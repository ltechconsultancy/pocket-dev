#!/bin/bash
set -e

# =============================================================================
# PocketDev PHP-FPM Production Entrypoint
# Uses gosu privilege drop pattern (same as official Docker images)
# - Runs as root initially for privileged operations
# - PHP-FPM workers run as www-data (standard PHP-FPM architecture)
# - Cross-group ownership: files owned by TARGET_UID:www-data (33)
# =============================================================================

echo "Starting Laravel production container..."

# Laravel expects /var/www/.env (Coolify: use env_file, not .env volume — empty mount blocks bootstrap).
if [ -f /var/www/.env ] && [ ! -s /var/www/.env ]; then
    echo "WARN: /var/www/.env exists but is empty (remove erroneous .env volume mount); recreating..."
    rm -f /var/www/.env
fi
bootstrap_env_file() {
if [ ! -f /var/www/.env ] && [ -f /var/www/.env.example ]; then
    echo "No .env — creating from .env.example + container environment..."
    cp /var/www/.env.example /var/www/.env
    for key in PD_APP_KEY PD_APP_ENV PD_APP_DEBUG PD_APP_URL PD_FORCE_HTTPS PD_DOMAIN_NAME \
        PD_DEPLOYMENT_MODE PD_PROJECT_NAME PD_DB_CONNECTION PD_DB_HOST PD_DB_PORT PD_DB_DATABASE \
        PD_DB_USERNAME PD_DB_PASSWORD PD_DB_READONLY_PASSWORD PD_DB_MEMORY_AI_PASSWORD \
        PD_REDIS_CLIENT PD_REDIS_HOST PD_REDIS_PORT PD_DOCKER_GID PD_USER_ID PD_GROUP_ID PD_QUEUE_WORKERS; do
        val="${!key:-}"
        if [ -n "$val" ]; then
            if grep -q "^${key}=" /var/www/.env 2>/dev/null; then
                sed -i "s|^${key}=.*|${key}=${val}|" /var/www/.env
            else
                echo "${key}=${val}" >> /var/www/.env
            fi
        fi
    done
    sed -i 's/^PD_APP_ENV=.*/PD_APP_ENV=production/' /var/www/.env
    sed -i 's/^PD_APP_DEBUG=.*/PD_APP_DEBUG=false/' /var/www/.env
    sed -i 's/^PD_DB_CONNECTION=.*/PD_DB_CONNECTION=pgsql/' /var/www/.env
    sed -i 's/^PD_REDIS_CLIENT=.*/PD_REDIS_CLIENT=predis/' /var/www/.env
fi
}
bootstrap_env_file

# Database preflight: fail fast on auth mismatch and retry on startup races.
check_database_connection() {
    local db_host="${PD_DB_HOST:-pocket-dev-postgres}"
    local db_port="${PD_DB_PORT:-5432}"
    local db_name="${PD_DB_DATABASE:-pocket-dev}"
    local db_user="${PD_DB_USERNAME:-pocket-dev}"
    local db_pass="${PD_DB_PASSWORD:-}"
    local attempts="${PD_DB_CONNECT_ATTEMPTS:-30}"
    local sleep_seconds="${PD_DB_CONNECT_SLEEP:-2}"
    local attempt=1
    local output=""

    if [ -z "$db_pass" ]; then
        echo "FATAL: PD_DB_PASSWORD is empty." >&2
        return 1
    fi

    echo "Checking database connection to ${db_host}:${db_port}/${db_name} as ${db_user}..."
    while [ "$attempt" -le "$attempts" ]; do
        if output="$(
            PGPASSWORD="$db_pass" psql \
                "host=${db_host} port=${db_port} dbname=${db_name} user=${db_user} connect_timeout=3" \
                -Atqc "SELECT 1" 2>&1
        )"; then
            echo "Database connection established."
            return 0
        fi

        if echo "$output" | grep -qi "password authentication failed\|role .* does not exist"; then
            echo "FATAL: Database authentication failed for ${db_user}@${db_host}:${db_port}/${db_name}." >&2
            echo "  This usually means the postgres volume was initialized with a different PD_DB_PASSWORD." >&2
            echo "  Fix: restore the old PD_DB_PASSWORD OR delete the ${PD_PROJECT_NAME:-pocket-dev}-postgres volume and redeploy." >&2
            return 1
        fi

        if [ "$attempt" -eq "$attempts" ]; then
            echo "FATAL: Database connection failed after ${attempts} attempts." >&2
            echo "  Last error: $output" >&2
            return 1
        fi

        echo "Database not ready yet (attempt ${attempt}/${attempts}); retrying in ${sleep_seconds}s..."
        attempt=$((attempt + 1))
        sleep "$sleep_seconds"
    done
}

create_pre_migration_backup() {
    local backup_dir="/var/www/storage/app/backups"
    local backup_file="${backup_dir}/pre-migrate-$(date +%Y%m%d_%H%M%S).sql"
    local backup_tmp="${backup_file}.tmp"
    local backup_err="${backup_file}.err"

    mkdir -p "$backup_dir"
    chown "${TARGET_UID}:33" "$backup_dir" 2>/dev/null || true

    rm -f "$backup_tmp" "$backup_err"

    if ! PGPASSWORD="${PD_DB_PASSWORD}" pg_dump \
        -h "${PD_DB_HOST:-pocket-dev-postgres}" \
        -p "${PD_DB_PORT:-5432}" \
        -U "${PD_DB_USERNAME:-pocket-dev}" \
        "${PD_DB_DATABASE:-pocket-dev}" > "$backup_tmp" 2>"$backup_err"; then
        echo "FATAL: Pre-migration backup failed; refusing to run migrations without a backup." >&2
        if [ -s "$backup_err" ]; then
            echo "  pg_dump error: $(tail -n 1 "$backup_err")" >&2
        fi
        rm -f "$backup_tmp" "$backup_err"
        return 1
    fi

    if [ ! -s "$backup_tmp" ]; then
        echo "FATAL: Pre-migration backup failed; generated backup file is empty." >&2
        rm -f "$backup_tmp" "$backup_err"
        return 1
    fi

    mv "$backup_tmp" "$backup_file"
    rm -f "$backup_err"
    echo "Pre-migration backup saved: ${backup_file}"

    # Keep only the 10 most recent pre-migration backups
    ls -t "${backup_dir}"/pre-migrate-*.sql 2>/dev/null | tail -n +11 | xargs rm -f 2>/dev/null || true
}

count_pending_migrations() {
    local migrations_table_exists=""
    local executed_migrations=""
    local pending_count=0
    local migration=""
    local -a local_migrations=()
    local -A executed_migration_map=()

    mapfile -t local_migrations < <(
        find /var/www/database/migrations -maxdepth 1 -type f -name '*.php' -printf '%f\n' 2>/dev/null \
            | sed 's/\.php$//' \
            | sort -u
    )

    if ! migrations_table_exists="$(
        PGPASSWORD="${PD_DB_PASSWORD}" psql \
            "host=${PD_DB_HOST:-pocket-dev-postgres} port=${PD_DB_PORT:-5432} dbname=${PD_DB_DATABASE:-pocket-dev} user=${PD_DB_USERNAME:-pocket-dev} connect_timeout=3" \
            -Atqc "SELECT EXISTS (
                SELECT 1
                FROM information_schema.tables
                WHERE table_schema = current_schema()
                  AND table_name = 'migrations'
            )"
    )"; then
        echo "FATAL: Failed to determine whether the migrations table exists." >&2
        return 1
    fi

    if [ "$migrations_table_exists" != "t" ]; then
        printf '%s\n' "${#local_migrations[@]}"
        return 0
    fi

    if ! executed_migrations="$(
        PGPASSWORD="${PD_DB_PASSWORD}" psql \
            "host=${PD_DB_HOST:-pocket-dev-postgres} port=${PD_DB_PORT:-5432} dbname=${PD_DB_DATABASE:-pocket-dev} user=${PD_DB_USERNAME:-pocket-dev} connect_timeout=3" \
            -Atqc "SELECT migration FROM migrations"
    )"; then
        echo "FATAL: Failed to read applied migrations from the database." >&2
        return 1
    fi

    while IFS= read -r migration; do
        if [ -n "$migration" ]; then
            executed_migration_map["$migration"]=1
        fi
    done <<< "$executed_migrations"

    for migration in "${local_migrations[@]}"; do
        if [ -z "${executed_migration_map[$migration]:-}" ]; then
            pending_count=$((pending_count + 1))
        fi
    done

    printf '%s\n' "$pending_count"
}

# Runtime configurable UID/GID (from compose.yml environment)
TARGET_UID="${PD_TARGET_UID:-1000}"
TARGET_GID="${PD_TARGET_GID:-1000}"

# Set HOME for tools that expect a writable home directory
export HOME=/home/appuser

# =============================================================================
# USER SETUP (cross-group ownership model)
# =============================================================================
# appuser: primary group www-data (33), secondary group TARGET_GID (for host)
# This enables file access between appuser and www-data processes.

# Ensure appgroup exists with TARGET_GID (for host file access)
if ! getent group "$TARGET_GID" > /dev/null 2>&1; then
    groupadd -g "$TARGET_GID" appgroup 2>/dev/null || true
fi

# Create a user for TARGET_UID if it doesn't exist
# Cross-group ownership: primary group www-data (33), secondary group TARGET_GID (for host)
if ! getent passwd "$TARGET_UID" > /dev/null 2>&1; then
    # UID doesn't exist - create it with a collision-safe name
    if getent passwd appuser > /dev/null 2>&1; then
        # "appuser" name is taken (by UID 1000), use unique name
        useradd -u "$TARGET_UID" -g 33 -G "$TARGET_GID" -d /home/appuser -s /bin/bash "appuser_$TARGET_UID" 2>/dev/null || true
    else
        useradd -u "$TARGET_UID" -g 33 -G "$TARGET_GID" -d /home/appuser -s /bin/bash appuser 2>/dev/null || true
    fi
fi

# Get the actual username for TARGET_UID (fail if creation failed)
TARGET_USER=$(getent passwd "$TARGET_UID" | cut -d: -f1)
if [ -z "$TARGET_USER" ]; then
    echo "FATAL: Failed to create or find user for UID $TARGET_UID" >&2
    exit 1
fi

# Ensure CLI config directories exist and are owned by TARGET_UID:www-data
mkdir -p "$HOME/.claude" "$HOME/.codex" "$HOME/.docker" 2>/dev/null || true
chown -R "${TARGET_UID}:33" "$HOME" 2>/dev/null || true
chmod 775 "$HOME" "$HOME/.claude" "$HOME/.codex" "$HOME/.docker" 2>/dev/null || true

# Fix SSH key permissions for www-data (PHP-FPM) panel access
# More restrictive than other dirs: 750 for dir, 640 for private keys
if [ -d "$HOME/.ssh" ]; then
    chmod 750 "$HOME/.ssh" 2>/dev/null || true
    find "$HOME/.ssh" -name "id_*" ! -name "*.pub" -exec chmod 640 {} \; 2>/dev/null || true
fi

# =============================================================================
# DOCKER SOCKET ACCESS
# =============================================================================
# Set up Docker socket access (needed for backup/restore operations)
# PD_DOCKER_GID is passed from compose.yml and matches the host's docker group
if [ -n "$PD_DOCKER_GID" ]; then
    echo "🐳 Setting up Docker socket access..."

    # Create the docker group with the host's GID if it doesn't exist
    if ! getent group "$PD_DOCKER_GID" > /dev/null 2>&1; then
        groupadd -g "$PD_DOCKER_GID" hostdocker_runtime 2>/dev/null || true
    fi

    # Get the group name for this GID
    DOCKER_GROUP_NAME=$(getent group "$PD_DOCKER_GID" | cut -d: -f1)
    if [ -z "$DOCKER_GROUP_NAME" ]; then
        DOCKER_GROUP_NAME="hostdocker_runtime"
    fi

    # Add www-data to docker group (for PHP-FPM workers)
    usermod -aG "$DOCKER_GROUP_NAME" www-data 2>/dev/null || true
    echo "  ✅ Added www-data to group $DOCKER_GROUP_NAME (GID $PD_DOCKER_GID)"

    # Also add TARGET_USER for CLI operations
    if [ -n "$TARGET_USER" ]; then
        usermod -aG "$DOCKER_GROUP_NAME" "$TARGET_USER" 2>/dev/null || true
        echo "  ✅ Added $TARGET_USER to group $DOCKER_GROUP_NAME (GID $PD_DOCKER_GID)"
    fi

    # Ensure docker socket has correct group ownership
    if [ -S /var/run/docker.sock ]; then
        chgrp "$DOCKER_GROUP_NAME" /var/run/docker.sock 2>/dev/null || true
        chmod 660 /var/run/docker.sock 2>/dev/null || true
    fi
fi

# =============================================================================
# VOLUME PERMISSIONS (for backup/restore and UID changes)
# =============================================================================
# When restoring from backup or changing PD_TARGET_UID, volume data may have
# wrong ownership. Fix permissions on all volumes that should be owned by TARGET_UID.
# Cross-group ownership: group www-data (33) for user container compatibility

# workspace volume - safe to chown -R (dedicated PocketDev volume)
chown -R "${TARGET_UID}:33" /workspace 2>/dev/null || true
chmod 775 /workspace 2>/dev/null || true

# pocketdev-storage volume (/var/www/storage/pocketdev)
if [ -d /var/www/storage/pocketdev ]; then
    chown -R "${TARGET_UID}:33" /var/www/storage/pocketdev 2>/dev/null || true
    find /var/www/storage/pocketdev -type d -exec chmod 775 {} \; 2>/dev/null || true
    find /var/www/storage/pocketdev -type f -exec chmod 664 {} \; 2>/dev/null || true
fi

# shared-tmp volume (/tmp) - fix PocketDev-specific directories
# Don't chown all of /tmp as other processes may use it
# Guard against symlink traversal (shared /tmp could have malicious symlinks)
for d in /tmp/pocketdev /tmp/pocketdev-uploads; do
    if [ -L "$d" ]; then
        echo "WARN: $d is a symlink; skipping ownership fix" >&2
        continue
    fi
    mkdir -p "$d" 2>/dev/null || true
    chown -R "${TARGET_UID}:33" "$d" 2>/dev/null || true
    find "$d" -type d -exec chmod 775 {} \; 2>/dev/null || true
    find "$d" -type f -exec chmod 664 {} \; 2>/dev/null || true
done

# =============================================================================
# STORAGE AND CACHE PERMISSIONS
# =============================================================================
# Fix permissions on Laravel storage and cache directories.
# Cross-group ownership: group www-data (33) for user container compatibility

echo "Setting storage permissions..."
chgrp -R 33 /var/www/storage /var/www/bootstrap/cache 2>/dev/null || true
find /var/www/storage -type d -exec chmod 775 {} \; 2>/dev/null || true
find /var/www/storage -type f -exec chmod 664 {} \; 2>/dev/null || true
find /var/www/bootstrap/cache -type d -exec chmod 775 {} \; 2>/dev/null || true
find /var/www/bootstrap/cache -type f -exec chmod 664 {} \; 2>/dev/null || true

# Check if running as main PHP container (no args or php-fpm)
# vs secondary container (queue worker, scheduler, etc.)
if [ $# -eq 0 ] || [ "$1" = "php-fpm" ]; then
    # Main PHP container: run migrations, caching, and start PHP-FPM

    if ! check_database_connection; then
        echo "FATAL: refusing to start PHP-FPM without a working database connection." >&2
        exit 1
    fi

    # Generate Laravel application key if not set
    if [ -f ".env" ] && ! grep -q "^PD_APP_KEY=.\+" .env; then
        echo "Generating Laravel application key..."
        PD_APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
        sed -i "s|^PD_APP_KEY=.*|PD_APP_KEY=$PD_APP_KEY|" .env
    fi

    # Run Laravel production optimizations (as www-data, which is in TARGET_GID group)
    echo "Running Laravel optimizations..."

    # Take a pre-migration backup if there are pending migrations.
    # This guards against data loss when switching branches with incompatible migration histories.
    if ! PENDING_MIGRATIONS="$(count_pending_migrations)"; then
        exit 1
    fi
    if [ "${PENDING_MIGRATIONS:-0}" -gt 0 ]; then
        echo "Detected ${PENDING_MIGRATIONS} pending migration(s) — creating pre-migration backup..."
        if ! create_pre_migration_backup; then
            exit 1
        fi
    fi

    if ! gosu www-data php artisan migrate --force --no-interaction 2>&1; then
        echo "FATAL: database migrations failed." >&2
        echo "  If you changed PD_DB_PASSWORD: delete the postgres volume in Coolify or restore the old password." >&2
        echo "  Logs: docker logs <pocket-dev-php-container>" >&2
        exit 1
    fi
    gosu www-data php artisan config:cache --no-interaction
    gosu www-data php artisan route:cache --no-interaction
    gosu www-data php artisan view:cache --no-interaction
    gosu www-data php artisan queue:restart --no-interaction

    # =============================================================================
    # SYSTEM PACKAGE INSTALLATION (requires root)
    # =============================================================================
    # Install user-configured system packages so they're available for workers.
    if [ -x /usr/local/bin/install-system-packages ]; then
        /usr/local/bin/install-system-packages || echo "Warning: System package installation had errors"
    fi

    echo "Laravel application ready for production"

    # PHP-FPM master runs as root, pool workers run as www-data (via www.conf)
    # This is standard Docker practice - see https://github.com/docker-library/php/issues/70
    # Running master as non-root fails with "failed to open error_log (/proc/self/fd/2): Permission denied"
    # Set group-writable umask so appuser (in appgroup) can edit files created by www-data
    # Default umask 022 creates 644 files; umask 002 creates 664 files (group-writable)
    umask 002

    echo "Starting PHP-FPM (master as root, workers as www-data)..."

    # Increase PHP-FPM worker pool for concurrent SSE streaming connections
    # Default max_children=5 causes exhaustion with just 5 open conversation tabs
    sed -i 's/^pm.max_children = .*/pm.max_children = 10/' /usr/local/etc/php-fpm.d/www.conf

    # Start PHP-FPM as root - pool workers will be www-data per www.conf
    exec php-fpm
else
    # Secondary container (queue worker, scheduler, etc.):
    # Wait for main container to complete migrations, then run the command
    echo "Waiting for database migrations..."
    max_attempts=60
    attempt=0
    while [ $attempt -lt $max_attempts ]; do
        if php artisan migrate:status > /dev/null 2>&1; then
            echo "Database ready"
            break
        fi
        attempt=$((attempt + 1))
        if [ $attempt -eq $max_attempts ]; then
            echo "Timeout waiting for database migrations"
            exit 1
        fi
        sleep 1
    done
    exec gosu www-data "$@"
fi

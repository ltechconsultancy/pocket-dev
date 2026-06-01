#!/bin/bash
# Generate a Coolify-ready .env from .env.coolify.example (secrets + domain).
# Paste the output into Coolify → Environment, or save as .env next to compose.coolify.yml.
#
# Usage:
#   ./setup-coolify.sh --domain=pocketdev.example.com
#   ./setup-coolify.sh --domain=pocketdev.example.com --output=/tmp/pocketdev-coolify.env
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TEMPLATE="${SCRIPT_DIR}/.env.coolify.example"
OUTPUT="${SCRIPT_DIR}/.env"
DOMAIN=""

sedi() {
    if [[ "${OSTYPE:-}" == darwin* ]]; then
        sed -i '' "$@"
    else
        sed -i "$@"
    fi
}

get_gid() {
    if stat -c '%g' "$1" >/dev/null 2>&1; then
        stat -c '%g' "$1"
    else
        stat -f '%g' "$1" 2>/dev/null
    fi
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --domain=*)
            DOMAIN="${1#*=}"
            shift
            ;;
        --domain)
            DOMAIN="${2:-}"
            shift 2
            ;;
        --output=*)
            OUTPUT="${1#*=}"
            shift
            ;;
        --output)
            OUTPUT="${2:-}"
            shift 2
            ;;
        -h|--help)
            echo "Usage: $0 --domain=pocketdev.example.com [--output=path]"
            exit 0
            ;;
        *)
            echo "Unknown option: $1" >&2
            exit 1
            ;;
    esac
done

if [[ -z "$DOMAIN" ]]; then
    echo "Error: --domain is required (e.g. --domain=pocketdev.example.com)" >&2
    exit 1
fi

if [[ ! -f "$TEMPLATE" ]]; then
    echo "Error: template not found: $TEMPLATE" >&2
    exit 1
fi

cp "$TEMPLATE" "$OUTPUT"

APP_KEY="base64:$(openssl rand -base64 32)"
DB_PASSWORD="$(openssl rand -hex 16)"
DB_READONLY_PASSWORD="$(openssl rand -hex 16)"
DB_MEMORY_AI_PASSWORD="$(openssl rand -hex 16)"

sedi "s|^PD_APP_KEY=.*|PD_APP_KEY=$APP_KEY|" "$OUTPUT"
sedi "s|^PD_DB_PASSWORD=.*|PD_DB_PASSWORD=$DB_PASSWORD|" "$OUTPUT"
sedi "s|^PD_DB_READONLY_PASSWORD=.*|PD_DB_READONLY_PASSWORD=$DB_READONLY_PASSWORD|" "$OUTPUT"
sedi "s|^PD_DB_MEMORY_AI_PASSWORD=.*|PD_DB_MEMORY_AI_PASSWORD=$DB_MEMORY_AI_PASSWORD|" "$OUTPUT"
sedi "s|^PD_APP_URL=.*|PD_APP_URL=https://$DOMAIN|" "$OUTPUT"
sedi "s|^PD_DOMAIN_NAME=.*|PD_DOMAIN_NAME=$DOMAIN|" "$OUTPUT"
sedi "s|^PD_FORCE_HTTPS=.*|PD_FORCE_HTTPS=true|" "$OUTPUT"
sedi "s|^PD_DEPLOYMENT_MODE=.*|PD_DEPLOYMENT_MODE=production|" "$OUTPUT"

if [[ -S /var/run/docker.sock ]]; then
    DOCKER_GID="$(get_gid /var/run/docker.sock)"
    if [[ -n "$DOCKER_GID" ]]; then
        sedi "s|^PD_DOCKER_GID=.*|PD_DOCKER_GID=$DOCKER_GID|" "$OUTPUT"
    fi
fi

USER_ID="$(id -u)"
GROUP_ID="$(id -g)"
sedi "s|^PD_USER_ID=.*|PD_USER_ID=$USER_ID|" "$OUTPUT"
sedi "s|^PD_GROUP_ID=.*|PD_GROUP_ID=$GROUP_ID|" "$OUTPUT"

echo "Wrote Coolify environment file: $OUTPUT"
echo ""
echo "Next steps:"
echo "  1. Copy all variables into Coolify → Environment (or use this file as deploy/.env)"
echo "  2. GitHub app → compose file: deploy/compose.coolify.yml"
echo "  3. FQDN on pocket-dev-nginx → https://$DOMAIN (port 80)"
echo "  4. Deploy (first build compiles php, nginx, postgres from repo)"

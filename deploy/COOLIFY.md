# PocketDev on Coolify

Deploy from the **full Git repository** via the **GitHub App** (Coolify clones the repo and **builds** images on deploy). No GHCR pull required for this path.

| This path | Standard `install.sh` path |
|-----------|----------------------------|
| GitHub App → `deploy/compose.coolify.yml` | `deploy/compose.yml` + proxy-nginx |
| `build:` from repo | Pre-built `ghcr.io/tetrixdev/...` images |

Use the **Docker Compose** build pack (not **Raw Compose Deployment**).

## Prerequisites

| Requirement | Notes |
|-------------|--------|
| Coolify server | Ubuntu 24.04+, Docker 24+, **4 GB RAM** min (**8 GB+** recommended) |
| GitHub App | Connected to `ltechconsultancy/pocket-dev` (or your fork) |
| DNS | `A` record → Coolify server IP |
| Docker socket | PHP + queue mount `/var/run/docker.sock` — high trust; prefer a **dedicated VPS** |

## Coolify networking (important)

- **Do not** add custom `networks:` in `compose.coolify.yml` — Coolify attaches Traefik to its network; extra networks → **504** ([Coolify #6215](https://github.com/coollabsio/coolify/issues/6215)).
- **Do not** bind host `80:80` on nginx — **FQDN** on `pocket-dev-nginx` → port **80**.
- **Do not** add manual Traefik labels — domain in Coolify UI only.

## 1. How builds work

Coolify checks out the repo and builds (first deploy may take 10–20 min):

| Service | Dockerfile |
|---------|------------|
| php, queue | `docker-laravel/production/php/Dockerfile` |
| nginx | `docker-laravel/production/nginx/Dockerfile` |
| postgres | `docker-postgres/Dockerfile` |

Build context is repo root (`context: .` with Coolify **Base Directory** `/`). Coolify runs Compose with `--project-directory` set to the cloned repository root, not `deploy/`.

`PD_IMAGE_OWNER` / `PD_IMAGE_TAG` are **not used** by `compose.coolify.yml`. For GHCR pull-only deploys, use [`compose.yml`](compose.yml) + `install.sh` instead.

## 2. Environment variables

Passed via Compose `environment:` (YAML anchor) — **no** `.env` file bind mount. Set everything in Coolify → **Environment**.

Do **not** set `PD_HOST_PROJECT_PATH` in Coolify, and do not paste any value containing `${PWD}`. Coolify writes Environment values into `build-time.env`; malformed templates such as `${PWD` stop the deploy before Dockerfiles are read.

```bash
cd deploy
chmod +x setup-coolify.sh
./setup-coolify.sh --domain=pocketdev.example.com
# Paste deploy/.env contents into Coolify → Environment
```

Do **not** use `setup.sh` for Coolify (it targets `.env.example` and localhost).

Required:

- `PD_APP_KEY`, `PD_DB_PASSWORD`, `PD_DB_READONLY_PASSWORD`, `PD_DB_MEMORY_AI_PASSWORD`
- `PD_APP_URL`, `PD_DOMAIN_NAME`, `PD_FORCE_HTTPS=true`, `PD_DEPLOYMENT_MODE=production`
- `PD_DOCKER_GID` — on server: `stat -c '%g' /var/run/docker.sock`
- `PD_QUEUE_WORKERS` — `4` on 4 GB VPS, `8` default in compose

Optional (not in `compose.coolify.yml` — add in Coolify only if you need a pinned CLI): `CLAUDE_CODE_VERSION=2.1.17` on the **queue** service via Coolify’s per-service env, not as a locked compose-derived var.
## 3. Coolify resource setup

1. **Project** → **Environment** → **+ Add Resource**
2. **Docker Compose** (normal build pack, not Raw Compose)
3. **GitHub App** → repository `ltechconsultancy/pocket-dev`, branch `main`
4. **Compose file location:** `deploy/compose.coolify.yml`
5. **Base Directory:** `/`
6. Paste environment from `setup-coolify.sh`
7. Delete any stale `PD_HOST_PROJECT_PATH` or other value containing `PWD`
8. **Deploy** (builds php, nginx, postgres from repo)

## 4. Domain (TLS) — only nginx

Coolify may show a domain field **per service**. PocketDev needs **one public URL**; only nginx serves HTTP.

| Service | Domain in Coolify |
|---------|-------------------|
| **pocket-dev-nginx** | `https://jouwdomein.nl` (port **80**) |
| pocket-dev-php | **leave empty** |
| pocket-dev-postgres | **leave empty** |
| pocket-dev-queue | **leave empty** |
| pocket-dev-redis | **leave empty** (if shown) |

php/postgres/redis/queue talk over the internal Docker network — never expose them on Traefik.

Set `PD_APP_URL` and `PD_DOMAIN_NAME` to the **same** hostname as nginx.

## 5. Traefik timeouts & uploads

`install.sh` sets proxy-nginx to 2048 MB body / 3600s websocket. On Coolify, raise Traefik timeouts/body size if long chat (SSE) or large uploads fail.

## 6. Updates

Push to the connected branch → **Redeploy** in Coolify (rebuilds when Dockerfiles or app code change). Optional: enable auto-deploy on push in Git settings.

## Backups

```bash
# From the Coolify project directory (adjust path to your deployment)
docker compose -f deploy/compose.coolify.yml exec pocket-dev-postgres \
  pg_dump -U pocket-dev pocket-dev > backup-$(date +%Y%m%d).sql

docker run --rm -v pocket-dev-workspace:/data -v "$(pwd)":/backup alpine \
  tar czf /backup/workspace-$(date +%Y%m%d).tar.gz -C /data .
```

## Smoke test after deploy

- [ ] HTTPS loads the setup wizard
- [ ] Chat / SSE streaming (long reply)
- [ ] File upload (~10 MB+)
- [ ] Docker panel works (`docker ps` from PocketDev)
- [ ] All services healthy (`docker compose ps`)
- [ ] Redeploy preserves data (volumes unchanged)

## Troubleshooting

| Symptom | Check |
|---------|--------|
| **`Invalid template: "${PWD"`** | In Coolify **Environment**, delete any variable whose value contains `PWD` (often stale `PD_HOST_PROJECT_PATH`). Do not use `deploy/compose.yml` here — only `deploy/compose.coolify.yml`. Save, refresh/reload the resource, and redeploy with cache disabled. |
| **Dockerfile not found (`../docker-...`)** | Base directory `/`, compose `deploy/compose.coolify.yml`; build `context` must be `.` (repo root). |
| **Build fails** | Coolify build logs; repo contains `www/`, `docker-laravel/`, `docker-postgres/` |
| **502 / 504** | FQDN on `pocket-dev-nginx:80`; no custom `networks:` in compose |
| **Missing config** | All `PD_*` in Coolify Environment; redeploy after changes |
| **Docker tools fail** | `PD_DOCKER_GID` matches host docker group |
| **OOM** | `PD_QUEUE_WORKERS=4`; use 8 GB+ RAM |

## Security

`docker.sock` can control all containers on the Coolify host. Use a dedicated VPS when possible.

## Files

| File | Purpose |
|------|---------|
| `compose.coolify.yml` | Build-from-repo Coolify stack |
| `setup-coolify.sh` | Generate `PD_*` secrets |
| `.env.coolify.example` | Env template (no image vars) |
| `compose.yml` | GHCR + `install.sh` |

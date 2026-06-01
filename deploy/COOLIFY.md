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

Build context is repo root (`context: ..` relative to `deploy/`).

`PD_IMAGE_OWNER` / `PD_IMAGE_TAG` are **not used** by `compose.coolify.yml`. For GHCR pull-only deploys, use [`compose.yml`](compose.yml) + `install.sh` instead.

## 2. Environment variables

Passed via Compose `environment:` (YAML anchor) — **no** `.env` file bind mount. Set everything in Coolify → **Environment**.

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

## 3. Coolify resource setup

1. **Project** → **Environment** → **+ Add Resource**
2. **Docker Compose** (normal build pack, not Raw Compose)
3. **GitHub App** → repository `ltechconsultancy/pocket-dev`, branch `main`
4. **Compose file location:** `deploy/compose.coolify.yml`
5. Paste environment from `setup-coolify.sh`
6. **Deploy** (builds php, nginx, postgres from repo)

## 4. Domain (TLS)

1. Resource → service **`pocket-dev-nginx`**
2. **FQDN:** `https://pocketdev.example.com`
3. **Port:** `80`

## 5. Traefik timeouts & uploads

`install.sh` sets proxy-nginx to 2048 MB body / 3600s websocket. On Coolify, raise Traefik timeouts/body size if long chat (SSE) or large uploads fail.

## 6. Updates

Push to the connected branch → **Redeploy** in Coolify (rebuilds when Dockerfiles or app code change). Optional: enable auto-deploy on push in Git settings.

## Backups & smoke test

See sections in previous docs — `pg_dump`, volume tar, checklist: HTTPS wizard, chat/SSE, uploads, Docker tools, queue healthy, redeploy keeps volumes.

## Security

`docker.sock` can control all containers on the Coolify host. Use a dedicated VPS when possible.

## Files

| File | Purpose |
|------|---------|
| `compose.coolify.yml` | Build-from-repo Coolify stack |
| `setup-coolify.sh` | Generate `PD_*` secrets |
| `.env.coolify.example` | Env template (no image vars) |
| `compose.yml` | GHCR + `install.sh` |

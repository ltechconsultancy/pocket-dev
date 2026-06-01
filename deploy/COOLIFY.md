# PocketDev on Coolify

Deploy the PocketDev stack on a [Coolify](https://coolify.io) server using `compose.coolify.yml`. This path uses **Traefik** (built into Coolify) instead of `proxy-nginx` / `install.sh`.

For the standard VPS install (Tailscale, proxy-nginx), see the [main README](../README.md) and [deploy/README.md](README.md).

## Prerequisites

| Requirement | Notes |
|-------------|--------|
| Coolify server | Ubuntu 24.04+, Docker 24+, ≥ **4 GB RAM** (8 GB+ recommended) |
| DNS | A record → Coolify server IP |
| GHCR access | Pull `ghcr.io/<owner>/pocket-dev-*` (public tetrixdev images, or your org after a release) |
| Docker socket | PHP and queue mount `/var/run/docker.sock` — required for PocketDev tools; **high trust** on the host |

## 1. Container images

`compose.coolify.yml` pulls:

```text
ghcr.io/${PD_IMAGE_OWNER}/pocket-dev-{php,nginx,postgres}:${PD_IMAGE_TAG}
```

| Situation | `PD_IMAGE_OWNER` | `PD_IMAGE_TAG` |
|-----------|------------------|----------------|
| **This fork** (Cursor Agent, custom code) | `ltechconsultancy` | Your GitHub release tag (after CI publishes to GHCR) |
| **Upstream only** (no fork-specific features) | `tetrixdev` | e.g. `v0.67.0` |

Publish images from this repo: create a **GitHub Release** — `.github/workflows/docker-laravel.yml` pushes to `ghcr.io/${{ github.repository_owner }}/pocket-dev-*`.

## 2. Create the Coolify resource

1. Coolify → **Project** → **Environment** → **+ Add Resource**
2. **Docker Compose** → connect repo `ltechconsultancy/pocket-dev` (branch `main`)
3. **Compose file path:** `deploy/compose.coolify.yml`
4. **Environment variables:** copy from [`.env.coolify.example`](.env.coolify.example) and fill secrets

Generate secrets on any machine:

```bash
cd deploy
cp .env.coolify.example .env
./setup.sh   # interactive; copies PD_* secrets into .env
```

Paste the resulting `PD_*` values into Coolify’s **Environment** tab (Coolify writes `.env` for the stack).

Required:

- `PD_APP_KEY`, `PD_DB_PASSWORD`, `PD_DB_READONLY_PASSWORD`, `PD_DB_MEMORY_AI_PASSWORD`
- `PD_APP_URL`, `PD_FORCE_HTTPS=true`, `PD_DOMAIN_NAME`, `PD_DEPLOYMENT_MODE=production`
- `PD_DOCKER_GID` — on the server: `stat -c '%g' /var/run/docker.sock`
- `PD_IMAGE_OWNER`, `PD_IMAGE_TAG`

## 3. Domain (Traefik)

1. Deploy the stack once (images must pull successfully).
2. Open the resource → service **`pocket-dev-nginx`**
3. Set **FQDN:** `https://pocketdev.example.com`
4. **Port:** `80` (container internal port)

Do **not** publish host ports `80:80` on nginx — Coolify’s proxy already binds 80/443 on the host.

## 4. First visit

Open the FQDN and complete the PocketDev setup wizard (AI provider, credentials, etc.).

## Updates

1. Bump `PD_IMAGE_TAG` in Coolify env to the new release.
2. **Redeploy** the compose resource (or use Coolify’s deploy webhook).

```bash
# Manual pull on the server (optional)
cd /data/coolify/...   # Coolify project path
docker compose pull && docker compose up -d
```

## Backups

Same as [deploy/README.md](README.md):

```bash
# Database
docker compose exec pocket-dev-postgres pg_dump -U pocket-dev pocket-dev > backup.sql

# Workspace volume
docker run --rm -v pocket-dev-workspace:/data -v $(pwd):/backup alpine \
  tar czf /backup/workspace.tar.gz -C /data .
```

## Troubleshooting

| Symptom | Check |
|---------|--------|
| **502 Bad Gateway** | FQDN on `pocket-dev-nginx`, port **80**; `docker compose ps` — all healthy |
| **Image pull 404** | `PD_IMAGE_OWNER` / `PD_IMAGE_TAG`; release exists on GHCR |
| **Docker tools fail** | `PD_DOCKER_GID` matches host; socket mounted |
| **OOM / slow** | Lower `PD_QUEUE_WORKERS` (4–8); increase VPS RAM |
| **Upload / SSE issues** | Increase Traefik timeouts/body size in Coolify proxy settings |

## Security note

PocketDev containers with `docker.sock` can manage **all** containers on the Coolify host, including other apps. Prefer a **dedicated VPS** for PocketDev, or accept the risk on a shared Coolify instance.

## Files

| File | Purpose |
|------|---------|
| `compose.coolify.yml` | Coolify / Traefik–optimized stack |
| `.env.coolify.example` | Environment template |
| `compose.yml` | Standard deploy (proxy-nginx / `install.sh`) |

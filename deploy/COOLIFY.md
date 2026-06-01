# PocketDev on Coolify

Deploy PocketDev with the **Docker Compose** build pack (not **Raw Compose Deployment**). Coolify runs Traefik; you do not need `proxy-nginx` or `install.sh`.

Standard VPS install: [deploy/README.md](README.md) and [main README](../README.md).

## Prerequisites

| Requirement | Notes |
|-------------|--------|
| Coolify server | Ubuntu 24.04+, Docker 24+, **4 GB RAM** minimum (**8 GB+** recommended) |
| DNS | `A` record → Coolify server IP |
| GHCR images | `ghcr.io/tetrixdev/pocket-dev-*` (public). Fork images: publish a GitHub **Release** first (`ghcr.io/ltechconsultancy/...`) |
| Docker socket | PHP + queue need `/var/run/docker.sock` — high trust on a shared Coolify host; prefer a **dedicated VPS** |

## Coolify networking (important)

- **Do not** add custom `networks:` blocks in `compose.coolify.yml`. Coolify creates its own network and attaches Traefik. Extra networks can cause **504 Gateway Timeout** ([Coolify #6215](https://github.com/coollabsio/coolify/issues/6215)).
- **Do not** bind host ports `80:80` on nginx — use Coolify **FQDN** on `pocket-dev-nginx` → container port **80**.
- **Do not** add manual Traefik labels — set the domain in the Coolify UI for `pocket-dev-nginx`.

## 1. Container images

```text
ghcr.io/${PD_IMAGE_OWNER}/pocket-dev-{php,nginx,postgres}:${PD_IMAGE_TAG}
```

| Situation | `PD_IMAGE_OWNER` | `PD_IMAGE_TAG` |
|-----------|------------------|----------------|
| **Works today** (upstream) | `tetrixdev` | `v0.67.0` (or newer release) |
| **This fork** (Cursor Agent, etc.) | `ltechconsultancy` | Your release tag after CI publishes to GHCR |

`compose.coolify.yml` requires both variables (no implicit `:latest`).

## 2. Environment variables

Variables are passed via Docker Compose `environment:` (no `.env` file bind mount). Coolify’s **Environment** tab must list every `PD_*` variable from [`.env.coolify.example`](.env.coolify.example).

Generate secrets locally:

```bash
cd deploy
chmod +x setup-coolify.sh
./setup-coolify.sh --domain=pocketdev.example.com
# Copy deploy/.env into Coolify → Environment
```

Do **not** use `setup.sh` for Coolify — it targets `.env.example`, sets `localhost`, and is meant for `install.sh` / proxy-nginx.

Required in Coolify:

- `PD_APP_KEY`, `PD_DB_PASSWORD`, `PD_DB_READONLY_PASSWORD`, `PD_DB_MEMORY_AI_PASSWORD`
- `PD_APP_URL`, `PD_DOMAIN_NAME`, `PD_FORCE_HTTPS=true`, `PD_DEPLOYMENT_MODE=production`
- `PD_IMAGE_OWNER`, `PD_IMAGE_TAG`
- `PD_DOCKER_GID` — on the server: `stat -c '%g' /var/run/docker.sock`

## 3. Create the Coolify resource

1. **Project** → **Environment** → **+ Add Resource**
2. **Docker Compose** (normal build pack, not Raw Compose)
3. Repository: `ltechconsultancy/pocket-dev`, branch `main`
4. **Compose file:** `deploy/compose.coolify.yml`
5. Paste environment from `setup-coolify.sh` output
6. **Deploy**

## 4. Domain (TLS)

1. Resource → service **`pocket-dev-nginx`**
2. **FQDN:** `https://pocketdev.example.com`
3. **Port:** `80`

## 5. Traefik timeouts & uploads

`install.sh` configures proxy-nginx with **2048 MB** body size and **3600s** websocket timeout. On Coolify, increase Traefik limits if long chat streams or large uploads fail:

- Coolify → **Server** → **Proxy** → adjust timeout / body size settings, or
- Add a Traefik middleware on the resource (see [Coolify Traefik docs](https://coolify.io/docs/knowledge-base/proxy/traefik/overview))

Internal nginx already uses long FastCGI timeouts for SSE chat.

## 6. First visit

Open the FQDN → complete the PocketDev setup wizard (AI provider, credentials).

## Updates

1. Bump `PD_IMAGE_TAG` in Coolify.
2. Redeploy the compose resource.

## Backups

```bash
docker compose -f compose.coolify.yml exec pocket-dev-postgres \
  pg_dump -U pocket-dev pocket-dev > backup.sql
```

## Smoke test after deploy

- [ ] HTTPS loads setup wizard
- [ ] Chat / SSE streaming (long reply)
- [ ] File upload (~10 MB+)
- [ ] Docker panel / `docker ps` from PocketDev
- [ ] Queue healthy (`docker compose ps`)
- [ ] Redeploy preserves volumes

## Security

Containers with `docker.sock` can control **all** Docker workloads on the host, including other Coolify apps. Use a dedicated VPS when possible.

## Files

| File | Purpose |
|------|---------|
| `compose.coolify.yml` | Coolify stack (no custom networks) |
| `.env.coolify.example` | Variable template |
| `setup-coolify.sh` | Generate secrets + production URL |
| `compose.yml` | Standard deploy (`install.sh` / proxy-nginx) |

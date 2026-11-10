# SocialMesh — Shared VPS Deployment Runbook

> [!CAUTION]
> **CRITICAL SHARED VPS PROTECTION RULE (`138.252.100.129`)**
> This VPS (`server-2vf78k`, Ubuntu 24.04.5 LTS) hosts the live production application **RK Enterprises Inventory** at `/opt/rk-inventory/deploy` (`deploy-app-1`, `deploy-caddy-1`).
> - **NEVER** modify `/opt/rk-inventory/deploy` or any files inside `/opt/rk-inventory`.
> - **NEVER** stop, restart, rebuild, or inspect secrets of `deploy-app-1` or `deploy-caddy-1`.
> - **NEVER** run `docker system prune`, `docker volume prune`, or `docker network prune`.
> - **NEVER** bind host ports `80` or `443` (already owned by `deploy-caddy-1`).

---

## 1. Deployment Architecture & Isolation

SocialMesh runs in complete isolation on the shared VPS:

| Resource | SocialMesh Value | RK Inventory Value (DO NOT TOUCH) |
|---|---|---|
| **Directory** | `/opt/apps/socialmesh` | `/opt/rk-inventory/deploy` |
| **Compose Project** | `socialmesh` | `deploy` |
| **Containers** | `socialmesh-app`, `socialmesh-cloudflared` | `deploy-app-1`, `deploy-caddy-1` |
| **Docker Network** | `socialmesh_net` | `deploy_default` |
| **Docker Volumes** | `socialmesh_sqlite`, `socialmesh_storage` | `deploy_caddy_data` |
| **Host Port Binding** | `127.0.0.1:8082:8080` (loopback only) | `80`, `443` |
| **Ingress / TLS** | Cloudflare Tunnel (`cloudflared` -> `http://app:8080`) | Host Caddy |
| **Public Domain** | `https://socialmesh.ebintech.in` | RK Inventory domain |
| **Resource Caps** | `app`: `1g` RAM, `1.0` CPU; `cloudflared`: `128m` RAM, `0.25` CPU | Untouched |

---

## 2. Pre-Deployment Verification Checklist (Read-Only on VPS)

Before deploying SocialMesh, SSH into `138.252.100.129` (`ssh -i /root/.ssh/ebin-tech root@138.252.100.129`) and run these read-only checks to verify VPS health and confirm RK Enterprises Inventory is healthy:

```bash
# 1. Verify running containers (confirm deploy-app-1 and deploy-caddy-1 are Up)
docker ps --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"

# 2. Verify available RAM and disk space (require >= 1.5 GB free RAM/swap & >= 5 GB free disk)
free -h
df -h

# 3. Verify loopback port 8082 is free
ss -tulpn | grep 8082 || echo "Port 8082 is free"
```

---

## 3. Step-by-Step Deployment Procedure

### Step 1: Prepare `/opt/apps/socialmesh`

```bash
sudo mkdir -p /opt/apps/socialmesh /opt/apps/socialmesh/backups
sudo chown -R "$USER:$USER" /opt/apps/socialmesh
cd /opt/apps/socialmesh

git clone https://github.com/Rajeshkadali97/socialmesh.git .
git checkout antigravity/complete-development
```

### Step 2: Configure `.env`

```bash
cp .env.example.prod .env
chmod 600 .env
```

Edit `/opt/apps/socialmesh/.env` and populate:
- `APP_KEY`: generate once via `php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'`
- `APP_URL=https://socialmesh.ebintech.in`
- `TUNNEL_TOKEN=<Cloudflare Zero Trust Tunnel Token>`
- `FILESYSTEM_DISK=r2` (or `public` if using local volume + Google Drive archive)
- `R2_*` and `GOOGLE_DRIVE_*` credentials
- `FACEBOOK_*` and `THREADS_*` OAuth credentials

### Step 3: Configure Cloudflare Zero Trust Tunnel

In the **Cloudflare Zero Trust Dashboard** (`Networks > Tunnels`):
1. Create a tunnel named `socialmesh-prod` and copy its `TUNNEL_TOKEN` into `/opt/apps/socialmesh/.env`.
2. Add a Public Hostname route:
   - **Subdomain**: `socialmesh`
   - **Domain**: `ebintech.in`
   - **Service Type**: `HTTP`
   - **URL**: `app:8080`

### Step 4: Start SocialMesh Stack

Always pass `-p socialmesh` and both compose files from `/opt/apps/socialmesh`. To avoid heavy multi-stage Composer/Bun builds on the shared VPS, assemble `socialmesh:local` from prebuilt development artifacts (or pull the prebuilt image) and start `app`:

```bash
cd /opt/apps/socialmesh
# Start the app container on 127.0.0.1:8082 (works even before TUNNEL_TOKEN is set)
docker compose -p socialmesh \
  -f docker-compose.production.yaml \
  -f docker-compose.tunnel.yaml \
  up -d --no-build app

# Once TUNNEL_TOKEN is set in /opt/apps/socialmesh/.env, start the Cloudflare Tunnel:
docker compose -p socialmesh \
  -f docker-compose.production.yaml \
  -f docker-compose.tunnel.yaml \
  up -d --no-build cloudflared
```

### Step 5: Initialize Database & Seed 13 Client Workspaces (First Deploy Only)

```bash
cd /opt/apps/socialmesh
docker exec socialmesh-app php artisan migrate --force
docker exec socialmesh-app php artisan db:seed --class="Database\\Seeders\\DefaultUserSeeder" --force
docker exec socialmesh-app php artisan db:seed --class="Database\\Seeders\\AgencyWorkspacesSeeder" --force
```

### Step 6: Post-Deploy Smoke Checks

```bash
# 1. Check SocialMesh container health
docker compose -p socialmesh -f docker-compose.production.yaml -f docker-compose.tunnel.yaml ps

# 2. Verify local loopback response
curl -I http://127.0.0.1:8082/up

# 3. Verify RK Enterprises Inventory is still running untouched
docker ps --filter "name=deploy-"
```

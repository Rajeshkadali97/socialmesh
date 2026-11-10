# VPS Connection & Verification Guide

## 1. VPS IP Discrepancy Resolution (Verified Live)

During the audit, two candidate IP addresses were checked against the local SSH configuration (`/root/.ssh/known_hosts`, `/root/.ssh/ebin-tech`, `/root/.ssh/id_ed25519`):

- **`138.252.100.129` — CONFIRMED ACTIVE PRODUCTION VPS**:
  - Matches all three host key entries in `/root/.ssh/known_hosts`.
  - Authenticated via read-only SSH (`ssh -i /root/.ssh/ebin-tech root@138.252.100.129`).
  - Hostname: `server-2vf78k` (`Ubuntu 24.04.5 LTS`).
  - Hosts the live **RK Enterprises Inventory** stack at `/opt/rk-inventory/deploy` (`deploy-app-1`, `deploy-caddy-1`).
- **`72.61.224.236` — Stale/Historical IP Reference**:
  - Not present in `/root/.ssh/known_hosts` and not the active host for `/opt/rk-inventory/deploy`.
  - All runbooks have been updated to reference **`138.252.100.129`** as the verified production VPS.

---

## 2. Live Read-Only VPS Inspection Results (`138.252.100.129`)

Verified via read-only SSH on `2026-11-10`:

| Check | Verified Value | Status |
|---|---|---|
| **SSH Connectivity** | `ssh -i /root/.ssh/ebin-tech root@138.252.100.129` | **VERIFIED** |
| **OS Version** | `Ubuntu 24.04.5 LTS` (hostname `server-2vf78k`) | **VERIFIED** |
| **Docker Engine** | `Docker version 29.9.3, build 5457199` | **VERIFIED** |
| **Docker Compose** | `Docker Compose version v5.7.0` | **VERIFIED** |
| **CPU Capacity** | `2 vCPUs` (load average `0.01, 0.00, 0.00`) | **VERIFIED** |
| **Available Memory** | `3.8 GiB total`, `731 MiB used`, **`3.1 GiB available`** | **VERIFIED** |
| **Disk Space (`/`)** | `19 GB total`, `4.8 GB used`, **`14 GB available` (26% used)** | **VERIFIED** |
| **Running Containers** | `deploy-caddy-1` (`caddy:2`), `deploy-app-1` (`deploy-app`) | **UNTOUCHED & HEALTHY** |
| **Existing Networks** | `deploy_default`, `bridge`, `host`, `none` | **VERIFIED** |
| **Existing Volumes** | `deploy_caddy_data` | **VERIFIED** |
| **Listening Ports** | `0.0.0.0:80` (`deploy-caddy-1`), `0.0.0.0:443` (`deploy-caddy-1`), `0.0.0.0:22` (`sshd`). Port `127.0.0.1:8082` is **free**. | **VERIFIED** |

---

## 3. Existing Production Application Protection (`RK Enterprises Inventory`)

- Directory `/opt/rk-inventory/deploy` was inspected read-only and **never modified**.
- Containers `deploy-app-1` and `deploy-caddy-1`, network `deploy_default`, and volume `deploy_caddy_data` are completely untouched.
- Because `deploy-caddy-1` binds host ports `80` and `443`, SocialMesh uses:
  - Loopback-only port `127.0.0.1:8082:8080` on `socialmesh-app`
  - Outbound `socialmesh-cloudflared` tunnel container on isolated bridge network `socialmesh_net` pointing to `http://app:8080`
  - Zero changes to `deploy-caddy-1` or host ports `80`/`443`.

# Installation of the ep-3 Bookingsystem

## Requirements

### Docker Setup (Recommended)

- Docker & Docker Compose
- Git

### Manual Setup

- Apache HTTP Server 2+ with `mod_rewrite` and `mod_headers`
- **PHP 8.4+** with `intl` extension
- MariaDB 10.11+ (or MySQL 5.7+)

---

## Quick Start (Docker)

```bash
# 1. Clone repository
git clone <repository-url>
cd ep3bs-payment

# 2. Create configuration files
cp .env.example .env
cp config/autoload/local.php.dist config/autoload/local.php
cp config/autoload/project.php.dist config/autoload/project.php
cp config/init.php.dist config/init.php

# Edit configuration files with your credentials:
# - .env: Docker environment (ports, Xdebug toggle)
# - local.php: Database credentials, SMTP, Payment API keys
# - project.php: URLs, session config, payment method toggles, feature flags
# - init.php: Dev mode flag, timezone, error reporting

# 3. Build and start Docker containers
docker compose build
docker compose up -d

# 4. Install/update PHP dependencies (inside container)
docker compose exec court composer update --ignore-platform-reqs

# 5. Import database schema
docker compose exec -T mariadb mariadb -u root -p<password> <database> < data/db/ep3-bs.sql

# Migrations run automatically on first request!
```

**Access Points:**
- Application: https://court.localhost (self-signed cert)
- Traefik Dashboard: http://localhost:8080
- MailHog UI: http://localhost:8025

### Docker Services

| Service | Port | Purpose |
|---------|------|---------|
| traefik | 80, 443, 8080 | Reverse proxy with HTTPS |
| court | via Traefik | PHP 8.4 Apache app server |
| mariadb | 3306 | MariaDB 10.11 database |
| mailhog | 8025 (UI), 1025 (SMTP) | Email testing |

### DEV vs PROD

Toggle via `INSTALL_XDEBUG` in `.env`:
- `true`: Xdebug 3.4 installed (port 9003, IDE key: PHPSTORM)
- `false`: No debug tools, production-ready

### Docker Compose Files

```bash
# Local dev (override auto-loaded):
docker compose up -d

# Production (base only, uses external Traefik):
docker compose -f docker-compose.yml up -d

# DEV on server (alongside production):
docker compose -f docker-compose.dev-server.yml up -d
```

### Loading a Production DB Dump

```bash
scp user@your-server:/backup/mycourt-pay.sql .
docker compose exec -T mariadb mariadb -u <user> -p<password> <database> < mycourt-pay.sql
```

**Important**: After importing, check `bs_squares_pricing.date_end` -- pricing entries must cover the current date, otherwise payment options won't appear on the confirmation page.

---

## Manual Installation (without Docker)

1. Setup the local configuration:
   - Rename `config/init.php.dist` to `init.php`
   - Optionally edit and customize the `init.php` values
   - Rename `config/autoload/local.php.dist` to `local.php`
   - Edit `local.php` and insert your database credentials
   - Rename `config/autoload/project.php.dist` to `project.php`
   - Edit `project.php` with your URLs and feature flags
   - Rename `public/.htaccess_original` to `.htaccess`
     (if you experience webserver problems, try `public/.htaccess_alternative`)

2. Enable UNIX write permission for:
   - `data/cache/`
   - `data/log/`
   - `data/session/`
   - `public/docs-client/upload/`
   - `public/imgs-client/upload/`

3. Import database: `data/db/ep3-bs.sql`

4. Migrations run automatically on first request (indexes, member emails table, etc.)

5. Optionally customize public files:
   - `css-client/default.css` for custom CSS
   - `imgs-client/icons/fav.ico`
   - `imgs-client/layout/logo.png` (75x75)

---

## Configuration Files

| File | Purpose | Git Status |
|------|---------|-----------|
| `.env` | Docker environment variables | `.gitignore` (from `.env.example`) |
| `config/autoload/local.php` | DB, mail, payment API keys | `.gitignore` (from `.dist`) |
| `config/autoload/project.php` | URLs, session, payment toggles | `.gitignore` (from `.dist`) |
| `config/init.php` | Dev mode, timezone, error reporting | `.gitignore` (from `.dist`) |

---

## Database Migrations

Base schema must be imported manually: `data/db/ep3-bs.sql`

All subsequent migrations run **automatically** on app startup. The system checks which migrations are needed and only executes missing ones. Schema version is tracked in `bs_options` (key: `schema.version`).

| Migration | Purpose | Auto |
|-----------|---------|------|
| `data/db/ep3-bs.sql` | Base schema | Manual |
| `data/db/migrations/001-add-indexes.sql` | Performance indexes | Auto |
| `data/db/migrations/002-member-emails.sql` | Member email verification table | Auto |

Migration registry: `data/db/migrations.php`

To add a new migration:
1. Create SQL file in `data/db/migrations/`
2. Add entry to `data/db/migrations.php` with a check query and file path

---

## Deployment

Once you are satisfied with the system and want to use it in production, please make sure to set the **Apache document root directly to the `public` directory** so that your domain reads like:

`https://example.com/`

And not like:

`https://example.com/public/`

The latter is a security threat, only acceptable while testing.

You may also use a subdomain: `https://bookings.example.com/`

---

## Custom Modules

Copy custom or third-party modules into the `modulex` directory and they will be loaded automatically.

---

## Issues

If you run into any issues, check the [GitHub issue section](https://github.com/zebinho20-belenus/ep3bs-payment/issues).

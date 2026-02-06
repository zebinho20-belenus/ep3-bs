# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Fork of ep3-bs (tkrebs/ep3-bs v1.7.0) — an online booking system for courts (e.g. tennis courts) built on **Zend Framework 2**. Extended with direct payment via **Payum** (PayPal, Stripe with SCA, Klarna), PWA support, member/non-member pricing, budget/gift card system, and door code integration (Loxone MiniServer).

## Build & Run Commands

```bash
# 1. Setup environment config
cp .env.example .env                                        # adjust values in .env
cp config/autoload/local.php.dist config/autoload/local.php # DB, mail, payment keys
cp config/autoload/project.php.dist config/autoload/project.php # URLs, feature flags

# 2. Build and start (Traefik + MariaDB + MailHog + Apache)
docker compose build
docker compose up -d

# 3. Install/update PHP dependencies (run inside container)
docker compose exec court composer update

# App available at https://court.localhost (self-signed cert)
# Traefik dashboard at http://localhost:8080
# MailHog UI at http://localhost:8025
```

There is no test runner configured yet (PHPUnit skeleton exists in `module/User/test/` but CI test step is commented out).

## Configuration Setup

Docker environment variables are in `.env` (from `.env.example`). Additionally, PHP config `.dist` files must be copied and configured:
- `config/autoload/local.php.dist` → `config/autoload/local.php` (DB credentials, mail, payment API keys)
- `config/autoload/project.php.dist` → `config/autoload/project.php` (instance URLs, session config, payment method toggles, feature flags)
- `config/init.php.dist` → `config/init.php` (dev mode flag, timezone, error reporting)

The `.dist` files contain Docker-friendly defaults (DB hostname `mariadb`, MailHog SMTP on port 1025).

Database schema: `data/db/ep3-bs.sql`

### Loading a Production DB Dump

```bash
# Fetch SQL dump from prod server (adjust credentials/path)
scp user@your-server:/backup/mycourt-pay.sql .

# Import into local MariaDB (adjust credentials)
docker compose exec -T mariadb mariadb -u <user> -p<password> <database> < mycourt-pay.sql
```

**Important**: After importing, check `bs_squares_pricing.date_end` — pricing entries must cover the current date, otherwise `$payable` stays `false` and payment options (PayPal etc.) won't appear on the confirmation page.

## Architecture

**PHP 8.1 / Zend Framework 2 MVC** with a custom Entity-Manager-Service layered pattern:

```
Entity (data container, extends AbstractEntity)
  → Manager (CRUD + DB transactions via TableGateway, extends AbstractManager)
    → Service (business logic)
      → Controller (HTTP layer)
        → View (.phtml templates via Zend\View)
```

### Modules (`module/`)

| Module | Role |
|--------|------|
| **Base** | Core utilities, AbstractEntity/AbstractManager, view helpers, OptionManager, ConfigManager, MailService |
| **Backend** | Admin dashboard — user management, booking management, system configuration |
| **Booking** | Booking creation/management, BookingService, BookingManager, billing, email notifications (NotificationListener) |
| **Square** | Court/square definitions, public booking UI (customization, confirmation views) |
| **Calendar** | Calendar widget rendering |
| **Event** | Events and court closures |
| **Frontend** | Public-facing index/calendar page |
| **User** | Authentication, account management, user metadata |
| **Payment** | Payum integration — PayPal, Stripe (card, SEPA, iDEAL, giropay), Klarna; Stripe webhook handler |
| **SquareControl** | Door code generation for Loxone MiniServer (toggled via config) |
| **Service** | Shared cross-module services |
| **Setup** | Installation wizard |

Zend Framework packages are individually forked into `src/Zend/` with PSR-4 autoloading in `composer.json` (not the ZF2 metapackage).

### Meta Properties Pattern

Core entities (Booking, Reservation, Event, Square, User) use a parallel `*_meta` table (key-value) for flexible extensibility. For example, `bs_bookings` holds fixed columns while `bs_bookings_meta` holds arbitrary metadata keyed by booking ID.

### Routing

Defined in each module's `config/module.config.php`. Key routes:
- `/` — Frontend calendar
- `/square/booking/*` — Booking flow (customization → confirmation → payment)
- `/backend/*` — Admin area
- `/payment/booking/*` — Payment processing and Stripe webhooks
- `/user/*` — Login, account

### Frontend Assets

- `public/js/` — jQuery, jQuery UI, TinyMCE, controller-specific scripts in `js/controller/`
- `public/css/` — Main styles in `default.css`, client customizations in `css-client/`
- `public/js/sw.js` + `manifest.json` — PWA service worker

### Payment Flow

Uses Payum framework with token-based security. Stripe supports PaymentIntents (SCA), webhooks for async payment confirmation, and multiple methods (card, SEPA, iDEAL, giropay, Apple Pay, Google Pay). Stripe twig templates live in `vendor/payum/stripe/`.

Payment options (PayPal/Stripe/Klarna) are only shown on the confirmation page when `$payable == true`, which requires `$total > 0`. The total is calculated via `SquarePricingManager::getFinalPricingInRange()` using `bs_squares_pricing` — if no pricing rule matches the booking date range, `$total` stays 0 and no payment buttons appear. Key pricing logic is in `module/Square/src/Square/Controller/BookingController.php`.

Unpaid bookings are auto-removed via a MySQL scheduled event (every 15 min, bookings older than 3 hours with `directpay=true` and `status_billing=pending`).

### Budget (Guthaben) System

Users can have a prepaid budget stored in `bs_users_meta` (key: `budget`, value in EUR). Admin-editable in Backend → User Edit.

**Budget payment flow** (`BookingController.php`):
1. Budget check: `$user->getMeta('budget') > 0 && $total > 0 && ($guestPlayerCheckbox != 1 || $member)`
2. Budget covers full amount → `$budgetpayment = true`, `$payable = false` → "Mit Budget zahlen" button
3. Budget partial → remaining amount charged via PayPal/Stripe/Klarna
4. Budget deducted: immediately for budget-only; after gateway success for partial payments
5. Budget info stored in booking meta: `hasBudget`, `budget`, `newbudget`, `budgetpayment`

**Budget refund on cancellation**: budget is restored to user account.

### Member/Guest Pricing Logic

Pricing rules in `bs_squares_pricing` with `member` column (0=non-member, 1=member):
- **Members** (member=1): use member pricing from DB (currently 0 = free)
- **Non-members** (member=0): pay full non-member price
- **Member with guest** (gp=1, member=1): pays **50% of non-member price** — only members get the 50% discount
- **Non-member with guest** (gp=1, member=0): pays **full non-member price** (no discount)

### Booking Email Notifications

Email is sent via event-driven system: `BookingService::createSingle()` triggers `create.single` event → `NotificationListener::onCreateSingle()` composes and sends email.

**Important**: Email is sent DURING `createSingle()`, so all payment/budget metadata must be included in the `$meta` array BEFORE calling `createSingle()`. Meta set after `createSingle()` (e.g., `setMeta()` calls) won't appear in the email.

Key file: `module/Booking/src/Booking/Service/Listener/NotificationListener.php`

Email includes: booking details, player names, itemized bill, payment information (method + budget deduction), guest payment instructions (only when not paid by budget/gateway).

### Dependency Injection

Zend ServiceManager with Factory classes (e.g., `BookingServiceFactory`). Factories implement `FactoryInterface` and are registered in each module's `module.config.php`.

## Coding Standards

- **PSR-4** autoloading: `\{Module}\{Class}` maps to `module/{Module}/src/{Module}/{Class}.php`
- Naming: `*Controller`, `*Manager`, `*Service`, `*Table`, `*Factory`, `*Entity`
- Views: `module/{Module}/view/{module-lowercase}/{controller}/{action}.phtml`
- Config per module: `module/{Module}/config/module.config.php`
- Translations: `data/res/i18n/de-DE/{module}.php` — key = English, value = German

## Docker Setup

Single `Dockerfile` (PHP 8.1-apache) for both DEV and PROD. Three compose files:
- `docker-compose.yml` — production-compatible base (court, mariadb, mailhog + Traefik labels, external `traefik_web` network)
- `docker-compose.override.yml` — local dev additions (Traefik service, self-signed HTTPS, local `traefik_web` network)
- `docker-compose.dev-server.yml` — DEV instance on server alongside production (separate service names, Traefik routers, DB port)

```bash
# Local dev (override auto-loaded):
docker compose up -d

# Production (base only, uses external Traefik):
docker compose -f docker-compose.yml up -d

# DEV on server (alongside production):
docker compose -f docker-compose.dev-server.yml up -d
```

| Service | Default Port | Purpose |
|---------|-------------|---------|
| traefik | 80, 443, 8080 | Reverse proxy with HTTPS (self-signed locally, Let's Encrypt on prod), dashboard |
| court | (via Traefik) | PHP 8.1 Apache app server |
| mariadb | 3306 | MariaDB |
| mailhog | 8025 (UI) | Email testing (SMTP 1025 internal) |

**DEV vs PROD** is toggled via `INSTALL_XDEBUG` in `.env`:
- `INSTALL_XDEBUG=true` — installs Xdebug 3 (port 9003, IDE key: PHPSTORM)
- `INSTALL_XDEBUG=false` — no debug tools, production-ready

**Composer** is NOT run during Docker build. `vendor/` is committed to git (matching production workflow). The volume mount `./:/var/www/html` provides `vendor/` at runtime. Run `docker compose exec court composer update` to update dependencies.

**macOS Docker Desktop**: set `DOCKER_SOCKET=~/.docker/run/docker.sock` in `.env` if Traefik can't reach the Docker socket.

## Writable Directories

`data/cache/`, `data/log/`, `data/session/`, `public/docs-client/upload/`, `public/imgs-client/upload/`

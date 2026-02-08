# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Fork of ep3-bs (tkrebs/ep3-bs v1.7.0) — an online booking system for courts (e.g. tennis courts) built on **Zend Framework 2**. Extended with direct payment via **Payum** (PayPal, Stripe with SCA, Klarna), PWA support, member/non-member pricing, budget/gift card system, and door code integration (Loxone MiniServer).

## 🚀 Migration zu Laravel 11 (v4.1)

**Status:** Migration geplant im Branch `dev_sh_laravel_migration`

Vollständige Migration von **Zend Framework 2** zu **Laravel 11 + Inertia.js + Vue 3 + PrimeVue 4**.

### Wichtigste Dokumente:

| Dokument | Zweck | Status |
|----------|-------|--------|
| **MIGRATION-PLAN.md** | Vollständiger Migrationsplan (10 Phasen, 456-629h) | ✅ Final v4.1 |
| **FEATURE-CHECKLIST.md** | IST/SOLL Feature-Abgleich (100% Parität) | ✅ Komplett |
| **CLAUDE.md** | Dokumentation aktuelles ZF2-System | ✅ Dieses Dokument |

### Technologie-Stack (SOLL):

| Layer | ZF2 (IST) | Laravel (SOLL) |
|-------|-----------|----------------|
| Backend | Zend Framework 2, PHP 8.1 | **Laravel 11, PHP 8.3** |
| Frontend | Bootstrap 5 + jQuery | **Vue 3 + PrimeVue 4** |
| Build | Manuell minifiziert | **Vite** |
| Payment | Payum (PayPal/Stripe/Klarna) | **srmklive/paypal** + stripe/stripe-php |
| PWA | Manueller Service Worker | **Vite PWA Plugin** |

### Kernfeatures (100% übernommen):

✅ Single + Subscription Bookings
✅ Pricing Engine (4-way Matrix: Member/Non-Member/Guest)
✅ Budget-System (Prepaid, Refunds)
✅ PayPal Primary + Stripe Optional
✅ Loxone Door Control
✅ Email-Benachrichtigungen (iCal)
✅ Responsive Calendar (Mobile-First)
✅ Backend Admin (Booking Range, Bills Editor)
✅ TinyMCE Rich Text Editor
✅ File Upload (Images)

### Aufwand:

- **Ohne Stripe:** 456–629h (~11–16 Wochen Vollzeit)
- **Mit Stripe:** 496–689h (~12–17 Wochen Vollzeit)

### Git-Workflow:

```bash
# AKTUELLES System (ZF2):
git checkout dev_sh_docker_devops

# MIGRATION (Laravel):
git checkout dev_sh_laravel_migration
```

**⚠️ WICHTIG:** Während der Migration NIEMALS in `dev_sh_docker_devops` pushen!

Siehe **MIGRATION-PLAN.md** für Details zu allen 10 Phasen.

---

## Build & Run Commands (ZF2 - Aktuelles System)

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
- `/backend/booking` — Booking list with edit/cancel/delete actions
- `/backend/booking/delete/:rid` — Booking cancel/delete confirmation page
- `/payment/booking/*` — Payment processing and Stripe webhooks
- `/user/*` — Login, account

### Frontend Assets & UI Framework

- **Bootstrap 5.3.3** loaded locally from `public/vendor/bootstrap/css/bootstrap.min.css` + JS bundle
- **Custom CSS** in `public/css/app.css` — design tokens, BS5 overrides, legacy compatibility; copied to `app.min.css`
- CSS load order: `bootstrap.min.css` → `jquery-ui.min.css` → `app.css` → `font-awesome` → `tennis-tcnkail.min.css`
- `public/js/` — jQuery, jQuery UI, TinyMCE, controller-specific scripts in `js/controller/`
- `public/js/sw.js` + `manifest.json` — PWA service worker

**View helpers** (Base module, registered in `module/Base/config/module.config.php`):
- Form helpers: `FormDefault`, `FormRowDefault`, `FormRowSubmit`, `FormRowCheckbox`, `FormRowCompact`, `FormElementErrors`, `FormElementNotes`
- Layout helpers: `HeaderLocaleChoice`, `SessionUser` (provides logged-in user to layout for admin nav)
- Display helpers: `Message`, `Messages`, `Tabs`, `Links`, `Setup`

**Form helper output** (important for layout decisions):
- `FormRowDefault` → `<div class="mb-3"><label class="form-label">...</label><div><input class="form-control">...</div></div>` — use directly in BS5 grid, do NOT wrap in `<table>`
- `FormRowCompact` → same but with `form-control-sm`/`form-select-sm` and `mb-2` — for compact forms in sandboxes
- `FormRowSubmit` → `<div class="mb-3"><input class="btn btn-primary"></div>`

**Layout** (`module/Base/view/layout/layout.phtml`): BS5 navbar + `container-xl` + footer. Content wrapped in `.content-panel` div with panel class from `$this->placeholder('panel')` (e.g. `centered-panel`, `phantom-panel`).

**Squarebox (calendar popup)**: jQuery-based modal loaded via AJAX. Two modes:
- **Desktop** (`squarebox-desktop` class): `position: absolute`, `max-width: 720px`, 2-column CSS grid layout for the booking form (4 sections in 2x2 grid). Centered via jQuery UI `.position()`.
- **Mobile** (`squarebox-mobile` class): `position: fixed`, `90vw` width, `max-height: 90vh`, `overflow-y: auto`, sections stacked vertically. BS5 `.form-select` needs `display: inline-block; width: auto` override for centering.
- JS source in `public/js/controller/calendar/index.js` + manually minified `index.min.js` (no build tool — **both must be kept in sync**).

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

**Budget refund on cancellation or deletion**: budget is restored to user account. Refund logic exists in both the cancel path and the delete path of `Backend\Controller\BookingController` (checks `status_billing == 'paid'` and `refunded != 'true'`).

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

### Backend Booking Management

**Booking list** (`/backend/booking`): Sortable table (panel: `giant-sized`, 1280px) with 13 columns. Uses `table-layout: fixed` with progressive column hiding via `responsive-pass-*` CSS classes. Action links are icon-only with `title` tooltips.

**Column visibility by breakpoint**:
- ≥1536px: all 13 columns
- ≤1280px (pass-2): hide Member, Billing Status
- ≤1024px (pass-3): hide Day, Notes, Budget
- ≤768px (pass-4): hide Court
- ≤512px (pass-5): hide Nr., Price → 5 columns remain: Status, Name, Date, Time, Actions

**Row actions** (icon-only, no text labels):
- **Active bookings**: Edit (symbolic-edit) + Cancel (symbolic-cross)
- **Cancelled bookings, slot free**: Edit + Reactivate (symbolic-reload) + Delete (symbolic-cross)
- **Cancelled bookings, slot occupied**: Edit + Delete (no Reactivate)

**Reactivate collision check**: `BookingFormat` has `ReservationManager` + `BookingManager` injected via `BookingFormatFactory`. Before showing the reactivate icon, it calls `getInRange()` to check for overlapping active bookings on the same court.

**Delete confirmation page** (`delete.phtml`): Uses `.edit-actions` flex container for button group. Delete button only visible to admin users (`admin.all` permission). Cancel sets status to `cancelled`. Both paths refund budget if paid.

**Booking format helper**: `Backend\View\Helper\Booking\BookingFormat` — renders each booking row including status badges (E/A/S), billing status badges, budget info, and conditional reactivation link.

**Table sort/filter JS** (`public/js/controller/backend/table-sort.js` + `table-sort.min.js`): Adds sortable headers, per-column filter inputs. Filter `<td>` cells inherit `responsive-pass-*` classes from their `<th>` headers. **Both files must be kept in sync** (no build tool).

### Backend Form Layout Pattern

All backend edit forms use **Bootstrap 5 grid** (`row`/`col-md-6`) instead of `<table>` wrappers:
- `booking/edit.phtml` — 2x2 grid (`col-md-6`), 4 sandboxes
- `event/edit.phtml` — `row`/`col-md-6` for date/time pairs
- `config-square/edit.phtml` — 2-column (`col-lg-6`): General + Time sections
- `booking/edit-range.phtml` — `row`/`col-sm-4` in sandbox
- `user/edit.phtml` — `row`/`col-lg-6` for account + personal data

**Button groups** use `.edit-actions` CSS class (flex container):
- Desktop: horizontal, centered, `gap: 0.75rem`, `min-width: 140px`
- Mobile (≤1024px): stacked vertically, full-width

**Button variants** (CSS classes on `.default-button`):
- `.default-button-danger` — red (#DC2626) for delete/cancel actions
- `.default-button-outline` — transparent bg, border, for secondary actions

**NOT converted** (valid table use): `booking/bills.phtml`, `booking/players.phtml`

### Time Dropdowns

ALL backend time-of-day fields use Select dropdowns with full hours (07:00–22:00). Minute-based fields (`cf-time-block`, etc.) remain as Text inputs. Forms: `Booking/EditForm`, `Booking/Range/EditTimeRangeForm`, `Event/EditForm`, `ConfigSquare/EditForm`.

### Backend User List

**User list** (`/backend/user`): Panel `giant-sized` (1280px). 7 columns with responsive hiding:
- ≤1280px (pass-2): hide Notes
- ≤1024px (pass-3): hide Email

Action links are icon-only: Edit (symbolic-edit) + Bookings (symbolic-booking).

### Dependency Injection

Zend ServiceManager with Factory classes (e.g., `BookingServiceFactory`). Factories implement `FactoryInterface` and are registered in each module's `module.config.php`.

## Coding Standards

- **PSR-4** autoloading: `\{Module}\{Class}` maps to `module/{Module}/src/{Module}/{Class}.php`
- Naming: `*Controller`, `*Manager`, `*Service`, `*Table`, `*Factory`, `*Entity`
- Views: `module/{Module}/view/{module-lowercase}/{controller}/{action}.phtml`
- Config per module: `module/{Module}/config/module.config.php`
- Translations: `data/res/i18n/de-DE/{module}.php` — key = English, value = German

## Key File Locations

| Area | File |
|------|------|
| Booking controller (payment logic) | `module/Square/src/Square/Controller/BookingController.php` |
| Backend booking controller (cancel/delete) | `module/Backend/src/Backend/Controller/BookingController.php` |
| Backend booking list format helper | `module/Backend/src/Backend/View/Helper/Booking/BookingFormat.php` |
| Booking confirmation view | `module/Square/view/square/booking/confirmation.phtml` |
| Email notification listener | `module/Booking/src/Booking/Service/Listener/NotificationListener.php` |
| Pricing manager | `module/Square/src/Square/Manager/SquarePricingManager.php` |
| Pricing summary (view helper) | `module/Square/src/Square/View/Helper/PricingSummary.php` |
| Stripe webhook handler | `module/Payment/src/Payment/Controller/PaymentController.php` |
| Backend user edit (budget field) | `module/Backend/src/Backend/Form/User/EditForm.php` |
| Backend booking delete confirmation | `module/Backend/view/backend/booking/delete.phtml` |
| Layout template | `module/Base/view/layout/layout.phtml` |
| Custom CSS | `public/css/app.css` (+ `app.min.css` copy) |
| Calendar squarebox JS | `public/js/controller/calendar/index.js` (+ `index.min.js`) |
| Table sort/filter JS | `public/js/controller/backend/table-sort.js` (+ `table-sort.min.js`) |
| Booking format factory | `module/Backend/src/Backend/View/Helper/Booking/BookingFormatFactory.php` |
| Bookings table headers | `module/Backend/src/Backend/View/Helper/Booking/BookingsFormat.php` |
| User table headers | `module/Backend/src/Backend/View/Helper/User/UsersFormat.php` |
| User table rows | `module/Backend/src/Backend/View/Helper/User/UserFormat.php` |
| Translations (German) | `data/res/i18n/de-DE/booking.php`, `square.php`, `backend.php` |
| Backend pricing config view | `module/Backend/view/backend/config-square/pricing.phtml` |

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

---

## 🚀 Migration zu Laravel 11 - Nächste Schritte

### Vorbereitung (bereits erledigt ✅):

- ✅ Vollständige Code-Analyse (307 PHP-Dateien, 13 Module, 15 Tabellen)
- ✅ Migration Plan v4.1 erstellt (10 Phasen, 456-629h)
- ✅ Feature-Checklist (100% Parität)
- ✅ Git Branch `dev_sh_laravel_migration` erstellt

### Phase 1: Foundation (Start) - 60–80h

```bash
# 1. Branch wechseln
git checkout dev_sh_laravel_migration

# 2. Laravel 11 Init (in neuem Verzeichnis)
mkdir ep3-bs-laravel && cd ep3-bs-laravel
composer create-project laravel/laravel . "11.*"

# 3. Packages installieren
composer require inertiajs/inertia-laravel tightenco/ziggy srmklive/paypal
npm install @inertiajs/vue3 vue @vitejs/plugin-vue
npm install primevue primeicons @primevue/themes
npm install tailwindcss @tailwindcss/forms

# 4. Breeze (Auth Scaffolding)
composer require laravel/breeze --dev
php artisan breeze:install vue --typescript

# 5. Erstes Commit
git add .
git commit -m "[Phase 1] Foundation: Laravel 11 + Breeze + Inertia + PrimeVue"
git push
```

### Wichtigste Tasks Phase 1:

1. **Eloquent Models** (15 Tabellen):
   - User, Booking, Square, Reservation, Event
   - Meta-Tabellen (UserMeta, BookingMeta, etc.)
   - `HasMeta` Trait für Meta-Pattern

2. **Seeders**:
   - Faker Data für Development
   - Test-User mit Budget

3. **Layouts**:
   - AppLayout.vue (PrimeVue Menubar)
   - BackendLayout.vue (PrimeVue PanelMenu + Drawer)

4. **i18n**:
   - laravel-vue-i18n Setup
   - Migration de-DE Translations

5. **Docker**:
   - PHP 8.3-fpm + Nginx
   - docker-compose.yml anpassen

### Dokumentation:

| Dokument | Inhalt |
|----------|--------|
| **MIGRATION-PLAN.md** | Vollständiger Plan (10 Phasen mit Code-Beispielen) |
| **FEATURE-CHECKLIST.md** | IST/SOLL Abgleich, Prioritäten |
| **CLAUDE.md** | ZF2-System Dokumentation (dieses Dokument) |

### Zeitplan (geschätzt):

| Phase | Wochen | Meilenstein |
|-------|--------|-------------|
| 1. Foundation | 1-2 | Laravel läuft, Models verbinden zu DB |
| 2. PayPal | 1 | Payment funktioniert |
| 3. Booking + Subscription | 2-3 | Buchungsflow komplett |
| 4. Calendar | 1-2 | Responsive Kalender |
| 5. Backend Admin | 2 | Admin kann alles verwalten |
| 6-9. Rest | 4-5 | Door Control, Content, PWA, Deploy |

**Gesamt:** 11-16 Wochen Vollzeit (456-629h)

### Git-Workflow während Migration:

```bash
# Feature-Branch erstellen
git checkout dev_sh_laravel_migration
git checkout -b feature/phase-1-foundation

# Arbeiten...
git add .
git commit -m "[Phase 1] Task: Description"

# Merge zurück
git checkout dev_sh_laravel_migration
git merge feature/phase-1-foundation
git push
```

**⚠️ WICHTIG:**
- NIEMALS in `dev_sh_docker_devops` pushen während Migration
- Nur in `dev_sh_laravel_migration` arbeiten
- ZF2-System bleibt funktionsfähig bis Migration komplett

### Bei Fragen:

Siehe **MIGRATION-PLAN.md** für:
- Detaillierte Code-Beispiele (PHP + Vue)
- Service Layer Mappings (ZF2 → Laravel)
- Alle 10 Phasen mit Tasks
- Testing-Strategie
- Deployment-Plan

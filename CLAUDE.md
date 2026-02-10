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
- `config/autoload/project.php.dist` → `config/autoload/project.php` (instance URLs, session config, payment method toggles, PayPal email for manual payments, feature flags)
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
- Display helpers: `Message`, `Messages`, `Tabs`, `Links` (accepts `$position`: `'top'` or `'bottom'`), `Setup`

**Form helper output** (important for layout decisions):
- `FormRowDefault` → `<div class="mb-3"><label class="form-label">...</label><div><input class="form-control">...</div></div>` — use directly in BS5 grid, do NOT wrap in `<table>`
- `FormRowCompact` → same but with `form-control-sm`/`form-select-sm` and `mb-2` — for compact forms in sandboxes
- `FormRowSubmit` → `<div class="mb-3"><input class="btn btn-primary"></div>`

**Layout** (`module/Base/view/layout/layout.phtml`): BS5 navbar + `container-xl` + footer. Content wrapped in `.content-panel` div with panel class from `$this->placeholder('panel')` (e.g. `centered-panel`, `phantom-panel`). Navigation links (`$this->links()`) rendered both above (`'top'`: `border-bottom`) and below (`'bottom'`: `border-top`) the content panel (#52). Pages without links placeholders render nothing.

**Squarebox (calendar popup)**: jQuery-based modal loaded via AJAX. Two modes:
- **Desktop** (`squarebox-desktop` class): `position: absolute`, `max-width: 720px`, 2-column CSS grid layout for the booking form (4 sections in 2x2 grid). Centered via jQuery UI `.position()`.
- **Mobile** (`squarebox-mobile` class): `position: fixed`, `90vw` width, `max-height: 90vh`, `overflow-y: auto`, sections stacked vertically. BS5 `.form-select` needs `display: inline-block; width: auto` override for centering.
- JS source in `public/js/controller/calendar/index.js` + manually minified `index.min.js` (no build tool — **both must be kept in sync**).

### Payment Flow

Uses Payum framework with token-based security. Stripe supports PaymentIntents (SCA), webhooks for async payment confirmation, and multiple methods (card, SEPA, iDEAL, giropay, Apple Pay, Google Pay). Stripe twig templates live in `vendor/payum/stripe/`.

Payment options (PayPal/Stripe/Klarna) are only shown on the confirmation page when `$payable == true`, which requires `$total > 0`. The total is calculated via `SquarePricingManager::getFinalPricingInRange()` using `bs_squares_pricing` — if no pricing rule matches the booking date range, `$total` stays 0 and no payment buttons appear. Key pricing logic is in `module/Square/src/Square/Controller/BookingController.php`.

Unpaid bookings are auto-removed via a MySQL scheduled event (every 15 min, bookings older than 3 hours with `directpay=true` and `status_billing=pending`).

### Manual Payment Instructions (PayPal Friends & Family)

For guest bookings where the user chooses "Pay Later", the system displays payment instructions with a PayPal email address for manual Friends & Family payments.

**Configuration** (`config/autoload/project.php`):
```php
'paypalEmail' => 'payment@your-domain.com',
```

**Implementation:**
- Translation strings use `%s` placeholder for email address
- Email injected via `sprintf()` at runtime
- Used in 3 locations:
  - `NotificationListener::onCreateSingle()` — booking confirmation email
  - `Square\BookingController` — flash message on confirmation page
  - `Backend\BookingController` — backend booking email
- Fallback: `'payment@your-domain.com'` if config not set

**Translation keys** (`data/res/i18n/de-DE/booking.php`):
- `'Please transfer the amount via PayPal Friends & Family to %s or use the money letterbox at the office.'`
- `'Please pay the booking amount via PayPal Friends & Family to %s or use the money letterbox at the office.'`

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

**Booking list** (`/backend/booking`): Sortable table (panel: `giant-sized`, 1280px) with 13 columns. Uses `table-layout: fixed` with progressive column hiding via `responsive-pass-*` CSS classes. Action links are icon-only with `title` tooltips. Time column uses compact format `08:00-09:00` (no "bis"/"Uhr").

**Column width classes** (CSS on both `<th>` and `<td>`):
- `.status-col` 3rem, `.nr-col` 3rem, `.member-col` 3.5rem, `.court-col` 5rem, `.price-col` 4.5rem, `.budget-col` 5rem
- `.notes-col` auto-width (no max-width constraint), `.bulk-check-col` 36px
- Remaining columns (Name, Day, Date, Time, Billing, Actions) auto-size with leftover space

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

## Known Issues & Fixes

### Calendar Booking Overlays Duplication (Fixed Feb 2026)

**Problem:** Calendar bookings appeared duplicated or shifted to wrong time slots after any layout change (window resize, flash messages appearing/disappearing, creating/canceling bookings).

**Root Cause:** The `updateCalendarEvents()` function in `public/js/controller/calendar/index.js` creates overlay elements (with IDs ending in `-overlay-`) for multi-slot bookings to visually span multiple table cells. This function is called on:
- Initial page load
- Every `window.resize` event
- Custom `updateLayout` events

The function checked `if (!eventGroupOverlay.length)` before creating overlays, but this check failed to prevent duplicates because stale DOM references were being reused. Each resize event created NEW overlays without removing the old ones → visual duplication.

**Solution:** Added cleanup at the start of `updateCalendarEvents()`:
```javascript
function updateCalendarEvents() {
    // Remove all existing overlays before recreating
    $("[id$='-overlay-']").remove();

    // ... rest of function
}
```

**Files changed:**
- `public/js/controller/calendar/index.js` (line 287-288)
- `public/js/controller/calendar/index.min.js` (same fix)

**Note:** This issue was initially misdiagnosed as being caused by flash message wrapper removal. Extensive debugging (including removing all flash wrapper JS) proved the calendar JS was the actual culprit.

### Backend Booking List Out-of-Range Abo Reservations (Fixed Feb 2026, #47)

**Problem:** Backend booking list (`/backend/booking`) showed too many rows when searching by date range. Subscription (Abo) bookings with reservations outside the date range appeared in the table, causing wrong row counts and broken column filters.

**Root Cause:** In `Backend\BookingController::indexAction()`, after finding bookings with reservations in the date range, `getByBookings($bookings)` re-fetches ALL reservations for matched bookings — including those outside the date range. For Abo bookings with weekly reservations spanning months, this brought back dozens of extra rows.

**Solution:** Added `array_filter()` after `getByBookings()` to remove reservations whose date falls outside `[$dateStart, $dateEnd]`.

**File changed:** `module/Backend/src/Backend/Controller/BookingController.php` (lines 61-69)

## Writable Directories

`data/cache/`, `data/log/`, `data/session/`, `public/docs-client/upload/`, `public/imgs-client/upload/`

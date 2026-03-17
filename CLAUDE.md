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
- `config/init.php.dist` → `config/init.php` (dev mode flag `EP3_BS_DEV_TAG` — default: `false`, timezone, error reporting)

The `.dist` files contain Docker-friendly defaults (DB hostname `mariadb`, MailHog SMTP on port 1025).

Database schema: `data/db/ep3-bs.sql`. Migrations in `data/db/migrations/` with registry in `data/db/migrations.php`. Migrations run automatically on app startup via `MigrationManager` (checks `bs_options` key `schema.version`).

### Loading a Production DB Dump

```bash
# Fetch SQL dump from prod server (adjust credentials/path)
scp user@your-server:/backup/mycourt-pay.sql .

# Import into local MariaDB (adjust credentials)
docker compose exec -T mariadb mariadb -u <user> -p<password> <database> < mycourt-pay.sql
```

**Important**: After importing, check `bs_squares_pricing.date_end` — pricing entries must cover the current date, otherwise `$payable` stays `false` and payment options (PayPal etc.) won't appear on the confirmation page.

## Architecture

**PHP 8.4 / Zend Framework 2 MVC** with a custom Entity-Manager-Service layered pattern:

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
| **Payment** | Payum integration — PayPal, Stripe (card, SEPA, iDEAL, giropay), Klarna; Stripe webhook handler. **NOT loaded as Zend module** (not in `config/application.php`). Its routes/controllers are never dispatched by Zend. All payment actions (confirm, done, webhook, pay) actually run via `Square\Controller\BookingController` with routes under `/square/booking/payment/*`. The Payment module code exists but is only used by `PaymentService` (service layer). |
| **SquareControl** | Door code generation for Loxone MiniServer (toggled via config) |
| **Service** | Shared cross-module services |
| **Setup** | Installation wizard |

**Important — Module loading**: Only modules listed in `config/application.php` (+ auto-discovered in `modulex/`) are loaded. The **Payment module is NOT listed** there. New routes and controller actions for payment must be added to the **Square module** (`Square\Controller\BookingController`), not the Payment module.

Zend Framework packages are individually forked into `src/Zend/` with PSR-4 autoloading in `composer.json` (not the ZF2 metapackage).

### Meta Properties Pattern

Core entities (Booking, Reservation, Event, Square, User) use a parallel `*_meta` table (key-value) for flexible extensibility. For example, `bs_bookings` holds fixed columns while `bs_bookings_meta` holds arbitrary metadata keyed by booking ID.

### Routing

Defined in each module's `config/module.config.php`. Key routes:
- `/` — Frontend calendar
- `/square/booking/*` — Booking flow (customization → confirmation → payment)
- `/square/booking/payment/pay/:bid` — Pay open bill (payLater flow, #72)
- `/square/booking/payment/done` — Payum done callback (PayPal, Klarna)
- `/square/booking/payment/confirm` — Payum confirm callback (Stripe SCA)
- `/backend/*` — Admin area
- `/backend/booking` — Booking list with edit/cancel/delete actions
- `/backend/booking/delete/:rid` — Booking cancel/delete confirmation page
- `/user/*` — Login, account
- `/user/bookings/bills/:bid` — User bill view (with payment buttons for pending bills)

**Note**: Routes in `module/Payment/config/module.config.php` (e.g. `/payment/booking/*`) are **never loaded** because the Payment module is not in `config/application.php`. All payment routes are child routes under `square/booking/` in `module/Square/config/module.config.php`.

### Frontend Assets & UI Framework

- **Bootstrap 5.3.8** loaded locally from `public/vendor/bootstrap/css/bootstrap.min.css` + JS bundle
- **Custom CSS** in `public/css/app.css` — design tokens, BS5 overrides, legacy compatibility; copied to `app.min.css`
- CSS load order: `bootstrap.min.css` → `jquery-ui.min.css` → `app.css` → `font-awesome` → `tennis-tcnkail.min.css`
- **jQuery 3.7.1** + **jQuery UI 1.14.1** (local: `public/js/jquery/`, `public/js/jquery-ui/`)
- **TinyMCE 6.8.5** (local: `public/js/tinymce/`, skin: oxide, theme: silver)
- `public/js/` — controller-specific scripts in `js/controller/`
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

**Payment gateway setup**: The `BookingController::createPaymentAndRedirect()` private method handles Payum token creation and gateway redirect for all payment methods (PayPal, Stripe, Klarna). Used by both `confirmationAction()` and `payAction()` to avoid code duplication.

**Important — Gateway-specific payment responses**: The `$payment` array from Payum `GetHumanStatus` has different keys per gateway. `$payment['status']` is **Stripe-specific** (values: `succeeded`, `processing`, `requires_action`). PayPal responses do NOT have this key. Always guard access with `isset($payment['status'])` or use a `$paymentStatus` variable. The `doneAction()` in `BookingController` extracts `$paymentStatus = isset($payment['status']) ? $payment['status'] : ''` before the success/error check.

Payment options (PayPal/Stripe/Klarna) are only shown on the confirmation page when `$payable == true`, which requires `$total > 0`. The total is calculated via `SquarePricingManager::getFinalPricingInRange()` using `bs_squares_pricing` — if no pricing rule matches the booking date range, `$total` stays 0 and no payment buttons appear. Key pricing logic is in `module/Square/src/Square/Controller/BookingController.php`.

**Guest player parameter**: The `gp` (guest player) flag is passed as view variable `$guestPlayer` from the controller to confirmation.phtml and as optional parameter to view helpers `PricingSummary` and `PricingHints`. Do NOT use `$_GET['gp']` directly in views or services — use the controller-provided value.

Unpaid bookings are auto-removed via a MySQL scheduled event (every 15 min, bookings older than 3 hours with `directpay=true` and `status_billing=pending`).

### Pay Later — Open Bill Payment (#72)

Users with `status_billing=pending` bookings can pay from their bill page (`/user/bookings/bills/:bid`). The bills view shows PayPal/Stripe/Klarna radio buttons when conditions are met (pending, not cancelled, total > 0, gateway configured).

**Flow**: Form POSTs to `/square/booking/payment/pay/:bid` → `BookingController::payAction()` → sets `payLater=true` meta → creates Payum tokens → redirects to gateway → `doneAction()` handles result.

**Key difference from normal booking flow**: The `payLater` meta flag in `doneAction()` prevents `cancelSingle()` on payment failure. On error, only a flash message is shown and the booking remains intact. On success, `status_billing` is set to `paid` and the `payLater` meta is cleaned up.

**Meta fields**: `payLater=true` (set in payAction, cleared in doneAction), `paymentMethod`, `directpay=true`

**PayPal sandbox pending workaround**: PayPal sandbox returns `PAYMENTINFO_0_PAYMENTSTATUS: "Pending"` (reason: `paymentreview`) while `PAYMENTREQUEST_0_PAYMENTSTATUS: "Completed"`. Payum reads the former → reports `pending`. The `doneAction` checks `PAYMENTREQUEST_0_PAYMENTSTATUS == "Completed"` to correctly mark as `paid`. In production, both fields agree, so this is safe.

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

**Budget refund on cancellation or deletion**: budget is restored to user account via `BookingService::refundBudget(Booking $booking)`. This centralized method handles the refund check (`status_billing == 'paid'` and `refunded != 'true'`) and returns the refund amount in cents. Called from Square\BookingController (user cancellation), Backend\BookingController (admin cancel/delete/bulk), and PaymentController (webhook).

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
- **Cancelled bookings, slot free + permission**: Edit + Reactivate (symbolic-reload) + Delete (symbolic-cross)
- **Cancelled bookings, slot occupied or no permission**: Edit + Delete (no Reactivate)

**Reactivate permission (#82)**: Reactivation requires the `calendar.reactivate-bookings` privilege. Admins have it automatically. Assist users need it explicitly granted in user meta (`allow.calendar.reactivate-bookings`). The permission is checked in:
1. `BookingFormat.php` — hides the reactivate icon in the booking list
2. `BookingController::editAction()` — reactivate via edit form
3. `BookingController::deleteAction()` — reactivate via booking list link
4. `BookingController::bulkAction()` — bulk reactivation

**Reactivate collision check**: `BookingFormat` has `ReservationManager` + `BookingManager` injected via `BookingFormatFactory`. Before showing the reactivate icon, it calls `getInRange()` to check for overlapping active bookings on the same court.

**Delete confirmation page** (`delete.phtml`): Uses `<form method="post">` for destructive actions (cancel/delete). Delete button only visible to admin users (`admin.all` permission). Cancel sets status to `cancelled`. Both paths refund budget via `BookingService::refundBudget()`.

**Cancellation page** (`cancellation.phtml`): Also uses `<form method="post">` with hidden `confirmed` field. Shows spinner overlay during processing. Controllers accept both POST and GET params (backward compatible).

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
| Migration registry | `data/db/migrations.php` |
| Migration manager | `module/Base/src/Base/Manager/MigrationManager.php` |
| SQL migrations | `data/db/migrations/001-add-indexes.sql` |
| | `data/db/migrations/002-member-emails.sql` |
| | `data/db/migrations/003-cleanup-interval.sql` |
| | `data/db/migrations/004-cleanup-interval-reset.sql` |
| | `data/db/migrations/005-opening-times.sql` |

## Docker Setup

Single `Dockerfile` (PHP 8.4-apache) for both DEV and PROD. Three compose files:
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
| court | (via Traefik) | PHP 8.4 Apache app server (memory_limit 256M) |
| mariadb | 3306 | MariaDB 10.11 (pinned, with healthcheck) |
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

### Swipe Gesture on Desktop Closes Booking Modal (Fixed Mar 2026, #12)

**Problem:** When editing a booking in the calendar squarebox, dragging the mouse outside the modal (e.g. while selecting text in the "Booked to" field) triggered Hammer.js swipe gestures, navigating to the previous/next day and closing the modal without saving.

**Root Cause:** Hammer.js was initialized on `document.body` for ALL devices, including desktop with mouse. Mouse drag movements were interpreted as swipe gestures.

**Solution:** Two guards added in `module/Base/view/layout/layout.phtml`:
1. **Touch-only check**: `('ontouchstart' in window || navigator.maxTouchPoints > 0)` — Hammer.js swipe only activates on touch devices
2. **Modal guard**: `document.querySelector('.squarebox')` check in swipe handlers — swipe is suppressed when a booking modal is open

### Pending Bookings Color & Calendar Display (Fixed Mar 2026, #79)

**My Bookings:** Future bookings with `status_billing == 'pending'` and `price > 0` get Bootstrap's `table-warning` class (yellow row background) in `module/User/view/user/account/bookings.phtml`. Past bookings remain `text-muted`.

**Calendar "temporär belegt":** Non-admin visitors no longer see "temporär belegt" for pending bookings. `OccupiedForVisitors.php` was changed to show pending bookings as regular "Belegt" (normal `cc-single` style, no `cc-try` orange). Admins/assistants still see "temporär belegt" via `OccupiedForPrivileged.php`.

### Email Formatting & Translated Billing Status (Fixed Mar 2026, #80)

**Problem:** Booking change emails showed raw billing status slugs ("pending → paid" instead of "Ausstehend → Bezahlt"). No billing status shown under bill total. Email lacked visual structure.

**Solution:**
- `$this->t(ucfirst($change['old/new']))` translates status slugs in change emails (`BookingController.php`)
- Billing status line added after Total in both `NotificationListener.php` and `BookingController.php` edit emails
- Separator lines (`str_repeat('-', 40)`) around bill and payment sections for visual block structure
- Translation key `'Billing status'` added to `data/res/i18n/de-DE/booking.php`
- Booking detail lines (Platz, Datum, Zeit, Buchungs-Nr) use single `\n` instead of `\n\n` for compact formatting in Backend BookingController (cancel, reactivate, edit emails)

### Auto-Registration with Member Recognition (Mar 2026, #17)

**Feature:** New "I am a club member" checkbox on registration form. When checked:
- User is auto-activated (`status=enabled`, `member=1`) regardless of activation mode setting
- Email checked against `bs_member_emails` table for verification
- Admin notification includes member status and whether email was found in member list

**Backend UI:** `/backend/config/member-emails` — manage member email list with CSV import (`email,firstname,lastname`) and single add/delete. Accessible from Configuration page.

**Key files:**
- `module/User/src/User/Form/RegistrationForm.php` — `rf-member` checkbox
- `module/User/src/User/Controller/AccountController.php` — auto-activation logic
- `module/Backend/src/Backend/Controller/ConfigController.php` — `memberEmailsAction()`
- `module/Backend/src/Backend/Manager/MemberEmailManager.php` — CRUD + CSV import
- `module/Backend/src/Backend/Entity/MemberEmail.php` — Entity (meid, email, firstname, lastname)
- `data/db/migrations/002-member-emails.sql` — table creation

**Migration required:** Run `002-member-emails.sql` to create `bs_member_emails` table.

### Security Hardening (Mar 2026)

Comprehensive OWASP Top 10 security audit and hardening. Key changes:

| Category | Changes |
|----------|---------|
| **Server** | `expose_php = Off`, `ServerTokens Prod`, `ServerSignature Off`, `mod_headers` enabled |
| **HTTP Headers** | HSTS, X-Frame-Options SAMEORIGIN, X-Content-Type-Options nosniff, Referrer-Policy, Permissions-Policy (in `public/.htaccess`) |
| **Session** | `SameSite=Lax` (was `None`) in `project.php.dist` |
| **SQL Injection** | Prepared statements in Backend BookingController (UPDATE/DELETE), `Zend\Db\Sql\Where` predicates in ReservationManager |
| **XSS** | `htmlspecialchars()` for Stripe errors in flash messages, `json_encode()` for JS context in confirmation.phtml, `escapeHtml()` for email in players.phtml |
| **CSRF** | Session-based `random_bytes(32)` in BookingController (was `sha1(time())`), HMAC in RegistrationForm (was deprecated `Bcrypt::setSalt()`) |
| **Auth Tokens** | HMAC-based password reset tokens (was bcrypt-substring), HMAC activation codes (was `sha1(created)`), `hash_equals()` for timing-safe comparison |
| **Bcrypt** | Cost factor 10 (was 6) in Backend UserController |
| **Hardening** | `unserialize(['allowed_classes' => false])` everywhere, removed `@` error suppression, `$_SERVER` guard for `HTTP_STRIPE_SIGNATURE` |
| **Libraries** | jQuery 1.12.4 → 3.7.1, jQuery UI 1.10.4 → 1.14.1, TinyMCE 4.0.26 → 6.8.5 |
| **Service Worker** | Cache version bumped to `ep3bs_v3.10:static` |

**TinyMCE 6 migration notes:**
- Skin: `lightgray` → `oxide`, Theme: `modern` → `silver`
- API: `file_browser_callback` → `file_picker_callback`, `styleselect` → `styles`
- Language codes: `de-DE` → `de`, `fr-FR` → `fr`, `hu-HU` → `hu`
- `tinyMCE.activeEditor.windowManager.open()` → `tinymce.activeEditor.windowManager.openUrl()`
- Setup files updated: `tinymce.setup.js`, `tinymce.setup.light.js`, `tinymce.setup.medium.js`

### Calendar Event Overlay Not Merging (Fixed Mar 2026, #94)

**Problem:** Multi-hour events (Veranstaltungen) displayed as individual hourly cells instead of one merged block. The HTML rendered correctly with `cc-event cc-group-{eid}` classes, but the JavaScript overlay that visually merges them was broken.

**Root Cause:** Multiple bugs in `updateCalendarEvents()` (`public/js/controller/calendar/index.js`):
1. `String.match()` returns an Array, but `$.inArray()` compared by reference (never found duplicates)
2. Off-by-one: `for (i <= length)` instead of `i < length`
3. Events spanning all courts (`sid=null`) have `cc-group-{eid}` on every court column, but the overlay merged ALL cells into one giant block across all courts
4. No `position: relative` container for `position: absolute` overlays

**Solution:** Complete rewrite of overlay logic:
- Extract `match()[0]` as string for proper `$.inArray` comparison
- Fix loop bound to `< length`
- Group cells by `td.index()` (court column) — create one overlay per (event, court) pair instead of one per event
- Append overlays to `.calendar-date-table` (with `position: relative`) for correct absolute positioning

**Phase 2 — Multi-column overlay merge (Mar 2026):**
Events spanning multiple court columns (e.g. 2 of 3 courts) created separate overlays per column. Fix: detect adjacent columns via `colKeys` and merge them into one wide overlay spanning all covered columns. Single-column events keep their original per-column overlay.

**Phase 3 — Datepicker z-index & label centering (Mar 2026):**
jQuery UI datepicker appeared behind event overlays (z-index conflict). Fix: `.ui-datepicker { z-index: 256 !important }` in `app.css`. Event overlay label text was left-aligned; fixed with `display: block; text-align: center` on the label `<span>`.

**Phase 4 — Single label, 1h fix, debounced resize (Mar 2026):**
- Multi-column events showed name per column. Fix: hide original cell labels, show label only in middle overlay.
- 1-hour multi-court events were invisible: safety check `firstColCells.length < 2` skipped wide overlay, combined with CSS label-hidden → no visible content. Fix: changed to `< 1`.
- Resize handler called `updateCalendarEvents()` on every pixel → flicker/stale overlays. Fix: single debounced handler (150ms), fires `updateCalendarCols()` + `updateCalendarEvents()` once after resize settles. Added `orientationchange` for mobile rotation.

**Phase 5 — Calendar mobile clean cells (Mar 2026):**
CSS-only mobile UX improvements (`@media (max-width: 767px)` in `app.css`):
- `a.cc-free .cc-label { visibility: hidden }` — "Frei" text hidden, light background sufficient
- `a.cc-own::after { content: "✓" }` — own bookings show checkmark icon instead of truncated text
- `a.cc-try::after { content: "!" }` — pending bookings show exclamation icon
- `a.cc-single .cc-label, a.cc-multiple .cc-label { visibility: hidden }` — occupied/abo color-only
- Color legend (`#calendar-legend`) in `index.phtml` below datepicker, `d-md-none` (mobile-only)

**Files changed:**
- `public/js/controller/calendar/index.js` + `index.min.js`
- `public/css/app.css` + `app.min.css`
- `module/Frontend/view/frontend/index/index.phtml` (legend)

### Payment Token Handling & Cleanup Interval (Fixed Mar 2026, #85)

**Problem:** Multiple issues with the payment flow: (1) Payum tokens expired or were invalid after the unpaid booking cleanup ran, causing 500 errors; (2) flash messages were lost because the session was not started for guest users; (3) payment method was not tracked in booking metadata.

**Solution:**
- Graceful error handling for invalid/expired Payum tokens — redirect to homepage with error message
- Session-independent success/error messages via query parameters instead of flash messages
- Payment method tracking: `paymentMethod` meta stored on booking, shown as tag in backend booking notes
- `payment_method` column added to backend booking list (responsive-pass-2)
- Migration 003: reduced cleanup interval to 3 min for testing; Migration 004: reset to production values (3 hours / every 15 min)

**Files changed:** `Square\BookingController.php`, `Backend\BookingFormat.php`, `BackendBookingsFormat.php`, migrations 003+004

### Cancellation Email Fixes (Fixed Mar 2026, #89)

**Problem:** (1) Cancellation emails were sent twice — once by the controller and once by `BookingService::cancelSingle()` event listener. (2) Guest users received "Herr/Frau" salutation instead of no salutation.

**Solution:**
- Removed duplicate email sending from controller; `NotificationListener` handles all cancellation emails
- Guest salutation check: skip "Herr/Frau" prefix when user has no `gender` meta

**Files changed:** `Backend\BookingController.php`, `NotificationListener.php`

### Squarebox Booking Form Init (Fixed Mar 2026, #91)

**Problem:** Booking form fields in the squarebox popup were not properly initialized after AJAX load — datepicker not attached, time dropdowns not populated, player count not synced.

**Solution:** Refactored to single `initBookingForm()` function called after every AJAX squarebox load. All form initialization (datepicker, time selects, player fields, autocomplete) consolidated in one place.

**Files changed:** `public/js/controller/calendar/index.js` + `index.min.js`

### jQuery UI Datepicker Arrows Invisible (Fixed Mar 2026, #92)

**Problem:** Datepicker prev/next month arrows were invisible — jQuery UI's default `.ui-icon` sprites not loaded, and the arrows relied on background-image icons.

**Solution:** CSS override in `app.css` — replaced sprite-based arrows with Unicode characters (`&#x25C0;` / `&#x25B6;`) via `::after` pseudo-elements, styled to match the theme.

**Files changed:** `public/css/app.css` + `app.min.css`

### Booking Limit Counts Slots, Not Reservations (Fixed Mar 2026, #93)

**Problem:** The "max active bookings" limit counted each reservation as 1, regardless of how many time slots it spanned. A 2-hour booking counted as 1, but should count as 2 slots.

**Solution:** Changed `BookingController` to sum reservation durations (slot count) instead of counting reservations. Also added per-user booking limit override via `bs_users_meta` key `maxActiveBookings`.

**Files changed:** `Square\BookingController.php`

### PHP 8.4 Migration (Mar 2026)

Upgraded from PHP 8.1 to 8.4, Stripe SDK 6.9.0 to 7.128.0. Key changes:

| Area | Changes |
|------|---------|
| `Dockerfile` | `php:8.1-apache` → `php:8.4-apache`, Xdebug 3.3.2 → 3.4.2 |
| `composer.json` | `php: >=8.4`, `stripe/stripe-php: ^7.0`, audit config |
| `src/Zend/**/*.php` | 317 implicit nullable fixes (`Type $param = null` → `?Type $param = null`) |
| `src/Zend/Stdlib/SplPriorityQueue.php` | `#[\ReturnTypeWillChange]` on `insert()` |
| `src/Zend/Mvc/Router/Http/Part.php` | Added `$priority` property declaration |
| `vendor/stripe/stripe-php/` | Replaced with v7.128.0, `utf8_encode()` → `mb_convert_encoding()` |
| `vendor/payum/stripe/Action/Api/*.php` | `Stripe\Error\Base` → `Stripe\Exception\ApiErrorException`, `__toArray(true)` → `toArray()` |
| `vendor/guzzlehttp/guzzle/Handler/CurlMultiHandler.php` | `#[\AllowDynamicProperties]` (lazy `$_mh` via `__get`) |
| `vendor/` (guzzle, payum, twig, eluceo, league, php-http) | Implicit nullable fixes |
| `module/` (Base, Calendar, User) | Implicit nullable + dynamic property fixes |

**Important — `composer update` is broken**: Due to `payum/payum-module` requiring the ZF2 metapackage while we use individual forked packages, `composer update` cannot resolve dependencies. Vendor changes must be managed manually. Always use `--ignore-platform-reqs` if running composer commands.

Previous PHP 8.1 fixes (still in place):
- `AbstractEntity.php:165`: `strlen(null)` guard in `setMeta()`
- `UriFactory.php:96`: `strtolower((string) ...)`
- `PropertyBag.php:69`: `#[\ReturnTypeWillChange]` on `getIterator()`

### Mobile Squarebox Layout Fixes (Fixed Mar 2026, #97)

**Problem:** Three mobile booking confirmation issues visible on screenshot:
1. X-Button appeared at bottom of modal (not top-right)
2. Pricing table (4 columns: court, duration, players, price) overflowed horizontally without scroll
3. Rules text was capped at `max-height: 120px` — text cut off mid-sentence

**Root Causes & Fixes:**
1. **Close button:** `squarebox.append(...)` placed it as the **last** DOM element → `position: sticky; float: right; top: 0` rendered it at the bottom. Fix: changed to `squarebox.prepend(...)` so it's the first element and floats top-right.
2. **Pricing table:** 4-column table (court, duration, players, price) overflowed in 90vw. Fix: columns 2+3 get `class="ps-detail-col"` and are hidden via CSS on mobile (`.squarebox-mobile .ps-detail-col { display: none }`). Same info shown as compact gray `.ps-meta` line inside first cell. Desktop: 4-column layout unchanged, `.ps-meta` hidden. No `table-responsive` scroll wrapper needed.
3. **Rules text:** `.rules-text-scroll` had `max-height: 120px` globally (180px on desktop). On mobile the squarebox itself has `overflow-y: auto` — no inner scroll cap needed. Fix: `.squarebox-mobile .rules-text-scroll { max-height: none; overflow-y: visible; }`.

**Files changed:** `public/js/controller/calendar/index.js` + `index.min.js`, `module/Square/src/Square/View/Helper/PricingSummary.php`, `public/css/app.css` + `app.min.css`

### Uniform Email Salutation (Fixed Mar 2026, #81)

**Problem:** Backend booking emails used gender-based "Sehr geehrter Herr/Sehr geehrte Frau". Guest/admin-created bookings showed only "Hallo Nachname" (no firstname). Inconsistent across all email-sending locations.

**Solution:** All emails now use `Hallo Vorname Nachname` (fallback: alias if no name set). Applied in:
- `User\MailService::send()` — builds `$salutationName` from `getMeta('firstname')` + `getMeta('lastname')`, uses `t('Hello')` translation key
- `Backend\BookingController` — 4 locations (cancel, reactivate, edit, bulk): replaced gender if/elseif with firstname+lastname logic
- `Square\BookingController` — 2 locations (user cancel, payment failed): removed email-address fallback, unified to alias

**Files changed:** `module/User/src/User/Service/MailService.php`, `module/Backend/src/Backend/Controller/BookingController.php`, `module/Square/src/Square/Controller/BookingController.php`

### My Bookings Smart-Sort & Badge Popover (Fixed Mar 2026, #65, #71)

**Problem:** "Meine Buchungen" page showed bookings in arbitrary order. Pending/unpaid bookings were not prioritized. No mobile-friendly way to preview booking summary from the notification badge.

**Solution — Smart-sort (`bookings.phtml`):**

Bookings are grouped in a PHP pre-pass into three arrays, then merged:
1. `$groupPending` — future bookings with `status_billing=pending` + `price > 0` (sorted ASC)
2. `$groupUpcoming` — future bookings that are not pending (sorted ASC)
3. `$groupPast` — past bookings (sorted DESC = newest first)

**Smart default filter:** Auto-selected on page load via `$defaultFilter` PHP variable (sets `checked` on radio button):
- `pending` — if `$groupPending` not empty **OR** any entry in `$groupPast` has `isPending=true`
- `upcoming` — if `$groupUpcoming` not empty
- `all` — otherwise

JS triggers the pre-selected filter on load: `$('#bookings-filter input:checked').trigger('change')`.

**Controller sort:** `ReservationManager::getByBookings($bookings, 'date ASC, time_start ASC')` — ASC so future bookings sort correctly within groups.

**Solution — Badge Popover (Option C):**
- Badge (`data-popover-content` attribute) on "My bookings" button in userpanel + navbar
- **Desktop** (`window.matchMedia('(hover: hover)')`): Bootstrap Popover on badge click, `trigger: 'focus'` closes on outside click
- **Mobile/touch**: badge is pure indicator — `pointer-events: none` via `@media (hover: none)`, no popover initialized → no tap-navigation conflict

**Files changed:** `module/User/view/user/account/bookings.phtml`, `module/User/src/User/Controller/AccountController.php`, `public/js/controller/user/bookings.js` + `.min.js`, `public/js/default.min.js`, `public/css/app.css` + `.min.css`, `module/Base/view/layout/layout.phtml`, `module/Frontend/view/frontend/index/userpanel.online.phtml`

### Debugging Tips

- Use `error_log()` for debug output — it goes to PHP error.log (`data/log/errors.txt` in Docker)
- Do NOT use `syslog()` — it does not appear in PHP error.log on the Docker setup
- Payum payment data can be dumped with: `error_log('payment: ' . json_encode($payment instanceof \ArrayAccess ? iterator_to_array($payment) : $payment))`

## Writable Directories

`data/cache/`, `data/log/`, `data/session/`, `public/docs-client/upload/`, `public/imgs-client/upload/`

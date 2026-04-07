# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Fork of ep3-bs (tkrebs/ep3-bs v1.7.0) — online booking system for courts built on **Zend Framework 2**. Extended with **Payum** payments (PayPal, Stripe SCA, Klarna), PWA, member/non-member pricing, budget/gift card system, Loxone door code integration.

## Build & Run

```bash
cp .env.example .env && cp config/autoload/local.php.dist config/autoload/local.php && cp config/autoload/project.php.dist config/autoload/project.php
docker compose build && docker compose up -d
docker compose exec court composer update  # update PHP deps inside container
# App: https://court.localhost | Traefik: :8080 | MailHog: :8025
```

Config files (copy from `.dist`): `local.php` (DB, mail, payment keys), `project.php` (URLs, payment toggles, `paypalEmail`, feature flags), `init.php` (dev mode, timezone).

DB schema: `data/db/ep3-bs.sql`. Migrations in `data/db/migrations/` run auto on startup via `MigrationManager` (`bs_options` key `schema.version`).

**After prod DB import**: check `bs_squares_pricing.date_end` covers today, else `$payable=false` and no payment buttons appear.

## Architecture

**PHP 8.4 / ZF2 MVC**: `Entity → Manager (TableGateway) → Service → Controller → View (.phtml)`

### Key Modules

| Module | Role |
|--------|------|
| **Base** | AbstractEntity/Manager, view helpers, OptionManager, MailService |
| **Backend** | Admin dashboard (users, bookings, config) |
| **Booking** | BookingService/Manager, billing, NotificationListener (emails) |
| **Square** | Court definitions, public booking UI, **all payment controller actions** |
| **Payment** | Payum config — **NOT loaded as ZF module**, routes never dispatched. Payment code used only as service layer. |
| **Service** | System status (enabled / administration / maintenance) |
| **User** | Auth, account, user metadata |

**Payment routes** must go in **Square module** (`Square\Controller\BookingController`), not Payment module. ZF packages individually forked into `src/Zend/`.

**Meta pattern**: Core entities have parallel `*_meta` table (key-value). E.g. `bs_bookings` + `bs_bookings_meta`.

**DI**: Zend ServiceManager + Factory classes implementing `FactoryInterface`, registered in each module's `module.config.php`.

### Key Routes (`module/*/config/module.config.php`)

- `/square/booking/*` — Booking flow (customization → confirmation → payment)
- `/square/booking/payment/pay/:bid` — Pay open bill (payLater)
- `/square/booking/payment/done|confirm` — Payum callbacks
- `/backend/booking` — Booking list; `/user/bookings/bills/:bid` — Bill view
- `/backend/audit` — Audit log (all system actions, filterable)

### Frontend

- **Bootstrap 5.3.8** + **jQuery 3.7.1** + **jQuery UI 1.14.1** + **TinyMCE 6.8.5** (all local)
- CSS: `public/css/app.css` → `app.min.css`. JS: `public/js/controller/*/` + `sw.js` (PWA)
- **Squarebox**: jQuery AJAX modal. Desktop: `squarebox-desktop`, absolute, 720px, 2-col grid. Mobile: `squarebox-mobile`, fixed, 90vw, stacked.
- Form helpers: `FormRowDefault` → BS5 `mb-3/form-control` divs (use in grid, NOT in `<table>`). `FormRowCompact` → `form-control-sm`.
- Layout: `module/Base/view/layout/layout.phtml`, BS5 navbar + `container-xl`, `.content-panel`.

### Payment Flow

`BookingController::createPaymentAndRedirect()` — creates Payum token + gateway redirect (used by `confirmationAction()` + `payAction()`).

- **`$payment['status']`** is Stripe-specific (`succeeded`/`processing`/`requires_action`). PayPal has no this key — always `isset()` guard.
- Payment buttons only shown when `$payable == true` (`$total > 0` from `SquarePricingManager::getFinalPricingInRange()`).
- `gp` guest player flag: passed as `$guestPlayer` from controller — never use `$_GET['gp']` in views/services.
- Unpaid bookings auto-removed by MySQL event (every 15 min, > 3h old, `directpay=true`, `status_billing=pending`).

**Pay Later** (`/user/bookings/bills/:bid`): `payAction()` sets `payLater=true` meta → gateway → `doneAction()`. With `payLater`: failure = flash only (no `cancelSingle()`), success = `status_billing=paid` + meta cleared.

**PayPal F&F instructions**: Config `paypalEmail` in `project.php`. Used via `sprintf()` in `NotificationListener`, `Square\BookingController`, `Backend\BookingController`.

### Budget System

`bs_users_meta` key `budget` (EUR). Flow in `BookingController.php`:
1. Check: `budget > 0 && total > 0 && (no guest || member)`
2. Full coverage: `$budgetpayment=true`, `$payable=false`; Partial: remaining via gateway
3. Meta: `hasBudget`, `budget`, `newbudget`, `budgetpayment`

**Refund**: `BookingService::refundBudget($booking)` — checks `status_billing==paid` and `refunded!=true`. Call from Square/Backend BookingController + PaymentController webhook.

**Atomic operations**: Budget deduction/refund uses `UserManager::deductBudgetAtomic()` / `addBudgetAtomic()` — single SQL `UPDATE` to prevent double-spend race conditions. Never use read-modify-write pattern for budget.

### Member/Guest Pricing

`bs_squares_pricing.member`: 0=non-member, 1=member.
- Member: member price (0=free). Non-member: full price.
- Member + guest (gp=1): **50% of non-member price**. Non-member + guest: full price.

### Email Notifications

`BookingService::createSingle()` → `create.single` event → `NotificationListener::onCreateSingle()`.

**Critical**: Email sent DURING `createSingle()`. All payment/budget meta must be in `$meta` array **before** the call.

Salutation: always `Hallo Vorname Nachname` (fallback: alias). No gender-based salutation.

**No-email user statuses**: `bs_options` key `service.no-email-statuses` (comma-separated, e.g. `team,guestgroup`). Configurable via Backend → Konfiguration → Verhalten. `User\MailService::send()` skips sending for matching statuses. All direct `need('email')` calls guarded with `get('email')` fallback. Backend BookingController email methods return early if no email.

### Subscription Booking Management

**Edit modes**: Clicking a subscription booking opens `editModeAction` — choose between `booking` (whole subscription) or `reservation` (individual occurrence).

- `editMode=booking`: Time/date fields disabled; shows subscription reservations table with cancel/reactivate per reservation.
- `editMode=reservation`: User field disabled; Platz/Rechnungsstatus/Anzahl Spieler/Notizen editable. Time/date/court changes apply to single reservation only.
- `editModeAction` dialog: Shows actual reservation list with status (cancelled = strikethrough + marker) instead of original date range. All reservations loaded in controller via `allReservations` param.

**Reactivation**: `calendar.reactivate-bookings` privilege required.
- Whole cancelled subscription: restores `status=subscription` (checks `getMeta('repeat')`), reactivates all cancelled reservations.
- Individual cancelled reservation: `deleteAction` with `editMode=reservation&reactivate=true` → confirmation dialog → sets reservation `status=confirmed`.
- Conflict check (`getInRange()`) before any reactivation.
- Both booking list (bulk + per-row icon) and edit view (subscription table) support reactivation.

**Booking list**: `BookingFormat` checkbox `data-status` reflects reservation status (not just booking status) — enables bulk reactivation of cancelled reservations within active subscriptions.

**Edit email**: `sendAdminBookingEditEmail()` accepts `$rid` parameter to load the correct reservation. Without `rid`, it falls back to the first reservation of the booking. Always pass `$d['bf-rid']` when calling from editAction. Time changes are combined into a single "Uhrzeit" line showing old/new range. Context header shows "Geaenderte Reservierung am DD.MM.YYYY:".

**Cancel/delete email**: `sendReservationCancellationEmail()` shows compact one-line summary + affected reservation marked with "← geloescht"/"← storniert" in the overview list. Previously cancelled reservations also marked.

**Email bill formatting**: `formatBillsForEmail($bills, $billingStatus, $bookingStatus)` — for > 5 items shows first 2, "... (N weitere Termine)", last item. Shows "Storniert" when `$bookingStatus == 'cancelled'`. All 4 email methods use this helper. Never list 50-120 bill positions individually.

**Subscription edit table**: For > 15 reservations, wrapped in scrollable container (max-height 300px) with sticky thead. Summary count in header row, not below table.

### Backend Booking List

Panel `giant-sized` (1280px), 13 columns, `table-layout: fixed`, `responsive-pass-*` hiding. Actions icon-only. Server-side pagination (100 per page). Status badges: `[E]`=single, `[A]`=subscription, `[S]`=cancelled, `[A][S]`=cancelled reservation within active subscription. Reactivate requires `calendar.reactivate-bookings` privilege (admins auto; assist users need `allow.calendar.reactivate-bookings` meta).

### Administration Mode

`bs_options` key `service.maintenance`: `false`=enabled, `administration`=admin+assist only, `true`=maintenance (503). Login route always accessible; no link on status page.

### Audit-Log System

`bs_audit_log` table — flat append-only, no meta table. `AuditService::log($category, $action, $message, $options)` writes to DB + `data/log/audit.log` (JSON-per-line). Fire-and-forget (try-catch, never breaks main flow).

- **AuditListener**: Hooks into `BookingService` events (`create.single`, `cancel.single`). Attached in `BookingServiceFactory`.
- **Direct calls**: Login/logout (`SessionController`), payment (`Square\BookingController::doneAction`), admin actions (`Backend\BookingController::audit()`), user/event edits.
- **Log rotation**: 5 MB max, 3 rotated files (`.1`, `.2`, `.3`).
- **DB cleanup**: MySQL event `cleanup_audit_log` (daily 03:00), retention configurable via `bs_options` key `service.audit.retention-days` (default 90).
- **GeoIP**: Login events resolve IP to country via `ip-api.com` (2s timeout, private IPs skipped).
- **Backend UI**: `/backend/audit` — card-style entries, filterable by date/category/search.
- **Change History**: Booking edit dialog shows collapsible audit trail per booking (last 20 entries).

### Per-Reservation Overrides

Subscription reservations can have individual overrides stored in `bs_reservations_meta`:
- `sid_override` — different square for this reservation
- `status_billing_override` — different billing status
- `quantity_override` — different player count
- `notes_override` — different notes

Overrides only stored when value differs from booking. Calendar `ReservationsForCell` checks `sid_override`. `BookingFormat` shows `≠` badge. Conflict detection considers `sid_override`.

## Coding Standards

- **PSR-4**: `\{Module}\{Class}` → `module/{Module}/src/{Module}/{Class}.php`
- Views: `module/{Module}/view/{module-lowercase}/{controller}/{action}.phtml`
- Translations: `data/res/i18n/de-DE/{module}.php` (key=English, value=German)
- Backend forms: BS5 grid (`row`/`col-md-6`), never `<table>`. Button variants: `.default-button-danger`, `.default-button-outline`.
- Time fields: Select dropdowns (07:00–22:00) for hours; Text inputs for minute-based fields.

## Key Files

| Area | File |
|------|------|
| Booking controller (payment) | `module/Square/src/Square/Controller/BookingController.php` |
| Backend booking controller | `module/Backend/src/Backend/Controller/BookingController.php` |
| Email listener | `module/Booking/src/Booking/Service/Listener/NotificationListener.php` |
| Pricing manager | `module/Square/src/Square/Manager/SquarePricingManager.php` |
| Layout | `module/Base/view/layout/layout.phtml` |
| Custom CSS | `public/css/app.css` + `app.min.css` |
| Squarebox JS | `public/js/controller/calendar/index.js` + `index.min.js` |
| Table sort JS | `public/js/controller/backend/table-sort.js` + `table-sort.min.js` |
| Service worker | `public/js/sw.js` |
| Translations | `data/res/i18n/de-DE/booking.php`, `square.php`, `backend.php` |
| Booking list helper | `module/Backend/src/Backend/View/Helper/Booking/BookingFormat.php` |
| Booking update plugin | `module/Backend/src/Backend/Controller/Plugin/Booking/Update.php` |
| Audit service | `module/Base/src/Base/Service/AuditService.php` |
| Audit listener | `module/Base/src/Base/Service/Listener/AuditListener.php` |
| Audit controller | `module/Backend/src/Backend/Controller/AuditController.php` |
| Migrations | `data/db/migrations.php` (registry), `data/db/migrations/*.sql` |

## Docker

Single `Dockerfile` (PHP 8.4-apache). OPcache (128MB) + APCu (32MB) + mod_deflate + mod_expires enabled. MariaDB tuned (`innodb_buffer_pool_size=256M`). `INSTALL_XDEBUG=true` → Xdebug 3 (port 9003). `vendor/` committed to git — not built into image.

```bash
docker compose up -d                                   # local dev (override auto-loaded)
docker compose -f docker-compose.yml up -d             # production
docker compose -f docker-compose.dev-server.yml up -d  # DEV alongside prod on server
```

Services: `traefik` (80/443/8080), `court` (PHP via Traefik), `mariadb` (3306), `mailhog` (8025 UI / 1025 SMTP).

macOS: set `DOCKER_SOCKET=~/.docker/run/docker.sock` in `.env` if Traefik can't reach Docker socket.

## Gotchas

- **JS/CSS sync**: `.js` + `.min.js` and `app.css` + `app.min.css` always kept in sync — no build tool.
- **SW cache bump required** after CSS/JS changes: `cacheName` in `public/js/sw.js` (`ep3bs_vX.XX:static`). Current: **v3.20**.
- **Event overlay**: use `.calendar-event-overlay` class for hide/remove — `[id$='-overlay-']` never matches (IDs end with `-overlay-0`).
- **`composer update` broken**: `payum/payum-module` conflicts with our forked ZF packages. Vendor changes manual only. Use `--ignore-platform-reqs` if needed.
- **Translation file scope**: key must be in correct module file (e.g. `booking.php` for NotificationListener). `$this->t(ucfirst($slug))` works for status slugs.
- **jQuery UI z-index**: set `appendTo` on datepicker/autocomplete to avoid rendering behind squarebox.
- **Email meta timing**: all meta must be in `$meta` BEFORE `createSingle()` — meta set after won't appear in email.
- **Stripe payment key**: `$payment['status']` Stripe-only. Always `isset()` guard for PayPal.
- **Budget refund**: always via `BookingService::refundBudget()` — never inline.
- **Booking limit**: counts time slots, not reservations. Per-user override: `bs_users_meta` key `maxActiveBookings`.
- **Hammer.js swipe**: touch-only + suppressed when `.squarebox` open — in `layout.phtml`.

## Debugging

- `error_log()` → `data/log/errors.txt`. Do NOT use `syslog()`.
- Payum: `error_log(json_encode($payment instanceof \ArrayAccess ? iterator_to_array($payment) : $payment))`

## Writable Dirs

`data/cache/`, `data/log/`, `data/session/`, `public/docs-client/upload/`, `public/imgs-client/upload/`

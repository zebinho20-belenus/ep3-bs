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

**PayPal transaction traceability**: Gateway is legacy PayPal Express Checkout NVP (`payum/paypal-express-checkout-nvp`), not the modern REST/Checkout SDK. `doneAction()` extracts `PAYERID`/`CORRELATIONID`/`PAYMENTINFO_0_TRANSACTIONID` from the NVP response and stores them as booking meta (`paypalPayerId`, `paypalCorrelationId`, `paypalTransactionId`) plus in the `payment_success`/`payment_failed` audit-log detail. Raw full NVP response is also persisted by Payum itself in `data/payum/` (`FilesystemStorage`, opaque hashed filenames, no cleanup — grep contents for `PAYMENTREQUEST_0_BID` to find a specific booking).

**PayPal pending/error diagnosis**: `PAYMENTINFO_0_PENDINGREASON` (why PAYMENTSTATUS=Pending — `paymentreview`=PayPal fraud hold, `echeck`=bank clearance, `verify`=unverified account, `multi-currency`, `unilateral`, `regulatoryreview`, `intl`) is captured as booking meta `paypalPendingReason` + audit detail on success. `L_ERRORCODE0`/`L_SHORTMESSAGE0`/`L_LONGMESSAGE0` (PayPal's own error detail, only present when ACK=Failure/FailureWithWarning) go into the `payment_failed` audit detail only, not booking meta.

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

### Auto-Registration with Member Recognition

`User\Controller\AccountController::registrationAction()` — "Ich bin Vereinsmitglied" checkbox (`rf-member`) on the registration form.

- Email is checked against `bs_member_emails` (`Backend\Manager\MemberEmailManager::getByEmail()`) **before** deciding member status — never set `member=1`/`status=enabled` first and check after.
- Match found → `meta['member']=1`, `status=enabled`. No match → falls through to the normal flow per `service.user.activation` (immediate/manual/manual-email/email); user is **not** marked as member.
- No match also triggers: flash message on the registration-confirmation page (`flashMessenger()->addInfoMessage()`, survives the redirect via `Base\View\Helper\Messages`), plus an extra paragraph in the activation email (only sent when `service.user.activation == 'email'`).
- Admin notification email always includes member status + verification result (`MITGLIED: Ja` + `E-Mail-Prüfung: ...`), regardless of match — this block is intentionally unaffected by the above.
- Backend UI `/backend/config/member-emails` manages the list (CSV import, single add/delete). Migration `data/db/migrations/002-member-emails.sql` creates `bs_member_emails`.

### Calendar Name Display (non-staff)

`OccupiedForVisitors.php` (non-staff path) reveals the booker's alias **only** for bookings with effective billing status `training` (`status_billing_override ?: booking.status_billing === 'training'`):
- **Logged-in user**: training names shown automatically on all squares (no per-square config).
- **Visitor (not logged in)**: training names shown only if the square meta `public_names = true`.
- All other billing statuses stay anonymous (`Belegt`/`Abo`) — no name leakage.
- `CalendarController` loads booking users whenever `$user` is set (so `needExtra('user')` is available).
- Staff (`calendar.see-data`) use `OccupiedForPrivileged` and always see all names — unchanged.
- The square `private_names` meta + `hasOneWithPrivateNames()` are now effectively unused (only `public_names` matters). The Backend "Sichtbarkeit von Namen" field governs visitor visibility only.

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

Panel `giant-sized` (1280px), 13 columns, `table-layout: fixed`, `responsive-pass-*` hiding. Actions icon-only. Server-side pagination (default 25, selectable 25/50/100 via dropdown). Status badges: `[E]`=single, `[A]`=subscription, `[S]`=cancelled, `[A][S]`=cancelled reservation within active subscription. Reactivate requires `calendar.reactivate-bookings` privilege (admins auto; assist users need `allow.calendar.reactivate-bookings` meta).

**Server-side search**: Column filters support server-side search on Enter:
- **Nr. filter**: type booking ID + Enter → `(bid = X)` search (date-independent, finds any booking)
- **Name filter**: type name + Enter → `(name = X)` search (LIKE on user alias, across all pages)
- **Search field**: supports `(bid = X)`, `(name = X)`, `(uid = X)`, `(sid = X)`, `(status_billing = X)` etc.

**Per-reservation overrides**: Subscription reservations can have `sid_override`, `status_billing_override`, `quantity_override`, `notes_override` in reservation meta. Calendar, booking list, and edit dialog all respect overrides. `DetermineParams` uses `effectiveSid` for square matching.

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

### Diagnostic Tool (CLI)

Read-only forensic + integrity scanner. Run inside the `court` container:

```bash
docker compose exec court php scripts/diagnose.php list-checks
docker compose exec court php scripts/diagnose.php inspect-booking <bid>
docker compose exec court php scripts/diagnose.php inspect-reservation <rid>   # resolves rid → booking (findings report rids)
docker compose exec court php scripts/diagnose.php inspect-slot 2026-07-16 1 18:00
docker compose exec court php scripts/diagnose.php scan [<von> <bis>] [--checks=occupancy,payment] [--severity=warning] [--json] [--alert]
```

- **Entrypoint**: `scripts/diagnose.php` (NOT under `public/` DocumentRoot → not web-reachable). Bootstraps the ServiceManager **without** `Application::bootstrap()`, so auto-migrations never run — the tool is read-only except `scan --alert` (writes audit-log entries + admin e-mail).
- **Service**: `Booking\Service\BookingDiagnosticService` (`inspectBooking`, `inspectSlot`, `scan`, `recordAlerts`). Factory builds the `CheckRegistry`.
- **Checks**: `module/Booking/src/Booking/Service/Diagnostic/Check/*.php` — each implements `DiagnosticCheckInterface` (or extends `AbstractCheck`) and returns `Finding[]`. **To add a new anomaly**: create one check class, register it in `BookingDiagnosticServiceFactory`. Key format `category.name`; `--checks` matches a full key or a category.
- Range-dependent checks (`occupancy`, `time.*`, `user.missing-email`) use `--from`/`--to` (default `today .. +42d`); the rest are whole-DB. A check that throws is logged and skipped (scan never aborts).
- Exit codes: `0` clean, `1` findings present, `2` usage/runtime error — usable in cron/monitoring.
- **Scheduled scan** (host cron on tcnkail-server, after audit cleanup): drop-in `/etc/cron.d/ep3bs-diagnose`
  `15 3 * * * root docker exec ep3-bs-8-prod-court-1 php scripts/diagnose.php scan --all --severity=warning --alert >> /var/log/ep3bs-diagnose.log 2>&1`
  `--severity=warning` filters both log and alert mail to warning+critical (INFO checks like `payment.paid-without-evidence` / `status.cancelled-with-active` are expected noise for this club).

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
| Diagnostic CLI | `scripts/diagnose.php` |
| Diagnostic service | `module/Booking/src/Booking/Service/BookingDiagnosticService.php` (+ `Diagnostic/Check/*.php`) |
| Migrations | `data/db/migrations.php` (registry), `data/db/migrations/*.sql` |
| App version | `VERSION` (file), `config/init.php` → `EP3_BS_VERSION` constant, footer in `layout.phtml` |

## Docker

Single `Dockerfile` (PHP 8.4-apache). OPcache (128MB) + APCu (32MB) + mod_deflate + mod_expires enabled. MariaDB tuned (`innodb_buffer_pool_size=256M`). `INSTALL_XDEBUG=true` → Xdebug 3 (port 9003). `vendor/` committed to git — not built into image.

```bash
docker compose up -d                                   # local dev (override auto-loaded)
docker compose -f docker-compose.yml up -d             # production
docker compose -f docker-compose.dev-server.yml up -d  # DEV alongside prod on server
```

Services: `traefik` (80/443/8080), `court` (PHP via Traefik), `mariadb` (3306), `mailhog` (8025 UI / 1025 SMTP).

macOS: set `DOCKER_SOCKET=~/.docker/run/docker.sock` in `.env` if Traefik can't reach Docker socket.

**Production server (tcnkail-server):** Traefik runs **standalone** (not in compose project), external Docker network `traefik_web` must exist. `docker-compose.yml` attaches `court` to both `courtnet` + `traefik_web`. Dev-server at `/opt/ep3-bs-8-dev/` uses `docker-compose.dev-server.yml`. After `git pull` on server: `git pull` from `master` branch.

## Gotchas

- **JS/CSS sync**: `.js` + `.min.js` and `app.css` + `app.min.css` always kept in sync — no build tool.
- **SW cache bump required** after CSS/JS changes: `cacheName` in `public/js/sw.js` (`ep3bs_vX.XX:static`). Current: **v3.32**.
- **Event overlay**: use `.calendar-event-overlay` class for hide/remove — `[id$='-overlay-']` never matches (IDs end with `-overlay-0`).
- **Traefik router name conflict**: If production (`court`) and dev (`court-dev`) containers both define a Traefik router named `court`, Traefik v3 drops both → production returns 404. Dev container in `docker-compose.dev-server.yml` MUST use router names `court-dev` / `court-dev-redirect`.
- **`init.php` no text before `<?php`**: Any characters before the opening PHP tag are output directly to the browser — e.g. stray `co` becomes visible garbage at the top of every page.
- **`composer update` broken**: `payum/payum-module` conflicts with our forked ZF packages. Vendor changes manual only. Use `--ignore-platform-reqs` if needed.
- **Translation file scope**: key must be in correct module file (e.g. `booking.php` for NotificationListener). `$this->t(ucfirst($slug))` works for status slugs.
- **jQuery UI z-index**: set `appendTo` on datepicker/autocomplete to avoid rendering behind squarebox.
- **Email meta timing**: all meta must be in `$meta` BEFORE `createSingle()` — meta set after won't appear in email.
- **Stripe payment key**: `$payment['status']` Stripe-only. Always `isset()` guard for PayPal.
- **Budget refund**: always via `BookingService::refundBudget()` — never inline.
- **Booking limit**: counts time slots, not reservations. Per-user override: `bs_users_meta` key `maxActiveBookings`.
- **Availability checks need effective sid**: any occupied/free decision must match reservations via `$reservation->getMeta('sid_override') ?: $booking->need('sid')` — never the base booking `sid` alone (per-reservation overrides move reservations between squares). Correct pattern in `ReservationsForCell`, `SquareValidator::isBookable()`, `TimeBlockChoice`, Backend `DetermineParams`. Missing this caused double bookings over moved subscription reservations (v2.2.13).
- **Hammer.js swipe**: touch-only + suppressed when `.squarebox` open — in `layout.phtml`.
- **User settings accordions**: `settings.js` requires `.edit-label` on trigger headings and `.sandbox` on container divs to show/hide forms. Both missing → all forms permanently hidden. See `module/User/view/user/account/settings.phtml`.
- **Calendar portrait indicators** (staff only): `OccupiedForPrivileged.php` adds `cc-member`/`cc-guest` to cell anchor classes. CSS `::before` selectors scoped to `.calendar-staff a.cc-*` — the `.calendar-staff` class is injected in `index.phtml` only when `user->can('calendar.see-data')`. G = non-member, M = member, MG = member+gp, A = subscription, T = event. Never add `cc-member`/`cc-guest` in `Occupied.php` (non-staff path).

## Debugging

- `error_log()` → `data/log/errors.txt`. Do NOT use `syslog()`.
- Payum: `error_log(json_encode($payment instanceof \ArrayAccess ? iterator_to_array($payment) : $payment))`

## Writable Dirs

`data/cache/`, `data/log/`, `data/session/`, `public/docs-client/upload/`, `public/imgs-client/upload/`

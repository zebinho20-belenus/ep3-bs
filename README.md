# EP3-BS Court Booking System

<div align="center">

![PHP](https://img.shields.io/badge/PHP-8.4-purple)
![Framework](https://img.shields.io/badge/Framework-Zend_Framework_2-green)
![Bootstrap](https://img.shields.io/badge/Bootstrap-5.3.8-blue)
![jQuery](https://img.shields.io/badge/jQuery-3.7.1-blue)
![TinyMCE](https://img.shields.io/badge/TinyMCE-6.8.5-blue)
![License](https://img.shields.io/badge/License-Proprietary-red)

**Professional Online Booking System for Tennis Courts (and other sports facilities)**

Fork of [tkrebs/ep3-bs](https://github.com/tkrebs/ep3-bs) v1.7.0
Extended with **Direct Payment**, **PWA Support**, **Member/Guest Pricing**, and **Loxone Door Control**

[Technical Documentation](CLAUDE.md) | [Changelog](CHANGELOG.md)

</div>

---

## Table of Contents

- [Overview](#overview)
- [Quick Start](#quick-start)
- [Architecture](#architecture)
- [Features](#features)
- [Payment Integration](#payment-integration)
- [Budget System](#budget-system)
- [Pricing System](#pricing-system)
- [Backend Admin](#backend-admin)
- [Docker Setup](#docker-setup)
- [Configuration](#configuration)
- [Security](#security)
- [Loxone Door Control](#loxone-door-control)
- [Contributing](#contributing)

---

## Overview

EP3-BS is a comprehensive online booking system designed for sports facilities (tennis courts, squash courts, etc.). This fork extends the original [tkrebs/ep3-bs](https://github.com/tkrebs/ep3-bs) with professional payment processing, budget management, and smart door access control.

### Technology Stack

| Component | Version |
|-----------|---------|
| PHP | 8.4 (Apache) |
| Framework | Zend Framework 2 (custom Entity-Manager-Service pattern) |
| Frontend | Bootstrap 5.3.8, jQuery 3.7.1, jQuery UI 1.14.1 |
| Editor | TinyMCE 6.8.5 |
| Payment | Payum (PayPal, Stripe with SCA, Klarna) |
| Database | MariaDB 10.11 |
| Proxy | Traefik (HTTPS via Let's Encrypt) |
| PWA | Manual service worker |

### Key Extensions

- **Direct Payment** via PayPal, Stripe (Card, SEPA, iDEAL, Giropay, Apple Pay, Google Pay), Klarna
- **Budget/Gift Card System** (prepaid, refunds, admin-managed)
- **Member/Guest Pricing** with 50% guest discount for members
- **Loxone Door Control** (automatic 4-digit codes per booking)
- **PWA Support** (app-like experience on smartphones)
- **Bootstrap 5 UI** (responsive design, mobile-first)
- **Subscription Bookings** (weekly/biweekly recurring)
- **Pay Open Bills** -- users can pay pending invoices from their bill page
- **Backend Bills Editor** (inline editing for admins)
- **TinyMCE Rich Text Editor** for content management
- **Auto-Registration** with member email verification
- **Security Hardened** (OWASP Top 10 audit, security headers, prepared statements)

### Code Statistics

- 13 Modules
- 307 PHP files
- 15 Database tables (bs_* schema)
- 4 Languages (de-DE, en-US, fr-FR, hu-HU)

---

## Quick Start

### Prerequisites

- Docker & Docker Compose
- Git

### Installation

```bash
# 1. Clone repository
git clone git@github.com:zebinho20-belenus/ep3-bs.git
cd ep3-bs

# 2. Create configuration files
cp .env.example .env
cp config/autoload/local.php.dist config/autoload/local.php
cp config/autoload/project.php.dist config/autoload/project.php

# Edit configuration files with your credentials:
# - .env: Docker environment (ports, Xdebug)
# - local.php: Database, SMTP, Payment API keys
# - project.php: URLs, session config, feature toggles

# 3. Build and start Docker containers
docker compose build
docker compose up -d

# 4. Install/update PHP dependencies (inside container)
docker compose exec court composer update

# 5. Import database schema
docker compose exec -T mariadb mariadb -u root -p<password> <database> < data/db/ep3-bs.sql
```

**Access Points:**
- Application: https://court.localhost (self-signed cert)
- Traefik Dashboard: http://localhost:8080
- MailHog UI: http://localhost:8025

### Important: Pricing Setup

After importing a database, verify that `bs_squares_pricing.date_end` covers the current date. If pricing rules are expired, `$payable` stays `false` and payment buttons won't appear on the confirmation page.

---

## Architecture

### Framework Pattern

**Zend Framework 2 MVC** with custom layered pattern:

```
Entity (data container, extends AbstractEntity)
  -> Manager (CRUD + DB transactions via TableGateway)
    -> Service (business logic, event-driven)
      -> Controller (HTTP layer, request handling)
        -> View (.phtml templates via Zend\View)
```

### Modules

| Module | Responsibility |
|--------|---------------|
| **Base** | Core utilities, abstract classes, view helpers, MailService |
| **Backend** | Admin dashboard -- user management, booking management, system config |
| **Booking** | Booking lifecycle -- BookingService, BookingManager, email notifications |
| **Square** | Court definitions -- public booking UI, confirmation views, payment routes |
| **Calendar** | Calendar widget -- day/week views, event overlays, squarebox popup |
| **Event** | Events and court closures |
| **Frontend** | Public-facing landing page, calendar display |
| **User** | Authentication, registration, account management |
| **Payment** | Payment service layer (Payum integration, NOT loaded as Zend module) |
| **SquareControl** | Loxone MiniServer integration, door code generation |
| **Service** | Shared cross-module services |
| **Setup** | Installation wizard |

**Note:** The Payment module is NOT listed in `config/application.php`. All payment routes run via `Square\Controller\BookingController` under `/square/booking/payment/*`.

### Database Schema

Core tables use a **meta pattern** -- parallel `*_meta` tables (key-value) for flexible extensibility:

- `bs_users` + `bs_users_meta` -- Users with flexible metadata (budget, member status)
- `bs_bookings` + `bs_bookings_meta` -- Bookings with payment/budget info
- `bs_reservations` -- Time slot reservations (linked to bookings)
- `bs_squares` + `bs_squares_meta` -- Courts/facilities
- `bs_squares_pricing` -- Time-based pricing rules (member/non-member)
- `bs_bills` -- Itemized billing
- `bs_events` + `bs_events_meta` -- Events and court closures
- `bs_member_emails` -- Member email verification list

### Frontend Assets

| Library | Version | Location |
|---------|---------|----------|
| Bootstrap | 5.3.8 | `public/vendor/bootstrap/` |
| jQuery | 3.7.1 | `public/js/jquery/` |
| jQuery UI | 1.14.1 | `public/js/jquery-ui/` |
| TinyMCE | 6.8.5 | `public/js/tinymce/` |
| Font Awesome | 4.x | `public/vendor/font-awesome/` |

CSS load order: `bootstrap.min.css` -> `jquery-ui.min.css` -> `app.css` -> `font-awesome` -> `tennis-tcnkail.min.css`

**Note:** `.js` and `.min.js` files must be kept in sync manually (no build tool).

### Routing

Routes defined in each module's `config/module.config.php`:

| Route | Purpose |
|-------|---------|
| `/` | Frontend calendar |
| `/square/booking/*` | Booking flow (customization -> confirmation -> payment) |
| `/square/booking/payment/pay/:bid` | Pay open bill |
| `/square/booking/payment/done` | Payum done callback (PayPal, Klarna) |
| `/square/booking/payment/confirm` | Payum confirm callback (Stripe SCA) |
| `/backend/*` | Admin area (bookings, users, config) |
| `/backend/config/member-emails` | Member email list management |
| `/user/*` | Login, registration, account |
| `/user/bookings/bills/:bid` | User bill view with payment buttons |

---

## Features

### Booking System

**Single Bookings:** One-time court reservations with time slot selection, player name input, collision detection, email confirmation with iCal attachment, and door codes (if Loxone enabled).

**Subscription Bookings (Serienbuchungen):** Recurring bookings -- weekly or biweekly frequency, date range selection, collision detection per occurrence, group management, bulk cancellation.

**Booking Range (Multi-Buchungen):** Admin feature for creating multiple bookings at once -- multiple dates and time slots, collision detection for all combinations. Useful for events, tournaments, training camps.

**Multi-Slot Reservations:** Bookings spanning multiple consecutive time slots with visual overlays on calendar and pricing calculated for full range.

### Calendar

**Mobile-First Design:**
- Day View (mobile default), 3-Day View (tablet), Week View (desktop)
- Touch gestures: swipe left/right for navigation (touch devices only)
- CSS Grid layout

**Calendar Popup (Squarebox):**
- Desktop: 720px modal, 2x2 grid layout, jQuery UI centered
- Mobile: 90vw fixed overlay, stacked sections, touch-optimized
- AJAX-loaded booking form with datepicker

### Auto-Registration with Member Recognition

New "I am a club member" checkbox on registration form:
- When checked: user is auto-activated (`status=enabled`, `member=1`)
- Email checked against `bs_member_emails` table for verification
- Admin notification includes member status and email verification result
- Backend UI at `/backend/config/member-emails` for managing member email list (CSV import supported)

**Migration required:** Run `data/db/migrations/002-member-emails.sql` to create `bs_member_emails` table.

### Email Notifications

Event-driven system: `BookingService::createSingle()` triggers `create.single` event -> `NotificationListener::onCreateSingle()` composes and sends email.

Email includes: booking details, player names, itemized bill, payment information (method + budget deduction), translated billing status, guest payment instructions, door code (if Loxone enabled), iCal attachment.

**Salutation:** All outgoing emails use `Hallo Vorname Nachname` (fallback: alias). No gender-based forms. Applied in `User\MailService::send()` (booking confirmations) and directly in Backend/Square BookingControllers (cancel, reactivate, edit, bulk, payment-failed).

### My Bookings Page (`/user/bookings`)

**Smart-sort and default filter:**

Bookings are grouped and sorted in three sections:
1. **Pending future** (unpaid, nearest first) — highlighted in yellow (`table-warning`)
2. **Upcoming future** (paid/free, nearest first)
3. **Past** (newest first — "jung nach alt")

Separator "Jetzt" appears between future and past sections.

**Smart default filter:** Auto-selects on page load:
- `pending` — if any unpaid booking exists (past OR future)
- `upcoming` — if no unpaid bookings but future bookings exist
- `all` — otherwise

**Notification badge:** Orange badge (unpaid count) or green badge (upcoming count) on "My bookings" button in userpanel and navbar. On **desktop** (hover devices): clicking the badge opens a Bootstrap popover with next 4 bookings summary. On **mobile/touch**: badge is a pure indicator only (no tap-conflict with link navigation).

Key files: `module/User/view/user/account/bookings.phtml`, `public/js/controller/user/bookings.js`, `module/User/src/User/View/Helper/LastBookings.php`

### Responsive Backend Tables

Progressive column hiding for booking list (13 columns -> 5 on mobile) and user list (7 -> 5). Sortable columns, per-column filters, icon-only actions with tooltips.

---

## Payment Integration

### Supported Gateways

**PayPal** (Primary):
- NVP/SOAP API, sandbox + live
- Configuration: Username, Password, Signature

**Stripe** (Optional):
- Card, SEPA Direct Debit, iDEAL, Giropay, Apple Pay, Google Pay
- PaymentIntents API for SCA/3D Secure compliance
- Webhook for async confirmation (SEPA)
- URL: `https://<domain>/payment/booking/webhook`

**Klarna** (Optional):
- Available via Stripe as payment method

### Payment Flow

1. `SquarePricingManager::getFinalPricingInRange()` calculates total from `bs_squares_pricing`
2. If `$total > 0`: payment buttons shown on confirmation page
3. Budget check: if sufficient -> direct booking or partial payment
4. User selects gateway -> Payum handles secure token-based payment
5. Email sent, booking status set to `paid` or `pending`
6. Stripe webhook updates async payments (SEPA)

### Pay Open Bills

Users with pending bookings can pay later from `/user/bookings/bills/:bid`. The `payLater` meta flag prevents booking cancellation on payment failure -- the booking stays intact, only an error message is shown.

### Manual Payment Instructions

For guest bookings with "Pay Later", payment instructions with PayPal email for Friends & Family transfers are displayed. Configure `paypalEmail` in `config/autoload/project.php`.

### Unpaid Booking Cleanup

MySQL Scheduled Event removes unpaid bookings automatically (every 15 min, bookings older than 3 hours with `directpay=true` and `status_billing=pending`).

---

## Budget System

Users can have a prepaid budget (Guthaben) in `bs_users_meta` (key: `budget`, value in EUR). Admin-editable in Backend -> User Edit.

**Scenarios:**
- **Full coverage:** Budget >= total -> "Mit Budget zahlen" button, no gateway needed
- **Partial coverage:** Budget deducted first, remainder via PayPal/Stripe/Klarna
- **No budget:** Full amount via gateway

**Refund:** Both cancel and delete paths restore budget to user account via `BookingService::refundBudget()`.

---

## Pricing System

4-way pricing matrix in `bs_squares_pricing`:

| User Type | Guest | Pricing |
|-----------|-------|---------|
| Member | No | Member price (e.g., 0.00 EUR) |
| Member | Yes | 50% of non-member price |
| Non-Member | No | Full non-member price |
| Non-Member | Yes | Full non-member price (no discount) |

Time-based pricing rules with date range, day-of-week, and time slot granularity. Configured in Backend -> Squares -> Pricing.

---

## Backend Admin

Access: `/backend` (requires authentication + admin permission)

### Booking Management

- **List View:** Sortable table, per-column filters, 13 columns (responsive: 13 -> 5 on mobile)
- **Actions:** Edit, Cancel (active), Reactivate (cancelled, if slot free + permission), Delete (cancelled, admin only)
- **Reactivate:** Requires `calendar.reactivate-bookings` privilege + collision check via `getInRange()`
- **Edit View:** BS5 grid layout (2x2, 4 sandboxes)
- **Delete/Cancel:** Confirmation page with `<form method="post">`, budget refund

### User Management

- **List View:** 7 columns, responsive hiding, icon-only actions (Edit, View Bookings)
- **Edit View:** BS5 grid (2 columns), budget field, password reset, permissions

### Form Layout Pattern

All backend forms use Bootstrap 5 grid (`row`/`col-md-6`), NOT `<table>` wrappers. `.edit-actions` flex container for buttons. Exception: `bills.phtml` and `players.phtml` use valid table layouts.

---

## Docker Setup

Single `Dockerfile` (PHP 8.4-apache) for DEV and PROD. Three compose files:

| File | Purpose |
|------|---------|
| `docker-compose.yml` | Production base (court, mariadb, mailhog + Traefik labels) |
| `docker-compose.override.yml` | Local dev (Traefik service, self-signed HTTPS, auto-loaded) |
| `docker-compose.dev-server.yml` | DEV on server alongside production |

### Services

| Service | Port | Purpose |
|---------|------|---------|
| traefik | 80, 443, 8080 | Reverse proxy with HTTPS |
| court | via Traefik | PHP 8.4 Apache app server |
| mariadb | 3306 | MariaDB 10.11 database |
| mailhog | 8025 (UI), 1025 (SMTP) | Email testing |

### DEV vs PROD

Toggle via `INSTALL_XDEBUG` in `.env`:
- `true`: Xdebug 3.4 (port 9003, IDE key: PHPSTORM)
- `false`: No debug tools, production-ready

### Composer & Vendor

`vendor/` is committed to Git (production workflow). Composer is NOT run during Docker build.

**Note:** `composer update` is currently broken due to `payum/payum-module` requiring the ZF2 metapackage which conflicts with individually forked Zend packages in `src/Zend/`. Vendor changes must be managed manually.

```bash
# Local dev
docker compose build && docker compose up -d

# Production (external Traefik)
docker compose -f docker-compose.yml up -d

# DEV on server
docker compose -f docker-compose.dev-server.yml up -d

# Shell access
docker compose exec court bash
```

---

## Configuration

### Configuration Files

| File | Purpose | Git Status |
|------|---------|-----------|
| `.env` | Docker environment variables | `.gitignore` (from `.env.example`) |
| `config/autoload/local.php` | DB, mail, payment API keys | `.gitignore` (from `.dist`) |
| `config/autoload/project.php` | URLs, session, payment toggles | `.gitignore` (from `.dist`) |
| `config/init.php` | Dev mode, timezone, error reporting | `.gitignore` (from `.dist`) |

### Database Migrations

Base schema must be imported manually: `data/db/ep3-bs.sql`

All subsequent migrations run **automatically on app startup**. The `MigrationManager` checks which migrations are needed (via idempotent SQL checks) and only executes missing ones. Schema version tracked in `bs_options`.

| Migration | Purpose | Auto |
|-----------|---------|------|
| `data/db/ep3-bs.sql` | Base schema | Manual |
| `001-add-indexes.sql` | Performance indexes | Auto |
| `002-member-emails.sql` | Member email verification | Auto |
| `003-cleanup-interval.sql` | Unpaid booking cleanup interval (test) | Auto |
| `004-cleanup-interval-reset.sql` | Reset cleanup to production values | Auto |
| `005-opening-times.sql` | Opening times table (`bs_squares_opening_times`) | Auto |

Registry: `data/db/migrations.php`

### Translations

German translations (primary) in `data/res/i18n/de-DE/`:
- `booking.php`, `square.php`, `backend.php`, `base.php`, `user.php`
- Format: `'English text' => 'Deutsche Uebersetzung'`

---

## Security

### Server Hardening (Mar 2026)

Comprehensive OWASP Top 10 security audit applied:

**Apache:**
- `ServerTokens Prod`, `ServerSignature Off` -- hide server version
- `expose_php = Off` -- hide PHP version
- `mod_headers` enabled for security headers

**HTTP Security Headers** (in `public/.htaccess`):
- `Strict-Transport-Security: max-age=31536000; includeSubDomains`
- `X-Frame-Options: SAMEORIGIN`
- `X-Content-Type-Options: nosniff`
- `Referrer-Policy: strict-origin-when-cross-origin`
- `Permissions-Policy: camera=(), microphone=(), geolocation=()`
- `X-Powered-By` header removed

**Session:**
- `SameSite=Lax` cookie attribute (was `None`)

### Input Validation & Injection Prevention

**SQL Injection:**
- Backend BookingController: prepared statements with `QUERY_MODE_PREPARE` (was `sprintf()` with string interpolation)
- ReservationManager: `Zend\Db\Sql\Where` predicates (was string concatenation)

**XSS:**
- Stripe error messages: `htmlspecialchars()` in flash messages
- Confirmation page JS: `json_encode()` with `JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT`
- Players page: `$this->escapeHtml()` for email in mailto href
- Inline onclick handlers replaced with function calls

**CSRF:**
- Booking confirmation: session-based `random_bytes(32)` token (was `sha1('Quick and dirty' . time())`)
- Registration form: HMAC token (was deprecated `Bcrypt::setSalt()`)

### Authentication & Token Security

- Password reset tokens: HMAC-based (`hash_hmac('sha256', uid+pw, created)`) replacing predictable bcrypt-substring
- Activation codes: HMAC-based (`hash_hmac('sha256', uid+created, email)`) replacing `sha1(created)`
- All token comparisons use `hash_equals()` for timing-safe comparison
- Bcrypt cost factor: 10 (was 6)

### Hardening

- `unserialize()` with `['allowed_classes' => false]` everywhere
- Removed `@` error suppression from `getServiceLocator()` and `file_get_contents()`
- `$_SERVER` guard for `HTTP_STRIPE_SIGNATURE` in webhook handler

### Frontend Library Upgrades

| Library | Old Version | New Version | Reason |
|---------|-------------|-------------|--------|
| jQuery | 1.12.4 | 3.7.1 | CVE fixes (Tenable WAS finding) |
| jQuery UI | 1.10.4 | 1.14.1 | Compatibility with jQuery 3.x |
| TinyMCE | 4.0.26 | 6.8.5 | EOL version, XSS vulnerabilities |

---

## Loxone Door Control

Automatic door access codes for bookings:

1. **Booking Created:** 4-digit PIN generated, sent to Loxone MiniServer via HTTP API, stored in `bs_bookings_meta`
2. **Email Sent:** Confirmation includes door code
3. **Booking Time Slot:** Code active on Loxone during booking period
4. **After Booking:** Cron job cleans up expired codes
5. **Cancellation:** Code immediately deleted

**Configuration** in `config/autoload/project.php`:
```php
'feature' => ['square_control' => true],
'square_control' => [
    'loxone' => [
        'api_url' => 'http://192.168.1.100/dev/sps/io/door-codes/',
        'username' => 'admin',
        'password' => 'your_password',
    ],
],
```

**Cron Job:**
```bash
*/5 * * * * cd /path/to/app && docker compose exec -T court php public/index.php square-control cleanup
```

---

## Laravel Migration

A migration to Laravel 11 is planned. Planning branch: `dev_sh_laravel_migration`. The current ZF2 system remains in active production use.

---

## Contributing

### Commit Convention

```
Type: Short description (#issue)

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
```

Types: `Feat`, `Fix`, `Refactor`, `Docs`, `UI`, `Security`, `Upgrade`

### Code Style

- PSR-4 autoloading: `\{Module}\{Class}` maps to `module/{Module}/src/{Module}/{Class}.php`
- Naming: `*Controller`, `*Manager`, `*Service`, `*Table`, `*Factory`, `*Entity`
- Views: `module/{Module}/view/{module-lowercase}/{controller}/{action}.phtml`
- Translations: `data/res/i18n/de-DE/{module}.php` -- key = English, value = German

### Debugging

- Use `error_log()` for debug output -> `data/log/errors.txt` in Docker
- Do NOT use `syslog()` (doesn't appear in Docker logs)
- Payum data: `error_log('payment: ' . json_encode($payment instanceof \ArrayAccess ? iterator_to_array($payment) : $payment))`

---

## Known Issues

| Issue | Status | Description |
|-------|--------|-------------|
| Calendar overlay duplication | Fixed Feb 2026 | Added overlay cleanup in `updateCalendarEvents()` |
| Backend Abo out-of-range rows (#47) | Fixed Feb 2026 | `array_filter()` after `getByBookings()` |
| Desktop swipe closes modal (#12) | Fixed Mar 2026 | Touch-only check + squarebox guard |
| Pending bookings not highlighted (#79) | Fixed Mar 2026 | `table-warning` class for pending rows |
| "temporaer belegt" visible to visitors (#79) | Fixed Mar 2026 | Visitors see "Belegt" instead of "temporaer belegt" for pending bookings |
| Email billing status raw slugs (#80) | Fixed Mar 2026 | `$this->t(ucfirst())` translation |
| Email booking details double spacing (#80) | Fixed Mar 2026 | Single newlines between detail lines (Platz, Datum, Zeit, Nr) |
| Payment token/cleanup issues (#85) | Fixed Mar 2026 | Graceful token error handling, session-independent messages, payment method tracking |
| Cancellation email duplicates (#89) | Fixed Mar 2026 | Removed duplicate email sending, fixed guest salutation |
| Email salutation gender-based (#81) | Fixed Mar 2026 | Unified to "Hallo Vorname Nachname" across all emails |
| My bookings sort/filter (#65, #71) | Fixed Mar 2026 | Smart-sort (pending first, upcoming, past newest-first), smart default filter, notification badge with desktop popover |
| Squarebox booking form init (#91) | Fixed Mar 2026 | Consolidated form initialization after AJAX load |
| Datepicker arrows invisible (#92) | Fixed Mar 2026 | CSS override with Unicode arrows instead of sprite icons |
| Booking limit counts slots (#93) | Fixed Mar 2026 | Sum slot durations instead of counting reservations |
| Event overlay not merging (#94) | Fixed Mar 2026 | JS `$.inArray` bug, off-by-one loop, multi-column merge, datepicker z-index |
| Datepicker behind squarebox (#96) | Fixed Mar 2026 | Raised datepicker z-index above squarebox (2048 > 1536) |
| Mobile squarebox layout (#97) | Fixed Mar 2026 | Close button top-right (was bottom), pricing table responsive, rules text no height cap |
| `composer update` broken | Known | `payum/payum-module` conflicts with forked ZF2 packages |

---

## License

Proprietary -- Copyright 2026

Based on [tkrebs/ep3-bs](https://github.com/tkrebs/ep3-bs) (see upstream LICENSE).

---

<div align="center">

**v2.1.1** — Production-ready ZF2 | **Next:** Laravel 11 Migration

</div>

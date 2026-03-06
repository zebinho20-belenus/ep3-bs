# EP3-BS Court Booking System

<div align="center">

![Status](https://img.shields.io/badge/Current-ZF2_Production-green)
![Migration](https://img.shields.io/badge/Migration-Laravel_11_Planned-blue)
![PHP](https://img.shields.io/badge/PHP-8.4-purple)
![License](https://img.shields.io/badge/License-Proprietary-red)

**Professional Online Booking System for Tennis Courts (and other sports facilities)**

Fork of [tkrebs/ep3-bs](https://github.com/tkrebs/ep3-bs) v1.7.0
Extended with **Direct Payment**, **PWA Support**, **Member/Guest Pricing**, and **Loxone Door Control**

[📖 Technical Documentation](CLAUDE.md) • [🚀 Migration Plan](MIGRATION-PLAN.md) • [✅ Feature Checklist](FEATURE-CHECKLIST.md)

</div>

---

## 📋 Table of Contents

- [Overview](#-overview)
- [Current System (ZF2)](#-current-system-zf2)
- [Quick Start](#-quick-start)
- [Architecture](#-architecture)
- [Features](#-features)
- [Payment Integration](#-payment-integration)
- [Budget System](#-budget-system)
- [Pricing System](#-pricing-system)
- [Backend Admin](#-backend-admin)
- [Docker Setup](#-docker-setup)
- [Configuration](#-configuration)
- [Loxone Door Control](#-loxone-door-control)
- [Laravel Migration](#-laravel-migration)
- [Contributing](#-contributing)
- [Support](#-support)

---

## 🎯 Overview

EP3-BS is a comprehensive online booking system designed specifically for sports facilities (tennis courts, squash courts, etc.). This fork extends the original [tkrebs/ep3-bs](https://github.com/tkrebs/ep3-bs) with professional payment processing, budget management, and smart door access control.

### Key Extensions

- ✅ **Direct Payment** via PayPal, Stripe (Card, SEPA, iDEAL, Giropay), Klarna
- ✅ **Budget/Gift Card System** (Prepaid, refunds, admin-managed)
- ✅ **Member/Guest Pricing** with 50% guest discount for members
- ✅ **Loxone Door Control** (automatic 4-digit codes per booking)
- ✅ **PWA Support** (app-like experience on smartphones)
- ✅ **Bootstrap 5 UI** (responsive design, mobile-first)
- ✅ **Subscription Bookings** (weekly/biweekly recurring)
- ✅ **Pay Open Bills** — users can pay pending invoices from their bill page (#72)
- ✅ **Backend Bills Editor** (inline editing for admins)
- ✅ **TinyMCE Rich Text Editor** for content management
- ✅ **File Upload** for court images
- ✅ **Docker-based Development** with Traefik, MariaDB, MailHog

### Production Deployment

- 🌐 Production: `https://your-domain.com`
- 🧪 Dev Server: `https://dev.your-domain.com`
- 🔧 GitHub: `git@github.com:zebinho20-belenus/ep3-bs.git`

---

## 💻 Current System (ZF2)

**Technology Stack:**
- PHP 8.4 (Apache)
- Zend Framework 2 (custom Entity-Manager-Service pattern)
- Bootstrap 5.3.3 + jQuery
- Payum (payment framework)
- MariaDB 10.11
- Traefik (reverse proxy with HTTPS)
- PWA (manual service worker)

**Code Statistics:**
- 13 Modules
- 307 PHP files
- 15 Database tables (bs_* schema)
- 4 Languages (de-DE, en-US, fr-FR, hu-HU)

---

## 🚀 Quick Start

### Prerequisites

- Docker & Docker Compose
- Git
- (Optional) gh CLI for GitHub integration

### Installation

```bash
# 1. Clone repository
git clone git@github.com:zebinho20-belenus/ep3-bs.git
cd ep3-bs

# 2. Switch to development branch (if needed)
git checkout dev_sh_docker_devops

# 3. Create configuration files
cp .env.example .env
cp config/autoload/local.php.dist config/autoload/local.php
cp config/autoload/project.php.dist config/autoload/project.php

# Edit configuration files with your credentials:
# - .env: Docker environment (ports, Xdebug)
# - local.php: Database, SMTP, Payment API keys
# - project.php: URLs, session config, feature toggles

# 4. Build and start Docker containers
docker compose build
docker compose up -d

# 5. Install/update PHP dependencies (inside container)
docker compose exec court composer update

# 6. (Optional) Import database schema or production dump
docker compose exec -T mariadb mariadb -u root -p<password> <database> < data/db/ep3-bs.sql
```

**Access Points:**
- 🌐 Application: https://court.localhost (self-signed cert)
- 📊 Traefik Dashboard: http://localhost:8080
- 📧 MailHog UI: http://localhost:8025

### Important: Pricing Setup

After importing a database, verify that `bs_squares_pricing.date_end` covers the current date. If pricing rules are expired, `$payable` stays `false` and payment buttons won't appear on the confirmation page.

---

## 🏗 Architecture

### Framework Pattern

**Zend Framework 2 MVC** with custom layered pattern:

```
Entity (data container, extends AbstractEntity)
  → Manager (CRUD + DB transactions via TableGateway)
    → Service (business logic, event-driven)
      → Controller (HTTP layer, request handling)
        → View (.phtml templates via Zend\View)
```

### Modules (`module/`)

| Module | Responsibility | Key Components |
|--------|---------------|----------------|
| **Base** | Core utilities, abstract classes, view helpers | AbstractEntity, AbstractManager, MailService, view helpers |
| **Backend** | Admin dashboard | User management, booking management, system config |
| **Booking** | Booking lifecycle | BookingService, BookingManager, email notifications |
| **Square** | Court definitions | Public booking UI, customization, confirmation views |
| **Calendar** | Calendar widget | Day/Week views, event overlays, squarebox popup |
| **Event** | Events & closures | Event CRUD, court blocking |
| **Frontend** | Public pages | Landing page, calendar display |
| **User** | Authentication | Login, registration, account management |
| **Payment** | Payment service layer | Payum integration (NOT loaded as Zend module — routes via Square module) |
| **SquareControl** | Door access | Loxone MiniServer integration, code generation |
| **Service** | Shared services | Cross-module utilities |
| **Setup** | Installation | Setup wizard |

### Database Schema

**Core Tables:**
- `bs_users` + `bs_users_meta` — Users with flexible metadata
- `bs_bookings` + `bs_bookings_meta` — Bookings with payment/budget info
- `bs_reservations` — Time slot reservations (linked to bookings)
- `bs_squares` + `bs_squares_meta` — Courts/facilities
- `bs_squares_pricing` — Time-based pricing rules (member/non-member)
- `bs_bills` — Itemized billing
- `bs_events` + `bs_events_meta` — Events and court closures

**Meta Pattern:**
Core entities use parallel `*_meta` tables (key-value) for flexible extensibility without schema changes.

### Frontend Assets

**Bootstrap 5 + jQuery Stack:**
- Bootstrap 5.3.3 (local: `public/vendor/bootstrap/`)
- jQuery + jQuery UI (datepicker, calendar interactions)
- Custom CSS: `public/css/app.css` (design tokens, BS5 overrides)
- CSS load order: `bootstrap.min.css` → `jquery-ui.min.css` → `app.css` → `font-awesome` → `tennis-tcnkail.min.css`

**JavaScript:**
- Controller-specific scripts in `public/js/controller/`
- Calendar JS: `public/js/controller/calendar/index.js` (+ `index.min.js`)
- Table sort/filter: `public/js/controller/backend/table-sort.js` (+ `table-sort.min.js`)
- **Note:** `.js` and `.min.js` files must be kept in sync manually (no build tool)

**PWA:**
- Service Worker: `public/js/sw.js`
- Manifest: `public/manifest.json`
- App-like experience on mobile devices

### View Helpers (Bootstrap 5)

Form helpers output Bootstrap 5 markup:
- `FormRowDefault` → `<div class="mb-3"><label>...</label><input class="form-control">...</div>`
- `FormRowCompact` → Same with `form-control-sm` and `mb-2`
- `FormRowSubmit` → `<div class="mb-3"><input class="btn btn-primary">...</div>`

**Important:** Use form helpers directly in BS5 grid (`row`/`col-*`), do NOT wrap in `<table>` tags.

### Routing

Routes defined in each module's `config/module.config.php`:
- `/` — Frontend calendar
- `/square/booking/*` — Booking flow (customization → confirmation → payment)
- `/square/booking/payment/pay/:bid` — Pay open bill (payLater flow, #72)
- `/square/booking/payment/done` — Payum done callback (PayPal, Klarna)
- `/square/booking/payment/confirm` — Payum confirm callback (Stripe SCA)
- `/backend/*` — Admin area (bookings, users, config)
- `/user/*` — Login, registration, account
- `/user/bookings/bills/:bid` — User bill view (with payment buttons for pending bills)

**Note:** The Payment module (`module/Payment/`) is NOT loaded as a Zend module (not in `config/application.php`). All payment routes run via `Square\Controller\BookingController`.

---

## ✨ Features

### Booking System

#### Single Bookings
Standard one-time court reservations with:
- Time slot selection (configurable time blocks)
- Player name input (optional)
- Collision detection (prevents double-booking)
- Email confirmation with iCal attachment
- Door codes (if Loxone enabled)

#### Subscription Bookings (Serienbuchungen)
Recurring bookings for regular players:
- Weekly or biweekly frequency
- Date range selection (start → end)
- Collision detection per occurrence
- Group management (linked by `recurring_group_id`)
- Bulk cancellation option

#### Booking Range (Multi-Buchungen)
Admin feature for creating multiple bookings at once:
- Multiple dates (e.g., Mon-Fri)
- Multiple time slots per day
- Collision detection for all combinations
- Useful for events, tournaments, training camps

#### Multi-Slot Reservations
Bookings spanning multiple consecutive time slots:
- Visual overlays on calendar
- Single confirmation for entire duration
- Pricing calculated for full range

### Calendar

**Mobile-First Design:**
- **Day View** (mobile default): Single day, all time slots
- **3-Day View** (tablet): Three consecutive days
- **Week View** (desktop): Full week overview
- Touch gestures: swipe left/right for navigation
- CSS Grid layout (no DOM overlay manipulation)

**Calendar Popup (Squarebox):**
- Desktop: 720px modal, 2x2 grid layout, jQuery UI centered
- Mobile: 90vw fixed overlay, stacked sections, touch-optimized
- AJAX-loaded booking form
- Datepicker integration

**Event Overlays:**
- Multi-slot bookings span visually across time slots
- Event blocks for court closures
- Group highlighting on hover

### Responsive Backend Tables

**Progressive Column Hiding:**

Booking List (13 columns → 5 on mobile):
| Breakpoint | Visible Columns |
|------------|----------------|
| ≥1536px | All 13: Status, ID, User, Member, Day, Date, Time, Court, Notes, Price, Billing, Budget, Actions |
| ≤1280px | 11 (hide: Member, Billing Status) |
| ≤1024px | 8 (hide: Day, Notes, Budget) |
| ≤768px | 7 (hide: Court) |
| ≤512px | 5 (hide: Nr., Price) → **Status, User, Date, Time, Actions** |

User List (7 columns → 5 on mobile):
| Breakpoint | Hidden Columns |
|------------|---------------|
| ≤1280px | Notes |
| ≤1024px | Email |

**Table Features:**
- Sortable columns (click header)
- Per-column filters (text input per column)
- Icon-only actions with tooltips
- `table-layout: fixed` for consistent widths

### Email Notifications

**Booking Confirmation Email:**
- Booking details (date, time, court)
- Player names
- Itemized bill (time slots + pricing)
- Payment information (method, budget deduction)
- Guest payment instructions (if not paid via budget/gateway)
- Door code (if Loxone enabled)
- iCal attachment (add to calendar)

**Event-Driven System:**
`BookingService::createSingle()` → triggers `create.single` event → `NotificationListener::onCreateSingle()` → composes and sends email

**Important:** Email is sent DURING `createSingle()`, so all meta (payment, budget) must be set BEFORE calling `createSingle()`.

---

## 💳 Payment Integration

### Supported Gateways

#### PayPal (Primary, 80% of users)
- PayPal NVP/SOAP API
- Configuration: Username, Password, Signature (Sandbox + Live)
- Instant confirmation
- Refund support

#### Stripe (Optional)
Payment methods:
- Credit/Debit Card
- SEPA Direct Debit (async, requires webhook)
- iDEAL (Netherlands)
- Giropay (Germany)
- Apple Pay (domain verification required)
- Google Pay

**Strong Customer Authentication (SCA):**
Stripe uses PaymentIntents API for 3D Secure compliance.

**Webhook Configuration:**
URL: `https://<domain>/payment/booking/webhook`
Events: `payment_intent.canceled`, `payment_intent.payment_failed`, `payment_intent.succeeded`

**Apple Pay:**
Domain must be verified in Stripe Dashboard → Settings → Apple Pay.

#### Klarna (Optional)
Available via Stripe as payment method.

### Payment Flow

1. **Pricing Calculation:**
   `SquarePricingManager::getFinalPricingInRange()` calculates `$total` based on `bs_squares_pricing` rules
2. **Payability Check:**
   `$payable = ($total > 0)` — if `false`, no payment buttons shown
3. **Budget Check:**
   If user has sufficient budget → direct booking OR partial payment
4. **Gateway Selection:**
   User selects PayPal/Stripe/Klarna on confirmation page
5. **Payment Processing:**
   Payum framework handles token-based secure payment
6. **Confirmation:**
   Email sent, booking status set to `paid` or `pending`
7. **Webhook (Stripe):**
   Async confirmation for SEPA, updates booking status

### Pay Open Bills (#72)

Users with `status_billing=pending` bookings can pay later from their bill page (`/user/bookings/bills/:bid`).

**Flow:**
1. User opens bill page → sees PayPal/Stripe/Klarna radio buttons (if pending + not cancelled + total > 0)
2. Selects payment method, clicks "Jetzt bezahlen"
3. Form POSTs to `/square/booking/payment/pay/:bid` → `BookingController::payAction()`
4. Sets `payLater=true` booking meta → creates Payum tokens → redirects to gateway
5. After payment: `doneAction()` sets `status_billing=paid` (success) or shows error (failure)

**Key difference from normal flow:** The `payLater` meta flag prevents `cancelSingle()` on payment failure — the booking stays intact, only an error message is shown.

**Technical note:** PayPal sandbox may report `PAYMENTINFO_0_PAYMENTSTATUS: "Pending"` (paymentreview) while the payment actually completed (`PAYMENTREQUEST_0_PAYMENTSTATUS: "Completed"`). The `doneAction` checks the latter to correctly detect success.

### Manual Payment Instructions

For guest bookings where users choose "Pay Later", the system displays payment instructions for manual PayPal Friends & Family transfers.

**Configuration:**
```php
// config/autoload/project.php
'paypalEmail' => 'payment@your-domain.com',
```

**How it works:**
1. Translation strings use `%s` placeholder for email address
2. Email injected at runtime via `sprintf()`
3. Displayed in:
   - Booking confirmation email
   - Flash message on confirmation page
   - Backend booking emails

**Implementation locations:**
- `NotificationListener::onCreateSingle()` — Email notifications
- `Square\BookingController` — Confirmation page flash message
- `Backend\BookingController` — Backend booking email

**Fallback:** If `paypalEmail` not configured, defaults to `'payment@your-domain.com'`

### Unpaid Booking Cleanup

MySQL Scheduled Event removes unpaid bookings automatically:

```sql
SET GLOBAL event_scheduler = ON;

CREATE EVENT remove_unpaid_bookings
  ON SCHEDULE EVERY 15 MINUTE
  ON COMPLETION PRESERVE
  DO DELETE FROM bs_bookings
     WHERE status = 'single'
       AND status_billing = 'pending'
       AND created < (NOW() - INTERVAL 3 HOUR)
       AND bid IN (SELECT bid FROM bs_bookings_meta
                   WHERE `key` = 'directpay' AND `value` = 'true');
```

Bookings older than 3 hours with `directpay=true` and `status_billing=pending` are automatically removed.

---

## 💰 Budget System

### Concept

Users can have a prepaid budget (Guthaben) stored in `bs_users_meta` table:
- Key: `budget`
- Value: EUR amount (e.g., `50.00`)
- Admin-editable in Backend → User Edit

### Use Cases

- **Gift Cards:** Admin loads budget for gift recipients
- **Prepaid Accounts:** Members prepay for faster booking
- **Corporate Accounts:** Companies prepay for employee bookings
- **Refunds:** Cancelled bookings refund to budget

### Payment Flow with Budget

**Scenario 1: Budget covers full amount**
1. Budget >= Total
2. `$budgetpayment = true`, `$payable = false`
3. "Mit Budget zahlen" button shown (no gateway buttons)
4. Budget deducted immediately on booking
5. Email sent WITHOUT payment instructions

**Scenario 2: Budget partial coverage**
1. Budget < Total
2. Budget deducted first
3. Remaining amount charged via PayPal/Stripe/Klarna
4. Email includes budget info + payment method

**Scenario 3: No budget**
1. Full amount charged via gateway
2. Standard payment flow

### Budget Metadata

Stored in `bs_bookings_meta` for tracking:
- `hasBudget`: `true` if budget was used
- `budget`: Original budget before booking
- `newbudget`: Budget after deduction
- `budgetpayment`: `true` if fully paid by budget

**Important:** These must be set in `$meta` array BEFORE calling `BookingService::createSingle()` (email is sent during `createSingle()`).

### Budget Refund

**Cancellation/Deletion:**
Both cancel and delete paths in `Backend\Controller\BookingController` check:
- `status_billing == 'paid'`
- `refunded != 'true'`

If conditions met → restore `bill_total` to user's budget, set `refunded = 'true'` in booking meta.

---

## 💵 Pricing System

### 4-Way Matrix

Pricing rules in `bs_squares_pricing` table with `member` column:

| User Type | Guest | Pricing |
|-----------|-------|---------|
| **Member** (member=1) | No | Member price (e.g., €0.00) |
| **Member** (member=1) | Yes | **50% of non-member price** |
| **Non-Member** (member=0) | No | Full non-member price |
| **Non-Member** (member=0) | Yes | Full non-member price (no discount) |

**Key Logic:**
Only members get the 50% guest discount. Non-members with guests pay full price.

### Time-Based Pricing Rules

**Configuration in Backend → Squares → Pricing:**

Example rule structure:
- **Date Range:** 2024-01-01 to 2024-12-31
- **Days:** Monday-Friday
- **Time Slots:**
  - 08:00-17:00: €15.00 (member: €0.00)
  - 17:00-22:00: €20.00 (member: €0.00)
- **Days:** Saturday-Sunday
  - 08:00-22:00: €25.00 (member: €0.00)

**Calculation:**
`SquarePricingManager::getFinalPricingInRange()` matches booking time against all active rules, sums up pricing for each time block.

**UI:**
Bootstrap 5 cards with flexbox (replaced nested tables Feb 2026). JavaScript handles dynamic rule creation/editing via `pricing.js` + `pricing.min.js` (must be kept in sync).

---

## 🔧 Backend Admin

### Dashboard Overview

**Access:** `/backend` (requires authentication + admin permission)

**Main Areas:**
- Bookings (list, edit, cancel, delete, reactivate)
- Users (list, edit, budget management)
- Squares (court config, pricing, images)
- Events (create, edit, delete)
- System Config (behavior, texts, status colors)

### Booking Management

**List View** (`/backend/booking`):
- Sortable table (click headers)
- Per-column filters (text inputs)
- 13 columns (responsive: 13 → 5 on mobile)
- Icon-only actions with tooltips:
  - Edit (symbolic-edit)
  - Cancel (symbolic-cross) — active bookings only
  - Reactivate (symbolic-reload) — cancelled bookings, if slot free
  - Delete (symbolic-cross) — cancelled bookings only

**Row Actions Logic:**
- **Active bookings:** Edit + Cancel
- **Cancelled, slot free:** Edit + Reactivate + Delete
- **Cancelled, slot occupied:** Edit + Delete (no Reactivate)

**Reactivate Collision Check:**
`BookingFormat` helper (via `BookingFormatFactory`) injects `ReservationManager` + `BookingManager`. Before showing reactivate icon, checks for overlapping active bookings on same court via `getInRange()`.

**Edit View** (`/backend/booking/edit/:bid`):
- Bootstrap 5 grid layout (2x2, 4 sandboxes)
- Date/time pickers
- Status, billing status, notes
- Budget info display

**Delete Confirmation** (`/backend/booking/delete/:rid`):
- Shows booking details
- Cancel button (sets `status = 'cancelled'`)
- Delete button (permanently removes, admin only)
- Both refund budget if paid

**Backend Bills Editor:**
Inline editing for admins to adjust booking billing:
- Edit description, quantity, price per line item
- Add/remove line items
- Recalculate totals
- Update booking meta with new bill

### User Management

**List View** (`/backend/user`):
- 7 columns (responsive hiding on mobile)
- Icon-only actions: Edit, View Bookings

**Edit View** (`/backend/user/edit/:uid`):
- Bootstrap 5 grid (2 columns: Account + Personal Data)
- **Budget Field:** Admin-editable, EUR amount
- Password reset
- Permissions (member, admin)

### Form Layout Pattern (Bootstrap 5)

**Standard since Feb 2026:**
- Use BS5 grid (`row` / `col-md-6` / `col-lg-6`)
- Do NOT use `<table>` wrappers for forms
- Form helpers output correct BS5 markup
- `.edit-actions` flex container for buttons:
  - Desktop: horizontal, centered
  - Mobile: stacked, full-width
- Button variants:
  - `.default-button-danger` — red (delete/cancel)
  - `.default-button-outline` — transparent (secondary)

**Converted Pages:**
- `booking/edit.phtml`, `event/edit.phtml`, `config-square/edit.phtml`
- `user/edit.phtml`, `config/text.phtml`, `config/behaviour.phtml`
- All config pages, pricing page

**NOT Converted (valid table use):**
- `booking/bills.phtml` — tabular data entry
- `booking/players.phtml` — tabular data

### Time Dropdowns

**All backend time-of-day fields use Select dropdowns:**
- Full hours: 07:00, 08:00, ..., 22:00
- Forms: `Booking/EditForm`, `Event/EditForm`, `ConfigSquare/EditForm`
- Minute-based fields (duration config) remain Text inputs

### TinyMCE Rich Text Editor

**Content Management:**
- Info texts for squares
- Email templates (future)
- System messages

**Configuration:**
- Toolbar presets: light, medium, full
- Image upload integration
- Code view for advanced users

### File Upload (Square Images)

**Admin can upload images for courts:**
- Target: `public/imgs-client/upload/`
- Image optimization (resize to max 1200px)
- Formats: JPG, PNG
- Displayed on public booking page

---

## 🐳 Docker Setup

### Architecture

Single `Dockerfile` (PHP 8.4-apache) for both DEV and PROD:
- **DEV:** Xdebug 3.4, error reporting enabled
- **PROD:** No debug tools, error logging only

**Three Compose Files:**
1. **`docker-compose.yml`** — Production-compatible base
   - Services: court, mariadb, mailhog
   - Traefik labels for external proxy
   - External `traefik_web` network
2. **`docker-compose.override.yml`** — Local dev additions (auto-loaded)
   - Traefik service (local reverse proxy)
   - Self-signed HTTPS certificates
   - Local `traefik_web` network
3. **`docker-compose.dev-server.yml`** — DEV on server (alongside production)
   - Separate service names (court-dev, mariadb-dev)
   - Separate Traefik routers (all suffixed `-dev`)
   - Separate DB port (3307)

### Services

| Service | Port | Purpose |
|---------|------|---------|
| **traefik** | 80, 443, 8080 | Reverse proxy with HTTPS (Let's Encrypt on prod, self-signed locally) |
| **court** | via Traefik | PHP 8.4 Apache, app server |
| **mariadb** | 3306 | MariaDB 10.11 database |
| **mailhog** | 8025 (UI), 1025 (SMTP) | Email testing, catches all outgoing mail |

### Environment Configuration

**`.env` file controls:**
- `INSTALL_XDEBUG=true/false` — Toggle between DEV and PROD
- `DOCKER_SOCKET` — Docker socket path (macOS: `~/.docker/run/docker.sock`)
- `DOCKER_API_VERSION=1.44` — For Traefik compatibility with Docker 29+
- Database credentials
- Port mappings

**Xdebug 3 Configuration:**
- Port: 9003 (not 9000 like Xdebug 2)
- IDE Key: `PHPSTORM`
- Mode: `debug,develop`

### Composer & Vendor

**Important:** `vendor/` is committed to Git (production workflow).

**Why?**
- Production server doesn't have Composer installed
- Custom Stripe fork (`lolmx/Stripe.git`) causes SSL issues during Docker build
- Volume mount `./:/var/www/html` provides `vendor/` at runtime

**Updating Dependencies:**

⚠️ `composer update` is currently broken due to `payum/payum-module` requiring the ZF2 metapackage (`zendframework/zendframework ~2.2`) which conflicts with the individually forked Zend packages in `src/Zend/`. Vendor changes must be managed manually.

```bash
# If needed, use --ignore-platform-reqs:
docker compose exec court composer update --ignore-platform-reqs
git add vendor/
git commit -m "Update composer dependencies"
```

### Docker Commands

```bash
# Local dev (override auto-loaded):
docker compose build
docker compose up -d
docker compose logs -f court

# Production (base only, external Traefik):
docker compose -f docker-compose.yml build
docker compose -f docker-compose.yml up -d

# DEV on server (alongside production):
docker compose -f docker-compose.dev-server.yml build
docker compose -f docker-compose.dev-server.yml up -d

# Shell access:
docker compose exec court bash

# MariaDB access:
docker compose exec mariadb mariadb -u root -p

# Restart specific service:
docker compose restart court
```

### Traefik Configuration

**Local Development:**
- Dashboard: http://localhost:8080
- HTTPS: Self-signed certificate (browser warning expected)
- Router: `court-http` and `court-https`

**Production:**
- Let's Encrypt automatic certificates
- ACME challenge via HTTP-01
- Certificate resolver: `letsencrypt`
- Email: configured in Traefik static config

**DEV Server (alongside production):**
- All router/middleware/service names suffixed with `-dev`
- Separate hostname: `dev.your-domain.com`
- Avoids collision with production Traefik config

### Known Issues & Fixes

**macOS Docker Desktop:**
- Docker socket at `~/.docker/run/docker.sock` (not `/var/run/docker.sock`)
- Set `DOCKER_SOCKET=~/.docker/run/docker.sock` in `.env`

**Traefik + Docker API 1.53:**
- Docker 29+ uses API version 1.53
- Traefik requires `DOCKER_API_VERSION=1.44` env var on container
- Set in `docker-compose.override.yml`

**PECL Install Fails:**
- `pecl install xdebug` may fail during build
- Solution: Download Xdebug source tarball via `wget`, compile manually
- Implemented in `Dockerfile` (Xdebug 3.4.2 for PHP 8.4)

---

## ⚙️ Configuration

### Configuration Files

| File | Purpose | Git Status |
|------|---------|-----------|
| `.env` | Docker environment variables | `.gitignore` (create from `.env.example`) |
| `config/autoload/local.php` | DB, mail, payment API keys | `.gitignore` (create from `.dist`) |
| `config/autoload/project.php` | URLs, session, payment toggles, PayPal email | `.gitignore` (create from `.dist`) |
| `config/init.php` | Dev mode, timezone, error reporting | `.gitignore` (create from `.dist`) |

### local.php Configuration

```php
<?php
return [
    'db' => [
        'driver'   => 'Pdo',
        'hostname' => 'mariadb', // Docker service name
        'database' => 'your_database',
        'username' => 'your_user',
        'password' => 'your_password',
    ],
    'mail' => [
        'type' => 'smtp',
        'host' => 'mailhog', // Docker service name (dev)
        'port' => 1025,
    ],
    'payum' => [
        'paypal_express_checkout_nvp' => [
            'username'  => 'your_paypal_api_username',
            'password'  => 'your_paypal_api_password',
            'signature' => 'your_paypal_api_signature',
            'sandbox'   => true, // false for production
        ],
        'stripe_checkout' => [
            'publishable_key' => 'pk_test_...',
            'secret_key'      => 'sk_test_...',
        ],
    ],
];
```

### project.php Configuration

```php
<?php
return [
    'client' => [
        'domain' => [
            'base' => 'https://court.localhost', // Local dev
            // base' => 'https://your-domain.com', // Production
        ],
    ],
    'service' => [
        'payment' => [
            'paypal' => true,  // Enable PayPal
            'paypalEmail' => 'payment@your-domain.com', // PayPal email for manual payments
            'stripe' => false, // Disable Stripe (optional)
            'klarna' => false, // Disable Klarna (optional)
        ],
    ],
    'feature' => [
        'square_control' => false, // Enable Loxone door control
    ],
];
```

### Translations

German translations (primary):
- `data/res/i18n/de-DE/booking.php`
- `data/res/i18n/de-DE/square.php`
- `data/res/i18n/de-DE/backend.php`

Format: `'English text' => 'Deutsche Übersetzung'`

---

## 🚪 Loxone Door Control

### Concept

Automatic door access codes for bookings:
- 4-digit PIN generated per booking
- Sent to Loxone MiniServer via HTTP API
- Code active only during booking time slot
- Code deleted after time slot ends

### Loxone Setup

**MiniServer Configuration:**
1. Create Virtual HTTP Input in Loxone Config
2. Note API URL: `http://<miniserver-ip>/dev/sps/io/<input-name>/<code>`
3. Configure authentication (username/password or token)

**Application Configuration:**

`config/autoload/project.php`:
```php
'feature' => [
    'square_control' => true, // Enable door control
],
'square_control' => [
    'loxone' => [
        'api_url' => 'http://192.168.1.100/dev/sps/io/door-codes/',
        'username' => 'admin',
        'password' => 'your_password',
    ],
],
```

### Code Lifecycle

1. **Booking Created:**
   - `SquareControlService::generateCode()` creates 4-digit PIN
   - HTTP POST to Loxone: `<api_url>/<code>`
   - Code stored in `bs_bookings_meta` (key: `door_code`)
2. **Email Sent:**
   - Confirmation email includes door code
   - Instructions for door access
3. **Booking Time Slot Starts:**
   - Code becomes active on Loxone
4. **Booking Time Slot Ends:**
   - Cron job calls `SquareControlService::cleanupExpiredCodes()`
   - HTTP DELETE to Loxone: `<api_url>/<code>`
5. **Booking Cancelled/Deleted:**
   - Code immediately deleted from Loxone

### Cron Job

Add to system crontab:
```bash
*/5 * * * * cd /path/to/app && docker compose exec -T court php public/index.php square-control cleanup
```

Runs every 5 minutes, cleans up expired codes.

---

## 🚀 Laravel Migration

### Status: Planning Phase Complete ✅

A comprehensive migration to Laravel 11 is planned in the `dev_sh_laravel_migration` branch.

**Migration Plan v4.1:**
- 📋 [MIGRATION-PLAN.md](MIGRATION-PLAN.md) — Complete 10-phase plan with code examples
- ✅ [FEATURE-CHECKLIST.md](FEATURE-CHECKLIST.md) — 100% feature parity verification

### Target Stack

| Component | Current (ZF2) | Target (Laravel) |
|-----------|---------------|------------------|
| Framework | Zend Framework 2 | Laravel 11 |
| PHP | 8.4 | 8.4 |
| Frontend | jQuery + Bootstrap 5 | Vue 3 + PrimeVue 4 |
| CSS | Custom CSS | Tailwind CSS 3 |
| Build | Manual minification | Vite |
| Payment | Payum (all 3 gateways) | srmklive/paypal (primary), Stripe optional |
| PWA | Manual service worker | Vite PWA Plugin |
| ORM | Zend\Db\TableGateway | Eloquent |
| Auth | Zend\Authentication | Laravel Breeze |

### Key Features (100% Parity)

**All current features will be migrated:**
- ✅ Single Bookings
- ✅ **Subscription Bookings** (weekly/biweekly)
- ✅ Multi-Slot Reservations
- ✅ **Booking Range** (multi-date/time admin feature)
- ✅ Collision Detection
- ✅ PayPal Payment (PRIMARY)
- ✅ Stripe Optional (card, SEPA, iDEAL, giropay)
- ✅ Klarna Optional
- ✅ Budget System (prepaid, refunds)
- ✅ 4-Way Pricing Matrix (member/non-member/guest)
- ✅ **Backend Bills Editor** (inline editing with PrimeVue DataTable)
- ✅ Loxone Door Control
- ✅ **TinyMCE Rich Text Editor**
- ✅ **File Upload** (square images)
- ✅ Mobile-First Calendar (Day/3-Day/Week views)
- ✅ Touch Gestures (swipe navigation)
- ✅ Email Notifications (iCal, itemized bills)
- ✅ PWA Support
- ✅ Multi-Language (de-DE, en-US, fr-FR, hu-HU)

### Migration Timeline

| Phase | Description | Effort |
|-------|-------------|--------|
| 0 | **Planning** ✅ | Complete |
| 1 | Foundation (Laravel 11, Models, Layout) | 60-80h |
| 2 | PayPal Primary | 40-60h |
| 3 | Booking Flow + Subscription | 110-160h |
| 4 | Calendar Mobile-First | 60-80h |
| 5 | Backend Admin Extended | 88-122h |
| 6 | Door Control | 20-30h |
| 6a | Content Management (TinyMCE, Upload) | 18-27h |
| 7 | Stripe Optional | 40-60h |
| 8 | PWA & Polish | 20-30h |
| 9 | Deployment | 20-30h |

**Total Effort:** 456-629h (without Stripe) / 496-689h (with Stripe)

### Git Workflow

**Branch Structure:**
```
master                          # Production (ZF2, DO NOT PUSH during migration)
├── dev_sh_docker_devops        # Current ZF2 system (REFERENCE ONLY)
└── dev_sh_laravel_migration    # Laravel Migration (MAIN DEV BRANCH)
    ├── feature/phase-1-foundation
    ├── feature/phase-2-paypal
    ├── feature/phase-3-booking-flow
    └── ...
```

**Feature Branch Workflow:**
```bash
# 1. Create feature branch
git checkout dev_sh_laravel_migration
git pull
git checkout -b feature/phase-1-models

# 2. Work on feature
git add .
git commit -m "[Phase 1] Add Eloquent Models for bs_* tables"

# 3. Push and create PR
git push -u origin feature/phase-1-models

# 4. After review: Merge to dev_sh_laravel_migration
git checkout dev_sh_laravel_migration
git merge feature/phase-1-models
git push
```

**⚠️ CRITICAL:** Never push to `dev_sh_docker_devops` during migration. This branch is the production reference for the current ZF2 system.

### Why Laravel?

**Technical Debt in ZF2:**
- Zend Framework 2 is end-of-life (no security updates)
- Individual package forks in `src/Zend/` difficult to maintain
- jQuery + manual minification slows development
- No TypeScript, limited component reusability
- Payum framework outdated, multiple gateways increase complexity

**Laravel 11 Benefits:**
- Modern, actively maintained framework
- Eloquent ORM (cleaner than TableGateway)
- Breeze Auth (session-based, matches current flow)
- Inertia.js (SPA without API complexity)
- Vue 3 + TypeScript (component-based, type-safe)
- PrimeVue 4 (mobile-first components, TouchUI)
- Vite (fast builds, automatic minification)
- srmklive/paypal (Laravel-native, PayPal only)
- Vite PWA Plugin (better than manual service worker)

### Next Steps

**Phase 1 Foundation (60-80h):**
1. `composer create-project laravel/laravel . "11.*"`
2. Install Breeze + Inertia + Vue
3. Create Eloquent Models for 15 tables
4. Implement HasMeta trait (for `*_meta` tables)
5. Create Seeders (test data)
6. Build Layout Components (PrimeVue)
7. Setup i18n (German primary)
8. Configure Docker (PHP 8.3-fpm + Nginx)

**Ready to start?** See [MIGRATION-PLAN.md](MIGRATION-PLAN.md) for detailed implementation guide.

---

## 🤝 Contributing

### Commit Convention

```
[Phase X] Type: Short description

- Detail 1
- Detail 2

Closes #123

Co-Authored-By: Claude Opus 4.6 <noreply@anthropic.com>
```

**Phase Labels:**
- `[ZF2]` — Changes to current ZF2 system
- `[Phase 1]` — Laravel Foundation
- `[Phase 2]` — PayPal Integration
- etc.

**Types:** feat, fix, refactor, docs, style, test, chore

### Code Style

**PHP (Current ZF2):**
- PSR-4 autoloading
- Naming: `*Controller`, `*Manager`, `*Service`, `*Table`, `*Entity`
- Views: `module/{Module}/view/{module-lowercase}/{controller}/{action}.phtml`

**PHP (Future Laravel):**
```bash
# Laravel Pint
./vendor/bin/pint
```

**TypeScript/Vue (Future):**
```bash
# ESLint
npm run lint

# Type Check
npm run type-check
```

### Testing

**Current (ZF2):** No test runner configured (skeleton exists in `module/User/test/`)

**Future (Laravel):**
```bash
# Unit + Feature Tests
php artisan test

# With Coverage
php artisan test --coverage

# E2E Tests (Cypress)
npm run cypress:open
```

---

## 📞 Support

### Documentation

- 📖 [CLAUDE.md](CLAUDE.md) — Technical documentation for Claude Code
- 🚀 [MIGRATION-PLAN.md](MIGRATION-PLAN.md) — Laravel migration plan (10 phases)
- ✅ [FEATURE-CHECKLIST.md](FEATURE-CHECKLIST.md) — Feature parity verification
- 💾 [MEMORY.md](.claude/memory/MEMORY.md) — Project memory and lessons learned

### GitHub Repository

- **Production:** `git@github.com:zebinho20-belenus/ep3-bs.git`
- **User:** `zebinho20-belenus`
- **Main Branch:** `master`
- **Dev Branch:** `dev_sh_docker_devops` (current system)
- **Migration Branch:** `dev_sh_laravel_migration` (Laravel 11)

### Known Issues & Solutions

**Calendar Booking Overlays Duplication (Fixed Feb 2026):**
- **Problem:** Bookings appeared duplicated after any layout change
- **Cause:** `updateCalendarEvents()` recreated overlays without removing old ones
- **Fix:** Added `$("[id$='-overlay-']").remove();` at start of function
- **Files:** `public/js/controller/calendar/index.js` + `index.min.js`

**Pricing Buttons Not Showing:**
- **Problem:** PayPal/Stripe buttons missing on confirmation page
- **Cause:** `bs_squares_pricing.date_end` doesn't cover current date
- **Fix:** Update pricing rules to include current date range

**Budget Refund Missing:**
- **Problem:** Budget not refunded on booking deletion
- **Cause:** Refund logic only in cancel path, not delete path
- **Fix:** Added refund logic to both paths (Feb 2026)

**Backend Booking List Out-of-Range Abo Reservations (#47, Fixed Feb 2026):**
- **Problem:** Subscription bookings showed extra rows outside the searched date range
- **Cause:** `getByBookings()` fetches ALL reservations for matched bookings, not just in-range ones
- **Fix:** Added `array_filter()` after `getByBookings()` in `Backend\BookingController::indexAction()`

**PHP 8.4 Migration (Mar 2026):**
- Upgraded from PHP 8.1 to 8.4, Stripe SDK 6.9.0 to 7.128.0
- 317 implicit nullable parameter fixes in `src/Zend/` (`Type $param = null` → `?Type $param = null`)
- ~20 implicit nullable fixes in `vendor/` (guzzle, payum, twig, eluceo, league, php-http)
- Stripe error namespace: `\Stripe\Error\Base` → `\Stripe\Exception\ApiErrorException`
- Stripe API: `__toArray(true)` → `toArray()`
- `utf8_encode()` → `mb_convert_encoding()` in Stripe SDK
- Dynamic property fixes: `#[\AllowDynamicProperties]` on `CurlMultiHandler`, property declarations in module code
- Previous PHP 8.1 fixes (strlen/strtolower null guards, ReturnTypeWillChange) still in place

---

## 📜 License

Proprietary — Copyright © 2026

Based on [tkrebs/ep3-bs](https://github.com/tkrebs/ep3-bs) (see upstream LICENSE).

---

<div align="center">

**Current System:** Production-ready ZF2 | **Migration:** Laravel 11 in planning

Made with ❤️ for Tennis Clubs

[↑ Back to Top](#ep3-bs-court-booking-system)

</div>

# Migration Plan: ep3-bs → Laravel 11 + Inertia.js + Vue 3 + PrimeVue

## Executive Summary

Complete rewrite of the ep3-bs booking system from **Zend Framework 2 (PHP 8.1)** to **Laravel 11 (PHP 8.3) + Inertia.js + Vue 3 + TypeScript + PrimeVue 4**. The existing MySQL database schema remains intact; Laravel Eloquent models map directly to the existing `bs_*` tables. The frontend moves from server-rendered `.phtml` + jQuery + Bootstrap to a reactive Vue 3 SPA powered by PrimeVue components and Tailwind CSS.

**Estimated effort:** 460–630 hours (solo developer)

**Target stack:**

| Layer | Technology |
|-------|-----------|
| Backend | Laravel 11, PHP 8.3, Eloquent ORM |
| Frontend | Vue 3 + TypeScript, Inertia.js |
| UI Components | PrimeVue 4 (Aura theme + Tailwind) |
| CSS | Tailwind CSS 3 |
| Payments | PayPal (primary), Klarna (optional), Stripe (optional) |
| Auth | Laravel Breeze (session-based) |
| Build | Vite |
| i18n | Deutsch (default) + English |
| Docker | PHP 8.3-fpm + Nginx + MariaDB + Traefik + Mailhog |

---

## 1. Current System Analysis

### 1.1 Database (14 Tables)

| Table | Purpose | Rows (est.) |
|-------|---------|-------------|
| `bs_users` | User accounts (uid, alias, status, email, pw, login tracking) | 200+ |
| `bs_users_meta` | User key-value metadata (budget, member, phone, address...) | 1000+ |
| `bs_bookings` | Bookings (bid, uid→user, sid→square, status, billing, quantity) | 5000+ |
| `bs_bookings_bills` | Itemized bills per booking (description, price in cents, VAT) | 5000+ |
| `bs_bookings_meta` | Booking metadata (payment method, budget info, player names...) | 10000+ |
| `bs_reservations` | Time slot reservations (rid, bid→booking, date, time_start/end) | 5000+ |
| `bs_reservations_meta` | Reservation metadata | 500+ |
| `bs_squares` | Court/square definitions (sid, name, capacity, time config) | 3–10 |
| `bs_squares_meta` | Square metadata (description, rules, images — locale-aware) | 50+ |
| `bs_squares_pricing` | Dynamic pricing rules (date/day/time ranges, member flag) | 20+ |
| `bs_squares_products` | Add-on products (rentals, drinks — per square, locale-aware) | 10+ |
| `bs_squares_coupons` | Discount codes (per square, date range, % or fixed) | 5+ |
| `bs_events` | Court closures / special events (eid, sid, datetime range) | 50+ |
| `bs_events_meta` | Event metadata (name, description — locale-aware) | 100+ |
| `bs_options` | Global config key-value store (locale-aware) | 100+ |

**Key patterns:**
- Meta-table pattern: 5 entities use parallel `*_meta` tables for flexible key-value storage
- Prices stored in **cents** (integer), not decimal
- `member` column on `bs_squares_pricing` (0=non-member, 1=member)
- Nullable `sid` on pricing/products/coupons/events means "applies to ALL courts"
- MySQL scheduled event: auto-delete unpaid bookings (`directpay=true AND status_billing=pending AND >3h old`) every 15 min

### 1.2 Business Logic Hotspots

1. **Pricing engine** — matches booking date/time against pricing rules by priority, 4-way member/guest matrix
2. **Budget system** — prepaid balance in user meta, deducted on booking, refunded on cancel/delete, partial budget + gateway
3. **Payment flow** — PayPal (primary), with optional Stripe/Klarna
4. **Collision detection** — checks overlapping time slots before booking/reactivation
5. **Email notifications** — sent during booking creation, all meta must be pre-set before event fires
6. **Cleanup job** — auto-remove unpaid direct-pay bookings older than 3 hours
7. **Calendar multi-slot display** — bookings spanning multiple time blocks need visual spanning (old system used error-prone DOM overlays; Vue solves this with reactive CSS grid-row)

---

## 2. Target Architecture

### 2.1 Directory Structure

```
ep3-bs-laravel/
├── app/
│   ├── Console/Commands/
│   │   ├── CleanupUnpaidBookings.php
│   │   └── SendBookingReminders.php              # NEW: email/push X hours before booking
│   ├── Enums/
│   │   ├── BookingStatus.php                   # single, subscription, cancelled
│   │   ├── BillingStatus.php                   # pending, paid, cancelled, uncollectable
│   │   ├── UserStatus.php                      # placeholder, deleted, blocked, disabled, enabled, assist, admin
│   │   └── SquareStatus.php                    # disabled, readonly, enabled
│   ├── Events/
│   │   ├── BookingCreated.php
│   │   └── WaitlistSlotFreed.php               # NEW: fired when cancellation frees a waitlisted slot
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── CalendarController.php
│   │   │   ├── BookingController.php
│   │   │   ├── PaymentController.php
│   │   │   ├── SetupController.php
│   │   │   ├── Auth/                           # Breeze scaffolded
│   │   │   ├── User/
│   │   │   │   ├── AccountController.php
│   │   │   │   └── ProfileController.php
│   │   │   └── Backend/
│   │   │       ├── DashboardController.php
│   │   │       ├── BookingController.php
│   │   │       ├── UserController.php
│   │   │       ├── EventController.php
│   │   │       ├── ConfigController.php
│   │   │       ├── SquareConfigController.php
│   │   │       ├── ProductController.php
│   │   │       ├── CouponController.php
│   │   │       └── ExportController.php        # NEW: CSV/PDF export
│   │   ├── Middleware/
│   │   │   ├── EnsureAdmin.php
│   │   │   ├── EnsureSetupComplete.php
│   │   │   └── SetLocale.php
│   │   └── Requests/                           # Form Request validation classes
│   ├── Listeners/
│   │   ├── SendBookingNotification.php
│   │   └── NotifyWaitlist.php                  # NEW: notify waitlist users when slot freed
│   ├── Mail/
│   │   ├── BookingConfirmation.php
│   │   ├── BookingCancellation.php
│   │   ├── BookingReminder.php                 # NEW: X hours before booking
│   │   ├── WaitlistNotification.php            # NEW: "your slot is now available"
│   │   └── ActivationEmail.php
│   ├── Models/
│   │   ├── User.php                            # extends Authenticatable
│   │   ├── UserMeta.php
│   │   ├── Square.php
│   │   ├── SquareMeta.php
│   │   ├── SquarePricing.php
│   │   ├── SquareProduct.php
│   │   ├── SquareCoupon.php
│   │   ├── Booking.php
│   │   ├── BookingBill.php
│   │   ├── BookingMeta.php
│   │   ├── Reservation.php
│   │   ├── ReservationMeta.php
│   │   ├── Event.php
│   │   ├── EventMeta.php
│   │   └── Option.php
│   ├── Models/Concerns/
│   │   └── HasLocalizedMeta.php                # trait for locale-aware meta access
│   ├── Services/
│   │   ├── PricingService.php
│   │   ├── BookingService.php
│   │   ├── BudgetService.php
│   │   ├── ReservationService.php
│   │   ├── OptionService.php                   # config cache
│   │   ├── PaymentService.php                  # gateway abstraction
│   │   ├── PayPalService.php                   # primary payment provider
│   │   ├── StripeService.php                   # optional — only if configured
│   │   ├── KlarnaService.php                   # optional — only if configured
│   │   ├── RecurringBookingService.php         # NEW: weekly/biweekly series bookings
│   │   ├── WaitlistService.php                 # NEW: waitlist for occupied slots
│   │   ├── InvoiceService.php                  # NEW: PDF receipt/invoice generation
│   │   ├── ExportService.php                   # NEW: CSV/PDF data export
│   │   └── NoShowService.php                   # NEW: no-show tracking + penalties
│   └── Policies/
│       ├── BookingPolicy.php
│       └── UserPolicy.php
├── resources/
│   ├── js/
│   │   ├── app.ts                              # Vue 3 + Inertia + PrimeVue bootstrap
│   │   ├── types/
│   │   │   ├── models.d.ts                     # TypeScript interfaces
│   │   │   └── inertia.d.ts
│   │   ├── Components/
│   │   │   ├── Calendar/
│   │   │   │   ├── CalendarGrid.vue
│   │   │   │   ├── TimeSlot.vue
│   │   │   │   └── BookingPopup.vue
│   │   │   ├── Layout/
│   │   │   │   ├── AppLayout.vue
│   │   │   │   ├── BackendLayout.vue
│   │   │   │   └── Navbar.vue
│   │   │   └── Shared/
│   │   │       ├── PriceDisplay.vue
│   │   │       └── StatusBadge.vue
│   │   ├── Pages/
│   │   │   ├── Calendar/Index.vue
│   │   │   ├── Booking/
│   │   │   │   ├── Customization.vue
│   │   │   │   ├── Confirmation.vue
│   │   │   │   └── Cancellation.vue
│   │   │   ├── Auth/
│   │   │   │   ├── Login.vue
│   │   │   │   ├── Register.vue
│   │   │   │   └── ForgotPassword.vue
│   │   │   ├── User/
│   │   │   │   ├── Bookings.vue
│   │   │   │   ├── Bills.vue
│   │   │   │   ├── InvoiceDownload.vue         # NEW: PDF receipt download
│   │   │   │   └── Settings.vue
│   │   │   ├── Setup/Index.vue
│   │   │   └── Backend/
│   │   │       ├── Dashboard.vue               # NEW content: KPI charts, revenue, occupancy
│   │   │       ├── Bookings/
│   │   │       │   ├── Index.vue
│   │   │       │   ├── Create.vue
│   │   │       │   ├── Edit.vue
│   │   │       │   ├── Delete.vue
│   │   │       │   ├── Bills.vue
│   │   │       │   ├── Players.vue
│   │   │       │   └── Recurring.vue           # NEW: manage recurring series
│   │   │       ├── Users/
│   │   │       │   ├── Index.vue
│   │   │       │   ├── Create.vue
│   │   │       │   └── Edit.vue
│   │   │       ├── Events/
│   │   │       │   ├── Index.vue
│   │   │       │   └── Edit.vue
│   │   │       ├── Waitlist/
│   │   │       │   └── Index.vue               # NEW: waitlist management
│   │   │       ├── Export/
│   │   │       │   └── Index.vue               # NEW: CSV/PDF exports
│   │   │       ├── Config/
│   │   │       │   ├── Index.vue
│   │   │       │   ├── Text.vue
│   │   │       │   ├── Behaviour.vue
│   │   │       │   └── StatusColors.vue
│   │   │       └── Squares/
│   │   │           ├── Index.vue
│   │   │           ├── Edit.vue
│   │   │           ├── Pricing.vue
│   │   │           ├── Products.vue
│   │   │           ├── ProductEdit.vue
│   │   │           ├── Coupons.vue
│   │   │           └── CouponEdit.vue
│   │   └── Composables/
│   │       ├── useCalendar.ts
│   │       ├── usePricing.ts
│   │       ├── useBooking.ts
│   │       ├── useLocale.ts
│   │       └── useResponsive.ts
│   ├── css/app.css
│   └── views/
│       ├── app.blade.php
│       └── mail/
│           ├── de/
│           │   ├── booking-confirmation.blade.php
│           │   ├── booking-cancellation.blade.php
│           │   ├── booking-reminder.blade.php      # NEW
│           │   ├── waitlist-notification.blade.php  # NEW
│           │   └── activation.blade.php
│           └── en/
│               ├── booking-confirmation.blade.php
│               ├── booking-cancellation.blade.php
│               ├── booking-reminder.blade.php      # NEW
│               ├── waitlist-notification.blade.php  # NEW
│               └── activation.blade.php
├── lang/
│   ├── de/
│   │   ├── auth.php
│   │   ├── booking.php
│   │   ├── calendar.php
│   │   ├── backend.php
│   │   ├── mail.php
│   │   └── validation.php
│   └── en/
│       ├── auth.php
│       ├── booking.php
│       ├── calendar.php
│       ├── backend.php
│       ├── mail.php
│       └── validation.php
├── routes/
│   ├── web.php
│   ├── api.php                                 # webhooks only
│   └── console.php                             # scheduled commands
├── config/
│   ├── ep3bs.php                               # app-specific config
│   └── paypal.php                              # PayPal credentials
├── docker/
│   ├── Dockerfile
│   ├── nginx.conf
│   ├── supervisord.conf
│   └── php.ini
├── docker-compose.yml                          # Production
├── docker-compose.override.yml                 # Local dev (auto-loaded)
├── docker-compose.dev-server.yml               # DEV on server alongside prod
├── tailwind.config.js
├── vite.config.ts
├── tsconfig.json
└── package.json
```

### 2.2 Eloquent Models → Existing Tables

All models map to existing `bs_*` tables without schema changes:

```php
class User extends Authenticatable {
    protected $table = 'bs_users';
    protected $primaryKey = 'uid';
    public $timestamps = false;

    public function meta()     { return $this->hasMany(UserMeta::class, 'uid', 'uid'); }
    public function bookings() { return $this->hasMany(Booking::class, 'uid', 'uid'); }

    public function getMeta(string $key, $default = null): mixed {
        return $this->meta->firstWhere('key', $key)?->value ?? $default;
    }
    public function setMeta(string $key, string $value): void {
        $this->meta()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}

class Booking extends Model {
    protected $table = 'bs_bookings';
    protected $primaryKey = 'bid';
    public $timestamps = false;

    public function user()         { return $this->belongsTo(User::class, 'uid', 'uid'); }
    public function square()       { return $this->belongsTo(Square::class, 'sid', 'sid'); }
    public function bills()        { return $this->hasMany(BookingBill::class, 'bid', 'bid'); }
    public function meta()         { return $this->hasMany(BookingMeta::class, 'bid', 'bid'); }
    public function reservations() { return $this->hasMany(Reservation::class, 'bid', 'bid'); }
}

class Square extends Model {
    use HasLocalizedMeta;
    protected $table = 'bs_squares';
    protected $primaryKey = 'sid';
    public $timestamps = false;

    public function meta()         { return $this->hasMany(SquareMeta::class, 'sid', 'sid'); }
    public function pricingRules() { return $this->hasMany(SquarePricing::class, 'sid', 'sid'); }
    public function products()     { return $this->hasMany(SquareProduct::class, 'sid', 'sid'); }
    public function coupons()      { return $this->hasMany(SquareCoupon::class, 'sid', 'sid'); }
    public function events()       { return $this->hasMany(Event::class, 'sid', 'sid'); }
}
```

---

## 3. Service Layer

### 3.1 PricingService — Member/Guest Pricing Matrix

```
┌─────────────────┬────────────────────────┬──────────────────────┐
│                 │ Without Guest (gp=0)    │ With Guest (gp=1)    │
├─────────────────┼────────────────────────┼──────────────────────┤
│ Member (m=1)    │ Member price (€0)       │ 50% of non-member    │
│ Non-member (m=0)│ Full non-member price   │ Full non-member      │
└─────────────────┴────────────────────────┴──────────────────────┘
```

Only **members** get the 50% guest discount. Non-members with guest pay full price.

```php
class PricingService
{
    /**
     * CRITICAL: Pricing rules in bs_squares_pricing.date_end MUST cover the booking date.
     * If no rule matches → $total = 0 → payment buttons hidden → booking appears free.
     */
    public function calculatePrice(
        Square $square, Carbon $dateStart, Carbon $dateEnd,
        string $timeStart, string $timeEnd,
        bool $isMember, bool $isGuest = false, int $quantity = 1
    ): array {
        // 1. Find matching pricing rules by date/day/time range, sorted by priority DESC
        // 2. For each time block:
        //    - member && !guest  → member price (member=1 rule)
        //    - !member           → non-member price (member=0 rule)
        //    - member && guest   → non-member price / 2
        //    - !member && guest  → non-member price (no discount)
        // 3. Calculate: price_per_unit × (duration / time_block) × quantity
        // Returns: {total: int (cents), bills: array, member_total: int, nonmember_total: int}
    }

    /** Budget NOT allowed when: guest checked AND non-member */
    public function canUseBudget(User $user, bool $isGuest): bool {
        $isMember = (bool) $user->getMeta('member', false);
        return (int) $user->getMeta('budget', 0) > 0 && !($isGuest && !$isMember);
    }
}
```

### 3.2 BookingService — Email Timing Constraint

**CRITICAL:** The confirmation email fires *during* `create()` via the `BookingCreated` event. All payment/budget metadata **must** be in `$meta` before calling this method.

```php
class BookingService
{
    public function create(
        User $user, Square $square,
        array $reservationData, array $billData,
        array $meta = []  // ALL meta must be passed here (payment method, budget info, player names)
    ): Booking {
        return DB::transaction(function () use ($user, $square, $reservationData, $billData, $meta) {
            $booking = Booking::create([/* ... */]);

            foreach ($meta as $key => $value) {
                $booking->meta()->create(['key' => $key, 'value' => $value]);
            }
            foreach ($billData as $bill) {
                $booking->bills()->create($bill);
            }
            foreach ($reservationData as $res) {
                $booking->reservations()->create($res);
            }

            BookingCreated::dispatch($booking); // Email fires HERE
            return $booking;
        });
    }
}
```

### 3.3 BudgetService

```php
class BudgetService
{
    public function getBalance(User $user): int;                              // cents
    public function deduct(User $user, int $amount, Booking $booking): void;
    public function refund(User $user, Booking $booking): void;              // restores to budget
    // Refund logic runs on BOTH cancel AND delete paths
    // Checks: status_billing == 'paid' && refunded != 'true'
}
```

### 3.4 OptionService (Config Cache)

```php
class OptionService
{
    public function get(string $key, ?string $locale = null, $default = null): mixed;
    public function set(string $key, string $value, ?string $locale = null): void;
    public function all(?string $locale = null): array;
    // Cached per locale, invalidated on set()
}
```

### 3.5 RecurringBookingService — Serienbuchungen

Weekly/biweekly recurring bookings are essential for tennis clubs. Members reserve a fixed slot (e.g. "every Tuesday 18:00–19:00") for a season.

```php
class RecurringBookingService
{
    /**
     * Create a series of bookings for a recurring slot.
     * Each occurrence = separate Booking + Reservation in DB (existing schema, no changes needed).
     * All linked via booking meta: 'recurring_group_id' => UUID.
     *
     * @param string $frequency  'weekly' | 'biweekly'
     * @param Carbon $startDate  First occurrence
     * @param Carbon $endDate    Series end (e.g. end of season)
     */
    public function createSeries(
        User $user, Square $square,
        string $timeStart, string $timeEnd,
        string $frequency, Carbon $startDate, Carbon $endDate,
        array $meta = []
    ): array {
        $dates = $this->generateOccurrences($frequency, $startDate, $endDate);
        $bookings = [];
        $conflicts = [];
        $groupId = Str::uuid()->toString();

        DB::transaction(function () use (&$bookings, &$conflicts, $dates, $user, $square, $timeStart, $timeEnd, $groupId, $meta) {
            foreach ($dates as $date) {
                // Check collision for each occurrence
                if ($this->reservation->hasCollision($square->sid, $date, $timeStart, $timeEnd)) {
                    $conflicts[] = $date->format('d.m.Y');
                    continue;
                }

                $bookingMeta = array_merge($meta, [
                    'recurring_group_id' => $groupId,
                    'recurring_frequency' => $frequency,
                ]);

                $bookings[] = $this->bookingService->create($user, $square, [
                    ['date' => $date, 'time_start' => $timeStart, 'time_end' => $timeEnd]
                ], $this->pricing->calculateBills($square, $date, $timeStart, $timeEnd, $user), $bookingMeta);
            }
        });

        return ['bookings' => $bookings, 'conflicts' => $conflicts, 'group_id' => $groupId];
    }

    /** Cancel all future occurrences in a series */
    public function cancelSeries(string $groupId, ?Carbon $fromDate = null): int { /* ... */ }

    /** List all occurrences for a series */
    public function getSeriesBookings(string $groupId): Collection { /* ... */ }
}
```

**Payment for series:** Admin can choose per-occurrence payment (each booking triggers PayPal) or total amount upfront (sum of all occurrences → single PayPal order).

### 3.6 WaitlistService — Warteliste

When a slot is occupied, users can register interest and get notified automatically when it becomes available.

```php
class WaitlistService
{
    /**
     * Waitlist entries stored in booking meta on a "virtual" waitlist booking
     * with status='waitlist'. No schema changes needed.
     *
     * Alternative: dedicated lightweight table (recommended for scale):
     *   bs_waitlist (id, uid, sid, date, time_start, time_end, created_at, notified_at)
     */
    public function join(User $user, int $sid, Carbon $date, string $timeStart, string $timeEnd): void
    {
        // Prevent duplicate entries
        // Store waitlist entry
        // User gets confirmation toast
    }

    public function leave(User $user, int $waitlistId): void { /* ... */ }

    /**
     * Called by BookingCancelled event listener.
     * Finds all waitlist entries matching the freed slot,
     * sends notification email with direct booking link.
     * Auto-expires after 2 hours if not booked.
     */
    public function notifyForFreedSlot(Reservation $freedReservation): void
    {
        $entries = $this->getEntriesForSlot(
            $freedReservation->sid,
            $freedReservation->date,
            $freedReservation->time_start,
            $freedReservation->time_end
        );

        foreach ($entries as $entry) {
            Mail::to($entry->user)->send(new WaitlistNotification($entry, $freedReservation));
            $entry->update(['notified_at' => now()]);
        }
    }

    /** Admin view: all active waitlist entries */
    public function getAllActive(): Collection { /* ... */ }
}
```

### 3.7 InvoiceService — PDF Receipts

Users need downloadable PDF receipts (Quittung/Rechnung) for expense claims or tax purposes.

```php
class InvoiceService
{
    /**
     * Generate a PDF receipt for a paid booking.
     * Uses barryvdh/laravel-dompdf (or similar).
     * Includes: facility address, booking details, itemized bill, VAT breakdown, payment method.
     */
    public function generatePdf(Booking $booking): string  // returns file path
    {
        $data = [
            'booking'  => $booking->load('bills', 'reservations', 'user', 'square'),
            'facility' => $this->options->get('facility_name'),
            'address'  => $this->options->get('facility_address'),
            'tax_id'   => $this->options->get('facility_tax_id'),
            'number'   => sprintf('INV-%d-%04d', $booking->created_at->year, $booking->bid),
        ];

        $pdf = Pdf::loadView("mail.{$booking->user->locale}.invoice", $data);
        return $pdf->output();
    }

    /** Stream PDF download to browser */
    public function download(Booking $booking): Response
    {
        return response($this->generatePdf($booking), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"Rechnung-{$booking->bid}.pdf\"",
        ]);
    }
}
```

### 3.8 ExportService — CSV/PDF Reports

Backend data export for accounting, club administration, and reporting.

```php
class ExportService
{
    /**
     * Supported exports:
     * - bookings: date range, optional filters (court, user, status)
     * - users: all active users with member status, budget, booking count
     * - revenue: monthly revenue breakdown by court, member/non-member
     * - occupancy: slot utilization by court, day, time range
     */
    public function exportBookingsCsv(Carbon $from, Carbon $to, array $filters = []): StreamedResponse;
    public function exportUsersCsv(): StreamedResponse;
    public function exportRevenueCsv(Carbon $from, Carbon $to): StreamedResponse;

    /** PDF report with charts (revenue summary, occupancy) — uses DomPDF */
    public function exportRevenueReportPdf(Carbon $from, Carbon $to): string;
}
```

### 3.9 NoShowService — No-Show Tracking

Admin can mark bookings as "no-show" after the slot time has passed. After a configurable number of no-shows, the user receives a warning or gets blocked.

```php
class NoShowService
{
    /**
     * Mark a booking as no-show (admin action).
     * Stored in booking meta: 'no_show' => true.
     */
    public function markNoShow(Booking $booking): void
    {
        $booking->setMeta('no_show', 'true');
        $count = $this->getNoShowCount($booking->user);

        $threshold = (int) $this->options->get('no_show_warning_threshold', null, 3);
        $blockThreshold = (int) $this->options->get('no_show_block_threshold', null, 5);

        if ($count >= $blockThreshold) {
            $booking->user->update(['status' => UserStatus::Blocked->value]);
            // Optional: notify admin
        } elseif ($count >= $threshold) {
            // Send warning email to user
            Mail::to($booking->user)->send(new NoShowWarning($booking->user, $count, $blockThreshold));
        }
    }

    public function getNoShowCount(User $user, ?int $monthsBack = 12): int { /* ... */ }
    public function clearNoShow(Booking $booking): void { /* ... */ }
}
```

### 3.10 Booking Reminders (Scheduled Command)

Automated email reminders X hours before a booking. Configurable per-user and globally.

```php
// app/Console/Commands/SendBookingReminders.php
class SendBookingReminders extends Command
{
    protected $signature = 'bookings:send-reminders';
    protected $description = 'Send email reminders for upcoming bookings';

    public function handle(): void
    {
        $hoursAhead = (int) app(OptionService::class)->get('reminder_hours_before', null, 24);

        $upcomingReservations = Reservation::query()
            ->whereHas('booking', fn ($q) => $q->where('status', '!=', 'cancelled'))
            ->where('date', Carbon::tomorrow()->toDateString())
            ->whereDoesntHave('booking.meta', fn ($q) =>
                $q->where('key', 'reminder_sent')->where('value', 'true'))
            ->with('booking.user', 'booking.square')
            ->get();

        foreach ($upcomingReservations as $reservation) {
            $user = $reservation->booking->user;

            // Skip if user opted out
            if ($user->getMeta('reminders_enabled', 'true') === 'false') continue;

            Mail::to($user)->send(new BookingReminder($reservation));
            $reservation->booking->setMeta('reminder_sent', 'true');
        }

        $this->info("Sent {$upcomingReservations->count()} reminders.");
    }
}

// routes/console.php
Schedule::command('bookings:send-reminders')->dailyAt('18:00');
Schedule::command('bookings:cleanup-unpaid')->everyFifteenMinutes();
```

---

## 4. Frontend: PrimeVue Component Architecture

### 4.1 App Bootstrap

```typescript
// resources/js/app.ts
import { createApp, h } from 'vue'
import { createInertiaApp } from '@inertiajs/vue3'
import { i18nVue } from 'laravel-vue-i18n'
import PrimeVue from 'primevue/config'
import Aura from '@primevue/themes/aura'
import ToastService from 'primevue/toastservice'
import ConfirmationService from 'primevue/confirmationservice'

createInertiaApp({
    resolve: name => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true })
        return pages[`./Pages/${name}.vue`]
    },
    setup({ el, App, props, plugin }) {
        const locale = props.initialPage.props.locale ?? 'de'

        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(i18nVue, {
                lang: locale,
                resolve: async (lang: string) => {
                    const langs = import.meta.glob('../../lang/*.json')
                    return await langs[`../../lang/${lang}.json`]()
                }
            })
            .use(PrimeVue, {
                theme: { preset: Aura, options: { darkModeSelector: '.dark' } }
            })
            .use(ToastService)
            .use(ConfirmationService)
            .mount(el)
    },
})
```

### 4.2 PrimeVue Component Registry

Alle UI-Komponenten im Projekt und wo sie eingesetzt werden:

| PrimeVue Component | Einsatz | Props / Konfiguration |
|--------------------|---------|-----------------------|
| **Layout** | | |
| `Menubar` | Hauptnavigation (AppLayout) | Responsive hamburger menu built-in |
| `PanelMenu` | Backend-Sidebar (Desktop) | Collapsible Sections mit Router-Links |
| `Drawer` | Backend-Sidebar (Mobile, < 1024px) | `position="left"`, aus Hamburger-Button |
| `Breadcrumb` | Backend Seiten | `:model="breadcrumbs"` |
| **Daten & Tabellen** | | |
| `DataTable` | Backend Listen (Bookings, Users, Events, Pricing, Bills, Players, Coupons, Products) | `sortMode="multiple"`, `filterDisplay="row"`, `:paginator="true"`, `:scrollable="true"` |
| `Column` | Spalten in DataTables | `sortable`, `filterElement` slot, `frozen`, `v-if` für responsive Hiding |
| `DataTable` (cell edit) | Bills-Editor, Pricing-Editor | `editMode="cell"`, `@cell-edit-complete` |
| **Formulare** | | |
| `InputText` | Name, Email, Codes, Freitext | |
| `InputNumber` | Preise (EUR), Mengen, MwSt. | `mode="currency" currency="EUR" locale="de-DE"` |
| `Select` | Status, Court-Auswahl, Zeitfelder (07:00–22:00) | `:options`, `optionLabel`, `optionValue` |
| `DatePicker` | Datum (Buchung, Events, Pricing-Regeln) | `dateFormat="dd.mm.yy"`, `:touchUI="isMobile"`, `firstDayOfWeek: 1` |
| `Checkbox` | Member-Flag, Feature-Toggles | `:binary="true"` |
| `AutoComplete` | User-Suche (Backend Booking/User) | `@complete` → lazy API search |
| `Editor` | Square-Info, Config Text/Help (Rich Text) | Quill-basiert, keine extra Plugins |
| `FileUpload` | Bild-Upload (Square-Info) | |
| `Textarea` | Notizen, Beschreibungen | |
| `Password` | Passwort-Felder (Auth) | `toggleMask` |
| **Feedback & Dialoge** | | |
| `Dialog` | Booking-Popup (Kalender), Detail-Ansichten | `modal`, `:breakpoints="{ '640px': '100vw' }"` |
| `ConfirmDialog` | Löschen, Stornieren (programmatisch) | via `useConfirm()` Composable |
| `Toast` | Erfolgs-/Fehlermeldungen | `position="top-right"`, via `useToast()` |
| `Tag` | Status-Badges | Booking: `success`/`warn`/`danger`, Billing: `success`/`warn`/`danger`/`info` |
| `Message` | Inline-Hinweise, Warnungen | `severity="warn"` für Pricing-Ablauf-Warnung |
| **Navigation & Wizard** | | |
| `Tabs` + `TabPanels` | Config-Seiten, Square-Bearbeitung | |
| `Stepper` | Setup-Wizard (4 Schritte) | |
| `Button` | Alle Aktionen | `severity`, `outlined`, `text`, `rounded`, `icon`, `v-tooltip` |
| `Tooltip` | Icon-only Buttons (Backend Aktionen) | `v-tooltip.top` Direktive |

### 4.3 Layout System

```vue
<!-- Components/Layout/AppLayout.vue -->
<script setup lang="ts">
import Menubar from 'primevue/menubar'
import Toast from 'primevue/toast'
import ConfirmDialog from 'primevue/confirmdialog'

withDefaults(defineProps<{
    width?: 'default' | 'wide' | 'full'
}>(), { width: 'default' })

const widthClass = computed(() => ({
    default: 'mx-auto max-w-4xl px-4',
    wide:    'mx-auto max-w-7xl px-4',
    full:    'w-full',
})[props.width])
</script>

<template>
    <div class="min-h-screen flex flex-col">
        <Navbar />
        <main :class="['flex-1 py-6', widthClass]">
            <slot />
        </main>
        <Footer />
        <Toast position="top-right" />
        <ConfirmDialog />
    </div>
</template>
```

```vue
<!-- Components/Layout/BackendLayout.vue -->
<script setup lang="ts">
import PanelMenu from 'primevue/panelmenu'
import Breadcrumb from 'primevue/breadcrumb'
import Drawer from 'primevue/drawer'
import { useResponsive } from '@/Composables/useResponsive'

const { isMobile } = useResponsive()
const sidebarVisible = ref(!isMobile.value)
</script>

<template>
    <AppLayout width="wide">
        <div class="flex gap-6">
            <!-- Sidebar: PanelMenu on desktop, Drawer on mobile -->
            <Drawer v-if="isMobile" v-model:visible="sidebarVisible" header="Navigation">
                <PanelMenu :model="menuItems" class="w-64" />
            </Drawer>
            <aside v-else class="w-64 shrink-0">
                <PanelMenu :model="menuItems" />
            </aside>

            <!-- Content -->
            <div class="flex-1 min-w-0">
                <Breadcrumb :model="breadcrumbs" class="mb-4" />
                <slot />
            </div>
        </div>
    </AppLayout>
</template>
```

### 4.4 Backend Form Pattern

All backend forms use this Tailwind grid pattern with PrimeVue `Card` as section containers:

```vue
<!-- Example: Backend Booking Edit — 2×2 grid with 4 PrimeVue Card sections -->
<template>
    <BackendLayout>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Section 1: Booking Details -->
            <Card>
                <template #title>{{ $t('backend.booking_details') }}</template>
                <template #content>
                    <div class="flex flex-col gap-4">
                        <div class="flex flex-col gap-1">
                            <label>{{ $t('booking.court') }}</label>
                            <Select v-model="form.sid" :options="squares"
                                    optionLabel="name" optionValue="sid" />
                        </div>
                        <div class="flex flex-col gap-1">
                            <label>{{ $t('booking.date') }}</label>
                            <DatePicker v-model="form.date" dateFormat="dd.mm.yy"
                                        :touchUI="isMobile" />
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="flex flex-col gap-1">
                                <label>{{ $t('booking.time_from') }}</label>
                                <Select v-model="form.time_start" :options="timeOptions" />
                            </div>
                            <div class="flex flex-col gap-1">
                                <label>{{ $t('booking.time_to') }}</label>
                                <Select v-model="form.time_end" :options="timeOptions" />
                            </div>
                        </div>
                    </div>
                </template>
            </Card>

            <!-- Section 2: User & Quantity -->
            <Card>
                <template #title>{{ $t('backend.user_quantity') }}</template>
                <template #content>
                    <div class="flex flex-col gap-4">
                        <div class="flex flex-col gap-1">
                            <label>{{ $t('booking.user') }}</label>
                            <AutoComplete v-model="form.user" :suggestions="userSuggestions"
                                          @complete="searchUsers" field="alias" />
                        </div>
                        <div class="flex flex-col gap-1">
                            <label>{{ $t('booking.quantity') }}</label>
                            <InputNumber v-model="form.quantity" :min="1" />
                        </div>
                    </div>
                </template>
            </Card>

            <!-- ... Section 3 + 4 ... -->
        </div>

        <!-- Action buttons -->
        <div class="flex flex-col sm:flex-row gap-3 justify-center mt-6">
            <Button :label="$t('booking.save')" severity="primary"
                    @click="form.post(route('backend.bookings.update', booking.bid))" />
            <Button :label="$t('booking.back')" outlined
                    @click="router.visit(route('backend.bookings.index'))" />
        </div>
    </BackendLayout>
</template>
```

**Time dropdown pattern** (all backend time fields):
```typescript
// Composables/useTimeOptions.ts
export function useTimeOptions(start = 7, end = 22) {
    return Array.from({ length: end - start + 1 }, (_, i) => {
        const h = (start + i).toString().padStart(2, '0')
        return { label: `${h}:00`, value: `${h}:00:00` }
    })
}
```

### 4.5 Backend Booking List (most complex DataTable)

```vue
<!-- Pages/Backend/Bookings/Index.vue -->
<script setup lang="ts">
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import { useResponsive } from '@/Composables/useResponsive'
import { useConfirm } from 'primevue/useconfirm'

const { width } = useResponsive()
const confirm = useConfirm()

// Progressive column visibility based on breakpoints
const showMember        = computed(() => width.value >= 1536)
const showBillingStatus = computed(() => width.value >= 1536)
const showDay           = computed(() => width.value >= 1024)
const showNotes         = computed(() => width.value >= 1024)
const showBudget        = computed(() => width.value >= 1024)
const showCourt         = computed(() => width.value >= 768)
const showNr            = computed(() => width.value >= 512)
const showPrice         = computed(() => width.value >= 512)

// Row action state machine
function getActions(booking: BookingRow) {
    const actions = [{ icon: 'pi pi-pencil', action: 'edit' }]

    if (booking.status !== 'cancelled') {
        actions.push({ icon: 'pi pi-times', action: 'cancel', severity: 'danger' })
    } else {
        // Cancelled: check if slot is free for reactivation
        if (booking.slotFree) {
            actions.push({ icon: 'pi pi-refresh', action: 'reactivate' })
        }
        // Delete only for admin (not assist)
        if (isAdmin.value) {
            actions.push({ icon: 'pi pi-trash', action: 'delete', severity: 'danger' })
        }
    }
    return actions
}
</script>

<template>
    <BackendLayout>
        <DataTable :value="bookings.data" :paginator="true" :rows="20"
                   v-model:filters="filters" filterDisplay="row"
                   sortMode="multiple" removableSort
                   :scrollable="true" scrollHeight="flex">

            <template #header>
                <div class="flex justify-between items-center flex-wrap gap-4">
                    <h2 class="text-xl font-semibold">{{ $t('backend.bookings') }}</h2>
                    <InputText v-model="filters.global.value"
                               :placeholder="$t('backend.search')" />
                </div>
            </template>

            <Column v-if="showNr" field="bid" header="#" sortable style="width: 80px" />
            <Column field="status" :header="$t('booking.status')" style="width: 100px">
                <template #body="{ data }">
                    <Tag :value="data.status" :severity="statusSeverity(data.status)" />
                </template>
            </Column>
            <Column v-if="showBillingStatus" field="billing_status"
                    :header="$t('booking.billing')" style="width: 100px">
                <template #body="{ data }">
                    <Tag :value="data.billing_status"
                         :severity="billingSeverity(data.billing_status)" />
                </template>
            </Column>
            <Column field="user.alias" :header="$t('booking.user')" sortable />
            <Column v-if="showCourt" field="square.name"
                    :header="$t('booking.court')" sortable />
            <Column field="date_start" :header="$t('booking.date')" sortable />
            <Column v-if="showDay" field="day_name" :header="$t('booking.day')" />
            <Column field="time_range" :header="$t('booking.time')" />
            <Column v-if="showPrice" field="total" :header="$t('booking.price')" sortable>
                <template #body="{ data }">
                    <PriceDisplay :cents="data.total" />
                </template>
            </Column>
            <Column v-if="showBudget" field="budget_info" header="Budget" />
            <Column v-if="showMember" field="is_member" :header="$t('booking.member')"
                    style="width: 80px">
                <template #body="{ data }">
                    <i v-if="data.is_member" class="pi pi-check text-green-600" />
                </template>
            </Column>
            <Column v-if="showNotes" field="notes" :header="$t('backend.notes')" />
            <Column :header="$t('backend.actions')" style="width: 120px" frozen
                    alignFrozen="right">
                <template #body="{ data }">
                    <div class="flex gap-1">
                        <Button v-for="action in getActions(data)" :key="action.action"
                                :icon="action.icon" text rounded size="small"
                                :severity="action.severity ?? 'secondary'"
                                v-tooltip.top="$t(`backend.action_${action.action}`)"
                                @click="handleAction(action.action, data)" />
                    </div>
                </template>
            </Column>
        </DataTable>
    </BackendLayout>
</template>
```

### 4.6 Backend User Edit

```vue
<!-- Pages/Backend/Users/Edit.vue — 2-column layout -->
<template>
    <BackendLayout>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Account section -->
            <Card>
                <template #title>{{ $t('backend.account') }}</template>
                <template #content>
                    <div class="flex flex-col gap-4">
                        <div class="flex flex-col gap-1">
                            <label>{{ $t('backend.status') }}</label>
                            <Select v-model="form.status" :options="statusOptions"
                                    optionLabel="label" optionValue="value" />
                        </div>
                        <div class="flex flex-col gap-1">
                            <label>Email</label>
                            <InputText v-model="form.email" type="email" />
                        </div>
                        <div class="flex items-center gap-2">
                            <Checkbox v-model="form.member" :binary="true" inputId="member" />
                            <label for="member">{{ $t('booking.member') }}</label>
                        </div>
                        <div class="flex flex-col gap-1">
                            <label>{{ $t('booking.budget') }}</label>
                            <InputNumber v-model="form.budget" mode="currency"
                                         currency="EUR" locale="de-DE" />
                        </div>
                    </div>
                </template>
            </Card>

            <!-- Personal data section -->
            <Card>
                <template #title>{{ $t('backend.personal_data') }}</template>
                <template #content>
                    <!-- name, phone, address fields -->
                </template>
            </Card>
        </div>

        <div class="flex flex-col sm:flex-row gap-3 justify-center mt-6">
            <Button :label="$t('booking.save')" severity="primary" @click="submit" />
            <Button :label="$t('backend.view_bookings')" outlined icon="pi pi-list"
                    @click="router.visit(route('backend.bookings.index', { user: user.uid }))" />
            <Button :label="$t('booking.back')" outlined
                    @click="router.visit(route('backend.users.index'))" />
        </div>
    </BackendLayout>
</template>
```

### 4.7 Bills & Players Editor (DataTable inline editing)

```vue
<!-- Pages/Backend/Bookings/Bills.vue -->
<template>
    <BackendLayout>
        <DataTable :value="bills" editMode="cell" @cell-edit-complete="onCellEdit">
            <Column field="description" :header="$t('booking.description')">
                <template #editor="{ data, field }">
                    <InputText v-model="data[field]" autofocus class="w-full" />
                </template>
            </Column>
            <Column field="price" :header="$t('booking.price')">
                <template #body="{ data }">
                    <PriceDisplay :cents="data.price" />
                </template>
                <template #editor="{ data, field }">
                    <InputNumber v-model="data[field]" mode="currency"
                                 currency="EUR" locale="de-DE" />
                </template>
            </Column>
            <Column field="rate" header="MwSt.">
                <template #body="{ data }">{{ data.rate }}%</template>
                <template #editor="{ data, field }">
                    <InputNumber v-model="data[field]" suffix="%" :min="0" :max="100" />
                </template>
            </Column>
            <Column style="width: 60px">
                <template #body="{ data }">
                    <Button icon="pi pi-trash" text rounded severity="danger"
                            @click="removeBill(data)" />
                </template>
            </Column>
        </DataTable>
        <Button :label="$t('backend.add_line')" icon="pi pi-plus" text class="mt-2"
                @click="addBill" />
    </BackendLayout>
</template>
```

### 4.8 Calendar — Mobile-First with 3 View Modes

**Multi-Slot Bookings:** In the old system, bookings spanning multiple time slots used DOM overlay elements (`-overlay-` IDs) that were re-created on every resize — causing a known duplication bug. In Vue, this is solved reactively: each booking gets a computed `gridRow` span based on its duration, no overlays needed.

```vue
<!-- Components/Calendar/CalendarGrid.vue -->
<script setup lang="ts">
import { useSwipe } from '@vueuse/core'
import DatePicker from 'primevue/datepicker'
import Tabs from 'primevue/tabs'
import TabList from 'primevue/tablist'
import Tab from 'primevue/tab'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import Tag from 'primevue/tag'
import { useResponsive } from '@/Composables/useResponsive'

const props = defineProps<{
    squares: Square[]
    reservations: Reservation[]
    events: Event[]
}>()

const { isMobile, isTablet } = useResponsive()

const viewMode = computed(() => {
    if (isMobile.value) return 'day'       // < 640px: 1 day, vertical timeline
    if (isTablet.value) return 'three'     // 640–1024px: 3 days
    return 'week'                           // > 1024px: full week, all courts
})

// Swipe navigation on mobile
const calendarRef = ref<HTMLElement>()
const { direction } = useSwipe(calendarRef)
watch(direction, (dir) => {
    if (dir === 'left') navigateForward()
    if (dir === 'right') navigateBack()
})

// Multi-slot booking spanning: computed grid-row per booking
// Replaces the old overlay-based approach (no DOM manipulation needed)
function bookingGridRow(reservation: Reservation): string {
    const startSlot = timeToSlotIndex(reservation.time_start)
    const endSlot = timeToSlotIndex(reservation.time_end)
    return `${startSlot + 1} / ${endSlot + 1}` // CSS grid-row: start / end
}

function bookingStyle(reservation: Reservation) {
    return {
        gridRow: bookingGridRow(reservation),
        gridColumn: squareColumnIndex(reservation.sid) + 1,
    }
}
</script>

<template>
    <div ref="calendarRef" class="touch-pan-y">
        <!-- Mobile: Day view with court tabs -->
        <template v-if="viewMode === 'day'">
            <div class="flex items-center justify-between p-3 bg-white sticky top-0 z-10">
                <Button icon="pi pi-chevron-left" text rounded @click="navigateBack" />
                <div class="text-center">
                    <h2 class="text-lg font-semibold">{{ formatDate(currentDay) }}</h2>
                    <DatePicker v-model="currentDay" dateFormat="dd.mm.yy" :touchUI="true"
                                :inline="false" class="mt-1">
                        <template #trigger>
                            <Button icon="pi pi-calendar" text size="small" />
                        </template>
                    </DatePicker>
                </div>
                <Button icon="pi pi-chevron-right" text rounded @click="navigateForward" />
            </div>

            <Tabs v-if="squares.length > 1" v-model:value="activeSquareIndex">
                <TabList>
                    <Tab v-for="(sq, i) in squares" :key="sq.sid" :value="i">
                        {{ sq.name }}
                    </Tab>
                </TabList>
            </Tabs>

            <!-- Vertical timeline with CSS Grid for multi-slot spanning -->
            <div class="grid grid-cols-[60px_1fr] relative">
                <!-- Time labels -->
                <template v-for="slot in timeSlots" :key="slot.time">
                    <div class="text-xs text-gray-500 py-3 text-right pr-2 border-r">
                        {{ slot.time }}
                    </div>
                    <TimeSlot :slot="slot" :square="activeSquare"
                              class="min-h-[48px] touch-manipulation"
                              @click="openBooking(activeSquare, currentDay, slot.time)" />
                </template>

                <!-- Bookings as absolutely-positioned spans (reactive, no overlay duplication) -->
                <div v-for="res in dayReservations" :key="res.rid"
                     :style="{ gridRow: bookingGridRow(res), gridColumn: 2 }"
                     class="bg-primary-100 border-l-4 border-primary-500 rounded px-2 py-1
                            text-sm cursor-pointer hover:bg-primary-200 transition"
                     @click="openBookingDetail(res)">
                    <span class="font-medium">{{ res.user_alias }}</span>
                    <span class="text-xs text-gray-500 block">
                        {{ res.time_start }} – {{ res.time_end }}
                    </span>
                </div>

                <!-- Events/closures as blocked spans -->
                <div v-for="evt in dayEvents" :key="evt.eid"
                     :style="{ gridRow: bookingGridRow(evt), gridColumn: 2 }"
                     class="bg-gray-200 border-l-4 border-gray-500 rounded px-2 py-1 text-sm">
                    <Tag severity="secondary" :value="evt.name ?? $t('calendar.closed')" />
                </div>
            </div>
        </template>

        <!-- Tablet: 3-day view -->
        <template v-else-if="viewMode === 'three'">
            <div class="flex items-center justify-between p-3 bg-white sticky top-0 z-10">
                <Button icon="pi pi-chevron-left" text rounded @click="navigateDays(-3)" />
                <h2 class="text-base font-semibold">
                    {{ formatDate(days[0]) }} – {{ formatDate(days[2]) }}
                </h2>
                <Button icon="pi pi-chevron-right" text rounded @click="navigateDays(3)" />
            </div>

            <div class="grid grid-cols-[60px_repeat(3,1fr)]">
                <!-- Header: 3 day columns -->
                <div />
                <div v-for="day in days" :key="day"
                     class="text-center py-2 border-b font-medium text-sm">
                    {{ formatDayShort(day) }}
                </div>

                <!-- Time rows -->
                <template v-for="slot in timeSlots" :key="slot.time">
                    <div class="text-xs text-gray-500 py-2 text-right pr-2 border-r">
                        {{ slot.time }}
                    </div>
                    <TimeSlot v-for="day in days" :key="`${day}-${slot.time}`"
                              :slot="slot" :day="day" :square="activeSquare"
                              class="min-h-[40px] touch-manipulation border-r border-b"
                              @click="openBooking(activeSquare, day, slot.time)" />
                </template>
            </div>
        </template>

        <!-- Desktop: Full week, all courts side by side -->
        <template v-else>
            <div class="flex items-center justify-between p-3 bg-white">
                <Button icon="pi pi-chevron-left" text @click="navigateWeek(-1)" />
                <div class="flex items-center gap-4">
                    <h2 class="text-lg font-semibold">KW {{ weekNumber }}</h2>
                    <DatePicker v-model="currentDay" dateFormat="dd.mm.yy" :inline="false" />
                </div>
                <Button icon="pi pi-chevron-right" text @click="navigateWeek(1)" />
            </div>

            <!-- Sub-tabs for courts if > 3 courts, otherwise all columns -->
            <div class="grid overflow-x-auto"
                 :style="{ gridTemplateColumns: `60px repeat(${7 * visibleSquares.length}, minmax(100px, 1fr))` }">
                <!-- ... 7 days × N courts grid with booking spans -->
            </div>
        </template>
    </div>

    <!-- Booking dialog: fullscreen on mobile, 75vw tablet, fixed on desktop -->
    <Dialog v-model:visible="showBooking" modal :header="$t('booking.title')"
            :breakpoints="{ '640px': '100vw', '960px': '75vw' }"
            :style="{ width: '500px' }" :dismissableMask="true">
        <BookingPopup v-if="selectedSlot" :slot="selectedSlot"
                      @booked="onBooked" @cancelled="showBooking = false" />
    </Dialog>

    <!-- Booking detail popup (click on existing reservation) -->
    <Dialog v-model:visible="showDetail" modal :header="$t('booking.details')"
            :breakpoints="{ '640px': '100vw' }" :style="{ width: '400px' }">
        <div v-if="selectedReservation" class="flex flex-col gap-3">
            <div class="flex justify-between">
                <span class="text-gray-500">{{ $t('booking.user') }}</span>
                <span class="font-medium">{{ selectedReservation.user_alias }}</span>
            </div>
            <div class="flex justify-between">
                <span class="text-gray-500">{{ $t('booking.time') }}</span>
                <span>{{ selectedReservation.time_start }} – {{ selectedReservation.time_end }}</span>
            </div>
            <div class="flex justify-between">
                <span class="text-gray-500">{{ $t('booking.court') }}</span>
                <span>{{ selectedReservation.square_name }}</span>
            </div>
            <Button v-if="canCancel(selectedReservation)" :label="$t('booking.cancel')"
                    severity="danger" outlined class="mt-2"
                    @click="cancelBooking(selectedReservation)" />
        </div>
    </Dialog>
</template>
```

### 4.9 Booking Confirmation Page (PayPal-First)

```vue
<!-- Pages/Booking/Confirmation.vue -->
<script setup lang="ts">
import Card from 'primevue/card'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Divider from 'primevue/divider'
import Message from 'primevue/message'
import RadioButton from 'primevue/radiobutton'
import Tag from 'primevue/tag'
import { useForm } from '@inertiajs/vue3'

const props = defineProps<{
    booking: BookingConfirmation
    bills: Bill[]
    total: number              // cents
    paymentMethods: PaymentMethod[]
    budgetBalance: number      // cents, 0 if no budget
    budgetCoversTotal: boolean
}>()

const form = useForm({
    payment_method: props.budgetCoversTotal ? 'budget' : 'paypal',
    coupon_code: '',
})

function submitPayment() {
    form.post(route('booking.pay', { bid: props.booking.bid }))
}
</script>

<template>
    <AppLayout>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Left: Booking summary (2 cols wide) -->
            <div class="lg:col-span-2 flex flex-col gap-6">
                <Card>
                    <template #title>{{ $t('booking.confirmation_title') }}</template>
                    <template #content>
                        <div class="grid grid-cols-2 gap-4 text-sm">
                            <div>
                                <span class="text-gray-500">{{ $t('booking.court') }}</span>
                                <p class="font-medium">{{ booking.square_name }}</p>
                            </div>
                            <div>
                                <span class="text-gray-500">{{ $t('booking.date') }}</span>
                                <p class="font-medium">{{ booking.date_formatted }}</p>
                            </div>
                            <div>
                                <span class="text-gray-500">{{ $t('booking.time') }}</span>
                                <p class="font-medium">{{ booking.time_start }} – {{ booking.time_end }}</p>
                            </div>
                            <div>
                                <span class="text-gray-500">{{ $t('booking.quantity') }}</span>
                                <p class="font-medium">{{ booking.quantity }}</p>
                            </div>
                        </div>
                    </template>
                </Card>

                <!-- Itemized bill -->
                <Card>
                    <template #title>{{ $t('booking.bill') }}</template>
                    <template #content>
                        <DataTable :value="bills" class="text-sm">
                            <Column field="description" :header="$t('booking.description')" />
                            <Column field="price" :header="$t('booking.price')" style="width: 120px">
                                <template #body="{ data }">
                                    <PriceDisplay :cents="data.price" />
                                </template>
                            </Column>
                            <Column field="rate" header="MwSt." style="width: 80px">
                                <template #body="{ data }">{{ data.rate }}%</template>
                            </Column>
                        </DataTable>
                        <Divider />
                        <div class="flex justify-between font-semibold text-lg">
                            <span>{{ $t('booking.total') }}</span>
                            <PriceDisplay :cents="total" />
                        </div>
                    </template>
                </Card>
            </div>

            <!-- Right: Payment (1 col) -->
            <div class="flex flex-col gap-6">
                <Card>
                    <template #title>{{ $t('booking.payment') }}</template>
                    <template #content>
                        <div class="flex flex-col gap-4">
                            <!-- Budget option (if balance > 0) -->
                            <div v-if="budgetBalance > 0"
                                 class="flex items-start gap-3 p-3 rounded-lg border cursor-pointer"
                                 :class="form.payment_method === 'budget' ? 'border-primary-500 bg-primary-50' : ''"
                                 @click="form.payment_method = 'budget'">
                                <RadioButton v-model="form.payment_method" value="budget" />
                                <div>
                                    <p class="font-medium">{{ $t('booking.budget') }}</p>
                                    <p class="text-sm text-gray-500">
                                        {{ $t('booking.budget_balance') }}:
                                        <PriceDisplay :cents="budgetBalance" />
                                    </p>
                                    <Tag v-if="budgetCoversTotal" severity="success"
                                         :value="$t('booking.budget_covers')" class="mt-1" />
                                    <p v-else class="text-sm text-orange-600 mt-1">
                                        {{ $t('booking.budget_partial') }}:
                                        <PriceDisplay :cents="total - budgetBalance" /> via PayPal
                                    </p>
                                </div>
                            </div>

                            <!-- PayPal (always available, primary) -->
                            <div class="flex items-start gap-3 p-3 rounded-lg border cursor-pointer"
                                 :class="form.payment_method === 'paypal' ? 'border-primary-500 bg-primary-50' : ''"
                                 @click="form.payment_method = 'paypal'">
                                <RadioButton v-model="form.payment_method" value="paypal" />
                                <div>
                                    <p class="font-medium">PayPal</p>
                                    <p class="text-sm text-gray-500">{{ $t('booking.paypal_hint') }}</p>
                                </div>
                            </div>

                            <!-- Optional gateways (only shown if configured) -->
                            <template v-for="method in paymentMethods.filter(m => m.key !== 'paypal' && m.key !== 'budget')"
                                      :key="method.key">
                                <div class="flex items-start gap-3 p-3 rounded-lg border cursor-pointer"
                                     :class="form.payment_method === method.key ? 'border-primary-500 bg-primary-50' : ''"
                                     @click="form.payment_method = method.key">
                                    <RadioButton v-model="form.payment_method" :value="method.key" />
                                    <p class="font-medium">{{ method.label }}</p>
                                </div>
                            </template>
                        </div>

                        <Divider />

                        <!-- Coupon code -->
                        <div class="flex flex-col gap-1">
                            <label class="text-sm">{{ $t('booking.coupon_code') }}</label>
                            <div class="flex gap-2">
                                <InputText v-model="form.coupon_code"
                                           :placeholder="$t('booking.coupon_placeholder')" class="flex-1" />
                                <Button :label="$t('booking.coupon_apply')" outlined size="small" />
                            </div>
                        </div>

                        <Divider />

                        <Button :label="$t('booking.pay_now')" severity="primary"
                                icon="pi pi-lock" class="w-full" size="large"
                                :loading="form.processing" @click="submitPayment" />

                        <Message v-if="form.errors.payment_method" severity="error" class="mt-2">
                            {{ form.errors.payment_method }}
                        </Message>
                    </template>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
```

### 4.10 Backend Config Pages (PrimeVue Tabs + Editor)

```vue
<!-- Pages/Backend/Config/Index.vue -->
<script setup lang="ts">
import Tabs from 'primevue/tabs'
import TabList from 'primevue/tablist'
import Tab from 'primevue/tab'
import TabPanels from 'primevue/tabpanels'
import TabPanel from 'primevue/tabpanel'
import Editor from 'primevue/editor'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Checkbox from 'primevue/checkbox'
import Select from 'primevue/select'
import ColorPicker from 'primevue/colorpicker'
import Button from 'primevue/button'
import Card from 'primevue/card'
import { useForm } from '@inertiajs/vue3'
</script>

<template>
    <BackendLayout>
        <Tabs value="text">
            <TabList>
                <Tab value="text">{{ $t('backend.config_text') }}</Tab>
                <Tab value="info">{{ $t('backend.config_info') }}</Tab>
                <Tab value="help">{{ $t('backend.config_help') }}</Tab>
                <Tab value="behaviour">{{ $t('backend.config_behaviour') }}</Tab>
                <Tab value="rules">{{ $t('backend.config_rules') }}</Tab>
                <Tab value="colors">{{ $t('backend.config_colors') }}</Tab>
            </TabList>

            <TabPanels>
                <!-- Text Config -->
                <TabPanel value="text">
                    <Card>
                        <template #content>
                            <div class="flex flex-col gap-4">
                                <div class="flex flex-col gap-1">
                                    <label>{{ $t('backend.facility_name') }}</label>
                                    <InputText v-model="textForm.facility_name" />
                                </div>
                                <div class="flex flex-col gap-1">
                                    <label>{{ $t('backend.welcome_text') }}</label>
                                    <Editor v-model="textForm.welcome_text" editorStyle="height: 200px" />
                                </div>
                                <Button :label="$t('booking.save')" severity="primary"
                                        @click="textForm.post(route('backend.config.text.update'))" />
                            </div>
                        </template>
                    </Card>
                </TabPanel>

                <!-- Info Page (Rich Text) -->
                <TabPanel value="info">
                    <Card>
                        <template #content>
                            <Editor v-model="infoForm.content" editorStyle="height: 400px" />
                            <Button :label="$t('booking.save')" severity="primary" class="mt-4"
                                    @click="infoForm.post(route('backend.config.info.update'))" />
                        </template>
                    </Card>
                </TabPanel>

                <!-- Behaviour Config -->
                <TabPanel value="behaviour">
                    <Card>
                        <template #content>
                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                                <div class="flex flex-col gap-4">
                                    <div class="flex flex-col gap-1">
                                        <label>{{ $t('backend.time_block') }}</label>
                                        <InputNumber v-model="behaviourForm.time_block"
                                                     suffix=" min" :min="15" :step="15" />
                                    </div>
                                    <div class="flex flex-col gap-1">
                                        <label>{{ $t('backend.max_bookings_per_day') }}</label>
                                        <InputNumber v-model="behaviourForm.max_per_day" :min="0" />
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <Checkbox v-model="behaviourForm.directpay"
                                                  :binary="true" inputId="directpay" />
                                        <label for="directpay">{{ $t('backend.require_payment') }}</label>
                                    </div>
                                </div>
                                <div class="flex flex-col gap-4">
                                    <div class="flex flex-col gap-1">
                                        <label>{{ $t('backend.min_cancel_hours') }}</label>
                                        <InputNumber v-model="behaviourForm.min_cancel_hours"
                                                     suffix=" h" :min="0" />
                                    </div>
                                </div>
                            </div>
                            <Button :label="$t('booking.save')" severity="primary" class="mt-6"
                                    @click="behaviourForm.post(route('backend.config.behaviour.update'))" />
                        </template>
                    </Card>
                </TabPanel>

                <!-- Status Colors -->
                <TabPanel value="colors">
                    <Card>
                        <template #content>
                            <div class="flex flex-col gap-4">
                                <div v-for="status in statusOptions" :key="status.key"
                                     class="flex items-center gap-4">
                                    <ColorPicker v-model="colorsForm[status.key]" />
                                    <Tag :style="{ background: colorsForm[status.key] }"
                                         :value="status.label" />
                                    <span class="text-sm text-gray-500">{{ status.description }}</span>
                                </div>
                            </div>
                            <Button :label="$t('booking.save')" severity="primary" class="mt-6"
                                    @click="colorsForm.post(route('backend.config.colors.update'))" />
                        </template>
                    </Card>
                </TabPanel>
            </TabPanels>
        </Tabs>
    </BackendLayout>
</template>
```

### 4.11 Backend Pricing Editor (DataTable Inline Edit)

```vue
<!-- Pages/Backend/Squares/Pricing.vue -->
<script setup lang="ts">
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import InputNumber from 'primevue/inputnumber'
import DatePicker from 'primevue/datepicker'
import Select from 'primevue/select'
import Checkbox from 'primevue/checkbox'
import Button from 'primevue/button'
import Message from 'primevue/message'
import { useTimeOptions } from '@/Composables/useTimeOptions'

const timeOptions = useTimeOptions(7, 22)
const props = defineProps<{ pricingRules: PricingRule[], squares: Square[] }>()

// Warn if any rule expires within 30 days
const expiringRules = computed(() =>
    props.pricingRules.filter(r => {
        const daysLeft = dayjs(r.date_end).diff(dayjs(), 'day')
        return daysLeft >= 0 && daysLeft <= 30
    })
)
</script>

<template>
    <BackendLayout>
        <Message v-if="expiringRules.length" severity="warn" class="mb-4">
            {{ $t('backend.pricing_expiry_warning', { count: expiringRules.length }) }}
        </Message>

        <DataTable :value="pricingRules" editMode="cell" @cell-edit-complete="onCellEdit"
                   sortField="priority" :sortOrder="-1">
            <Column field="sid" :header="$t('booking.court')" style="width: 140px">
                <template #body="{ data }">{{ data.sid ? squareName(data.sid) : $t('backend.all_courts') }}</template>
                <template #editor="{ data, field }">
                    <Select v-model="data[field]" :options="[{ sid: null, name: $t('backend.all_courts') }, ...squares]"
                            optionLabel="name" optionValue="sid" class="w-full" />
                </template>
            </Column>
            <Column field="date_start" :header="$t('backend.date_from')">
                <template #editor="{ data, field }">
                    <DatePicker v-model="data[field]" dateFormat="dd.mm.yy" class="w-full" />
                </template>
            </Column>
            <Column field="date_end" :header="$t('backend.date_to')">
                <template #body="{ data }">
                    <span :class="{ 'text-red-600 font-semibold': isExpiring(data) }">
                        {{ formatDate(data.date_end) }}
                    </span>
                </template>
                <template #editor="{ data, field }">
                    <DatePicker v-model="data[field]" dateFormat="dd.mm.yy" class="w-full" />
                </template>
            </Column>
            <Column field="time_start" :header="$t('booking.time_from')" style="width: 110px">
                <template #editor="{ data, field }">
                    <Select v-model="data[field]" :options="timeOptions"
                            optionLabel="label" optionValue="value" class="w-full" />
                </template>
            </Column>
            <Column field="time_end" :header="$t('booking.time_to')" style="width: 110px">
                <template #editor="{ data, field }">
                    <Select v-model="data[field]" :options="timeOptions"
                            optionLabel="label" optionValue="value" class="w-full" />
                </template>
            </Column>
            <Column field="member" header="Member" style="width: 80px">
                <template #body="{ data }">
                    <i :class="data.member ? 'pi pi-check text-green-600' : 'pi pi-minus text-gray-400'" />
                </template>
                <template #editor="{ data, field }">
                    <Checkbox v-model="data[field]" :binary="true" />
                </template>
            </Column>
            <Column field="price" :header="$t('booking.price')" style="width: 120px">
                <template #body="{ data }"><PriceDisplay :cents="data.price" /></template>
                <template #editor="{ data, field }">
                    <InputNumber v-model="data[field]" mode="currency" currency="EUR" locale="de-DE" class="w-full" />
                </template>
            </Column>
            <Column field="priority" header="Prio" style="width: 80px">
                <template #editor="{ data, field }">
                    <InputNumber v-model="data[field]" :min="0" class="w-full" />
                </template>
            </Column>
            <Column style="width: 60px">
                <template #body="{ data }">
                    <Button icon="pi pi-trash" text rounded severity="danger" @click="removeRule(data)" />
                </template>
            </Column>
        </DataTable>
        <Button :label="$t('backend.add_rule')" icon="pi pi-plus" text class="mt-2" @click="addRule" />
    </BackendLayout>
</template>
```

### 4.12 Setup Wizard (PrimeVue Stepper)

```vue
<!-- Pages/Setup/Index.vue -->
<script setup lang="ts">
import Stepper from 'primevue/stepper'
import StepList from 'primevue/steplist'
import Step from 'primevue/step'
import StepPanels from 'primevue/steppanels'
import StepPanel from 'primevue/steppanel'
import Card from 'primevue/card'
import InputText from 'primevue/inputtext'
import Password from 'primevue/password'
import Select from 'primevue/select'
import InputNumber from 'primevue/inputnumber'
import Button from 'primevue/button'
import Message from 'primevue/message'
import { useForm } from '@inertiajs/vue3'
import { useTimeOptions } from '@/Composables/useTimeOptions'

const timeOptions = useTimeOptions(7, 22)
const activeStep = ref(1)

const dbForm = useForm({
    db_host: 'mariadb', db_name: 'ep3bs', db_user: 'ep3bs', db_password: ''
})
const adminForm = useForm({
    alias: '', email: '', password: '', password_confirmation: ''
})
const facilityForm = useForm({
    name: '', courts: 1, time_start: '08:00:00', time_end: '22:00:00', time_block: 60
})
</script>

<template>
    <div class="min-h-screen flex items-center justify-center bg-gray-50 p-4">
        <Card class="w-full max-w-3xl">
            <template #title>{{ $t('setup.title') }}</template>
            <template #content>
                <Stepper :value="activeStep" linear>
                    <StepList>
                        <Step :value="1">{{ $t('setup.database') }}</Step>
                        <Step :value="2">{{ $t('setup.admin') }}</Step>
                        <Step :value="3">{{ $t('setup.facility') }}</Step>
                        <Step :value="4">{{ $t('setup.complete') }}</Step>
                    </StepList>

                    <StepPanels>
                        <!-- Step 1: Database -->
                        <StepPanel :value="1">
                            <div class="flex flex-col gap-4 py-4">
                                <div class="flex flex-col gap-1">
                                    <label>Host</label>
                                    <InputText v-model="dbForm.db_host" />
                                </div>
                                <div class="flex flex-col gap-1">
                                    <label>{{ $t('setup.db_name') }}</label>
                                    <InputText v-model="dbForm.db_name" />
                                </div>
                                <div class="grid grid-cols-2 gap-4">
                                    <div class="flex flex-col gap-1">
                                        <label>{{ $t('setup.db_user') }}</label>
                                        <InputText v-model="dbForm.db_user" />
                                    </div>
                                    <div class="flex flex-col gap-1">
                                        <label>{{ $t('setup.db_password') }}</label>
                                        <Password v-model="dbForm.db_password" :feedback="false" toggleMask />
                                    </div>
                                </div>
                                <Button :label="$t('setup.test_connection')" outlined @click="testDb"
                                        :loading="dbForm.processing" />
                                <Message v-if="dbConnected" severity="success">
                                    {{ $t('setup.db_connected') }}
                                </Message>
                            </div>
                            <div class="flex justify-end">
                                <Button :label="$t('setup.next')" icon="pi pi-arrow-right" iconPos="right"
                                        @click="activeStep = 2" :disabled="!dbConnected" />
                            </div>
                        </StepPanel>

                        <!-- Step 2: Admin Account -->
                        <StepPanel :value="2">
                            <div class="flex flex-col gap-4 py-4">
                                <div class="flex flex-col gap-1">
                                    <label>{{ $t('setup.admin_name') }}</label>
                                    <InputText v-model="adminForm.alias" />
                                </div>
                                <div class="flex flex-col gap-1">
                                    <label>Email</label>
                                    <InputText v-model="adminForm.email" type="email" />
                                </div>
                                <div class="grid grid-cols-2 gap-4">
                                    <div class="flex flex-col gap-1">
                                        <label>{{ $t('setup.password') }}</label>
                                        <Password v-model="adminForm.password" toggleMask />
                                    </div>
                                    <div class="flex flex-col gap-1">
                                        <label>{{ $t('setup.password_confirm') }}</label>
                                        <Password v-model="adminForm.password_confirmation"
                                                  :feedback="false" toggleMask />
                                    </div>
                                </div>
                            </div>
                            <div class="flex justify-between">
                                <Button :label="$t('setup.back')" text @click="activeStep = 1" />
                                <Button :label="$t('setup.next')" icon="pi pi-arrow-right" iconPos="right"
                                        @click="activeStep = 3" />
                            </div>
                        </StepPanel>

                        <!-- Step 3: Facility -->
                        <StepPanel :value="3">
                            <div class="flex flex-col gap-4 py-4">
                                <div class="flex flex-col gap-1">
                                    <label>{{ $t('setup.facility_name') }}</label>
                                    <InputText v-model="facilityForm.name" />
                                </div>
                                <div class="flex flex-col gap-1">
                                    <label>{{ $t('setup.court_count') }}</label>
                                    <InputNumber v-model="facilityForm.courts" :min="1" :max="20" />
                                </div>
                                <div class="grid grid-cols-3 gap-4">
                                    <div class="flex flex-col gap-1">
                                        <label>{{ $t('booking.time_from') }}</label>
                                        <Select v-model="facilityForm.time_start" :options="timeOptions"
                                                optionLabel="label" optionValue="value" />
                                    </div>
                                    <div class="flex flex-col gap-1">
                                        <label>{{ $t('booking.time_to') }}</label>
                                        <Select v-model="facilityForm.time_end" :options="timeOptions"
                                                optionLabel="label" optionValue="value" />
                                    </div>
                                    <div class="flex flex-col gap-1">
                                        <label>{{ $t('backend.time_block') }}</label>
                                        <Select v-model="facilityForm.time_block"
                                                :options="[30, 60, 90, 120].map(m => ({ label: m + ' min', value: m }))"
                                                optionLabel="label" optionValue="value" />
                                    </div>
                                </div>
                            </div>
                            <div class="flex justify-between">
                                <Button :label="$t('setup.back')" text @click="activeStep = 2" />
                                <Button :label="$t('setup.install')" severity="primary"
                                        icon="pi pi-check" @click="runSetup" :loading="installing" />
                            </div>
                        </StepPanel>

                        <!-- Step 4: Complete -->
                        <StepPanel :value="4">
                            <div class="text-center py-8">
                                <i class="pi pi-check-circle text-6xl text-green-500 mb-4" />
                                <h2 class="text-2xl font-semibold mb-2">{{ $t('setup.done_title') }}</h2>
                                <p class="text-gray-500 mb-6">{{ $t('setup.done_text') }}</p>
                                <Button :label="$t('setup.go_to_app')" severity="primary"
                                        @click="router.visit('/')" />
                            </div>
                        </StepPanel>
                    </StepPanels>
                </Stepper>
            </template>
        </Card>
    </div>
</template>
```

### 4.13 Admin Dashboard with KPIs (recharts)

```vue
<!-- Pages/Backend/Dashboard.vue -->
<script setup lang="ts">
import Card from 'primevue/card'
import Tag from 'primevue/tag'
import Select from 'primevue/select'
import DatePicker from 'primevue/datepicker'
import Chart from 'primevue/chart'       // PrimeVue Chart.js wrapper
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'

const props = defineProps<{
    stats: {
        bookingsToday: number
        bookingsThisMonth: number
        revenueThisMonth: number           // cents
        occupancyRate: number              // 0–100
        noShowRate: number                 // 0–100
        activeMembers: number
        pendingPayments: number
        waitlistEntries: number
    }
    revenueChart: { labels: string[], data: number[] }  // last 12 months
    occupancyHeatmap: { day: string, hour: number, rate: number }[]
    topUsers: { alias: string, bookings: number, revenue: number }[]
    upcomingBookings: Booking[]
}>()

// Revenue chart data for PrimeVue Chart (Chart.js wrapper)
const revenueChartData = computed(() => ({
    labels: props.revenueChart.labels,
    datasets: [{
        label: $t('backend.revenue'),
        data: props.revenueChart.data.map(c => c / 100),  // cents → EUR
        borderColor: '#3B82F6',
        backgroundColor: 'rgba(59, 130, 246, 0.1)',
        fill: true, tension: 0.3,
    }],
}))

// Occupancy heatmap: 7 days × 15 hours → color intensity grid
const occupancyChartData = computed(() => ({
    labels: ['07', '08', '09', '10', '11', '12', '13', '14', '15', '16', '17', '18', '19', '20', '21'],
    datasets: ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'].map((day, i) => ({
        label: day,
        data: props.occupancyHeatmap.filter(h => h.day === day).map(h => h.rate),
        backgroundColor: `rgba(59, 130, 246, 0.${Math.round(i * 0.1 + 0.3)})`,
    })),
}))
</script>

<template>
    <BackendLayout>
        <!-- KPI Cards Row -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
            <Card class="text-center">
                <template #content>
                    <p class="text-3xl font-bold text-primary-600">{{ stats.bookingsToday }}</p>
                    <p class="text-sm text-gray-500">{{ $t('backend.bookings_today') }}</p>
                </template>
            </Card>
            <Card class="text-center">
                <template #content>
                    <p class="text-3xl font-bold text-green-600">
                        <PriceDisplay :cents="stats.revenueThisMonth" />
                    </p>
                    <p class="text-sm text-gray-500">{{ $t('backend.revenue_month') }}</p>
                </template>
            </Card>
            <Card class="text-center">
                <template #content>
                    <p class="text-3xl font-bold" :class="stats.occupancyRate > 70 ? 'text-green-600' : 'text-orange-500'">
                        {{ stats.occupancyRate }}%
                    </p>
                    <p class="text-sm text-gray-500">{{ $t('backend.occupancy_rate') }}</p>
                </template>
            </Card>
            <Card class="text-center">
                <template #content>
                    <p class="text-3xl font-bold" :class="stats.noShowRate > 10 ? 'text-red-600' : 'text-gray-600'">
                        {{ stats.noShowRate }}%
                    </p>
                    <p class="text-sm text-gray-500">{{ $t('backend.noshow_rate') }}</p>
                </template>
            </Card>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <!-- Revenue Chart (12 months) -->
            <Card>
                <template #title>{{ $t('backend.revenue_trend') }}</template>
                <template #content>
                    <Chart type="line" :data="revenueChartData" :options="{ responsive: true }" />
                </template>
            </Card>

            <!-- Occupancy Heatmap -->
            <Card>
                <template #title>{{ $t('backend.occupancy_heatmap') }}</template>
                <template #content>
                    <Chart type="bar" :data="occupancyChartData" :options="{ responsive: true, scales: { x: { stacked: true }, y: { stacked: true, max: 100 } } }" />
                </template>
            </Card>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Quick stats -->
            <Card>
                <template #title>{{ $t('backend.quick_info') }}</template>
                <template #content>
                    <div class="flex flex-col gap-3">
                        <div class="flex justify-between">
                            <span>{{ $t('backend.active_members') }}</span>
                            <Tag severity="info" :value="String(stats.activeMembers)" />
                        </div>
                        <div class="flex justify-between">
                            <span>{{ $t('backend.pending_payments') }}</span>
                            <Tag :severity="stats.pendingPayments > 0 ? 'warn' : 'success'"
                                 :value="String(stats.pendingPayments)" />
                        </div>
                        <div class="flex justify-between">
                            <span>{{ $t('backend.waitlist_entries') }}</span>
                            <Tag severity="secondary" :value="String(stats.waitlistEntries)" />
                        </div>
                    </div>
                </template>
            </Card>

            <!-- Top Users -->
            <Card>
                <template #title>{{ $t('backend.top_users') }}</template>
                <template #content>
                    <DataTable :value="topUsers" :rows="5" class="text-sm">
                        <Column field="alias" :header="$t('booking.user')" />
                        <Column field="bookings" header="Buchungen" style="width: 100px" />
                        <Column field="revenue" :header="$t('booking.price')" style="width: 100px">
                            <template #body="{ data }">
                                <PriceDisplay :cents="data.revenue" />
                            </template>
                        </Column>
                    </DataTable>
                </template>
            </Card>
        </div>
    </BackendLayout>
</template>
```

### 4.14 Recurring Booking Form

```vue
<!-- Pages/Backend/Bookings/Recurring.vue -->
<script setup lang="ts">
import Card from 'primevue/card'
import Select from 'primevue/select'
import DatePicker from 'primevue/datepicker'
import AutoComplete from 'primevue/autocomplete'
import InputNumber from 'primevue/inputnumber'
import Button from 'primevue/button'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import Message from 'primevue/message'
import { useForm } from '@inertiajs/vue3'
import { useTimeOptions } from '@/Composables/useTimeOptions'

const timeOptions = useTimeOptions(7, 22)

const form = useForm({
    user: null as User | null,
    sid: null as number | null,
    time_start: '18:00:00',
    time_end: '19:00:00',
    frequency: 'weekly',
    date_start: null as Date | null,   // first occurrence
    date_end: null as Date | null,     // series end (e.g. end of season)
    payment_mode: 'per_occurrence',    // 'per_occurrence' | 'upfront'
})

const frequencyOptions = [
    { label: $t('backend.weekly'), value: 'weekly' },
    { label: $t('backend.biweekly'), value: 'biweekly' },
]

const paymentModeOptions = [
    { label: $t('backend.pay_per_booking'), value: 'per_occurrence' },
    { label: $t('backend.pay_upfront'), value: 'upfront' },
]

// Preview: calculate all occurrences and check conflicts
const preview = ref<{ date: string, conflict: boolean, price: number }[]>([])
</script>

<template>
    <BackendLayout>
        <Card>
            <template #title>{{ $t('backend.recurring_booking') }}</template>
            <template #content>
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <div class="flex flex-col gap-4">
                        <div class="flex flex-col gap-1">
                            <label>{{ $t('booking.user') }}</label>
                            <AutoComplete v-model="form.user" :suggestions="userSuggestions"
                                          @complete="searchUsers" field="alias" />
                        </div>
                        <div class="flex flex-col gap-1">
                            <label>{{ $t('booking.court') }}</label>
                            <Select v-model="form.sid" :options="squares"
                                    optionLabel="name" optionValue="sid" />
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="flex flex-col gap-1">
                                <label>{{ $t('booking.time_from') }}</label>
                                <Select v-model="form.time_start" :options="timeOptions"
                                        optionLabel="label" optionValue="value" />
                            </div>
                            <div class="flex flex-col gap-1">
                                <label>{{ $t('booking.time_to') }}</label>
                                <Select v-model="form.time_end" :options="timeOptions"
                                        optionLabel="label" optionValue="value" />
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-col gap-4">
                        <div class="flex flex-col gap-1">
                            <label>{{ $t('backend.frequency') }}</label>
                            <Select v-model="form.frequency" :options="frequencyOptions"
                                    optionLabel="label" optionValue="value" />
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="flex flex-col gap-1">
                                <label>{{ $t('backend.series_start') }}</label>
                                <DatePicker v-model="form.date_start" dateFormat="dd.mm.yy" />
                            </div>
                            <div class="flex flex-col gap-1">
                                <label>{{ $t('backend.series_end') }}</label>
                                <DatePicker v-model="form.date_end" dateFormat="dd.mm.yy" />
                            </div>
                        </div>
                        <div class="flex flex-col gap-1">
                            <label>{{ $t('backend.payment_mode') }}</label>
                            <Select v-model="form.payment_mode" :options="paymentModeOptions"
                                    optionLabel="label" optionValue="value" />
                        </div>
                        <Button :label="$t('backend.preview_series')" outlined
                                icon="pi pi-eye" @click="generatePreview" />
                    </div>
                </div>

                <!-- Preview table: all occurrences with conflict check -->
                <div v-if="preview.length" class="mt-6">
                    <Message v-if="conflictCount > 0" severity="warn">
                        {{ $t('backend.series_conflicts', { count: conflictCount }) }}
                    </Message>

                    <DataTable :value="preview" :paginator="preview.length > 20" :rows="20"
                               class="text-sm mt-2">
                        <Column field="date" :header="$t('booking.date')" />
                        <Column :header="$t('backend.status')" style="width: 120px">
                            <template #body="{ data }">
                                <Tag :severity="data.conflict ? 'danger' : 'success'"
                                     :value="data.conflict ? $t('backend.conflict') : $t('backend.available')" />
                            </template>
                        </Column>
                        <Column field="price" :header="$t('booking.price')" style="width: 100px">
                            <template #body="{ data }">
                                <PriceDisplay :cents="data.price" />
                            </template>
                        </Column>
                    </DataTable>

                    <div class="flex justify-between items-center mt-4 p-3 bg-gray-50 rounded">
                        <span class="font-semibold">
                            {{ $t('backend.total_series') }}: {{ preview.filter(p => !p.conflict).length }} ×
                        </span>
                        <span class="text-xl font-bold">
                            <PriceDisplay :cents="totalSeriesPrice" />
                        </span>
                    </div>

                    <div class="flex flex-col sm:flex-row gap-3 justify-center mt-6">
                        <Button :label="$t('backend.create_series')" severity="primary"
                                icon="pi pi-check" :loading="form.processing"
                                @click="form.post(route('backend.bookings.recurring.store'))" />
                    </div>
                </div>
            </template>
        </Card>
    </BackendLayout>
</template>
```

### 4.15 Waitlist on Calendar

```vue
<!-- In BookingPopup.vue — when slot is occupied -->
<template>
    <div v-if="slot.isOccupied" class="flex flex-col gap-4">
        <Message severity="info">
            {{ $t('booking.slot_occupied') }}
        </Message>

        <div class="text-sm text-gray-600">
            <p>{{ $t('booking.slot_occupied_by', { name: slot.bookedBy }) }}</p>
            <p>{{ slot.time_start }} – {{ slot.time_end }}</p>
        </div>

        <Button v-if="!alreadyOnWaitlist"
                :label="$t('booking.join_waitlist')"
                icon="pi pi-bell" outlined severity="secondary"
                @click="joinWaitlist"
                :loading="waitlistLoading" />

        <div v-else class="flex items-center gap-2 text-green-600">
            <i class="pi pi-check-circle" />
            <span>{{ $t('booking.on_waitlist') }}</span>
            <Button :label="$t('booking.leave_waitlist')" text size="small"
                    severity="danger" @click="leaveWaitlist" />
        </div>
    </div>

    <!-- Existing booking form when slot is free -->
    <div v-else>
        <!-- ... existing BookingPopup form ... -->
    </div>
</template>
```

### 4.16 Backend Export Page

```vue
<!-- Pages/Backend/Export/Index.vue -->
<script setup lang="ts">
import Card from 'primevue/card'
import DatePicker from 'primevue/datepicker'
import Select from 'primevue/select'
import Button from 'primevue/button'
import Divider from 'primevue/divider'
</script>

<template>
    <BackendLayout>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Booking Export -->
            <Card>
                <template #title>{{ $t('backend.export_bookings') }}</template>
                <template #content>
                    <div class="flex flex-col gap-4">
                        <div class="grid grid-cols-2 gap-4">
                            <div class="flex flex-col gap-1">
                                <label>{{ $t('backend.date_from') }}</label>
                                <DatePicker v-model="bookingExport.from" dateFormat="dd.mm.yy" />
                            </div>
                            <div class="flex flex-col gap-1">
                                <label>{{ $t('backend.date_to') }}</label>
                                <DatePicker v-model="bookingExport.to" dateFormat="dd.mm.yy" />
                            </div>
                        </div>
                        <Select v-model="bookingExport.status" :options="statusOptions"
                                optionLabel="label" optionValue="value"
                                :placeholder="$t('backend.all_statuses')" showClear />
                        <div class="flex gap-3">
                            <Button :label="$t('backend.export_csv')" icon="pi pi-file"
                                    outlined @click="exportBookingsCsv" />
                            <Button :label="$t('backend.export_pdf')" icon="pi pi-file-pdf"
                                    outlined severity="danger" @click="exportBookingsPdf" />
                        </div>
                    </div>
                </template>
            </Card>

            <!-- Revenue Report -->
            <Card>
                <template #title>{{ $t('backend.export_revenue') }}</template>
                <template #content>
                    <div class="flex flex-col gap-4">
                        <div class="grid grid-cols-2 gap-4">
                            <div class="flex flex-col gap-1">
                                <label>{{ $t('backend.date_from') }}</label>
                                <DatePicker v-model="revenueExport.from" dateFormat="dd.mm.yy" />
                            </div>
                            <div class="flex flex-col gap-1">
                                <label>{{ $t('backend.date_to') }}</label>
                                <DatePicker v-model="revenueExport.to" dateFormat="dd.mm.yy" />
                            </div>
                        </div>
                        <div class="flex gap-3">
                            <Button :label="$t('backend.export_csv')" icon="pi pi-file"
                                    outlined @click="exportRevenueCsv" />
                            <Button :label="$t('backend.revenue_report_pdf')" icon="pi pi-file-pdf"
                                    outlined severity="danger" @click="exportRevenueReportPdf" />
                        </div>
                    </div>
                </template>
            </Card>

            <!-- User Export -->
            <Card>
                <template #title>{{ $t('backend.export_users') }}</template>
                <template #content>
                    <Button :label="$t('backend.export_csv')" icon="pi pi-file"
                            outlined @click="exportUsersCsv" />
                </template>
            </Card>

            <!-- Occupancy Report -->
            <Card>
                <template #title>{{ $t('backend.export_occupancy') }}</template>
                <template #content>
                    <div class="flex flex-col gap-4">
                        <div class="grid grid-cols-2 gap-4">
                            <div class="flex flex-col gap-1">
                                <label>{{ $t('backend.date_from') }}</label>
                                <DatePicker v-model="occupancyExport.from" dateFormat="dd.mm.yy" />
                            </div>
                            <div class="flex flex-col gap-1">
                                <label>{{ $t('backend.date_to') }}</label>
                                <DatePicker v-model="occupancyExport.to" dateFormat="dd.mm.yy" />
                            </div>
                        </div>
                        <Button :label="$t('backend.export_csv')" icon="pi pi-file"
                                outlined @click="exportOccupancyCsv" />
                    </div>
                </template>
            </Card>
        </div>
    </BackendLayout>
</template>
```

### 4.17 User Settings — Reminders & Invoice Download

```vue
<!-- Pages/User/Settings.vue — extended with reminder toggle and invoice downloads -->
<template>
    <AppLayout>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Personal Data -->
            <Card>
                <template #title>{{ $t('user.personal_data') }}</template>
                <template #content>
                    <!-- ... existing name, email, phone fields ... -->
                </template>
            </Card>

            <!-- Password -->
            <Card>
                <template #title>{{ $t('user.password') }}</template>
                <template #content>
                    <!-- ... existing password change fields ... -->
                </template>
            </Card>

            <!-- Notification Settings (NEW) -->
            <Card>
                <template #title>{{ $t('user.notifications') }}</template>
                <template #content>
                    <div class="flex flex-col gap-4">
                        <div class="flex items-center gap-3">
                            <ToggleSwitch v-model="form.reminders_enabled" />
                            <div>
                                <p class="font-medium">{{ $t('user.booking_reminders') }}</p>
                                <p class="text-sm text-gray-500">
                                    {{ $t('user.reminder_description') }}
                                </p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <ToggleSwitch v-model="form.waitlist_notifications" />
                            <div>
                                <p class="font-medium">{{ $t('user.waitlist_notifications') }}</p>
                                <p class="text-sm text-gray-500">
                                    {{ $t('user.waitlist_description') }}
                                </p>
                            </div>
                        </div>
                    </div>
                </template>
            </Card>
        </div>
    </AppLayout>
</template>
```

```vue
<!-- Pages/User/Bookings.vue — extended with invoice download and no-show badge -->
<template>
    <AppLayout>
        <DataTable :value="bookings" :paginator="true" :rows="10">
            <Column field="date" :header="$t('booking.date')" sortable />
            <Column field="time_range" :header="$t('booking.time')" />
            <Column field="square_name" :header="$t('booking.court')" />
            <Column :header="$t('booking.status')">
                <template #body="{ data }">
                    <div class="flex gap-1">
                        <Tag :severity="statusSeverity(data.status)" :value="data.status" />
                        <Tag v-if="data.no_show" severity="danger" value="No-Show" />
                    </div>
                </template>
            </Column>
            <Column field="total" :header="$t('booking.price')">
                <template #body="{ data }"><PriceDisplay :cents="data.total" /></template>
            </Column>
            <Column :header="$t('backend.actions')" style="width: 120px">
                <template #body="{ data }">
                    <div class="flex gap-1">
                        <!-- Invoice PDF download (only for paid bookings) -->
                        <Button v-if="data.billing_status === 'paid'"
                                icon="pi pi-file-pdf" text rounded size="small"
                                v-tooltip.top="$t('user.download_invoice')"
                                @click="downloadInvoice(data.bid)" />
                        <!-- Cancel (only future, active bookings) -->
                        <Button v-if="canCancel(data)"
                                icon="pi pi-times" text rounded size="small"
                                severity="danger"
                                v-tooltip.top="$t('booking.cancel')"
                                @click="confirmCancel(data)" />
                    </div>
                </template>
            </Column>
        </DataTable>
    </AppLayout>
</template>
```

---

### 5.1 PayPal — Primary Provider

```php
class PayPalService
{
    private PayPalClient $client;

    public function __construct() {
        $this->client = new PayPalClient;
        $this->client->setApiCredentials(config('paypal'));
        $this->client->setAccessToken($this->client->getAccessToken());
    }

    public function createOrder(Booking $booking, int $amountInCents): array {
        $response = $this->client->createOrder([
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => (string) $booking->bid,
                'description' => sprintf('Buchung #%d', $booking->bid),
                'amount' => [
                    'currency_code' => 'EUR',
                    'value' => number_format($amountInCents / 100, 2, '.', ''),
                ],
            ]],
            'application_context' => [
                'return_url' => route('payment.done', ['gateway' => 'paypal', 'bid' => $booking->bid]),
                'cancel_url' => route('payment.confirm', ['gateway' => 'paypal', 'bid' => $booking->bid, 'cancelled' => 1]),
                'shipping_preference' => 'NO_SHIPPING',
                'user_action' => 'PAY_NOW',
            ],
        ]);

        $approvalUrl = collect($response['links'])->firstWhere('rel', 'approve')['href'];
        $booking->setMeta('paypal_order_id', $response['id']);
        return ['order_id' => $response['id'], 'approval_url' => $approvalUrl];
    }

    public function captureOrder(string $orderId): array { /* ... */ }
    public function refundCapture(string $captureId, ?int $amountInCents = null): array { /* ... */ }
}
```

### 5.2 Stripe — Optional (only if `STRIPE_ENABLED=true`)

```php
class StripeService
{
    public static function isEnabled(): bool {
        return (bool) config('ep3bs.payments.stripe.enabled', false)
            && !empty(config('cashier.key'));
    }
    // Only instantiated when isEnabled() returns true
    // Supports: card, SEPA, iDEAL, giropay
    // Apple Pay + Google Pay enabled via Stripe Dashboard (automatic when 'card' is active)
}
```

### 5.3 Payment Gateway Abstraction

```php
class PaymentService
{
    public function getAvailableMethods(User $user, int $total): array {
        $methods = [];

        // Budget: always first if user has balance
        $balance = $this->budget->getBalance($user);
        if ($balance > 0) {
            $methods[] = ['key' => 'budget', 'label' => __('booking.budget'),
                          'balance' => $balance, 'covers_total' => $balance >= $total];
        }

        // PayPal: always available
        $methods[] = ['key' => 'paypal', 'label' => 'PayPal'];

        // Stripe: only if configured
        if (StripeService::isEnabled()) {
            $methods[] = ['key' => 'stripe', 'label' => __('booking.creditcard_sepa')];
        }

        // Klarna: only if configured
        if (KlarnaService::isEnabled()) {
            $methods[] = ['key' => 'klarna', 'label' => 'Klarna'];
        }

        return $methods;
    }

    /** Budget + gateway combination logic:
     *  1. budget >= total → budget-only, no gateway
     *  2. budget > 0 && < total → deduct budget, charge remaining via gateway
     *  3. budget deduction: immediate for budget-only; after gateway success for partial
     *  4. refund on cancel/delete: restore full bill total to budget
     */
    public function processPayment(Booking $booking, string $method, int $total): PaymentResult { /* ... */ }
}
```

---

## 6. Docker, Traefik & Dev Infrastructure

### 6.1 Docker Compose — Production

```yaml
# docker-compose.yml
services:
  app:
    build: { context: ., dockerfile: docker/Dockerfile }
    container_name: ep3bs-app
    restart: unless-stopped
    volumes:
      - ./storage:/var/www/html/storage
      - ./public/docs-client/upload:/var/www/html/public/docs-client/upload
      - ./public/imgs-client/upload:/var/www/html/public/imgs-client/upload
    depends_on: [db]
    networks: [ep3bs-internal, traefik-public]
    labels:
      - "traefik.enable=true"
      - "traefik.http.routers.ep3bs.rule=Host(`booking.example.com`)"
      - "traefik.http.routers.ep3bs.entrypoints=websecure"
      - "traefik.http.routers.ep3bs.tls.certresolver=letsencrypt"
      - "traefik.http.services.ep3bs.loadbalancer.server.port=80"

  db:
    image: mariadb:11
    container_name: ep3bs-db
    restart: unless-stopped
    volumes: [db-data:/var/lib/mysql]
    environment:
      MYSQL_ROOT_PASSWORD: ${DB_ROOT_PASSWORD}
      MYSQL_DATABASE: ${DB_DATABASE}
      MYSQL_USER: ${DB_USERNAME}
      MYSQL_PASSWORD: ${DB_PASSWORD}
    networks: [ep3bs-internal]

  scheduler:
    build: { context: ., dockerfile: docker/Dockerfile }
    container_name: ep3bs-scheduler
    restart: unless-stopped
    command: ["php", "artisan", "schedule:work"]
    volumes: [./storage:/var/www/html/storage]
    depends_on: [db]
    networks: [ep3bs-internal]

volumes:
  db-data:
networks:
  ep3bs-internal:
  traefik-public:
    external: true
```

### 6.2 Docker Compose — Dev Override (auto-loaded)

```yaml
# docker-compose.override.yml
services:
  app:
    build: { target: dev }
    volumes:
      - .:/var/www/html
      - /var/www/html/vendor
      - /var/www/html/node_modules
    environment:
      APP_ENV: local
      APP_DEBUG: "true"
      MAIL_HOST: mailhog
      MAIL_PORT: 1025
      XDEBUG_MODE: debug,develop
    labels:
      - "traefik.http.routers.ep3bs-dev.rule=Host(`ep3bs.localhost`)"
      - "traefik.http.routers.ep3bs-dev.tls=true"

  vite:
    build: { context: ., dockerfile: docker/Dockerfile, target: dev }
    container_name: ep3bs-vite
    command: ["npm", "run", "dev", "--", "--host", "0.0.0.0"]
    volumes: [.:/var/www/html, /var/www/html/node_modules]
    labels:
      - "traefik.http.routers.ep3bs-vite.rule=Host(`vite.ep3bs.localhost`)"
      - "traefik.http.routers.ep3bs-vite.tls=true"
      - "traefik.http.services.ep3bs-vite.loadbalancer.server.port=5173"
    networks: [traefik-public]

  mailhog:
    image: mailhog/mailhog:latest
    container_name: ep3bs-mailhog
    labels:
      - "traefik.http.routers.ep3bs-mail.rule=Host(`mail.ep3bs.localhost`)"
      - "traefik.http.routers.ep3bs-mail.tls=true"
      - "traefik.http.services.ep3bs-mail.loadbalancer.server.port=8025"
    networks: [ep3bs-internal, traefik-public]

  db:
    ports: ["3306:3306"]

networks:
  traefik-public:
    external: true
```

### 6.3 Docker Compose — Dev on Server (alongside Production)

```yaml
# docker-compose.dev-server.yml
# Usage: docker compose -f docker-compose.dev-server.yml up -d
services:
  app-dev:
    build: { context: ., dockerfile: docker/Dockerfile, target: dev }
    container_name: ep3bs-app-dev
    labels:
      - "traefik.http.routers.ep3bs-dev-server.rule=Host(`dev-booking.example.com`)"
      - "traefik.http.routers.ep3bs-dev-server.tls.certresolver=letsencrypt"
    networks: [ep3bs-dev, traefik-public]
    depends_on: [db-dev]

  db-dev:
    image: mariadb:11
    ports: ["3307:3306"]
    networks: [ep3bs-dev]

  mailhog-dev:
    image: mailhog/mailhog:latest
    labels:
      - "traefik.http.routers.ep3bs-mail-dev.rule=Host(`dev-mail.example.com`)"
    networks: [ep3bs-dev, traefik-public]

networks:
  ep3bs-dev:
  traefik-public:
    external: true
```

### 6.4 Dev URLs

| Service | URL | Purpose |
|---------|-----|---------|
| App | `https://ep3bs.localhost` | Laravel + Inertia |
| Vite HMR | `https://vite.ep3bs.localhost` | Hot Module Replacement |
| Mailhog | `https://mail.ep3bs.localhost` | Email Inbox |
| DB | `localhost:3306` | MySQL-Zugang |

### 6.5 Dockerfile (Multi-Stage)

```dockerfile
FROM php:8.3-fpm-alpine AS base
RUN apk add --no-cache nginx supervisor \
    && docker-php-ext-install pdo_mysql opcache bcmath
WORKDIR /var/www/html

FROM base AS composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-scripts

FROM node:20-alpine AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

FROM base AS production
COPY --from=composer /var/www/html/vendor ./vendor
COPY --from=frontend /app/public/build ./public/build
COPY . .
RUN php artisan config:cache && php artisan route:cache && php artisan view:cache
EXPOSE 80
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]

FROM base AS dev
RUN apk add --no-cache nodejs npm \
    && pecl install xdebug && docker-php-ext-enable xdebug
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
EXPOSE 80
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
```

### 6.6 Vite Config

```typescript
export default defineConfig({
    plugins: [
        laravel({ input: 'resources/js/app.ts', refresh: true }),
        vue({ template: { transformAssetUrls: { base: null, includeAbsolute: false } } }),
    ],
    server: {
        host: '0.0.0.0', port: 5173,
        hmr: { host: 'vite.ep3bs.localhost', protocol: 'wss' },
    },
    resolve: { alias: { '@': '/resources/js' } },
})
```

---

## 7. Internationalisierung (Deutsch + Englisch)

### 7.1 Architecture

| Layer | Solution |
|-------|---------|
| Laravel Backend | `lang/{de,en}/*.php` |
| Vue Frontend | `laravel-vue-i18n` — syncs Laravel translations to Vue |
| PrimeVue | Dynamic locale via `usePrimeVueLocale` composable |
| DB Meta | `locale` column in `*_meta` tables via `HasLocalizedMeta` trait |
| Emails | `mail/{de,en}/*.blade.php` |

### 7.2 Locale Middleware

```php
class SetLocale
{
    public function handle(Request $request, Closure $next): Response {
        $locale = $request->query('lang')
            ?? $request->session()->get('locale')
            ?? $request->getPreferredLanguage(['de', 'en'])
            ?? 'de';

        if (in_array($locale, ['de', 'en'])) {
            app()->setLocale($locale);
            $request->session()->put('locale', $locale);
        }
        return $next($request);
    }
}
```

### 7.3 Language Switcher

```vue
<!-- In Navbar.vue -->
<div class="flex gap-2">
    <button @click="switchLocale('de')" :class="{ 'font-bold': locale === 'de' }">DE</button>
    <button @click="switchLocale('en')" :class="{ 'font-bold': locale === 'en' }">EN</button>
</div>

<script setup>
async function switchLocale(lang) {
    await loadLanguageAsync(lang)
    setPrimeVueLocale(lang)    // sync DatePicker labels, pagination text etc.
    router.visit(location.pathname, { data: { lang }, preserveState: true })
}
</script>
```

---

## 8. Mobile-First & Responsive

### 8.1 Breakpoint Strategy

| Viewport | Calendar | Backend DataTables | Dialogs | Navigation |
|----------|----------|-------------------|---------|------------|
| < 640px | Day view, swipe nav, court tabs | Horizontal scroll, 5 columns visible | Fullscreen | Hamburger → Drawer |
| 640–1024px | 3-day view | Most columns, scroll if needed | 75vw | Collapsible sidebar |
| > 1024px | Full week, all courts | All 13 columns | 500px fixed | Full sidebar |

### 8.2 Touch Optimization

| Element | Spec |
|---------|------|
| TimeSlot | min-height 48px (WCAG 2.5.5), `touch-manipulation` |
| Buttons | min-height 44px, 12px padding |
| Dialog on mobile | `breakpoints="{ '640px': '100vw' }"` |
| DatePicker on mobile | `:touchUI="isMobile"` |
| DataTable on mobile | `scrollable`, `scrollHeight="flex"`, frozen action column |
| Formular inputs | `inputmode="numeric"` for prices, `inputmode="email"` for email |

### 8.3 PWA

```html
<!-- app.blade.php -->
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<meta name="theme-color" content="#1e40af">
<meta name="apple-mobile-web-app-capable" content="yes">
<link rel="manifest" href="/manifest.json">
```
- `public/sw.js` — cache static assets, offline fallback
- `public/manifest.json` — app name, icons, `display: standalone`

### 8.4 useResponsive Composable

```typescript
export function useResponsive() {
    const width = ref(window.innerWidth)
    const isMobile = computed(() => width.value < 640)
    const isTablet = computed(() => width.value >= 640 && width.value < 1024)
    const isDesktop = computed(() => width.value >= 1024)

    onMounted(() => window.addEventListener('resize', () => width.value = window.innerWidth))
    onUnmounted(() => window.removeEventListener('resize', update))

    return { width, isMobile, isTablet, isDesktop }
}
```

---

## 9. Migration Phases

### Phase 0: Project Setup (25h)

- [ ] `laravel new ep3-bs-laravel` with Breeze + Inertia + Vue + TypeScript
- [ ] Docker: multi-stage Dockerfile, 3 compose files (prod / dev / dev-server)
- [ ] Traefik labels: `ep3bs.localhost`, `vite.ep3bs.localhost`, `mail.ep3bs.localhost`
- [ ] Mailhog service for dev
- [ ] Tailwind CSS + PrimeVue 4 (Aura theme) + `laravel-vue-i18n`
- [ ] PrimeVue locale composable (DE + EN)
- [ ] Vite config with Traefik WSS HMR
- [ ] Database migrations from existing schema
- [ ] All 15 Eloquent models + `HasLocalizedMeta` trait
- [ ] Enums (BookingStatus, BillingStatus, UserStatus, SquareStatus)
- [ ] `SetLocale` middleware + translation files DE/EN
- [ ] Migrate ZF2 translation files → Laravel format

### Phase 1: Auth + Layout (40h)

- [ ] Breeze auth (login, register, password reset, activation)
- [ ] Password compatibility: ZF2 bcrypt `$2y$` → Laravel bcrypt (works directly)
- [ ] `UserStatus` logic (disabled → activation, blocked → denied)
- [ ] `AppLayout.vue`:
  - PrimeVue `Menubar` (hamburger on mobile)
  - Language switcher DE/EN
  - PrimeVue `Toast` + `ConfirmDialog`
  - Layout width prop: `default` / `wide` / `full`
  - PWA meta tags in `app.blade.php`
- [ ] `BackendLayout.vue`:
  - PrimeVue `PanelMenu` sidebar (desktop) / `Drawer` (mobile)
  - PrimeVue `Breadcrumb`
- [ ] `EnsureAdmin` middleware
- [ ] Shared: `useLocale.ts`, `useResponsive.ts`, `useTimeOptions.ts`
- [ ] Shared: `StatusBadge.vue` (PrimeVue `Tag`), `PriceDisplay.vue`

### Phase 2: Calendar + Public Booking (130h) — **MVP Critical Path**

- [ ] `CalendarController`: squares, day grid, reservations, events
- [ ] `CalendarGrid.vue` — 3 view modes:
  - Mobile: day view, vertical timeline, court `Tabs`, swipe via `@vueuse/core`
  - Tablet: 3-day view
  - Desktop: week view, all courts
- [ ] Multi-slot booking display: CSS `grid-row` spanning (reactive, no DOM overlays)
- [ ] `TimeSlot.vue`: 48px touch targets, PrimeVue `Tag` for availability
- [ ] `BookingPopup.vue`: PrimeVue `Dialog` (fullscreen on mobile)
- [ ] **Waitlist UI**: occupied-slot view in BookingPopup with "Benachrichtigen wenn frei" `Button`
- [ ] `WaitlistService`: join, leave, check duplicate
- [ ] Port `PricingService` with 4-way member/guest matrix
- [ ] Port `ReservationService` collision detection
- [ ] `BookingController`: customization → confirmation → create
- [ ] `Confirmation.vue`: PrimeVue `InputNumber` (currency), payment method `Select`
- [ ] `Cancellation.vue`: PrimeVue `ConfirmDialog` + budget refund + **waitlist notification trigger**
- [ ] Coupon redemption: code input + validation against `bs_squares_coupons`

### Phase 3: Payment Integration (60h)

- [ ] Install `srmklive/paypal` v3 (PayPal REST API v2)
- [ ] `PayPalService`: createOrder, captureOrder, refundCapture
- [ ] PayPal webhook handler + signature verification
- [ ] PayPal return flow (done + cancelled)
- [ ] `PaymentService` gateway abstraction
- [ ] Budget payment flow (full / partial + gateway)
- [ ] Payment confirmation pages
- [ ] **InvoiceService**: PDF receipt generation (`barryvdh/laravel-dompdf`)
- [ ] Optional: Klarna (direct API)
- [ ] Optional: Stripe via Cashier (only if `STRIPE_ENABLED=true`)
- [ ] Test: PayPal, budget, budget+PayPal

### Phase 4: User Account (40h)

- [ ] Bookings list with status, pagination, **No-Show badge** (`Tag severity="danger"`)
- [ ] **Invoice PDF download**: `Button` icon `pi-file-pdf` per paid booking
- [ ] Bills view: itemized bill
- [ ] Settings: email, password, personal data
- [ ] **Notification settings**: `ToggleSwitch` for booking reminders + waitlist notifications
- [ ] **Waitlist: "Meine Warteliste"** — list of active waitlist entries with leave option
- [ ] Activation flow: email verification

### Phase 5: Backend — Bookings + Users (110h)

**Booking List** (PrimeVue `DataTable`, 13 columns):
- [ ] Progressive column hiding via `useResponsive()` + `v-if` on `Column`
- [ ] Row action state machine:
  - Active → Edit + Cancel (icon-only `Button` with `v-tooltip`)
  - Cancelled + slot free → Edit + Reactivate + Delete
  - Cancelled + slot occupied → Edit + Delete
  - Delete only for admin (not assist)
- [ ] Reactivate: collision check before showing button
- [ ] Per-column filtering via PrimeVue `filterElement` slots
- [ ] Frozen action column on mobile (`frozen alignFrozen="right"`)

**Booking CRUD:**
- [ ] Create (admin): `AutoComplete` for user, `DatePicker`, `Select` for court
- [ ] Edit: 2×2 grid (`grid-cols-1 lg:grid-cols-2`), 4 PrimeVue `Card` sections
- [ ] All time fields: PrimeVue `Select` with full hours 07:00–22:00
- [ ] Delete: separate confirmation, budget refund (check `billing=paid` + `refunded!=true`)
- [ ] Range edit: time range with `Select` dropdowns in `grid-cols-3`
- [ ] Bills editor: `DataTable` `editMode="cell"` (description, price as currency, VAT)
- [ ] Players editor: `DataTable` with add/remove
- [ ] **No-Show**: admin `Button` "No-Show markieren" on past bookings, `Tag severity="danger"` badge
- [ ] **NoShowService**: mark, count per user (rolling 12 months), auto-block threshold
- [ ] **No-Show column** in booking list: PrimeVue `Tag` + configurable thresholds in config

**Recurring Bookings:**
- [ ] `RecurringBookingService`: createSeries, cancelSeries, getSeriesBookings
- [ ] `Recurring.vue`: user `AutoComplete`, court + time `Select`, frequency `Select` (weekly/biweekly), date range `DatePicker`, payment mode (per-occurrence / upfront)
- [ ] **Series preview**: `DataTable` with conflict detection per occurrence (`Tag` green/red)
- [ ] **Series management**: filter booking list by `recurring_group_id`, cancel future occurrences
- [ ] Payment: per-occurrence (individual PayPal orders) or upfront (total sum → single PayPal order)

**User List** (PrimeVue `DataTable`, 7+1 columns):
- [ ] Progressive hiding: ≤1280px hide Notes, ≤1024px hide Email
- [ ] Actions (icon-only): Edit + View Bookings (filtered list)
- [ ] **No-Show count** column: `Tag` with count, color by threshold

**User CRUD:**
- [ ] Create: status `Select`, member `Checkbox`, budget `InputNumber` (currency)
- [ ] Edit: 2-column grid (`grid-cols-1 lg:grid-cols-2`), 2 PrimeVue `Card` sections
- [ ] Edit: **No-Show history** section — count + list of no-show bookings + clear button

### Phase 6: Backend — Events + Config + Dashboard + Export (80h)

- [ ] Event list: PrimeVue `DataTable`
- [ ] Event edit: `DatePicker` + time `Select` (07:00–22:00) + square `Select`
- [ ] Event delete: `ConfirmDialog`
- [ ] Config pages (PrimeVue `Tabs`):
  - Text, Info (PrimeVue `Editor`), Help (`Editor`), Behaviour, Rules, Status Colors
  - OptionService get/set with locale awareness
  - **NEW tab "Notifications"**: reminder hours config, no-show thresholds (warning + block)
- [ ] Square config: 2-column grid, `Editor` for info, time fields as `Select`
- [ ] Pricing config: `DataTable` inline editing, date_end expiry warning
- [ ] Products: CRUD (locale-aware)
- [ ] Coupons: `DataTable` with inline editing (code, type, amount, dates, square)
- [ ] Setup wizard: PrimeVue `Stepper` (4 steps), `EnsureSetupComplete` middleware
- [ ] Feature flags in config: Stripe, Klarna
- [ ] **Dashboard KPIs** (`Dashboard.vue`):
  - 4 KPI cards: bookings today, revenue month, occupancy %, no-show %
  - Revenue trend line chart (PrimeVue `Chart`, last 12 months)
  - Occupancy heatmap (stacked bar: day × hour)
  - Top users table, pending payments count, waitlist count
- [ ] **Waitlist admin** (`Waitlist/Index.vue`): `DataTable` with all active entries, manual notify/remove
- [ ] **Export page** (`Export/Index.vue`):
  - Bookings CSV/PDF (date range + filters)
  - Revenue CSV + PDF report (date range)
  - User list CSV
  - Occupancy report CSV (court × day × time)
- [ ] `ExportService`: CSV streaming, PDF via DomPDF

### Phase 7: Email Notifications (35h)

- [ ] `BookingConfirmation` mailable:
  - Booking details (court, date, time, quantity), player names
  - Itemized bill (description + price + VAT per line)
  - Payment method used + budget deduction amount
  - **Guest payment instructions**: shown ONLY when booking is NOT paid by budget or gateway
  - **PDF receipt attachment** (for paid bookings)
  - **All meta via `BookingService::create($meta)` — must be pre-set before event**
- [ ] `BookingCancellation` (refund info)
- [ ] **`BookingReminder`** — sent X hours before booking (configurable in backend, default 24h)
  - Includes: date, time, court, player names
  - Opt-out via user settings
- [ ] **`WaitlistNotification`** — "Dein gewünschter Platz ist jetzt frei!"
  - Includes: court, date, time, direct booking link
  - Auto-expires after 2 hours if not booked
- [ ] **`NoShowWarning`** — sent when user hits warning threshold
  - Includes: no-show count, block threshold, appeal info
- [ ] `ActivationEmail`
- [ ] Locale-aware templates: `mail/{de,en}/*.blade.php`
- [ ] Test via Mailhog

### Phase 8: Scheduling + PWA (15h)

- [ ] `CleanupUnpaidBookings`: `directpay=true AND status_billing=pending AND >3h old`
- [ ] **`SendBookingReminders`**: daily at 18:00, sends for next-day bookings
- [ ] **Waitlist expiry**: clean up waitlist entries for past dates
- [ ] Register all in `routes/console.php` via `Schedule::command()`
- [ ] PWA: `sw.js`, `manifest.json`, offline fallback

### Phase 9: Testing + QA (70h)

- [ ] Unit: PricingService (all 4 member/guest combos), BudgetService (deduct + refund on cancel AND delete), ReservationService, CouponService
- [ ] Unit: **RecurringBookingService** (create series, conflict detection, cancel series, partial cancellation)
- [ ] Unit: **WaitlistService** (join, leave, notify on cancellation, duplicate prevention)
- [ ] Unit: **NoShowService** (mark, count, threshold warning, auto-block)
- [ ] Unit: **InvoiceService** (PDF generation, correct VAT, correct totals)
- [ ] Feature: booking flow, PayPal sandbox, cancellation + budget refund
- [ ] Feature: **recurring booking** end-to-end (create series → pay → cancel future)
- [ ] Feature: **waitlist** end-to-end (join → slot cancellation → notification → book)
- [ ] Feature: backend CRUD, setup wizard
- [ ] Feature: calendar multi-slot bookings display correctly (2h, 3h bookings span multiple rows)
- [ ] Feature: guest payment instructions shown correctly (only when no budget/gateway payment)
- [ ] Feature: **export** CSV + PDF (bookings, revenue, users)
- [ ] Feature: **dashboard** KPIs match actual data
- [ ] i18n: all pages DE + EN, no missing translation keys
- [ ] Cypress E2E — **3 viewports, full mobile flows**:
  - iPhone SE (375×667): calendar day view, swipe left/right, court tab switching, open booking dialog (fullscreen), complete booking with PayPal, cancel booking, view detail popup, **join waitlist on occupied slot**, **download invoice PDF**
  - iPad (768×1024): 3-day view, backend navigation via drawer, DataTable horizontal scroll
  - Desktop (1920×1080): week view, all courts visible, DataTable full columns, inline editing, **recurring booking creation, export page**
- [ ] Touch-target audit: all clickable elements ≥ 48px on mobile
- [ ] PayPal sandbox end-to-end (create → pay → capture → webhook → status update)
- [ ] Optional: Stripe test mode (if enabled)
- [ ] Email rendering in Mailhog: DE + EN, verify guest payment instructions, **reminder email, waitlist notification, no-show warning**

### Phase 10: Deployment (30h)

- [ ] Verify Eloquent against production data
- [ ] Password compatibility test
- [ ] Meta-table read/write (incl. locale column)
- [ ] Traefik production labels + Let's Encrypt
- [ ] Blue-green: old + new against same DB
- [ ] Traefik weighted routing: 10% → 50% → 100%
- [ ] Rollback: Traefik label switch
- [ ] Monitoring: Laravel Telescope or Sentry
- [ ] Scheduler container verified

---

## 10. Database Strategy

### 10.1 Zero-downtime

Existing `bs_*` tables **unchanged**. Eloquent maps directly → parallel running possible.

### 10.2 Optional Schema Additions

```sql
ALTER TABLE bs_users ADD COLUMN updated_at DATETIME DEFAULT NULL;
ALTER TABLE bs_bookings ADD COLUMN updated_at DATETIME DEFAULT NULL;
-- Stripe columns only if STRIPE_ENABLED=true (added via migration on demand)

-- NEW: Waitlist table (lightweight, no meta pattern needed)
CREATE TABLE bs_waitlist (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uid INT NOT NULL,
    sid INT DEFAULT NULL,          -- court (NULL = any court)
    date DATE NOT NULL,
    time_start TIME NOT NULL,
    time_end TIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notified_at DATETIME DEFAULT NULL,
    FOREIGN KEY (uid) REFERENCES bs_users(uid) ON DELETE CASCADE,
    FOREIGN KEY (sid) REFERENCES bs_squares(sid) ON DELETE CASCADE,
    INDEX idx_waitlist_slot (sid, date, time_start, time_end)
);
```

**Recurring bookings** and **No-Show tracking** use the existing meta-table pattern (no schema changes):
- `bs_bookings_meta`: `recurring_group_id` (UUID), `recurring_frequency`, `no_show` (true/false)
- No new tables needed — keeps zero-downtime parallel running possible

### 10.3 Password Compatibility

ZF2 `password_hash(PASSWORD_BCRYPT)` → `$2y$` hashes. Laravel `Hash::make()` → same. **No migration needed.**

---

## 11. Risk Assessment

| Risk | Impact | Mitigation |
|------|--------|------------|
| Pricing logic bug (4-way matrix) | High | Unit test all member/guest combos, parallel running |
| Pricing `date_end` expired → $total=0 | High | Admin warning when rules expire within 30 days |
| Email meta timing → incomplete emails | High | All meta via `create($meta)`, unit test email content |
| Recurring booking conflicts undetected | High | Per-occurrence collision check, preview before creation, conflict list in response |
| PayPal API changes | Medium | Stable v2 Orders API, webhook monitoring |
| Budget refund missed on delete path | Medium | Unit test cancel AND delete refund paths separately |
| Calendar mobile UX | Medium | Prototype mobile view early (Phase 2), real device testing |
| Cleanup job criteria mismatch | Medium | Match exact MySQL event criteria, log every action |
| Waitlist race condition (two users book freed slot) | Medium | First-come-first-served via DB transaction, second user stays on waitlist |
| No-Show false positive (user was there) | Medium | Admin-only action, undo button, no auto-marking |
| i18n translation gaps | Medium | Full audit DE + EN before go-live |
| Password incompatibility | Medium | Test with production hashes |
| Calendar multi-slot display bugs | Medium | Vue reactive grid-row — no duplication bug possible. Test with 1h/2h/3h bookings |
| PDF generation performance (DomPDF) | Low | Generate on demand, cache recent invoices |
| Meta-table N+1 queries | Low | Eager loading `with('meta')`, OptionService cache |

---

## 12. Go-Live Checklist

- [ ] E2E tests passing (Desktop + Mobile + Tablet viewports)
- [ ] **Mobile calendar**: day view, swipe navigation, court tabs, multi-slot bookings display, booking dialog fullscreen, PayPal payment, **waitlist join on occupied slot** — all on iPhone SE + iPad
- [ ] **Mobile backend**: Drawer navigation, DataTable horizontal scroll, booking/user edit forms usable on small screens
- [ ] i18n: all pages DE + EN complete, no missing keys
- [ ] PayPal: sandbox → live credentials, webhook verified, refund tested
- [ ] Optional: Stripe test mode (if enabled)
- [ ] Emails: tested via Mailhog → real SMTP (DE + EN), **reminder + waitlist + no-show warning**
- [ ] Production DB: read-only test from new app
- [ ] Passwords: login with production hashes
- [ ] Budget: deduct, refund on cancel, refund on delete
- [ ] Pricing: verified against current production, `date_end` covers current dates
- [ ] Coupons: %, fixed, expired, invalid
- [ ] **Recurring bookings**: create series, cancel future, series payment (per-occurrence + upfront)
- [ ] **Waitlist**: join, notification on cancellation, direct booking link, expiry
- [ ] **No-Show**: mark, count, warning email, auto-block threshold
- [ ] **Export**: CSV (bookings, users, revenue), PDF report
- [ ] **Dashboard**: KPIs match actual data, charts render correctly
- [ ] **Invoice PDF**: download from user bookings, correct VAT + totals
- [ ] Scheduler: CleanupUnpaidBookings + **SendBookingReminders** + Waitlist expiry running
- [ ] Traefik: production labels, TLS, HTTP→HTTPS redirect, health check
- [ ] Mobile: iPhone SE, iPhone 14, iPad, Android (Chrome + Safari)
- [ ] Touch targets: all clickable ≥ 48px, no 300ms click delay (`touch-manipulation`)
- [ ] Rollback procedure tested (Traefik label switch)
- [ ] DNS TTL lowered 24h before
- [ ] Team notified

# Migration Plan: Laravel 11 + Inertia.js + Vue 3

## Executive Summary

Complete rewrite of the ep3-bs booking system from **Zend Framework 2 (PHP 8.1)** to **Laravel 11 (PHP 8.3) + Inertia.js + Vue 3 + TypeScript**. The existing MySQL database schema remains intact; Laravel Eloquent models map to the existing tables. The frontend moves from server-rendered `.phtml` + jQuery to Vue 3 SFCs with Inertia.js for SPA-like navigation without a REST API.

**Estimated effort:** 500–700 hours (solo developer)
**Target stack:**
- Backend: Laravel 11, PHP 8.3, Eloquent ORM
- Frontend: Vue 3 + TypeScript, Inertia.js, Tailwind CSS
- Payments: Laravel Cashier (Stripe) + custom PayPal/Klarna integration
- Auth: Laravel Breeze (session-based)
- Build: Vite
- Docker: existing setup adapted

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
- `member` column on `bs_squares_pricing` (0=non-member, 1=member) added post-schema
- Nullable `sid` on pricing/products/coupons/events means "applies to ALL courts"
- Scheduled MySQL event: auto-delete unpaid direct-pay bookings every 15 min (> 3h old)

### 1.2 Routes & Controllers (71 Actions)

| Module | Controllers | Routes | Purpose |
|--------|------------|--------|---------|
| Frontend | 1 | 1 | Public calendar home |
| Calendar | 1 | 1 | Calendar grid rendering |
| Square | 2 | 8 | Court availability + booking flow (customization → confirmation → payment) |
| User | 2 | 11 | Login, registration, activation, account, bookings, settings |
| Payment | 1 | 3 | Payum integration (confirm, done, webhook) |
| Backend | 6 | 39 | Admin: users, bookings, events, config, squares, door codes |
| Event | 1 | 1 | Public event popup |
| Service | 1 | 3 | Info, help, status pages |
| Setup | 1 | 5 | Installation wizard |
| **Total** | **16** | **72** | |

### 1.3 Business Logic Hotspots

1. **Pricing engine** — `SquarePricingManager::getFinalPricingInRange()`: matches booking date/time against pricing rules by priority, considers member/non-member/guest-with-50%-discount
2. **Budget system** — prepaid balance in `bs_users_meta`, deducted on booking, refunded on cancel/delete, partial budget + gateway payments
3. **Payment flow** — Payum tokens → PayPal EC / Stripe (SCA, webhooks) / Klarna
4. **Collision detection** — `ReservationManager::getInRange()` checks overlapping time slots
5. **Email notifications** — Event-driven during `createSingle()`, includes billing details
6. **Door codes** — Loxone MiniServer integration (optional feature)

---

## 2. Target Architecture

### 2.1 Tech Stack

| Layer | Technology | Why |
|-------|-----------|-----|
| Backend framework | Laravel 11 | Modern PHP, Eloquent ORM, excellent ecosystem, long-term support |
| PHP version | 8.3 | Current stable, required by Laravel 11 |
| Frontend framework | Vue 3 + TypeScript | Reactive components, good DX, Inertia.js native support |
| SPA bridge | Inertia.js | SPA feel without building a REST API; shares auth/sessions with Laravel |
| CSS | Tailwind CSS 3 | Utility-first, replaces Bootstrap 5, smaller bundle |
| Build tool | Vite | Laravel default, fast HMR, TypeScript support |
| Auth | Laravel Breeze (Inertia/Vue) | Session-based auth, registration, password reset — scaffolded |
| Payments (Stripe) | Laravel Cashier + Stripe SDK | SCA, PaymentIntents, webhooks — built for Laravel |
| Payments (PayPal) | `srmklive/paypal` | PayPal REST API v2, Laravel integration |
| Payments (Klarna) | Klarna SDK + custom service | Direct API integration |
| Emails | Laravel Mail + Mailables | Blade/Markdown templates, queue support |
| Scheduling | Laravel Task Scheduling | Replaces MySQL scheduled event (unpaid booking cleanup) |
| Testing | PHPUnit + Pest + Cypress | Unit, feature, E2E |
| Docker | existing setup adapted | PHP 8.3-fpm + Nginx (instead of Apache) |

### 2.2 Directory Structure

```
ep3-bs-laravel/
├── app/
│   ├── Console/
│   │   └── Commands/
│   │       └── CleanupUnpaidBookings.php      # replaces MySQL event
│   ├── Enums/
│   │   ├── BookingStatus.php                   # single, subscription, cancelled
│   │   ├── BillingStatus.php                   # pending, paid, cancelled, uncollectable
│   │   ├── UserStatus.php                      # placeholder, deleted, blocked, disabled, enabled, assist, admin
│   │   └── SquareStatus.php                    # disabled, readonly, enabled
│   ├── Events/
│   │   └── BookingCreated.php                  # replaces ZF2 event system
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── CalendarController.php
│   │   │   ├── BookingController.php           # public booking flow
│   │   │   ├── PaymentController.php           # webhooks + confirm/done
│   │   │   ├── Auth/                           # Breeze scaffolded
│   │   │   ├── User/
│   │   │   │   ├── AccountController.php       # bookings, settings, bills
│   │   │   │   └── ProfileController.php
│   │   │   └── Backend/
│   │   │       ├── DashboardController.php
│   │   │       ├── BookingController.php
│   │   │       ├── UserController.php
│   │   │       ├── EventController.php
│   │   │       ├── ConfigController.php
│   │   │       ├── SquareConfigController.php
│   │   │       └── SquareControlController.php
│   │   ├── Middleware/
│   │   │   ├── EnsureAdmin.php
│   │   │   └── EnsureSetupComplete.php
│   │   └── Requests/                           # form request validation
│   ├── Listeners/
│   │   └── SendBookingNotification.php
│   ├── Mail/
│   │   ├── BookingConfirmation.php
│   │   └── BookingCancellation.php
│   ├── Models/
│   │   ├── User.php
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
│   ├── Services/
│   │   ├── PricingService.php                  # replaces SquarePricingManager
│   │   ├── BookingService.php                  # replaces BookingService + BookingManager
│   │   ├── BudgetService.php                   # budget deduction, refund
│   │   ├── ReservationService.php              # collision detection
│   │   ├── PaymentService.php                  # gateway abstraction
│   │   └── DoorCodeService.php                 # Loxone integration
│   └── Policies/
│       ├── BookingPolicy.php
│       └── UserPolicy.php
├── database/
│   ├── migrations/                             # initial: create from existing schema
│   └── seeders/
│       └── OptionSeeder.php                    # default config values
├── resources/
│   ├── js/
│   │   ├── app.ts                              # Vue 3 + Inertia bootstrap
│   │   ├── types/                              # TypeScript interfaces
│   │   │   ├── models.d.ts                     # User, Booking, Square, etc.
│   │   │   └── inertia.d.ts
│   │   ├── Components/                         # reusable Vue components
│   │   │   ├── Calendar/
│   │   │   │   ├── CalendarGrid.vue
│   │   │   │   ├── TimeSlot.vue
│   │   │   │   └── BookingPopup.vue
│   │   │   ├── Forms/
│   │   │   │   ├── InputField.vue
│   │   │   │   ├── SelectField.vue
│   │   │   │   └── DatePicker.vue
│   │   │   ├── Layout/
│   │   │   │   ├── AppLayout.vue
│   │   │   │   ├── BackendLayout.vue
│   │   │   │   └── Navbar.vue
│   │   │   └── UI/
│   │   │       ├── Badge.vue
│   │   │       ├── DataTable.vue
│   │   │       └── Modal.vue
│   │   ├── Pages/
│   │   │   ├── Calendar/
│   │   │   │   └── Index.vue                   # main calendar page
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
│   │   │   │   └── Settings.vue
│   │   │   └── Backend/
│   │   │       ├── Dashboard.vue
│   │   │       ├── Bookings/
│   │   │       │   ├── Index.vue
│   │   │       │   ├── Edit.vue
│   │   │       │   ├── Delete.vue
│   │   │       │   ├── Bills.vue
│   │   │       │   └── Players.vue
│   │   │       ├── Users/
│   │   │       │   ├── Index.vue
│   │   │       │   └── Edit.vue
│   │   │       ├── Events/
│   │   │       │   ├── Index.vue
│   │   │       │   └── Edit.vue
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
│   │   │           └── ProductEdit.vue
│   │   └── Composables/
│   │       ├── useCalendar.ts
│   │       ├── usePricing.ts
│   │       └── useBooking.ts
│   ├── css/
│   │   └── app.css                             # Tailwind imports + custom styles
│   └── views/
│       ├── app.blade.php                       # Inertia root template
│       └── mail/                               # email templates
│           ├── booking-confirmation.blade.php
│           └── booking-cancellation.blade.php
├── routes/
│   ├── web.php                                 # all routes (Inertia)
│   └── api.php                                 # webhooks only
├── config/
│   └── ep3bs.php                               # app-specific config
├── public/
│   ├── docs-client/upload/                     # file uploads (preserved)
│   └── imgs-client/upload/                     # image uploads (preserved)
├── docker/
│   ├── Dockerfile                              # PHP 8.3-fpm + Nginx
│   ├── nginx.conf
│   └── supervisord.conf
├── docker-compose.yml
├── docker-compose.override.yml                 # local dev
├── tailwind.config.js
├── vite.config.ts
├── tsconfig.json
└── package.json
```

### 2.3 Eloquent Models → Existing Tables

All models map to existing `bs_*` tables without schema changes:

```php
// app/Models/User.php
class User extends Authenticatable {
    protected $table = 'bs_users';
    protected $primaryKey = 'uid';
    public $timestamps = false; // existing table has 'created' but no 'updated_at'

    public function meta() { return $this->hasMany(UserMeta::class, 'uid', 'uid'); }
    public function bookings() { return $this->hasMany(Booking::class, 'uid', 'uid'); }

    // Accessor for meta key-value
    public function getMeta(string $key, $default = null): mixed {
        return $this->meta->firstWhere('key', $key)?->value ?? $default;
    }
    public function setMeta(string $key, string $value): void {
        $this->meta()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}

// app/Models/Booking.php
class Booking extends Model {
    protected $table = 'bs_bookings';
    protected $primaryKey = 'bid';
    public $timestamps = false;

    public function user() { return $this->belongsTo(User::class, 'uid', 'uid'); }
    public function square() { return $this->belongsTo(Square::class, 'sid', 'sid'); }
    public function bills() { return $this->hasMany(BookingBill::class, 'bid', 'bid'); }
    public function meta() { return $this->hasMany(BookingMeta::class, 'bid', 'bid'); }
    public function reservations() { return $this->hasMany(Reservation::class, 'bid', 'bid'); }
}

// app/Models/Square.php
class Square extends Model {
    protected $table = 'bs_squares';
    protected $primaryKey = 'sid';
    public $timestamps = false;

    public function meta() { return $this->hasMany(SquareMeta::class, 'sid', 'sid'); }
    public function pricingRules() { return $this->hasMany(SquarePricing::class, 'sid', 'sid'); }
    public function products() { return $this->hasMany(SquareProduct::class, 'sid', 'sid'); }
    public function events() { return $this->hasMany(Event::class, 'sid', 'sid'); }
    public function bookings() { return $this->hasMany(Booking::class, 'sid', 'sid'); }
}

// app/Models/SquarePricing.php
class SquarePricing extends Model {
    protected $table = 'bs_squares_pricing';
    protected $primaryKey = 'spid';
    public $timestamps = false;

    public function square() { return $this->belongsTo(Square::class, 'sid', 'sid'); }
    // sid=NULL means applies to ALL squares
}
```

### 2.4 Route Mapping (ZF2 → Laravel)

```php
// routes/web.php

// --- Public ---
Route::get('/', [CalendarController::class, 'index'])->name('calendar');
Route::get('/calendar', [CalendarController::class, 'grid'])->name('calendar.grid');
Route::get('/square/availability', [CalendarController::class, 'availability'])->name('square.availability'); // JSON
Route::get('/event/{event}', [EventController::class, 'show'])->name('event.show');

// --- Booking Flow ---
Route::prefix('booking')->name('booking.')->group(function () {
    Route::get('/customization', [BookingController::class, 'customization'])->name('customization');
    Route::post('/customization', [BookingController::class, 'storeCustomization']);
    Route::get('/confirmation', [BookingController::class, 'confirmation'])->name('confirmation');
    Route::post('/confirmation', [BookingController::class, 'storeConfirmation']);
    Route::get('/cancellation/{booking}', [BookingController::class, 'cancellation'])->name('cancellation');
    Route::post('/cancellation/{booking}', [BookingController::class, 'confirmCancellation']);
});

// --- Payment Callbacks ---
Route::prefix('payment')->name('payment.')->group(function () {
    Route::get('/confirm', [PaymentController::class, 'confirm'])->name('confirm');  // Stripe SCA
    Route::get('/done', [PaymentController::class, 'done'])->name('done');
    Route::post('/webhook/stripe', [PaymentController::class, 'stripeWebhook'])->name('webhook.stripe');
    Route::post('/webhook/paypal', [PaymentController::class, 'paypalWebhook'])->name('webhook.paypal');
});

// --- Auth (Breeze scaffolded) ---
require __DIR__.'/auth.php';

// --- Authenticated User ---
Route::middleware('auth')->prefix('user')->name('user.')->group(function () {
    Route::get('/bookings', [AccountController::class, 'bookings'])->name('bookings');
    Route::get('/bookings/{booking}/bills', [AccountController::class, 'bills'])->name('bills');
    Route::get('/settings', [AccountController::class, 'settings'])->name('settings');
    Route::post('/settings', [AccountController::class, 'updateSettings']);
});

// --- Service Pages ---
Route::get('/service/info', [ServiceController::class, 'info'])->name('service.info');
Route::get('/service/help', [ServiceController::class, 'help'])->name('service.help');
Route::get('/service/status', [ServiceController::class, 'status'])->name('service.status');

// --- Backend (Admin) ---
Route::middleware(['auth', 'admin'])->prefix('backend')->name('backend.')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // Users
    Route::resource('users', Backend\UserController::class)->except(['show']);
    Route::get('/users/search', [Backend\UserController::class, 'search'])->name('users.search'); // JSON
    Route::get('/users/stats', [Backend\UserController::class, 'stats'])->name('users.stats');

    // Bookings
    Route::resource('bookings', Backend\BookingController::class)->except(['show']);
    Route::get('/bookings/{booking}/bills', [Backend\BookingController::class, 'bills'])->name('bookings.bills');
    Route::post('/bookings/{booking}/bills', [Backend\BookingController::class, 'updateBills']);
    Route::get('/bookings/{booking}/players', [Backend\BookingController::class, 'players'])->name('bookings.players');
    Route::post('/bookings/{booking}/reactivate', [Backend\BookingController::class, 'reactivate'])->name('bookings.reactivate');
    Route::get('/bookings/stats', [Backend\BookingController::class, 'stats'])->name('bookings.stats');

    // Events
    Route::resource('events', Backend\EventController::class)->except(['show']);
    Route::get('/events/stats', [Backend\EventController::class, 'stats'])->name('events.stats');

    // Config
    Route::prefix('config')->name('config.')->group(function () {
        Route::get('/', [Backend\ConfigController::class, 'index'])->name('index');
        Route::match(['get', 'post'], '/text', [Backend\ConfigController::class, 'text'])->name('text');
        Route::match(['get', 'post'], '/info', [Backend\ConfigController::class, 'info'])->name('info');
        Route::match(['get', 'post'], '/help', [Backend\ConfigController::class, 'help'])->name('help');
        Route::match(['get', 'post'], '/behaviour', [Backend\ConfigController::class, 'behaviour'])->name('behaviour');
        Route::match(['get', 'post'], '/behaviour/rules', [Backend\ConfigController::class, 'behaviourRules'])->name('behaviour.rules');
        Route::match(['get', 'post'], '/behaviour/status-colors', [Backend\ConfigController::class, 'behaviourStatusColors'])->name('behaviour.status-colors');
    });

    // Square Config
    Route::prefix('squares')->name('squares.')->group(function () {
        Route::get('/', [Backend\SquareConfigController::class, 'index'])->name('index');
        Route::match(['get', 'post'], '/{square}/edit', [Backend\SquareConfigController::class, 'edit'])->name('edit');
        Route::match(['get', 'post'], '/{square}/info', [Backend\SquareConfigController::class, 'info'])->name('info');
        Route::get('/pricing', [Backend\SquareConfigController::class, 'pricing'])->name('pricing');
        Route::post('/pricing', [Backend\SquareConfigController::class, 'savePricing']);
        Route::delete('/{square}', [Backend\SquareConfigController::class, 'destroy'])->name('destroy');
        // Products
        Route::resource('products', Backend\ProductController::class)->except(['show']);
    });

    // Door Codes
    Route::prefix('doorcodes')->name('doorcodes.')->group(function () {
        Route::get('/', [Backend\SquareControlController::class, 'index'])->name('index');
        Route::delete('/{code}', [Backend\SquareControlController::class, 'destroy'])->name('destroy');
        Route::post('/cleanup', [Backend\SquareControlController::class, 'cleanup'])->name('cleanup');
    });
});
```

---

## 3. Service Layer Mapping

### 3.1 ZF2 → Laravel Service Mapping

| ZF2 Component | Laravel Equivalent | Notes |
|--------------|-------------------|-------|
| `SquarePricingManager::getFinalPricingInRange()` | `PricingService::calculatePrice()` | Core pricing engine — most complex logic |
| `BookingService::createSingle()` | `BookingService::create()` | Booking creation + event dispatch |
| `BookingManager` (CRUD) | `Booking` Eloquent model | Direct Eloquent operations |
| `ReservationManager::getInRange()` | `ReservationService::checkCollision()` | Collision detection |
| `BillManager` | `BookingBill` Eloquent model | Eloquent relationships |
| `UserManager` | `User` Eloquent model + Breeze | Auth scaffolded by Breeze |
| `SquareManager` | `Square` Eloquent model | |
| `EventManager` | `Event` Eloquent model | |
| `OptionManager` | `Option` model + config cache | Settings stored in DB |
| `MailService` | Laravel Mail + Mailables | Queue support built-in |
| `BookingStatusService` | `BillingStatus` enum + helpers | |
| `SquareValidator` | Form Request validation | Laravel validation rules |
| `NotificationListener` | `SendBookingNotification` listener | Laravel Events |
| `PaymentService` (Payum) | `PaymentService` (Cashier + custom) | Stripe via Cashier, PayPal/Klarna custom |
| `SquareControlService` | `DoorCodeService` | Loxone integration |

### 3.2 PricingService (Critical Business Logic)

```php
// app/Services/PricingService.php
class PricingService
{
    /**
     * Calculate total price for a booking.
     *
     * Current ZF2 logic:
     * 1. Get all pricing rules matching: date range, day range, time range, square
     * 2. Sort by priority (highest first)
     * 3. For each time block in the reservation:
     *    - Find first matching rule for member/non-member
     *    - Calculate: price * (time / per_time_block) * quantity
     * 4. Guest with member: non-member price / 2
     *
     * @return array{total: int, bills: array, member_total: int, nonmember_total: int}
     */
    public function calculatePrice(
        Square $square,
        Carbon $dateStart,
        Carbon $dateEnd,
        string $timeStart,
        string $timeEnd,
        bool $isMember,
        bool $isGuest = false,
        int $quantity = 1
    ): array {
        // ... port from SquarePricingManager::getFinalPricingInRange()
    }
}
```

### 3.3 BudgetService

```php
// app/Services/BudgetService.php
class BudgetService
{
    public function getBalance(User $user): int; // in cents
    public function deduct(User $user, int $amount, Booking $booking): void;
    public function refund(User $user, Booking $booking): void;
    public function canPayWithBudget(User $user, int $total, bool $isGuest, bool $isMember): bool;
}
```

---

## 4. Frontend Architecture (Vue 3 + Inertia)

### 4.1 Inertia.js Flow

Instead of building a REST API:
1. Laravel controller returns `Inertia::render('Page/Name', $props)`
2. Inertia sends props to Vue component as reactive data
3. Forms use `useForm()` composable — submits via Inertia (no fetch/axios needed)
4. Navigation via `<Link>` component — SPA-like page transitions

### 4.2 Key Vue Pages

**Calendar (most complex frontend component):**
```vue
<!-- resources/js/Pages/Calendar/Index.vue -->
<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import CalendarGrid from '@/Components/Calendar/CalendarGrid.vue'
import BookingPopup from '@/Components/Calendar/BookingPopup.vue'

interface Props {
    squares: Square[]
    days: string[]  // date strings
    reservations: Record<string, Reservation[]>  // grouped by date+square
    events: Event[]
}
const props = defineProps<Props>()

const selectedSlot = ref<{square: Square, date: string, time: string} | null>(null)

function onSlotClick(square: Square, date: string, time: string) {
    selectedSlot.value = { square, date, time }
}
</script>
```

**Backend Booking List (DataTable with sorting/filtering):**
```vue
<!-- resources/js/Pages/Backend/Bookings/Index.vue -->
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import DataTable from '@/Components/UI/DataTable.vue'

interface Props {
    bookings: Paginated<BookingRow>
    filters: { search?: string, dateFrom?: string, dateTo?: string }
}
const props = defineProps<Props>()

const filterForm = useForm({
    search: props.filters.search ?? '',
    dateFrom: props.filters.dateFrom ?? '',
    dateTo: props.filters.dateTo ?? '',
})
</script>
```

### 4.3 Replacing jQuery

| jQuery Feature | Vue 3 Replacement |
|---------------|-------------------|
| `$.ajax()` / `$.get()` | Inertia `router.visit()` / `useForm().post()` |
| jQuery UI Datepicker | `@vuepic/vue-datepicker` or Flatpickr |
| jQuery UI Position | CSS `position: fixed` + Vue teleport |
| `$(selector).on('click')` | `@click` event handler |
| `.show()` / `.hide()` | `v-if` / `v-show` |
| `$(selector).closest()` | Vue component props / `provide/inject` |
| TinyMCE jQuery plugin | `@tinymce/tinymce-vue` |
| `$.position()` centering | CSS Flexbox/Grid |

---

## 5. Payment Migration

### 5.1 Stripe (via Laravel Cashier)

```php
// Current: Payum + custom Stripe gateway
// New: Laravel Cashier for Stripe

// app/Services/PaymentService.php
class PaymentService
{
    public function createStripePaymentIntent(Booking $booking, int $amount): PaymentIntent
    {
        return $booking->user->createPayment($amount, [
            'payment_method_types' => ['card', 'sepa_debit', 'giropay', 'ideal'],
            'metadata' => [
                'booking_id' => $booking->bid,
            ],
        ]);
    }
}

// Webhook: config/cashier.php → Stripe webhook secret
// Route: Route::post('/payment/webhook/stripe', [PaymentController::class, 'stripeWebhook']);
```

### 5.2 PayPal

```php
// Current: Payum PayPal Express Checkout
// New: srmklive/paypal (PayPal REST API v2)

// app/Services/PayPalService.php
class PayPalService
{
    public function createOrder(Booking $booking, int $amount): array; // returns approval URL
    public function captureOrder(string $orderId): array;             // captures payment
}
```

### 5.3 Budget + Payment Combination

Logic preserved from current system:
1. If `budget >= total` → budget-only payment, no gateway
2. If `budget > 0 && budget < total` → deduct budget, charge remaining via gateway
3. Budget deduction: immediate for budget-only; after gateway success for partial
4. Refund on cancel: restore full bill total to budget

---

## 6. Migration Phases

### Phase 0: Project Setup (20h)

- [ ] `laravel new ep3-bs-laravel` with Breeze + Inertia + Vue + TypeScript
- [ ] Configure Docker (PHP 8.3-fpm, Nginx, MariaDB, Mailhog)
- [ ] Configure `.env` for existing database
- [ ] Install Tailwind CSS, configure `tailwind.config.js`
- [ ] Set up Vite config
- [ ] Create database migrations from existing schema (use `--path` to match `bs_*` tables)
- [ ] Create all 15 Eloquent models with relationships
- [ ] Create Enums (BookingStatus, BillingStatus, UserStatus, SquareStatus)
- [ ] Configure i18n (Laravel localization, migrate `de-DE/*.php` translation files)

### Phase 1: Auth + Layout (40h)

- [ ] Configure Breeze auth (login, register, password reset, activation)
- [ ] Map `bs_users.pw` (bcrypt) → Laravel auth (bcrypt compatible, should work directly)
- [ ] Implement `UserStatus` logic (disabled → needs activation, blocked → denied)
- [ ] Create `AppLayout.vue` (navbar, footer, mobile responsive)
- [ ] Create `BackendLayout.vue` (admin sidebar/nav)
- [ ] Implement `EnsureAdmin` middleware (check `status` in [admin, assist])
- [ ] Create shared Vue components (InputField, SelectField, Badge, etc.)

### Phase 2: Calendar + Public Booking (120h) — **MVP Critical Path**

- [ ] **CalendarController**: fetch squares, generate day grid, load reservations/events
- [ ] **CalendarGrid.vue**: responsive calendar with time slots (replace jQuery calendar)
- [ ] **TimeSlot.vue**: clickable slots with availability colors
- [ ] **BookingPopup.vue**: replaces squarebox (customization form in modal)
- [ ] **Port PricingService**: `getFinalPricingInRange()` → `calculatePrice()`
- [ ] **Port ReservationService**: collision detection
- [ ] **BookingController**: customization → confirmation → create booking
- [ ] **Confirmation.vue**: price display, payment method selection, budget option
- [ ] **Cancellation.vue**: cancel booking with budget refund

### Phase 3: Payment Integration (80h)

- [ ] Install + configure Laravel Cashier (Stripe)
- [ ] Implement `PaymentService` (Stripe PaymentIntents, SCA)
- [ ] Implement Stripe webhook handler
- [ ] Implement PayPal integration (`srmklive/paypal`)
- [ ] Implement Klarna integration (direct API)
- [ ] Budget payment flow (full + partial)
- [ ] Payment confirmation pages
- [ ] Test all payment paths (card, SEPA, PayPal, budget, budget+card)

### Phase 4: User Account Area (30h)

- [ ] **Bookings list**: user's bookings with status, pagination
- [ ] **Bills view**: itemized bill for a booking
- [ ] **Settings**: email change, password change, personal data
- [ ] **Activation flow**: email verification, resend

### Phase 5: Backend — Bookings + Users (100h)

- [ ] **Booking list** (`DataTable.vue`): sortable columns, filters, responsive hiding
- [ ] **Booking edit**: date/time/square/user/quantity form
- [ ] **Booking delete/cancel**: confirmation, budget refund
- [ ] **Booking reactivate**: collision check, status reset
- [ ] **Bills editor**: inline bill item editing
- [ ] **Players editor**: player names display/edit
- [ ] **User list**: DataTable with search, filters
- [ ] **User edit**: account, personal data, member flag, budget
- [ ] **User search**: AJAX autocomplete (JSON endpoint)
- [ ] **Statistics pages**: booking/user/event stats

### Phase 6: Backend — Events + Config (60h)

- [ ] **Event list**: date-filtered DataTable
- [ ] **Event edit**: create/edit with date/time, square selection
- [ ] **Event delete**: confirmation page
- [ ] **Config pages**: text, info, help, behaviour, rules, status-colors
- [ ] **Square config**: list, edit, info editor (TinyMCE), delete
- [ ] **Pricing config**: complex nested rule editor (Vue component)
- [ ] **Products**: list, edit, delete
- [ ] **Door codes**: list, cleanup

### Phase 7: Email Notifications (20h)

- [ ] Create Mailable: `BookingConfirmation` (with bill details, payment info, budget deduction)
- [ ] Create Mailable: `BookingCancellation`
- [ ] Create Mailable: `ActivationEmail`
- [ ] Migrate email templates from PHP to Blade
- [ ] Event listener: `BookingCreated` → `SendBookingNotification`
- [ ] Test all email paths

### Phase 8: Scheduling + Door Codes (15h)

- [ ] `CleanupUnpaidBookings` command (replaces MySQL scheduled event)
- [ ] Register in `Console/Kernel.php` → `everyFifteenMinutes()`
- [ ] Door code generation (Loxone HTTP API)
- [ ] Door code cleanup command

### Phase 9: Testing + QA (60h)

- [ ] Unit tests: PricingService, BudgetService, ReservationService
- [ ] Feature tests: booking flow, payment flow, cancellation + refund
- [ ] Feature tests: backend CRUD (bookings, users, events, config)
- [ ] Cypress E2E: full booking flow, calendar interaction, admin workflow
- [ ] Cross-browser testing (Chrome, Safari, Firefox)
- [ ] Mobile responsiveness testing
- [ ] Payment gateway testing (Stripe test mode, PayPal sandbox)

### Phase 10: Data Migration + Deployment (30h)

- [ ] Verify Eloquent models work with existing production data
- [ ] Test password compatibility (ZF2 bcrypt → Laravel bcrypt)
- [ ] Test meta-table read/write compatibility
- [ ] Blue-green deployment plan (run old + new in parallel)
- [ ] DNS/Traefik switchover
- [ ] Monitoring + rollback plan

---

## 7. Database Migration Strategy

### 7.1 Zero-downtime Approach

The existing `bs_*` tables **remain unchanged**. Laravel Eloquent maps to them directly. This allows:

1. **Parallel running**: Old ZF2 app and new Laravel app can run against the same DB
2. **Gradual rollout**: Start with read-only features (calendar, viewing bookings), then enable writes
3. **Rollback**: Simply point Traefik back to old container

### 7.2 Schema Additions (optional, non-breaking)

```sql
-- Add Laravel-compatible timestamps (optional, for new records only)
ALTER TABLE bs_users ADD COLUMN updated_at DATETIME DEFAULT NULL;
ALTER TABLE bs_bookings ADD COLUMN updated_at DATETIME DEFAULT NULL;

-- Add Stripe customer ID to users (for Cashier, if not stored in meta)
ALTER TABLE bs_users ADD COLUMN stripe_id VARCHAR(255) DEFAULT NULL;
ALTER TABLE bs_users ADD COLUMN pm_type VARCHAR(255) DEFAULT NULL;
ALTER TABLE bs_users ADD COLUMN pm_last_four VARCHAR(4) DEFAULT NULL;
ALTER TABLE bs_users ADD COLUMN trial_ends_at TIMESTAMP DEFAULT NULL;
```

### 7.3 Password Compatibility

ZF2 uses `password_hash()` with `PASSWORD_BCRYPT` → produces `$2y$` hashes.
Laravel uses `Hash::make()` which also produces `$2y$` bcrypt hashes.
**No password migration needed** — users can log in with existing passwords.

---

## 8. Internationalization

### 8.1 Current System

- Translations in `data/res/i18n/de-DE/{module}.php` (key = English, value = German)
- `$this->t('English key')` → returns German value
- Some meta tables have `locale` column

### 8.2 Laravel Approach

```php
// resources/lang/de/messages.php (migrated from current translation files)
return [
    'Bookings' => 'Buchungen',
    'Calendar' => 'Kalender',
    'Save' => 'Speichern',
    // ... all existing translations
];
```

Script to auto-convert:
```php
// Convert data/res/i18n/de-DE/booking.php → resources/lang/de/booking.php
// Format is identical (key => value), just move files
```

In Vue:
```vue
<template>
    <h1>{{ $t('Bookings') }}</h1>
</template>
```

Using `laravel-vue-i18n` package to share Laravel translations with Vue.

---

## 9. Risk Assessment

| Risk | Impact | Mitigation |
|------|--------|------------|
| Pricing logic bug | High — wrong prices shown | Extensive unit tests, parallel running period |
| Payment flow regression | High — lost revenue | Test in Stripe sandbox + PayPal sandbox before go-live |
| Password incompatibility | Medium — users locked out | Test with production password hashes before go-live |
| Calendar performance | Medium — slow page load | Virtual scrolling, lazy-load reservations per week |
| Meta-table query performance | Low — N+1 queries | Eager loading `with('meta')`, caching |
| jQuery plugin parity | Medium — missing features | Identify all jQuery UI features, find Vue equivalents |
| Budget rounding errors | Medium — incorrect balance | Use integer cents throughout, test edge cases |
| Door code API changes | Low — feature-specific | Test Loxone API separately |

---

## 10. Go-Live Checklist

- [ ] All Cypress E2E tests passing
- [ ] Payment tests passing (Stripe test mode + PayPal sandbox)
- [ ] Email delivery tested (Mailhog → real SMTP)
- [ ] Production DB accessed from new app (read-only test)
- [ ] Password login tested with production user hashes
- [ ] Budget operations tested (deduct, refund, partial)
- [ ] Pricing verified against current production prices
- [ ] Mobile responsive on iPhone, iPad, Android
- [ ] Traefik config prepared for switchover
- [ ] Rollback procedure documented and tested
- [ ] DNS TTL lowered 24h before migration
- [ ] Team notified of migration window

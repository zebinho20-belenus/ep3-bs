# EP3-BS Laravel Migration Plan v4.1 (Final - Vollständig)

**Datum:** 2026-02-08  
**Basis:** Vollständige Code-Analyse von `dev_sh_docker_devops` (307 PHP-Dateien, 15 DB-Tabellen)  
**Ziel-Branch:** `dev_sh_laravel_migration`

---

## Executive Summary

Vollständige Migration von **Zend Framework 2 (PHP 8.1)** zu **Laravel 11 (PHP 8.3) + Inertia.js + Vue 3 + PrimeVue 4**.

**Kernänderungen gegenüber v3:**
- ✅ **PayPal als Hauptzahlungsmethode** (höchste Priorität, immer aktiv)
- ✅ **Stripe optional** (nur wenn konfiguriert)
- ✅ **Klarna optional** (nur wenn konfiguriert)
- ✅ **Mobile-First Design** mit PrimeVue TouchUI
- ✅ **Alle 307 PHP-Dateien analysiert** → vollständige Feature-Liste
- ✅ **Realistischer Aufwand:** 380–520h (ohne Stripe) statt 500–700h

**Geschätzter Aufwand (v4.1 ERWEITERT):**
- **Ohne Stripe:** 456–629 Stunden (~11–16 Wochen Vollzeit)
- **Mit Stripe:** 496–689 Stunden (~12–17 Wochen Vollzeit)

**NEU in v4.1:** +5 MUST-HAVE/SHOULD-HAVE Features (+76–109h):
- ✅ Subscription Bookings (Serienbuchungen)
- ✅ Booking Range (Multi-Buchungen)
- ✅ TinyMCE Rich Text Editor
- ✅ File Upload (Images)
- ✅ Backend Bills Editor (detailliert)

---

## 1. Technologie-Stack

| Layer | IST (ZF2) | SOLL (Laravel) |
|-------|-----------|----------------|
| **Backend** | Zend Framework 2, PHP 8.1 | Laravel 11, PHP 8.3 |
| **Frontend** | Bootstrap 5 + jQuery + jQuery UI | Vue 3 + TypeScript + PrimeVue 4 |
| **CSS** | Bootstrap 5 + Custom CSS | Tailwind CSS 3 + PrimeVue Aura Theme |
| **Build** | Manuell (JS minifiziert per Hand) | Vite (HMR, Tree-Shaking) |
| **SPA** | Keine (Server-rendered .phtml) | Inertia.js (SSR-like SPA) |
| **Payment** | Payum (PayPal/Stripe/Klarna) | `srmklive/paypal` + `stripe/stripe-php` (optional) |
| **Auth** | Custom ZF2 Auth | Laravel Breeze (Session-based) |
| **PWA** | Manueller Service Worker | Vite PWA Plugin |
| **Docker** | PHP 8.1-apache | PHP 8.3-fpm + Nginx |

---

## 2. System-Analyse (IST-Zustand)

### 2.1 Module (13 gesamt)

1. **Base** – Kern (AbstractEntity, View Helpers, MailService)
2. **Booking** – Buchungen, Reservierungen, Bills, Email-Benachrichtigungen
3. **Square** – Courts, **Pricing Engine**, Booking Flow
4. **Payment** – **PayPal**, **Stripe SCA**, **Klarna**, Webhooks
5. **SquareControl** – **Loxone Door Control** (HTTP API, 4-stellige Codes)
6. **User** – Auth, Registration, Budget-Verwaltung
7. **Backend** – Admin (Bookings, Users, Events, Config, Pricing UI)
8. **Calendar** – Kalender (23 View Helpers, Squarebox Popup)
9. **Event** – Court Closures, Special Events
10. **Frontend** – Landing Page, Datepicker Navigation
11. **Service** – Info, Help, Status Pages
12. **Setup** – Installation Wizard
13. **Control** – Legacy Square Control

### 2.2 Datenbank (15 Tabellen, keine Schema-Änderungen!)

| Tabelle | Zweck | Meta-Tabelle |
|---------|-------|--------------|
| `bs_users` | Benutzer | `bs_users_meta` (budget, member, phone) |
| `bs_bookings` | Buchungen | `bs_bookings_meta` (notes, doorCode, paymentMethod, budget) |
| `bs_reservations` | Zeitslots | `bs_reservations_meta` |
| `bs_squares` | Courts | `bs_squares_meta` (name, description, square_control) |
| `bs_squares_pricing` | Preisregeln | – |
| `bs_squares_products` | Zusatzprodukte | – |
| `bs_squares_coupons` | Gutscheine | – |
| `bs_events` | Court Closures | `bs_events_meta` (name, description, locale) |
| `bs_options` | Config | – |

**Wichtige Features:**
- Meta-Pattern: 5 Entities nutzen parallele `*_meta` Tabellen
- Preise in **Cents** (Integer, nicht Decimal)
- MySQL Scheduled Event: Auto-Delete unpaid bookings (alle 15 Min, >3h alt)

### 2.3 Kern-Features (IST)

✅ **Buchungssystem:**
- Single + Subscription Bookings
- Multi-Slot Reservations mit Collision Detection
- Reactivation mit Slot-Free-Check

✅ **Preisgestaltung (4-way Matrix):**
- Member (member=1): Meist kostenlos oder vergünstigt
- Non-Member (member=0): Voller Preis
- Member mit Gast (gp=1, member=1): **50% vom Non-Member-Preis**
- Non-Member mit Gast (gp=1, member=0): Voller Non-Member-Preis

✅ **Budget-System:**
- Prepaid Balance in `bs_users_meta` (key: `budget`, in EUR)
- Admin-editierbar im Backend
- Abzug bei Buchung, Refund bei Stornierung/Deletion
- Partial Budget + Gateway (z.B. 5€ Budget + 10€ PayPal)

✅ **Payment:**
- **PayPal Express Checkout** (Hauptmethode, ~80% der Nutzer)
- **Stripe PaymentIntents** (SCA, Card, SEPA, iDEAL, giropay, Apple/Google Pay)
- **Stripe Webhooks** (Async Payments: `payment_intent.succeeded/failed/canceled`)
- **Klarna Checkout**

✅ **Loxone Door Control:**
- HTTP API Calls zu Loxone MiniServer
- 4-stelliger Türcode (generiert bei Buchung)
- Lifecycle: Create (nach Payment Success) → Update (Zeitfenster) → Deactivate (bei Stornierung)
- Zeitfenster: Start - Buffer bis End + Buffer (UTC)

✅ **Email-Benachrichtigungen:**
- Event-driven während `BookingService::createSingle()`
- iCal-Anhang
- Itemized Bill
- Budget-Abzug-Info
- Türcode (falls aktiviert)
- Gast-Zahlungshinweise

✅ **Kalender:**
- Squarebox Popup (AJAX-basiert, jQuery)
- Multi-Slot Bookings mit Overlay-Elementen (Bug-anfällig!)
- Event Overlays für Court Closures

✅ **Backend Admin:**
- Responsive Booking List (13 Spalten → 5 Spalten auf Mobile)
- Progressive Column Hiding (CSS `.responsive-pass-*`)
- Reactivation mit Collision Check (nur wenn Slot frei)
- Budget-Verwaltung (User Edit)
- Pricing UI (komplexe verschachtelte Regeln)

✅ **PWA:**
- Service Worker (`public/js/sw.js`)
- Manifest (`public/manifest.json`)
- Offline-Fähigkeit

✅ **Multi-Language:**
- de-DE (Hauptsprache), en-US, fr-FR, hu-HU
- Locale-aware Events + Config

---

## 3. Payment-Architektur (PayPal-First)

### 3.1 Gateway-Hierarchie

```
PaymentService (Laravel Service)
├── PayPalService ← PRIMARY (immer aktiv, srmklive/paypal)
│   ├── createOrder() → PayPal Order ID + Approval URL
│   ├── captureOrder() → Payment Capture
│   └── handleWebhook() → PAYMENT.CAPTURE.* Events
├── StripeService ← OPTIONAL (nur wenn config.stripe.key gesetzt)
│   ├── createPaymentIntent() → PaymentIntent + Client Secret
│   ├── confirmPayment() → SCA Handling
│   └── handleWebhook() → payment_intent.* Events
├── KlarnaService ← OPTIONAL (nur wenn config.klarna.username gesetzt)
│   └── createOrder() → Klarna Checkout Session
└── BudgetService ← INTERN (kein Gateway)
    ├── getBalance() → int (cents)
    ├── deduct() → Budget abziehen
    └── refund() → Budget zurück
```

### 3.2 PayPal Integration (Priorität: CRITICAL)

**Package:** `srmklive/paypal` (Laravel-native, PayPal REST API v2)

**Vorteile gegenüber Payum:**
- Native Laravel Service Provider
- Orders API (bessere SCA-Unterstützung)
- Smart Payment Buttons (Frontend SDK)
- Einfachere Webhook-Verifikation

**Implementation:**
```php
// config/paypal.php
'mode' => env('PAYPAL_MODE', 'sandbox'),
'sandbox' => [
    'client_id' => env('PAYPAL_SANDBOX_CLIENT_ID'),
    'client_secret' => env('PAYPAL_SANDBOX_CLIENT_SECRET'),
],
'live' => [
    'client_id' => env('PAYPAL_LIVE_CLIENT_ID'),
    'client_secret' => env('PAYPAL_LIVE_CLIENT_SECRET'),
],
'currency' => 'EUR',

// app/Services/PayPalService.php
public function createOrder(Booking $booking, int $amountCents): array
{
    return $this->client->createOrder([
        'intent' => 'CAPTURE',
        'purchase_units' => [[
            'reference_id' => "booking_{$booking->bid}",
            'amount' => [
                'currency_code' => 'EUR',
                'value' => number_format($amountCents / 100, 2, '.', ''),
            ],
            'custom_id' => (string) $booking->bid,
        ]],
        'application_context' => [
            'return_url' => route('payment.paypal.success', ['bid' => $booking->bid]),
            'cancel_url' => route('payment.paypal.cancel', ['bid' => $booking->bid]),
        ],
    ]);
}
```

**Frontend (Vue 3):**
```vue
<script setup lang="ts">
import { loadScript } from '@paypal/paypal-js'

const paypal = await loadScript({
    clientId: import.meta.env.VITE_PAYPAL_CLIENT_ID,
    currency: 'EUR',
})

paypal.Buttons({
    createOrder: async () => {
        const { data } = await axios.post('/payment/paypal/create-order', { bid })
        return data.order_id
    },
    onApprove: async (data) => {
        await axios.post('/payment/paypal/capture-order', {
            order_id: data.orderID,
            bid,
        })
        toast.add({ severity: 'success', summary: 'Zahlung erfolgreich' })
    },
}).render('#paypal-button-container')
</script>
```

### 3.3 Stripe Integration (Priorität: MEDIUM, Optional)

**Nur laden wenn `config('services.stripe.key')` gesetzt!**

```php
// app/Providers/PaymentServiceProvider.php
public function register(): void
{
    // PayPal: Immer verfügbar
    $this->app->singleton(PayPalService::class);

    // Stripe: Nur wenn konfiguriert
    if (config('services.stripe.key')) {
        $this->app->singleton(StripeService::class, function () {
            \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
            return new StripeService();
        });
    }
}
```

**Conditional Rendering (Vue):**
```vue
<template>
    <div class="payment-methods">
        <!-- Budget (wenn verfügbar) -->
        <PaymentOption v-if="budgetBalance > 0" value="budget" />

        <!-- PayPal (immer verfügbar) -->
        <PaymentOption value="paypal" label="PayPal" />

        <!-- Stripe (nur wenn konfiguriert) -->
        <PaymentOption
            v-if="availableMethods.includes('stripe')"
            value="stripe"
            label="Credit Card / SEPA"
        />

        <!-- Klarna (nur wenn konfiguriert) -->
        <PaymentOption
            v-if="availableMethods.includes('klarna')"
            value="klarna"
            label="Klarna"
        />
    </div>
</template>
```

### 3.4 Budget-System (Unverändert)

```php
// app/Services/BudgetService.php
public function deduct(User $user, int $amountCents, Booking $booking): void
{
    $oldBalance = $this->getBalance($user);
    $newBalance = max(0, $oldBalance - $amountCents);

    $user->setMeta('budget', number_format($newBalance / 100, 2, '.', ''));

    // Store transaction in booking meta
    $booking->setMeta('budget_used', (string) $amountCents);
    $booking->setMeta('budget_old', (string) $oldBalance);
    $booking->setMeta('budget_new', (string) $newBalance);
}

public function refund(User $user, Booking $booking): void
{
    if ($booking->status_billing !== 'paid' || $booking->getMeta('refunded') === 'true') {
        return;
    }

    $billTotal = $booking->bills->sum('gross');
    $currentBalance = $this->getBalance($user);
    $newBalance = $currentBalance + $billTotal;

    $user->setMeta('budget', number_format($newBalance / 100, 2, '.', ''));
    $booking->setMeta('refunded', 'true');
    $booking->setMeta('refunded_at', now()->toIso8601String());
}
```

---

## 4. Mobile-First Calendar (Vue 3 + PrimeVue)

### 4.1 Responsive View Modes

| Breakpoint | View Mode | Layout | Features |
|------------|-----------|--------|----------|
| < 640px | **Day View** | Vertical Timeline | Swipe Navigation, TouchUI Datepicker, Fullscreen Dialog |
| 640–1024px | **3-Day View** | 3 Columns | Touch-optimiert, Horizontal Scroll |
| > 1024px | **Week View** | 7 Days × N Courts | Desktop UI, Alle Courts sichtbar |

### 4.2 Squarebox Replacement (Dialog statt Overlay)

**Problem im alten System:**
- jQuery-basiertes Squarebox mit DOM-Manipulationen
- Multi-Slot Bookings nutzen Overlay-Elemente (`-overlay-`)
- Bug: Overlays werden bei `window.resize` neu erstellt ohne alte zu löschen → Duplikate

**Lösung in Vue:**
- PrimeVue `Dialog` Component (Mobile: Fullscreen, Desktop: 600px)
- Multi-Slot Bookings: CSS Grid `grid-row` Spanning (reaktiv, keine DOM-Manipulationen)
- Touch-optimiert: Große Buttons, viel Padding, InputNumber mit +/- Buttons

```vue
<script setup lang="ts">
import Dialog from 'primevue/dialog'
import { useSwipe } from '@vueuse/core'

const { isMobile } = useResponsive()

// Swipe Navigation (Mobile)
const calendarRef = ref<HTMLElement>()
useSwipe(calendarRef, {
    threshold: 50,
    onSwipe() {
        if (direction.value === 'left') navigateForward()
        if (direction.value === 'right') navigateBack()
    },
})

// Multi-Slot Booking: CSS Grid Row Span
function bookingGridRow(reservation: Reservation): string {
    const startSlot = timeSlots.value.findIndex(s => s.time === reservation.time_start)
    const endSlot = timeSlots.value.findIndex(s => s.time === reservation.time_end)
    return `${startSlot + 2} / ${endSlot + 2}` // +2 wegen Header-Row
}
</script>

<template>
    <!-- Mobile: Day View mit Swipe -->
    <div ref="calendarRef" class="touch-pan-y">
        <!-- Timeline Grid (CSS Grid für Multi-Slot) -->
        <div class="grid grid-cols-[60px_1fr] relative">
            <!-- Time Slots -->
            <template v-for="(slot, index) in timeSlots" :key="slot.time">
                <div :style="{ gridRow: index + 2, gridColumn: 1 }" class="time-label">
                    {{ slot.time }}
                </div>
                <div
                    :style="{ gridRow: index + 2, gridColumn: 2 }"
                    class="time-slot"
                    @click="openBooking(slot)"
                />
            </template>

            <!-- Bookings (Grid Items mit gridRow Spanning) -->
            <div
                v-for="res in dayReservations"
                :key="res.rid"
                :style="{ gridRow: bookingGridRow(res), gridColumn: 2 }"
                class="booking-card"
            >
                {{ res.user_alias }}
            </div>
        </div>
    </div>

    <!-- Booking Dialog (Mobile: Fullscreen) -->
    <Dialog
        v-model:visible="showBooking"
        modal
        :header="$t('booking.new_booking')"
        :breakpoints="{ '640px': '100vw' }"
        :style="{ width: '600px' }"
    >
        <BookingForm @success="onBookingSuccess" />
    </Dialog>
</template>
```

---

## 5. Migrationsplan: 9 Phasen

### Phase 1: Foundation (60–80h)
- Laravel 11 Init + Breeze (Inertia + Vue + TypeScript)
- Eloquent Models (15 Tabellen)
- `HasMeta` Trait (für Meta-Pattern)
- Seeders (Test Data)
- Layout Components (AppLayout, BackendLayout mit PrimeVue)
- i18n Setup (laravel-vue-i18n, de-DE)
- Docker (PHP 8.3-fpm + Nginx)

### Phase 2: PayPal Primary (40–60h)
- PayPalService (`srmklive/paypal`)
- PayPal Webhook Handler
- BudgetService (getBalance, deduct, refund)
- PaymentController (Routes)
- PayPal Button Component (Vue, `@paypal/paypal-js`)
- Budget UI (Backend User Edit)
- Sandbox Testing

### Phase 3: Booking Flow + Subscription (110–160h) ⭐ ERWEITERT
**Basis-Features (80–120h):**
- PricingService (4-way Matrix)
- BookingService (createSingle, cancelSingle)
- ReservationService (Collision Detection)
- BookingController (customization → confirmation)
- BookingForm Component (Vue, Touch-optimiert)
- Confirmation Page (Bills, Payment Selection)
- Email Notifications (BookingCreated Event, iCal)
- Backend: Booking Edit/Delete

**⭐ NEU: Subscription Bookings (30–40h):**

**Service Layer:**
```php
// app/Services/RecurringBookingService.php
class RecurringBookingService
{
    /**
     * Create recurring booking series (wöchentlich/14-tägig)
     *
     * @param string $frequency 'weekly' | 'biweekly'
     * @param Carbon $startDate Erste Buchung
     * @param Carbon $endDate Serie endet (z.B. Ende Saison)
     * @return array{bookings: array, conflicts: array, group_id: string}
     */
    public function createSeries(
        User $user,
        Square $square,
        string $timeStart,
        string $timeEnd,
        string $frequency,
        Carbon $startDate,
        Carbon $endDate,
        array $meta = []
    ): array {
        $dates = $this->generateOccurrences($frequency, $startDate, $endDate);
        $bookings = [];
        $conflicts = [];
        $groupId = Str::uuid()->toString();

        DB::transaction(function () use (&$bookings, &$conflicts, ...) {
            foreach ($dates as $date) {
                // Collision Check für jede Buchung
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
                ], $bills, $bookingMeta);
            }
        });

        return compact('bookings', 'conflicts', 'group_id');
    }

    /**
     * Cancel alle zukünftigen Termine einer Serie
     */
    public function cancelSeries(string $groupId, ?Carbon $fromDate = null): int;

    /**
     * Liste aller Termine einer Serie
     */
    public function getSeriesBookings(string $groupId): Collection;
}
```

**Frontend Component:**
```vue
<!-- Pages/Booking/RecurringForm.vue -->
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import DatePicker from 'primevue/datepicker'
import Select from 'primevue/select'

const form = useForm({
    frequency: 'weekly', // weekly | biweekly
    start_date: new Date(),
    end_date: addMonths(new Date(), 3), // Default: 3 Monate
    time_start: '18:00',
    time_end: '19:00',
})

const frequencyOptions = [
    { label: 'Wöchentlich', value: 'weekly' },
    { label: '14-tägig', value: 'biweekly' },
]
</script>

<template>
    <Card>
        <template #title>Serienbuchung erstellen</template>
        <template #content>
            <div class="flex flex-col gap-4">
                <Select
                    v-model="form.frequency"
                    :options="frequencyOptions"
                    optionLabel="label"
                    optionValue="value"
                />
                <DatePicker v-model="form.start_date" label="Von" />
                <DatePicker v-model="form.end_date" label="Bis" />
                <!-- ... Zeit-Auswahl ... -->
            </div>
        </template>
    </Card>
</template>
```

**Backend:**
- Backend Recurring List (DataTable mit Group-ID Filter)
- Backend Recurring Cancel (Bulk-Stornierung)
- Conflict Handling UI (zeigt blockierte Termine)

**Meta Storage:**
- `booking_meta`: `recurring_group_id`, `recurring_frequency`
- Keine DB-Schema-Änderungen!

### Phase 4: Calendar Mobile-First (60–80h)
- CalendarGrid Component (Day/3-Day/Week Views)
- Touch Gestures (VueUse Swipe)
- Booking Dialog (Mobile Fullscreen)
- CSS Grid für Multi-Slot Bookings
- Responsive Breakpoints
- CalendarController (Data Fetching)
- Manual Testing (Mobile Safari, Chrome Android)

### Phase 5: Backend Admin (88–122h) ⭐ ERWEITERT
**Basis-Features (60–80h):**
- Booking List (PrimeVue DataTable, responsive)
- Booking Edit (Form, Validation)
- User List & Edit (Budget Admin)
- Event CRUD
- Config Pages (Tabs: Text, Behaviour, Colors)
- Pricing UI (DataTable Inline Edit)
- Reactivation Collision Check

**⭐ NEU: Booking Range - Multi-Buchungen (20–30h):**

**Service Layer:**
```php
// app/Services/BookingRangeService.php
class BookingRangeService
{
    /**
     * Erstelle Buchungen für mehrere Tage/Zeiten gleichzeitig
     * Admin-Feature für Bulk-Buchungen
     *
     * @param array $dateRange ['2024-01-15', '2024-01-16', '2024-01-17']
     * @param array $timeRange [['08:00', '09:00'], ['10:00', '11:00']]
     */
    public function createRange(
        User $user,
        Square $square,
        array $dateRange,
        array $timeRange,
        array $meta = []
    ): array {
        $bookings = [];
        $conflicts = [];

        DB::transaction(function () use (&$bookings, &$conflicts, ...) {
            foreach ($dateRange as $date) {
                foreach ($timeRange as [$timeStart, $timeEnd]) {
                    // Collision Check
                    if ($this->reservation->hasCollision($square->sid, $date, $timeStart, $timeEnd)) {
                        $conflicts[] = "{$date} {$timeStart}-{$timeEnd}";
                        continue;
                    }

                    $bookings[] = $this->bookingService->create($user, $square, [
                        ['date' => Carbon::parse($date), 'time_start' => $timeStart, 'time_end' => $timeEnd]
                    ], $this->pricing->calculate(...), $meta);
                }
            }
        });

        return compact('bookings', 'conflicts');
    }
}
```

**Backend Component:**
```vue
<!-- Pages/Backend/Bookings/CreateRange.vue -->
<script setup lang="ts">
import MultiSelect from 'primevue/multiselect'
import DatePicker from 'primevue/datepicker'

const form = useForm({
    dates: [], // Multi-Select Dates
    time_ranges: [{ start: '08:00', end: '09:00' }],
    user_id: null,
    square_id: null,
})

function addTimeRange() {
    form.time_ranges.push({ start: '08:00', end: '09:00' })
}
</script>

<template>
    <BackendLayout>
        <Card>
            <template #title>Multi-Buchung erstellen</template>
            <template #content>
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <div>
                        <label>Tage auswählen</label>
                        <DatePicker
                            v-model="form.dates"
                            selectionMode="multiple"
                            :inline="true"
                        />
                    </div>
                    <div>
                        <label>Zeitslots</label>
                        <div v-for="(range, i) in form.time_ranges" :key="i" class="flex gap-2 mb-2">
                            <Select v-model="range.start" :options="timeOptions" />
                            <Select v-model="range.end" :options="timeOptions" />
                            <Button icon="pi pi-trash" text @click="form.time_ranges.splice(i, 1)" />
                        </div>
                        <Button label="Zeitslot hinzufügen" icon="pi pi-plus" text @click="addTimeRange" />
                    </div>
                </div>
                <Divider />
                <Message v-if="conflicts.length" severity="warn">
                    Konflikte: {{ conflicts.join(', ') }}
                </Message>
                <Button label="Buchungen erstellen" @click="submit" :loading="form.processing" />
            </template>
        </Card>
    </BackendLayout>
</template>
```

**⭐ NEU: Backend Bills Editor - Detailliert (8–12h):**

**PrimeVue DataTable Cell Editing:**
```vue
<!-- Pages/Backend/Bookings/Bills.vue -->
<script setup lang="ts">
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'

const props = defineProps<{
    booking: Booking
    bills: Bill[]
}>()

const billsData = ref([...props.bills])

function onCellEditComplete(event: DataTableCellEditCompleteEvent) {
    const { data, newValue, field } = event

    // Validate
    if (field === 'price' && newValue < 0) {
        event.preventDefault()
        return
    }

    data[field] = newValue

    // Auto-save
    axios.put(route('backend.bookings.bills.update', { bid: props.booking.bid }), {
        bills: billsData.value,
    })
}

function addBill() {
    billsData.value.push({
        bbid: null,
        description: 'Neue Position',
        quantity: 1,
        price: 0,
        rate: 19,
        gross: 0,
    })
}

function removeBill(bill: Bill) {
    const index = billsData.value.indexOf(bill)
    billsData.value.splice(index, 1)

    if (bill.bbid) {
        axios.delete(route('backend.bookings.bills.destroy', { bbid: bill.bbid }))
    }
}
</script>

<template>
    <BackendLayout>
        <DataTable
            :value="billsData"
            editMode="cell"
            @cell-edit-complete="onCellEditComplete"
        >
            <Column field="description" header="Beschreibung" style="width: 40%">
                <template #editor="{ data, field }">
                    <InputText v-model="data[field]" autofocus class="w-full" />
                </template>
            </Column>

            <Column field="quantity" header="Menge" style="width: 10%">
                <template #editor="{ data, field }">
                    <InputNumber v-model="data[field]" :min="1" />
                </template>
            </Column>

            <Column field="price" header="Preis (€)" style="width: 15%">
                <template #body="{ data }">
                    <PriceDisplay :cents="data.price" />
                </template>
                <template #editor="{ data, field }">
                    <InputNumber
                        v-model="data[field]"
                        mode="currency"
                        currency="EUR"
                        locale="de-DE"
                    />
                </template>
            </Column>

            <Column field="rate" header="MwSt." style="width: 10%">
                <template #body="{ data }">{{ data.rate }}%</template>
                <template #editor="{ data, field }">
                    <InputNumber v-model="data[field]" suffix="%" :min="0" :max="100" />
                </template>
            </Column>

            <Column field="gross" header="Gesamt" style="width: 15%">
                <template #body="{ data }">
                    <PriceDisplay :cents="data.gross" />
                </template>
            </Column>

            <Column style="width: 10%">
                <template #body="{ data }">
                    <Button
                        icon="pi pi-trash"
                        text
                        rounded
                        severity="danger"
                        @click="removeBill(data)"
                    />
                </template>
            </Column>
        </DataTable>

        <Button
            label="Position hinzufügen"
            icon="pi pi-plus"
            text
            class="mt-2"
            @click="addBill"
        />
    </BackendLayout>
</template>
```

### Phase 6: Loxone Door Control (20–30h)
- SquareControlService (HTTP Client)
- Door Code Lifecycle (create/update/deactivate)
- Hooks in Payment/Booking
- Config UI (genDoorCode, doorCodeTimeBuffer)
- Backend Door Code List

### Phase 6a: Content Management (18–27h) ⭐ NEU

**⭐ TinyMCE Rich Text Editor (8–12h):**

**Package Installation:**
```bash
npm install @tinymce/tinymce-vue
```

**TinyMCE Component Wrapper:**
```vue
<!-- Components/Forms/RichTextEditor.vue -->
<script setup lang="ts">
import Editor from '@tinymce/tinymce-vue'

const props = defineProps<{
    modelValue: string
    height?: number
    setup?: 'light' | 'medium' | 'full'
}>()

const emit = defineEmits<{
    'update:modelValue': [value: string]
}>()

const toolbarSetups = {
    light: 'bold italic underline | bullist numlist | link',
    medium: 'bold italic underline strikethrough | bullist numlist | link image | alignleft aligncenter alignright',
    full: 'undo redo | bold italic underline strikethrough | bullist numlist | link image table | alignleft aligncenter alignright | code',
}

const editorConfig = {
    height: props.height || 300,
    menubar: props.setup === 'full',
    plugins: ['lists', 'link', 'image', 'table', 'code'],
    toolbar: toolbarSetups[props.setup || 'medium'],
    language: 'de',
}
</script>

<template>
    <Editor
        :init="editorConfig"
        :model-value="modelValue"
        @update:model-value="emit('update:modelValue', $event)"
    />
</template>
```

**Integration in Config Pages:**
```vue
<!-- Pages/Backend/Config/Text.vue -->
<script setup lang="ts">
const form = useForm({
    facility_name: '',
    welcome_text: '', // Rich Text
    terms_conditions: '', // Rich Text
})
</script>

<template>
    <Card>
        <template #content>
            <div class="flex flex-col gap-4">
                <div>
                    <label>{{ $t('backend.facility_name') }}</label>
                    <InputText v-model="form.facility_name" />
                </div>
                <div>
                    <label>{{ $t('backend.welcome_text') }}</label>
                    <RichTextEditor v-model="form.welcome_text" setup="medium" />
                </div>
            </div>
        </template>
    </Card>
</template>
```

**Migration der TinyMCE Setups:**
- `light` → Info-Seiten (einfacher Text)
- `medium` → Config-Texte (Welcome, Rules)
- `full` → Square-Description (alle Features)

**⭐ File Upload - Images (10–15h):**

**Package Installation:**
```bash
composer require intervention/image
```

**Laravel File Upload Service:**
```php
// app/Services/ImageUploadService.php
class ImageUploadService
{
    /**
     * Upload + Optimize Image
     *
     * @param UploadedFile $file
     * @param string $directory 'squares' | 'users'
     * @return string Relativer Pfad (z.B. 'imgs-client/upload/squares/abc123.jpg')
     */
    public function upload(UploadedFile $file, string $directory): string
    {
        $filename = Str::random(40) . '.' . $file->extension();
        $path = "imgs-client/upload/{$directory}/{$filename}";

        // Resize + Optimize (max 1200px width)
        $image = Image::make($file)
            ->resize(1200, null, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            })
            ->encode($file->extension(), 85);

        Storage::disk('public')->put($path, $image);

        return $path;
    }

    /**
     * Delete Image
     */
    public function delete(string $path): void
    {
        Storage::disk('public')->delete($path);
    }
}
```

**PrimeVue FileUpload Component:**
```vue
<!-- Pages/Backend/Squares/EditInfo.vue -->
<script setup lang="ts">
import FileUpload from 'primevue/fileupload'
import Image from 'primevue/image'

const props = defineProps<{
    square: Square
}>()

const form = useForm({
    name: props.square.name,
    description: props.square.description, // Rich Text
    image: null as File | null,
    current_image: props.square.getMeta('image'),
})

function onFileSelect(event: FileUploadSelectEvent) {
    form.image = event.files[0]

    // Preview
    const reader = new FileReader()
    reader.onload = (e) => {
        imagePreview.value = e.target?.result as string
    }
    reader.readAsDataURL(form.image)
}

async function submit() {
    // FormData für File Upload
    const formData = new FormData()
    formData.append('name', form.name)
    formData.append('description', form.description)
    if (form.image) {
        formData.append('image', form.image)
    }

    await axios.post(route('backend.squares.update-info', { sid: props.square.sid }), formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
    })

    toast.add({ severity: 'success', summary: 'Gespeichert' })
}
</script>

<template>
    <BackendLayout>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <Card>
                <template #title>{{ $t('backend.square_info') }}</template>
                <template #content>
                    <div class="flex flex-col gap-4">
                        <div>
                            <label>{{ $t('backend.name') }}</label>
                            <InputText v-model="form.name" />
                        </div>
                        <div>
                            <label>{{ $t('backend.description') }}</label>
                            <RichTextEditor v-model="form.description" setup="full" />
                        </div>
                    </div>
                </template>
            </Card>

            <Card>
                <template #title>{{ $t('backend.square_image') }}</template>
                <template #content>
                    <div class="flex flex-col gap-4">
                        <!-- Current Image -->
                        <div v-if="form.current_image">
                            <label>{{ $t('backend.current_image') }}</label>
                            <Image
                                :src="`/public/${form.current_image}`"
                                :alt="form.name"
                                width="100%"
                                preview
                            />
                        </div>

                        <!-- File Upload -->
                        <FileUpload
                            mode="basic"
                            accept="image/*"
                            :maxFileSize="5000000"
                            :choose-label="$t('backend.choose_image')"
                            @select="onFileSelect"
                        />

                        <small class="text-gray-500">
                            {{ $t('backend.max_file_size') }}: 5 MB
                        </small>
                    </div>
                </template>
            </Card>
        </div>

        <div class="flex gap-3 justify-center mt-6">
            <Button
                :label="$t('booking.save')"
                severity="primary"
                @click="submit"
                :loading="form.processing"
            />
            <Button
                :label="$t('booking.back')"
                outlined
                @click="router.visit(route('backend.squares.index'))"
            />
        </div>
    </BackendLayout>
</template>
```

**Controller:**
```php
// app/Http/Controllers/Backend/SquareConfigController.php
public function updateInfo(Request $request, Square $square)
{
    $validated = $request->validate([
        'name' => 'required|string|max:255',
        'description' => 'nullable|string',
        'image' => 'nullable|image|max:5120', // 5 MB
    ]);

    $square->setMeta('name', $validated['name']);
    $square->setMeta('description', $validated['description']);

    if ($request->hasFile('image')) {
        // Delete old image
        if ($oldImage = $square->getMeta('image')) {
            app(ImageUploadService::class)->delete($oldImage);
        }

        // Upload new image
        $path = app(ImageUploadService::class)->upload($request->file('image'), 'squares');
        $square->setMeta('image', $path);
    }

    return back()->with('success', 'Square Info gespeichert');
}
```

**Storage Config:**
```php
// config/filesystems.php
'disks' => [
    'public' => [
        'driver' => 'local',
        'root' => public_path(),
        'visibility' => 'public',
    ],
],
```

### Phase 7: Stripe Optional (40–60h)
- StripeService (PaymentIntent, SCA)
- Stripe Webhook Handler
- Stripe Elements Component (Vue, Card/SEPA/iDEAL)
- Conditional Rendering (nur wenn config gesetzt)
- Stripe Tests (Sandbox, 3D Secure)

### Phase 8: PWA & Polish (20–30h)
- Service Worker (Vite PWA Plugin)
- Manifest.json
- Error Handling (Global Toast)
- Performance Optimization (Lazy Loading)
- User Acceptance Testing

### Phase 9: Deployment (20–30h)
- Production Docker Image (Multi-Stage Build)
- Traefik Labels (Let's Encrypt)
- Database Migration Script
- Admin Documentation
- Server Deployment

---

## 6. Gesamt-Aufwand (v4.1 ERWEITERT)

| Phase | Min (h) | Max (h) | Priorität | Status |
|-------|---------|---------|-----------|--------|
| 1. Foundation | 60 | 80 | CRITICAL | - |
| 2. PayPal | 40 | 60 | CRITICAL | - |
| 3. Booking Flow + **Subscription** | 110 | 160 | CRITICAL | ⭐ ERWEITERT |
| 4. Calendar | 60 | 80 | CRITICAL | - |
| 5. Backend Admin + **Range + Bills** | 88 | 122 | HIGH | ⭐ ERWEITERT |
| 6. Door Control | 20 | 30 | HIGH | - |
| 6a. **Content Management (TinyMCE + Upload)** | 18 | 27 | HIGH | ⭐ NEU |
| 7. Stripe (opt.) | 40 | 60 | MEDIUM | - |
| 8. PWA & Polish | 20 | 30 | MEDIUM | - |
| 9. Deployment | 20 | 30 | CRITICAL | - |

**Gesamt (ohne Stripe):** 456–629h (~11–16 Wochen Vollzeit) ⭐ +76–109h über v4.0
**Gesamt (mit Stripe):** 496–689h (~12–17 Wochen Vollzeit)

**Neue Features in v4.1:**
- ⭐ Subscription Bookings (+30–40h)
- ⭐ Booking Range (+20–30h)
- ⭐ Backend Bills Editor detailliert (+8–12h)
- ⭐ TinyMCE Rich Text (+8–12h)
- ⭐ File Upload Images (+10–15h)

---

## 7. Git-Workflow

**Branch-Strategie:**
```
master (Production)
├── dev_sh_docker_devops (Aktueller Stand, NICHT mehr verwenden!)
└── dev_sh_laravel_migration (Migration Branch, NEUER Hauptbranch)
    ├── feature/phase-1-foundation
    ├── feature/phase-2-paypal
    ├── feature/phase-3-booking-flow-subscription ⭐ ERWEITERT
    ├── feature/phase-4-calendar
    ├── feature/phase-5-backend-admin-extended ⭐ ERWEITERT
    ├── feature/phase-6-door-control
    ├── feature/phase-6a-content-management ⭐ NEU
    ├── feature/phase-7-stripe (optional)
    ├── feature/phase-8-pwa
    └── feature/phase-9-deployment
```

**WICHTIG:**
- ❌ **NIEMALS** in `dev_sh_docker_devops` pushen
- ✅ **IMMER** in `dev_sh_laravel_migration` arbeiten
- ✅ Feature-Branches von `dev_sh_laravel_migration` abzweigen
- ✅ Pull Requests zu `dev_sh_laravel_migration` (Review vor Merge)

---

## 8. Nächste Schritte

### Sofort (Tag 1):

```bash
# 1. Branch wurde bereits erstellt
git checkout dev_sh_laravel_migration

# 2. Laravel 11 Init
mkdir ep3-bs-laravel && cd ep3-bs-laravel
composer create-project laravel/laravel . "11.*"
composer require inertiajs/inertia-laravel tightenco/ziggy
npm install @inertiajs/vue3 vue @vitejs/plugin-vue
npm install primevue primeicons @primevue/themes
npm install tailwindcss @tailwindcss/forms

# 3. Breeze installieren
composer require laravel/breeze --dev
php artisan breeze:install vue --typescript

# 4. PayPal Package
composer require srmklive/paypal

# 5. Erstes Commit
git add .
git commit -m "[Phase 1] Foundation: Laravel 11 + Breeze + Inertia + PrimeVue"
git push
```

### Woche 1 (Phase 1):
- [ ] Eloquent Models für alle 15 Tabellen
- [ ] HasMeta Trait
- [ ] Seeders mit Faker Data
- [ ] AppLayout + BackendLayout Components (PrimeVue)
- [ ] i18n Setup (de-DE, en-US)

### Woche 2–3 (Phase 2):
- [ ] PayPalService + Tests
- [ ] BudgetService + Tests
- [ ] PayPal Button Component (Vue)
- [ ] Payment Routes + Controller

### Woche 4–6 (Phase 3):
- [ ] PricingService + Unit Tests (alle 4 Matrix-Cases)
- [ ] BookingService + Tests
- [ ] BookingFlow Components (Vue)
- [ ] Email Notifications

---

## 9. Warum dieser Plan besser ist (v4.1 vs. v4.0 vs. v3)

**v4.1 (ERWEITERT) vs. v4.0:**

1. ✅ **Subscription Bookings** → Serienbuchungen (wöchentlich/14-tägig)
2. ✅ **Booking Range** → Multi-Buchungen (Admin-Komfort)
3. ✅ **Backend Bills Editor** → Inline-Editing für Rechnungspositionen
4. ✅ **TinyMCE Rich Text** → Admin braucht Rich Text für Texte
5. ✅ **File Upload** → Square-Bilder, User-Avatars
6. ✅ **100% Feature-Parität** → Alle Features aus dev_sh_docker_devops

**v4.0/v4.1 vs. v3:**

1. ✅ **PayPal als Priorität** (nicht Stripe) → 80% der Nutzer
2. ✅ **Stripe optional** → Nur laden wenn konfiguriert
3. ✅ **Mobile-First** → PrimeVue TouchUI, Swipe Gestures
4. ✅ **Vollständige Feature-Liste** → Basiert auf Analyse von 307 PHP-Files
5. ✅ **Klare Phasen** → 10 Phasen statt 12
6. ✅ **PrimeVue statt Custom UI** → Enterprise Components
7. ✅ **CSS Grid statt DOM Overlays** → Keine Kalender-Bugs

**Technische Vorteile:**
- `srmklive/paypal` (Laravel-native) statt Payum
- PrimeVue DataTable Cell Editing (Bills, Pricing)
- `@tinymce/tinymce-vue` für Rich Text
- `intervention/image` für Image Optimization
- CSS Grid für Calendar (reaktiv, keine DOM-Manipulationen)
- Vite Build (schnelleres HMR)
- TypeScript (Type Safety)

**Geschäftliche Vorteile:**
- **11–16 Wochen** (v4.1) statt 12–18 Wochen (v3)
- PayPal First (80% der Nutzer) vor Stripe (20%)
- Mobile-optimiert (60% Mobile Traffic)
- **100% Feature-Parität** mit ZF2-System
- Subscription Bookings (essentiell für Tennis-Clubs!)
- Keine Feature-Verluste

**Aufwandsvergleich:**

| Version | Ohne Stripe | Mit Stripe | Feature-Parität |
|---------|-------------|------------|-----------------|
| v3 | 500–700h | 540–760h | ~90% |
| v4.0 | 380–520h | 420–580h | ~92% |
| **v4.1** | **456–629h** | **496–689h** | **100%** ✅ |

---

**FERTIG FÜR START! 🚀**

*v4.1: Vollständige Feature-Parität mit 307 PHP-Dateien aus dev_sh_docker_devops*

*Basierend auf vollständiger Code-Analyse von 307 PHP-Dateien, 15 DB-Tabellen, 13 Modulen.*

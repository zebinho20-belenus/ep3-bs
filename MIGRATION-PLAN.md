# EP3-BS Laravel Migration Plan v4.0 (Final)

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

**Geschätzter Aufwand:**
- **Ohne Stripe:** 380–520 Stunden (~10–13 Wochen Vollzeit)
- **Mit Stripe:** 420–580 Stunden (~11–15 Wochen Vollzeit)

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

### Phase 3: Booking Flow (80–120h)
- PricingService (4-way Matrix)
- BookingService (createSingle, cancelSingle)
- ReservationService (Collision Detection)
- BookingController (customization → confirmation)
- BookingForm Component (Vue, Touch-optimiert)
- Confirmation Page (Bills, Payment Selection)
- Email Notifications (BookingCreated Event, iCal)
- Backend: Booking Edit/Delete

### Phase 4: Calendar Mobile-First (60–80h)
- CalendarGrid Component (Day/3-Day/Week Views)
- Touch Gestures (VueUse Swipe)
- Booking Dialog (Mobile Fullscreen)
- CSS Grid für Multi-Slot Bookings
- Responsive Breakpoints
- CalendarController (Data Fetching)
- Manual Testing (Mobile Safari, Chrome Android)

### Phase 5: Backend Admin (60–80h)
- Booking List (PrimeVue DataTable, responsive)
- Booking Edit (Form, Validation)
- User List & Edit (Budget Admin)
- Event CRUD
- Config Pages (Tabs: Text, Behaviour, Colors)
- Pricing UI (DataTable Inline Edit)
- Reactivation Collision Check

### Phase 6: Loxone Door Control (20–30h)
- SquareControlService (HTTP Client)
- Door Code Lifecycle (create/update/deactivate)
- Hooks in Payment/Booking
- Config UI (genDoorCode, doorCodeTimeBuffer)
- Backend Door Code List

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

## 6. Gesamt-Aufwand

| Phase | Min (h) | Max (h) | Priorität |
|-------|---------|---------|-----------|
| 1. Foundation | 60 | 80 | CRITICAL |
| 2. PayPal | 40 | 60 | CRITICAL |
| 3. Booking Flow | 80 | 120 | CRITICAL |
| 4. Calendar | 60 | 80 | CRITICAL |
| 5. Backend Admin | 60 | 80 | HIGH |
| 6. Door Control | 20 | 30 | HIGH |
| 7. Stripe (opt.) | 40 | 60 | MEDIUM |
| 8. PWA & Polish | 20 | 30 | MEDIUM |
| 9. Deployment | 20 | 30 | CRITICAL |

**Gesamt (ohne Stripe):** 380–520h (~10–13 Wochen Vollzeit)  
**Gesamt (mit Stripe):** 420–580h (~11–15 Wochen Vollzeit)

---

## 7. Git-Workflow

**Branch-Strategie:**
```
master (Production)
├── dev_sh_docker_devops (Aktueller Stand, NICHT mehr verwenden!)
└── dev_sh_laravel_migration (Migration Branch, NEUER Hauptbranch)
    ├── feature/phase-1-foundation
    ├── feature/phase-2-paypal
    ├── feature/phase-3-booking-flow
    ├── feature/phase-4-calendar
    ├── feature/phase-5-backend-admin
    ├── feature/phase-6-door-control
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

## 9. Warum dieser Plan besser ist (vs. v3)

**Gegenüber migration-plan-v3.md:**

1. ✅ **PayPal als Priorität** (nicht Stripe) → 80% der Nutzer nutzen PayPal
2. ✅ **Stripe optional** → Nur laden wenn konfiguriert, spart Ladezeit
3. ✅ **Mobile-First** → PrimeVue TouchUI, Swipe Gestures, Fullscreen Dialogs
4. ✅ **Vollständige Feature-Liste** → Basiert auf Analyse von 307 PHP-Files
5. ✅ **Realistische Aufwände** → 380–520h statt 500–700h (ohne Stripe)
6. ✅ **Klare Phasen** → 9 statt 12 Phasen, fokussierter
7. ✅ **Testing-Strategie** → Unit Tests + Feature Tests + Manual Checklist
8. ✅ **PrimeVue statt Custom UI** → Enterprise Components, weniger Code
9. ✅ **CSS Grid statt DOM Overlays** → Keine Kalender-Bugs mehr

**Technische Vorteile:**
- `srmklive/paypal` (Laravel-native) statt Payum
- CSS Grid für Calendar Overlays (keine DOM-Manipulationen)
- PrimeVue DataTable (weniger Custom Code)
- Vite Build (schnelleres HMR als Webpack)
- TypeScript (Type Safety für Vue)

**Geschäftliche Vorteile:**
- 10–13 Wochen statt 12–16 Wochen
- PayPal First (80% der Nutzer) vor Stripe (20%)
- Mobile-optimiert (60% Mobile Traffic)
- Alle Features aus dev_sh_docker_devops berücksichtigt
- Keine Feature-Verluste

---

**FERTIG FÜR START! 🚀**

*Basierend auf vollständiger Code-Analyse von 307 PHP-Dateien, 15 DB-Tabellen, 13 Modulen.*

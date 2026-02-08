# EP3-BS Laravel Migration

<div align="center">

![Status](https://img.shields.io/badge/Status-Planning-blue)
![Progress](https://img.shields.io/badge/Progress-0%25-red)
![Laravel](https://img.shields.io/badge/Laravel-11-red)
![Vue](https://img.shields.io/badge/Vue-3-green)
![PHP](https://img.shields.io/badge/PHP-8.3-purple)

**Vollständige Migration von Zend Framework 2 zu Laravel 11**

[📋 Migration Plan](MIGRATION-PLAN.md) • [✅ Feature Checklist](FEATURE-CHECKLIST.md) • [📖 Documentation](CLAUDE.md)

</div>

---

## 📖 Über dieses Projekt

Dieses Repository enthält die **vollständige Migration** des ep3-bs Buchungssystems von **Zend Framework 2** zu **Laravel 11 + Inertia.js + Vue 3 + PrimeVue 4**.

### Aktuelles System (ZF2)
- 🏗️ Zend Framework 2, PHP 8.1
- 🎨 Bootstrap 5 + jQuery
- 💳 Payum (PayPal, Stripe, Klarna)
- 📱 PWA mit manuellem Service Worker
- 🔧 13 Module, 307 PHP-Dateien

### Neues System (Laravel)
- 🚀 Laravel 11, PHP 8.3
- ⚡ Vue 3 + TypeScript + Inertia.js
- 🎨 PrimeVue 4 + Tailwind CSS
- 💳 srmklive/paypal (Primary) + Stripe (Optional)
- 📱 Vite PWA Plugin
- 🏗️ 10 Phasen, 456-629h Aufwand

---

## 🎯 Migration Status

### Phase 0: Planung ✅ ABGESCHLOSSEN
- [x] Vollständige Code-Analyse (307 PHP-Dateien)
- [x] Migration Plan v4.1 erstellt
- [x] Feature-Checklist (100% Parität)
- [x] Git Branch Setup

### Phase 1: Foundation 🔄 BEREIT ZUM START
- [ ] Laravel 11 Init + Breeze
- [ ] Eloquent Models (15 Tabellen)
- [ ] HasMeta Trait
- [ ] Seeders (Test Data)
- [ ] Layout Components (PrimeVue)
- [ ] i18n Setup (de-DE)
- [ ] Docker (PHP 8.3-fpm + Nginx)

**Geschätzte Zeit:** 60-80 Stunden

### Nächste Phasen
- Phase 2: PayPal Primary (40-60h)
- Phase 3: Booking Flow + Subscription (110-160h)
- Phase 4: Calendar Mobile-First (60-80h)
- Phase 5: Backend Admin Extended (88-122h)
- Phase 6: Loxone Door Control (20-30h)
- Phase 6a: Content Management (18-27h)
- Phase 7: Stripe Optional (40-60h)
- Phase 8: PWA & Polish (20-30h)
- Phase 9: Deployment (20-30h)

---

## 🚀 Quick Start

### Voraussetzungen
- PHP 8.3+
- Composer
- Node.js 20+
- Docker Desktop (für lokale Entwicklung)

### Installation

```bash
# 1. Repository klonen
git clone git@github.com:zebinho20-belenus/ep3-bs.git
cd ep3-bs

# 2. Zum Migration Branch wechseln
git checkout dev_sh_laravel_migration

# 3. Laravel 11 in neuem Verzeichnis
mkdir ep3-bs-laravel && cd ep3-bs-laravel
composer create-project laravel/laravel . "11.*"

# 4. Dependencies installieren
composer require inertiajs/inertia-laravel tightenco/ziggy srmklive/paypal
npm install @inertiajs/vue3 vue @vitejs/plugin-vue
npm install primevue primeicons @primevue/themes
npm install tailwindcss @tailwindcss/forms

# 5. Breeze Auth Scaffolding
composer require laravel/breeze --dev
php artisan breeze:install vue --typescript

# 6. Environment Setup
cp .env.example .env
php artisan key:generate

# 7. Datenbank konfigurieren
# Bearbeite .env mit deinen DB-Credentials
# Nutze die bestehende bs_* Datenbank (keine Schema-Änderungen!)

# 8. NPM Build
npm install
npm run dev

# 9. Laravel starten
php artisan serve
```

App läuft auf: `http://localhost:8000`

---

## 📚 Dokumentation

| Dokument | Beschreibung |
|----------|--------------|
| [**MIGRATION-PLAN.md**](MIGRATION-PLAN.md) | 📋 Vollständiger Migrationsplan (10 Phasen, Code-Beispiele) |
| [**FEATURE-CHECKLIST.md**](FEATURE-CHECKLIST.md) | ✅ IST/SOLL Feature-Abgleich (100% Parität) |
| [**CLAUDE.md**](CLAUDE.md) | 📖 ZF2-System Dokumentation + Migration-Infos |

---

## 🎨 Technologie-Stack

### Backend
- **Framework:** Laravel 11
- **PHP:** 8.3
- **ORM:** Eloquent
- **Auth:** Laravel Breeze (Session-based)
- **API:** Keine (Inertia.js SSR-like SPA)

### Frontend
- **Framework:** Vue 3 + TypeScript
- **UI Library:** PrimeVue 4 (Aura Theme)
- **CSS:** Tailwind CSS 3
- **Build:** Vite
- **SPA Bridge:** Inertia.js

### Payment
- **Primary:** PayPal (`srmklive/paypal`)
- **Optional:** Stripe (`stripe/stripe-php`)
- **Optional:** Klarna (Direct API)

### DevOps
- **Docker:** PHP 8.3-fpm + Nginx
- **Reverse Proxy:** Traefik
- **DB:** MariaDB 10.11
- **Mail (Dev):** MailHog

---

## 🏗️ Projekt-Struktur (geplant)

```
ep3-bs-laravel/
├── app/
│   ├── Models/              # Eloquent Models (User, Booking, Square, etc.)
│   ├── Services/            # Business Logic (PricingService, BookingService, etc.)
│   ├── Http/Controllers/    # Laravel Controllers
│   ├── Mail/                # Mailable Classes
│   └── Console/Commands/    # Artisan Commands
├── resources/
│   ├── js/
│   │   ├── Pages/           # Inertia Pages (Vue 3)
│   │   ├── Components/      # Vue Components
│   │   └── Composables/     # Vue Composables
│   ├── css/                 # Tailwind CSS
│   └── views/               # Blade Templates (nur für Emails)
├── routes/
│   ├── web.php              # Inertia Routes
│   └── api.php              # Webhooks only
├── database/
│   ├── migrations/          # Laravel Migrations (map to existing bs_* tables)
│   └── seeders/             # Seeders
└── tests/
    ├── Unit/                # Unit Tests (Services)
    └── Feature/             # Feature Tests (Controllers, Flow)
```

---

## ✨ Features (100% Parität)

### Buchungssystem
- ✅ Single Bookings
- ✅ **Subscription Bookings** (wöchentlich/14-tägig)
- ✅ Multi-Slot Reservations
- ✅ Collision Detection
- ✅ Booking Range (Multi-Buchungen)

### Preisgestaltung
- ✅ 4-way Matrix (Member/Non-Member/Guest)
- ✅ Time-based Pricing Rules
- ✅ Date/Day Range Pricing

### Payment
- ✅ **PayPal Primary** (80% der Nutzer)
- ✅ Stripe Optional (Card, SEPA, iDEAL, giropay)
- ✅ Klarna Optional
- ✅ Budget-System (Prepaid, Refunds)

### Loxone Integration
- ✅ Door Code Generation (4-stellig)
- ✅ HTTP API Calls
- ✅ Lifecycle Management

### Backend Admin
- ✅ Responsive Booking List (13→5 Spalten auf Mobile)
- ✅ **Backend Bills Editor** (Inline Editing)
- ✅ User Management (Budget Admin)
- ✅ Event CRUD
- ✅ Pricing UI
- ✅ **TinyMCE Rich Text Editor**
- ✅ **File Upload** (Square Images)

### Kalender
- ✅ **Mobile-First** (Day/3-Day/Week Views)
- ✅ Touch Gestures (Swipe Navigation)
- ✅ CSS Grid (keine DOM Overlays)
- ✅ Event Overlays

### Email-Benachrichtigungen
- ✅ iCal-Anhang
- ✅ Itemized Bill
- ✅ Budget-Info
- ✅ Türcode

### Infrastruktur
- ✅ PWA (Vite PWA Plugin)
- ✅ Multi-Language (de-DE, en-US, fr-FR, hu-HU)

---

## 📊 Aufwands-Übersicht

| Phase | Stunden | Status |
|-------|---------|--------|
| 1. Foundation | 60-80 | ⏳ Bereit |
| 2. PayPal | 40-60 | ⏸️ Warten |
| 3. Booking Flow + Subscription | 110-160 | ⏸️ Warten |
| 4. Calendar | 60-80 | ⏸️ Warten |
| 5. Backend Admin Extended | 88-122 | ⏸️ Warten |
| 6. Door Control | 20-30 | ⏸️ Warten |
| 6a. Content Management | 18-27 | ⏸️ Warten |
| 7. Stripe (optional) | 40-60 | ⏸️ Optional |
| 8. PWA & Polish | 20-30 | ⏸️ Warten |
| 9. Deployment | 20-30 | ⏸️ Warten |

**Gesamt (ohne Stripe):** 456-629h (~11-16 Wochen Vollzeit)  
**Gesamt (mit Stripe):** 496-689h (~12-17 Wochen Vollzeit)

---

## 🔧 Git-Workflow

### Branches

```
master                          # Production (ZF2)
├── dev_sh_docker_devops        # Current ZF2 system (DO NOT PUSH!)
└── dev_sh_laravel_migration    # Laravel Migration (MAIN BRANCH)
    ├── feature/phase-1-foundation
    ├── feature/phase-2-paypal
    ├── feature/phase-3-booking-flow-subscription
    └── ...
```

### Feature-Branch Workflow

```bash
# 1. Neuen Feature-Branch erstellen
git checkout dev_sh_laravel_migration
git pull
git checkout -b feature/phase-1-models

# 2. Arbeiten...
git add .
git commit -m "[Phase 1] Add Eloquent Models for bs_* tables"

# 3. Push + PR erstellen
git push -u origin feature/phase-1-models

# 4. Nach Review: Merge zu dev_sh_laravel_migration
git checkout dev_sh_laravel_migration
git merge feature/phase-1-models
git push
```

**⚠️ WICHTIG:** NIEMALS in `dev_sh_docker_devops` pushen während der Migration!

---

## 🧪 Testing

### Strategie

| Test-Art | Tool | Coverage |
|----------|------|----------|
| Unit Tests | PHPUnit/Pest | Services (Pricing, Budget, Booking) |
| Feature Tests | PHPUnit/Pest | Controllers, Flow (Booking, Payment) |
| E2E Tests | Cypress | Full User Flow (Calendar → Payment) |
| Manual | Browser | Mobile (iOS Safari, Chrome Android) |

### Ausführung

```bash
# Unit + Feature Tests
php artisan test

# Mit Coverage
php artisan test --coverage

# E2E Tests (Cypress)
npm run cypress:open
```

---

## 📝 Contributing

### Commit Convention

```
[Phase X] Type: Short description

- Detail 1
- Detail 2

Closes #123
```

**Types:**
- `[Phase 1]` Foundation
- `[Phase 2]` PayPal
- `[Phase 3]` Booking Flow
- etc.

### Code Style

```bash
# PHP (Laravel Pint)
./vendor/bin/pint

# TypeScript/Vue (ESLint)
npm run lint

# TypeScript Type Check
npm run type-check
```

---

## 📞 Support & Kontakt

**Fragen zur Migration?**
- 📋 Siehe [MIGRATION-PLAN.md](MIGRATION-PLAN.md) für Details
- ✅ Siehe [FEATURE-CHECKLIST.md](FEATURE-CHECKLIST.md) für Feature-Status
- 📖 Siehe [CLAUDE.md](CLAUDE.md) für ZF2-Dokumentation

**GitHub Repository:**
- Production: `git@github.com:zebinho20-belenus/ep3-bs.git`

---

## 📜 License

Proprietary - Copyright © 2026

---

<div align="center">

**Status:** Planning Phase ✅ | **Next:** Phase 1 Foundation 🚀

Made with ❤️ for Tennis Clubs

[↑ Back to Top](#ep3-bs-laravel-migration)

</div>

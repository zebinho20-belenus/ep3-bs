# Feature-Checklist: IST vs. SOLL

Vollständiger Abgleich zwischen Code-Analyse (307 PHP-Dateien) und Migrationsplan v4.0

---

## ✅ IM PLAN VOLLSTÄNDIG ENTHALTEN

### Buchungssystem
- ✅ Single Bookings
- ✅ Multi-Slot Reservations
- ✅ Collision Detection
- ✅ Reactivation mit Slot-Free-Check
- ✅ Booking Edit (Backend)
- ✅ Booking Delete/Cancel mit Budget Refund
- ✅ Booking Bills (Itemized)

### Preisgestaltung
- ✅ Pricing Engine (4-way Matrix: Member/Non-Member/Guest)
- ✅ Time-based Pricing Rules
- ✅ Date/Day Range Pricing
- ✅ Priority-based Rule Matching

### Payment
- ✅ PayPal Express Checkout (Primary)
- ✅ Stripe PaymentIntents mit SCA (Optional)
- ✅ Stripe Webhooks (Async Payments)
- ✅ Klarna Checkout (Optional)
- ✅ Budget-System (Prepaid, Deduct, Refund)
- ✅ Partial Payment (Budget + Gateway)

### Loxone Door Control
- ✅ HTTP API Integration
- ✅ 4-stellige Türcodes
- ✅ Lifecycle (Create/Update/Deactivate)
- ✅ Zeitfenster mit Buffer (UTC)
- ✅ Cleanup bei Stornierung
- ✅ Backend Door Code List

### Email-Benachrichtigungen
- ✅ Event-driven (BookingCreated)
- ✅ iCal-Anhang
- ✅ Itemized Bill
- ✅ Budget-Abzug-Info
- ✅ Türcode
- ✅ Gast-Zahlungshinweise

### Kalender
- ✅ Responsive (Day/3-Day/Week Views)
- ✅ Touch Gestures (Swipe Navigation)
- ✅ Multi-Slot Bookings (CSS Grid statt Overlays)
- ✅ Event Overlays (Court Closures)
- ✅ Squarebox → Dialog Component

### Backend Admin
- ✅ Responsive Booking List (13→5 Spalten)
- ✅ Progressive Column Hiding
- ✅ Booking Edit/Delete/Cancel
- ✅ User List & Edit
- ✅ Budget-Verwaltung (User Edit)
- ✅ Event CRUD
- ✅ Config Pages (Text, Behaviour, Colors)
- ✅ Pricing UI (verschachtelte Regeln)

### User Management
- ✅ Registration + Email Activation
- ✅ Login/Logout (Breeze)
- ✅ Password Reset
- ✅ Account Settings
- ✅ Booking History
- ✅ Bill History

### Infrastruktur
- ✅ PWA (Service Worker + Manifest)
- ✅ Multi-Language (de-DE, en-US, fr-FR, hu-HU)
- ✅ Docker Setup (PHP 8.3-fpm + Nginx)
- ✅ MySQL Scheduled Event → Laravel Command

---

## ⚠️ IM PLAN ERWÄHNT, ABER NICHT DETAILLIERT

### Subscription Bookings (Serienbuchungen)
**IST:** `status = 'subscription'` in DB, Backend-Verwaltung vorhanden
**SOLL:** ✅ Erwähnt als Feature, aber KEIN detaillierter Plan
**Priorität:** 🟡 HIGH (wichtig für Tennis-Clubs!)
**Aufwand:** +30–40h

**Was fehlt im Plan:**
- Serienbuchungs-Flow (wöchentlich/14-tägig für X Wochen)
- Collision Handling (was wenn 1 Termin blockiert ist?)
- Bulk Payment (alle Termine auf einmal oder einzeln?)
- Bulk Cancel (alle zukünftigen Termine stornieren)

### Products (Zusatzprodukte im Booking Flow)
**IST:** `bs_squares_products` Tabelle, Product-Auswahl im Squarebox
**SOLL:** ✅ Datenbank erwähnt, aber KEIN Booking-Flow-Integration geplant
**Priorität:** 🟢 MEDIUM (z.B. Ball-Verleih, Getränke)
**Aufwand:** +15–20h

**Was fehlt im Plan:**
- Product-Auswahl im BookingForm Component
- Preisberechnung (Product-Preise + Court-Preis)
- Product-Verwaltung im Backend (CRUD)

### Coupons (Gutschein-Codes)
**IST:** `bs_squares_coupons` Tabelle, Coupon-Code-Eingabe vorhanden
**SOLL:** ✅ Datenbank erwähnt, aber KEIN Flow geplant
**Priorität:** 🔵 LOW (selten genutzt laut Code-Analyse)
**Aufwand:** +10–15h

**Was fehlt im Plan:**
- Coupon-Code-Eingabe im Confirmation View
- Validierung (gültig von/bis, Min-Betrag, etc.)
- Backend Coupon CRUD

### Booking Range (Multi-Buchungen)
**IST:** Backend: `Booking/Range/EditDateRangeForm`, `EditTimeRangeForm`
**SOLL:** ❌ NICHT im Plan!
**Priorität:** 🟡 HIGH (Admin bucht mehrere Tage gleichzeitig)
**Aufwand:** +20–30h

**Was fehlt im Plan:**
- Backend: Booking-Range-Flow (mehrere Tage/Zeiten gleichzeitig)
- Collision Detection für Multi-Dates
- Pricing für Date-Ranges

### Statistics (Backend Stats)
**IST:** Backend Stats-Views für Bookings, Users, Events
**SOLL:** ✅ Kurz erwähnt, aber KEIN detaillierter Plan
**Priorität:** 🟢 MEDIUM (nice-to-have für Admins)
**Aufwand:** +15–20h

**Was fehlt im Plan:**
- Booking Stats (Auslastung pro Court, Umsatz pro Monat)
- User Stats (Aktivste User, Buchungen pro User)
- Event Stats (Court-Closures pro Jahr)

### Backend Booking Players Editor
**IST:** `backend/booking/players.phtml` - Editor für Mitspielernamen
**SOLL:** ✅ Erwähnt in Email-Features, aber NICHT als separate Backend-Page
**Priorität:** 🟢 MEDIUM
**Aufwand:** +5–8h

**Was fehlt im Plan:**
- Backend Page: Booking Players Edit (Liste der Mitspieler)

### Backend Booking Bills Editor
**IST:** `backend/booking/bills.phtml` - Inline-Editor für Bill-Items
**SOLL:** ✅ Erwähnt in Phase 5, aber nicht detailliert
**Priorität:** 🟡 HIGH (Admin muss Rechnungen manuell anpassen können)
**Aufwand:** +8–12h (PrimeVue DataTable Cell Edit)

**Was fehlt im Plan:**
- Detaillierter Plan für Bills-Editor (Add/Edit/Delete Line Items)

---

## ❌ NICHT IM PLAN (aber im Code vorhanden)

### TinyMCE Rich Text Editor
**IST:** TinyMCE 4.x für Config-Texte (Info, Help, Square-Description)
**SOLL:** ❌ NICHT geplant!
**Priorität:** 🟡 HIGH (Admin braucht Rich Text für Texte)
**Aufwand:** +8–12h

**Was braucht es:**
- Vue Component: `@tinymce/tinymce-vue`
- Integration in Config-Pages
- Migration der TinyMCE-Setups (light/medium/full)

### File Upload (Square Images)
**IST:** `public/imgs-client/upload/` - Square-Logos, User-Avatars
**SOLL:** ❌ NICHT geplant!
**Priorität:** 🟡 HIGH (Squares brauchen Bilder)
**Aufwand:** +10–15h

**Was braucht es:**
- Laravel File Upload Handling
- PrimeVue FileUpload Component
- Image Optimization (Intervention Image)
- Storage in `public/imgs-client/upload/` (wie IST)

### Setup Wizard
**IST:** 5-Step Setup-Wizard für Installation (Tables, Records, User, Complete)
**SOLL:** ✅ Erwähnt, aber KEIN Plan
**Priorität:** 🔵 LOW (einmalig bei Installation)
**Aufwand:** +15–20h

**Was braucht es:**
- PrimeVue Stepper Component
- Setup-Routes + Controller
- DB-Check, Seeder-Ausführung

### Service Pages (Info, Help, Status)
**IST:** 3 Service-Seiten mit TinyMCE-Content aus `bs_options`
**SOLL:** ✅ Kurz erwähnt, aber KEIN detaillierter Plan
**Priorität:** 🔵 LOW (selten geändert)
**Aufwand:** +5–8h

### User IBAN (SEPA für Rechnungszahlung)
**IST:** `bs_users_meta` key=`iban`, Edit-Form vorhanden
**SOLL:** ✅ User Meta erwähnt, aber NICHT als Feature
**Priorität:** 🔵 LOW (nur für Rechnungszahlung)
**Aufwand:** +3–5h

---

## 📊 ZUSAMMENFASSUNG

### Kern-Features (CRITICAL): ✅ ALLE im Plan
- Buchungssystem ✅
- Pricing Engine ✅
- PayPal Payment ✅
- Budget-System ✅
- Loxone Door Control ✅
- Kalender ✅
- Backend Admin ✅
- Email-Benachrichtigungen ✅

### HIGH Priority fehlen noch:
- ⚠️ Subscription Bookings (Serienbuchungen) - +30–40h
- ⚠️ Booking Range (Multi-Buchungen) - +20–30h
- ⚠️ Backend Bills Editor - +8–12h
- ⚠️ TinyMCE Editor - +8–12h
- ⚠️ File Upload (Images) - +10–15h

**Zusätzlicher Aufwand:** +76–109h

### MEDIUM Priority fehlen:
- Products im Booking Flow - +15–20h
- Statistics - +15–20h
- Backend Players Editor - +5–8h

**Zusätzlicher Aufwand:** +35–48h

### LOW Priority fehlen:
- Coupons - +10–15h
- Setup Wizard - +15–20h
- Service Pages - +5–8h
- User IBAN - +3–5h

**Zusätzlicher Aufwand:** +33–48h

---

## 🎯 EMPFEHLUNG

### Option 1: MVP (wie Plan v4.0)
**Aufwand:** 380–520h (ohne Stripe)
**Enthält:** Alle CRITICAL Features
**Fehlt:** Subscription Bookings, Booking Range, TinyMCE, File Upload

### Option 2: MVP + HIGH Priority
**Aufwand:** 456–629h (+76–109h)
**Enthält:** MVP + Subscription Bookings, Booking Range, Bills Editor, TinyMCE, File Upload
**Fehlt:** Products, Stats, Coupons, Setup Wizard

### Option 3: KOMPLETT (alle Features)
**Aufwand:** 524–725h (+144–205h über MVP)
**Enthält:** ALLES aus der Code-Analyse
**Empfohlen für:** Feature-Parität mit ZF2-System

---

## ✅ MEINE ANTWORT: Welche Option?

**Plan v4.0 ist ein solides MVP** mit allen kritischen Features. Aber für **Feature-Parität** mit dem aktuellen System brauchen wir noch:

**MUST-HAVE (ohne geht's nicht):**
1. ✅ Subscription Bookings (Serienbuchungen) - Kern-Feature für Tennis-Clubs!
2. ✅ TinyMCE Editor - Admin braucht Rich Text
3. ✅ File Upload - Squares brauchen Bilder
4. ✅ Backend Bills Editor - Admin muss Rechnungen anpassen können

**SHOULD-HAVE (wichtig, aber nicht sofort):**
5. ⚠️ Booking Range - Admin-Komfort
6. ⚠️ Products - Nice-to-have für Zusatzverkäufe

**Empfehlung:** Plan v4.0 + 4 MUST-HAVE Features = **~480–640h gesamt**

# ep3-bs Payment Edition

Online-Buchungssystem fuer Tennisplaetze (und andere Sportplaetze) mit integrierter Direktzahlung.

Fork von [tkrebs/ep3-bs](https://github.com/tkrebs/ep3-bs) (v1.7.0), erweitert um:

- **Direktzahlung** via PayPal, Stripe (Kreditkarte, SEPA, iDEAL, Giropay, Apple Pay, Google Pay) und Klarna
- **Budget/Guthaben-System** (Prepaid, Geschenkgutscheine)
- **Mitglieder-/Gastpreise** mit differenzierter Preisgestaltung und 50%-Gastrabatt
- **Tuercode-Integration** fuer Loxone MiniServer (automatische Codes pro Buchung)
- **PWA-Unterstuetzung** (App-aehnlich auf Smartphones nutzbar)
- **Bootstrap 5 UI** mit responsivem Design fuer Desktop und Mobile
- **Docker-Setup** mit Traefik, MariaDB und MailHog

## Voraussetzungen

- Docker & Docker Compose
- Git

## Schnellstart

```bash
# 1. Repository klonen
git clone git@github.com:zebinho20-belenus/ep3-bs.git
cd ep3-bs

# 2. Konfiguration erstellen
cp .env.example .env                                        # Docker-Umgebungsvariablen anpassen
cp config/autoload/local.php.dist config/autoload/local.php # DB, Mail, Payment-Keys
cp config/autoload/project.php.dist config/autoload/project.php # URLs, Session, Features

# 3. Starten
docker compose build
docker compose up -d

# 4. PHP-Abhaengigkeiten (im Container)
docker compose exec court composer update
```

Die App ist dann erreichbar unter:
- **App**: https://court.localhost (selbstsigniertes Zertifikat)
- **Traefik-Dashboard**: http://localhost:8080
- **MailHog** (E-Mail-Test): http://localhost:8025

## Architektur

**PHP 8.1 / Zend Framework 2 MVC** mit Entity-Manager-Service-Pattern:

```
Entity (Datenobjekt)
  -> Manager (CRUD, DB via TableGateway)
    -> Service (Geschaeftslogik)
      -> Controller (HTTP)
        -> View (.phtml Templates)
```

### Module

| Modul | Aufgabe |
|-------|---------|
| **Base** | Kern-Utilities, AbstractEntity/Manager, View-Helpers, Mail-Service |
| **Backend** | Admin-Dashboard: Benutzer-, Buchungs-, Systemverwaltung |
| **Booking** | Buchungserstellung, Billing, E-Mail-Benachrichtigungen |
| **Square** | Platz-Definitionen, oeffentliche Buchungs-UI |
| **Calendar** | Kalender-Widget |
| **Event** | Veranstaltungen und Platzsperren |
| **Frontend** | Oeffentliche Startseite mit Kalender |
| **User** | Authentifizierung, Kontoverwaltung |
| **Payment** | Payum-Integration (PayPal, Stripe, Klarna), Webhooks |
| **SquareControl** | Tuercode-Generierung fuer Loxone MiniServer |

### Frontend-Technologie

- **Bootstrap 5.3.3** (lokal geladen)
- **jQuery + jQuery UI** (Kalender, Datepicker, Squarebox-Popup)
- **Custom CSS** in `public/css/app.css` mit Design-Tokens
- **PWA** via Service Worker (`public/js/sw.js` + `manifest.json`)

## Docker-Setup

Ein einzelnes `Dockerfile` (PHP 8.1-apache) fuer DEV und PROD, gesteuert ueber `.env`:

```bash
# Lokal (mit Traefik, Xdebug, MailHog):
docker compose up -d

# Produktion (ohne lokalen Traefik, nutzt externen):
docker compose -f docker-compose.yml up -d

# DEV-Server (neben Produktion):
docker compose -f docker-compose.dev-server.yml up -d
```

| Service | Port | Zweck |
|---------|------|-------|
| traefik | 80, 443, 8080 | Reverse-Proxy mit HTTPS |
| court | (via Traefik) | PHP 8.1 Apache |
| mariadb | 3306 | Datenbank |
| mailhog | 8025 | E-Mail-Testing |

**DEV vs PROD**: `INSTALL_XDEBUG=true/false` in `.env`

**Hinweis**: `vendor/` ist im Git committed (Produktion-Workflow). Composer wird **nicht** im Docker-Build ausgefuehrt.

## Zahlungssystem

### PayPal
PayPal-Konto erstellen (zuerst Sandbox, dann Live). NVP/SOAP-Credentials (Username, Password, Signature) in `config/autoload/local.php` eintragen.

### Stripe
Stripe-Konto erstellen, API-Keys (publishable + secret) in `config/autoload/local.php`. Gewuenschte Zahlungsmethoden im Stripe-Dashboard aktivieren.

**Webhook** fuer asynchrone Zahlungen (SEPA etc.):
- URL: `https://<domain>/payment/booking/webhook`
- Events: `payment_intent.canceled`, `payment_intent.payment_failed`, `payment_intent.succeeded`

**Apple Pay**: Domain im Stripe-Dashboard verifizieren.

### Klarna
Ueber Stripe als Zahlungsmethode verfuegbar.

### Unbezahlte Buchungen entfernen
Automatisches Loeschen via MySQL Scheduled Event (alle 15 Min, Buchungen aelter als 3 Stunden mit `directpay=true` und `status_billing=pending`):

```sql
SET GLOBAL event_scheduler = ON;
CREATE EVENT remove_unpaid_bookings
  ON SCHEDULE EVERY 15 MINUTE ON COMPLETION PRESERVE
  DO DELETE FROM bs_bookings
     WHERE status = 'single'
       AND status_billing = 'pending'
       AND created < (NOW() - INTERVAL 3 HOUR)
       AND bid IN (SELECT bid FROM bs_bookings_meta
                   WHERE `key` = 'directpay' AND `value` = 'true');
```

## Budget-System (Guthaben)

Benutzer koennen ein Prepaid-Guthaben haben (z.B. fuer Geschenkgutscheine). Verwaltung im Backend unter Benutzer-Bearbeitung.

- Budget deckt vollen Betrag ab: direkte Buchung ohne Payment-Gateway
- Budget deckt Teilbetrag ab: Restbetrag ueber PayPal/Stripe/Klarna
- Budget wird bei Stornierung/Loeschung zurueckerstattet

## Mitglieder-/Gast-Preise

Preisregeln in `bs_squares_pricing` mit `member`-Spalte:
- **Mitglieder**: Mitgliederpreis (z.B. kostenlos)
- **Nicht-Mitglieder**: voller Preis
- **Mitglied mit Gast**: 50% des Nicht-Mitglieder-Preises
- **Nicht-Mitglied mit Gast**: voller Preis (kein Rabatt)

## Konfiguration

| Datei | Zweck |
|-------|-------|
| `.env` | Docker-Umgebungsvariablen (Ports, DB-Credentials, Xdebug) |
| `config/autoload/local.php` | DB, Mail, Payment-API-Keys |
| `config/autoload/project.php` | URLs, Session, Payment-Toggles, Features |
| `config/init.php` | Dev-Modus, Timezone, Error-Reporting |

Alle `.dist`-Dateien enthalten Docker-kompatible Defaults.

**Wichtig**: Nach DB-Import pruefen, dass `bs_squares_pricing.date_end` das aktuelle Datum abdeckt, sonst werden keine Zahlungsoptionen angezeigt.

## Stripe-Templates

Die Stripe-Checkout-Seiten koennen ueber Twig-Templates angepasst werden:
- `vendor/payum/stripe/Payum/Stripe/Resources/views/Action/stripe_js.html.twig`
- `vendor/payum/stripe/Payum/Stripe/Resources/views/Action/stripe_confirm.html.twig`

## Lizenz

Basierend auf [tkrebs/ep3-bs](https://github.com/tkrebs/ep3-bs). Siehe [LICENSE](LICENSE).

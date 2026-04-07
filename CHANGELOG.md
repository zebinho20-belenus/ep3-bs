# Changelog

## v2.2.7 (2026-04-07)

### New Features

- **Audit-Log System**: Comprehensive audit logging for all system actions
  - New `bs_audit_log` DB table (auto-migration on startup)
  - Dual output: database + `data/log/audit.log` (JSON-per-line)
  - Event-based logging: booking create/cancel via `AuditListener`
  - Direct logging: login/login failure, payment success/failure
  - Backend UI: `/backend/audit` with filter (date, category, search), pagination, expandable detail view
  - Categories: booking (blue), payment (green), user (yellow), admin (red), config (gray), system (dark)

### Bug Fixes

- **Partial budget payment not deducted (#102)**: `hasBudget` meta was stored as boolean instead of string `'true'`, causing the comparison in `doneAction()` to fail after DB round-trip. Budget is now correctly deducted for partial payments (budget + PayPal).
- **CSP form-action blocking payments**: Added `form-action 'self' https://*.paypal.com https://*.stripe.com` to CSP header. Also added `object-src 'none'` and `base-uri 'self'`.

### Cleanup

- Removed all temporary debug logging (`file_put_contents`, `error_log DEBUG`)

<details>
<summary><b>Deutsche Zusammenfassung</b></summary>

**Audit-Log**: Neues System zur Nachvollziehbarkeit aller Aktionen (Buchungen, Zahlungen, Logins, Admin-Aktionen). DB-Tabelle + Logdatei + Backend-UI unter Konfiguration → Audit-Protokoll mit Filter und Pagination.

**Bugfix #102**: Teilzahlung mit Budget — Budget wurde nach PayPal-Zahlung nicht abgezogen weil `hasBudget` als boolean statt String gespeichert wurde.

**CSP**: `form-action` mit Payment-Gateway-Domains hinzugefuegt, verhindert Blockierung von PayPal/Stripe-Formularen.
</details>

---

## v2.2.6 Hotfix (2026-04-04)

### Bug Fixes

- **Billing status in cancelled booking emails**: Emails for cancelled bookings showed the technical billing status (e.g. "Ausstehend"/pending) instead of "Storniert". Now displays "Storniert" when `booking.status == cancelled`, regardless of the `status_billing` value. Applied to all 4 email methods via `formatBillsForEmail()`.

<details>
<summary><b>Deutsche Zusammenfassung</b></summary>

**Bugfix**: E-Mails fuer stornierte Buchungen zeigten den technischen Rechnungsstatus (z.B. "Ausstehend") statt "Storniert". Jetzt wird bei stornierten Buchungen immer "Storniert" angezeigt, unabhaengig vom `status_billing` Wert in der Datenbank.
</details>

---

## v2.2.5 Hotfix (2026-04-04)

### Improvements

- **Long subscription handling**: Subscriptions with many reservations (50-120+) no longer flood the UI or emails:
  - **Edit-mode dialog**: Compact date range summary + count. Only cancelled reservations listed individually (was showing every single reservation)
  - **Subscription edit table**: Scrollable container (max 300px) with sticky header for > 15 reservations. Summary count in header row
  - **Email bill section**: New `formatBillsForEmail()` — shows first 2, "... (N weitere Termine)", last item for > 5 positions. Applied to all 4 email methods
- **Booking list dual badge**: Cancelled reservations within active subscriptions show `[A][S]` (blue + red) instead of just `[S]`
- **Edit email formatting**: Combined time range ("Uhrzeit: 13:00 - 14:00 → 13:00 - 15:00 Uhr"). Context header "Geänderte Reservierung am DD.MM.YYYY:"
- **Cancel/delete email formatting**: Compact one-liner, booking number in header, affected reservation marked with "← gelöscht"/"← storniert" in overview

### Bug Fixes

- **Edit email wrong reservation for subscriptions**: Email showed first reservation instead of actually edited one. Fixed by loading via `rid`.

<details>
<summary><b>Deutsche Zusammenfassung</b></summary>

**Lange Abos**: Abos mit vielen Reservierungen (50-120+) ueberfluten nicht mehr die Oberflaeche oder E-Mails. Edit-Mode-Dialog zeigt kompakte Zusammenfassung + nur stornierte Reservierungen. Abo-Reservierungstabelle scrollbar (max 300px) mit festem Header. E-Mail-Rechnung: erste 2 + "... (N weitere Termine)" + letzte Position statt alle einzeln.

**Buchungsliste**: Stornierte Abo-Reservierungen zeigen Doppel-Badge `[A][S]` (blau+rot). **E-Mails**: Zeitaenderungen als kombinierte Zeile, Kontextzeile welche Reservierung geaendert wurde, Stornierungs-E-Mail mit Pfeil-Markierung.

**Bugfix**: Edit-E-Mail zeigte bei Abo-Buchungen die Daten der ersten Reservierung statt der bearbeiteten.
</details>

---

## v2.2.1 Hotfix (2026-04-04)

### Security Hardening (10 OWASP Audit Fixes)

- **SEC-001**: CSRF protection for booking cancellation (was missing, unlike confirmation form)
- **SEC-002**: Removed legacy MD5 password hash fallback + migration to delete remaining `legacy-pw` entries
- **SEC-003**: Session ID regeneration after login (prevents session fixation)
- **SEC-004**: Atomic budget deduction/refund via SQL `UPDATE` (prevents double-spend race condition)
- **SEC-006**: Booking ownership check in `doneAction` (defense-in-depth for leaked Payum tokens)
- **SEC-007**: Replaced `syslog()` with `error_log()` in webhook handlers
- **SEC-008**: Removed `unserialize()` fallback + migration to convert serialized player-names to JSON
- **SEC-009**: Removed commented-out test code in webhook handler (hardcoded `bid=1443`)
- **SEC-010**: Rate limiting on payment retries (max 5 attempts per booking per hour)

### Performance Optimization

- **OPcache** enabled (128MB bytecode cache) — 2-5x faster PHP responses
- **APCu** for Composer classmap caching (32MB) — eliminates filesystem lookups
- **MariaDB tuning**: `innodb_buffer_pool_size=256M`, `innodb_log_file_size=64M`, `O_DIRECT` flush
- **Apache mod_deflate** (gzip compression) — 60-80% less transfer for text assets
- **Apache mod_expires** + browser cache headers — static assets cached 1 year
- **SQL indexes** (migration 009): `uid+status`, `reservation status`, meta UNIQUE, pricing date+priority
- **`getByValidity()` SQL push-down**: Filters `cancelled`/`private` bookings in SQL instead of PHP (20-50% fewer rows loaded)
- **SquarePricingManager**: Pre-parsed date boundaries to timestamps (avoids repeated `DateTime` creation)
- **SquareManager**: Removed legacy `ALTER TABLE` checks from constructor
- **Service Worker**: Network-first for HTML navigation (fixes stale pages after deploy), cache-first for static assets only

### Features

- **Backend booking list pagination**: Server-side pagination with 100 items per page. Bootstrap 5 pagination controls (top + bottom). `countInRange()` method for efficient total count. Compatible with existing date range filters and search.

### Bug Fixes

- **Booking/reservation edit redirect**: After saving changes to a booking or reservation in the backend edit form, the window stayed open instead of redirecting back. The controller fell through to form rendering after successful update. Now redirects to calendar with flash message.

<details>
<summary><b>Deutsche Zusammenfassung</b></summary>

**Sicherheit**: 10 Schwachstellen aus OWASP-Audit behoben — CSRF-Schutz fuer Stornierung, Session-Fixation-Schutz, atomare Budget-Operationen (verhindert Doppelabbuchung), Rate-Limiting bei Zahlungsversuchen, Legacy-MD5-Hashes entfernt, Booking-Ownership-Check bei Zahlungs-Callbacks.

**Performance**: PHP OPcache (2-5x schnellere Antwortzeiten), APCu-Cache fuer Classloading, MariaDB-Tuning (256MB Buffer Pool), Gzip-Kompression, Browser-Caching fuer statische Dateien, zusaetzliche DB-Indexes, SQL-Filterung statt PHP-Filterung bei Buchungsabfragen, optimierte Preisberechnung, Service Worker mit Network-first fuer HTML.

**Neue Funktionen**: Backend-Buchungsliste mit serverseitiger Paginierung (100 pro Seite) mit Bootstrap 5 Seitennavigation.

**Bugfix**: Nach dem Speichern einer Buchungs-/Reservierungsaenderung im Backend blieb das Bearbeitungsfenster offen statt zum Kalender zurueckzuleiten.
</details>

---

## v2.2 (2026-04-04)

### Features

- **Subscription booking management ([#100](https://github.com/zebinho20-belenus/ep3bs-payment/issues/100), [#101](https://github.com/zebinho20-belenus/ep3bs-payment/issues/101))**: Complete overhaul of subscription (Abo) booking management:
  - **Reservation overview table**: Subscription edit view now shows all reservations with status, date, day, time, and cancel/reactivate actions
  - **Individual reservation editing**: Court, billing status, player count, and notes now editable per-reservation (previously disabled)
  - **Individual reservation cancel/delete**: Single reservations within a subscription can be cancelled or deleted independently, with separate email notifications and automatic booking cancellation when all reservations are removed
  - **Individual reservation reactivation**: Cancelled reservations within active subscriptions can be reactivated from both the subscription edit table and the backend booking list (per-row icon + bulk action)
  - **Subscription reactivation**: Cancelled subscription bookings now restore to `subscription` status (was incorrectly set to `single`), with all cancelled reservations automatically reactivated
  - **Conflict detection**: Booking creation now checks for existing bookings in the time slot and shows a warning dialog with conflicting booking details (name, date, time, court, type). Users can override and create anyway

### Bug Fixes

- **Booking conflict name shows '?'**: Conflict dialog displayed '?' instead of user name because `getExtra('user')` was never populated by `getByReservations()`. Now loads user via `UserManager::get(uid)` ([#101](https://github.com/zebinho20-belenus/ep3bs-payment/issues/101))
- **False conflict detection**: Conflict check incorrectly flagged the booking being edited as a conflict with itself. Fixed by excluding current booking from overlap check ([#100](https://github.com/zebinho20-belenus/ep3bs-payment/issues/100))
- **Reservation status in booking list**: Cancelled individual reservations within active subscriptions now show cancelled status icon and grey styling in booking list ([#100](https://github.com/zebinho20-belenus/ep3bs-payment/issues/100))
- **Cancel booking when all reservations removed**: Booking status automatically set to `cancelled` when the last reservation is cancelled or deleted ([#100](https://github.com/zebinho20-belenus/ep3bs-payment/issues/100))
- **Reservation entity status property**: Added `status` field to Reservation entity for individual reservation lifecycle ([#100](https://github.com/zebinho20-belenus/ep3bs-payment/issues/100))

<details>
<summary><b>Deutsche Zusammenfassung</b></summary>

**Abo-Verwaltung komplett ueberarbeitet**: Reservierungsuebersicht mit Status/Datum/Uhrzeit und Stornieren/Reaktivieren pro Reservierung. Einzelne Abo-Reservierungen koennen jetzt bearbeitet (Platz, Rechnungsstatus, Spieleranzahl), storniert und reaktiviert werden. Stornierte Abos werden korrekt als `subscription` (nicht `single`) wiederhergestellt. Neue Kollisionserkennung bei Buchungserstellung mit Warnungsdialog.

**Bugfixes**: Benutzername in Kollisionsdialog zeigte '?' statt Namen. Falsche Kollisionserkennung beim Bearbeiten eigener Buchung. Stornierte Einzelreservierungen zeigen korrekten Status in Buchungsliste. Buchung wird automatisch storniert wenn letzte Reservierung entfernt wird.
</details>

---

## v2.1.1 (2026-03-18)

### Features

- **Administration mode ([#98](https://github.com/zebinho20-belenus/ep3bs-payment/issues/98))**: New third system state "Administration" (between Enabled and Maintenance). Allows `admin` (Verwalter) and `assist` (Mitarbeiter) users to log in and make bookings while regular users are blocked. Configurable via Backend → Configuration → Behaviour. Shows dedicated status page with "Buchungen nur für Mitarbeiter" message.

<details>
<summary><b>Deutsche Zusammenfassung</b></summary>

**Verwaltungsmodus**: Neuer dritter Systemstatus zwischen "Aktiviert" und "Wartung". Nur Verwalter und Mitarbeiter koennen sich einloggen und Buchungen vornehmen. Regulaere Benutzer sehen eine Statusseite mit "Buchungen nur fuer Mitarbeiter". Konfigurierbar unter Backend → Konfiguration → Verhalten.
</details>

---

## v2.1.1 (2026-03-17)

### Bug Fixes

- **Event overlay refactoring (#94)**: Complete rewrite of `updateCalendarEvents()` — `getBoundingClientRect()`, `tdRect()`/`createOverlay()` helpers, z-index 256, `.calendar-event-overlay` class. **Root cause fix:** `[id$='-overlay-']` selector never matched (IDs end with `-overlay-0`), so overlays accumulated and were never hidden on resize. SW `v3.10` → `v3.16`.
- **Mobile squarebox layout (#97)**: Fixed mobile booking confirmation modal: close button top-right (was bottom, `append` → `prepend`); pricing table 2-column on mobile (duration/players as `.ps-detail-col` hidden, shown as compact `.ps-meta` line in first cell — no scroll; total row `colspan` replaced with separate hidden cells); rules text no height cap on mobile (squarebox scrolls itself).
- **Uniform email salutation (#81)**: All outgoing emails now use "Hallo Vorname Nachname" (fallback: alias). Removed gender-based "Sehr geehrter Herr/Sehr geehrte Frau" from all email-sending locations (Backend cancel/reactivate/edit/bulk, Square cancel/payment-failed, User MailService for booking confirmations).

### Features

- **Reactivate permission (#82)**: New `calendar.reactivate-bookings` privilege for assist users. Reactivation of cancelled bookings can now be granted/denied independently of the general `admin.booking` permission. Permission check added in all views (edit form, bulk action, booking list) and controllers.
- **My bookings smart-sort & notification badge (#65, #71)**: Notification badge on "My bookings" (orange = unpaid, green = upcoming). Badge click opens a popover with next 4 bookings summary — desktop only (touch devices: badge is indicator only, no tap conflict). Bookings grouped and sorted: 1) pending future (nearest first), 2) upcoming future (nearest first), 3) past (newest first). Smart default filter auto-selects "Pending" → "Upcoming" → "All".
- **Bookings filter & mobile card layout**: Filter toggle buttons (All/Upcoming/Pending) on "My bookings" page. Mobile-responsive card layout for booking rows with `data-label` pseudo-element labels.
- **Clickable pending bookings**: Pending booking rows (with unpaid bills) are fully clickable — entire row/card navigates to the bill page, not just the price button.
- **Mobile-friendly bills page**: Bill table uses stacked card layout on mobile (< 576px) instead of horizontal-scrolling table. Payment option buttons stack vertically on small screens. Long bill descriptions wrap with `word-break`. Labels shown as block above values. Duplicate "Gesamt" label in total row hidden.
- **Datepicker z-index fix (#96)**: Datepicker in squarebox now appears above the modal (z-index 2048 > 1536).
- **Event overlay improvements (#94)**: Multi-column events show name only once (middle overlay). Fixed 1-hour multi-court events invisible (`< 2` → `< 1`). Debounced resize handler (150ms) prevents overlay flicker. Added `orientationchange` for mobile rotation.
- **Calendar mobile UX**: Clean cell display — "Frei" hidden (color sufficient), own bookings show ✓, pending show !, occupied/abo color-only. Color legend below datepicker (mobile-only).
- **Event admin search (#95)**: Default date range expanded to ±2 weeks. "New event" button always visible. Event datepicker inputs widened to 120px with centered date text.

<details>
<summary><b>Deutsche Zusammenfassung</b></summary>

**Bugfixes**: Veranstaltungs-Overlay komplett ueberarbeitet (Overlays wurden nicht korrekt entfernt). Mobile Buchungsbestaetigung: X-Button oben rechts, Preistabelle 2-spaltig ohne Scrollen, Regeltext ohne Hoehenlimit. Einheitliche E-Mail-Anrede "Hallo Vorname Nachname" in allen E-Mails.

**Neue Funktionen**: Eigene Berechtigung fuer Reaktivierung stornierter Buchungen. "Meine Buchungen" mit Smart-Sort (unbezahlt zuerst), Benachrichtigungsbadge (orange/gruen), Filter-Buttons. Offene Buchungen als Karte klickbar. Mobile Rechnungsansicht. Datepicker ueber Squarebox. Verbesserte Veranstaltungs-Overlays. Kalender-Mobilansicht (kompakter). Veranstaltungssuche mit erweitertem Datumsbereich.
</details>

---

## v2.1 (2026-03-17)

61 commits since v2.0 (2026-03-07). All changes on branch `dev_sh_docker_devops`.

### Features

- **Payment method tracking (#85)**: `paymentMethod` meta stored on each booking, shown as tag in backend booking notes. New `payment_method` column in backend booking list (responsive-pass-2).
- **Per-user booking limit override**: Admin can set `maxActiveBookings` in user meta to override the global booking limit per user.
- **Opening times migration (#84)**: New `bs_squares_opening_times` table via migration 005 for configurable court opening hours.

### Bug Fixes

- **Payment token handling (#85)**: Graceful error handling for invalid/expired Payum tokens. Session-independent success/error messages via query parameters instead of flash messages. Fixed false error on successful token reuse. Email sender address restored for payment/cancellation notifications. PayPal pending-on-abort no longer treated as success.
- **Cancellation emails (#89)**: Eliminated duplicate emails (controller + listener both sending). Fixed guest salutation showing "Herr/Frau" for users without gender. Corrected German cancellation email subject to show court name. Aligned cancellation/payment-failed email style with backend emails.
- **Squarebox booking form (#91)**: Consolidated form initialization into single `initBookingForm()` after AJAX load. Fixed autocomplete dropdown hidden behind squarebox z-index.
- **Datepicker arrows (#92)**: jQuery UI datepicker prev/next arrows invisible — replaced sprite-based icons with Unicode arrows via CSS.
- **Booking limit slots (#93)**: Limit check now sums reservation slot durations instead of counting 1 per reservation. A 2-hour booking counts as 2 slots.
- **Event overlay (#94)**: Complete rewrite of `updateCalendarEvents()` — fixed `$.inArray` array-vs-string comparison, off-by-one loop bound, per-court-column grouping, multi-column merge into single wide overlay, datepicker z-index above overlays, centered overlay label text.
- **Config cache**: Disabled config cache — Payum objects cannot be serialized with `var_export`.
- **PHP 8.4 deprecations**: Fixed `SID` constant usage, null parameter warnings, `Exception` code type.
- **Migration parser**: Fixed comment filtering that skipped `CREATE TABLE` statements.
- **Backend bill edit**: Fixed crash when using "create default" button.
- **Booking bill actions (#86)**: Delete/create bill items now require POST, not GET.
- **Duplicate guest payment message**: Removed duplicate flash message on confirmation page.

### UI/UX

- **Event admin search (#95)**: Default date range expanded from +2 days to ±2 weeks. "New event" button now always visible (not only when no results found), styled as `default-button`.
- **Event popup styling**: Icons, better spacing, fixed unclosed `<p>` tag.
- **Squarebox improvements**: Scrollable on desktop, improved visual hierarchy, smooth guest player info transition.
- **Logo updates (#88)**: New TCN Kail e.V. 2026 branding — SVG source, ImageMagick rendering, proper icon sizes for PWA manifest.
- **Rules text**: Scrollable container on confirmation page for compact layout.
- **Mobile header**: Show short system name on mobile.
- **Login page**: Modernized header and datepicker navigation.

### Infrastructure

- **MariaDB volume**: Use `./db` path to match production layout.
- **Traefik**: Removed basic auth from DEV instance.
- **.gitignore**: Added testplan files and Payum cache directory.
- **Cleanup**: Removed unused Stripe CSS, old versioned files, nginx config, local temp files.
- **Robots.txt**: Block bots from `/square` paths; graceful redirect for invalid square requests.

### Security

- **Additional audit findings (#90)**: CSRF protection for all backend forms, destructive actions via POST only.

### Documentation

- Updated CLAUDE.md and README.md with all fixes and new features.
- Bootstrap version corrected to 5.3.8.
- Added migrations 003-005 to documentation.
- Removed dead links (MIGRATION-PLAN.md, FEATURE-CHECKLIST.md).
- Shortened Laravel migration section.

<details>
<summary><b>Deutsche Zusammenfassung</b></summary>

61 Commits seit v2.0. **Neue Funktionen**: Zahlungsmethode wird pro Buchung gespeichert und im Backend angezeigt. Individuelles Buchungslimit pro Benutzer. Oeffnungszeiten-Tabelle (Migration 005).

**Bugfixes**: Verbesserte Payum-Token-Behandlung, sessionsunabhaengige Erfolgsmeldungen. Doppelte Stornierungs-E-Mails beseitigt. Squarebox-Formular konsolidiert. Datepicker-Pfeile sichtbar. Buchungslimit zaehlt Zeitslots statt Reservierungen. Veranstaltungs-Overlay komplett neu geschrieben. PHP 8.4 Deprecation-Warnungen behoben. Config-Cache deaktiviert (Payum nicht serialisierbar).

**UI/UX**: Veranstaltungssuche erweitert, Squarebox scrollbar, neues Logo, Regeltext scrollbar, mobile Header, modernisierte Login-Seite.

**Sicherheit**: CSRF-Schutz fuer alle Backend-Formulare, destruktive Aktionen nur per POST.
</details>

---

## v2.0 (2026-03-07)

Initial release of the extended EP3-BS fork with:
- PHP 8.4, Docker setup (Traefik + MariaDB + MailHog)
- Payment integration (PayPal, Stripe with SCA, Klarna)
- Budget/gift card system
- Member/guest pricing with 50% guest discount
- Loxone door control integration
- PWA support
- Auto-registration with member email verification
- Comprehensive OWASP Top 10 security hardening
- Bootstrap 5.3.8 UI, jQuery 3.7.1, TinyMCE 6.8.5
- Auto-migration system (MigrationManager)

<details>
<summary><b>Deutsche Zusammenfassung</b></summary>

Erster Release des erweiterten EP3-BS Forks: PHP 8.4, Docker-Setup (Traefik + MariaDB + MailHog), Zahlungsintegration (PayPal, Stripe mit SCA, Klarna), Guthaben-/Gutscheinsystem, Mitglieder-/Gastpreise mit 50% Gastrabatt, Loxone-Tuersteuerung, PWA-Unterstuetzung, Auto-Registrierung mit Mitglieder-E-Mail-Verifizierung, umfassende OWASP Top 10 Sicherheitshaertung, Bootstrap 5.3.8, jQuery 3.7.1, TinyMCE 6.8.5, Auto-Migrationssystem.
</details>

# Changelog

## v2.1.1 (2026-03-17)

### Bug Fixes

- **Service worker cache + event label force-hide (#94)**: SW cache `v3.10` → `v3.12`. Event cell labels force-hidden with `!important` + `font-size: 0` (CSS + JS). JS label-hiding moved before overlay creation. Overlay hidden on resize, recalculated after 150ms debounce.
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

# Mitarbeiter-Handbuch – Tennisplatzbuchung

Dieses Handbuch richtet sich an **Mitarbeiter** (Assist-Benutzer) des Buchungssystems.

---

## Anmelden im Verwaltungsmodus

Wenn das System im **Verwaltungsmodus** ist, können sich nur Admins und Mitarbeiter einloggen. Mitglieder und Gäste sehen eine Sperrseite.

> **Login-URL:** `https://[domain]/user/login`
>
> *(Kein Link auf der Sperrseite — URL direkt im Browser eingeben)*

Nach dem Login gelangst du direkt zum Kalender und kannst Buchungen anlegen, bevor das System für alle geöffnet wird.

---

## Buchung für ein Mitglied anlegen

1. Kalender öffnen → freien Slot anklicken
2. Im Buchungsformular den **Namen des Mitglieds** eintragen
3. Dauer und ggf. Mitspieler auswählen
4. **Weiter** → Bestätigungsseite → Buchung abschließen
5. Das Mitglied erhält automatisch eine Bestätigungs-E-Mail

---

## Backend-Zugriff (`/backend`)

Mitarbeiter haben je nach Berechtigung Zugriff auf folgende Bereiche:

| Bereich | Aktionen |
|---------|----------|
| **Buchungen** | Einsehen, bearbeiten, stornieren |
| **Buchungen reaktivieren** | Nur mit Berechtigung `calendar.reactivate-bookings` |
| **Veranstaltungen** | Anlegen, bearbeiten, löschen |

---

## Buchung stornieren (Backend)

1. Backend → **Buchungen**
2. Gewünschte Buchung in der Liste suchen
3. Klick auf **×** (Stornieren-Symbol)
4. Bestätigungsseite: Stornierung mit **Ja** bestätigen
5. Vorhandenes Budget wird automatisch zurückgebucht

---

## Buchung reaktivieren

Stornierte Buchungen können reaktiviert werden, sofern der Zeitslot noch frei ist:

1. Backend → Buchungen → stornierte Buchung suchen
2. Klick auf **↺** (Reaktivieren-Symbol)
3. Kollisionsprüfung erfolgt automatisch — Symbol erscheint nur, wenn der Slot frei ist

> Reaktivierung erfordert die Berechtigung `calendar.reactivate-bookings`.
> Wende dich an einen Admin, falls das Symbol nicht sichtbar ist.

---

## Systemstatus (nur für Admins sichtbar)

Das System kennt drei Betriebsmodi:

| Modus | Wer kann sich anmelden |
|-------|------------------------|
| **Aktiviert** | Alle Benutzer |
| **Verwaltungsmodus** | Admins + Mitarbeiter |
| **Wartungsarbeiten** | Nur Admins |

Einstellung: Backend → **Konfiguration → Verhalten → System**

---

## Häufige Fragen

**Ich kann mich nicht einloggen.**
Prüfe, ob das System im Wartungsmodus ist. Im Wartungsmodus können sich nur Admins einloggen — wende dich an einen Admin.

**Das Reaktivieren-Symbol fehlt.**
Entweder ist der Zeitslot bereits belegt, oder die Berechtigung `calendar.reactivate-bookings` fehlt. Admin fragen.

**Eine Buchung zeigt kein Zahlungsbutton.**
Zahlungsoptionen erscheinen nur, wenn der Gesamtbetrag > 0 € und eine gültige Preisregel für das Datum hinterlegt ist.
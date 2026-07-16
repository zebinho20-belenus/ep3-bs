<?php
/**
 * ep3-bs booking diagnostic CLI.
 *
 * Read-only forensic inspector + integrity/anomaly scanner. The only writes
 * happen with `scan --alert` (audit-log entries + a summary e-mail).
 *
 * Usage:
 *   php scripts/diagnose.php inspect-booking <bid> [--json]
 *   php scripts/diagnose.php inspect-reservation <rid> [--json]
 *   php scripts/diagnose.php inspect-slot <YYYY-MM-DD> <sid> [HH:MM] [HH:MM] [--json]
 *   php scripts/diagnose.php scan [<from> <to>] [--checks=a,b|--all] [--severity=critical|warning|info] [--json] [--alert]
 *   php scripts/diagnose.php list-checks [--json]
 *
 * Exit codes: 0 = clean, 1 = findings present, 2 = usage/runtime error.
 *
 * NOTE: the application is initialised WITHOUT triggering the MVC bootstrap
 * event, so auto-migrations (a write) never run from this tool.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script may only be run from the command line.\n");
    exit(2);
}

chdir(dirname(__DIR__));

require 'vendor/autoload.php';
require 'config/init.php';

/* Keep stdout clean for report/JSON output: HTML error formatting makes no
 * sense on the CLI, deprecation notices from vendored ZF2 are noise, and any
 * warning must go to stderr so it never corrupts --json output. */
ini_set('html_errors', '0');
ini_set('display_errors', 'stderr');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

/* ---- Bootstrap service manager without running the MVC bootstrap ---- */

$configuration  = require 'config/application.php';
$smConfig       = isset($configuration['service_manager']) ? $configuration['service_manager'] : array();
$serviceManager = new Zend\ServiceManager\ServiceManager(new Zend\Mvc\Service\ServiceManagerConfig($smConfig));
$serviceManager->setService('ApplicationConfig', $configuration);
$serviceManager->get('ModuleManager')->loadModules();

/** @var \Booking\Service\BookingDiagnosticService $service */
$service = $serviceManager->get('Booking\Service\BookingDiagnosticService');

/* ---- Argument parsing ---- */

$argv = $_SERVER['argv'];
array_shift($argv); // script name

$command    = null;
$positional = array();
$flags      = array();

foreach ($argv as $arg) {
    if (strpos($arg, '--') === 0) {
        $eq = strpos($arg, '=');
        if ($eq !== false) {
            $flags[substr($arg, 2, $eq - 2)] = substr($arg, $eq + 1);
        } else {
            $flags[substr($arg, 2)] = true;
        }
    } elseif ($command === null) {
        $command = $arg;
    } else {
        $positional[] = $arg;
    }
}

$json = isset($flags['json']);

function out_json($data)
{
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
}

function parse_date($value, $fallback)
{
    if ($value === null) {
        return new DateTime($fallback);
    }

    try {
        return new DateTime($value);
    } catch (Exception $e) {
        fwrite(STDERR, "Invalid date: $value\n");
        exit(2);
    }
}

/* ---- Commands ---- */

switch ($command) {

    case 'list-checks':
        $checks = $service->getRegistry()->all();
        if ($json) {
            $data = array();
            foreach ($checks as $key => $check) {
                $data[] = array('key' => $key, 'category' => $check->getCategory(),
                                'needsDateRange' => $check->needsDateRange(), 'description' => $check->getDescription());
            }
            out_json($data);
            exit(0);
        }
        ksort($checks);
        echo "Verfügbare Prüfungen:\n\n";
        $lastCat = null;
        foreach ($checks as $key => $check) {
            if ($check->getCategory() !== $lastCat) {
                $lastCat = $check->getCategory();
                echo "  [" . strtoupper($lastCat) . "]\n";
            }
            printf("    %-32s %s%s\n", $key, $check->getDescription(), $check->needsDateRange() ? ' (Zeitraum)' : '');
        }
        exit(0);

    case 'inspect-booking':
        if (! isset($positional[0])) {
            fwrite(STDERR, "Usage: inspect-booking <bid>\n");
            exit(2);
        }
        $result = $service->inspectBooking((int) $positional[0]);
        if ($result === null) {
            fwrite(STDERR, "Booking #{$positional[0]} not found.\n");
            exit(2);
        }
        if ($json) {
            out_json($result);
            exit(0);
        }
        print_inspect_booking($result);
        exit(0);

    case 'inspect-reservation':
        if (! isset($positional[0])) {
            fwrite(STDERR, "Usage: inspect-reservation <rid>\n");
            exit(2);
        }
        $result = $service->inspectReservation((int) $positional[0]);
        if ($result === null) {
            fwrite(STDERR, "Reservation #{$positional[0]} not found.\n");
            exit(2);
        }
        if ($json) {
            out_json($result);
            exit(0);
        }
        print_inspect_booking($result);
        exit(0);

    case 'inspect-slot':
        if (! isset($positional[0], $positional[1])) {
            fwrite(STDERR, "Usage: inspect-slot <YYYY-MM-DD> <sid> [HH:MM] [HH:MM]\n");
            exit(2);
        }
        $result = $service->inspectSlot(
            $positional[0], (int) $positional[1],
            isset($positional[2]) ? $positional[2] : null,
            isset($positional[3]) ? $positional[3] : null
        );
        if ($json) {
            out_json($result);
            exit(0);
        }
        print_inspect_slot($result);
        exit(0);

    case 'scan':
        $from = parse_date(isset($positional[0]) ? $positional[0] : null, 'today');
        $to   = parse_date(isset($positional[1]) ? $positional[1] : null, '+42 days');

        $checkKeys = array();
        if (isset($flags['checks']) && is_string($flags['checks'])) {
            $checkKeys = explode(',', $flags['checks']);
        }

        $severity = isset($flags['severity']) && is_string($flags['severity']) ? $flags['severity'] : null;

        $findings = $service->scan($checkKeys, $from, $to);
        $shown    = $severity ? $service->filterBySeverity($findings, $severity) : $findings;

        if (isset($flags['alert'])) {
            $alertThreshold = $severity ?: \Booking\Service\Diagnostic\Finding::SEVERITY_WARNING;
            $recorded = $service->recordAlerts($service->filterBySeverity($findings, $alertThreshold));
            if (! $json) {
                fwrite(STDERR, "Alerts recorded: $recorded\n");
            }
        }

        if ($json) {
            out_json(array_map(function ($f) { return $f->toArray(); }, $shown));
        } else {
            print_scan($shown, $from, $to);
        }

        exit(empty($shown) ? 0 : 1);

    default:
        fwrite(STDERR, <<<TXT
ep3-bs Diagnose-Tool

  inspect-booking <bid> [--json]
  inspect-reservation <rid> [--json]
  inspect-slot <YYYY-MM-DD> <sid> [HH:MM] [HH:MM] [--json]
  scan [<from> <to>] [--checks=a,b|--all] [--severity=critical|warning|info] [--json] [--alert]
  list-checks [--json]

TXT
        );
        exit(2);
}

/* ---- Text formatters ---- */

function print_inspect_booking(array $r)
{
    $b = $r['booking'];
    echo "=== Buchung #{$b['bid']} ===\n";
    printf("  Platz:   %s (sid %d)\n", $r['square'], $b['sid']);
    printf("  Nutzer:  %s (uid %d)\n", $r['user'], $b['uid']);
    printf("  Status:  %s / Rechnung: %s / Sichtbarkeit: %s / Menge: %s\n",
        $b['status'], $b['status_billing'], $b['visibility'], $b['quantity']);
    printf("  Erstellt: %s\n", isset($b['created']) ? $b['created'] : '?');

    if ($r['meta']) {
        echo "  Meta:\n";
        foreach ($r['meta'] as $k => $v) {
            printf("    %-20s %s\n", $k, $v);
        }
    }

    echo "\n  Reservierungen:\n";
    printf("    %-6s %-10s %-13s %-18s %-12s %-10s %s\n", 'rid', 'Datum', 'Zeit', 'Platz (eff.)', 'Res-Status', 'Rechnung', 'verschoben');
    $focusRid = isset($r['focusRid']) ? (int) $r['focusRid'] : null;
    foreach ($r['reservations'] as $res) {
        $marker = ($focusRid !== null && (int) $res['rid'] === $focusRid) ? '  <== geprüfte Reservierung' : '';
        printf("    %-6s %-10s %-13s %-18s %-12s %-10s %s%s\n",
            $res['rid'], $res['date'],
            substr($res['time_start'], 0, 5) . '-' . substr($res['time_end'], 0, 5),
            $res['effective_name'],
            $res['status'] !== null && $res['status'] !== '' ? $res['status'] : 'confirmed',
            $res['effective_billing'],
            $res['moved'] ? 'JA (Basis sid ' . (int) $b['sid'] . ')' : '-',
            $marker);
    }

    if ($r['bills']) {
        echo "\n  Rechnungspositionen:\n";
        foreach ($r['bills'] as $bill) {
            printf("    %-40s %8.2f €\n", $bill['description'], $bill['price'] / 100);
        }
        printf("    %-40s %8.2f €\n", 'SUMME', $r['billTotal'] / 100);
    }

    print_timeline_block($r['timeline'], '  ');
}

function print_inspect_slot(array $r)
{
    printf("=== %s am %s", $r['square'], $r['date']);
    if ($r['timeStart']) {
        printf(" %s-%s", $r['timeStart'], $r['timeEnd'] ?: '');
    }
    echo " ===\n";

    if (! $r['reservations']) {
        echo "  Keine (effektiven) Reservierungen auf diesem Platz.\n";
        return;
    }

    printf("  %-6s %-6s %-13s %-14s %-13s %-10s %s\n", 'rid', 'bid', 'Zeit', 'Basis→eff sid', 'Buch.-Status', 'Rechnung', 'Nutzer');
    foreach ($r['reservations'] as $res) {
        printf("  %-6s %-6s %-13s %-14s %-13s %-10s %s\n",
            $res['rid'], $res['bid'],
            substr($res['time_start'], 0, 5) . '-' . substr($res['time_end'], 0, 5),
            $res['base_sid'] . '→' . $res['effective_sid'],
            $res['booking_status'], $res['status_billing'], $res['user']);
    }

    foreach ($r['timelines'] as $bid => $timeline) {
        echo "\n  Verlauf Buchung #$bid:\n";
        print_timeline_block($timeline, '    ');
    }
}

function print_timeline_block(array $timeline, $indent)
{
    if (! $timeline) {
        echo "\n{$indent}Verlauf: (keine Audit-Einträge — evtl. > Aufbewahrungsfrist)\n";
        return;
    }

    echo "\n{$indent}Verlauf (neueste zuerst):\n";
    foreach ($timeline as $entry) {
        printf("%s  %s  %-8s %-16s %s\n", $indent, $entry['created'], $entry['action'],
            $entry['user_name'] !== null ? $entry['user_name'] : '-', $entry['message']);
    }
}

function print_scan(array $findings, DateTime $from, DateTime $to)
{
    printf("Integritäts-Scan (Zeitraum %s bis %s)\n", $from->format('Y-m-d'), $to->format('Y-m-d'));

    if (! $findings) {
        echo "Keine Auffälligkeiten gefunden.\n";
        return;
    }

    $grouped = array('critical' => array(), 'warning' => array(), 'info' => array());
    foreach ($findings as $f) {
        if (isset($grouped[$f->severity])) {
            $grouped[$f->severity][] = $f;
        }
    }
    printf("Gefunden: %d (kritisch %d, Warnung %d, Info %d)\n",
        count($findings), count($grouped['critical']), count($grouped['warning']), count($grouped['info']));
    echo "Tipp: mit --severity=critical|warning eingrenzen, --json für maschinenlesbare Ausgabe.\n";

    $labels = array('critical' => 'KRITISCH', 'warning' => 'WARNUNG', 'info' => 'INFO');
    foreach ($grouped as $severity => $items) {
        if (! $items) {
            continue;
        }
        printf("\n[%s] (%d)\n", $labels[$severity], count($items));
        foreach ($items as $f) {
            printf("  %-30s %s\n", $f->key, $f->title);
        }
    }
}

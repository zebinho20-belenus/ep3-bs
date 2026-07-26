<?php
/**
 * ep3-bs payment maintenance CLI.
 *
 * Two jobs, both meant to run from cron inside the `court` container:
 *
 *   reconcile — asks PayPal what became of payments that are still under review
 *               (PAYMENTSTATUS=Pending / PENDINGREASON=paymentreview, echeck, ...) and
 *               settles the booking: paid, cancelled, or kept open with an admin alert.
 *   remind    — sends one payment reminder for checkouts the member abandoned, before the
 *               remove_unpaid_bookings MySQL event deletes the reservation.
 *
 * Usage:
 *   php scripts/payments.php reconcile [--dry-run] [--json]
 *   php scripts/payments.php remind    [--dry-run] [--json]
 *
 * Exit codes: 0 = nothing to report / all clean, 1 = actions taken or errors, 2 = usage error.
 *
 * NOTE: like scripts/diagnose.php this bootstraps the service manager WITHOUT the MVC
 * bootstrap event, so auto-migrations never run from here.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script may only be run from the command line.\n");
    exit(2);
}

chdir(dirname(__DIR__));

require 'vendor/autoload.php';
require 'config/init.php';

ini_set('html_errors', '0');
ini_set('display_errors', 'stderr');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

/* ---- Argument parsing ---- */

$argv = $_SERVER['argv'];
array_shift($argv);

$command = null;
$flags   = array();

foreach ($argv as $arg) {
    if (strpos($arg, '--') === 0) {
        $flags[substr($arg, 2)] = true;
    } elseif ($command === null) {
        $command = $arg;
    }
}

if (! in_array($command, array('reconcile', 'remind'), true)) {
    fwrite(STDERR, "Usage: php scripts/payments.php reconcile|remind [--dry-run] [--json]\n");
    exit(2);
}

$dryRun = isset($flags['dry-run']);
$json   = isset($flags['json']);

/* ---- Bootstrap service manager without running the MVC bootstrap ---- */

$configuration  = require 'config/application.php';
$smConfig       = isset($configuration['service_manager']) ? $configuration['service_manager'] : array();
$serviceManager = new Zend\ServiceManager\ServiceManager(new Zend\Mvc\Service\ServiceManagerConfig($smConfig));
$serviceManager->setService('ApplicationConfig', $configuration);
$serviceManager->get('ModuleManager')->loadModules();

/** @var \Booking\Service\PaymentReconciliationService $service */
$service = $serviceManager->get('Booking\Service\PaymentReconciliationService');

/* ---- Run ---- */

$report = ($command === 'reconcile') ? $service->reconcile($dryRun) : $service->remindUnpaid($dryRun);

if ($json) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
} else {
    $stamp = date('Y-m-d H:i:s');
    $prefix = $dryRun ? '[dry-run] ' : '';

    if ($command === 'reconcile') {
        printf("%s %s%s: %d geprueft, %d bezahlt, %d storniert, %d offen gelassen, %d weiter in Pruefung, %d Fehler\n",
            $stamp, $prefix, 'reconcile', $report['checked'], $report['paid'], $report['cancelled'],
            $report['kept_open'], $report['still_review'], $report['errors']);
    } else {
        printf("%s %s%s: %d geprueft, %d erinnert, %d uebersprungen, %d Fehler (Fenster %s)\n",
            $stamp, $prefix, 'remind', $report['checked'], $report['reminded'],
            $report['skipped'], $report['errors'], $report['window']);
    }

    foreach ($report['items'] as $item) {
        $bid = isset($item['bid']) ? '#' . $item['bid'] : '-';
        $extra = array();

        foreach (array('paypalStatus', 'pendingReason', 'reviewDays', 'minutesLeft', 'message') as $key) {
            if (isset($item[$key]) && $item[$key] !== '') {
                $extra[] = $key . '=' . $item[$key];
            }
        }

        printf("  %-8s %-16s %s\n", $bid, $item['result'], implode(' ', $extra));
    }
}

$touched = ($command === 'reconcile')
    ? ($report['paid'] + $report['cancelled'] + $report['errors'])
    : ($report['reminded'] + $report['errors']);

exit($touched > 0 ? 1 : 0);

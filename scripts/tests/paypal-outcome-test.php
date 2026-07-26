<?php
/**
 * Standalone harness for the PayPal outcome classification.
 *
 * Runs the REAL classes (no copies):
 *   - Booking\Service\PaymentReconciliationService::classifyPaypalStatus()  (static)
 *   - Square\Controller\BookingController::evaluatePaypalOutcome()          (private, via Reflection)
 *
 * Usage (inside the court container, or any php:8.4-cli image with the repo mounted):
 *   docker compose exec court php scripts/tests/paypal-outcome-test.php
 *
 * Exit code 0 = all cases pass. Extend the case list whenever a new PayPal response shape
 * shows up in data/payum/ — this file is the regression net for the cancel-vs-review decision.
 */

chdir(dirname(dirname(__DIR__)));
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
ini_set('display_errors', 'stderr');

require 'vendor/autoload.php';

/* ZF2 module classes are not in the composer classmap — map PSR-0/4 style module paths. */
$root = getcwd();
spl_autoload_register(function ($class) use ($root) {
    $parts = explode('\\', $class);
    $module = $parts[0];
    $path = $root . '/module/' . $module . '/src/' . implode('/', $parts) . '.php';

    if (is_readable($path)) {
        require $path;
    }
});

/* Minimal GetHumanStatus stub — evaluatePaypalOutcome only asks isCaptured/isAuthorized. */
class StatusStub
{
    private $captured;
    private $authorized;

    public function __construct($captured = false, $authorized = false)
    {
        $this->captured = $captured;
        $this->authorized = $authorized;
    }

    public function isCaptured()   { return $this->captured; }
    public function isAuthorized() { return $this->authorized; }
}

$controller = new Square\Controller\BookingController();
$method = new ReflectionMethod('Square\Controller\BookingController', 'evaluatePaypalOutcome');
$method->setAccessible(true);

/* [name, payment fields, status stub, expected outcome] */
$cases = array(
    // The real #5324 case: PayPal accepted the money and holds it for a security review.
    array('review: paymentreview (#5324)',
        array('ACK' => 'Success', 'CHECKOUTSTATUS' => 'PaymentActionCompleted', 'PAYERID' => 'ABC123',
              'PAYMENTINFO_0_PAYMENTSTATUS' => 'Pending', 'PAYMENTINFO_0_PENDINGREASON' => 'paymentreview',
              'PAYMENTINFO_0_TRANSACTIONID' => '5T209199N5247000G'),
        new StatusStub(false, false), 'review'),

    array('review: echeck',
        array('CHECKOUTSTATUS' => 'PaymentActionCompleted', 'PAYMENTINFO_0_PAYMENTSTATUS' => 'Pending',
              'PAYMENTINFO_0_PENDINGREASON' => 'echeck'),
        new StatusStub(false, false), 'review'),

    array('review: regulatoryreview',
        array('PAYMENTINFO_0_PAYMENTSTATUS' => 'Pending', 'PAYMENTINFO_0_PENDINGREASON' => 'regulatoryreview'),
        new StatusStub(false, false), 'review'),

    array('review: In-Progress without reason',
        array('PAYMENTINFO_0_PAYMENTSTATUS' => 'In-Progress'),
        new StatusStub(false, false), 'review'),

    array('paid: Completed',
        array('CHECKOUTSTATUS' => 'PaymentActionCompleted', 'PAYMENTINFO_0_PAYMENTSTATUS' => 'Completed',
              'PAYMENTINFO_0_PENDINGREASON' => 'None'),
        new StatusStub(true, false), 'paid'),

    array('paid: Processed',
        array('PAYMENTINFO_0_PAYMENTSTATUS' => 'Processed'),
        new StatusStub(true, false), 'paid'),

    array('paid: Completed-Funds-Held',
        array('PAYMENTINFO_0_PAYMENTSTATUS' => 'Completed-Funds-Held'),
        new StatusStub(true, false), 'paid'),

    array('paid: Pending + authorization (capture outstanding)',
        array('PAYMENTINFO_0_PAYMENTSTATUS' => 'Pending', 'PAYMENTINFO_0_PENDINGREASON' => 'authorization'),
        new StatusStub(false, true), 'paid'),

    array('paid: reconciled model (PAYMENTREQUEST_0_*)',
        array('PAYMENTREQUEST_0_PAYMENTSTATUS' => 'Completed', 'PAYMENTREQUEST_0_PENDINGREASON' => 'None'),
        new StatusStub(false, false), 'paid'),

    array('paid: no fields but captured (zero amount / agreement)',
        array('CHECKOUTSTATUS' => 'PaymentActionCompleted'),
        new StatusStub(true, false), 'paid'),

    array('failed: Denied',
        array('PAYMENTINFO_0_PAYMENTSTATUS' => 'Denied'),
        new StatusStub(false, false), 'failed'),

    array('failed: Expired',
        array('PAYMENTINFO_0_PAYMENTSTATUS' => 'Expired'),
        new StatusStub(false, false), 'failed'),

    array('failed: Reversed',
        array('PAYMENTINFO_0_PAYMENTSTATUS' => 'Reversed'),
        new StatusStub(false, false), 'failed'),

    array('failed: CHECKOUTSTATUS=PaymentActionFailed without PAYMENTINFO',
        array('CHECKOUTSTATUS' => 'PaymentActionFailed', 'PAYERID' => 'ABC123'),
        new StatusStub(false, false), 'failed'),

    // The regression b19dee72 was meant to fix: user returned/aborted, no money moved.
    array('abort: PaymentActionNotInitiated, no PAYERID',
        array('CHECKOUTSTATUS' => 'PaymentActionNotInitiated', 'TOKEN' => 'EC-123'),
        new StatusStub(false, false), 'abort'),

    array('abort: empty response',
        array(),
        new StatusStub(false, false), 'abort'),

    array('abort: cancelled at PayPal',
        array('CANCELLED' => '1', 'CHECKOUTSTATUS' => 'PaymentActionNotInitiated'),
        new StatusStub(false, false), 'abort'),
);

$failed = 0;

foreach ($cases as $case) {
    list($name, $payment, $status, $expected) = $case;

    $model = new Payum\Core\Bridge\Spl\ArrayObject($payment);
    $actual = $method->invoke($controller, $model, $status);

    $ok = ($actual === $expected);
    $failed += $ok ? 0 : 1;

    printf("%-6s %-52s expected=%-7s actual=%s\n", $ok ? '  ok' : 'FAIL', $name, $expected, $actual);
}

/* Direct checks of the shared static classifier used by the reconcile CLI. */
$staticCases = array(
    array('Completed', null, 'paid'),
    array('Pending', 'paymentreview', 'review'),
    array('Pending', 'PAYMENTREVIEW', 'review'),      // case-insensitive
    array('Pending', 'authorization', 'paid'),
    array('Denied', null, 'failed'),
    array('Voided', 'authorization', 'failed'),
    array('', null, 'unknown'),
    array('Something-New', null, 'unknown'),
);

echo "\n";

foreach ($staticCases as $case) {
    list($paymentStatus, $pendingReason, $expected) = $case;

    $actual = Booking\Service\PaymentReconciliationService::classifyPaypalStatus($paymentStatus, $pendingReason);
    $ok = ($actual === $expected);
    $failed += $ok ? 0 : 1;

    printf("%-6s classify(%-14s, %-14s) expected=%-8s actual=%s\n", $ok ? '  ok' : 'FAIL',
        var_export($paymentStatus, true), var_export($pendingReason, true), $expected, $actual);
}

printf("\n%s\n", $failed === 0 ? 'ALL PASSED' : $failed . ' CASE(S) FAILED');

exit($failed === 0 ? 0 : 1);

<?php

namespace Booking\Service;

use Booking\Service\Diagnostic\CheckRegistry;
use Booking\Service\Diagnostic\Check;
use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

class BookingDiagnosticServiceFactory implements FactoryInterface
{

    public function createService(ServiceLocatorInterface $sm)
    {
        $registry = new CheckRegistry(array(
            new Check\DoubleBookingCheck(),
            new Check\OrphanReservationsCheck(),
            new Check\OrphanMetaCheck(),
            new Check\DanglingBookingRefsCheck(),
            new Check\SidOverrideCheck(),
            new Check\QuantityOverrideCheck(),
            new Check\BillingOverrideCheck(),
            new Check\StatusEnumCheck(),
            new Check\SubscriptionIntegrityCheck(),
            new Check\CancelledWithActiveCheck(),
            new Check\InvalidTimeRangeCheck(),
            new Check\OutsideOpeningTimesCheck(),
            new Check\DayExceptionCheck(),
            new Check\DisabledSquareReservationCheck(),
            new Check\StuckPendingCheck(),
            new Check\PaidWithoutEvidenceCheck(),
            new Check\BudgetConsistencyCheck(),
            new Check\PricingCoverageGapCheck(),
            new Check\PendingMigrationsCheck(),
            new Check\MaintenanceModeCheck(),
            new Check\MissingEmailCheck(),
        ));

        return new BookingDiagnosticService(
            $registry,
            $sm->get('Zend\Db\Adapter\Adapter'),
            $sm->get('Booking\Manager\ReservationManager'),
            $sm->get('Booking\Manager\BookingManager'),
            $sm->get('Square\Manager\SquareManager'),
            $sm->get('User\Manager\UserManager'),
            $sm->get('Square\Manager\SquareOpeningTimesManager'),
            $sm->get('Square\Manager\SquarePricingManager'),
            $sm->get('Base\Manager\OptionManager'),
            $sm->get('Base\Service\AuditService'),
            $sm->get('Backend\Service\MailService'),
            $this->resolveLatestSchemaVersion()
        );
    }

    /**
     * Reads the highest migration version from the registry file, if reachable.
     *
     * @return int|null
     */
    protected function resolveLatestSchemaVersion()
    {
        $path = getcwd() . '/data/db/migrations.php';

        if (! is_readable($path)) {
            return null;
        }

        try {
            $migrations = include $path;

            if (is_array($migrations) && $migrations) {
                return max(array_keys($migrations));
            }
        } catch (\Exception $e) {
            /* ignore */
        }

        return null;
    }

}

<?php

namespace Base;

use Zend\EventManager\EventInterface;
use Zend\ModuleManager\Feature\AutoloaderProviderInterface;
use Zend\ModuleManager\Feature\BootstrapListenerInterface;
use Zend\ModuleManager\Feature\ConfigProviderInterface;
use Zend\Session\Container as SessionContainer;
use Zend\Validator\AbstractValidator;

class Module implements AutoloaderProviderInterface, BootstrapListenerInterface, ConfigProviderInterface
{

    public function getAutoloaderConfig()
    {
        return array(
            'Zend\Loader\StandardAutoloader' => array(
                'namespaces' => array(
                    __NAMESPACE__ => __DIR__ . '/src/' . __NAMESPACE__,
                ),
            ),
        );
    }

    public function onBootstrap(EventInterface $e)
    {
        $serviceManager = $e->getApplication()->getServiceManager();

        /* Initialize the configured session manager as the default container manager.
           This applies the session save handler / save_path while no PHP session is
           active yet and makes FlashMessenger (and any other Zend\Session\Container)
           reuse the same, correctly configured manager instead of spinning up a fresh
           default one. Without this, a container touched before the app's SessionManager
           is lazily created (e.g. flashMessenger() on the registration confirmation page)
           starts the session with the default handler, and the later save-handler update
           fatals with "session save handler module cannot be changed when a session is
           active". The session itself is still started lazily on first access. */

        try {
            $sessionManager = $serviceManager->get('Zend\Session\SessionManager');
            SessionContainer::setDefaultManager($sessionManager);
        } catch (\Exception $e) {
            error_log('SessionManager bootstrap error: ' . $e->getMessage());
        }

        /* Check database */

        $dbAdapter = $serviceManager->get('Zend\Db\Adapter\Adapter');
        $dbConnection = $dbAdapter->getDriver()->getConnection();

        try {
            $dbConnection->connect();
        } catch (\RuntimeException $e) {
            include 'Charon.php';

            Charon::carry('application', 'configuration', 1);
        }

        /* Run pending database migrations */

        try {
            $migrationManager = new Manager\MigrationManager($dbAdapter, getcwd());
            $migrationManager->runPendingMigrations();
        } catch (\Exception $e) {
            error_log('MigrationManager bootstrap error: ' . $e->getMessage());
        }

        /* Set global validator translator */

        $translator = $serviceManager->get('Translator');
        AbstractValidator::setDefaultTranslator($translator);
    }

    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

}
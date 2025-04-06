<?php
/*
namespace User\Manager;

use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

class UserSessionManagerFactory implements FactoryInterface
{

    public function createService(ServiceLocatorInterface $sm)
    {
        return new UserSessionManager(
            $sm->get('Base\Manager\ConfigManager'),
            $sm->get('User\Manager\UserManager'),
            $sm->get('Zend\Session\SessionManager'));
    }

}*/


namespace User\Manager;

use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;
use Zend\Session\Config\SessionConfig;
use Zend\Session\SessionManager;
use Zend\Session\Validator\RemoteAddr;
use Zend\Session\Validator\HttpUserAgent;

class UserSessionManagerFactory implements FactoryInterface
{
    public function createService(ServiceLocatorInterface $sm)
    {
        $configManager = $sm->get('Base\Manager\ConfigManager');
        $userManager = $sm->get('User\Manager\UserManager');

        $sessionSettings = $configManager->get('session_config');

        $sessionConfig = new SessionConfig();

        // Fehler vermeiden: Session darf NICHT aktiv sein beim save_handler setzen!
        if (session_status() === PHP_SESSION_NONE) {
            $sessionConfig->setOptions($sessionSettings);
        }

        $sessionManager = new SessionManager($sessionConfig);

        // Validatoren aktivieren (optional aber empfohlen)
        $sessionManager->getValidatorChain()->attach('session.validate', [new RemoteAddr(), 'isValid']);
        $sessionManager->getValidatorChain()->attach('session.validate', [new HttpUserAgent(), 'isValid']);

        // Session nur starten, wenn noch nicht aktiv (wichtig für PayPal Redirects)
        if (session_status() === PHP_SESSION_NONE) {
            $sessionManager->start();
        }

        return new UserSessionManager(
            $configManager,
            $userManager,
            $sessionManager
        );
    }
}
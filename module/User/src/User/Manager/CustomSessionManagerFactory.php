<?php

namespace User\Manager;

use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;
use Zend\Session\Config\SessionConfig;
use Zend\Session\SessionManager;
use Zend\Session\Validator\RemoteAddr;
use Zend\Session\Validator\HttpUserAgent;

class CustomSessionManagerFactory implements FactoryInterface
{
    public function createService(ServiceLocatorInterface $sm)
    {
        $config = new SessionConfig();

        $appConfig = $sm->get('config');
        $sessionOptions = $appConfig['session'] ?? [];

        // Sicherstellen, dass save_path gültig ist
        if (!empty($sessionOptions['save_path'])) {
            if (!is_dir($sessionOptions['save_path']) || !is_writable($sessionOptions['save_path'])) {
                // ungültiger Pfad => entfernen
                unset($sessionOptions['save_path']);
                error_log("⚠ Ungültiger session.save_path in CustomSessionManagerFactory, wird ignoriert");
            }
        }

        // Session-Optionen setzen
        $config->setOptions($sessionOptions + [
                'name' => 'platzbuchung',
                'save_handler' => 'files',
                'use_cookies' => true,
                'use_only_cookies' => true,
                'cookie_httponly' => true,
                'cookie_secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            ]);

        $sessionManager = new SessionManager($config);

        // Validatoren anhängen (optional aber empfohlen)
        $sessionManager->getValidatorChain()->attach('session.validate', [new RemoteAddr(), 'isValid']);
        $sessionManager->getValidatorChain()->attach('session.validate', [new HttpUserAgent(), 'isValid']);

        // Session nur starten, wenn noch nicht aktiv
        if (session_status() === PHP_SESSION_NONE) {
            $sessionManager->start();
        }

        return $sessionManager;
    }
}
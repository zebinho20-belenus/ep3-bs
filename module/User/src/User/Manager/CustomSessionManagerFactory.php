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

        $options = [
            'name' => 'platzbuchung',
            'save_handler' => 'files',
            'save_path' => '/tmp',
            'use_cookies' => true,
            'use_only_cookies' => true,
            'cookie_httponly' => true,
            'cookie_secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        ];

        // Nur wenn Session **noch NICHT** aktiv ist → Optionen setzen
        if (session_status() === PHP_SESSION_NONE) {
            // Nur save_path setzen, wenn gültig
            if (!is_dir($options['save_path']) || !is_writable($options['save_path'])) {
                unset($options['save_path']); // Sicher entfernen
            }

            $config->setOptions($options);
        }

        $sessionManager = new SessionManager($config);

        // Validatoren nur einmal registrieren
        if (session_status() === PHP_SESSION_NONE) {
            $sessionManager->getValidatorChain()->attach('session.validate', [new RemoteAddr(), 'isValid']);
            $sessionManager->getValidatorChain()->attach('session.validate', [new HttpUserAgent(), 'isValid']);
            $sessionManager->start();
        }

        return $sessionManager;
    }
}
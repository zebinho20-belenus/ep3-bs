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

        // Sicheren Session-Pfad setzen (z. B. tmp oder definierter Pfad)
        $savePath = ini_get('session.save_path') ?: '/tmp';

        if (session_status() === PHP_SESSION_NONE) {
            if (is_dir($savePath) && is_writable($savePath)) {
                $config->setOptions([
                    'name' => 'platzbuchung',
                    'save_handler' => 'files',
                    'save_path' => $savePath,
                    'use_cookies' => true,
                    'use_only_cookies' => true,
                    'cookie_httponly' => true,
                    'cookie_secure' => isset($_SERVER['HTTPS']), // Nur true, wenn SSL aktiv
                ]);
            } else {
                error_log("⚠ Ungültiger session.save_path: {$savePath}");
            }
        }

        $sessionManager = new SessionManager($config);

        // Validatoren (optional, aber sinnvoll)
        $sessionManager->getValidatorChain()->attach('session.validate', [new RemoteAddr(), 'isValid']);
        $sessionManager->getValidatorChain()->attach('session.validate', [new HttpUserAgent(), 'isValid']);

        // Session starten, wenn noch nicht aktiv
        if (session_status() === PHP_SESSION_NONE) {
            $sessionManager->start();
        }

        return $sessionManager;
    }
}
<?php

namespace User\Manager;

use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;
use Zend\Session\Config\SessionConfig;
use Zend\Session\SessionManager;

class CustomSessionManagerFactory implements FactoryInterface
{
    public function createService(ServiceLocatorInterface $sm)
    {
        $config = new SessionConfig();

        // save_path nur setzen, wenn gültig
        $savePath = ini_get('session.save_path') ?: '/tmp';

        if (!is_dir($savePath) || !is_writable($savePath)) {
            error_log("Session save_path '$savePath' is invalid or not writable");
            // lieber NICHT setzen, als Fehler riskieren
            $savePath = null;
        }

        if (session_status() === PHP_SESSION_NONE) {
            $options = [
                'save_handler' => 'files',
                'name' => 'platzbuchung',
                'cookie_httponly' => true,
            ];

            if ($savePath) {
                $options['save_path'] = $savePath;
            }

            $config->setOptions($options);
        }

        $manager = new SessionManager($config);
        $manager->start(); // Session sicher starten

        return $manager;
    }
}
<?php
namespace User\Manager;

use Zend\Session\Config\SessionConfig;
use Zend\Session\SessionManager;
use Zend\Session\Container;

class CustomSessionManagerFactory
{
    public function __invoke($container)
    {
        $config = new SessionConfig();

        if (session_status() === PHP_SESSION_NONE) {
            $config->setOptions([
                'save_handler' => 'files',
               // 'save_path' => '/data/session/',
                'name' => 'platzbuchung',
                'cookie_httponly' => true,
            ]);
        }

        $manager = new SessionManager($config);
        Container::setDefaultManager($manager);

        if (session_status() === PHP_SESSION_NONE) {
            $manager->start();
        }

        return $manager;
    }
}



<?php
/**
 * Global application configuration
 *
 * Usually, you can leave this file as is
 * and do not need to worry about its contents.
 */

/*return array(
    'db' => array(
        'driver' => 'pdo_mysql',
        'charset' => 'UTF8',
    ),
);*/

return array(
    'db' => array(
        'driver' => 'pdo_mysql',
        'charset' => 'UTF8',
    ),
    'session' => array(
        'config' => array(
            'class' => 'Zend\Session\Config\SessionConfig',
            'options' => array(
                'name' => 'tcn_kail_session',
                'save_handler' => 'files',
                'cookie_lifetime' => 86400,
                'gc_maxlifetime' => 86400,
                'cookie_httponly' => true,
                'use_cookies' => true,
            ),
        ),
        'storage' => 'Zend\Session\Storage\SessionArrayStorage',
        'validators' => array(
            array(
                'name' => 'Zend\Session\Validator\RemoteAddr',
            ),
            array(
                'name' => 'Zend\Session\Validator\HttpUserAgent',
            ),
        ),
    ),
);

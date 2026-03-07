<?php

namespace Base\Controller\Plugin;

use Zend\Mvc\Controller\Plugin\AbstractPlugin;
use Zend\Session\Container;

class CsrfProtection extends AbstractPlugin
{

    public function getToken()
    {
        $session = new Container('csrf_backend');

        if (! $session->token) {
            $session->token = bin2hex(random_bytes(32));
        }

        return $session->token;
    }

    public function validate($token)
    {
        $session = new Container('csrf_backend');

        if (! $session->token || ! $token) {
            return false;
        }

        return hash_equals($session->token, $token);
    }

}

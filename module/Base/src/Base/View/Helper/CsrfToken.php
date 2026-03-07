<?php

namespace Base\View\Helper;

use Zend\Session\Container;
use Zend\View\Helper\AbstractHelper;

class CsrfToken extends AbstractHelper
{

    public function __invoke()
    {
        $session = new Container('csrf_backend');

        if (! $session->token) {
            $session->token = bin2hex(random_bytes(32));
        }

        return sprintf(
            '<input type="hidden" name="csrf_token" value="%s">',
            htmlspecialchars($session->token, ENT_QUOTES, 'UTF-8')
        );
    }

}

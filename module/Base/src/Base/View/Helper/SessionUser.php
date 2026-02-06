<?php

namespace Base\View\Helper;

use Zend\View\Helper\AbstractHelper;

class SessionUser extends AbstractHelper
{

    protected $user;

    public function __construct($user = null)
    {
        $this->user = $user;
    }

    public function __invoke()
    {
        return $this->user;
    }

}

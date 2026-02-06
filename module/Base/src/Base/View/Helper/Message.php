<?php

namespace Base\View\Helper;

use Zend\View\Helper\AbstractHelper;

class Message extends AbstractHelper
{

    protected $typeMap = array(
        'default' => 'success',
        'success' => 'success',
        'info' => 'info',
        'error' => 'danger',
        'warning' => 'warning',
    );

    public function __invoke($message, $type = 'success')
    {
        if ($message) {
            $view = $this->getView();

            $bsType = isset($this->typeMap[$type]) ? $this->typeMap[$type] : 'info';

            return sprintf('<div class="alert alert-%s alert-dismissible fade show" role="alert">%s<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>',
                $bsType, $view->translate($message));

        } else {
            return null;
        }
    }

}

<?php

namespace Base\View\Helper;

use Zend\Form\ElementInterface;
use Zend\View\Helper\AbstractHelper;

class FormElementNotes extends AbstractHelper
{

    public function __invoke(ElementInterface $element, array $arguments = array())
    {
        $notes = $element->getOption('notes');

        if (! $notes) {
            return null;
        }

        $view = $this->getView();

        return '<div class="form-text">' . vsprintf($view->translate($notes), $arguments) . '</div>';
    }

}

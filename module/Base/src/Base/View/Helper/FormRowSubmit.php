<?php

namespace Base\View\Helper;

use Zend\Form\ElementInterface;
use Zend\View\Helper\AbstractHelper;

class FormRowSubmit extends AbstractHelper
{

    public function __invoke($form, $id)
    {
        $view = $this->getView();

        if ($id instanceof ElementInterface) {
            $formElement = $id;
        } else {
            $formElement = $form->get($id);
        }

        $existingClass = $formElement->getAttribute('class') ?: '';
        if (strpos($existingClass, 'btn') === false) {
            $formElement->setAttribute('class', trim($existingClass . ' btn btn-primary'));
        }

        $html = sprintf('<div class="mb-3">%s</div>',
            $view->formElement($formElement));

        return $html;
    }

}

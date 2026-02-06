<?php

namespace Base\View\Helper;

use Zend\Form\ElementInterface;
use Zend\View\Helper\AbstractHelper;

class FormRowCheckbox extends AbstractHelper
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
        if (strpos($existingClass, 'form-check-input') === false) {
            $formElement->setAttribute('class', trim($existingClass . ' form-check-input'));
        }

        // Add form-check-label class to label
        $labelAttributes = $formElement->getLabelAttributes() ?: array();
        $labelClass = isset($labelAttributes['class']) ? $labelAttributes['class'] : '';
        if (strpos($labelClass, 'form-check-label') === false) {
            $labelAttributes['class'] = trim($labelClass . ' form-check-label');
            $formElement->setLabelAttributes($labelAttributes);
        }

        $html = '<div class="mb-3 form-check">';
        $html .= $view->formElement($formElement);
        $html .= ' ' . $view->formLabel($formElement);
        $html .= $view->formElementNotes($formElement);
        $html .= $view->formElementErrors($formElement);
        $html .= '</div>';

        return $html;
    }

}

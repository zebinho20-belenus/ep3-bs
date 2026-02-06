<?php

namespace Base\View\Helper;

use Zend\Form\ElementInterface;
use Zend\View\Helper\AbstractHelper;

class FormRowCompact extends AbstractHelper
{

    public function __invoke($form, $id)
    {
        $view = $this->getView();

        if ($id instanceof ElementInterface) {
            $formElement = $id;
        } else {
            $formElement = $form->get($id);
        }

        $postfix = $formElement->getOption('postfix');

        if ($postfix) {
            $postfix = sprintf('<span class="default-form-postfix ms-2">%s</span>',
                $view->t($postfix));
        }

        $type = $formElement->getAttribute('type');
        if ($type !== 'hidden') {
            $existingClass = $formElement->getAttribute('class') ?: '';
            if (strpos($existingClass, 'form-control') === false && strpos($existingClass, 'form-select') === false) {
                if ($formElement instanceof \Zend\Form\Element\Select) {
                    $formElement->setAttribute('class', trim($existingClass . ' form-select form-select-sm'));
                } elseif ($formElement instanceof \Zend\Form\Element\Textarea) {
                    $formElement->setAttribute('class', trim($existingClass . ' form-control form-control-sm'));
                } elseif ($type === 'text' || $type === 'password' || $type === 'email' || $type === 'number' || $type === 'tel' || $type === 'url' || $type === 'date') {
                    $formElement->setAttribute('class', trim($existingClass . ' form-control form-control-sm'));
                }
            }
        }

        // Add small text-muted classes to label
        $labelAttributes = $formElement->getLabelAttributes() ?: array();
        $labelClass = isset($labelAttributes['class']) ? $labelAttributes['class'] : '';
        if (strpos($labelClass, 'form-label') === false) {
            $labelAttributes['class'] = trim($labelClass . ' form-label small text-muted mb-0');
            $formElement->setLabelAttributes($labelAttributes);
        }

        $html = '<div class="mb-2">';
        $html .= $view->formLabel($formElement);
        $html .= sprintf('<div>%s %s</div>', $view->formElement($formElement), $postfix);
        $html .= $view->formElementNotes($formElement);
        $html .= $view->formElementErrors($formElement);
        $html .= '</div>';

        return $html;
    }

}

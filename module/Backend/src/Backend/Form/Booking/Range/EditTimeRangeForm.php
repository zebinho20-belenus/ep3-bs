<?php

namespace Backend\Form\Booking\Range;

use Zend\Form\Form;
use Zend\InputFilter\Factory;

class EditTimeRangeForm extends Form
{

    public function init()
    {
        $this->setName('bf');

        $timeOptions = array();
        for ($h = 7; $h <= 22; $h++) {
            $timeOptions[sprintf('%02d:00', $h)] = sprintf('%02d:00', $h);
        }

        $this->add(array(
            'name' => 'bf-time-start',
            'type' => 'Select',
            'attributes' => array(
                'id' => 'bf-time-start',
                'style' => 'width: 90px;',
            ),
            'options' => array(
                'label' => 'Time (Start)',
                'value_options' => $timeOptions,
            ),
        ));

        $this->add(array(
            'name' => 'bf-time-end',
            'type' => 'Select',
            'attributes' => array(
                'id' => 'bf-time-end',
                'style' => 'width: 90px;',
            ),
            'options' => array(
                'label' => 'Time (End)',
                'value_options' => $timeOptions,
            ),
        ));

        $this->add(array(
            'name' => 'bf-submit',
            'type' => 'Submit',
            'attributes' => array(
                'value' => 'Save',
                'id' => 'bf-submit',
                'class' => 'default-button',
                'style' => 'width: 125px;',
            ),
        ));

        /* Input filters */

        $factory = new Factory();

        $this->setInputFilter($factory->createInputFilter(array(
            'bf-time-start' => array(
                'filters' => array(
                    array('name' => 'StringTrim'),
                ),
                'validators' => array(
                    array(
                        'name' => 'NotEmpty',
                        'options' => array(
                            'message' => 'Please select a time',
                        ),
                        'break_chain_on_failure' => true,
                    ),
                ),
            ),
            'bf-time-end' => array(
                'filters' => array(
                    array('name' => 'StringTrim'),
                ),
                'validators' => array(
                    array(
                        'name' => 'NotEmpty',
                        'options' => array(
                            'message' => 'Please select a time',
                        ),
                        'break_chain_on_failure' => true,
                    ),
                ),
            ),
        )));
    }

}
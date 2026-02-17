<?php

return array(
    'router' => array(
        'routes' => array(
            'payment_done' => array(
                'type' => 'segment',
                'options' => array(
                    'route'    => '/payment/booking/done[/:payum_token]',
                    'defaults' => array(
                        'action' => 'done',
                    ),
                ),
            ),
            'payment_confirm' => array(
                'type' => 'segment',
                'options' => array(
                    'route'    => '/payment/booking/confirm[/:payum_token]',
                    'defaults' => array(
                        'action' => 'confirm',
                    ),
                ),
            ),
            'payment_pay' => array(
                'type' => 'segment',
                'options' => array(
                    'route'    => '/payment/booking/pay/:bid',
                    'defaults' => array(
                        'controller' => 'Payment\Controller\Payment',
                        'action' => 'pay',
                    ),
                    'constraints' => array(
                        'bid' => '[0-9]+',
                    ),
                ),
            ),
            'payment_webhook' => array(
                'type' => 'Literal',
                'options' => array(
                    'route'    => '/payment/booking/webhook',
                    'defaults' => array(
                        'action' => 'webhook',
                    ),
                ),
            ),
        ),
    ),

    'controllers' => array(
        'invokables' => array(
            'Payment\Controller\Payment' => 'Payment\Controller\PaymentController',
        ),
    ),

    'service_manager' => array(
        'factories' => array(
            'Payment\Service\PaymentService' => 'Payment\Service\PaymentServiceFactory',
            'Zend\Session\Config\ConfigInterface' => 'Zend\Session\Service\SessionConfigFactory',
            'Zend\Session\SessionManager' => 'Zend\Session\Service\SessionManagerFactory',
        ),
    ),

    'view_manager' => array(
        'template_path_stack' => array(
            __DIR__ . '/../view',
        ),
    ),
);

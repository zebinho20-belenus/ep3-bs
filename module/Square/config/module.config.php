<?php

return array(
    'router' => array(
        'routes' => array(
            'square' => array(
                'type' => 'Literal',
                'options' => array(
                    'route' => '/square',
                    'defaults' => array(
                        'controller' => 'Square\Controller\Square',
                        'action' => 'index',
                    ),
                ),
                'may_terminate' => true,
                'child_routes'  => array(
                    'booking' => array(
                        'type' => 'Literal',
                        'options' => array(
                            'route' => '/booking',
                            'defaults' => array(
                                'controller' => 'Square\Controller\Booking',
                            ),
                        ),
                        'may_terminate' => false,
                        'child_routes'  => array(
                            'customization' => array(
                                'type' => 'Literal',
                                'options' => array(
                                    'route' => '/customization',
                                    'defaults' => array(
                                        'action' => 'customization',
                                    ),
                                ),
                            ),
                            'confirmation' => array(
                                'type' => 'Literal',
                                'options' => array(
                                    'route' => '/confirmation',
                                    'defaults' => array(
                                        'action' => 'confirmation',
                                    ),
                                ),
                            ),
                            'cancellation' => array(
                                'type' => 'Literal',
                                'options' => array(
                                    'route' => '/cancellation',
                                    'defaults' => array(
                                        'action' => 'cancellation',
                                    ),
                                ),
                            ),
                            'payment_done' => array(
                                'type' => 'segment',
                                'options' => array(
                                    'route'    => '/payment/done[/:payum_token]',
                                    'defaults' => array(
                                        'action' => 'done',
                                    ),
                                ),
                            ),
                            'payment_confirm' => array(
                                'type' => 'segment',
                                'options' => array(
                                    'route'    => '/payment/confirm[/:payum_token]',
                                    'defaults' => array(
                                        'action' => 'confirm',
                                    ),
                                ),
                            ),
                            'payment_webhook' => array(
                                'type' => 'Literal',
                                'options' => array(
                                    'route'    => '/payment/webhook',
                                    'defaults' => array(
                                        'action' => 'webhook',
                                    ),
                                ),
                            ),
                            'payment_pay' => array(
                                'type' => 'segment',
                                'options' => array(
                                    'route'    => '/payment/pay/:bid',
                                    'defaults' => array(
                                        'action' => 'pay',
                                    ),
                                    'constraints' => array(
                                        'bid' => '[0-9]+',
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
            ),
        ),
    ),

    'controllers' => array(
        'invokables' => array(
            'Square\Controller\Square' => 'Square\Controller\SquareController',
            'Square\Controller\Booking' => 'Square\Controller\BookingController',
        ),
    ),

    'service_manager' => array(
        'factories' => array(
            'Square\Manager\SquareManager' => 'Square\Manager\SquareManagerFactory',
            'Square\Manager\SquarePricingManager' => 'Square\Manager\SquarePricingManagerFactory',
            'Square\Manager\SquareOpeningTimesManager' => 'Square\Manager\SquareOpeningTimesManagerFactory',
            'Square\Manager\SquareProductManager' => 'Square\Manager\SquareProductManagerFactory',

            'Square\Service\SquareValidator' => 'Square\Service\SquareValidatorFactory',

            'Square\Table\SquareMetaTable' => 'Square\Table\SquareMetaTableFactory',
            'Square\Table\SquareTable' => 'Square\Table\SquareTableFactory',

            'Square\Table\SquarePricingTable' => 'Square\Table\SquarePricingTableFactory',
            'Square\Table\SquareOpeningTimesTable' => 'Square\Table\SquareOpeningTimesTableFactory',
            'Square\Table\SquareProductTable' => 'Square\Table\SquareProductTableFactory',
        ),
    ),

    'view_helpers' => array(
        'invokables' => array(
            'SquareCapacityInfo' => 'Square\View\Helper\CapacityInfo',
            'SquareDateFormat' => 'Square\View\Helper\DateFormat',
        ),

        'factories' => array(
            'SquarePricingHints' => 'Square\View\Helper\PricingHintsFactory',
            'SquarePricingSummary' => 'Square\View\Helper\PricingSummaryFactory',

            'SquareProductChoice' => 'Square\View\Helper\ProductChoiceFactory',
            'SquareQuantityChoice' => 'Square\View\Helper\QuantityChoiceFactory',
            'SquareTimeBlockChoice' => 'Square\View\Helper\TimeBlockChoiceFactory',
        ),
    ),

    'view_manager' => array(
        'template_path_stack' => array(
            __DIR__ . '/../view',
        ),
        'strategies' => array(
            'ViewJsonStrategy',
        ),
    ),
);

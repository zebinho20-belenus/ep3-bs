<?php

namespace Payum\Stripe\Action\Api;

use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\ApiAwareTrait;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\LogicException;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Stripe\Keys;
use Payum\Stripe\Request\Api\ConfirmPaymentIntent;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;
use Stripe\Stripe;

/**
 * Class ConfirmPaymentIntentAction.
 *
 * @author Eric Masoero <em@studeal.fr>
 */
class ConfirmPaymentIntentAction implements ActionInterface, ApiAwareInterface
{
    use ApiAwareTrait {
        setApi as _setApi;
    }

    /**
     * @deprecated BC will be removed in 2.x. Use $this->api
     *
     * @var Keys
     */
    protected $keys;

    public function __construct()
    {
        $this->apiClass = Keys::class;
    }

    /**
     * {@inheritDoc}
     */
    public function setApi($api)
    {
        $this->_setApi($api);

        // BC. will be removed in 2.x
        $this->keys = $this->api;
    }

    /**
     * {@inheritDoc}
     */
    public function execute($request)
    {
        // syslog(LOG_EMERG,"confirmPaymentIntentAction");
        
        /** @var $request ConfirmPaymentIntent */
        RequestNotSupportedException::assertSupports($this, $request);

        $model = ArrayObject::ensureArrayObject($request->getModel());

        if (false == $model['payment_intent']) {
            throw new LogicException('The payment intent id has to be set.');
        }

        try {
            Stripe::setApiKey($this->keys->getSecretKey());

            $intent = PaymentIntent::retrieve($model['payment_intent']);

            if ($intent['status'] == 'requires_confirmation' || $intent['status'] == 'requires_action') {
                $intent->confirm();
            }

            $model->replace($intent->toArray());

        } catch (ApiErrorException $e) {
            $model->replace($e->getJsonBody());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function supports($request)
    {
        return
            $request instanceof ConfirmPaymentIntent &&
            $request->getModel() instanceof \ArrayAccess
            ;
    }
}

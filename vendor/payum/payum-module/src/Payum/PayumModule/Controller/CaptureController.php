<?php
namespace Payum\PayumModule\Controller;

use Payum\Core\Exception\InvalidArgumentException;
use Payum\Core\Reply\HttpRedirect;
use Payum\Core\Reply\HttpResponse;
use Payum\Core\Reply\ReplyInterface;
use Payum\Core\Request\Capture;
use Zend\Http\Response;

class CaptureController extends PayumController
{
    public function doAction()
    {
        try {
            $token = $this->getHttpRequestVerifier()->verify($this);
        } catch (InvalidArgumentException $e) {
            // Token not found or invalid — e.g. payment cancelled, expired, or already processed
            error_log('CaptureController: Token verification failed: ' . $e->getMessage());
            return $this->redirect()->toRoute('frontend', [], ['query' => ['payment_error' => 1]]);
        }

        $gateway = $this->getPayum()->getGateway($token->getGatewayName());

        try {
            $gateway->execute(new Capture($token));
        } catch (ReplyInterface $reply) {
            if ($reply instanceof HttpRedirect) {
                return $this->redirect()->toUrl($reply->getUrl());
            }

            if ($reply instanceof HttpResponse) {
                $this->getResponse()->setContent($reply->getContent());

                $response = new Response();
                $response->setStatusCode(200);
                $response->setContent($reply->getContent());

                return $response;
            }

            throw new \LogicException('Unsupported reply', null, $reply);
        }

        $this->getHttpRequestVerifier()->invalidate($token);

        return $this->redirect()->toUrl($token->getAfterUrl());
    }
}

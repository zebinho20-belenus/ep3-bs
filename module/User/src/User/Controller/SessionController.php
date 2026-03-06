<?php

namespace User\Controller;

use User\Authentication\Result;
use Zend\Mvc\Controller\AbstractActionController;

class SessionController extends AbstractActionController
{

    public function loginAction()
    {
        $serviceManager = @$this->getServiceLocator();

        $userSessionManager = $serviceManager->get('User\Manager\UserSessionManager');
        $user = $userSessionManager->getSessionUser();

        if ($user) {
            return $this->redirectBack()->toOrigin();
        }

        $formElementManager = $serviceManager->get('FormElementManager');

        $loginForm = $formElementManager->get('User\Form\LoginForm');
        $loginMessage = null;
        $loginDetent = null;

        if ($this->getRequest()->isPost()) {
            $loginForm->setData($this->params()->fromPost());

            if ($loginForm->isValid()) {
                $loginData = $loginForm->getData();

                $loginResult = $userSessionManager->login($loginData['lf-email'], $loginData['lf-pw']);

                switch ($loginResult->getCode()) {
                    case Result::SUCCESS:

                        $user = $loginResult->getIdentity();

                        $this->flashMessenger()->addSuccessMessage(
                            sprintf($this->t('Welcome, %s'), $user->need('alias')));

                        return $this->redirectBack()->toOrigin();

                    case Result::FAILURE_TOO_MANY_TRIES:

                        $loginMessage = 'Due to too many login attempts, temporarily blocked until %s';
                        $loginDetent = $loginResult->getExtra('login_detent');
                        break;

                    default:
                        // Generic message for all failures to prevent user enumeration (OWASP A07)
                        $loginMessage = 'Email address and/or password invalid';
                        break;
                }
            } else {
                if ($this->params()->fromPost('lf-email') || $this->params()->fromPost('lf-pw')) {
                    $loginMessage = 'Email address and/or password invalid';
                }
            }

            $loginForm->setData( $loginForm->getData() );
        }

        return array(
            'loginForm' => $loginForm,
            'loginMessage' => $loginMessage,
            'loginDetent' => $loginDetent,
        );
    }

    public function logoutAction()
    {
        $serviceManager = @$this->getServiceLocator();

        $userSessionManager = $serviceManager->get('User\Manager\UserSessionManager');
        $user = $userSessionManager->getSessionUser();

        return array(
            'result' => $userSessionManager->logout(),
            'user' => $user,
        );
    }

}

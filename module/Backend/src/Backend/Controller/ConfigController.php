<?php

namespace Backend\Controller;

use Backend\Form\Config\TextForm;
use Zend\Mvc\Controller\AbstractActionController;

class ConfigController extends AbstractActionController
{

    public function indexAction()
    {
        $this->authorize('admin.config');
    }

    public function textAction()
    {
        $this->authorize('admin.config');

        $serviceManager = @$this->getServiceLocator();
        $optionManager = $serviceManager->get('Base\Manager\OptionManager');
        $formElementManager = $serviceManager->get('FormElementManager');

        $textForm = $formElementManager->get('Backend\Form\Config\TextForm');

        if ($this->getRequest()->isPost()) {
            if (! $this->CsrfProtection()->validate($this->params()->fromPost('csrf_token'))) {
                $this->flashMessenger()->addErrorMessage('Invalid security token. Please try again.');
                return $this->redirect()->toRoute('backend/config/text');
            }

            $textForm->setData($this->params()->fromPost());

            if ($textForm->isValid()) {
                $textData = $textForm->getData();

                foreach (TextForm::$definitions as $key => $value) {
                    $formKey = str_replace('.', '_', $key);

	                $currentValue = $optionManager->get($key);
                    $formValue = $textData['cf-' . $formKey];

	                if (isset($value[2]) && $value[2]) {
				        $type = $value[2];
			        } else {
				        $type = 'Text';
			        }

	                if ($type == 'Checkbox') {
				        $formValue = (boolean) $formValue;
			        }

                    if (($formValue && $formValue != $currentValue) || is_bool($formValue)) {
                        $optionManager->set($key, $formValue, $this->config('i18n.locale'));
                    }
                }

                $this->flashMessenger()->addSuccessMessage('Names and text have been saved');

                return $this->redirect()->toRoute('backend/config/text');
            }
        } else {
            foreach (TextForm::$definitions as $key => $value) {
                $formKey = str_replace('.', '_', $key);
                $textForm->get('cf-' . $formKey)->setValue($optionManager->get($key));
            }
        }

        return array(
            'textForm' => $textForm,
        );
    }

    public function infoAction()
    {
        $this->authorize('admin.config');

        if ($this->getRequest()->isPost()) {
            if (! $this->CsrfProtection()->validate($this->params()->fromPost('csrf_token'))) {
                $this->flashMessenger()->addErrorMessage('Invalid security token. Please try again.');
                return $this->redirect()->toRoute('backend/config/info');
            }

            $info = $this->params()->fromPost('cf-info');

            if ($info && strlen($info) > 32) {
                $optionManager = @$this->getServiceLocator()->get('Base\Manager\OptionManager');
                $optionManager->set('subject.about', $info, $this->config('i18n.locale'));

                $this->flashMessenger()->addSuccessMessage('Info page has been saved');
            } else {
                $this->flashMessenger()->addErrorMessage('Info page text is too short');
            }

            return $this->redirect()->toRoute('backend/config/info');
        }
    }

    public function helpAction()
    {
        $this->authorize('admin.config');

        if ($this->getRequest()->isPost()) {
            if (! $this->CsrfProtection()->validate($this->params()->fromPost('csrf_token'))) {
                $this->flashMessenger()->addErrorMessage('Invalid security token. Please try again.');
                return $this->redirect()->toRoute('backend/config/help');
            }

            $help = $this->params()->fromPost('cf-help');

            if ($help && strlen($help) > 32) {
                $optionManager = @$this->getServiceLocator()->get('Base\Manager\OptionManager');
                $optionManager->set('subject.help', $help, $this->config('i18n.locale'));

                $this->flashMessenger()->addSuccessMessage('Help page has been saved');
            } else {
                $this->flashMessenger()->addErrorMessage('Help page text is too short');
            }

            return $this->redirect()->toRoute('backend/config/help');
        }
    }

    public function behaviourAction()
    {
        $this->authorize('admin.config');

        $serviceManager = @$this->getServiceLocator();
        $optionManager = $serviceManager->get('Base\Manager\OptionManager');
        $formElementManager = $serviceManager->get('FormElementManager');

        $behaviourForm = $formElementManager->get('Backend\Form\Config\BehaviourForm');

        if ($this->getRequest()->isPost()) {
            if (! $this->CsrfProtection()->validate($this->params()->fromPost('csrf_token'))) {
                $this->flashMessenger()->addErrorMessage('Invalid security token. Please try again.');
                return $this->redirect()->toRoute('backend/config/behaviour');
            }

            $behaviourForm->setData($this->params()->fromPost());

            if ($behaviourForm->isValid()) {
                $data = $behaviourForm->getData();

                $maintenance = $data['cf-maintenance'];
                $maintenanceMessage = $data['cf-maintenance-message'];
                $registration = $data['cf-registration'];
                $registrationMessage = $data['cf-registration-message'];
                $activation = $data['cf-activation'];
                $calendarDays = $data['cf-calendar-days'];
                $calendarDayExceptions = $data['cf-calendar-day-exceptions'];
                $calendarClubExceptions = $data['cf-calendar-club-exceptions'];
                $calendarDisplayClubExceptions = $data['cf-calendar-display-club-exceptions'];
                $noEmailStatuses = trim($data['cf-no-email-statuses']);

                $locale = $this->config('i18n.locale');

                $optionManager->set('service.maintenance', $maintenance);
                $optionManager->set('service.maintenance.message', $maintenanceMessage, $locale);
                $optionManager->set('service.user.registration', $registration);
                $optionManager->set('service.user.registration.message', $registrationMessage, $locale);
                $optionManager->set('service.user.activation', $activation);
                $optionManager->set('service.calendar.days', $calendarDays);
                $optionManager->set('service.calendar.day-exceptions', $calendarDayExceptions);
                $optionManager->set('service.calendar.club-exceptions', $calendarClubExceptions);
                $optionManager->set('service.calendar.display-club-exceptions', $calendarDisplayClubExceptions);
                $optionManager->set('service.no-email-statuses', $noEmailStatuses);

                $auditRetentionDays = (int) $data['cf-audit-retention-days'];
                if ($auditRetentionDays < 7) { $auditRetentionDays = 90; }
                $optionManager->set('service.audit.retention-days', $auditRetentionDays);

                $this->flashMessenger()->addSuccessMessage('Configuration has been saved');
            } else {
                $this->flashMessenger()->addErrorMessage('Configuration is (partially) invalid');
            }

            return $this->redirect()->toRoute('backend/config/behaviour');
        } else {
            $behaviourForm->get('cf-maintenance')->setValue($optionManager->get('service.maintenance', 'false'));
            $behaviourForm->get('cf-maintenance-message')->setValue($optionManager->get('service.maintenance.message'));
            $behaviourForm->get('cf-registration')->setValue($optionManager->get('service.user.registration', 'false'));
            $behaviourForm->get('cf-registration-message')->setValue($optionManager->get('service.user.registration.message'));
            $behaviourForm->get('cf-activation')->setValue($optionManager->get('service.user.activation', 'email'));
            $behaviourForm->get('cf-calendar-days')->setValue($optionManager->get('service.calendar.days', '4'));
            $behaviourForm->get('cf-calendar-day-exceptions')->setValue($optionManager->get('service.calendar.day-exceptions'));
            $behaviourForm->get('cf-calendar-club-exceptions')->setValue($optionManager->get('service.calendar.club-exceptions'));
            $behaviourForm->get('cf-calendar-display-club-exceptions')->setValue($optionManager->get('service.calendar.display-club-exceptions'));
            $behaviourForm->get('cf-no-email-statuses')->setValue($optionManager->get('service.no-email-statuses'));
            $behaviourForm->get('cf-audit-retention-days')->setValue($optionManager->get('service.audit.retention-days', '90'));
        }

        return array(
            'behaviourForm' => $behaviourForm,
        );
    }

    public function behaviourRulesAction()
    {
        $this->authorize('admin.config');

        $serviceManager = @$this->getServiceLocator();
        $optionManager = $serviceManager->get('Base\Manager\OptionManager');
        $formElementManager = $serviceManager->get('FormElementManager');

        $rulesForm = $formElementManager->get('Backend\Form\Config\BehaviourRulesForm');

        $locale = $this->config('i18n.locale');

        if ($this->getRequest()->isPost()) {
            if (! $this->CsrfProtection()->validate($this->params()->fromPost('csrf_token'))) {
                $this->flashMessenger()->addErrorMessage('Invalid security token. Please try again.');
                return $this->redirect()->toRoute('backend/config/behaviour/rules');
            }

            $deleteFile = $this->params()->fromPost('delete-file');

            if ($deleteFile === 'terms') {
                $optionManager->set('service.user.registration.terms.file', null, $locale);
                $this->flashMessenger()->addSuccessMessage('Configuration has been updated');
                return $this->redirect()->toRoute('backend/config/behaviour/rules');
            }

            if ($deleteFile === 'privacy') {
                $optionManager->set('service.user.registration.privacy.file', null, $locale);
                $this->flashMessenger()->addSuccessMessage('Configuration has been updated');
                return $this->redirect()->toRoute('backend/config/behaviour/rules');
            }

            $post = array_merge_recursive(
                $this->getRequest()->getPost()->toArray(),
                $this->getRequest()->getFiles()->toArray()
            );

            $rulesForm->setData($post);

            if ($rulesForm->isValid()) {
                $rulesData = $rulesForm->getData();

                /* Save business terms */

                $termsFile = $rulesData['cf-terms-file'];

                if (isset($termsFile['name']) && $termsFile['name'] && isset($termsFile['tmp_name']) && $termsFile['tmp_name']) {
                    $finfo = new \finfo(FILEINFO_MIME_TYPE);
                    $mimeType = $finfo->file($termsFile['tmp_name']);

                    if ($mimeType !== 'application/pdf') {
                        $this->flashMessenger()->addErrorMessage('Invalid file type. Only PDF files are allowed.');
                    } elseif ($termsFile['size'] > 10 * 1024 * 1024) {
                        $this->flashMessenger()->addErrorMessage('File too large. Maximum file size is 10 MB.');
                    } else {
                        $rulesFileName = $termsFile['name'];
                        $rulesFileName = str_replace('.pdf', '', $rulesFileName);
                        $rulesFileName = trim($rulesFileName);
                        $rulesFileName = preg_replace('/[^a-zA-Z0-9 -]/', '', $rulesFileName);
                        $rulesFileName = str_replace(' ', '-', $rulesFileName);
                        $rulesFileName = strtolower($rulesFileName);

                        $destination = sprintf('docs-client/upload/%s.pdf',
                            $rulesFileName);

                        move_uploaded_file($termsFile['tmp_name'], sprintf('%s/public/%s', getcwd(), $destination));

                        $optionManager->set('service.user.registration.terms.file', $destination, $locale);
                    }
                }

                $optionManager->set('service.user.registration.terms.name', $rulesData['cf-terms-name'], $locale);

                /* Save privacy policy */

                $privacyFile = $rulesData['cf-privacy-file'];

                if (isset($privacyFile['name']) && $privacyFile['name'] && isset($privacyFile['tmp_name']) && $privacyFile['tmp_name']) {
                    $finfo = new \finfo(FILEINFO_MIME_TYPE);
                    $mimeType = $finfo->file($privacyFile['tmp_name']);

                    if ($mimeType !== 'application/pdf') {
                        $this->flashMessenger()->addErrorMessage('Invalid file type. Only PDF files are allowed.');
                    } elseif ($privacyFile['size'] > 10 * 1024 * 1024) {
                        $this->flashMessenger()->addErrorMessage('File too large. Maximum file size is 10 MB.');
                    } else {
                        $privacyFileName = $privacyFile['name'];
                        $privacyFileName = str_replace('.pdf', '', $privacyFileName);
                        $privacyFileName = trim($privacyFileName);
                        $privacyFileName = preg_replace('/[^a-zA-Z0-9 -]/', '', $privacyFileName);
                        $privacyFileName = str_replace(' ', '-', $privacyFileName);
                        $privacyFileName = strtolower($privacyFileName);

                        $destination = sprintf('docs-client/upload/%s.pdf',
                            $privacyFileName);

                        move_uploaded_file($privacyFile['tmp_name'], sprintf('%s/public/%s', getcwd(), $destination));

                        $optionManager->set('service.user.registration.privacy.file', $destination, $locale);
                    }
                }

                $optionManager->set('service.user.registration.privacy.name', $rulesData['cf-privacy-name'], $locale);

                $this->flashMessenger()->addSuccessMessage('Configuration has been saved');

                return $this->redirect()->toRoute('backend/config/behaviour/rules');
            }
        } else {
            $rulesForm->setData(array(
                'cf-terms-name' => $optionManager->get('service.user.registration.terms.name'),
                'cf-privacy-name' => $optionManager->get('service.user.registration.privacy.name'),
            ));
        }

        return array(
            'rulesForm' => $rulesForm,
        );
    }

    public function behaviourStatusColorsAction()
    {
        $this->authorize('admin.config');

        $serviceManager = @$this->getServiceLocator();
        $formElementManager = $serviceManager->get('FormElementManager');

        $statusColorsForm = $formElementManager->get('Backend\Form\Config\BehaviourStatusColorsForm');

        $bookingStatusService = $serviceManager->get('Booking\Service\BookingStatusService');

        if ($this->getRequest()->isPost()) {
            if (! $this->CsrfProtection()->validate($this->params()->fromPost('csrf_token'))) {
                $this->flashMessenger()->addErrorMessage('Invalid security token. Please try again.');
                return $this->redirect()->toRoute('backend/config/behaviour/status-colors');
            }

            $statusColorsForm->setData($this->params()->fromPost());

            if ($statusColorsForm->isValid()) {
                $data = $statusColorsForm->getData();

                $statusColors = $data['cf-status-colors'];

                if ($bookingStatusService->checkStatusColors($statusColors)) {
                    $bookingStatusService->setStatusColors($statusColors, $this->config('i18n.locale'));

                    $this->flashMessenger()->addSuccessMessage('Configuration has been saved');
                } else {
                    $this->flashMessenger()->addErrorMessage('Configuration is (partially) invalid');
                }
            } else {
                $this->flashMessenger()->addErrorMessage('Configuration is (partially) invalid');
            }

            return $this->redirect()->toRoute('backend/config/behaviour/status-colors');
        } else {
            $statusColorsForm->get('cf-status-colors')->setValue($bookingStatusService->getStatusColorsRaw());
        }

        return array(
            'statusColorsForm' => $statusColorsForm,
        );
    }

    public function memberEmailsAction()
    {
        $this->authorize('admin.config');

        $serviceManager = @$this->getServiceLocator();
        $memberEmailManager = $serviceManager->get('Backend\Manager\MemberEmailManager');

        if ($this->getRequest()->isPost()) {

            /* CSRF validation */
            $csrfToken = $this->params()->fromPost('csrf_token');

            if (! $this->CsrfProtection()->validate($csrfToken)) {
                $this->flashMessenger()->addErrorMessage('Invalid security token. Please try again.');
                return $this->redirect()->toRoute('backend/config/member-emails');
            }

            /* Delete single entry */
            $delete = $this->params()->fromPost('delete');

            if ($delete && is_numeric($delete)) {
                $memberEmailManager->delete($delete);
                $this->flashMessenger()->addSuccessMessage('Member email has been deleted');
                return $this->redirect()->toRoute('backend/config/member-emails');
            }

            /* Delete all entries */
            $deleteAll = $this->params()->fromPost('delete-all');

            if ($deleteAll === '1') {
                $memberEmailManager->deleteAll();
                $this->flashMessenger()->addSuccessMessage('All member emails have been deleted');
                return $this->redirect()->toRoute('backend/config/member-emails');
            }

            /* CSV upload */
            $files = $this->getRequest()->getFiles();

            if (isset($files['csv-file']) && $files['csv-file']['error'] === UPLOAD_ERR_OK) {
                if ($files['csv-file']['size'] > 1048576) {
                    $this->flashMessenger()->addErrorMessage('CSV file is too large (max 1 MB)');
                    return $this->redirect()->toRoute('backend/config/member-emails');
                }

                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->file($files['csv-file']['tmp_name']);

                if (! in_array($mimeType, ['text/plain', 'text/csv', 'application/csv', 'application/octet-stream'])) {
                    $this->flashMessenger()->addErrorMessage('Invalid file type');
                    return $this->redirect()->toRoute('backend/config/member-emails');
                }

                $content = file_get_contents($files['csv-file']['tmp_name']);

                if ($content) {
                    $imported = $memberEmailManager->importFromCsv($content);
                    $this->flashMessenger()->addSuccessMessage(
                        sprintf($this->t('%d member email(s) have been imported'), $imported)
                    );
                } else {
                    $this->flashMessenger()->addErrorMessage('CSV file is empty');
                }

                return $this->redirect()->toRoute('backend/config/member-emails');
            }

            /* Add single entry */
            $email = trim(strip_tags($this->params()->fromPost('me-email', '')));
            $firstname = trim(strip_tags($this->params()->fromPost('me-firstname', '')));
            $lastname = trim(strip_tags($this->params()->fromPost('me-lastname', '')));

            if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $existing = $memberEmailManager->getByEmail($email);

                if ($existing) {
                    $this->flashMessenger()->addErrorMessage('This email address already exists in the list');
                } else {
                    $memberEmail = new \Backend\Entity\MemberEmail(array(
                        'email' => strtolower($email),
                        'firstname' => $firstname ?: null,
                        'lastname' => $lastname ?: null,
                    ));

                    $memberEmailManager->save($memberEmail);
                    $this->flashMessenger()->addSuccessMessage('Member email has been added');
                }

                return $this->redirect()->toRoute('backend/config/member-emails');
            } elseif ($email) {
                $this->flashMessenger()->addErrorMessage('Invalid email address');
                return $this->redirect()->toRoute('backend/config/member-emails');
            }
        }

        $memberEmails = $memberEmailManager->getAll('lastname ASC, firstname ASC, email ASC');
        $count = $memberEmailManager->getCount();

        return array(
            'memberEmails' => $memberEmails,
            'count' => $count,
        );
    }

}

<?php

namespace Backend\Controller;

use User\Entity\User;
use User\Table\UserTable;
use Zend\Crypt\Password\Bcrypt;
use Zend\Db\Adapter\Adapter;
use Zend\Mvc\Controller\AbstractActionController;

class UserController extends AbstractActionController
{

    public function indexAction()
    {
        $this->authorize('admin.user');

        $serviceManager = @$this->getServiceLocator();
        $userManager = $serviceManager->get('User\Manager\UserManager');

        $users = array();

        $search = $this->params()->fromQuery('usf-search');

        if ($search) {
            $filters = $this->backendUserDetermineFilters($search);
      
            $filterFreeSearch = $filters['search'];

            try {
                //$limit = 1000;
                $limit = null;

                if ($filterFreeSearch) {
                    if (preg_match('/\(([0-9]+)\)/', $filterFreeSearch, $matches)) {
                        $filterFreeSearch = $matches[1];
                    }

                    $users = $userManager->interpret($filterFreeSearch, $limit, true, $filters['filters']);
                } else {
                    $users = $userManager->getBy($filters['filters'], 'alias ASC', $limit);
                }

                if (!empty($filters['metaFilters'])) {
                    $users = array_filter($users, function($user) use ($filters) {
                        foreach ($filters['metaFilters'] as $metaFilter) {
                            list($key, $operator, $value) = $metaFilter;
                            $metaValue = $user->getMeta($key);
                            if ($operator === '=' && (string)$metaValue !== (string)$value) {
                                return false;
                            }
                        }
                        return true;
                    });
                }
            } catch (\RuntimeException $e) {
                $users = array();
            }
        }

        return array(
            'search' => $search,
            'users' => $users,
        );
    }

    public function editAction()
    {
        $sessionUser = $this->authorize('admin.user');

        $serviceManager = @$this->getServiceLocator();
        $userManager = $serviceManager->get('User\Manager\UserManager');
        $formElementManager = $serviceManager->get('FormElementManager');

        $uid = $this->params()->fromRoute('uid');
        $search = $this->params()->fromQuery('search');

        if ($uid) {
            $user = $userManager->get($uid);
        } else {
            $user = null;
        }

        $editUserForm = $formElementManager->get('Backend\Form\User\EditForm');

        if ($this->getRequest()->isPost()) {
            if (! $this->CsrfProtection()->validate($this->params()->fromPost('csrf_token'))) {
                $this->flashMessenger()->addErrorMessage('Invalid security token. Please try again.');
                return $this->redirect()->toRoute('backend/user/edit', ['uid' => $uid]);
            }

            $editUserForm->setData($this->params()->fromPost());

            if ($editUserForm->isValid()) {
                $eud = $editUserForm->getData();

                if (! $user) {
                    $user = new User();
                }

                if ($user->get('status') == 'admin') {
                    if (! $sessionUser->can('admin')) {
                        $this->flashMessenger()->addInfoMessage('Admin users can only be edited by admins');

                        return $this->redirect()->toRoute('backend/user/edit', ['uid' => $uid]);
                    }
                }

                /* Account data */

                $user->set('alias', $eud['euf-alias']);

                $status = $eud['euf-status'];

                if ($status == 'admin') {
                    if ($sessionUser->can('admin')) {
                        $user->set('status', $status);
                    } else {
                        $this->flashMessenger()->addInfoMessage('Admin status can only be given by admins');

                        if (! $user->get('uid')) {
                            return $this->redirect()->toRoute('backend/user/edit', ['uid' => $uid]);
                        }
                    }
                } else {
                    $user->set('status', $status);
                }

                if ($eud['euf-privileges']) {
                    if ($sessionUser->can('admin')) {
                        foreach (User::$privileges as $privilege => $privilegeLabel) {
                            if (in_array($privilege, $eud['euf-privileges'])) {
                                $user->setMeta('allow.' . $privilege, 'true');
                            } else {
                                $user->setMeta('allow.' . $privilege, null);
                            }
                        }
                    } else {
                        $this->flashMessenger()->addInfoMessage('Privileges can only be edited by admins');
                    }
                }

                $user->set('email', $eud['euf-email']);

                $pw = $eud['euf-pw'];

                if ($pw) {
                    $bcrypt = new Bcrypt();
                    $bcrypt->setCost(10);

                    $user->set('pw', $bcrypt->create($pw));
                }

                /* Personal data */

                $user->setMeta('gender', $eud['euf-gender']);

                switch ($eud['euf-gender']) {
                    case 'family':
                    case 'firm':
                        $user->setMeta('name', $eud['euf-firstname']);
                        break;
                    default:
                        $user->setMeta('firstname', $eud['euf-firstname']);
                        $user->setMeta('lastname', $eud['euf-lastname']);
                }

                $user->setMeta('street', $eud['euf-street']);
                $user->setMeta('zip', $eud['euf-zip']);
                $user->setMeta('city', $eud['euf-city']);
                $user->setMeta('phone', $eud['euf-phone']);
                // $user->setMeta('birthdate', $eud['euf-birthdate']);
                $user->setMeta('member', $eud['euf-member']);
                $oldBudget = $user->getMeta('budget');
                $newBudget = $eud['euf-budget'] !== '' ? $eud['euf-budget'] : '0';
                $user->setMeta('budget', $newBudget);
                $user->setMeta('max_active_bookings', $eud['euf-max-active-bookings'] !== '' ? $eud['euf-max-active-bookings'] : null);
                $user->setMeta('notes', $eud['euf-notes']);

                $userManager->save($user);

                // Audit: user edit
                $auditDetail = ['alias' => $user->get('alias'), 'status' => $user->get('status')];
                if ($oldBudget != $newBudget) {
                    $auditDetail['budget_old'] = $oldBudget;
                    $auditDetail['budget_new'] = $newBudget;
                }
                try {
                    $serviceManager->get('Base\Service\AuditService')->log('admin', 'edit_user',
                        sprintf('Benutzer %s bearbeitet%s', $user->get('alias'),
                            $oldBudget != $newBudget ? sprintf(' (Budget: %s → %s)', $oldBudget ?: '0', $newBudget) : ''),
                        ['user_id' => $sessionUser->get('uid'), 'user_name' => $sessionUser->get('alias'),
                         'entity_type' => 'user', 'entity_id' => $user->get('uid'), 'detail' => $auditDetail]);
                } catch (\Exception $e) { error_log('Audit error: ' . $e->getMessage()); }

                $this->flashMessenger()->addSuccessMessage('User has been saved');

                if ($search) {
                    return $this->redirect()->toRoute('backend/user', [], ['query' => ['usf-search' => $search]]);
                } else {
                    return $this->redirect()->toRoute('frontend');
                }
            }
        } else {
            if ($user) {
                $privileges = array();

                foreach (User::$privileges as $privilege => $privilegeLabel) {
                    if ($user->getMeta('allow.' . $privilege) == 'true') {
                        $privileges[] = $privilege;
                    }
                }

                $editUserForm->setData(array(
                    'euf-uid' => $user->need('uid'),
                    'euf-alias' => $user->need('alias'),
                    'euf-status' => $user->need('status'),
                    'euf-privileges' => $privileges,
                    'euf-email' => $user->get('email'),
                    'euf-gender' => $user->getMeta('gender'),
                    'euf-firstname' => $user->getMeta('firstname', $user->getMeta('name')),
                    'euf-lastname' => $user->getMeta('lastname'),
                    'euf-street' => $user->getMeta('street'),
                    'euf-zip' => $user->getMeta('zip'),
                    'euf-city' => $user->getMeta('city'),
                    'euf-phone' => $user->getMeta('phone'),
                    // 'euf-birthdate' => $user->getMeta('birthdate'),
                    'euf-member' => $user->getMeta('member'),
                    'euf-budget' => $user->getMeta('budget'),
                    'euf-max-active-bookings' => $user->getMeta('max_active_bookings'),
                    'euf-notes' => $user->getMeta('notes'),
                ));
            }
        }

        return array(
            'editUserForm' => $editUserForm,
            'user' => $user,
            'search' => $search,
        );
    }

    public function deleteAction()
    {
        $this->authorize('admin.user');

        $uid = $this->params()->fromRoute('uid');
        $search = $this->params()->fromQuery('search');

        $serviceManager = @$this->getServiceLocator();
        $bookingManager = $serviceManager->get('Booking\Manager\BookingManager');
        $userManager = $serviceManager->get('User\Manager\UserManager');

        $user = $userManager->get($uid);
        $bookings = $bookingManager->getBy(['uid' => $uid]);

        $confirmed = $this->params()->fromPost('confirmed');

        if ($confirmed == 'true') {
            if (! $this->CsrfProtection()->validate($this->params()->fromPost('csrf_token'))) {
                $this->flashMessenger()->addErrorMessage('Invalid security token. Please try again.');
                return $this->redirect()->toRoute('backend/user/edit', ['uid' => $uid]);
            }

            if ($bookings) {

                // User already has bookings, so we can only set his status to disabled
                $user->set('status', 'deleted');
                $userManager->save($user);

                try {
                    $serviceManager->get('Base\Service\AuditService')->log('admin', 'disable_user',
                        sprintf('Benutzer %s deaktiviert (hat Buchungen)', $user->get('alias')),
                        ['user_id' => $sessionUser->get('uid'), 'user_name' => $sessionUser->get('alias'),
                         'entity_type' => 'user', 'entity_id' => $user->get('uid')]);
                } catch (\Exception $e) { error_log('Audit error: ' . $e->getMessage()); }

                $this->flashMessenger()->addSuccessMessage('User status has been set to deleted');
            } else {

                // User has no bookings, so we can actually delete him
                $userManager->delete($user);

                try {
                    $serviceManager->get('Base\Service\AuditService')->log('admin', 'delete_user',
                        sprintf('Benutzer %s geloescht', $user->get('alias')),
                        ['user_id' => $sessionUser->get('uid'), 'user_name' => $sessionUser->get('alias'),
                         'entity_type' => 'user', 'entity_id' => $user->get('uid')]);
                } catch (\Exception $e) { error_log('Audit error: ' . $e->getMessage()); }

                $this->flashMessenger()->addSuccessMessage('User has been deleted');
            }

            if ($search) {
                return $this->redirect()->toRoute('backend/user', [], ['query' => ['usf-search' => $search]]);
            } else {
                return $this->redirect()->toRoute('frontend');
            }
        }

        return array(
            'uid' => $uid,
            'search' => $search,
            'user' => $user,
            'bookings' => $bookings,
        );
    }

    public function interpretAction()
    {
        $this->authorize('admin.user, admin.booking, calendar.see-data');

        $serviceManager = @$this->getServiceLocator();
        $userManager = $serviceManager->get('User\Manager\UserManager');

        $term = $this->params()->fromQuery('term');

        $usersMax = 15;

        $users = $userManager->interpret($term, $usersMax);

        $usersList = array();

        foreach ($users as $uid => $user) {
            $usersList[] = sprintf('%s (%s)', $user->need('alias'), $uid);
        }

        if (count($usersList) == $usersMax) {
            $usersList[] = '[...]';
        }

        return $this->jsonViewModel($usersList);
    }

    public function statsAction()
    {
        $this->authorize('admin.user');

        $db = @$this->getServiceLocator()->get('Zend\Db\Adapter\Adapter');

        $stats = $db->query(sprintf('SELECT status, COUNT(status) AS count FROM %s GROUP BY status', UserTable::NAME),
            Adapter::QUERY_MODE_EXECUTE)->toArray();

        return array(
            'stats' => $stats,
        );
    }

}

<?php

namespace Backend\View\Helper\User;

use Zend\View\Helper\AbstractHelper;

class UsersFormat extends AbstractHelper
{

    public function __invoke(array $users, $search = null)
    {
        $view = $this->getView();
        $html = '';

        $html .= '<div class="table-responsive">';
        $html .= '<table class="table table-bordered table-hover align-middle">';

        $html .= '<thead><tr>';
        $html .= '<th data-sort-type="number" class="nr-col responsive-pass-5">' . $view->t('No.') . '</th>';
        $html .= '<th data-sort-type="text">' . $view->t('Name') . '</th>';
        $html .= '<th data-sort-type="text" data-filter-type="select" class="member-col responsive-pass-3">' . $view->t('Member') . '</th>';
        $html .= '<th data-sort-type="text" data-filter-type="select">' . $view->t('Status') . '</th>';
        $html .= '<th data-sort-type="text" class="email-col responsive-pass-3">' . $view->t('Email address') . '</th>';
        $html .= '<th data-sort-type="price" data-filter-type="budget" class="budget-col responsive-pass-2">' . $view->t('Budget') . '</th>';
        $html .= '<th data-sort-type="text" class="notes-col responsive-pass-2">' . $view->t('Notes') . '</th>';
        $html .= '<th data-sort-type="none" class="no-print">&nbsp;</th>';
        $html .= '</tr></thead>';

        $html .= '<tbody>';
        foreach ($users as $user) {
            $html .= $view->backendUserFormat($user, $search);
        }
        $html .= '</tbody>';

        $html .= '</table>';
        $html .= '</div>';

        $view->headScript()->appendFile($view->basePath('js/controller/backend/user/index.min.js'));
        $view->headScript()->appendFile($view->basePath('js/controller/backend/table-sort.min.js'));

        return $html;
    }

}

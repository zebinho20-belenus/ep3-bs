<?php

namespace Base\View\Helper;

use Zend\View\Helper\AbstractHelper;

class Tabs extends AbstractHelper
{

    protected $currentRequestPath;

    public function __construct($currentRequestPath)
    {
        $this->currentRequestPath = $currentRequestPath;
    }

    public function __invoke()
    {
        $view = $this->getView();
        $html = '';

        $tabs = $view->placeholder('tabs')->getValue();
        $misc = $view->placeholder('misc')->getValue();

        if (is_array($tabs)) {
            $html .= '<ul class="nav nav-tabs mb-3';

            // Carry over panel sizing classes
            $panelClass = $view->placeholder('panel')->__toString();
            if (strpos($panelClass, 'large-sized') !== false) {
                $html .= ' large-sized';
            } elseif (strpos($panelClass, 'giant-sized') !== false) {
                $html .= ' giant-sized';
            }

            $html .= ' mx-auto" style="max-width: 768px;">';

            // Adjust max-width based on panel size
            if (strpos($panelClass, 'large-sized') !== false) {
                $html = str_replace('max-width: 768px;', 'max-width: 1024px;', $html);
            } elseif (strpos($panelClass, 'giant-sized') !== false) {
                $html = str_replace('max-width: 768px;', 'max-width: 1280px;', $html);
            }

            foreach ($tabs as $tabTitle => $tabConfig) {
                $tabHtml = null;
                $tabHref = null;
                $tabOuterClass = '';
                $tabLinkClass = '';
                $tabInnerClass = '';

                if (is_array($tabConfig)) {
                    if (isset($tabConfig['html'])) {
                        $tabHtml = $tabConfig['html'];
                    }

                    if (isset($tabConfig['url'])) {
                        $tabHref = $tabConfig['url'];
                    }

                    if (isset($tabConfig['outer-class'])) {
                        $tabOuterClass = $tabConfig['outer-class'];
                    }

                    if (isset($tabConfig['link-class'])) {
                        $tabLinkClass = $tabConfig['link-class'];
                    }

                    if (isset($tabConfig['inner-class'])) {
                        $tabInnerClass = $tabConfig['inner-class'];
                    }
                } else if (is_string($tabConfig)) {
                    $tabHref = $tabConfig;
                }

                if ($tabHref) {
                    $isActive = false;

                    if (is_array($misc) && isset($misc['tabsActive'])) {
                        $tabsActive = $misc['tabsActive'];

                        if (is_string($tabsActive)) {
                            $tabsActive = array($tabsActive);
                        }

                        if (in_array($tabTitle, $tabsActive)) {
                            $isActive = true;
                        }
                    }

                    if ($tabHref == $this->currentRequestPath) {
                        $isActive = true;
                    }

                    $activeClass = $isActive ? ' active' : '';

                    // Map old tab-align-right to ms-auto
                    $liClass = 'nav-item';
                    if (strpos($tabOuterClass, 'tab-align-right') !== false) {
                        $liClass .= ' ms-auto';
                    }

                    $html .= sprintf('<li class="%s">', $liClass);

                    if ($tabHtml) {
                        $html .= $tabHtml;
                    }

                    $html .= sprintf('<a class="nav-link%s %s" href="%s">%s</a>',
                        $activeClass, trim($tabInnerClass), $tabHref, $view->translate($tabTitle));

                    $html .= '</li>';
                } else if ($tabHtml) {
                    $html .= sprintf('<li class="nav-item %s">%s</li>',
                        $tabOuterClass, $tabHtml);
                }
            }

            $html .= '</ul>';
        }

        return $html;
    }

}

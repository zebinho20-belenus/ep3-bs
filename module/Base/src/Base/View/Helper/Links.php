<?php

namespace Base\View\Helper;

use Zend\View\Helper\AbstractHelper;

class Links extends AbstractHelper
{

    public function __invoke($position = 'bottom')
    {
        $view = $this->getView();
        $html = '';

        $backHref = $view->placeholder('back-href')->getValue();
        $backTitle = $view->placeholder('back-title')->getValue();

        if ($backHref && $backTitle) {
            $html .= sprintf('<a href="%s" class="btn btn-outline-secondary btn-sm"><span class="fa fa-chevron-left"></span> %s: %s</a>',
                $backHref, $view->translate('Back to'), $backTitle);
        }

        $links = $view->placeholder('links')->getValue();

        if ($links) {
            foreach ($links as $title => $href) {
                $html .= sprintf('<a href="%s" class="btn btn-outline-primary btn-sm">%s</a>',
                    $href, $view->translate($title));
            }
        }

        if ($html) {
            if ($position === 'top') {
                $html = '<nav class="links-nav mb-3 pb-3 border-bottom border-secondary-subtle text-center no-print"><div class="d-inline-flex flex-wrap justify-content-center align-items-center gap-2">' . $html . '</div></nav>';
            } else {
                $html = '<nav class="links-nav mt-4 pt-3 border-top border-secondary-subtle text-center no-print"><div class="d-inline-flex flex-wrap justify-content-center align-items-center gap-2">' . $html . '</div></nav>';
            }
        }

        return $html;
    }

}

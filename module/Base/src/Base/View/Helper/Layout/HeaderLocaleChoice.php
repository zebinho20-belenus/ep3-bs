<?php

namespace Base\View\Helper\Layout;

use Base\Manager\ConfigManager;
use Base\Manager\OptionManager;
use Zend\Uri\Http as HttpUri;
use Zend\View\Helper\AbstractHelper;

class HeaderLocaleChoice extends AbstractHelper
{

    protected $configManager;
    protected $optionManager;
    protected $uri;

    public function __construct(ConfigManager $configManager, OptionManager $optionManager, HttpUri $uri)
    {
        $this->configManager = $configManager;
        $this->optionManager = $optionManager;
        $this->uri = $uri;
    }

    public function __invoke()
    {
        $localeChoice = $this->configManager->get('i18n.choice');

        if (! ($localeChoice && is_array($localeChoice))) {
            return null;
        }

        $view = $this->getView();
        $html = '';

        foreach ($localeChoice as $locale => $title) {
            $uriString = $this->optionManager->need('service.website');
            $localePattern = '/locale=[^&]+/';

            if (preg_match($localePattern, $uriString)) {
                $href = preg_replace($localePattern, 'locale=' . $locale, $uriString);
            } else {
                $href = $uriString . '?locale=' . $locale;
            }

            $html .= sprintf('<li class="nav-item"><a href="%1$s" title="%2$s" class="nav-link py-1"><img src="%3$s" alt="%2$s" style="height: 16px; border-radius: 2px;"></a></li>',
                $href, $title, $view->basePath('imgs/icons/locale/' . $locale . '.png'));
        }

        return $html;
    }

}

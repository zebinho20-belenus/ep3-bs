<?php

namespace User\Service;

use Base\Manager\ConfigManager;
use Base\Manager\OptionManager;
use Base\Service\AbstractService;
use Base\Service\MailService as BaseMailService;
use User\Entity\User;

class MailService extends AbstractService
{

    protected $baseMailService;
    protected $configManager;
    protected $optionManager;

    public function __construct(BaseMailService $baseMailService, ConfigManager $configManager, OptionManager $optionManager)
    {
        $this->baseMailService = $baseMailService;
        $this->configManager = $configManager;
        $this->optionManager = $optionManager;
    }

    public function send(User $recipient, $subject, $text, array $attachments = array())
    {
        $fromAddress = $this->configManager->need('mail.address');
        $fromName = $this->optionManager->need('client.name.short') . ' ' . $this->optionManager->need('service.name.full');  
        $fromText = $this->t("team from") . ' ' . $fromName;

        $replyToAddress = $this->optionManager->need('client.contact.email');
        $replyToName = $this->optionManager->need('client.name.full');

        $toAddress = $recipient->need('email');
        $toName = $recipient->need('alias');

        $firstname = $recipient->getMeta('firstname');
        $lastname  = $recipient->getMeta('lastname');
        if ($firstname && $lastname) {
            $salutationName = $firstname . ' ' . $lastname;
        } elseif ($lastname) {
            $salutationName = $lastname;
        } else {
            $salutationName = $toName;
        }

        $text = sprintf("%s %s,\r\n\r\n%s\r\n\r\n%s,\r\n%s %s\r\n%s",
            $this->t('Hello'), $salutationName, $text, $this->t('Sincerely'), $this->t("Your"), $fromText, $this->optionManager->need('service.website'));

        $this->baseMailService->sendPlain($fromAddress, $fromName, $replyToAddress, $replyToName, $toAddress, $toName, $subject, $text, $attachments);
    }

}

<?php

namespace Backend\Service;

use Base\Manager\ConfigManager;
use Base\Manager\OptionManager;
use Base\Service\AbstractService;
use Base\Service\MailService as BaseMailService;

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

    public function send($subject, $text, array $attachments = array(), $addendum = null)
    {
        $fromAddress = $this->configManager->need('mail.address');
        $fromName = $this->optionManager->need('client.name.short') . ' ' . $this->optionManager->need('service.name.full');

        $replyToAddress = null;
        $replyToName = null;

        $toAddress = $this->optionManager->need('client.contact.email');
        $toName = $this->optionManager->need('client.name.full');

        $text = sprintf("%s,\r\n\r\n%s\r\n\r\n%s %s\r\n\r\n%s,\r\n%s %s\r\n%s",
            $this->t('Hello'),
            $text,
            $this->t('This was an automated message from the system.'),
            $addendum,
            $this->t('Sincerely'),
            $this->t("Your"),
            $this->optionManager->need('service.name.full'),
            $this->optionManager->need('service.website'));

        $this->baseMailService->sendPlain($fromAddress, $fromName, $replyToAddress, $replyToName, $toAddress, $toName, $subject, $text, $attachments);
    }
    
    /**
     * Sendet eine E-Mail an eine benutzerdefinierte E-Mail-Adresse
     *
     * @param string $subject Der Betreff der E-Mail
     * @param string $text Der Inhalt der E-Mail
     * @param string $toAddress Die Empfänger-E-Mail-Adresse
     * @param string $toName Der Name des Empfängers
     * @param array $attachments Anhänge (optional)
     * @param string $addendum Zusätzlicher Text, der nach dem Hauptinhalt angezeigt wird (optional)
     * @return void
     */
    public function sendCustomEmail($subject, $text, $toAddress, $toName, array $attachments = array(), $addendum = null)
    {
        $fromAddress = $this->configManager->need('mail.address');
        $fromName = $this->optionManager->need('client.name.short') . ' ' . $this->optionManager->need('service.name.full');

        $replyToAddress = $this->optionManager->need('client.contact.email');
        $replyToName = $this->optionManager->need('client.name.full');

        // Formatieren des E-Mail-Textes mit Signatur und Addendum
        $formattedText = sprintf("%s\r\n\r\n%s %s\r\n\r\n%s,\r\n%s %s\r\n%s",
            $text,
            $this->t('This was an automated message from the system.'),
            $addendum,
            $this->t('Sincerely'),
            $this->t("Your"),
            $this->optionManager->need('service.name.full'),
            $this->optionManager->need('service.website'));

        // Senden der E-Mail an den Kunden
        $this->baseMailService->sendPlain(
            $fromAddress,
            $fromName,
            $replyToAddress,
            $replyToName,
            $toAddress,
            $toName,
            $subject,
            $formattedText,
            $attachments
        );

        // Optional: Kopie an die Client-Kontakt-E-Mail senden
        $clientContactEmail = $this->optionManager->need('client.contact.email');
        if (!empty($clientContactEmail) && $toAddress !== $clientContactEmail) {
            // Für die Admin-Kopie fügen wir einen Hinweis hinzu, an wen die E-Mail ursprünglich ging
            $adminText = sprintf("%s\r\n\r\n%s %s\r\n\r\n%s %s (%s).\r\n\r\n%s,\r\n%s %s\r\n%s",
                $text,
                $this->t('This was an automated message from the system.'),
                $addendum,
                $this->t('Originally sent to'),
                $toName,
                $toAddress,
                $this->t('Sincerely'),
                $this->t("Your"),
                $this->optionManager->need('service.name.full'),
                $this->optionManager->need('service.website'));

            $this->baseMailService->sendPlain(
                $fromAddress,
                $fromName,
                $replyToAddress,
                $replyToName,
                $clientContactEmail,
                $replyToName,
                $subject . ' [KOPIE]',
                $adminText,
                $attachments
            );
        }
    }
}

<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use CommerceKings\Notification\Api\CooldownInterface;
use CommerceKings\Notification\Api\Data\Message;
use CommerceKings\Notification\Api\MailerInterface;
use CommerceKings\Notification\Exception\MessageSentException;
use Magento\Framework\App\Area;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Store\Model\App\Emulation;
use Psr\Log\LoggerInterface;

/**
 * Sends one notification email: optional recipient cooldown claim, recipient re-check, content built in
 * the message's store, optional RFC 8058 one-click headers. Mail already handed to the mail server can't
 * be recalled - a later failure must never cause a second email, so it surfaces as MessageSentException.
 */
class Mailer implements MailerInterface
{
    public function __construct(
        private readonly TransportBuilder $transportBuilder,
        private readonly Emulation $emulation,
        private readonly CooldownInterface $cooldown,
        private readonly Config $config,
        private readonly OneClickHeaders $oneClickHeaders,
        private readonly LoggerInterface $logger
    ) {
    }

    public function send(Message $message): string
    {
        $claim = null;
        if ($message->useCooldown && $this->config->getCooldownDays() > 0) {
            $claim = $this->cooldown->claim($message->email);
            if ($claim === null) {
                return self::COOLDOWN;
            }
        }
        $sent = false;
        try {
            // A replaced resolver may read an external recipient source. Recheck it after claiming too.
            if ($message->isRecipientCurrent !== null && !($message->isRecipientCurrent)()) {
                if ($claim !== null) {
                    $this->cooldown->release($message->email, $claim);
                }
                return self::RECIPIENT_CHANGED;
            }
            $this->emulation->startEnvironmentEmulation($message->storeId, Area::AREA_FRONTEND, true);
            try {
                $content = ($message->content)();
                $transport = $this->transportBuilder
                    ->setTemplateIdentifier($message->templateId)
                    ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $message->storeId])
                    ->setTemplateVars($content->vars)
                    ->setFromByScope($message->sender, $message->storeId)
                    ->addTo($message->email, $content->recipientName ?: null)
                    ->getTransport();
                if ($content->oneClickUnsubscribeUrl !== null) {
                    $url = $content->oneClickUnsubscribeUrl;
                    if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
                        throw new \UnexpectedValueException('One-click unsubscribe requires an HTTPS opt-out URL.');
                    }
                    if (!$this->oneClickHeaders->add($transport, $url)) {
                        $this->logger->warning('CommerceKings_Notification: the mail transport does not support'
                            . ' one-click headers; sent without them (the email\'s unsubscribe link still works).');
                    }
                }
                $transport->sendMessage();
                $sent = true;
            } finally {
                $this->emulation->stopEnvironmentEmulation();
            }
        } catch (\Throwable $e) {
            if ($sent) {
                // SMTP returned successfully. A later emulation cleanup error must not enable a duplicate.
                throw new MessageSentException('Notification sent; post-send cleanup failed.', 0, $e);
            }
            if ($claim !== null) {
                $this->cooldown->release($message->email, $claim);
            }
            throw $e;
        }
        return self::SENT;
    }
}

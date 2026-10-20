<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model\Provider;

use CommerceKings\Notification\Api\Channel;
use CommerceKings\Notification\Api\Data\Delivery;
use CommerceKings\Notification\Api\Data\DeliveryContent;
use CommerceKings\Notification\Api\ProviderInterface;
use CommerceKings\Notification\Exception\MessageSentException;
use CommerceKings\Notification\Exception\TemporaryFailureException;
use CommerceKings\Notification\Model\OneClickHeaders;
use Magento\Framework\App\Area;
use Magento\Framework\Mail\Template\TransportBuilder;
use Psr\Log\LoggerInterface;

/**
 * The default email provider: Magento's email templates and mail transport, optional RFC 8058 headers. A mail server
 * refusing for now (an SMTP 4xx reply: busy, throttled, greylisted) is a TemporaryFailureException. A connection lost
 * after the whole message went out and before the server's reply is a MessageSentException: the server may have
 * taken it, and "never twice" means it is not sent again - on that rare ambiguity an email can be lost, never
 * doubled. Any other failure (a refusal, no connection, a failed login, one before the message ended) is rethrown as
 * it is: nothing was taken, it is sent again.
 */
class MagentoEmail implements ProviderInterface
{
    public function __construct(
        private readonly TransportBuilder $transportBuilder,
        private readonly OneClickHeaders $oneClickHeaders,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getCode(): string
    {
        return 'magento_email';
    }

    public function getLabel(): string
    {
        return (string)__('Magento email');
    }

    public function getChannel(): string
    {
        return Channel::EMAIL;
    }

    public function supportsOneClickUnsubscribe(): bool
    {
        return true;
    }

    public function send(Delivery $delivery, DeliveryContent $content): void
    {
        $transport = $this->transportBuilder
            ->setTemplateIdentifier($delivery->templateId)
            ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $delivery->storeId])
            ->setTemplateVars($content->vars)
            ->setFromByScope($delivery->sender, $delivery->storeId)
            ->addTo($delivery->recipient, $content->recipientName ?: null)
            ->getTransport();
        if ($content->oneClickUnsubscribeUrl !== null) {
            // Never block the email over the optional headers: its unsubscribe link works either way.
            $url = $content->oneClickUnsubscribeUrl;
            if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
                $this->logger->warning('CommerceKings_Notification: one-click unsubscribe needs an HTTPS store'
                    . ' URL (RFC 8058); sent without the headers.');
            } elseif (!$this->oneClickHeaders->add($transport, $url)) {
                $this->logger->warning('CommerceKings_Notification: the mail transport does not support'
                    . ' one-click headers; sent without them (the email\'s unsubscribe link still works).');
            }
        }
        try {
            $transport->sendMessage();
        } catch (\Throwable $error) {
            $code = self::temporarySmtpCode($error);
            if ($code !== null) {
                throw new TemporaryFailureException(sprintf('The mail server refused the email for now (SMTP %d);'
                    . ' nothing was sent, try again soon.', $code), null, $error);
            }
            if (self::handedOver($error)) {
                throw new MessageSentException('The connection ended after the whole email went out and before the'
                    . ' mail server replied: it may have taken it; not sent again.', 0, $error);
            }
            throw $error;
        }
    }

    /**
     * The SMTP 4xx reply behind a send failure (Magento wraps the transport's error in a MailException), or null:
     * Symfony Mailer (Magento 2.4.8+) carries the reply code as the exception code, Laminas Mail (earlier) starts the
     * message with the server's reply ("451 4.7.1 Try again later").
     */
    /**
     * The whole message went out and no reply came (the connection closed, timed out, or failed after it). Symfony
     * Mailer: its SMTP transcript (TransportException::getDebug()) has the message's final "." written and no reply
     * read after it. Laminas Mail (no transcript): waiting for a reply that never came ("... has timed out", "Could
     * not read from ...") - at whatever step, so it counts as maybe taken.
     */
    private static function handedOver(\Throwable $error): bool
    {
        for ($cause = $error; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof \Symfony\Component\Mailer\Exception\TransportException) {
                $debug = $cause->getDebug();
                $end = preg_match_all('/^\[[^\]]*\] > \.\r?$/m', $debug, $m, PREG_OFFSET_CAPTURE)
                    ? (int)end($m[0])[1] : -1;
                return $end >= 0 && !preg_match('/^\[[^\]]*\] < /m', substr($debug, $end));
            }
            if (str_starts_with(get_class($cause), 'Laminas\\Mail\\')
                || str_starts_with(get_class($cause), 'Zend\\Mail\\')) {
                return (bool)preg_match('/ has timed out$|^Could not read from /', $cause->getMessage());
            }
        }
        return false;
    }

    private static function temporarySmtpCode(\Throwable $error): ?int
    {
        for ($cause = $error; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof \Symfony\Component\Mailer\Exception\TransportExceptionInterface
                && $cause->getCode() >= 400 && $cause->getCode() < 500) {
                return (int)$cause->getCode();
            }
            if ((str_starts_with(get_class($cause), 'Laminas\\Mail\\') || str_starts_with(get_class($cause), 'Zend\\Mail\\'))
                && preg_match('/^\s*(4\d\d)[ -]/', $cause->getMessage(), $match)) {
                return (int)$match[1];
            }
        }
        return null;
    }
}

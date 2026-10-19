<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model\Provider;

use CommerceKings\Notification\Api\Channel;
use CommerceKings\Notification\Api\Data\Delivery;
use CommerceKings\Notification\Api\Data\DeliveryContent;
use CommerceKings\Notification\Api\ProviderInterface;
use CommerceKings\Notification\Exception\TemporaryFailureException;
use CommerceKings\Notification\Model\OneClickHeaders;
use Magento\Framework\App\Area;
use Magento\Framework\Mail\Template\TransportBuilder;
use Psr\Log\LoggerInterface;

/**
 * The default email provider: Magento's email templates and mail transport, optional RFC 8058 headers. A mail server
 * refusing for now (an SMTP 4xx reply: busy, throttled, greylisted) is a TemporaryFailureException; any other
 * failure is rethrown as it is.
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
            throw $error;
        }
    }

    /**
     * The SMTP 4xx reply behind a send failure (Magento wraps the transport's error in a MailException), or null:
     * Symfony Mailer (Magento 2.4.8+) carries the reply code as the exception code, Laminas Mail (earlier) starts the
     * message with the server's reply ("451 4.7.1 Try again later").
     */
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

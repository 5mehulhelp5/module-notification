<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model\Provider;

use CommerceKings\Notification\Api\Channel;
use CommerceKings\Notification\Api\Data\Delivery;
use CommerceKings\Notification\Api\Data\DeliveryContent;
use CommerceKings\Notification\Api\ProviderInterface;
use CommerceKings\Notification\Model\OneClickHeaders;
use Magento\Framework\App\Area;
use Magento\Framework\Mail\Template\TransportBuilder;
use Psr\Log\LoggerInterface;

/** The default email provider: Magento's email templates and mail transport, optional RFC 8058 headers. */
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

    public function supports(string $capability): bool
    {
        return $capability === self::ONE_CLICK_UNSUBSCRIBE;
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
        $transport->sendMessage();
    }
}

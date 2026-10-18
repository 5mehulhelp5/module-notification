<?php
declare(strict_types=1);

namespace CommerceKings\Notification\ViewModel;

use CommerceKings\Notification\Model\Config;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;

/** The address consent checkbox (address-consent.phtml): the store view's wording, its hash and the REST routes. */
class AddressConsent implements ArgumentInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly StoreManagerInterface $stores,
        private readonly UrlInterface $url
    ) {
    }

    /** '' when the store view asks for no consent on the channel: then no checkbox is shown. */
    public function getWording(string $channel): string
    {
        return $this->config->getAddressConsentWording((int)$this->stores->getStore()->getId(), $channel);
    }

    /** What the visitor confirms: the server records the consent only for this exact wording. */
    public function getWordingHash(string $channel): string
    {
        return hash('sha256', $this->getWording($channel));
    }

    /** @return array{guest: string, mine: string} */
    public function getUrls(): array
    {
        $base = rtrim($this->url->getBaseUrl(['_type' => UrlInterface::URL_TYPE_WEB]), '/') . '/rest/'
            . rawurlencode((string)$this->stores->getStore()->getCode())
            . '/V1/ck-notification/address-consents';
        return ['guest' => $base, 'mine' => $base . '/mine'];
    }
}

<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use CommerceKings\Notification\Api\AddressConsentManagementInterface;
use CommerceKings\Notification\Api\Channel;
use CommerceKings\Notification\Api\SubscriptionRemovalInterface;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Filter\FilterManager;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Validator\EmailAddress;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Records a checkbox consent for one address, in this order, nothing written before the last step: the type uses
 * address consent, the address is valid for the channel, the type authorizes the reference, the wording the visitor
 * saw is the configured one. Unchecking withdraws the address's consent for the type and website and removes the
 * subscriptions that rested on it. Input errors are Magento's 400 without echoing the input.
 */
class AddressConsentManagement implements AddressConsentManagementInterface
{
    public function __construct(
        private readonly SubscriptionTypePool $types,
        private readonly AddressConsentRepository $consents,
        private readonly Config $config,
        private readonly RecipientNormalizerPool $normalizers,
        private readonly FilterManager $filterManager,
        private readonly EmailAddress $emailValidator,
        private readonly StoreManagerInterface $stores,
        private readonly RemoteAddress $remoteAddress,
        private readonly KeyedHash $keyedHash,
        private readonly SubscriptionRemovalInterface $removal
    ) {
    }

    public function recordGuest(string $type, string $reference, mixed $recipient, string $wordingHash, bool $given = true): bool
    {
        return $this->record(0, $type, $reference, $recipient, $wordingHash, $given);
    }

    public function recordCustomer(int $customerId, string $type, string $reference, mixed $recipient,
        string $wordingHash, bool $given = true): bool
    {
        return $customerId > 0 && $this->record($customerId, $type, $reference, $recipient, $wordingHash, $given);
    }

    private function record(int $customerId, string $code, string $reference, mixed $recipient, string $wordingHash,
        bool $given): bool
    {
        $type = $this->types->get($code);
        if ($type === null) {
            throw new InputException(__('Unknown notification type.'));
        }
        $permission = $type->getPermission();
        if (!$permission instanceof AddressConsent) {
            throw new InputException(__('This notification does not take an address consent.'));
        }
        $channel = $type->getChannel();
        $address = $this->address($channel, $recipient);
        $target = $type->authorize($reference, $customerId);
        if ($target === null) {
            return false;
        }
        $websiteId = (int)$this->stores->getStore($target->storeId)->getWebsiteId();
        if (!$given) {
            $this->removal->removeByPermission(
                $this->consents->withdraw($channel, $address, $permission->getTypeCode(), $websiteId)
            );
            return true;
        }
        if (!$target->accepting) {
            return false;
        }
        $wording = $this->config->getAddressConsentWording($target->storeId, $channel);
        if ($wording === '') {
            throw new InputException(__('This store asks for no consent on this channel.'));
        }
        if (!hash_equals(hash('sha256', $wording), $wordingHash)) {
            throw new InputException(__('The consent text has changed. Please read it and confirm again.'));
        }
        $ip = (string)$this->remoteAddress->getRemoteAddress();
        $this->consents->record($permission->getTypeCode(), $channel, $address, $websiteId, $target->storeId, $wording,
            $ip === '' ? '' : $this->keyedHash->current('commercekings_notification_address_consent_ip|' . $ip));
        return true;
    }

    /** The normalized address (the form subscriptions are checked with); anything else is Magento's 400. */
    private function address(string $channel, mixed $recipient): string
    {
        $value = $this->filterManager->get('stringTrim')->filter($recipient);
        $exception = new InputException(__('The recipient has an invalid format.'));
        if (!is_string($value) || $value === '' || strlen($value) > 255) {
            throw $exception;
        }
        if ($channel === Channel::EMAIL) {
            if (!$this->emailValidator->isValid($value)) {
                throw $exception;
            }
            return $value;
        }
        $normalized = $this->normalizers->has($channel) ? $this->normalizers->normalize($channel, $value) : '';
        if ($normalized === '') {
            throw $exception;
        }
        return $normalized;
    }
}

<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Api;

use CommerceKings\Notification\Api\Data\Delivery;
use CommerceKings\Notification\Api\Data\DeliveryContent;

/**
 * Hands one notification to a delivery service (Magento's mail transport, an email API, or any other
 * channel's service).
 * Registered in ProviderPool through di.xml; the admin selects one per channel. SenderInterface calls it inside
 * the store's environment, after the recipient cooldown and re-check - a provider only delivers.
 *
 * @api
 */
interface ProviderInterface
{
    /** Unique, stored in the configuration, e.g. "magento_email". */
    public function getCode(): string;

    /** Shown in the admin's provider list. */
    public function getLabel(): string;

    /** One of the Channel codes. */
    public function getChannel(): string;

    /**
     * Can it add RFC 8058 one-click unsubscribe headers? When false, the sender leaves out
     * DeliveryContent::$oneClickUnsubscribeUrl (the notification's own unsubscribe link still works).
     */
    public function supportsOneClickUnsubscribe(): bool;

    /**
     * Throws if the service did not accept the notification. A failure after it accepted it must be thrown as
     * \CommerceKings\Notification\Exception\MessageSentException - it is out, it must never be sent again. A refusal
     * for now (e.g. HTTP 429, or 503 with Retry-After) is thrown as
     * \CommerceKings\Notification\Exception\TemporaryFailureException - nothing was sent, try again soon.
     */
    public function send(Delivery $delivery, DeliveryContent $content): void;
}

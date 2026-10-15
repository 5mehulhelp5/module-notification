<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use CommerceKings\Consent\Api\Data\WithdrawalContext;
use CommerceKings\Consent\Api\WithdrawalHandlerInterface;
use Magento\Framework\App\ResourceConnection;

/**
 * Removes the subscriptions of withdrawn consents, for every type. Consent registers handlers per purpose,
 * so each feature registers this handler under its purpose (one di.xml item). Repeating it is harmless.
 * Without it nothing is sent anyway - the consumer gate and claims check the consent - only cleanup waits.
 */
class WithdrawalHandler implements WithdrawalHandlerInterface
{
    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function execute(WithdrawalContext $context): void
    {
        if ($context->consentIds) {
            $this->resource->getConnection()->delete($this->resource->getTableName(SubscriptionManagement::TABLE),
                ['consent_id IN (?)' => $context->consentIds]);
        }
    }
}

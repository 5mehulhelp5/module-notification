<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Api;

/**
 * The only way a visitor subscribes to a notification. Order: email rules, the type's authorize(),
 * consent for the type's purpose (CommerceKings_Consent, read server-side), the contact, then one
 * INSERT that also checks the consent is still valid - a withdrawal at the same moment wins.
 *
 * @api
 */
interface SubscriptionManagementInterface
{
    /**
     * Guest. An empty email, missing consent or a type that is not accepting removes the guest's
     * subscription of a single type (a type with one subscription per person keeps it; withdrawal
     * links remove those).
     *
     * @param string $type notification type code
     * @param string $reference as the type expects it (e.g. a masked cart id)
     * @param mixed $email raw input; must be a string, an empty string unsubscribes
     * @return bool true when stored, including an identical successful retry; false when refused or removed
     * @throws \Magento\Framework\Exception\InputException
     */
    public function subscribeGuest(string $type, string $reference, mixed $email): bool;

    /**
     * Logged-in customer; the email comes from the account. Without consent in this browser nothing
     * changes (a consent from another device stays); withdrawals remove it.
     *
     * @param int $customerId forced from the session or token
     * @param string $type notification type code
     * @param string $reference as the type expects it
     * @return bool true when stored, including an identical successful retry
     */
    public function subscribeCustomer(int $customerId, string $type, string $reference): bool;
}

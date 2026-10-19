<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Api;

/**
 * Records or withdraws a checkbox consent for one address (Model\AddressConsent), e.g. "text me offers" next to a phone
 * field. The type must use address consent as its permission; the visitor must be allowed to touch the reference
 * (the type's authorize(), e.g. a masked cart id). The server stores the store view's configured wording for the
 * type's channel; $wordingHash (sha256 of that configured wording) must match it, so the stored text is the one
 * shown. A wording may contain {{number}} (self::NUMBER): it is shown with the number in its place, while the stored
 * evidence is the configured wording with the placeholder, its hash, and the number (as a keyed hash). Subscribe after
 * the consent is recorded.
 *
 * @api
 */
interface AddressConsentManagementInterface
{
    /** The placeholder for the number in a configured wording. */
    public const NUMBER = '{{number}}';

    /**
     * Guest.
     *
     * @param string $type notification type code
     * @param string $reference as the type expects it (e.g. a masked cart id)
     * @param mixed $recipient the address on the type's channel (raw input; must be a string)
     * @param string $wordingHash sha256 of the configured wording, with its {{number}} placeholder (ignored when
     *     withdrawing)
     * @param bool $given true: the box is checked; false: unchecked, withdraws the address's consent
     * @return bool true when recorded or withdrawn; false when the reference is refused
     * @throws \Magento\Framework\Exception\InputException
     */
    public function recordGuest(string $type, string $reference, mixed $recipient, string $wordingHash, bool $given = true): bool;

    /**
     * Logged-in customer; like recordGuest().
     *
     * @param int $customerId forced from the session or token
     * @param string $type
     * @param string $reference
     * @param mixed $recipient
     * @param string $wordingHash
     * @param bool $given
     * @return bool
     * @throws \Magento\Framework\Exception\InputException
     */
    public function recordCustomer(int $customerId, string $type, string $reference, mixed $recipient,
        string $wordingHash, bool $given = true): bool;
}

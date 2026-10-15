<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Api;

/**
 * A module that stores contact ids tells the cleanup which contacts it still uses
 * (register in di.xml: Model\ContactReferencePool "references").
 *
 * @api
 */
interface ContactReferenceInterface
{
    /** SQL that is true while the module uses the contact, e.g. EXISTS (SELECT 1 FROM t WHERE t.contact_id = <column>). */
    public function usedCondition(string $contactIdColumn): string;
}

<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Api;

/**
 * The time zones in which a channel's quiet hours are kept, e.g. the recipient's for a phone channel (US state laws
 * such as Florida's FTSA go by the recipient's local time). Registered per channel in di.xml
 * (Model\QuietHours "zones": channel code => implementation); a channel without one uses the store's time zone. The
 * window, its settings and clock changes stay Notification's - register a zone provider, do not replace
 * QuietHoursInterface.
 *
 * @api
 */
interface QuietHoursZoneInterface
{
    /**
     * The zones the recipient may be in (several, e.g., for a number whose area spans zones): quiet when the window is
     * on in any of them, until the latest of their ends. [] when it cannot tell - not quiet; for a null recipient (the
     * whole store, asked before the recipients are known, see QuietHoursInterface) a recipient-based provider returns
     * [], so each delivery is held on its own.
     *
     * @param string|null $recipient the delivery's address on the channel (Delivery::$recipient), null for the store
     * @return \DateTimeZone[]
     */
    public function getZones(int $storeId, string $channel, ?string $recipient): array;
}

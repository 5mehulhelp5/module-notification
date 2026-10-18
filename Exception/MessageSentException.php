<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Exception;

/**
 * The provider accepted the notification (an email, a text, ...), then a later step failed (getPrevious()). Never send
 * it again.
 *
 * @api
 */
class MessageSentException extends \RuntimeException
{
}

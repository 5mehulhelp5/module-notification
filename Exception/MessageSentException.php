<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Exception;

/** The mail server accepted the email, then a later step failed (getPrevious()). Never send it again. */
class MessageSentException extends \RuntimeException
{
}

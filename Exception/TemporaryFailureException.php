<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Exception;

/**
 * The provider refused the notification for now - an SMTP 4xx reply (421, 450, 451, 452: busy, throttled, greylisted),
 * HTTP 429, or 503 with Retry-After: nothing was sent, and the same notification may be sent again soon. A feature
 * retries it after a short wait (getRetryAfter() when the provider said how long) and does not count it as a failed
 * attempt; anything else a provider throws is a failure as before.
 *
 * @api
 */
class TemporaryFailureException extends \RuntimeException
{
    public function __construct(string $message, private readonly ?int $retryAfter = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /** The seconds the provider asked to wait, or null when it did not say. */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }

    /**
     * An HTTP Retry-After header in seconds: a number of seconds or an HTTP date ("Mon, 19 Oct 2026 10:05:00 GMT");
     * null when absent or unreadable.
     */
    public static function parseRetryAfter(string $header, ?int $now = null): ?int
    {
        $header = trim($header);
        if ($header === '') {
            return null;
        }
        if (ctype_digit($header)) {
            return (int)$header;
        }
        $date = \DateTimeImmutable::createFromFormat('D, d M Y H:i:s \G\M\T', $header, new \DateTimeZone('GMT'));
        return $date === false ? null : max(0, $date->getTimestamp() - ($now ?? time()));
    }
}

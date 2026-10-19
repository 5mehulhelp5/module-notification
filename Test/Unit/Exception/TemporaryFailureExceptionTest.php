<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Test\Unit\Exception;

use CommerceKings\Notification\Exception\TemporaryFailureException;
use PHPUnit\Framework\TestCase;

class TemporaryFailureExceptionTest extends TestCase
{
    public function testItCarriesTheWaitAndTheCause(): void
    {
        $cause = new \RuntimeException('429');
        $error = new TemporaryFailureException('later', 30, $cause);
        $this->assertSame([30, $cause, 'later'], [$error->getRetryAfter(), $error->getPrevious(), $error->getMessage()]);
        $this->assertNull((new TemporaryFailureException('later'))->getRetryAfter());
    }

    public function testRetryAfterInSecondsOrAsAnHttpDate(): void
    {
        $now = strtotime('2026-10-19 10:00:00 GMT');
        $this->assertSame(120, TemporaryFailureException::parseRetryAfter(' 120 ', $now));
        $this->assertSame(0, TemporaryFailureException::parseRetryAfter('0', $now));
        $this->assertSame(300, TemporaryFailureException::parseRetryAfter('Mon, 19 Oct 2026 10:05:00 GMT', $now));
        $this->assertSame(0, TemporaryFailureException::parseRetryAfter('Mon, 19 Oct 2026 09:00:00 GMT', $now), 'a past date');
        foreach (['', '   ', 'soon', '-5', '1.5'] as $header) {
            $this->assertNull(TemporaryFailureException::parseRetryAfter($header, $now), var_export($header, true));
        }
    }
}

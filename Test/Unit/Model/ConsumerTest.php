<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Test\Unit\Model;

use CommerceKings\Notification\Api\NotificationTypeInterface;
use CommerceKings\Notification\Api\SubscriptionGuardInterface;
use CommerceKings\Notification\Api\SubscriptionTypeInterface;
use CommerceKings\Notification\Model\Consumer;
use CommerceKings\Notification\Model\Queue;
use CommerceKings\Notification\Model\SubscriptionTypePool;
use CommerceKings\Notification\Model\TypePool;
use Magento\Framework\Exception\NotFoundException;
use Magento\Framework\MessageQueue\ConnectionLostException;
use Magento\Framework\MessageQueue\MessageLockException;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Phrase;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ConsumerTest extends TestCase
{
    private array $processed = [];
    private array $errors = [];
    private array $skipped = [];
    /** subscription ids still valid for "subscribed" */
    private array $valid = [];

    private function consumer(): Consumer
    {
        $type = $this->createStub(NotificationTypeInterface::class);
        $type->method('process')->willReturnCallback(function ($reference) { $this->processed[] = $reference; });
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(function ($message) { $this->errors[] = $message; });
        $logger->method('info')->willReturnCallback(function ($message) { $this->skipped[] = $message; });
        $guard = $this->createStub(SubscriptionGuardInterface::class);
        $guard->method('isValid')->willReturnCallback(fn ($id, $code) => $code === 'subscribed' && in_array($id, $this->valid, true));
        return new Consumer(new TypePool(['cart_reminder' => $type, 'subscribed' => $type, 'broken' => new \stdClass()]), $logger,
            new SubscriptionTypePool(['subscribed' => $this->createStub(SubscriptionTypeInterface::class)]), $guard);
    }

    public function testHandsTheReferenceToItsType(): void
    {
        $this->consumer()->process('cart_reminder|42');
        $this->consumer()->process('cart_reminder|a|b');
        $this->assertSame(['42', 'a|b'], $this->processed);
    }

    public function testUnknownOrMalformedMessagesAreDroppedWithAnError(): void
    {
        foreach (['removed_module|42', 'cart_reminder', 'cart_reminder|', 'broken|1'] as $message) {
            $this->consumer()->process($message);
        }
        $this->assertSame([], $this->processed);
        $this->assertCount(4, $this->errors);
    }

    public function testSubscriptionTypesOnlyGetValidSubscriptions(): void
    {
        $this->valid = [7];
        foreach (['subscribed|7', 'subscribed|8', 'subscribed|abc', 'subscribed|7x'] as $message) {
            $this->consumer()->process($message);
        }
        $this->assertSame(['7'], $this->processed);
        $this->assertCount(3, $this->skipped);
        $this->assertSame([], $this->errors, 'a withdrawn subscription is expected, not an error');
    }

    private function failingConsumer(\Throwable $error): Consumer
    {
        $type = $this->createStub(NotificationTypeInterface::class);
        $type->method('process')->willThrowException($error);
        return new Consumer(new TypePool(['cart_reminder' => $type]), $this->createStub(LoggerInterface::class),
            new SubscriptionTypePool([]), $this->createStub(SubscriptionGuardInterface::class));
    }

    public function testFailureReachesMagentoWithoutTheRecipientAddress(): void
    {
        $smtp = new \RuntimeException('550 5.1.1 <jane@example.com>: Recipient address rejected', 550);
        try {
            $this->failingConsumer($smtp)->process('cart_reminder|42');
            $this->fail('Expected the failure to reach Magento so the message is rejected');
        } catch (\RuntimeException $error) {
            $this->assertNotSame($smtp, $error);
            $this->assertStringNotContainsString('jane@example.com', $error->getMessage());
            $this->assertStringStartsWith('CommerceKings_Notification: cart_reminder 42 failed (RuntimeException, code 550, at ',
                $error->getMessage());
            $this->assertStringContainsString(__FILE__ . ':', $error->getMessage());
        }
    }

    public function testAnErrorIsWrappedSoTheConsumerKeepsRunning(): void
    {
        $typeError = new \TypeError('Argument #1 ($to) must be of type string, null given for jane@example.com');
        try {
            $this->failingConsumer($typeError)->process('cart_reminder|42');
            $this->fail('Expected the failure to reach Magento as an exception it rejects');
        } catch (\RuntimeException $error) {
            $this->assertStringStartsWith('CommerceKings_Notification: cart_reminder 42 failed (TypeError, code 0, at ',
                $error->getMessage(), 'Magento\'s consumer catches exceptions, not errors: unwrapped, it would stop');
            $this->assertStringNotContainsString('jane@example.com', $error->getMessage());
        }
    }

    public function testMagentoQueueExceptionsKeepTheirMeaning(): void
    {
        foreach ([new MessageLockException(new Phrase('locked')), new ConnectionLostException('gone'),
            new NotFoundException(new Phrase('missing'))] as $queueError) {
            try {
                $this->failingConsumer($queueError)->process('cart_reminder|42');
                $this->fail('Expected ' . get_class($queueError));
            } catch (\Exception $error) {
                $this->assertSame($queueError, $error);
            }
        }
    }

    public function testInvalidNotificationCodeIsNotLogged(): void
    {
        $this->consumer()->process("jane@example.com\nforged|42");
        $this->assertSame(['CommerceKings_Notification: notification with an invalid code dropped'], $this->errors);
    }

    public function testQueuePublishesTypeAndReferenceToTheSharedTopic(): void
    {
        $published = [];
        $publisher = $this->createStub(PublisherInterface::class);
        $publisher->method('publish')->willReturnCallback(function ($topic, $data) use (&$published) { $published[] = [$topic, $data]; });
        $queue = new Queue($publisher);
        $queue->publish('cart_reminder', '42');
        $this->assertSame([['commercekings.notification.send', 'cart_reminder|42']], $published);
        $this->expectException(\InvalidArgumentException::class);
        $queue->publish('Bad Type', '1');
    }

    public function testEmptyQueueReferenceIsRejectedBeforePublishing(): void
    {
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($this->never())->method('publish');
        $this->expectException(\InvalidArgumentException::class);
        (new Queue($publisher))->publish('cart_reminder', '');
    }
}

<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Test\Unit\Model;

use CommerceKings\Notification\Api\NotificationTypeInterface;
use CommerceKings\Notification\Model\Consumer;
use CommerceKings\Notification\Model\Queue;
use CommerceKings\Notification\Model\TypePool;
use Magento\Framework\MessageQueue\PublisherInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ConsumerTest extends TestCase
{
    private array $processed = [];
    private array $errors = [];

    private function consumer(): Consumer
    {
        $type = $this->createStub(NotificationTypeInterface::class);
        $type->method('process')->willReturnCallback(function ($reference) { $this->processed[] = $reference; });
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(function ($message) { $this->errors[] = $message; });
        return new Consumer(new TypePool(['cart_reminder' => $type, 'broken' => new \stdClass()]), $logger);
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
}

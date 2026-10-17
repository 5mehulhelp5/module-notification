<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use Magento\Framework\Mail\TransportInterface;

/**
 * RFC 8058 one-click headers (List-Unsubscribe, List-Unsubscribe-Post) on one email.
 *
 * Magento 2.4.8+ sends through Symfony Mailer: the headers go on the Symfony message.
 * Magento 2.4.4-2.4.7 send with Laminas and have no public header API. Polyfill for those frozen releases:
 * the headers go on the Laminas message the email wraps (Magento\Framework\Mail\Message::$zendMessage), and the
 * transport's Laminas sender (Magento\Email\Model\Transport::$laminasTransport) is wrapped for this email only.
 * Magento re-reads the message as UTF-8 before sending, which would MIME-encode every unknown header; the
 * wrapper switches these two back to ASCII so mail clients can read the URL.
 */
class OneClickHeaders
{
    private const NAMES = ['List-Unsubscribe', 'List-Unsubscribe-Post'];

    /** false: unknown mail transport (e.g. replaced by an extension) - nothing was added. */
    public function add(TransportInterface $transport, string $url): bool
    {
        $values = ['<' . $url . '>', 'List-Unsubscribe=One-Click'];
        $mail = $transport->getMessage();
        if (is_object($mail) && method_exists($mail, 'getSymfonyMessage')) {
            $headers = $mail->getSymfonyMessage()->getHeaders();
            foreach (self::NAMES as $i => $name) {
                $headers->remove($name);
                $headers->addTextHeader($name, $values[$i]);
            }
            return true;
        }
        $laminas = is_object($mail) ? $this->property($mail, 'zendMessage') : null;
        if (!is_object($laminas) || !method_exists($laminas, 'getHeaders') || !$this->wrapSender($transport)) {
            return false;
        }
        $headers = $laminas->getHeaders();
        foreach (self::NAMES as $i => $name) {
            $headers->removeHeader($name);
            $headers->addHeaderLine($name, $values[$i]);
        }
        return true;
    }

    private function wrapSender(TransportInterface $transport): bool
    {
        // 2.4.6-2.4.7 create the sender on first use; 2.4.4-2.4.5 in the constructor
        if ($this->property($transport, 'laminasTransport') === null && method_exists($transport, 'getTransport')) {
            $transport->getTransport();
        }
        $sender = $this->property($transport, 'laminasTransport');
        if (!$sender instanceof \Laminas\Mail\Transport\TransportInterface) {
            return false;
        }
        $this->property($transport, 'laminasTransport', new class ($sender, self::NAMES) implements
            \Laminas\Mail\Transport\TransportInterface {
            public function __construct(private readonly object $sender, private readonly array $names)
            {
            }

            public function send(\Laminas\Mail\Message $message)
            {
                foreach ($this->names as $name) {
                    $found = $message->getHeaders()->get($name);
                    foreach ($found instanceof \ArrayIterator ? $found : array_filter([$found]) as $header) {
                        $header->setEncoding('ASCII');
                    }
                }
                return $this->sender->send($message);
            }

            public function __call(string $method, array $arguments): mixed
            {
                return $this->sender->$method(...$arguments);
            }
        });
        return true;
    }

    /** Reads (or, with a third argument, writes) a property declared anywhere in the object's class hierarchy. */
    private function property(object $object, string $name, mixed ...$value): mixed
    {
        for ($class = new \ReflectionClass($object); $class; $class = $class->getParentClass()) {
            if ($class->hasProperty($name) && $class->getProperty($name)->getDeclaringClass()->getName() === $class->getName()) {
                $property = $class->getProperty($name);
                if ($value) {
                    $property->setValue($object, $value[0]);
                    return null;
                }
                return $property->isInitialized($object) ? $property->getValue($object) : null;
            }
        }
        return null;
    }
}

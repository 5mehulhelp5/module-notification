<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Test\Integration;

use CommerceKings\Notification\Api\Data\Delivery;
use CommerceKings\Notification\Api\Data\DeliveryContent;
use CommerceKings\Notification\Api\Data\Permission;
use CommerceKings\Notification\Api\Data\Target;
use CommerceKings\Notification\Api\ProviderInterface;
use CommerceKings\Notification\Api\QuietHoursInterface;
use CommerceKings\Notification\Api\RecipientNormalizerInterface;
use CommerceKings\Notification\Api\SenderInterface;
use CommerceKings\Notification\Api\SubscriptionPermissionInterface;
use CommerceKings\Notification\Api\SubscriptionTypeInterface;
use CommerceKings\Notification\Model\Config;
use CommerceKings\Notification\Model\ContactRepository;
use CommerceKings\Notification\Model\Cooldown;
use CommerceKings\Notification\Model\PermissionResolver;
use CommerceKings\Notification\Model\ProviderPool;
use CommerceKings\Notification\Model\RecipientNormalizerPool;
use CommerceKings\Notification\Model\Sender;
use CommerceKings\Notification\Model\StopList;
use CommerceKings\Notification\Model\SubscriptionManagement;
use CommerceKings\Notification\Model\SubscriptionTypePool;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Framework\ObjectManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Notification is channel-generic: a test SMS and a test WhatsApp channel – each with a subscription type, a phone
 * normalizer and a provider – go through the same sign-up, contacts, stop list, cooldown and sender as email, on the
 * real database. Nothing in Notification knows these channels.
 */
class MultiChannelTest extends TestCase
{
    private ObjectManagerInterface $om;
    private ResourceConnection $resource;
    private string $run;
    private int $storeId;
    private array $references = [];
    /** what each test provider delivered: [channel, recipient, text] */
    private array $delivered = [];
    private SubscriptionManagement $management;
    private StopList $stopList;
    private Sender $sender;
    private ContactRepository $contacts;
    private RecipientNormalizerPool $normalizers;
    /** EVENT_SAVED data dispatched by the management */
    private array $saved = [];
    private ?int $customerId = null;

    protected function setUp(): void
    {
        $root = getenv('MAGENTO_ROOT') ?: (defined('BP') ? BP : getcwd());
        if (!is_file($root . '/app/bootstrap.php')) {
            throw new \RuntimeException('Run from the Magento root or set MAGENTO_ROOT.');
        }
        require_once $root . '/app/bootstrap.php';
        $this->om = Bootstrap::create(BP, $_SERVER)->getObjectManager();
        $this->om->get(State::class)->setAreaCode('frontend');
        $stores = $this->om->get(StoreManagerInterface::class);
        $stores->setCurrentStore('default');
        $this->storeId = (int)$stores->getStore()->getId();
        $this->resource = $this->om->get(ResourceConnection::class);
        $this->run = bin2hex(random_bytes(4));

        // a phone normalizer, as an SMS or WhatsApp provider module would bring: digits, +381 for a leading 0
        $phone = new class implements RecipientNormalizerInterface {
            public function normalize(string $recipient): string
            {
                $digits = (string)preg_replace('/\D/', '', $recipient);
                if (strlen($digits) < 8) {
                    return '';
                }
                return '+' . (str_starts_with($digits, '0') ? '381' . substr($digits, 1) : $digits);
            }
        };
        $this->normalizers = new RecipientNormalizerPool(['sms' => $phone, 'whatsapp' => $phone]);
        $types = new SubscriptionTypePool([$this->type('sms') => $this->subscriptionType('sms'),
            $this->type('whatsapp') => $this->subscriptionType('whatsapp')]);
        $this->contacts = $this->om->create(ContactRepository::class, ['normalizers' => $this->normalizers]);
        $this->stopList = $this->om->create(StopList::class,
            ['normalizers' => $this->normalizers, 'contacts' => $this->contacts, 'types' => $types]);
        $permission = $this->createStub(SubscriptionPermissionInterface::class);
        $permission->method('check')->willReturn(Permission::granted(''));
        $permission->method('validCondition')->willReturn('1 = 1');
        $this->management = $this->om->create(SubscriptionManagement::class, ['types' => $types,
            'permissions' => new PermissionResolver($permission, $types, $this->resource), 'contacts' => $this->contacts,
            'stopList' => $this->stopList, 'normalizers' => $this->normalizers, 'events' => $this->events()]);
        $providers = new ProviderPool($this->om->get(Config::class),
            [$this->provider('acme_sms', 'sms'), $this->provider('acme_whatsapp', 'whatsapp')]);
        // quiet hours are on by default: never held here, whatever the time of the run
        $this->sender = $this->om->create(Sender::class, ['providers' => $providers, 'normalizers' => $this->normalizers,
            'stopList' => $this->stopList, 'quietHours' => $this->createStub(QuietHoursInterface::class)]);
    }

    private function events(): \Magento\Framework\Event\ManagerInterface
    {
        $events = $this->createStub(\Magento\Framework\Event\ManagerInterface::class);
        $events->method('dispatch')->willReturnCallback(function ($name, $data) {
            if ($name === \CommerceKings\Notification\Api\SubscriptionManagementInterface::EVENT_SAVED) {
                $this->saved[] = $data;
            }
        });
        return $events;
    }

    private function customer(): int
    {
        $customer = $this->om->get(\Magento\Customer\Api\Data\CustomerInterfaceFactory::class)->create();
        $customer->setWebsiteId(1)->setEmail('ck-multi-' . $this->run . '@example.test')->setFirstname('Multi')
            ->setLastname('Channel');
        return $this->customerId = (int)$this->om->get(\Magento\Customer\Api\CustomerRepositoryInterface::class)
            ->save($customer)->getId();
    }

    protected function tearDown(): void
    {
        $db = $this->resource->getConnection();
        if ($this->customerId !== null) {
            $db->delete($this->resource->getTableName('customer_entity'), ['entity_id = ?' => $this->customerId]);
            $this->stopList->resume('email', 'ck-multi-' . $this->run . '@example.test');
        }
        $db->delete($this->resource->getTableName(SubscriptionManagement::TABLE),
            ['type IN (?)' => [$this->type('sms'), $this->type('whatsapp')]]);
        $db->delete($this->resource->getTableName(ContactRepository::TABLE),
            ['channel IN (?)' => ['sms', 'whatsapp'], 'recipient LIKE ?' => '%' . $this->number()]);
        foreach (['sms', 'whatsapp'] as $channel) {
            $this->stopList->resume($channel, $this->number());
            $db->delete($this->resource->getTableName('ck_notification_cooldown'),
                ['recipient_hash = ?' => $this->om->get(Cooldown::class)->hash($channel . ':' . $this->normalizers->normalize($channel, $this->number()))]);
        }
    }

    private function type(string $channel): string
    {
        return 'ck_' . $channel . '_' . $this->run;
    }

    /** A unique test number per run: 06x xxx xxxx. */
    private function number(): string
    {
        return '06' . substr((string)hexdec($this->run), 0, 8);
    }

    private function subscriptionType(string $channel): SubscriptionTypeInterface
    {
        $type = $this->createStub(SubscriptionTypeInterface::class);
        $type->method('getPurpose')->willReturn($channel . '_reminder');
        $type->method('getChannel')->willReturn($channel);
        $type->method('isStoppable')->willReturn(true);
        $type->method('isSingle')->willReturn(true);
        $type->method('authorize')->willReturnCallback(function () use ($channel) {
            $this->references[$channel] ??= random_int(1000000000, 2000000000);
            return new Target($this->references[$channel], $this->storeId, true, '1 = 1');
        });
        return $type;
    }

    private function provider(string $code, string $channel): ProviderInterface
    {
        $test = $this;
        return new class($code, $channel, $test) implements ProviderInterface {
            public function __construct(private string $code, private string $channel, private MultiChannelTest $test) {}
            public function getCode(): string { return $this->code; }
            public function getLabel(): string { return $this->code; }
            public function getChannel(): string { return $this->channel; }
            public function supportsOneClickUnsubscribe(): bool { return false; }
            public function send(Delivery $delivery, DeliveryContent $content): void
            {
                $this->test->record([$this->channel, $delivery->recipient, $content->text]);
            }
        };
    }

    public function record(array $delivery): void
    {
        $this->delivered[] = $delivery;
    }

    private function subscriptions(): array
    {
        $db = $this->resource->getConnection();
        return $db->fetchPairs($db->select()->from(['s' => $this->resource->getTableName(SubscriptionManagement::TABLE)], [])
            ->join(['n' => $this->resource->getTableName(ContactRepository::TABLE)], 'n.contact_id = s.contact_id',
                ['n.channel', 'n.recipient'])
            ->where('s.type IN (?)', [$this->type('sms'), $this->type('whatsapp')]));
    }

    private function text(string $channel, bool $stoppable = true, bool $cooldown = true): Delivery
    {
        return Delivery::text($this->storeId, $channel, ' ' . $this->number() . ' ',
            fn () => new DeliveryContent([], null, null, "Your cart is waiting ($channel)"), $cooldown, null, $stoppable);
    }

    public function testOneNumberSubscribesOnSmsAndWhatsappAsTwoContacts(): void
    {
        $typed = substr($this->number(), 0, 3) . ' ' . substr($this->number(), 3, 3) . ' ' . substr($this->number(), 6);
        $this->assertTrue($this->management->subscribeGuest($this->type('sms'), 'ref', recipient: $typed));
        $this->assertTrue($this->management->subscribeGuest($this->type('whatsapp'), 'ref', recipient: $this->number()));
        $this->assertSame(['sms' => $typed, 'whatsapp' => $this->number()], $this->subscriptions(),
            'one contact per channel, kept as entered');
        $this->assertSame($this->contacts->find(1, $typed, 'sms'), $this->contacts->find(1, '+381' . substr($this->number(), 1), 'sms'),
            'one number however it is typed: the same SMS contact');
        $this->assertNotSame($this->contacts->find(1, $this->number(), 'sms'), $this->contacts->find(1, $this->number(), 'whatsapp'));
    }

    public function testAnSmsTypeRefusesAnEmailAddress(): void
    {
        $this->expectException(\Magento\Framework\Exception\InputException::class);
        $this->management->subscribeGuest($this->type('sms'), 'ref', 'someone@example.test');
    }

    public function testEachChannelIsSentByItsOwnProviderWithANormalizedNumber(): void
    {
        $normalized = '+381' . substr($this->number(), 1);
        $this->assertSame(SenderInterface::SENT, $this->sender->send($this->text('sms')));
        $this->assertSame(SenderInterface::SENT, $this->sender->send($this->text('whatsapp')), 'its own cooldown');
        $this->assertSame([['sms', $normalized, 'Your cart is waiting (sms)'], ['whatsapp', $normalized, 'Your cart is waiting (whatsapp)']],
            $this->delivered);
        $this->assertSame(SenderInterface::COOLDOWN, $this->sender->send($this->text('sms')), 'the SMS cooldown holds');
    }

    public function testStoppingSmsStopsOnlySmsAndItsSubscription(): void
    {
        $this->management->subscribeGuest($this->type('sms'), 'ref', recipient: $this->number());
        $this->management->subscribeGuest($this->type('whatsapp'), 'ref', recipient: $this->number());
        // e.g. the SMS provider's webhook: the person replied STOP, from their number in international format
        $this->stopList->stop('sms', '+381' . substr($this->number(), 1));

        $this->assertSame(['whatsapp' => $this->number()], $this->subscriptions(), 'the SMS subscription is gone');
        $this->assertTrue($this->stopList->isStopped('sms', $this->number()));
        $this->assertFalse($this->stopList->isStopped('whatsapp', $this->number()), 'WhatsApp is another channel');
        $this->assertSame(SenderInterface::STOPPED, $this->sender->send($this->text('sms', cooldown: false)));
        $this->assertSame(SenderInterface::SENT, $this->sender->send($this->text('sms', false, false)),
            'a message that is not stoppable (e.g. order shipped) still goes out');
        $this->assertSame(SenderInterface::SENT, $this->sender->send($this->text('whatsapp', cooldown: false)));
        $this->assertTrue($this->management->subscribeGuest($this->type('sms'), 'ref', recipient: $this->number()),
            'a new sign-up is answered as stored...');
        $this->assertSame(['whatsapp' => $this->number()], $this->subscriptions(), '...but not written');
    }

    public function testTheStopLinkWorksForAPhoneNumber(): void
    {
        $url = $this->stopList->getStopUrl('sms', $this->number(), $this->storeId);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        $this->assertStringContainsString('/cknotification/stop', $url);
        $this->assertStringNotContainsString(substr($this->number(), 2), $url, 'the number is encrypted');
        $link = $this->stopList->fromLink($query['r'], $query['s']);
        $this->assertSame(['sms', $this->number(), true], [$link['channel'], $link['recipient'], $link['current']]);
    }

    /** A signed-in customer gives a phone number for an SMS type: their contact on that channel, next to the account. */
    public function testACustomerSubscribesTheirPhoneNumber(): void
    {
        $customerId = $this->customer();
        $this->assertTrue($this->management->subscribeCustomer($customerId, $this->type('sms'), 'ref', $this->number()));
        $db = $this->resource->getConnection();
        $row = $db->fetchRow($db->select()->from($this->resource->getTableName(SubscriptionManagement::TABLE))
            ->where('type = ?', $this->type('sms')));
        $this->assertSame($customerId, (int)$row['customer_id']);
        $repository = $this->om->get(\CommerceKings\Notification\Api\SubscriptionRepositoryInterface::class);
        $subscription = $repository->get((int)$row['subscription_id']);
        $this->assertSame(['channel' => 'sms', 'recipient' => $this->number()], $repository->getRecipient($subscription));
        $this->assertSame('ck-multi-' . $this->run . '@example.test', $repository->getEmail($subscription),
            'getEmail() stays the account email');

        // stopping the account email leaves the SMS subscription; stopping the number removes it
        $this->stopList->stop('email', 'ck-multi-' . $this->run . '@example.test');
        $this->assertSame(['sms' => $this->number()], $this->subscriptions());
        $this->stopList->stop('sms', '+381' . substr($this->number(), 1));
        $this->assertSame([], $this->subscriptions(), 'the customer\'s SMS subscription is gone');
        $this->assertTrue($this->management->subscribeCustomer($customerId, $this->type('sms'), 'ref', $this->number()),
            'a stopped number is answered as stored...');
        $this->assertSame([], $this->subscriptions(), '...but not written');
    }

    /** Real MySQL: a new row, a changed row and an identical retry; only the first two are announced. */
    public function testTheSavedEventFollowsWhatTheDatabaseWrote(): void
    {
        $this->management->subscribeGuest($this->type('sms'), 'ref', $this->number());
        $this->management->subscribeGuest($this->type('sms'), 'ref', $this->number());
        // another number: the last digit changed
        $other = substr($this->number(), 0, -1) . (((int)substr($this->number(), -1) + 1) % 10);
        $this->management->subscribeGuest($this->type('sms'), 'ref', $other);
        $id = (int)$this->resource->getConnection()->fetchOne($this->resource->getConnection()->select()
            ->from($this->resource->getTableName(SubscriptionManagement::TABLE), 'subscription_id')
            ->where('type = ?', $this->type('sms')));
        $this->assertCount(2, $this->saved, 'the identical retry is not announced');
        $this->assertSame([$id, true, 'sms'], [$this->saved[0]['subscription_id'], $this->saved[0]['created'], $this->saved[0]['channel']]);
        $this->assertSame([$id, false], [$this->saved[1]['subscription_id'], $this->saved[1]['created']],
            'a changed number: the same row, not created');
        $this->assertNotSame($this->saved[0]['contact_id'], $this->saved[1]['contact_id']);
        $this->assertSame([null, $this->saved[0]['contact_id']],
            [$this->saved[0]['previous_contact_id'], $this->saved[1]['previous_contact_id']], 'the number before the change');
        $this->resource->getConnection()->delete($this->resource->getTableName(ContactRepository::TABLE),
            ['contact_id = ?' => $this->saved[1]['contact_id']]);
    }

    /** first_for_address: the first subscription of a number in the website, not every new one of a known number. */
    public function testOnlyTheFirstSubscriptionOfANumberIsFirstForTheAddress(): void
    {
        $this->management->subscribeGuest($this->type('sms'), 'ref', $this->number());
        $this->references['sms'] = random_int(1000000000, 2000000000);   // another cart, the same number
        $this->management->subscribeGuest($this->type('sms'), 'ref', $this->number());
        $other = substr($this->number(), 0, -1) . (((int)substr($this->number(), -1) + 1) % 10);
        $this->management->subscribeGuest($this->type('sms'), 'ref', $other);
        $this->assertSame([true, false, true], array_map(fn ($event) => $event['first_for_address'], $this->saved),
            'first cart: confirm; second cart of the number: not again; a new number: confirm');
        $db = $this->resource->getConnection();
        $db->delete($this->resource->getTableName(SubscriptionManagement::TABLE), ['contact_id = ?' => $this->saved[2]['contact_id']]);
        $db->delete($this->resource->getTableName(ContactRepository::TABLE), ['contact_id = ?' => $this->saved[2]['contact_id']]);
    }
}

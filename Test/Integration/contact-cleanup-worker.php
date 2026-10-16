<?php
declare(strict_types=1);

use CommerceKings\Notification\Api\ContactReferenceInterface;
use CommerceKings\Notification\Cron\CleanUpContacts;
use CommerceKings\Notification\Model\ContactReferencePool;
use CommerceKings\Notification\Model\SubscriptionContactReference;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\ResourceConnection;

$root = getenv('MAGENTO_ROOT') ?: getcwd();
require $root . '/app/bootstrap.php';
$id = (int)($argv[1] ?? 0);
if ($id <= 0) {
    throw new InvalidArgumentException('A fixture contact id is required.');
}
$om = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$resource = $om->get(ResourceConnection::class);
$reference = new class($id, new SubscriptionContactReference($resource)) implements ContactReferenceInterface {
    public function __construct(private int $id, private SubscriptionContactReference $actual) {}
    public function usedCondition(string $column): string
    {
        return sprintf('%s <> %d OR (%s)', $column, $this->id, $this->actual->usedCondition($column));
    }
};
$resource->getConnection()->query('SET SESSION innodb_lock_wait_timeout = 10');
echo $resource->getConnection()->fetchOne('SELECT CONNECTION_ID()') . PHP_EOL;
flush();
echo (new CleanUpContacts($resource, new ContactReferencePool(['fixture' => $reference])))->execute() . PHP_EOL;

<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model;

use CommerceKings\Notification\Api\DeliveryStateInterface;
use Magento\Framework\App\ResourceConnection;

/** Direct SQL with bound values; aliases are checked to be identifiers, never taken from input. */
class DeliveryState implements DeliveryStateInterface
{
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    public function getTableName(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }

    public function joinCondition(string $stateAlias, string $subscriptionAlias): string
    {
        [$d, $s] = [$this->alias($stateAlias), $this->alias($subscriptionAlias)];
        return sprintf('%1$s.type = %2$s.type AND %1$s.reference_id = %2$s.reference_id'
            . ' AND %1$s.subject_key = %2$s.subject_key', $d, $s);
    }

    public function keyCondition(string $type, int $referenceId, string $subjectKey = '', string $alias = ''): array
    {
        $prefix = $alias === '' ? '' : $this->alias($alias) . '.';
        return [$prefix . 'type = ?' => $type, $prefix . 'reference_id = ?' => $referenceId,
            $prefix . 'subject_key = ?' => $subjectKey];
    }

    public function ensure(string $type, int $referenceId, string $subjectKey = ''): void
    {
        $db = $this->resource->getConnection();
        $db->query(sprintf('INSERT IGNORE INTO %s (type, reference_id, subject_key) VALUES (?, ?, ?)',
            $db->quoteIdentifier($this->getTableName())), [$type, $referenceId, $subjectKey]);
    }

    public function load(string $type, int $referenceId, string $subjectKey = ''): ?array
    {
        $db = $this->resource->getConnection();
        $select = $db->select()->from($this->getTableName());
        foreach ($this->keyCondition($type, $referenceId, $subjectKey) as $condition => $value) {
            $select->where($condition, $value);
        }
        $row = $db->fetchRow($select);
        return $row ?: null;
    }

    private function alias(string $alias): string
    {
        if (!preg_match('/^[a-z_][a-z0-9_]*$/iD', $alias)) {
            throw new \InvalidArgumentException('A table alias must be a SQL identifier.');
        }
        return $alias;
    }
}

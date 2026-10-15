<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Test\Unit;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;

/**
 * Recording fake for the direct SQL this module runs: every update/insert/delete is kept with its
 * data and WHERE conditions, reads return what the test queues. Table names are passed through.
 */
trait DbTestTrait
{
    /** @var array<int, array{table: string, data: array, where: array}> */
    private array $updates = [];
    private array $inserts = [];
    private array $deletes = [];
    /** @var array<int, array{0: string, 1: mixed}> where() calls of the last select */
    private array $selectWhere = [];
    /** queued return values of update() (rows affected), default 1 */
    private array $updateResults = [];
    private mixed $fetchRowResult = false;
    private mixed $fetchOneResult = false;
    private array $fetchColResult = [];
    private array $fetchColResults = [];
    /** queued fetchPairs() results, one per call; [] when none left */
    private array $fetchPairsResults = [];
    /** limit() calls of selects: [count, offset] */
    private array $limits = [];
    /** raw query() calls: [sql, bind]; queued rowCount() results, default 1 */
    private array $queries = [];
    private array $queryRowCounts = [];
    private ?\Throwable $queryFailure = null;
    private array $transactionEvents = [];
    private array $rowLocks = [];

    private function resource(): ResourceConnection
    {
        $select = $this->createStub(Select::class);
        foreach (['from', 'order', 'joinInner'] as $method) {
            $select->method($method)->willReturnSelf();
        }
        $select->method('forUpdate')->willReturnCallback(function ($flag) use ($select) {
            $this->rowLocks[] = $flag;
            return $select;
        });
        $select->method('limit')->willReturnCallback(function ($count = null, $offset = null) use ($select) {
            $this->limits[] = [$count, $offset];
            return $select;
        });
        $select->method('where')->willReturnCallback(function ($cond, $value = null) use ($select) {
            $this->selectWhere[] = [$cond, $value];
            return $select;
        });

        $db = $this->createStub(AdapterInterface::class);
        $db->method('select')->willReturn($select);
        foreach (['beginTransaction', 'commit', 'rollBack'] as $method) {
            $db->method($method)->willReturnCallback(function () use ($method, $db) {
                $this->transactionEvents[] = $method;
                return $db;
            });
        }
        $db->method('fetchAll')->willReturnCallback(function () {
            $this->transactionEvents[] = 'lock';
            return [];
        });
        $db->method('update')->willReturnCallback(function ($table, array $data, $where = '') {
            $this->updates[] = ['table' => $table, 'data' => $data, 'where' => (array)$where];
            return $this->updateResults ? array_shift($this->updateResults) : 1;
        });
        $db->method('insertOnDuplicate')->willReturnCallback(function ($table, array $data, array $fields = []) {
            $this->inserts[] = ['table' => $table, 'data' => $data];
            return 1;
        });
        $db->method('delete')->willReturnCallback(function ($table, $where = '') {
            $this->deletes[] = ['table' => $table, 'where' => (array)$where];
            return 3;
        });
        $db->method('fetchRow')->willReturnCallback(fn () => $this->fetchRowResult);
        $db->method('fetchOne')->willReturnCallback(fn () => $this->fetchOneResult);
        $db->method('fetchCol')->willReturnCallback(fn () => $this->fetchColResults ? array_shift($this->fetchColResults) : $this->fetchColResult);
        $db->method('getCheckSql')->willReturnCallback(fn ($condition, $yes, $no) => 'IF(' . $condition . ', ' . $yes . ', ' . $no . ')');
        $db->method('fetchPairs')->willReturnCallback(fn () => $this->fetchPairsResults ? array_shift($this->fetchPairsResults) : []);
        $db->method('query')->willReturnCallback(function ($sql, $bind = []) {
            $this->queries[] = [$sql, $bind];
            if ($this->queryFailure !== null) {
                throw $this->queryFailure;
            }
            $statement = $this->createStub(\Zend_Db_Statement_Interface::class);
            $statement->method('rowCount')->willReturn($this->queryRowCounts ? array_shift($this->queryRowCounts) : 1);
            return $statement;
        });
        $db->method('quoteInto')->willReturnCallback(fn ($text, $value) => str_replace('?', "'" . $value . "'", $text));
        $db->method('quote')->willReturnCallback(fn ($value) => "'" . $value . "'");
        $db->method('quoteIdentifier')->willReturnArgument(0);

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($db);
        $resource->method('getTableName')->willReturnArgument(0);
        return $resource;
    }

    /** All WHERE conditions of an update as plain strings ("col = ?" keys and numeric-key conditions). */
    private static function conditions(array $where): array
    {
        $out = [];
        foreach ($where as $key => $value) {
            $out[] = is_int($key) ? $value : $key;
        }
        return $out;
    }
}

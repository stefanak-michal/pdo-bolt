<?php

namespace pdo_bolt\tests;

use pdo_bolt\drivers\bolt\Statement as BoltStatement;

/**
 * Class PDOStatementTest
 * @author Michal Stefanak
 * @link https://github.com/stefanak-michal/pdo-bolt
 */
class PDOStatementTest extends \PHPUnit\Framework\TestCase
{
    private static \pdo_bolt\PDO $pdo;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$pdo = new \pdo_bolt\PDO(
            'bolt:host=' . ($GLOBALS['NEO_HOST'] ?? '127.0.0.1') . ';port=' . ($GLOBALS['NEO_PORT'] ?? 7687) . ';appname=pdo-bolt',
            $GLOBALS['NEO_USER'] ?? 'neo4j',
            $GLOBALS['NEO_PASS'] ?? 'neo4j',
            ['protocol_versions' => [5]]
        );
        self::assertInstanceOf(\pdo_bolt\PDO::class, self::$pdo);
    }

    public function testBindValue(): void
    {
        /** @var BoltStatement $stmt */
        $stmt = self::$pdo->prepare('RETURN $n as num, $p as str');
        $this->assertInstanceOf(BoltStatement::class, $stmt);
        $stmt->bindValue('n', 4324, \PDO::PARAM_INT);
        $stmt->bindValue('p', 'fdasf');
        $this->assertTrue($stmt->execute());
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertEquals(['num' => 4324, 'str' => 'fdasf'], $row);
    }

    public function testBindParam(): void
    {
        /** @var BoltStatement $stmt */
        $stmt = self::$pdo->prepare('RETURN $n as num, $p as str');
        $this->assertInstanceOf(BoltStatement::class, $stmt);
        $i = rand();
        $stmt->bindParam('n', $i, \PDO::PARAM_INT);
        $i = rand();
        $s = 'foo';
        $stmt->bindParam('p', $s);
        $s = 'bar';
        $this->assertTrue($stmt->execute());
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertEquals(['num' => $i, 'str' => $s], $row);
    }

    public function testBindColumn(): void
    {
        /** @var BoltStatement $stmt */
        $stmt = self::$pdo->prepare('RETURN 123 as num, "foo" as str');
        $n = $s = null;
        $stmt->bindColumn('num', $n, \PDO::PARAM_INT);
        $stmt->bindColumn('str', $s);
        $this->assertTrue($stmt->execute());
        $this->assertTrue($stmt->fetch(\PDO::FETCH_BOUND));
        $this->assertEquals(123, $n);
        $this->assertEquals('foo', $s);
    }

    public function testCloseCursor(): void
    {
        /** @var BoltStatement $stmt */
        $stmt = self::$pdo->query('RETURN 123 as num, "foo" as str');
        $this->assertInstanceOf(BoltStatement::class, $stmt);
        $this->assertTrue($stmt->closeCursor());
    }

    public function testColumnCount(): void
    {
        /** @var BoltStatement $stmt */
        $stmt = self::$pdo->query('RETURN 123 as num, "foo" as str');
        $this->assertInstanceOf(BoltStatement::class, $stmt);
        $this->assertEquals(2, $stmt->columnCount());
        $this->assertTrue($stmt->closeCursor());
    }

    public function testFetchAll(): void
    {
        /** @var BoltStatement $stmt */
        $stmt = self::$pdo->query('UNWIND [123, 456] AS num RETURN num');
        $this->assertInstanceOf(BoltStatement::class, $stmt);
        $this->assertEquals([
            [0 => 123, 'num' => 123],
            [0 => 456, 'num' => 456]
        ], $stmt->fetchAll());
    }

    public function testFetchColumn(): void
    {
        /** @var BoltStatement $stmt */
        $stmt = self::$pdo->query('RETURN 123 as num, "foo" as str');
        $this->assertInstanceOf(BoltStatement::class, $stmt);
        $this->assertEquals('foo', $stmt->fetchColumn(1));
    }

    public function testFetchObject1(): void
    {
        /** @var BoltStatement $stmt */
        $stmt = self::$pdo->query('RETURN 123 as num, "foo" as str');
        $this->assertInstanceOf(BoltStatement::class, $stmt);
        $cls = $stmt->fetchObject();
        $this->assertEquals(123, $cls->num);
        $this->assertEquals('foo', $cls->str);
    }

    public function testFetchObject2(): void
    {
        /** @var BoltStatement $stmt */
        $stmt = self::$pdo->query('RETURN 123 as num, "foo" as str');
        $this->assertInstanceOf(BoltStatement::class, $stmt);
        $cls = $stmt->fetchObject(classes\FetchObject::class, ['hello']);
        $this->assertEquals(123, $cls->num());
        $this->assertEquals('foo', $cls->str());
        $this->assertEquals('hello', $cls->bar);
    }

    public function testGetColumnMeta(): void
    {
        /** @var BoltStatement $stmt */
        $stmt = self::$pdo->query('RETURN 123 as num, "foo" as str');
        $this->assertInstanceOf(BoltStatement::class, $stmt);
        $this->assertEquals('num', $stmt->getColumnMeta(0)['name']);
        $this->assertTrue($stmt->closeCursor());
    }

    public function testNextRowset(): void
    {
        /** @var BoltStatement $stmt */
        $stmt = self::$pdo->query('UNWIND [123, 456] AS num RETURN num');
        $this->assertInstanceOf(BoltStatement::class, $stmt);
        $this->assertTrue($stmt->nextRowset());
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assertEquals(456, $row['num']);
    }
}

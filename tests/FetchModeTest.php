<?php

namespace pdo_bolt\tests;

use pdo_bolt\drivers\bolt\Statement as BoltStatement;

/**
 * Class FetchModeTest
 * @author Michal Stefanak
 * @link https://github.com/stefanak-michal/pdo-bolt
 */
class FetchModeTest extends \PHPUnit\Framework\TestCase
{
    private static \pdo_bolt\PDO $pdo;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$pdo = new \pdo_bolt\PDO(
            'bolt:host=' . (getenv('GDB_HOST') ? getenv('GDB_HOST') : '127.0.0.1')
            . ';port=' . (getenv('GDB_PORT') ? getenv('GDB_PORT') : 7687)
            . ';appname=pdo-bolt',
            getenv('GDB_USERNAME'),
            getenv('GDB_PASSWORD'),
            ['protocol_versions' => [getenv('BOLT_VERSION')]]
        );
        self::assertInstanceOf(\pdo_bolt\PDO::class, self::$pdo);
    }

    public function testAssoc(): void
    {
        /** @var BoltStatement $stmt */
        $stmt = self::$pdo->query('RETURN 123 as num, "foo" as str');
        $this->assertInstanceOf(BoltStatement::class, $stmt);
        $this->assertEquals(['num' => 123, 'str' => 'foo'], $stmt->fetch(\PDO::FETCH_ASSOC));
    }

    public function testBoth(): void
    {
        /** @var BoltStatement $stmt */
        $stmt = self::$pdo->query('RETURN 123 as num, "foo" as str');
        $this->assertInstanceOf(BoltStatement::class, $stmt);
        $this->assertEquals([0 => 123, 1 => 'foo', 'num' => 123, 'str' => 'foo'], $stmt->fetch());
    }

    public function testClass(): void
    {
        /** @var BoltStatement $stmt */
        $stmt = self::$pdo->query('RETURN 123 as num, "foo" as str, "hello" as bar', \PDO::FETCH_CLASS, classes\FetchObject::class);
        $this->assertInstanceOf(BoltStatement::class, $stmt);
        foreach ($stmt as $row) {
            $this->assertEquals(123, $row->num());
            $this->assertEquals('foo', $row->str());
            $this->assertEquals(null, $row->bar);
        }

        $stmt = self::$pdo->query('RETURN 123 as num, "foo" as str, "hello" as bar', \PDO::FETCH_CLASS | \PDO::FETCH_PROPS_LATE, classes\FetchObject::class);
        $this->assertInstanceOf(BoltStatement::class, $stmt);
        foreach ($stmt as $row) {
            $this->assertEquals(123, $row->num());
            $this->assertEquals('foo', $row->str());
            $this->assertEquals('hello', $row->bar);
        }

        $stmt = self::$pdo->prepare('RETURN $cls as cls, 123 as num, "foo" as str');
        $this->assertInstanceOf(BoltStatement::class, $stmt);
        $stmt->bindValue('cls', classes\FetchObject::class);
        $this->assertTrue($stmt->execute());
        $stmt->setFetchMode(\PDO::FETCH_CLASS | \PDO::FETCH_CLASSTYPE);
        foreach ($stmt as $row) {
            $this->assertEquals(123, $row->num());
            $this->assertEquals('foo', $row->str());
        }
    }

    public function testInto(): void
    {
        /** @var BoltStatement $stmt */
        $stmt = self::$pdo->query('UNWIND [123, 456] as num RETURN num');
        $this->assertInstanceOf(BoltStatement::class, $stmt);
        $cls = $stmt->fetch(\PDO::FETCH_OBJ);
        $this->assertEquals(123, $cls->num);
        $stmt->fetch(\PDO::FETCH_INTO);
        $this->assertEquals(456, $cls->num);
    }

    public function testNum(): void
    {
        /** @var BoltStatement $stmt */
        $stmt = self::$pdo->query('RETURN 123 as num, "foo" as str');
        $this->assertInstanceOf(BoltStatement::class, $stmt);
        $this->assertEquals([123, 'foo'], $stmt->fetch(\PDO::FETCH_NUM));
    }
}

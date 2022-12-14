<?php

namespace pdo_bolt\tests;

use pdo_bolt\drivers\bolt\Statement as BoltStatement;
use pdo_bolt\PDO;
use PDOException;

/**
 * Class PDOTest
 * @author Michal Stefanak
 * @link https://github.com/stefanak-michal/pdo-bolt
 */
class PDOTest extends \PHPUnit\Framework\TestCase
{
    public function testConstruct(): PDO
    {
        $this->assertContains('bolt', PDO::getAvailableDrivers());

        $pdo = new PDO(
            'bolt:host=' . (getenv('GDB_HOST') ? getenv('GDB_HOST') : '127.0.0.1')
            . ';port=' . (getenv('GDB_PORT') ? getenv('GDB_PORT') : 7687)
            . ';appname=pdo-bolt',
            getenv('GDB_USERNAME'),
            getenv('GDB_PASSWORD'),
            ['protocol_versions' => [getenv('BOLT_VERSION')]]
        );
        $this->assertInstanceOf(PDO::class, $pdo);
        return $pdo;
    }

    /**
     * @depends testConstruct
     */
    public function testTransaction(PDO $pdo): void
    {
        $this->assertTrue($pdo->beginTransaction());
        $this->assertTrue($pdo->inTransaction());
        $this->assertTrue($pdo->commit());
        $this->assertFalse($pdo->inTransaction());

        $this->assertTrue($pdo->beginTransaction());
        $this->assertTrue($pdo->inTransaction());
        $this->assertTrue($pdo->rollBack());
        $this->assertFalse($pdo->inTransaction());
    }

    /**
     * @depends testConstruct
     */
    public function testLastInsertId(PDO $pdo): void
    {
        $this->expectException(PDOException::class);
        $pdo->lastInsertId();
    }

    /**
     * @depends testConstruct
     */
    public function testQuote(PDO $pdo): void
    {
        $this->assertEquals("abc \' def \\\ ", $pdo->quote("abc ' def \ "));
    }

    /**
     * @depends testConstruct
     */
    public function testPrepare(PDO $pdo): void
    {
        $stmt = $pdo->prepare('RETURN 1 as num');
        $this->assertInstanceOf(BoltStatement::class, $stmt);
    }

    /**
     * @depends testConstruct
     */
    public function testExec(PDO $pdo): void
    {
        $this->assertTrue($pdo->beginTransaction());
        $result = $pdo->exec('CREATE (:Test { i: 123 })');
        $this->assertEquals(1, $result);
        $this->assertTrue($pdo->rollBack());
    }

    /**
     * @depends testConstruct
     */
    public function testQuery(PDO $pdo): void
    {
        $stmt = $pdo->query('RETURN 1 as num', PDO::FETCH_ASSOC);
        $this->assertInstanceOf(BoltStatement::class, $stmt);
        foreach ($stmt as $row) {
            $this->assertEquals(['num' => 1], $row);
        }
    }

    /**
     * @depends testConstruct
     */
    public function testAttribute(PDO $pdo): void
    {
        $pdo->setAttribute(PDO::ATTR_TIMEOUT, 3);
        $this->assertEquals(3, $pdo->getAttribute(PDO::ATTR_TIMEOUT));
    }

    /**
     * @depends testConstruct
     */
    public function testError(PDO $pdo): void
    {
        //exception
        try {
            $pdo->lastInsertId();
            $this->markTestIncomplete('PDOException was not thrown');
        } catch (PDOException $e) {
            $this->assertEquals(\pdo_bolt\drivers\bolt\Driver::ERR_FETCH, $pdo->errorCode());
            $this->assertNotEmpty($pdo->errorInfo()[2]);
        }

        //warning
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);
        set_error_handler(function (int     $errno,
                                    string  $errstr,
                                    ?string $errfile = null,
                                    ?int    $errline = null,
                                    ?array  $errcontext = null): bool {
            $this->assertEquals('CQLSTATE[06000] Database does not support last inserted id', $errstr);
            return $errstr === 'CQLSTATE[06000] Database does not support last inserted id';
        }, E_USER_WARNING);
        $pdo->lastInsertId();
        set_error_handler(null, E_USER_WARNING);

        //silent
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
        $pdo->lastInsertId();
        $this->assertTrue(true);

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * @depends testConstruct
     */
    public function testReset(PDO $pdo): void
    {
        //failure recovery
        try {
            $pdo->query('This is not CQL');
        } catch (PDOException) {
            if ($pdo->errorCode() === \pdo_bolt\drivers\bolt\Driver::ERR_MESSAGE_FAILURE) {
                $this->assertTrue($pdo->reset());
            } else {
                $this->markTestIncomplete('Not received expected failure');
            }
        }

        //failure and ignored recovery
        try {
            $pdo->query('This is not CQL');
        } catch (PDOException) {
            if ($pdo->errorCode() === \pdo_bolt\drivers\bolt\Driver::ERR_MESSAGE_FAILURE) {
                try {
                    $pdo->query('RETURN 1 as num');
                } catch (PDOException) {
                    if ($pdo->errorCode() === \pdo_bolt\drivers\bolt\Driver::ERR_MESSAGE_IGNORED) {
                        $this->assertTrue($pdo->reset());
                    } else {
                        $this->markTestIncomplete('Not received expected ignored message.');
                    }
                }
            } else {
                $this->markTestIncomplete('Not received expected failure message.');
            }
        }

        //test recovered state
        $stmt = $pdo->query('RETURN 1 as num');
        $this->assertInstanceOf(BoltStatement::class, $stmt);
    }
}

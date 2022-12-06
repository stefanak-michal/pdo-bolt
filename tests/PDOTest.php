<?php

namespace pdo_bolt\tests;

use pdo_bolt\PDO;
use PDOException;

/**
 * Class PDOTest
 * @author Michal Stefanak
 * @link https://github.com/stefanak-michal/pdo-bolt
 */
class PDOTest extends \PHPUnit\Framework\TestCase
{
    //todo all three variants for dsn in construct
    //todo add test construct with ssl
    //todo each method
    //todo each FETCH_MODE
    //todo each PARAM_TYPE
    //todo github action with yii framework ?

    public function testConstruct(): PDO
    {
        $this->assertContains('bolt', PDO::getAvailableDrivers());

        $pdo = new PDO(
            'bolt:host=' . ($GLOBALS['NEO_HOST'] ?? '127.0.0.1') . ';port=' . ($GLOBALS['NEO_PORT'] ?? 7687) . ';appname=pdo-bolt',
            $GLOBALS['NEO_USER'] ?? 'neo4j',
            $GLOBALS['NEO_PASS'] ?? 'neo4j'
        );
        $this->assertInstanceOf(PDO::class, $pdo);
        return $pdo;
    }

    /**
     * @depends testConstruct
     */
    public function testTransaction(PDO $pdo)
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
    public function testLastInsertId(PDO $pdo)
    {
        $this->expectException(PDOException::class);
        $pdo->lastInsertId();
    }

    /**
     * @depends testConstruct
     */
    public function testQuote(PDO $pdo)
    {
        $this->assertEquals("abc \' def \\\ ", $pdo->quote("abc ' def \ "));
    }

    //prepare
    //exec
    //query


    /**
     * @depends testConstruct
     */
    public function testAttribute(PDO $pdo)
    {
        $pdo->setAttribute(PDO::ATTR_TIMEOUT, 3);
        $this->assertEquals(3, $pdo->getAttribute(PDO::ATTR_TIMEOUT));
    }

    /**
     * @depends testConstruct
     */
    public function testError(PDO $pdo)
    {
        //exception
        try {
            $pdo->lastInsertId();
            $this->markTestIncomplete('PDOException was not thrown');
        } catch (PDOException $e) {
            $this->assertEquals(\pdo_bolt\drivers\bolt\BoltDriver::ERR_FETCH, $pdo->errorCode());
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

}

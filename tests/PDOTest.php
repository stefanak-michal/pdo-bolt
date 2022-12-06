<?php

namespace pdo_bolt\tests;

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
    //todo ERR_MODE

    public function testConstruct(): \pdo_bolt\PDO
    {
        $this->assertContains('bolt', \pdo_bolt\PDO::getAvailableDrivers());

        $pdo = new \pdo_bolt\PDO(
            'bolt:host=' . ($GLOBALS['NEO_HOST'] ?? '127.0.0.1') . ';port=' . ($GLOBALS['NEO_PORT'] ?? 7687) . ';appname=pdo-bolt',
            $GLOBALS['NEO_USER'] ?? 'neo4j',
            $GLOBALS['NEO_PASS'] ?? 'neo4j'
        );
        $this->assertInstanceOf(\pdo_bolt\PDO::class, $pdo);
        return $pdo;
    }

    /**
     * @depends testConstruct
     */
    public function testTransaction(\pdo_bolt\PDO $pdo)
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
    public function testLastInsertId(\pdo_bolt\PDO $pdo)
    {
        $this->expectException(\PDOException::class);
        $pdo->lastInsertId();
    }

    //quote
    //prepare
    //exec
    //query
    //getAttribute
    //setAttribute
    //errorCode
    //errorInfo

}

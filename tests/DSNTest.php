<?php

namespace pdo_bolt\tests;

use pdo_bolt\PDO;

/**
 * Class DSNTest
 * @author Michal Stefanak
 * @link https://github.com/stefanak-michal/pdo-bolt
 */
class DSNTest extends \PHPUnit\Framework\TestCase
{
    public function testSSL()
    {
        $pdo = new PDO(
            'bolt:host=demo.neo4jlabs.com;port=7687;appname=pdo-bolt',
            'movies',
            'movies',
            ['ssl' => [], 'protocol_versions' => [4.4]]
        );
        $this->assertInstanceOf(PDO::class, $pdo);
    }

    public function testUriFileDsn()
    {
        $pdo = new PDO(
            'uri:file://' . __DIR__ . DIRECTORY_SEPARATOR . 'dsn.bolt',
            'movies',
            'movies',
            ['ssl' => [], 'protocol_versions' => [4.4]]
        );
        $this->assertInstanceOf(PDO::class, $pdo);
    }

    public function testAliasDsn()
    {
        $pdo = new PDO(
            'mybolt',
            $GLOBALS['NEO_USER'] ?? 'neo4j',
            $GLOBALS['NEO_PASS'] ?? 'neo4j',
            ['protocol_versions' => [5]]
        );
        $this->assertInstanceOf(PDO::class, $pdo);
    }
}

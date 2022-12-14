<?php

namespace pdo_bolt\tests;

use Bolt\packstream\Bytes;
use Bolt\protocol\v1\structures\Date;
use pdo_bolt\drivers\bolt\Statement as BoltStatement;

/**
 * Class ParamTypeTest
 * @author Michal Stefanak
 * @link https://github.com/stefanak-michal/pdo-bolt
 */
class ParamTypeTest extends \PHPUnit\Framework\TestCase
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

    public function paramProvider(): \Generator
    {
        yield 'null' => [\PDO::PARAM_NULL, null];

        $n = rand();
        yield 'int ' . $n => [\PDO::PARAM_INT, $n];

        yield 'str' => [\PDO::PARAM_STR, 'Hello world'];
        yield 'str char' => [\PDO::PARAM_STR_CHAR, 'Hello world!'];
        yield 'str natl' => [\PDO::PARAM_STR_NATL, 'Hello world!'];

        $f = fopen(__DIR__ . DIRECTORY_SEPARATOR . 'dsn.bolt', 'rb');
        yield 'lob resource' => [\PDO::PARAM_LOB, $f, function (Bytes $p) {
            $this->assertInstanceOf(Bytes::class, $p);
            $this->assertEquals(file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'dsn.bolt'), (string)$p);
        }];
        yield 'lob string' => [\PDO::PARAM_LOB, 'Hello world!'];

        yield 'bool true' => [\PDO::PARAM_BOOL, true];
        yield 'bool false' => [\PDO::PARAM_BOOL, false];

        $n = rand() / rand();
        yield 'float ' . $n => [\pdo_bolt\PDO::BOLT_PARAM_FLOAT, $n];

        yield 'list array' => [\pdo_bolt\PDO::BOLT_PARAM_LIST, range(0, 10)];
        yield 'list generator' => [\pdo_bolt\PDO::BOLT_PARAM_LIST, new generators\ListGenerator(range(0, 9)), function (array $p) {
            $this->assertEquals(range(0, 9), $p);
        }];

        yield 'dictionary array' => [\pdo_bolt\PDO::BOLT_PARAM_DICTIONARY, ['a' => 123, 'b' => 'foo']];
        yield 'dictionary object' => [\pdo_bolt\PDO::BOLT_PARAM_DICTIONARY, (object)['a' => 123, 'b' => 'foo'], function (array $p) {
            $this->assertEquals(['a' => 123, 'b' => 'foo'], $p);
        }];
        yield 'dictionary generator' => [\pdo_bolt\PDO::BOLT_PARAM_DICTIONARY, new generators\DictionaryGenerator(['a' => 123, 'b' => 'foo']), function (array $p) {
            $this->assertEquals(['a' => 123, 'b' => 'foo'], $p);
        }];

        $bytes = new Bytes();
        for ($i = 0; $i < 10; $i++) {
            $bytes[$i] = pack('H', rand(0, 255));
        }
        yield 'bytes' => [\pdo_bolt\PDO::BOLT_PARAM_BYTES, $bytes];

        yield 'structure (date)' => [\pdo_bolt\PDO::BOLT_PARAM_STRUCTURE, new Date(floor(time() / 60 / 60 / 24))];
    }

    /**
     * @dataProvider paramProvider
     */
    public function testParam(int $param, mixed $value, ?callable $callback = null)
    {
        $stmt = self::$pdo->prepare('RETURN $p AS p');
        $this->assertInstanceOf(BoltStatement::class, $stmt);
        $stmt->bindValue('p', $value, $param);
        $this->assertTrue($stmt->execute());
        $stmt->setFetchMode(\PDO::FETCH_ASSOC);
        foreach ($stmt as $row) {
            if (is_null($callback)) {
                $this->assertEquals($value, $row['p']);
            } else {
                $callback($row['p']);
            }
        }
    }
}

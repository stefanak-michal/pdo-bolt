<?php

namespace pdo_bolt;

use pdo_bolt\drivers\IDriver;
use PDOStatement;
use PDOException;

/**
 * Class PDO
 * @author Michal Stefanak
 * @link https://github.com/stefanak-michal/pdo-bolt
 */
class PDO extends \PDO
{
    public const BOLT_PARAM_FLOAT = 8000;
    public const BOLT_PARAM_LIST = 8001;
    public const BOLT_PARAM_DICTIONARY = 8002;
    public const BOLT_PARAM_STRUCTURE = 8003;
    public const BOLT_PARAM_BYTES = 8004;

    private ?IDriver $driver;

    /**
     * @inheritDoc
     * @param string $dsn <pre>
     * Bolt driver supports these elements for DSN:
     * <b>host</b> - The hostname on which the database server resides.
     * <b>port</b> - The port number where the database server is listening.
     * <b>dbname</b> - The name of the database.
     * <b>appname</b> - The application name (used as UserAgent).
     * </pre>
     * @param array|null $options <pre>
     * Bolt driver supports these options:
     * (string) <b>auth</b> - specify authentification method (see \Bolt\helpers\Auth).
     *      If username is empty `none` is used.
     *      If username and password are set `basic` is used.
     *      For other methods username is used as token.
     * (array) <b>ssl</b> - enable encrypted communication and set ssl context options (see <a href="https://www.php.net/manual/en/context.ssl.php">php.net</a>)
     * (array) <b>protocol_versions</b> - specify requested bolt versions (see \Bolt\Bolt::setProtocolVersions())
     * </pre>
     */
    public function __construct(string $dsn, ?string $username = null, ?string $password = null, ?array $options = null)
    {
        //alias
        if (!str_contains($dsn, ':')) {
            $dsn = get_cfg_var('pdo.dsn.' . $dsn);
            if (!$dsn) {
                throw new PDOException('Argument #1 ($dsn) must be a valid data source name');
            }
        }

        list($scheme, $rest) = explode(':', $dsn, 2);

        //uri
        if ($scheme === 'uri') {
            $dsn = file_get_contents($rest);
            if (!$rest) {
                throw new PDOException('Argument #1 ($dsn) must be a valid data source name');
            } else {
                $dsn = trim($dsn);
            }
            $scheme = explode(':', $dsn, 2)[0];
        }

        //bolt
        if ($scheme === 'bolt') {
            $this->driver = new drivers\bolt\Driver($dsn, $username, $password, $options);
            return;
        }

        //origin
        parent::__construct($dsn, $username, $password, $options);
    }

    private function __invokeDriverMethod(string $method, ...$args): mixed
    {
        if (!is_null($this->driver) && method_exists($this->driver, $method)) {
            return $this->driver->{$method}(...$args);
        }
        return parent::{$method}(...$args);
    }

    public function beginTransaction(): bool
    {
        return $this->__invokeDriverMethod(__FUNCTION__);
    }

    public function commit(): bool
    {
        return $this->__invokeDriverMethod(__FUNCTION__);
    }

    public function rollBack(): bool
    {
        return $this->__invokeDriverMethod(__FUNCTION__);
    }

    public function inTransaction(): bool
    {
        return $this->__invokeDriverMethod(__FUNCTION__);
    }

    #[\ReturnTypeWillChange]
    public function lastInsertId(?string $name = null): string|bool
    {
        return $this->__invokeDriverMethod(__FUNCTION__, $name);
    }

    #[\ReturnTypeWillChange]
    public function quote(string $string, int $type = self::PARAM_STR): string|bool
    {
        return $this->__invokeDriverMethod(__FUNCTION__, $string, $type);
    }

    #[\ReturnTypeWillChange]
    public function prepare(string $query, array $options = []): PDOStatement|bool
    {
        return $this->__invokeDriverMethod(__FUNCTION__, $query, $options);
    }

    #[\ReturnTypeWillChange]
    public function exec(string $statement): int|bool
    {
        return $this->__invokeDriverMethod(__FUNCTION__, $statement);
    }

    #[\ReturnTypeWillChange]
    public function query($statement, $mode = self::ATTR_DEFAULT_FETCH_MODE, ...$fetch_mode_args): PDOStatement|bool
    {
        return $this->__invokeDriverMethod(__FUNCTION__, $statement, $mode, ...$fetch_mode_args);
    }

    public static function getAvailableDrivers(): array
    {
        return array_merge(
            parent::getAvailableDrivers(),
            ['bolt']
        );
    }

    public function getAttribute(int $attribute): mixed
    {
        return $this->__invokeDriverMethod(__FUNCTION__, $attribute);
    }

    public function setAttribute(int $attribute, mixed $value): bool
    {
        return $this->__invokeDriverMethod(__FUNCTION__, $attribute, $value);
    }

    public function errorCode(): ?string
    {
        return $this->__invokeDriverMethod(__FUNCTION__);
    }

    public function errorInfo(): array
    {
        return $this->__invokeDriverMethod(__FUNCTION__);
    }

}

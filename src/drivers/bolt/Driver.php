<?php

namespace pdo_bolt\drivers\bolt;

use Bolt\connection\IConnection;
use Bolt\connection\Socket;
use Bolt\connection\StreamSocket;
use Bolt\error\BoltException;
use Bolt\helpers\Auth;
use Bolt\protocol\AProtocol;
use Bolt\protocol\Response;
use pdo_bolt\drivers\IDriver;
use PDO;

/**
 * Class Bolt Driver
 * @author Michal Stefanak
 * @link https://github.com/stefanak-michal/pdo-bolt
 */
class Driver implements IDriver
{
    public const ERR_AUTH = '01000';
    public const ERR_AUTH_LOGIN = '01001';
    public const ERR_AUTH_TYPE = '01002';
    public const ERR_MESSAGE = '02000';
    public const ERR_MESSAGE_FAILURE = '02001';
    public const ERR_MESSAGE_IGNORED = '02002';
    public const ERR_TRANSACTION = '03000';
    public const ERR_TRANSACTION_NOT_SUPPORTED = '03001';
    public const ERR_ATTRIBUTE = '04000';
    public const ERR_ATTRIBUTE_NOT_SUPPORTED = '04001';
    public const ERR_PARAMETER = '05000';
    public const ERR_PARAMETER_TYPE_NOT_SUPPORTED = '05001';
    public const ERR_PARAMETER_PLACEHOLDER_MISMATCH = '05002';
    public const ERR_FETCH = '06000';
    public const ERR_FETCH_COLUMN_NOT_DEFINED = '06001';
    public const ERR_FETCH_OBJECT = '06002';
    public const ERR_BOLT = '07000';

    public AProtocol $protocol;
    private IConnection $connection;
    private bool $inTransaction = false;
    private string $dbname;
    public Bookmarks $bookmarks;

    private string $errorCode = '00000';
    private array $failureContent = [];

    private array $attributes = [
        PDO::ATTR_TIMEOUT => 15,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_CLIENT_VERSION => null,
        PDO::ATTR_DRIVER_NAME => null,
        PDO::ATTR_SERVER_INFO => null,
        PDO::ATTR_STATEMENT_CLASS => Statement::class,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_BOTH
    ];

    public function __construct(string $dsn, ?string $username = null, ?string $password = null, ?array $options = null)
    {
        list($scheme, $rest) = explode(':', $dsn, 2);
        $dsnArray = [];
        foreach (explode(';', $rest) as $entry) {
            $arr = explode('=', $entry, 2);
            $dsnArray[$arr[0]] = $arr[1];
        }

        if (is_null($options)) {
            $options = [];
        }

        if (array_key_exists('appname', $dsnArray)) {
            Auth::$defaultUserAgent = $dsnArray['appname'];
        }

        if (empty($username)) {
            $auth = Auth::none();
        } elseif (!empty($password)) {
            $auth = Auth::basic($username, $password);
        } elseif (isset($options['auth']) && method_exists(Auth::class, $options['auth'])) {
            $auth = Auth::{$options['auth']}($username);
        } else {
            $this->handleError(self::ERR_AUTH_TYPE, 'Authentication type not resolved by username, password and DSN auth.', errorMode: PDO::ERRMODE_EXCEPTION);
            return;
        }

        $timeout = $this->getAttribute(PDO::ATTR_TIMEOUT);

        if (array_key_exists('ssl', $options)) {
            $this->connection = new StreamSocket($dsnArray['host'] ?? '127.0.0.1', $dsnArray['port'] ?? 7687, $timeout);
            if (!isset($options['ssl']['verify_peer'])) {
                $options['ssl']['verify_peer'] = true;
            }
            $this->connection->setSslContextOptions($options['ssl']);
        } else {
            $this->connection = new Socket($dsnArray['host'] ?? '127.0.0.1', $dsnArray['port'] ?? 7687, $timeout);
        }

        $this->attributes[PDO::ATTR_DRIVER_NAME] = $scheme;

        $bolt = new \Bolt\Bolt($this->connection);
        if (array_key_exists('protocol_versions', $options)) {
            $bolt->setProtocolVersions(...$options['protocol_versions']);
        }

        try {
            $this->protocol = $bolt->build();

            if (method_exists($this->protocol, 'logon')) {
                $response = $this->protocol->hello();
                if ($response->getSignature() === $response::SIGNATURE_FAILURE) {
                    $this->handleError(self::ERR_AUTH_LOGIN, $response->getContent(), errorMode: PDO::ERRMODE_EXCEPTION);
                }
                unset($auth['user_agent']);
                $response = $this->protocol->logon($auth);
            } elseif (method_exists($this->protocol, 'hello')) {
                $response = $this->protocol->hello($auth);
            } elseif (method_exists($this->protocol, 'init')) {
                $userAgent = $auth['user_agent'];
                unset($auth['user_agent']);
                $response = $this->protocol->init($userAgent, $auth);
            } else {
                $this->handleError(self::ERR_AUTH, ['message' => 'Low level bolt library is missing init/hello message.'], errorMode: PDO::ERRMODE_EXCEPTION);
                return;
            }

            if ($response->getSignature() === $response::SIGNATURE_FAILURE) {
                $this->handleError(self::ERR_AUTH_LOGIN, $response->getContent(), errorMode: PDO::ERRMODE_EXCEPTION);
            }

            $this->attributes[PDO::ATTR_SERVER_INFO] = $response->getContent()['server'] ?? '';
            $this->attributes[PDO::ATTR_CLIENT_VERSION] = $this->protocol->getVersion();

            if (array_key_exists('dbname', $dsnArray)) {
                $this->dbname = $dsnArray['dbname'];
            }
        } catch (BoltException $e) {
            $this->handleError(self::ERR_AUTH, $e->getMessage(), errorMode: PDO::ERRMODE_EXCEPTION);
        }

        $this->bookmarks = new Bookmarks();
    }

    public function beginTransaction(): bool
    {
        try {
            if (method_exists($this->protocol, 'begin')) {
                $response = $this->protocol->begin($this->getExtraDictionary())->getResponse();
            } else {
                $this->handleError(self::ERR_TRANSACTION_NOT_SUPPORTED);
                return false;
            }
            if ($this->checkResponse($response)) {
                $this->inTransaction = true;
                return true;
            }
        } catch (BoltException $e) {
            $this->handleError(self::ERR_BOLT, 'Underlying Bolt library error occurred', $e);
        }

        return false;
    }

    public function commit(): bool
    {
        try {
            if (method_exists($this->protocol, 'commit')) {
                $response = $this->protocol->commit()->getResponse();
            } else {
                $this->handleError(self::ERR_TRANSACTION_NOT_SUPPORTED);
                return false;
            }
            if ($this->checkResponse($response)) {
                $this->bookmarks->add($response->getContent()['bookmark'] ?? '');
                $this->inTransaction = false;
                return true;
            }
        } catch (BoltException $e) {
            $this->handleError(self::ERR_BOLT, 'Underlying Bolt library error occurred', $e);
        }

        return false;
    }

    public function rollBack(): bool
    {
        try {
            if (method_exists($this->protocol, 'rollback')) {
                $response = $this->protocol->rollback()->getResponse();
            } else {
                $this->handleError(self::ERR_TRANSACTION_NOT_SUPPORTED);
                return false;
            }
            if ($this->checkResponse($response)) {
                $this->inTransaction = false;
                return true;
            }
        } catch (BoltException $e) {
            $this->handleError(self::ERR_BOLT, 'Underlying Bolt library error occurred', $e);
        }

        return false;
    }

    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    public function lastInsertId(?string $name = null): bool
    {
        $this->handleError(self::ERR_FETCH, 'Database does not support last inserted id');
        return false;
    }

    public function quote(string $string, int $type = PDO::PARAM_STR): string|bool
    {
        if ($type === PDO::PARAM_STR) {
            return addslashes(sprintf('%s', $string));
        } else {
            return false;
        }
    }

    public function prepare(string $query, array $options = []): \PDOStatement
    {
        $cls = $this->getAttribute(PDO::ATTR_STATEMENT_CLASS);
        return new $cls($this, $query);
    }

    public function exec(string $statement): int|bool
    {
        try {
            if (method_exists($this->protocol, 'run')) {
                /** @var Response $runResponse */
                $runResponse = $this->protocol
                    ->run($statement, [], $this->getExtraDictionary())
                    ->getResponse();

                if (!$this->checkResponse($runResponse)) {
                    return false;
                }
            } else {
                $this->handleError(self::ERR_BOLT, 'Low level bolt library is missing RUN message.');
                return false;
            }

            if (method_exists($this->protocol, 'discard')) {
                $this->protocol->discard(['qid' => $runResponse->getContent()['qid'] ?? -1]);
            } elseif (method_exists($this->protocol, 'discardAll')) {
                $this->protocol->discardAll();
            } else {
                $this->handleError(self::ERR_BOLT, 'Low level bolt library is missing DISCARD message.');
                return false;
            }

            $iterator = $this->protocol->getResponses();
        } catch (BoltException $e) {
            $this->handleError(self::ERR_BOLT, 'Underlying Bolt library error occurred', $e);
            return false;
        }

        $output = false;
        /** @var Response $response */
        foreach ($iterator as $response) {
            if ($this->checkResponse($response) && $response->getMessage() === $response::MESSAGE_DISCARD) {
                $this->bookmarks->add($response->getContent()['bookmark'] ?? '');
                $output = ($response->getContent()['stats']['nodes-created'] ?? 0)
                    + ($response->getContent()['stats']['nodes-deleted'] ?? 0)
                    + ($response->getContent()['stats']['relationships-created'] ?? 0)
                    + ($response->getContent()['stats']['relationship-deleted'] ?? 0);
            }
        }
        return $output;
    }

    public function query(string $statement, int $mode = PDO::ATTR_DEFAULT_FETCH_MODE, ...$fetch_mode_args): \PDOStatement|bool
    {
        $cls = $this->getAttribute(PDO::ATTR_STATEMENT_CLASS);
        $stmt = new $cls($this, $statement);
        $result = $stmt->execute();
        $stmt->setFetchMode($mode, ...$fetch_mode_args);
        return $result ? $stmt : false;
    }

    public function getAttribute(int $attribute): mixed
    {
        return $this->attributes[$attribute] ?? null;
    }

    public function setAttribute(int $attribute, mixed $value): bool
    {
        if (!array_key_exists($attribute, $this->attributes)) {
            $this->handleError(self::ERR_ATTRIBUTE_NOT_SUPPORTED);
            return false;
        }

        $this->attributes[$attribute] = $value;

        switch ($attribute) {
            case PDO::ATTR_TIMEOUT:
                $this->connection->setTimeout(floatval($value));
                break;
        }

        return true;
    }

    /**
     * Reset bolt connection to initial state
     */
    public function reset(): bool
    {
        try {
            /** @var Response $response */
            $response = $this->protocol->reset()->getResponse();
            return $this->checkResponse($response);
        } catch (BoltException $e) {
            $this->handleError(self::ERR_BOLT, 'Underlying Bolt library error occurred', $e);
        }
        return false;
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }

    public function errorInfo(): array
    {
        return [
            $this->errorCode,
            $this->failureContent['code'] ?? '',
            $this->failureContent['message'] ?? ''
        ];
    }

    public function checkResponse(Response $response): bool
    {
        switch ($response->getSignature()) {
            case $response::SIGNATURE_SUCCESS:
                return true;
            case $response::SIGNATURE_FAILURE:
                $this->handleError(Driver::ERR_MESSAGE_FAILURE, $response->getContent());
                return false;
            case $response::SIGNATURE_IGNORED:
                $this->handleError(Driver::ERR_MESSAGE_IGNORED, ['code' => 'IGNORED', 'message' => 'Request has not been carried out.']);
                return false;
        }
        return false;
    }

    public function handleError(string $errorCode = PDO::ERR_NONE, array|string $failureContent = [], ?\Throwable $previous = null, ?int $errorMode = null): void
    {
        $this->errorCode = $errorCode;
        $this->failureContent = is_string($failureContent) ? ['message' => $failureContent] : $failureContent;

        if (!str_starts_with($errorCode, '00')) {
            if (is_null($errorMode)) {
                $errorMode = $this->getAttribute(PDO::ATTR_ERRMODE);
            }

            $message = 'CQLSTATE[' . $errorCode . '] ' . ($this->failureContent['message'] ?? '');
            if (!empty($this->failureContent['code'])) {
                $message .= ' (' . $this->failureContent['code'] . ')';
            }
            if ($errorMode === PDO::ERRMODE_EXCEPTION) {
                $e = new \PDOException($message, intval($errorCode), $previous);
                $e->errorInfo = $this->errorInfo();
                throw $e;
            } elseif ($errorMode === PDO::ERRMODE_WARNING) {
                trigger_error($message, E_USER_WARNING);
            }
        }
    }

    public function getExtraDictionary(): array
    {
        $output = [];
        if (!empty($this->dbname)) {
            $output['db'] = $this->dbname;
        }
        if ($this->bookmarks->hasAny()) {
            $output['bookmarks'] = $this->bookmarks->get();
        }
        return $output;
    }
}

<?php

namespace pdo_bolt\drivers\bolt;

use Bolt\error\BoltException;
use Bolt\protocol\Response;
use Iterator;
use PDO;
use PDOStatement;

/**
 * Class Bolt Statement
 * @author Michal Stefanak
 * @link https://github.com/stefanak-michal/pdo-bolt
 */
class Statement extends PDOStatement
{
    private array $boundColumns = [];
    private array $parsedQueryString = [];
    private int $placeholdersCnt = 0;
    private array $columns = [];
    private array $boundParameters = [];
    private ?Records $records;
    private int $qid = -1;

    public function __construct(private Driver $driver, string $query)
    {
        $this->queryString = $query;
        $this->parseQueryString();
    }

    private function parseQueryString(): void
    {
        preg_match_all('/\$[a-z][a-z0-9]*|\?{1,2}/i', $this->queryString, $matches, PREG_OFFSET_CAPTURE);
        $this->placeholdersCnt = count($matches[0]);
        if ($this->placeholdersCnt) {
            $n = array_count_values(array_map(function (string $item) {
                return $item[0];
            }, array_column($matches[0], 0)));
            if (count($n) > 1) {
                $this->driver->handleError(Driver::ERR_PARAMETER_PLACEHOLDER_MISMATCH, 'Different placeholders in query at once is not supported.');
                return;
            }
        }

        $withoutParams = preg_split('/\$[a-z][a-z0-9]*|\?{1,2}/i', $this->queryString);

        $parts = [];
        $index = 1;
        foreach ($withoutParams as $i => $str) {
            $param = $matches[0][$i][0] ?? '';
            if ($param === '??') {
                $str .= '?';
            }
            if (strlen($str) > 0) {
                $parts[] = $str;
            }
            if ($param === '?') {
                $parts[] = ['placeholder' => $index];
                $index++;
            } elseif (str_starts_with($param, '$')) {
                $parts[] = ['placeholder' => ltrim($param, '$')];
            }
        }

        $this->parsedQueryString = $parts;
    }

    public function bindColumn(
        string|int $column,
        mixed      &$var,
        int        $type = PDO::PARAM_STR,
        ?int       $maxLength = 0,
        mixed      $driverOptions = null
    ): bool
    {
        $this->boundColumns[$column] = [
            'var' => &$var,
            'type' => $type,
            'maxLength' => $maxLength,
            'driverOptions' => $driverOptions
        ];
        return true;
    }

    public function bindParam(
        int|string $param,
        mixed      &$var,
        int        $type = PDO::PARAM_STR,
        int        $maxLength = null,
        mixed      $driverOptions = null
    ): bool
    {
        if (is_string($param)) {
            $param = ltrim($param, '$');
        }
        $this->boundParameters[$param] = [
            'var' => &$var,
            'type' => $type,
            'maxLength' => $maxLength,
            'driverOptions' => $driverOptions
        ];
        return true;
    }

    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        if (is_string($param)) {
            $param = ltrim($param, '$');
        }
        $this->boundParameters[$param] = [
            'var' => $value,
            'type' => $type
        ];
        return true;
    }

    public function closeCursor(): bool
    {
        try {
            /** @var Response $response */
            if (method_exists($this->driver->protocol, 'discard')) {
                $response = $this->driver->protocol->discard(['qid' => $this->qid])->getResponse();
            } elseif (method_exists($this->driver->protocol, 'discardAll')) {
                $response = $this->driver->protocol->discardAll()->getResponse();
            } else {
                $this->driver->handleError(Driver::ERR_BOLT, 'Low level bolt library is missing DISCARD message.');
                return false;
            }
            if ($this->driver->checkResponse($response)) {
                $this->driver->bookmarks->add($response->getContent()['bookmark'] ?? '');
                return true;
            }
        } catch (BoltException $e) {
            $this->driver->handleError(Driver::ERR_BOLT, 'Underlying Bolt library error occurred', $e);
        }
        return false;
    }

    public function columnCount(): int
    {
        return count($this->columns);
    }

    public function rowCount(): int
    {
        //not supported .. we don't know in advance how many records is waiting for pull
        return -1;
    }

    public function debugDumpParams(): ?bool
    {
        echo 'CQL: [' . strlen($this->queryString) . '] ' . $this->queryString . PHP_EOL
            . 'Params: ' . count($this->boundParameters) . PHP_EOL;

        foreach ($this->boundParameters as $key => $parameter) {
            echo 'Key: ';
            if (is_string($key)) {
                echo 'Name: [' . strlen($key) + 1 . '] $' . $key . PHP_EOL
                    . 'paramno=-1' . PHP_EOL
                    . 'name=[' . strlen($key) + 1 . '] "$' . $key . '"' . PHP_EOL;
            } else {
                echo 'Position #' . $key . PHP_EOL
                    . 'paramno=' . $key . PHP_EOL
                    . 'name=[0] ""' . PHP_EOL;
            }
            echo 'is_param=1' . PHP_EOL
                . 'param_type=' . $parameter['type'] . PHP_EOL;
        }

        return null;
    }

    public function execute(?array $params = null): bool
    {
        if (is_array($params)) {
            $this->boundParameters = [];
            foreach ($params as $key => $value) {
                $this->bindValue($key, $value);
            }
        }
        if (count($this->boundParameters) !== $this->placeholdersCnt) {
            $this->driver->handleError(Driver::ERR_PARAMETER, 'Amount of bound parameters is not equal to amount of placeholders in query.');
            return false;
        }

        $parameters = [];
        $queryString = '';
        foreach ($this->parsedQueryString as $i => $part) {
            if (is_array($part)) {
                if (array_key_exists($part['placeholder'], $this->boundParameters)) {
                    $key = $part['placeholder'];
                    if (is_int($key)) {
                        $key = 'a' . $i;
                    }

                    $parameters[$key] = $this->sanitizeParameter(
                        $this->boundParameters[$part['placeholder']]['var'],
                        $this->boundParameters[$part['placeholder']]['type']
                    );
                    $queryString .= '$' . $key;
                } else {
                    $this->driver->handleError(Driver::ERR_PARAMETER, 'Placeholder from query is not defined in bound parameters.');
                    return false;
                }
            } else {
                $queryString .= $part;
            }
        }

        try {
            if (method_exists($this->driver->protocol, 'run')) {
                /** @var Response $response */
                $response = $this->driver->protocol
                    ->run($queryString, $parameters, $this->driver->getExtraDictionary())
                    ->getResponse();
                if ($this->driver->checkResponse($response)) {
                    $this->columns = $response->getContent()['fields'] ?? [];
                    $this->qid = $response->getContent()['qid'] ?? -1;
                    $this->records = new Records($this->driver, $this->columns, $this->boundColumns, $this->qid);
                    return true;
                }
            } else {
                $this->driver->handleError(Driver::ERR_BOLT, 'Low level bolt library is missing RUN message.');
            }
        } catch (BoltException $e) {
            $this->driver->handleError(Driver::ERR_BOLT, 'Underlying Bolt library error occurred', $e);
        }

        return false;
    }

    private function sanitizeParameter(mixed $value, int $type): mixed
    {
        switch ($type) {
            //default types
            case PDO::PARAM_NULL:
                return null;
            case PDO::PARAM_INT:
                return intval($value);
            case PDO::PARAM_STR:
            case PDO::PARAM_STR_CHAR:
            case PDO::PARAM_STR_NATL:
                return addslashes(sprintf('%s', $value));
            case PDO::PARAM_LOB:
                if (is_resource($value)) {
                    $value = mb_str_split(stream_get_contents($value), 1, '8bit');
                } elseif (is_string($value)) {
                    $value = str_split($value, 1);
                } else {
                    $this->driver->handleError(Driver::ERR_PARAMETER, ['message' => 'Parameter of type LOB expected string or resource.', 'code' => $type]);
                    break;
                }
                return (new \Bolt\packstream\Bytes($value));
            case PDO::PARAM_BOOL:
                return boolval($value);

            //Bolt types
            case \pdo_bolt\PDO::BOLT_PARAM_FLOAT:
                return floatval($value);
            case \pdo_bolt\PDO::BOLT_PARAM_LIST:
                if (is_array($value) || $value instanceof \Bolt\packstream\IPackListGenerator) {
                    return $value;
                }
                $this->driver->handleError(Driver::ERR_PARAMETER, ['message' => 'Bolt list parameter has to be array or IPackListGenerator instance.', 'code' => $type]);
                break;
            case \pdo_bolt\PDO::BOLT_PARAM_DICTIONARY:
                if (gettype($value) === 'object') {
                    return $value;
                } elseif (is_array($value)) {
                    return (object)$value;
                }
                $this->driver->handleError(Driver::ERR_PARAMETER, ['message' => 'Bolt dictionary parameter has to be object, array or IPackDictionaryGenerator instance.', 'code' => $type]);
                break;
            case \pdo_bolt\PDO::BOLT_PARAM_STRUCTURE:
                if ($value instanceof \Bolt\protocol\IStructure) {
                    return $value;
                }
                $this->driver->handleError(Driver::ERR_PARAMETER, ['message' => 'Bolt structure parameter has to be IStructure instance.', 'code' => $type]);
                break;
            case \pdo_bolt\PDO::BOLT_PARAM_BYTES:
                if ($value instanceof \Bolt\packstream\Bytes) {
                    return $value;
                }
                $this->driver->handleError(Driver::ERR_PARAMETER, ['message' => 'Bolt bytes parameter has to be Bytes instance.', 'code' => $type]);
                break;
        }

        $this->driver->handleError(Driver::ERR_PARAMETER_TYPE_NOT_SUPPORTED);
        return false;
    }

    public function fetch(int $mode = PDO::FETCH_BOTH, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        $this->records->next();
        return $this->records->valid() ? $this->records->currentAs($mode) : false;
    }

    public function fetchAll(int $mode = PDO::FETCH_BOTH, mixed ...$args): array
    {
        $output = [];
        $this->records->rewind();
        while ($this->records->valid()) {
            $output[] = $this->records->currentAs($mode, ...$args);
            $this->records->next();
        }
        return $output;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $this->records->next();
        return $this->records->valid() ? $this->records->currentAs(PDO::FETCH_NUM)[$column] : false;
    }

    #[\ReturnTypeWillChange]
    public function fetchObject(?string $class = "stdClass", array $constructorArgs = []): object|bool
    {
        $this->records->next();
        return $this->records->valid() ? $this->records->currentAs(PDO::FETCH_CLASS, $class, $constructorArgs) : false;
    }

    public function setAttribute(int $attribute, mixed $value): bool
    {
        return $this->driver->setAttribute($attribute, $value);
    }

    public function getAttribute(int $name): mixed
    {
        return $this->driver->getAttribute($name);
    }

    #[\ReturnTypeWillChange]
    public function getColumnMeta(int $column): array|bool
    {
        if (array_key_exists($column, $this->columns)) {
            return [
                'native_type' => '',
                'flags' => [],
                'name' => $this->columns[$column],
                'len' => -1,
                'precision' => 0,
                'pdo_type' => -1
            ];
        }
        return false;
    }

    public function setFetchMode($mode, $className = null, ...$params): bool
    {
        if (is_null($this->records)) {
            return false;
        }
        $this->records->setFetchMode($mode, $className, ...$params);
        return true;
    }

    public function nextRowset(): bool
    {
        $this->records->next();
        return $this->records->valid();
    }

    public function getIterator(): Iterator
    {
        return $this->records;
    }

    public function errorCode(): string
    {
        return $this->driver->errorCode();
    }

    public function errorInfo(): array
    {
        return $this->driver->errorInfo();
    }
}

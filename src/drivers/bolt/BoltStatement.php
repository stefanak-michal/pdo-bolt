<?php

namespace pdo_bolt\drivers\bolt;

use Bolt\error\BoltException;
use Bolt\protocol\AProtocol;
use Bolt\protocol\Response;
use Iterator;
use PDO;
use PDOStatement;

/**
 * Class BoltStatement
 * @author Michal Stefanak
 * @link https://github.com/stefanak-michal/pdo-bolt
 */
class BoltStatement extends PDOStatement
{
    private array $boundColumns = [];
    private array $parsedQueryString = [];
    private int $placeholdersCnt = 0;
    private array $columns = [];
    private ?Response $record;
    private array $fetchMode = [];
    private array $boundParameters = [];
    private bool $hasMore = true;

    use ErrorTrait;

    public function __construct(private AProtocol $protocol, string $query, private array $attributes = [])
    {
        $this->queryString = $query;
        $this->parseQueryString();
    }

    // todo this class should replace placeholders in query only if ATTR_EMULATE_PREPARES is true ..otherwise you send parameters as RUN parameters and replace placeholders with cypher placeholder ($)
    // todo bookmarks

    private function parseQueryString(): void
    {
        preg_match_all('/\$[a-z][a-z0-9]*|\?{1,2}/i', $this->queryString, $matches, PREG_OFFSET_CAPTURE);
        $this->placeholdersCnt = count($matches[0]);
        if ($this->placeholdersCnt) {
            $n = array_count_values(array_map(function (string $item) {
                return $item[0];
            }, array_column($matches[0], 0)));
            if (count($n) > 1) {
                $this->handleError(BoltDriver::ERR_PARAMETER_PLACEHOLDER_MISMATCH, 'Different placeholders in query at once is not supported.');
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
        //todo not tested
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
            if (method_exists($this->protocol, 'discard')) {
                $response = $this->protocol->discard()->getResponse();
            } elseif (method_exists($this->protocol, 'discardAll')) {
                $response = $this->protocol->discardAll()->getResponse();
            } else {
                $this->handleError(BoltDriver::ERR_BOLT, 'Low level bolt library is missing DISCARD message.');
                return false;
            }
            return $this->checkResponse($response);
        } catch (BoltException $e) {
            $this->handleError(BoltDriver::ERR_BOLT, 'Underlying Bolt library error occurred', $e);
            return false;
        }
    }

    public function columnCount(): int
    {
        return count($this->columns);
    }

    public function rowCount(): int
    {
        //todo can i get this info somewhere?
        return -1;
    }

    public function debugDumpParams(): ?bool
    {
        //todo
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
            $this->handleError(BoltDriver::ERR_PARAMETER, 'Amount of bound parameters is not equal to amount of placeholders in query.');
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
                    $this->handleError(BoltDriver::ERR_PARAMETER, 'Placeholder from query is not defined in bound parameters.');
                    return false;
                }
            } else {
                $queryString .= $part;
            }
        }

        try {
            if (method_exists($this->protocol, 'run')) {
                /** @var Response $response */
                $response = $this->protocol
                    ->run($queryString, $parameters)
                    ->getResponse();
                if ($this->checkResponse($response)) {
                    $this->columns = $response->getContent()['fields'] ?? [];
                    return true;
                }
            } else {
                $this->handleError(BoltDriver::ERR_BOLT, 'Low level bolt library is missing RUN message.');
            }
        } catch (BoltException $e) {
            $this->handleError(BoltDriver::ERR_BOLT, 'Underlying Bolt library error occurred', $e);
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
            case PDO::PARAM_LOB: // todo https://www.php.net/manual/en/pdo.lobs.php
                return addslashes(sprintf('%s', $value));
            case PDO::PARAM_BOOL:
                return boolval($value);

            //Bolt types
            case \pdo_bolt\PDO::BOLT_PARAM_FLOAT:
                return floatval($value);
            case \pdo_bolt\PDO::BOLT_PARAM_LIST:
                if (is_array($value) || $value instanceof \Bolt\packstream\IPackListGenerator) {
                    return $value;
                }
                $this->handleError(BoltDriver::ERR_PARAMETER, ['message' => 'Bolt list parameter has to be array or IPackListGenerator instance.', 'code' => $type]);
                break;
            case \pdo_bolt\PDO::BOLT_PARAM_DICTIONARY:
                if (gettype($value) === 'object') {
                    return $value;
                } elseif (is_array($value)) {
                    return (object)$value;
                }
                $this->handleError(BoltDriver::ERR_PARAMETER, ['message' => 'Bolt dictionary parameter has to be object, array or IPackDictionaryGenerator instance.', 'code' => $type]);
                break;
            case \pdo_bolt\PDO::BOLT_PARAM_STRUCTURE:
                if ($value instanceof \Bolt\protocol\IStructure) {
                    return $value;
                }
                $this->handleError(BoltDriver::ERR_PARAMETER, ['message' => 'Bolt structure parameter has to be IStructure instance.', 'code' => $type]);
                break;
            case \pdo_bolt\PDO::BOLT_PARAM_BYTES:
                if ($value instanceof \Bolt\packstream\Bytes) {
                    return $value;
                }
                $this->handleError(BoltDriver::ERR_PARAMETER, ['message' => 'Bolt bytes parameter has to be Bytes instance.', 'code' => $type]);
                break;
        }

        $this->handleError(BoltDriver::ERR_PARAMETER_TYPE_NOT_SUPPORTED);
        return false;
    }

    public function fetch(int $mode = PDO::FETCH_BOTH, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        //todo
        if (!$this->nextRowset()) {
            return false;
        }

        switch ($mode) {
            case PDO::FETCH_ASSOC:
                return array_combine($this->columns, $this->record->getContent());
            case PDO::FETCH_BOTH:
                return $this->record->getContent() + array_combine($this->columns, $this->record->getContent());
            case PDO::FETCH_BOUND:
                //todo insert into binded columns
                return true;
            case PDO::FETCH_CLASS:
                break;
            case PDO::FETCH_INTO:
                break;
            case PDO::FETCH_LAZY:
                break;
            case PDO::FETCH_NAMED:
                break;
            case PDO::FETCH_NUM:
                return $this->record->getContent();
            case PDO::FETCH_OBJ:
                return (object)array_combine($this->columns, $this->record->getContent());
            case PDO::FETCH_PROPS_LATE:
                break;

        }

        return false;
    }

    public function fetchAll(int $mode = PDO::FETCH_BOTH, mixed ...$args): array
    {
        $output = [];
        while ($this->nextRowset()) {
            // todo
            $output[] = $this->record->getContent();
        }
        return $output;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        if ($this->nextRowset()) {
            return $this->record->getContent()[$column];
        }
        return false;
    }

    #[\ReturnTypeWillChange]
    public function fetchObject(?string $class = "stdClass", array $constructorArgs = []): object|bool
    {
        if ($this->nextRowset()) {
            try {
                $ref = new \ReflectionClass($class);
                $instance = $ref->newInstanceWithoutConstructor();

                $arr = array_combine($this->columns, $this->record->getContent());
                foreach ($ref->getProperties() as $property) {
                    $property->setValue($instance, $arr[$property->getName()]);
                }

                $constructor = $ref->getConstructor();
                if ($constructor instanceof \ReflectionMethod) {
                    $constructor->invokeArgs($instance, $constructorArgs);
                }

                return $instance;
            } catch (\ReflectionException $e) {
                $this->handleError(BoltDriver::ERR_BOLT, 'Underlying Bolt library error occurred', $e);
            }
        }
        return false;
    }

    public function setAttribute(int $attribute, mixed $value): bool
    {
        if (!array_key_exists($attribute, $this->attributes)) {
            $this->handleError(BoltDriver::ERR_ATTRIBUTE_NOT_SUPPORTED);
            return false;
        }

        $this->attributes[$attribute] = $value;
        return true;
    }

    public function getAttribute(int $name): mixed
    {
        return $this->attributes[$name] ?? null;
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
        //todo check if $mode is supported
        $this->fetchMode = [$mode, $className, $params];
        return true;
    }

    public function nextRowset(): bool
    {
        $this->record = null;

        if (!$this->hasMore) {
            return false;
        }

        try {
            if (method_exists($this->protocol, 'pull')) {
                $this->protocol->pull(['n' => 1]); //todo add qid
            } elseif (method_exists($this->protocol, 'pullAll')) {
                $this->protocol->pullAll();
            } else {
                $this->handleError(BoltDriver::ERR_BOLT, 'Low level bolt library is missing PULL message.');
                return false;
            }
            /** @var Response $response */
            foreach ($this->protocol->getResponses() as $response) {
                if ($response->getSignature() === $response::SIGNATURE_RECORD) {
                    $this->record = $response;
                } elseif ($this->checkResponse($response)) {
                    $this->hasMore = $response->getContent()['has_more'] ?? false;
                }
            }
        } catch (BoltException $e) {
            $this->handleError(BoltDriver::ERR_BOLT, 'Underlying Bolt library error occurred', $e);
        }

        return !is_null($this->record);
    }

    public function getIterator(): Iterator
    {
        return $this->protocol->getResponses();
    }
}

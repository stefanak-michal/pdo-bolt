<?php

namespace pdo_bolt\drivers\bolt;

use Bolt\error\BoltException;
use Bolt\protocol\Response;
use Iterator;
use PDO;

/**
 * Class Records
 * @author Michal Stefanak
 * @link https://github.com/stefanak-michal/pdo-bolt
 */
class Records implements Iterator
{
    private ?object $recordAsObject = null;
    private bool $hasMore = true;
    private array $fetchMode = [PDO::FETCH_BOTH];
    private array $cache = [];
    private int $key = -1;

    public function __construct(private Driver $driver, private array $columns, private array $boundColumns, private int $qid = -1)
    {
    }

    public function setFetchMode(int $mode, ?string $className = null, mixed ...$params)
    {
        $this->fetchMode = [$mode, $className, $params];
    }

    public function current(): mixed
    {
        if (array_key_exists($this->key, $this->cache)) {
            return $this->getAs(...$this->fetchMode);
        } elseif ($this->hasMore && $this->pullRecord()) {
            return $this->getAs(...$this->fetchMode);
        }
        return null;
    }

    public function currentAs(int $mode, mixed ...$args): mixed
    {
        if (array_key_exists($this->key, $this->cache)) {
            return $this->getAs($mode, ...$args);
        } elseif ($this->hasMore && $this->pullRecord()) {
            return $this->getAs($mode, ...$args);
        }
        return null;
    }

    public function next(): void
    {
        if ($this->hasMore) {
            $this->pullRecord();
        }
        $this->key++;
    }

    public function key(): ?int
    {
        return $this->key;
    }

    public function valid(): bool
    {
        return array_key_exists($this->key, $this->cache) || $this->hasMore;
    }

    public function rewind(): void
    {
        $this->key = 0;
    }

    private function getAs(int $mode, mixed ...$args): mixed
    {
        if ($mode === PDO::FETCH_ASSOC) {
            return array_combine($this->columns, $this->cache[$this->key]->getContent());
        } elseif ($mode === PDO::FETCH_BOTH) {
            return $this->cache[$this->key]->getContent() + array_combine($this->columns, $this->cache[$this->key]->getContent());
        } elseif ($mode === PDO::FETCH_BOUND) {
            foreach ($this->cache[$this->key]->getContent() as $i => $value) {
                if (array_key_exists($i + 1, $this->boundColumns)) {
                    $this->boundColumns[$i + 1]['var'] = $value;
                } elseif (array_key_exists($this->columns[$i], $this->boundColumns)) {
                    $this->boundColumns[$this->columns[$i]]['var'] = $value;
                } else {
                    $this->driver->handleError(Driver::ERR_FETCH_COLUMN_NOT_DEFINED, 'Column "' . $i + 1 . '" or "' . $this->columns[$i] . '" not bound.');
                    return false;
                }
            }
            return true;
        } elseif ($mode === PDO::FETCH_INTO) {
            if ($this->recordToObject()) {
                return true;
            } else {
                $this->driver->handleError(Driver::ERR_FETCH_OBJECT, 'Fetch as object unsuccessful.');
            }
        } elseif ($mode === PDO::FETCH_LAZY) {
            $this->driver->handleError(Driver::ERR_FETCH_OBJECT, 'Lazy fetch not implemented.');
        } elseif ($mode === PDO::FETCH_NAMED) {
            $this->driver->handleError(Driver::ERR_FETCH, 'Multiple result columns with the same name are not supported');
        } elseif ($mode === PDO::FETCH_NUM) {
            return $this->cache[$this->key]->getContent();
        } elseif ($mode === PDO::FETCH_OBJ) {
            if ($this->recordAsObject()) {
                return $this->recordAsObject;
            } else {
                $this->driver->handleError(Driver::ERR_FETCH_OBJECT, 'Fetch as object unsuccessful.');
            }
        } elseif ($mode & PDO::FETCH_CLASS) {
            if ($this->recordAsObject(
                $mode & PDO::FETCH_CLASSTYPE
                    ? str_replace('\\\\', '\\', $this->cache[$this->key]->getContent()[0])
                    : ($args[0] ?? null),
                $args[1] ?? [],
                $mode & PDO::FETCH_PROPS_LATE
            )) {
                return $this->recordAsObject;
            } else {
                $this->driver->handleError(Driver::ERR_FETCH_OBJECT, 'Fetch as object unsuccessful.');
            }
        }
        return null;
    }

    private function pullRecord(): bool
    {
        try {
            if (method_exists($this->driver->protocol, 'pull')) {
                $this->driver->protocol->pull(['n' => 1, 'qid' => $this->qid]);
            } elseif (method_exists($this->driver->protocol, 'pullAll')) {
                $this->driver->protocol->pullAll();
            } else {
                $this->driver->handleError(Driver::ERR_BOLT, 'Low level bolt library is missing PULL message.');
                return false;
            }
            /** @var Response $response */
            foreach ($this->driver->protocol->getResponses() as $response) {
                if ($response->getSignature() === $response::SIGNATURE_RECORD) {
                    $this->cache[] = $response;
                } elseif ($this->driver->checkResponse($response)) {
                    $this->hasMore = $response->getContent()['has_more'] ?? false;
                    $this->driver->bookmarks->add($response->getContent()['bookmark'] ?? '');
                }
            }
            return true;
        } catch (BoltException $e) {
            $this->driver->handleError(Driver::ERR_BOLT, 'Underlying Bolt library error occurred', $e);
        }
        return false;
    }

    private function recordToObject(): bool
    {
        if (!is_null($this->recordAsObject)) {
            foreach ($this->columns as $i => $column) {
                $this->recordAsObject->{$column} = $this->cache[$this->key]->getContent()[$i];
            }
            return true;
        }
        return false;
    }

    private function recordAsObject(?string $class = null, array $constructorArgs = [], bool $late = false): bool
    {
        if (is_null($class)) {
            $class = new class {
            };
        }
        try {
            $ref = new \ReflectionClass($class);
            if ($late) {
                $instance = $ref->newInstanceArgs($constructorArgs);
            } else {
                $instance = $ref->newInstanceWithoutConstructor();
            }

            $arr = array_combine($this->columns, $this->cache[$this->key]->getContent());
            foreach ($ref->getProperties() as $property) {
                if (array_key_exists($property->getName(), $arr)) {
                    $property->setAccessible(true);
                    $property->setValue($instance, $arr[$property->getName()]);
                    unset($arr[$property->getName()]);
                }
            }
            foreach ($arr as $key => $value) {
                $instance->{$key} = $value;
            }

            if (!$late) {
                $constructor = $ref->getConstructor();
                if ($constructor instanceof \ReflectionMethod) {
                    $constructor->invokeArgs($instance, $constructorArgs);
                }
            }

            $this->recordAsObject = $instance;
            return true;
        } catch (\ReflectionException $e) {
            $this->driver->handleError(Driver::ERR_FETCH_OBJECT, previous: $e);
        }
        return false;
    }
}

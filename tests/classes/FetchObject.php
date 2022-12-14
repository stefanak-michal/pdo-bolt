<?php

namespace pdo_bolt\tests\classes;

/**
 * Class FetchObject
 * @author Michal Stefanak
 * @link https://github.com/stefanak-michal/pdo-bolt
 */
class FetchObject
{
    // These are private because PDO is able to write into them and is used for testing purpose
    private int $num;
    private string $str;

    public function num(): int
    {
        return $this->num;
    }

    public function str(): string
    {
        return $this->str;
    }

    public function __construct(public ?string $bar = null)
    {
    }
}

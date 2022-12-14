<?php

namespace pdo_bolt\drivers\bolt;

/**
 * Class Bookmarks
 * @author Michal Stefanak
 * @link https://github.com/stefanak-michal/pdo-bolt
 */
class Bookmarks
{
    private array $bookmarks = [];

    public function add(string $bookmark): void
    {
        if (!empty($bookmark) && !in_array($bookmark, $this->bookmarks)) {
            $this->bookmarks[] = $bookmark;
        }
    }

    public function get(): array
    {
        return $this->bookmarks;
    }

    public function hasAny(): bool
    {
        return !empty($this->bookmarks);
    }
}

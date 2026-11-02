<?php

namespace Folklore\JsonData\Schema\Concerns;

use Folklore\JsonData\Exceptions\InvalidJsonData;
use Folklore\JsonData\Schema\ReferenceType;
use Folklore\JsonData\Schema\Type;

/**
 * Reference nodes at paths under a node, without describing the structure in between
 * (`paths()`), as a declaration of laravel-folklore 1.x names only the paths of its
 * references: an object, a list, a map or `Json::any()`.
 */
trait HasPaths
{
    /**
     * @var array<string, ReferenceType>
     */
    protected array $paths = [];

    /**
     * Reference nodes at paths relative to this node, whose segments are property names or
     * `*` (`'*.blocks.*.media' => Json::media()`), compiled as they are written. A path
     * adds no input key to `JsonData::fill()` and no validation rule: this node fills and
     * validates as it does without them. The nodes are reference nodes, with their
     * options, and can't be extracted into a column or read from an input key.
     *
     * @param  array<string, Type>  $paths
     */
    public function paths(array $paths): static
    {
        foreach ($paths as $path => $node) {
            $path = (string) $path;
            if (! $node instanceof ReferenceType) {
                throw InvalidJsonData::invalidSchema("The node at [{$path}] of paths() is not a reference node.");
            }
            $segments = explode('.', $path);
            foreach ($segments as $segment) {
                if ($segment === '' || ($segment !== '*' && str_contains($segment, '*'))) {
                    throw InvalidJsonData::invalidSchema("[{$path}] is not a path of paths(): its segments are property names or `*`, with no `**`.");
                }
            }
            $this->checkPath($segments, $path);
            $this->paths[$path] = $node;
        }

        return $this;
    }

    /**
     * @return array<string, ReferenceType>
     */
    public function getPaths(): array
    {
        return $this->paths;
    }

    /**
     * Refuses a path that the node describes otherwise.
     *
     * @param  list<string>  $segments
     */
    protected function checkPath(array $segments, string $path): void {}
}

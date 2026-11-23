<?php

/**
 * This source file is subject to the GNU General Public License version 3 (GPLv3)
 * For the full copyright and license information, please view the LICENSE.md and gpl-3.0.txt
 * files that are distributed with this source code.
 *
 * @license    https://choosealicense.com/licenses/gpl-3.0/ GNU General Public License v3.0
 * @copyright  Copyright (c) 2021 CI HUB GmbH (https://ci-hub.com)
 */

declare(strict_types=1);

namespace CIHub\Bundle\SimpleRESTAdapterBundle\Tests\Integration\Elasticsearch;

use CIHub\Bundle\SimpleRESTAdapterBundle\Elasticsearch\WorkspaceQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The workspace semantics of Simple REST: evaluates the generated search condition for element paths (needs no
 * search engine; the queries used are match_all, term, prefix and bool)
 */
final class WorkspaceQueryTest extends TestCase
{
    /**
     * @return iterable<string, array{list<array{cpath: string, read: bool|string}>, array<string, bool>}>
     */
    public static function workspaces(): iterable
    {
        yield 'none' => [[], ['/a' => false, '/a/b.jpg' => false]];

        yield 'root' => [[['cpath' => '/', 'read' => true]], ['/a' => true, '/a/b/c.jpg' => true]];

        yield 'one folder' => [[['cpath' => '/a', 'read' => true]], [
            '/a' => true,
            '/a/b.jpg' => true,
            '/ab.jpg' => false,
            '/b/a.jpg' => false,
        ]];

        yield 'denied subfolder' => [[['cpath' => '/', 'read' => true], ['cpath' => '/a', 'read' => false]], [
            '/b.jpg' => true,
            '/a' => false,
            '/a/b.jpg' => false,
            '/ab.jpg' => true,
        ]];

        yield 'allowed again below a denied folder' => [[
            ['cpath' => '/', 'read' => true],
            ['cpath' => '/a', 'read' => false],
            ['cpath' => '/a/b', 'read' => true],
        ], [
            '/a/x.jpg' => false,
            '/a/b/x.jpg' => true,
            '/c.jpg' => true,
        ]];

        yield 'only denied' => [[['cpath' => '/a', 'read' => false]], ['/a/b.jpg' => false, '/b.jpg' => false]];

        yield 'trailing slash and string flags' => [[['cpath' => '/a/', 'read' => 'true']], ['/a/b.jpg' => true, '/c.jpg' => false]];
    }

    /**
     * @param list<array{cpath: string, read: bool|string}> $workspace
     * @param array<string, bool>                          $expected  path => visible
     */
    #[DataProvider('workspaces')]
    public function testVisibilityFollowsTheMostSpecificWorkspace(array $workspace, array $expected): void
    {
        $query = WorkspaceQuery::build($workspace);

        foreach ($expected as $path => $visible) {
            self::assertSame($visible, $this->isVisible($query, $path), $path);
        }
    }

    /**
     * @param array<array-key, mixed> $query
     */
    private function isVisible(array $query, string $path): bool
    {
        $leaf = $this->evaluateLeaf($query, $path);
        if (null !== $leaf) {
            return $leaf;
        }

        $bool = $this->part($query, 'bool');
        $all = array_merge($this->conditions($bool, 'filter'), $this->conditions($bool, 'must'));
        $any = $this->conditions($bool, 'should');

        return [] === array_filter($all, fn (array $condition): bool => !$this->isVisible($condition, $path))
            && [] === array_filter($this->conditions($bool, 'must_not'), fn (array $condition): bool => $this->isVisible($condition, $path))
            && ([] === $any || [] !== array_filter($any, fn (array $condition): bool => $this->isVisible($condition, $path)));
    }

    /**
     * match_all, term and prefix on the path; null for bool queries
     *
     * @param array<array-key, mixed> $query
     */
    private function evaluateLeaf(array $query, string $path): ?bool
    {
        if (isset($query['match_all'])) {
            return true;
        }

        if (isset($query['term'])) {
            return $this->part($query, 'term')['system.fullPath'] === $path;
        }

        if (isset($query['prefix'])) {
            $prefix = $this->part($query, 'prefix')['system.fullPath'] ?? null;
            self::assertIsString($prefix);

            return str_starts_with($path, $prefix);
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $query
     *
     * @return array<array-key, mixed>
     */
    private function part(array $query, string $key): array
    {
        $part = $query[$key] ?? null;
        self::assertIsArray($part);

        return $part;
    }

    /**
     * @param array<array-key, mixed> $bool
     *
     * @return list<array<array-key, mixed>>
     */
    private function conditions(array $bool, string $key): array
    {
        $conditions = $bool[$key] ?? [];
        self::assertIsArray($conditions);

        return array_values(array_filter($conditions, \is_array(...)));
    }
}

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

namespace CIHub\Bundle\SimpleRESTAdapterBundle\Elasticsearch;

/**
 * Search condition for the workspaces of a DataHub config ("workspaces.asset|object": list of {cpath, read}), with the
 * semantics of Pimcore's DataHub Simple REST: an element is visible when the most specific workspace whose path
 * contains it allows reading. Without workspaces nothing is visible.
 */
final class WorkspaceQuery
{
    private const FIELD = 'system.fullPath';

    /**
     * @param array<array-key, mixed> $workspace entries of one element type
     *
     * @return array<string, mixed>
     */
    public static function build(array $workspace): array
    {
        $entries = [];
        foreach ($workspace as $entry) {
            if (\is_array($entry) && \is_string($entry['cpath'] ?? null) && '' !== $entry['cpath']) {
                $entries[self::normalize($entry['cpath'])] = filter_var($entry['read'] ?? false, \FILTER_VALIDATE_BOOLEAN);
            }
        }

        $allowed = array_keys(array_filter($entries));
        if ([] === $allowed) {
            return ['bool' => ['must_not' => [['match_all' => (object) []]]]];
        }

        return self::anyOf(array_map(
            static fn (string $path): array => self::within($path, $entries, true),
            self::outermost($allowed)
        ));
    }

    /**
     * Elements inside $path, without the subtrees of more specific workspaces that decide the other way (recursively)
     *
     * @param array<string, bool> $entries
     *
     * @return array<string, mixed>
     */
    private static function within(string $path, array $entries, bool $read): array
    {
        $exceptions = array_keys(array_filter(
            $entries,
            static fn (bool $entryRead, string $entryPath): bool => $entryRead !== $read && self::isBelow($entryPath, $path),
            \ARRAY_FILTER_USE_BOTH
        ));

        $condition = self::pathCondition($path);
        if ([] === $exceptions) {
            return $condition;
        }

        return ['bool' => [
            'filter' => [$condition],
            'must_not' => [self::anyOf(array_map(
                static fn (string $exception): array => self::within($exception, $entries, !$read),
                self::outermost($exceptions)
            ))],
        ]];
    }

    /**
     * @param list<string> $paths
     *
     * @return list<string> the paths that are not below another one of them
     */
    private static function outermost(array $paths): array
    {
        return array_values(array_filter(
            $paths,
            static fn (string $path): bool => [] === array_filter($paths, static fn (string $other): bool => self::isBelow($path, $other))
        ));
    }

    /**
     * @param list<array<string, mixed>> $conditions
     *
     * @return array<string, mixed>
     */
    private static function anyOf(array $conditions): array
    {
        return 1 === \count($conditions) ? $conditions[0] : ['bool' => ['should' => $conditions, 'minimum_should_match' => 1]];
    }

    /**
     * The element at $path and everything below it
     *
     * @return array<string, mixed>
     */
    private static function pathCondition(string $path): array
    {
        if ('/' === $path) {
            return ['match_all' => (object) []];
        }

        return ['bool' => ['should' => [
            ['term' => [self::FIELD => $path]],
            ['prefix' => [self::FIELD => $path.'/']],
        ], 'minimum_should_match' => 1]];
    }

    private static function isBelow(string $path, string $parent): bool
    {
        return $path !== $parent && ('/' === $parent || str_starts_with($path, $parent.'/'));
    }

    private static function normalize(string $path): string
    {
        $path = '/'.trim($path, '/');

        return '/' === $path ? '/' : $path;
    }
}

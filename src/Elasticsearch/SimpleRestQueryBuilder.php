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

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Builds the search request body from the query parameters of Pimcore's DataHub Simple REST endpoints, with their
 * semantics: "fulltext_search" (simple_query_string over all fields), "query_string_query", "filter" (JSON with
 * $and, $or and $not), "order_by" and the numeric "page_cursor".
 */
final class SimpleRestQueryBuilder
{
    public const DEFAULT_SIZE = 200;

    private const MAX_RESULT_WINDOW = 10000;

    /**
     * @param list<array<string, mixed>> $conditions further filter conditions, e.g. the parent of the tree level
     *
     * @return array<string, mixed>
     */
    public function build(Request $request, array $conditions = []): array
    {
        [$size, $from] = $this->getPaging($request);

        $body = [
            'size' => $size,
            'from' => $from,
            'query' => ['bool' => ['must' => [], 'filter' => ['bool' => ['must' => [...$this->buildFilter($request), ...$conditions]]]]],
        ];

        $fulltextSearch = $request->query->getString('fulltext_search');
        if ('' !== $fulltextSearch) {
            $body['query']['bool']['must'][] = ['simple_query_string' => ['query' => $fulltextSearch, 'fields' => []]];
        }

        $sort = $this->buildSort($request->query->getString('order_by'));
        if ([] !== $sort) {
            $body['sort'] = $sort;
        }

        return $body;
    }

    /**
     * @return array{int, int} size and offset of the page
     */
    public function getPaging(Request $request): array
    {
        $size = max($request->query->getInt('size', self::DEFAULT_SIZE), 1);
        $from = max($request->query->getInt('page_cursor'), 0);
        if ($from + $size > self::MAX_RESULT_WINDOW) {
            throw new BadRequestHttpException('Result window exceeds ' . self::MAX_RESULT_WINDOW);
        }

        return [$size, $from];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildFilter(Request $request): array
    {
        $conditions = [];

        $filter = $request->query->getString('filter');
        if ('' !== $filter) {
            $decoded = json_decode($filter, true);
            if (!\is_array($decoded)) {
                throw new BadRequestHttpException('Parameter filter contains invalid json.');
            }

            $conditions[] = $this->generateBoolFilter($decoded);
        }

        $queryStringQuery = $request->query->getString('query_string_query');
        if ('' !== $queryStringQuery) {
            $conditions[] = ['query_string' => ['query' => $queryStringQuery]];
        }

        return $conditions;
    }

    /**
     * @param array<array-key, mixed> $filter
     *
     * @return array<string, mixed>
     */
    private function generateBoolFilter(array $filter, string $operator = 'must'): array
    {
        $parts = [];
        $mustNot = [];

        foreach ($filter as $key => $queryPart) {
            if (\is_array($queryPart) && '$not' === array_key_first($queryPart) && !str_starts_with((string) $key, '$')) {
                $mustNot[] = ['term' => [$key => $queryPart['$not']]];
            } else {
                $parts[] = $this->generateFilterPart((string) $key, $queryPart);
            }
        }

        return ['bool' => array_filter([$operator => $parts, 'must_not' => $mustNot])];
    }

    /**
     * @return array<string, mixed>
     */
    private function generateFilterPart(string $key, mixed $queryPart): array
    {
        if (!\is_array($queryPart)) {
            return ['term' => [$key => $queryPart]];
        }

        return $this->generateBoolFilter($queryPart, '$or' === $key ? 'should' : 'must');
    }

    /**
     * "order_by": a field name, or JSON with field => "asc"/"desc"/1/-1 or field => {"order": …, "unmapped_type": …}.
     *
     * @return list<array<string, mixed>>
     */
    private function buildSort(string $orderBy): array
    {
        if ('' === $orderBy) {
            return [];
        }

        $decoded = json_decode($orderBy, true);
        $fields = \is_array($decoded) ? $decoded : [\is_string($decoded) ? $decoded : $orderBy => 'asc'];

        $sort = [];
        foreach ($fields as $field => $order) {
            $sort[] = \is_int($field) ? $this->buildSortEntry((string) $order, 'asc') : $this->buildSortEntry($field, $order);
        }

        $sort[] = ['system.id' => ['order' => 'asc']];

        return $sort;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function buildSortEntry(string $field, mixed $order): array
    {
        $unmappedType = \is_array($order) ? (string) ($order['unmapped_type'] ?? 'keyword') : 'keyword';
        $direction = match (true) {
            \is_array($order) => (string) ($order['order'] ?? 'asc'),
            \is_int($order) => -1 === $order ? 'desc' : 'asc',
            default => (string) $order,
        };

        return [$field => ['order' => $direction, 'missing' => '_last', 'unmapped_type' => $unmappedType]];
    }
}

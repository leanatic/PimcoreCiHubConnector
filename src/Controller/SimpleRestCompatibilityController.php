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

namespace CIHub\Bundle\SimpleRESTAdapterBundle\Controller;

use CIHub\Bundle\SimpleRESTAdapterBundle\Elasticsearch\Index\IndexQueryService;
use CIHub\Bundle\SimpleRESTAdapterBundle\Elasticsearch\Mapping\DefaultMapping;
use CIHub\Bundle\SimpleRESTAdapterBundle\Elasticsearch\SimpleRestQueryBuilder;
use CIHub\Bundle\SimpleRESTAdapterBundle\Exception\AccessDeniedException;
use CIHub\Bundle\SimpleRESTAdapterBundle\Extractor\LabelExtractorInterface;
use CIHub\Bundle\SimpleRESTAdapterBundle\Manager\AuthManager;
use CIHub\Bundle\SimpleRESTAdapterBundle\Manager\IndexManager;
use CIHub\Bundle\SimpleRESTAdapterBundle\Reader\ConfigReader;
use CIHub\Bundle\SimpleRESTAdapterBundle\Repository\DataHubConfigurationRepository;
use Pimcore\Bundle\DataHubBundle\Configuration;
use Pimcore\Controller\FrontendController;
use Pimcore\Model\Asset;
use Pimcore\Tool;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * The read endpoints of Pimcore's DataHub Simple REST bundle (tree-items, search, get-element, download-asset), for CI
 * HUB clients set up against Simple REST.
 *
 * Parameters, filter syntax, paging and responses follow Simple REST, so these clients keep working without changes.
 * Like Simple REST, they accept the config's API key ("deliverySettings.apikey") and answer from the index, which only
 * holds the elements of the config's workspaces. A CI HUB user token is accepted as well. The other endpoints of this
 * bundle (upload, versions, lock …) need a user token, as they act in the name of a Pimcore user.
 *
 * The routes take priority over the "/pimcore-datahub-webservices/simplerest/{config}" aliases of the other controllers.
 */
#[Route(
    path: '/pimcore-datahub-webservices/simplerest/{config}',
    name: 'datahub_rest_simple_rest_',
    requirements: ['config' => '[\w-]+'],
    priority: 10
)]
final class SimpleRestCompatibilityController extends FrontendController
{
    public function __construct(
        private readonly DataHubConfigurationRepository $dataHubConfigurationRepository,
        private readonly AuthManager $authManager,
        private readonly IndexManager $indexManager,
        private readonly IndexQueryService $indexQueryService,
        private readonly LabelExtractorInterface $labelExtractor,
    ) {
    }

    #[Route('/tree-items', name: 'tree_items', methods: ['GET'])]
    public function treeItemsAction(Request $request, string $config): JsonResponse
    {
        try {
            $type = $this->getRequiredParameter($request, 'type');
            $configReader = $this->authorize($config);
            $includeFolders = filter_var($request->query->get('include_folders', true), \FILTER_VALIDATE_BOOLEAN);
            $parentId = $request->query->getInt('parent_id', $request->query->getInt('parentId', 1));

            $indices = $this->getIndices($configReader, $config, $type, $includeFolders);

            return $this->search($request, $configReader, $config, $indices, [
                ['term' => ['system.type' => $type]],
                ['term' => ['system.parentId' => $parentId]],
            ]);
        } catch (\Exception $exception) {
            return $this->sendJsonError($exception);
        }
    }

    #[Route('/search', name: 'search', methods: ['GET'])]
    public function searchAction(Request $request, string $config): JsonResponse
    {
        try {
            $configReader = $this->authorize($config);
            $indices = array_merge(
                $this->getIndices($configReader, $config, 'asset', false),
                $this->getIndices($configReader, $config, 'object', false)
            );

            return $this->search($request, $configReader, $config, $indices);
        } catch (\Exception $exception) {
            return $this->sendJsonError($exception);
        }
    }

    #[Route('/get-element', name: 'get_element', methods: ['GET'])]
    public function getElementAction(Request $request, string $config): JsonResponse
    {
        try {
            $type = $this->getRequiredParameter($request, 'type');
            $id = (int) $this->getRequiredParameter($request, 'id');
            $configReader = $this->authorize($config);

            $hit = $this->findIndexedElement($configReader, $config, $type, $id);
            if (null === $hit) {
                throw new NotFoundHttpException(\sprintf("Element with type '%s' and id '%d' not found.", $type, $id));
            }

            $element = $this->withoutInternalFields($hit['_source']);
            $element['labels'] = $this->getLabels($configReader, [$hit['_index']]);

            return new JsonResponse($element);
        } catch (\Exception $exception) {
            return $this->sendJsonError($exception);
        }
    }

    #[Route('/download-asset', name: 'download_asset', methods: ['GET', 'OPTIONS'])]
    public function downloadAssetAction(Request $request, string $config): Response
    {
        $headers = [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, OPTIONS',
            'Access-Control-Allow-Headers' => 'authorization',
        ];

        if ($request->isMethod('OPTIONS')) {
            return new Response('', Response::HTTP_NO_CONTENT, $headers + ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        try {
            $id = (int) $this->getRequiredParameter($request, 'id');
            $configReader = $this->authorize($config);

            $asset = Asset::getById($id);
            if (!$asset instanceof Asset || null === $this->findIndexedElement($configReader, $config, 'asset', $id)) {
                throw new NotFoundHttpException(\sprintf("Asset with id '%d' not found or access denied.", $id));
            }

            $thumbnailName = $request->query->getString('thumbnail');
            if ($asset instanceof Asset\Image && '' !== $thumbnailName) {
                $thumbnail = $asset->getThumbnail($thumbnailName);

                return $this->streamFile($thumbnail->getStream(), basename($thumbnail->getPath()), (string) $thumbnail->getMimeType(), $thumbnail->getFileSize(), $headers);
            }

            return $this->streamFile($asset->getStream(), (string) $asset->getFilename(), (string) $asset->getMimeType(), $asset->getFileSize(), $headers);
        } catch (\Exception $exception) {
            return $this->sendJsonError($exception);
        }
    }

    /**
     * @param array<string, mixed> $source
     *
     * @return array<string, mixed>
     */
    private function withoutInternalFields(array $source): array
    {
        return array_diff_key($source, [DefaultMapping::INTERNAL_FULLTEXT => true]);
    }

    private function authorize(string $config): ConfigReader
    {
        $configuration = $this->dataHubConfigurationRepository->findOneByName($config);
        if (!$configuration instanceof Configuration || 'ciHub' !== $configuration->getType() || !$configuration->isActive()) {
            throw new AccessDeniedException();
        }

        $configReader = new ConfigReader($configuration->getConfiguration());
        if (!$this->authManager->hasConfigApiKey($configReader->getApiKey())) {
            $this->authManager->authenticate();
        }

        return $configReader;
    }

    private function getRequiredParameter(Request $request, string $name): string
    {
        $value = $request->query->getString($name);
        if ('' === $value) {
            throw new BadRequestHttpException('Missing required params: ' . $name);
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function getIndices(ConfigReader $configReader, string $config, string $type, bool $includeFolders): array
    {
        $indices = [];
        if ('asset' === $type && $configReader->isAssetIndexingEnabled()) {
            $indices[] = IndexManager::INDEX_ASSET;
            if ($includeFolders) {
                $indices[] = IndexManager::INDEX_ASSET_FOLDER;
            }
        } elseif ('object' === $type && $configReader->isObjectIndexingEnabled()) {
            $indices = array_map(mb_strtolower(...), $configReader->getObjectClassNames());
            if ($includeFolders) {
                $indices[] = IndexManager::INDEX_OBJECT_FOLDER;
            }
        }

        return array_map(fn (string $index): string => $this->indexManager->getIndexName($index, $config), $indices);
    }

    /**
     * @return array{_index: string, _source: array<string, mixed>}|null
     */
    private function findIndexedElement(ConfigReader $configReader, string $config, string $type, int $id): ?array
    {
        $indices = $this->getIndices($configReader, $config, $type, true);
        if ([] === $indices) {
            return null;
        }

        $result = $this->indexQueryService->search(implode(',', $indices), [
            'size' => 1,
            'query' => ['term' => ['system.id' => $id]],
        ]);

        return $result['hits']['hits'][0] ?? null;
    }

    /**
     * @param list<string>               $indices
     * @param list<array<string, mixed>> $conditions
     */
    private function search(Request $request, ConfigReader $configReader, string $config, array $indices, array $conditions = []): JsonResponse
    {
        if ([] === $indices) {
            return new JsonResponse(['total_count' => 0, 'items' => [], 'labels' => []]);
        }

        $queryBuilder = new SimpleRestQueryBuilder();
        [$size, $pageCursor] = $queryBuilder->getPaging($request);
        $body = $queryBuilder->build($request, $conditions);

        $result = $this->indexQueryService->search(implode(',', $indices), $body);

        $hits = $result['hits']['hits'] ?? [];
        $response = [
            'total_count' => $result['hits']['total']['value'] ?? $result['hits']['total'] ?? 0,
            'items' => array_map($this->withoutInternalFields(...), array_column($hits, '_source')),
            'page_cursor' => $pageCursor + $size,
            'labels' => $this->getLabels($configReader, array_values(array_unique(array_column($hits, '_index')))),
        ];

        $headers = [];
        if (\count($hits) === $size) {
            $parameters = ['page_cursor' => $response['page_cursor'], 'config' => $config] + $request->query->all();
            $headers['link'] = \sprintf('<%s>; rel="next"', $this->generateUrl(
                (string) $request->attributes->get('_route'),
                $parameters,
                UrlGeneratorInterface::ABSOLUTE_URL
            ));
        }

        return new JsonResponse($response, Response::HTTP_OK, $headers);
    }

    /**
     * The labels of the config's label settings that the given indices use, per language, as Simple REST returns them.
     *
     * @param list<string> $indices
     *
     * @return array<string, array<string, string>>
     */
    private function getLabels(ConfigReader $configReader, array $indices): array
    {
        if ([] === $indices) {
            return [];
        }

        $usedLabels = array_flip($this->labelExtractor->extractLabels($indices));
        $languages = Tool::getValidLanguages();

        $labels = [];
        foreach ($configReader->getLabelSettings() as $labelSetting) {
            $id = $labelSetting['id'] ?? null;
            if (!\is_string($id) || !isset($usedLabels[$id])) {
                continue;
            }

            foreach ($languages as $language) {
                $labels[$id][$language] = $labelSetting[$language] ?? $id;
            }
        }

        return $labels;
    }

    /**
     * @param resource|null         $stream
     * @param array<string, string> $headers
     */
    private function streamFile($stream, string $filename, string $mimeType, int $fileSize, array $headers): Response
    {
        if (!\is_resource($stream)) {
            throw new NotFoundHttpException('The file could not be read from the storage.');
        }

        $fallback = (string) preg_replace('/[^\w\-.]/', '', $filename);

        return new StreamedResponse(static function () use ($stream): void {
            fpassthru($stream);
        }, Response::HTTP_OK, $headers + [
            'Content-Type' => $mimeType,
            'Content-Length' => (string) $fileSize,
            'Content-Disposition' => HeaderUtils::makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename, '' !== $fallback ? $fallback : 'download'),
        ]);
    }

    private function sendJsonError(\Exception $exception): JsonResponse
    {
        $status = match (true) {
            $exception instanceof AccessDeniedException,
            $exception instanceof AuthenticationException => Response::HTTP_UNAUTHORIZED,
            $exception instanceof NotFoundHttpException => Response::HTTP_NOT_FOUND,
            $exception instanceof BadRequestHttpException => Response::HTTP_BAD_REQUEST,
            default => Response::HTTP_INTERNAL_SERVER_ERROR,
        };

        if (Response::HTTP_INTERNAL_SERVER_ERROR === $status) {
            throw $exception;
        }

        return new JsonResponse(['error' => $exception->getMessage()], $status);
    }
}

<?php

/**
 * This source file is subject to the GNU General Public License version 3 (GPLv3)
 * For the full copyright and license information, please view the LICENSE.md and gpl-3.0.txt
 * files that are distributed with this source code.
 *
 * @license    https://choosealicense.com/licenses/gpl-3.0/ GNU General Public License v3.0
 * @copyright  Copyright (c) 2023 Brand Oriented sp. z o.o. (https://brandoriented.pl)
 * @copyright  Copyright (c) 2021 CI HUB GmbH (https://ci-hub.com)
 */

namespace CIHub\Bundle\SimpleRESTAdapterBundle\Manager;

use CIHub\Bundle\SimpleRESTAdapterBundle\Elasticsearch\Index\IndexPersistenceService;
use CIHub\Bundle\SimpleRESTAdapterBundle\Exception\ESClientException;
use CIHub\Bundle\SimpleRESTAdapterBundle\Reader\ConfigReader;
use CIHub\Bundle\SimpleRESTAdapterBundle\Utils\DiffArray;
use OpenSearch\Common\Exceptions\OpenSearchException;
use Pimcore\Model\Asset;
use Pimcore\Model\Asset\Folder;
use Pimcore\Model\DataObject;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\Element\ElementInterface;

final readonly class IndexManager
{
    public const INDEX_ASSET = 'asset';

    public const INDEX_ASSET_FOLDER = 'assetfolder';

    public const INDEX_OBJECT_FOLDER = 'objectfolder';

    public function __construct(private string $indexNamePrefix, private IndexPersistenceService $indexPersistenceService)
    {
    }

    /**
     * Clears an index by cloning and renaming the existing index.
     *
     * @throws OpenSearchException
     */
    public function clearIndexData(string $indexName): void
    {
        $mapping = $this->getIndexMapping($indexName);

        if ([] === $mapping) {
            throw new \RuntimeException(sprintf('Could not clear index data. No mapping found for "%s"', $indexName));
        }

        $this->updateMapping($indexName, $mapping, false, true);
    }

    /**
     * Creates or updates an index by its name and mapping.
     *
     * @param string $indexName – The index to create or update
     * @param array  $mapping   – The mapping to check against
     *
     * @throws OpenSearchException
     */
    public function createOrUpdateIndex(string $indexName, array $mapping): void
    {
        if ($this->indexPersistenceService->aliasExists($indexName)) {
            $this->updateMapping($indexName, $mapping);
        } else {
            $evenIndexName = sprintf('%s-even', $indexName);
            if (!$this->indexPersistenceService->indexExists($evenIndexName)) {
                $this->indexPersistenceService->createIndex($evenIndexName, $mapping);
            }
            $this->indexPersistenceService->createAlias($evenIndexName, $indexName);
        }
    }

    /**
     * Deletes all indices of a certain endpoint configuration.
     *
     * @throws OpenSearchException
     */
    public function deleteAllIndices(string $endpointName): void
    {
        $endpointIndices = sprintf('%s__%s*', $this->indexNamePrefix, mb_strtolower($endpointName));

        // The search engine may refuse wildcards on delete (action.destructive_requires_name): delete by name
        foreach ($this->indexPersistenceService->getIndexNames($endpointIndices) as $indexName) {
            $this->indexPersistenceService->deleteIndex($indexName);
        }
    }

    /**
     * Tries to find an index by alias name.
     *
     * @param string $aliasName – The name of the alias of an index
     *
     * @throws OpenSearchException
     */
    public function findIndexNameByAlias(string $aliasName): string
    {
        $aliases = $this->indexPersistenceService->getAlias($aliasName);

        foreach ($aliases as $index => $aliasMapping) {
            if (\array_key_exists($aliasName, $aliasMapping['aliases'])) {
                return $index;
            }
        }

        return '';
    }

    /**
     * Returns all index names of the current.
     *
     * @return array<int, string>
     */
    public function getAllIndexNames(ConfigReader $configReader): array
    {
        $indices = [];
        $endpointName = $configReader->getName();

        if ($configReader->isAssetIndexingEnabled()) {
            $indices[] = $this->getIndexName(self::INDEX_ASSET, $endpointName);
            $indices[] = $this->getIndexName(self::INDEX_ASSET_FOLDER, $endpointName);
        }

        if ($configReader->isObjectIndexingEnabled()) {
            $indices[] = $this->getIndexName(self::INDEX_OBJECT_FOLDER, $endpointName);

            foreach ($configReader->getObjectClassNames() as $className) {
                $indices[] = $this->getIndexName(mb_strtolower($className), $endpointName);
            }
        }

        return $indices;
    }

    /**
     * Returns only the mapping of the provided index name.
     *
     * @param string $indexName – A comma-separated list of index names
     *
     * @return array<string, mixed>
     *
     * @throws OpenSearchException
     */
    public function getIndexMapping(string $indexName): array
    {
        if (!str_ends_with($indexName, '-odd') && !str_ends_with($indexName, '-even')) {
            if (!$this->indexPersistenceService->aliasExists($indexName)) {
                throw new \RuntimeException(sprintf('Could not get index mapping. No alias found for "%s"', $indexName));
            }

            $indexName = $this->findIndexNameByAlias($indexName);
        }

        $indexMapping = $this->indexPersistenceService->getMapping($indexName);

        return $indexMapping[$indexName]['mappings'] ?? [];
    }

    /**
     * Builds the index name for a given name or element and the endpoint's name.
     *
     * @param string $endpointName – The endpoint's name
     */
    public function getIndexName(mixed $value, string $endpointName): string
    {
        $indexName = $value;

        if ($value instanceof ElementInterface) {
            if ($value instanceof Asset\Unknown) {
                // A folder only ends up as "unknown" when its type got lost. getPath() is the parent's path and always
                // ends with "/", so it put every file of an unknown type (CAD files, …) into the folder index.
                $indexName = 'folder' === $value->getType() ? self::INDEX_ASSET_FOLDER : self::INDEX_ASSET;
            } elseif ($value instanceof Folder) {
                $indexName = self::INDEX_ASSET_FOLDER;
            } elseif ($value instanceof Asset) {
                $indexName = self::INDEX_ASSET;
            } elseif ($value instanceof DataObject\Folder) {
                $indexName = self::INDEX_OBJECT_FOLDER;
            } elseif ($value instanceof Concrete) {
                $indexName = mb_strtolower((string) $value->getClassName());
            }
        }

        if (!\is_string($indexName)) {
            throw new \InvalidArgumentException(sprintf('The given value must either be a string or a Pimcore element, %s given.', \gettype($value)));
        }

        // Index names must be lowercase, config names need not be (e.g. "ciHub"), as with Simple REST
        return sprintf(
            '%s__%s__%s',
            $this->indexNamePrefix,
            mb_strtolower($endpointName),
            $indexName
        );
    }

    /**
     * Checks whether the current mapping of an index is different to the given mapping.
     *
     * @param array<string, mixed> $mapping
     */
    public function hasMappingChanged(string $indexName, array $mapping): bool
    {
        $currentMapping = $this->getIndexMapping($indexName);

        return [] !== array_merge(
                DiffArray::diffAssocRecursive($mapping, $currentMapping),
                DiffArray::diffAssocRecursive($currentMapping, $mapping)
            );
    }

    /**
     * Checks whether the workspaces have changed compared to the prior configuration or not.
     */
    public function hasWorkspaceChanged(ConfigReader $reader, ConfigReader $priorReader): bool
    {
        $assetWorkspace = $reader->getWorkspace('asset');
        $priorAssetWorkspace = $priorReader->getWorkspace('asset');

        if ([] !== DiffArray::diffAssocRecursive($assetWorkspace, $priorAssetWorkspace)) {
            return true;
        }

        $objectWorkspace = $reader->getWorkspace('object');
        $priorObjectWorkspace = $priorReader->getWorkspace('object');

        return [] !== DiffArray::diffAssocRecursive($objectWorkspace, $priorObjectWorkspace);
    }

    /**
     * Creates a new target index with mappings and re-indexes the data from the source index.
     * Then a new alias pointing to the new index is created and the old index is removed.
     *
     * @throws OpenSearchException
     */
    public function updateMapping(string $indexName, array $mapping, bool $reindexData = true, bool $force = false): void
    {
        $oddIndexName = sprintf('%s-odd', $indexName);
        $evenIndexName = sprintf('%s-even', $indexName);

        if ($this->indexPersistenceService->indexExists($oddIndexName)) {
            $source = $oddIndexName;
            $target = $evenIndexName;
        } else {
            $source = $evenIndexName;
            $target = $oddIndexName;
        }

        if ($force || $this->hasMappingChanged($source, $mapping)) {
            if (!$this->indexPersistenceService->indexExists($target)) {
                // Create a new target index with mapping
                $indexResponse = $this->indexPersistenceService->createIndex($target, $mapping);
                if (!isset($indexResponse['acknowledged']) || true !== $indexResponse['acknowledged']) {
                    throw new ESClientException(sprintf('Could not create index "%s"', $target));
                }
            }

            if ($reindexData) {
                // Refresh source index before re-indexing the data
                $refreshResponse = $this->indexPersistenceService->refreshIndex($source);
                if (!isset($refreshResponse['_shards']['failed']) || $refreshResponse['_shards']['failed'] > 0) {
                    throw new ESClientException(sprintf('Could not refresh index "%s"', $source));
                }

                // Reindex the data from the source to the target index
                $reIndexResponse = $this->indexPersistenceService->reindex($source, $target);
                if (!isset($reIndexResponse['failures']) || !empty($reIndexResponse['failures'])) {
                    throw new ESClientException(sprintf('Could not reindex data from "%s" to "%s"', $source, $target));
                }

                // Make the copied documents searchable before the alias points to them; otherwise the API answers
                // without them until the next refresh (up to a second after every config save)
                $this->indexPersistenceService->refreshIndex($target);
            }

            // Create the alias for the new target index
            $aliasResponse = $this->indexPersistenceService->createAlias($target, $indexName);
            if (!isset($aliasResponse['acknowledged']) || true !== $aliasResponse['acknowledged']) {
                throw new ESClientException(sprintf('Could not create alias for "%s"', $target));
            }

            // Delete the old source index
            $this->indexPersistenceService->deleteIndex($source);
        }
    }
}

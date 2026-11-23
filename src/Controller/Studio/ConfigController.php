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

namespace CIHub\Bundle\SimpleRESTAdapterBundle\Controller\Studio;

use CIHub\Bundle\SimpleRESTAdapterBundle\Extractor\LabelExtractorInterface;
use CIHub\Bundle\SimpleRESTAdapterBundle\Manager\IndexManager;
use CIHub\Bundle\SimpleRESTAdapterBundle\Reader\ConfigReader;
use CIHub\Bundle\SimpleRESTAdapterBundle\Services\ClassFieldProvider;
use Pimcore\Bundle\DataHubBundle\Configuration;
use Pimcore\Model\Asset\Image\Thumbnail\Config as ThumbnailConfig;
use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Model\User;
use Pimcore\Security\User\User as SecurityUser;
use Pimcore\Tool;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * API of the editor of the "ciHub" DataHub configs in Studio (assets/studio, DataHub > CI HUB): loads and saves a
 * config, and the classes, fields, thumbnails and labels to choose from. Successor of the Classic Admin config panel.
 * DataHub's own Studio screen lists, creates and deletes the configs. Saving goes through DataHub's Configuration, so
 * ConfigModificationListener updates the indices as before.
 *
 * Same rules as DataHub: the permission "plugin_datahub_config", and the config's own read/update permissions.
 */
#[Route(path: '/pimcore-studio/api/ci-hub')]
final class ConfigController extends AbstractController
{
    public const CONFIG_TYPE = 'ciHub';

    private const PERMISSION = 'plugin_datahub_config';

    public function __construct(
        private readonly ClassFieldProvider $classFieldProvider,
        private readonly IndexManager $indexManager,
        private readonly LabelExtractorInterface $labelExtractor
    ) {
    }

    #[Route(path: '/configs/{name}', name: 'datahub_rest_studio_ci_hub_config', requirements: ['name' => '[\w-]+'], methods: ['GET'])]
    public function get(string $name): JsonResponse
    {
        $access = $this->getConfiguration($name, 'read');
        if ($access instanceof JsonResponse) {
            return $access;
        }
        [$user, $configuration] = $access;

        $config = $this->getStoredConfig($configuration);

        return $this->json([
            'success' => true,
            'configuration' => $config,
            'modificationDate' => $this->getModificationDate($config),
            'writeable' => false !== $configuration->isWriteable(),
            'userPermissions' => [
                'update' => (bool) $configuration->isAllowed('update', $user),
                'delete' => (bool) $configuration->isAllowed('delete', $user),
            ],
        ]);
    }

    /**
     * Body: {"configuration": {…}, "modificationDate": <date of the loaded config>}. Name, type and path stay as stored.
     */
    #[Route(path: '/configs/{name}', name: 'datahub_rest_studio_ci_hub_config_save', requirements: ['name' => '[\w-]+'], methods: ['PUT'])]
    public function save(string $name, Request $request): JsonResponse
    {
        $access = $this->getConfiguration($name, 'update');
        if ($access instanceof JsonResponse) {
            return $access;
        }
        [, $configuration] = $access;

        if (false === $configuration->isWriteable()) {
            return $this->error('The configuration is not writeable', Response::HTTP_FORBIDDEN);
        }

        $body = json_decode($request->getContent(), true);
        $submitted = \is_array($body) && \is_array($body['configuration'] ?? null) ? $body['configuration'] : null;
        if (null === $submitted) {
            return $this->error('Missing configuration', Response::HTTP_BAD_REQUEST);
        }

        $stored = $this->getStoredConfig($configuration);
        $storedGeneral = \is_array($stored['general'] ?? null) ? $stored['general'] : [];
        if (!is_numeric($body['modificationDate'] ?? null) || (int) $body['modificationDate'] < $this->getModificationDate($stored)) {
            return $this->error(
                'The configuration was changed in the meantime, reload it and make your changes again',
                Response::HTTP_CONFLICT
            );
        }

        $general = \is_array($submitted['general'] ?? null) ? $submitted['general'] : [];
        $submitted['general'] = array_merge($general, [
            'type' => self::CONFIG_TYPE,
            'name' => $configuration->getName(),
            'path' => $storedGeneral['path'] ?? $configuration->getPath(),
            'createDate' => $storedGeneral['createDate'] ?? null,
            'modificationDate' => time(),
        ]);

        $configuration->setConfiguration($submitted);
        $configuration->save();

        return $this->json(['success' => true, 'modificationDate' => $submitted['general']['modificationDate']]);
    }

    /**
     * The label ids used in the config's indices, for the label settings
     */
    #[Route(path: '/configs/{name}/labels', name: 'datahub_rest_studio_ci_hub_config_labels', requirements: ['name' => '[\w-]+'], methods: ['GET'])]
    public function labels(string $name): JsonResponse
    {
        $access = $this->getConfiguration($name, 'read');
        if ($access instanceof JsonResponse) {
            return $access;
        }
        [, $configuration] = $access;

        /** @var array<string, array<mixed>> $config the parts ConfigReader reads are arrays */
        $config = $this->getStoredConfig($configuration);
        $configReader = new ConfigReader($config);

        try {
            $labels = $this->labelExtractor->extractLabels($this->indexManager->getAllIndexNames($configReader));
        } catch (\Throwable) {
            // Indices that do not exist yet (config never saved with assets or classes)
            $labels = [];
        }
        sort($labels);

        return $this->json(['success' => true, 'labels' => array_values(array_unique($labels))]);
    }

    /**
     * Classes, image thumbnails and languages to choose from
     */
    #[Route(path: '/config-options', name: 'datahub_rest_studio_ci_hub_config_options', methods: ['GET'])]
    public function options(): JsonResponse
    {
        if (!$this->getCurrentUser() instanceof User) {
            return $this->error('Access denied', Response::HTTP_FORBIDDEN);
        }

        $classes = [];
        foreach ((new ClassDefinition\Listing())->load() as $classDefinition) {
            $classes[] = (string) $classDefinition->getName();
        }
        sort($classes);

        $thumbnails = [];
        foreach ((new ThumbnailConfig\Listing())->getThumbnails() as $thumbnail) {
            $thumbnails[] = $thumbnail->getName();
        }
        sort($thumbnails);

        return $this->json([
            'success' => true,
            'classes' => $classes,
            'thumbnails' => $thumbnails,
            'languages' => array_values(Tool::getValidLanguages()),
        ]);
    }

    /**
     * The fields of a class that the index can hold (ClassFieldProvider)
     */
    #[Route(path: '/classes/{className}/fields', name: 'datahub_rest_studio_ci_hub_class_fields', requirements: ['className' => '\w+'], methods: ['GET'])]
    public function fields(string $className): JsonResponse
    {
        if (!$this->getCurrentUser() instanceof User) {
            return $this->error('Access denied', Response::HTTP_FORBIDDEN);
        }

        $classDefinition = ClassDefinition::getByName($className);
        if (!$classDefinition instanceof ClassDefinition) {
            return $this->error('Unknown class', Response::HTTP_NOT_FOUND);
        }

        return $this->json(['success' => true, 'fields' => $this->classFieldProvider->getFields($classDefinition)]);
    }

    /**
     * @return array{User, Configuration}|JsonResponse
     */
    private function getConfiguration(string $name, string $permission): array|JsonResponse
    {
        $user = $this->getCurrentUser();
        if (!$user instanceof User) {
            return $this->error('Access denied', Response::HTTP_FORBIDDEN);
        }

        $configuration = Configuration::getByName($name);
        if (!$configuration instanceof Configuration || self::CONFIG_TYPE !== $configuration->getType()) {
            return $this->error('Unknown configuration', Response::HTTP_NOT_FOUND);
        }

        if (!$configuration->isAllowed($permission, $user)) {
            return $this->error('Access denied', Response::HTTP_FORBIDDEN);
        }

        return [$user, $configuration];
    }

    /**
     * @return array<string, mixed>
     */
    private function getStoredConfig(Configuration $configuration): array
    {
        /** @var array<string, mixed>|null $config */
        $config = $configuration->getConfiguration();

        return $config ?? [];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function getModificationDate(array $config): int
    {
        $general = $config['general'] ?? null;
        $date = \is_array($general) ? ($general['modificationDate'] ?? 0) : 0;

        return is_numeric($date) ? (int) $date : 0;
    }

    /**
     * The logged in Studio user, if it may open DataHub configs
     */
    private function getCurrentUser(): ?User
    {
        $securityUser = $this->getUser();
        if (!$securityUser instanceof SecurityUser) {
            return null;
        }

        $user = $securityUser->getUser();

        return $user->isAllowed(self::PERMISSION) ? $user : null;
    }

    private function error(string $message, int $status): JsonResponse
    {
        return $this->json(['success' => false, 'message' => $message], $status);
    }
}

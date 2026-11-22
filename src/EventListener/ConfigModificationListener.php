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

namespace CIHub\Bundle\SimpleRESTAdapterBundle\EventListener;

use CIHub\Bundle\SimpleRESTAdapterBundle\Elasticsearch\EndpointAndIndexesConfigurator;
use CIHub\Bundle\SimpleRESTAdapterBundle\Exception\ESClientException;
use CIHub\Bundle\SimpleRESTAdapterBundle\Manager\IndexManager;
use CIHub\Bundle\SimpleRESTAdapterBundle\Model\Event\ConfigurationEvent;
use CIHub\Bundle\SimpleRESTAdapterBundle\Reader\ConfigReader;
use CIHub\Bundle\SimpleRESTAdapterBundle\SimpleRESTAdapterEvents;
use Doctrine\DBAL\Exception;
use Pimcore\Bundle\DataHubBundle\Configuration;
use Pimcore\Bundle\DataHubBundle\Event\ConfigurationEvents;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class ConfigModificationListener implements EventSubscriberInterface
{
    private const CONFIG_TYPE = 'ciHub';

    public function __construct(
        private IndexManager $indexManager,
        private EndpointAndIndexesConfigurator $endpointAndIndexesConfigurator
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SimpleRESTAdapterEvents::CONFIGURATION_PRE_DELETE => 'onPreDelete',
            SimpleRESTAdapterEvents::CONFIGURATION_POST_SAVE => 'onPostSave',
            // The events above were dispatched by the Classic Admin configuration screen, which Pimcore 2026 removed.
            // DataHub dispatches these for every saved or deleted configuration (Studio, API, code).
            ConfigurationEvents::CONFIGURATION_POST_SAVE => 'onDataHubPostSave',
            ConfigurationEvents::CONFIGURATION_POST_DELETE => 'onDataHubPostDelete',
        ];
    }

    public function onPreDelete(ConfigurationEvent $configurationEvent): void
    {
        $configReader = new ConfigReader($configurationEvent->getConfiguration());
        $this->indexManager->deleteAllIndices($configReader->getName());
    }

    /**
     * @throws \RuntimeException
     * @throws ESClientException
     * @throws Exception
     */
    public function onPostSave(ConfigurationEvent $configurationEvent): void
    {
        $configReader = new ConfigReader($configurationEvent->getConfiguration());

        $this->endpointAndIndexesConfigurator->createOrUpdate($configReader);
    }

    /**
     * @throws \RuntimeException
     * @throws ESClientException
     * @throws Exception
     */
    public function onDataHubPostSave(GenericEvent $genericEvent): void
    {
        $configReader = $this->getCiHubConfigReader($genericEvent);
        if ($configReader instanceof ConfigReader) {
            $this->endpointAndIndexesConfigurator->createOrUpdate($configReader);
        }
    }

    public function onDataHubPostDelete(GenericEvent $genericEvent): void
    {
        $configReader = $this->getCiHubConfigReader($genericEvent);
        if ($configReader instanceof ConfigReader) {
            $this->indexManager->deleteAllIndices($configReader->getName());
        }
    }

    private function getCiHubConfigReader(GenericEvent $genericEvent): ?ConfigReader
    {
        $configuration = $genericEvent->getSubject();
        if (!$configuration instanceof Configuration || self::CONFIG_TYPE !== $configuration->getType()) {
            return null;
        }

        $config = $configuration->getConfiguration();

        return \is_array($config) ? new ConfigReader($config) : null;
    }
}

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

namespace CIHub\Bundle\SimpleRESTAdapterBundle\Tests\Integration\Controller\Studio;

use CIHub\Bundle\SimpleRESTAdapterBundle\Controller\Studio\ConfigController;
use Pimcore\Bundle\DataHubBundle\Configuration;
use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Model\User;
use Pimcore\Security\User\User as SecurityUser;
use Pimcore\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The Studio API of the "ciHub" config editor (ConfigController) against a real Pimcore (the user "admin" must exist).
 * The test configs index nothing (no assets, no classes), so no search index is created; they and the test users are
 * deleted afterwards.
 */
final class ConfigControllerTest extends KernelTestCase
{
    /**
     * @var list<Configuration|User>
     */
    private array $created = [];

    protected function setUp(): void
    {
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as $element) {
            $element->delete();
        }
        $this->created = [];

        parent::tearDown();
    }

    public function testConfigIsLoadedAndSavedKeepingNameAndType(): void
    {
        $this->logIn($this->getAdmin());
        $name = $this->createConfig();

        $loaded = $this->decode($this->getController()->get($name));
        self::assertIsArray($loaded['configuration']);
        self::assertTrue($loaded['writeable']);
        self::assertSame(['update' => true, 'delete' => true], $loaded['userPermissions']);

        /** @var array<string, mixed> $configuration */
        $configuration = $loaded['configuration'];
        $configuration['general'] = ['name' => 'other', 'type' => 'graphql', 'active' => true, 'description' => 'saved'];
        $configuration['workspaces'] = ['asset' => [['cpath' => '/', 'read' => true]], 'object' => []];
        $configuration['deliverySettings'] = ['apikey' => 'key'];

        $saved = $this->decode($this->getController()->save($name, $this->createSaveRequest(
            $configuration,
            $loaded['modificationDate']
        )));
        self::assertIsInt($saved['modificationDate']);

        $stored = Configuration::getByName($name)?->getConfiguration();
        self::assertIsArray($stored);
        self::assertSame($name, $stored['general']['name']);
        self::assertSame(ConfigController::CONFIG_TYPE, $stored['general']['type']);
        self::assertSame('saved', $stored['general']['description']);
        self::assertTrue($stored['general']['active']);
        self::assertSame([['cpath' => '/', 'read' => true]], $stored['workspaces']['asset']);
        self::assertSame('key', $stored['deliverySettings']['apikey']);
    }

    public function testSavingAnOutdatedConfigIsRefused(): void
    {
        $this->logIn($this->getAdmin());
        $name = $this->createConfig();
        $loaded = $this->decode($this->getController()->get($name));
        self::assertIsArray($loaded['configuration']);
        self::assertIsInt($loaded['modificationDate']);

        /** @var array<string, mixed> $configuration */
        $configuration = $loaded['configuration'];
        $response = $this->getController()->save($name, $this->createSaveRequest($configuration, $loaded['modificationDate'] - 1));

        self::assertSame(Response::HTTP_CONFLICT, $response->getStatusCode());
    }

    public function testOnlyCiHubConfigsAreServed(): void
    {
        $this->logIn($this->getAdmin());

        self::assertSame(Response::HTTP_NOT_FOUND, $this->getController()->get('unknown_'.uniqid())->getStatusCode());

        $graphql = new Configuration('graphql', null, 'graphql_'.uniqid());
        $graphql->save();
        $this->created[] = $graphql;
        self::assertSame(Response::HTTP_NOT_FOUND, $this->getController()->get((string) $graphql->getName())->getStatusCode());
    }

    public function testUserWithoutDataHubPermissionIsDenied(): void
    {
        $name = $this->createConfig();
        $user = User::create(['parentId' => 0, 'name' => 'ci-hub-config-test-'.uniqid(), 'active' => true]);
        $this->created[] = $user;
        $this->logIn($user);

        self::assertSame(Response::HTTP_FORBIDDEN, $this->getController()->get($name)->getStatusCode());
        self::assertSame(Response::HTTP_FORBIDDEN, $this->getController()->options()->getStatusCode());

        // The DataHub permission alone does not grant the config: the adapter permission or the config's own
        // permissions do (DataHub's rules)
        $user->setPermission('plugin_datahub_config', true);
        $user->save();
        $this->logIn($user);
        self::assertSame(Response::HTTP_FORBIDDEN, $this->getController()->get($name)->getStatusCode());

        $user->setPermission('plugin_datahub_adapter_ciHub', true);
        $user->save();
        $this->logIn($user);
        self::assertSame(Response::HTTP_OK, $this->getController()->get($name)->getStatusCode());
    }

    public function testOptionsAndFieldsAreListed(): void
    {
        $this->logIn($this->getAdmin());

        $options = $this->decode($this->getController()->options());
        self::assertIsArray($options['classes']);
        self::assertIsArray($options['thumbnails']);
        self::assertNotEmpty($options['languages']);

        $classDefinition = (new ClassDefinition\Listing())->load()[0] ?? null;
        if (!$classDefinition instanceof ClassDefinition) {
            self::markTestSkipped('The project has no class');
        }

        self::assertContains($classDefinition->getName(), $options['classes']);

        $fields = $this->decode($this->getController()->fields((string) $classDefinition->getName()))['fields'];
        self::assertIsArray($fields);
        $keys = array_column($fields, 'key');
        self::assertContains('id', $keys);
        self::assertNotContains('localizedfields', $keys);

        self::assertSame(Response::HTTP_NOT_FOUND, $this->getController()->fields('Unknown'.uniqid())->getStatusCode());
    }

    public function testLabelsOfAConfigWithoutIndexAreEmpty(): void
    {
        $this->logIn($this->getAdmin());

        self::assertSame([], $this->decode($this->getController()->labels($this->createConfig()))['labels']);
    }

    private function createConfig(): string
    {
        $name = 'ci_hub_test_'.uniqid();
        $configuration = new Configuration(ConfigController::CONFIG_TYPE, null, $name, [
            'general' => ['type' => ConfigController::CONFIG_TYPE, 'name' => $name, 'active' => false, 'modificationDate' => time() - 10],
            'schema' => ['assets' => [], 'dataObjectClasses' => []],
        ]);
        $configuration->save();
        $this->created[] = $configuration;

        return $name;
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function createSaveRequest(array $configuration, mixed $modificationDate): Request
    {
        return new Request(content: (string) json_encode([
            'configuration' => $configuration,
            'modificationDate' => $modificationDate,
        ]));
    }

    private function getController(): ConfigController
    {
        $controller = self::getContainer()->get(ConfigController::class);
        self::assertInstanceOf(ConfigController::class, $controller);

        return $controller;
    }

    private function getAdmin(): User
    {
        $admin = User::getByName('admin');
        self::assertInstanceOf(User::class, $admin);

        return $admin;
    }

    private function logIn(User $user): void
    {
        $securityUser = new SecurityUser($user);
        $tokenStorage = self::getContainer()->get(TokenStorageInterface::class);
        self::assertInstanceOf(TokenStorageInterface::class, $tokenStorage);
        $tokenStorage->setToken(new UsernamePasswordToken($securityUser, 'pimcore_studio', $securityUser->getRoles()));
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(JsonResponse $response): array
    {
        self::assertSame(Response::HTTP_OK, $response->getStatusCode(), (string) $response->getContent());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertIsArray($payload);

        /** @var array<string, mixed> $payload */
        return $payload;
    }
}

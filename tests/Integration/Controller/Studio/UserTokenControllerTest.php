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

use CIHub\Bundle\SimpleRESTAdapterBundle\Controller\Studio\UserTokenController;
use CIHub\Bundle\SimpleRESTAdapterBundle\Manager\AuthManager;
use CIHub\Bundle\SimpleRESTAdapterBundle\Manager\UserTokenManager;
use Pimcore\Model\User;
use Pimcore\Security\User\User as SecurityUser;
use Pimcore\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * The Studio API of the CI HUB tokens (UserTokenController) against a real Pimcore: boots the kernel of the project the
 * bundle is installed in and uses its database (the user "admin" must exist). Run it from the project, e.g.
 * vendor/bin/phpunit vendor/bo-hub/ci-hub-api-bundle/tests/Integration. The users it creates are deleted afterwards.
 */
final class UserTokenControllerTest extends KernelTestCase
{
    /**
     * @var list<User>
     */
    private array $createdUsers = [];

    protected function setUp(): void
    {
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdUsers as $user) {
            $user->delete();
        }
        $this->createdUsers = [];

        parent::tearDown();
    }

    public function testTokenIsGeneratedListedRenewedAndRevoked(): void
    {
        $this->logIn($this->getAdmin());
        $user = $this->createUser();
        $tokenManager = $this->getService(UserTokenManager::class);

        self::assertFalse($this->findListed($user)['hasToken']);

        $token = $this->decode($this->getController()->generate((int) $user->getId()))['token'];
        self::assertIsString($token);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        self::assertTrue($this->findListed($user)['hasToken']);

        $renewed = $this->decode($this->getController()->generate((int) $user->getId()))['token'];
        self::assertNotSame($token, $renewed);

        $this->decode($this->getController()->revoke((int) $user->getId()));
        self::assertFalse($tokenManager->hasToken($user));
        self::assertFalse($this->findListed($user)['hasToken']);
    }

    public function testGeneratedTokenAuthenticatesItsUserUntilRevoked(): void
    {
        $this->logIn($this->getAdmin());
        $user = $this->createUser();

        $token = $this->decode($this->getController()->generate((int) $user->getId()))['token'];
        self::assertIsString($token);
        self::assertSame($user->getId(), $this->createAuthManager($token)->authenticate()->getId());

        $renewed = $this->decode($this->getController()->generate((int) $user->getId()))['token'];
        self::assertIsString($renewed);
        self::assertSame($user->getId(), $this->createAuthManager($renewed)->authenticate()->getId());
        $this->assertRefused($token);

        $this->decode($this->getController()->revoke((int) $user->getId()));
        $this->assertRefused($renewed);
    }

    public function testListDoesNotContainTokensOrRoles(): void
    {
        $this->logIn($this->getAdmin());
        $user = $this->createUser();
        $this->getService(UserTokenManager::class)->generate($user);

        $content = (string) $this->getController()->index()->getContent();
        $users = $this->decode($this->getController()->index())['users'];

        self::assertIsArray($users);
        self::assertStringNotContainsString('apikey', $content);
        self::assertStringNotContainsString('token"', $content);
        self::assertSame([], array_filter(
            $users,
            static fn (mixed $entry): bool => \is_array($entry) && 0 === $entry['id']
        ));
    }

    public function testUserWithoutPermissionIsDenied(): void
    {
        $user = $this->createUser();
        $this->logIn($user);

        self::assertSame(Response::HTTP_FORBIDDEN, $this->getController()->index()->getStatusCode());
        self::assertSame(
            Response::HTTP_FORBIDDEN,
            $this->getController()->generate((int) $user->getId())->getStatusCode()
        );

        $user->setPermission(UserTokenController::PERMISSION, true);
        $user->save();
        $this->logIn($user);

        self::assertSame(Response::HTTP_OK, $this->getController()->index()->getStatusCode());
    }

    public function testOnlyAdminsChangeTokensOfAdmins(): void
    {
        $manager = $this->createUser();
        $manager->setPermission(UserTokenController::PERMISSION, true);
        $manager->save();
        $this->logIn($manager);

        $admin = (int) $this->getAdmin()->getId();
        self::assertSame(Response::HTTP_FORBIDDEN, $this->getController()->generate($admin)->getStatusCode());
        self::assertSame(Response::HTTP_FORBIDDEN, $this->getController()->revoke($admin)->getStatusCode());
        self::assertSame(
            Response::HTTP_OK,
            $this->getController()->generate((int) $this->createUser()->getId())->getStatusCode()
        );
    }

    public function testUnknownUserIsNotFound(): void
    {
        $this->logIn($this->getAdmin());

        self::assertSame(Response::HTTP_NOT_FOUND, $this->getController()->generate(999_999_999)->getStatusCode());
    }

    /**
     * @return array<string, mixed>
     */
    private function findListed(User $user): array
    {
        $users = $this->decode($this->getController()->index())['users'];
        self::assertIsArray($users);

        foreach ($users as $entry) {
            if (\is_array($entry) && $entry['id'] === $user->getId()) {
                /** @var array<string, mixed> $entry */
                return $entry;
            }
        }

        self::fail('User is not listed');
    }

    /**
     * The API's authentication (AuthManager) for a request with the token as bearer token
     */
    private function createAuthManager(string $token): AuthManager
    {
        $requestStack = new RequestStack();
        $requestStack->push(new Request(server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]));

        return new AuthManager($requestStack);
    }

    private function assertRefused(string $token): void
    {
        try {
            $this->createAuthManager($token)->authenticate();
            self::fail('The token still authenticates');
        } catch (AuthenticationException) {
            $this->addToAssertionCount(1);
        }
    }

    private function getController(): UserTokenController
    {
        return $this->getService(UserTokenController::class);
    }

    private function createUser(): User
    {
        $user = User::create(['parentId' => 0, 'name' => 'ci-hub-token-test-'.uniqid(), 'active' => true]);
        $this->createdUsers[] = $user;

        return $user;
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
        $this->getService(TokenStorageInterface::class)->setToken(
            new UsernamePasswordToken($securityUser, 'pimcore_studio', $securityUser->getRoles())
        );
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $serviceId
     *
     * @return T
     */
    private function getService(string $serviceId): object
    {
        $service = self::getContainer()->get($serviceId);
        self::assertInstanceOf($serviceId, $service);

        return $service;
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

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

use CIHub\Bundle\SimpleRESTAdapterBundle\Manager\UserTokenManager;
use Pimcore\Model\User;
use Pimcore\Security\User\User as SecurityUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * API of the Studio screen "CI HUB Tokens" (assets/studio, System > User & Roles): which users have a CI HUB token,
 * generating and revoking one. Successor of the CI HUB tab of the Classic Admin user editor. Same rules as there:
 * needs the permission "users", and only admins may change the token of an admin.
 */
#[Route(path: '/pimcore-studio/api/ci-hub/tokens')]
final class UserTokenController extends AbstractController
{
    public const PERMISSION = 'users';

    public function __construct(
        private readonly UserTokenManager $userTokenManager
    ) {
    }

    #[Route(path: '', name: 'datahub_rest_studio_ci_hub_tokens', methods: ['GET'])]
    public function index(): JsonResponse
    {
        if (! $this->getCurrentUser() instanceof User) {
            return $this->error('Access denied', Response::HTTP_FORBIDDEN);
        }

        $withToken = array_flip($this->userTokenManager->getUserIdsWithToken());

        $listing = new User\Listing();
        $listing->setCondition('`type` = ? AND `id` > 0', ['user']);
        $listing->setOrderKey('name');

        $users = [];
        foreach ($listing->getUsers() as $user) {
            if (null === $user->getId()) {
                continue;
            }

            $users[] = [
                'id' => $user->getId(),
                'name' => $user->getName(),
                'active' => $user->isActive(),
                'admin' => $user->isAdmin(),
                'hasToken' => isset($withToken[$user->getId()])
            ];
        }

        return $this->json(['success' => true, 'users' => $users]);
    }

    /**
     * Creates a new token (replaces an existing one) and returns it, it is not shown again
     */
    #[Route(
        path: '/{id}',
        name: 'datahub_rest_studio_ci_hub_token_generate',
        requirements: ['id' => '\d+'],
        methods: [
            'POST'
        ]
    )]
    public function generate(int $id): JsonResponse
    {
        $user = $this->getEditableUser($id);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return $this->json(['success' => true, 'token' => $this->userTokenManager->generate($user)]);
    }

    #[Route(
        path: '/{id}',
        name: 'datahub_rest_studio_ci_hub_token_revoke',
        requirements: ['id' => '\d+'],
        methods: [
            'DELETE'
        ]
    )]
    public function revoke(int $id): JsonResponse
    {
        $user = $this->getEditableUser($id);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $this->userTokenManager->revoke($user);

        return $this->json(['success' => true]);
    }

    private function getEditableUser(int $id): User|JsonResponse
    {
        $currentUser = $this->getCurrentUser();
        if (! $currentUser instanceof User) {
            return $this->error('Access denied', Response::HTTP_FORBIDDEN);
        }

        $user = User::getById($id);
        if (! $user instanceof User || $user->getId() < 1) {
            return $this->error('Unknown user', Response::HTTP_NOT_FOUND);
        }

        if ($user->isAdmin() && ! $currentUser->isAdmin()) {
            return $this->error('Only admin users are allowed to modify admin users', Response::HTTP_FORBIDDEN);
        }

        return $user;
    }

    /**
     * The logged in Studio user, if it may manage users
     */
    private function getCurrentUser(): ?User
    {
        $securityUser = $this->getUser();
        if (! $securityUser instanceof SecurityUser) {
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

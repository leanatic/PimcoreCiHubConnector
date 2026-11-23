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

namespace CIHub\Bundle\SimpleRESTAdapterBundle\Manager;

use CIHub\Bundle\SimpleRESTAdapterBundle\Installer;
use Doctrine\DBAL\Connection;
use Pimcore\Model\User;

/**
 * The CI HUB tokens of Pimcore users (table users_datahub_config, JSON {"apikey": "…"}). A token is the bearer token
 * of the CI HUB plugin and acts with the rights of its user (AuthManager). Tokens are never read back for display.
 */
final readonly class UserTokenManager
{
    private const TABLE = Installer::USER_DATAHUB_CONFIG_TABLE;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @return list<int> ids of the users that have a token
     */
    public function getUserIdsWithToken(): array
    {
        $ids = $this->connection->fetchFirstColumn(
            'SELECT userId FROM '.self::TABLE
            ." WHERE COALESCE(JSON_UNQUOTE(JSON_EXTRACT(data, '$.apikey')), '') NOT IN ('', 'null')"
        );

        return array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $ids);
    }

    public function hasToken(User $user): bool
    {
        return $this->getToken($this->getData($user)) !== null;
    }

    /**
     * Creates a token (64 hex characters) and replaces an existing one.
     *
     * @return string the new token; it is not shown again
     */
    public function generate(User $user): string
    {
        $data = $this->getData($user);
        $data['apikey'] = bin2hex(random_bytes(32));
        $this->saveData($user, $data);

        return $data['apikey'];
    }

    /**
     * @return bool whether the user had a token
     */
    public function revoke(User $user): bool
    {
        $data = $this->getData($user);
        if (null === $this->getToken($data)) {
            return false;
        }

        unset($data['apikey']);
        $this->saveData($user, $data);

        return true;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function getToken(array $data): ?string
    {
        $token = $data['apikey'] ?? null;

        return \is_string($token) && '' !== $token ? $token : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function getData(User $user): array
    {
        $json = $this->connection->fetchOne('SELECT data FROM '.self::TABLE.' WHERE userId = ?', [$user->getId()]);
        $data = \is_string($json) ? json_decode($json, true) : null;

        return \is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function saveData(User $user, array $data): void
    {
        $json = json_encode((object) $data, \JSON_THROW_ON_ERROR);
        $exists = false !== $this->connection->fetchOne('SELECT 1 FROM '.self::TABLE.' WHERE userId = ?', [$user->getId()]);

        if ($exists) {
            $this->connection->update(self::TABLE, ['data' => $json], ['userId' => $user->getId()]);
        } else {
            $this->connection->insert(self::TABLE, ['data' => $json, 'userId' => $user->getId()]);
        }
    }
}

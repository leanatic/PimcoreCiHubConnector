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

namespace CIHub\Bundle\SimpleRESTAdapterBundle\Command;

use CIHub\Bundle\SimpleRESTAdapterBundle\Manager\UserTokenManager;
use Pimcore\Model\User;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Manages the CI HUB tokens of Pimcore users (UserTokenManager), which the Classic Admin user editor managed before.
 */
#[AsCommand('datahub:ci-hub:token', 'List, generate or revoke the CI HUB tokens of Pimcore users')]
final class UserTokenCommand extends Command
{
    private const ACTIONS = ['list', 'generate', 'revoke'];

    public function __construct(private readonly UserTokenManager $userTokenManager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, implode('|', self::ACTIONS))
            ->addArgument('user', InputArgument::OPTIONAL, 'Pimcore user name (generate, revoke)')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'generate: replace an existing token')
            ->setHelp(<<<'HELP'
                <info>list</info>                  users with a token (the token itself is not shown)
                <info>generate</info> <user>       creates a token and prints it once; --force replaces an existing one
                <info>revoke</info> <user>         deletes the token of the user
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = $this->stringArgument($input, 'action');

        if ('list' === $action) {
            return $this->list($io);
        }

        if (!\in_array($action, self::ACTIONS, true)) {
            $io->error(sprintf('Unknown action "%s", use one of: %s', $action, implode(', ', self::ACTIONS)));

            return Command::INVALID;
        }

        $userName = $this->stringArgument($input, 'user');
        $user = '' === $userName ? null : User::getByName($userName);
        if (!$user instanceof User) {
            $io->error(sprintf('No Pimcore user "%s"', $userName));

            return Command::INVALID;
        }

        return 'generate' === $action
            ? $this->generate($io, $user, (bool) $input->getOption('force'))
            : $this->revoke($io, $user);
    }

    private function list(SymfonyStyle $io): int
    {
        $rows = [];
        foreach ($this->userTokenManager->getUserIdsWithToken() as $userId) {
            $user = User::getById($userId);
            if ($user instanceof User) {
                $name = (string) $user->getName();
                $rows[$name] = [$name, $user->isActive() ? 'yes' : 'no', $user->isAdmin() ? 'yes' : 'no'];
            }
        }
        ksort($rows);

        $io->table(['User', 'Active', 'Admin'], array_values($rows));

        return Command::SUCCESS;
    }

    private function generate(SymfonyStyle $io, User $user, bool $force): int
    {
        if ($this->userTokenManager->hasToken($user) && !$force) {
            $io->error(sprintf('"%s" already has a token, use --force to replace it', $user->getName()));

            return Command::FAILURE;
        }

        $token = $this->userTokenManager->generate($user);

        if (!$user->isActive()) {
            $io->warning(sprintf('"%s" is inactive, the token only works once the user is active', $user->getName()));
        }

        $io->success(sprintf('Token of "%s" (shown only now):', $user->getName()));
        $io->writeln($token);

        return Command::SUCCESS;
    }

    private function revoke(SymfonyStyle $io, User $user): int
    {
        if ($this->userTokenManager->revoke($user)) {
            $io->success(sprintf('Token of "%s" revoked', $user->getName()));
        } else {
            $io->note(sprintf('"%s" has no token', $user->getName()));
        }

        return Command::SUCCESS;
    }

    private function stringArgument(InputInterface $input, string $name): string
    {
        $value = $input->getArgument($name);

        return \is_string($value) ? $value : '';
    }
}

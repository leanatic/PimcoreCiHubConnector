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

namespace CIHub\Bundle\SimpleRESTAdapterBundle;

use Nelmio\ApiDocBundle\NelmioApiDocBundle;
use Pimcore\Extension\Bundle\AbstractPimcoreBundle;
use Pimcore\Extension\Bundle\Installer\InstallerInterface;
use Pimcore\Extension\Bundle\Traits\PackageVersionTrait;
use Pimcore\HttpKernel\Bundle\DependentBundleInterface;
use Pimcore\HttpKernel\BundleCollection\BundleCollection;

final class SimpleRESTAdapterBundle extends AbstractPimcoreBundle implements DependentBundleInterface
{
    use PackageVersionTrait;

    public const PACKAGE_NAME = 'bo-hub/ci-hub-api-bundle';

    public function getInstaller(): ?InstallerInterface
    {
        return $this->container->get(Installer::class);
    }

    protected function getComposerPackageName(): string
    {
        return self::PACKAGE_NAME;
    }

    public static function registerDependentBundles(BundleCollection $bundleCollection): void
    {
        $bundleCollection->addBundle(new NelmioApiDocBundle());
    }
}

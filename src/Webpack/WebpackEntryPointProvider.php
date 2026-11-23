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

namespace CIHub\Bundle\SimpleRESTAdapterBundle\Webpack;

use Pimcore\Bundle\StudioUiBundle\Webpack\WebpackEntryPointProviderInterface;

/*
 * Registers the bundle's Studio plugin (built from assets/studio into src/Resources/public/studio/build/<buildId>) with
 * Pimcore Studio. Only defined when Studio is installed (services.php registers it in that case only).
 */
if (interface_exists(WebpackEntryPointProviderInterface::class)) {
    final class WebpackEntryPointProvider implements WebpackEntryPointProviderInterface
    {
        /**
         * @return string[]
         */
        public function getEntryPointsJsonLocations(): array
        {
            $locations = glob(__DIR__.'/../Resources/public/studio/build/*/entrypoints.json');

            return false === $locations ? [] : $locations;
        }

        /**
         * @return string[]
         */
        public function getEntryPoints(): array
        {
            return [] === $this->getEntryPointsJsonLocations() ? [] : ['exposeRemote'];
        }

        /**
         * @return string[]
         */
        public function getOptionalEntryPoints(): array
        {
            return [];
        }
    }
}

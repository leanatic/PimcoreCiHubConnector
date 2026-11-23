import { type IAbstractPlugin } from '@pimcore/studio-ui-bundle'
import { TokenModule } from './modules/token-module'

/**
 * Studio plugin of the CI HUB connector: what the bundle's Classic Admin scripts (src/Resources/public/pimcore) did,
 * rebuilt for Pimcore Studio.
 */
export const CiHubPlugin: IAbstractPlugin = {
  name: 'CiHubPlugin',

  onStartup ({ moduleSystem }) {
    moduleSystem.registerModule(TokenModule)
  }
}

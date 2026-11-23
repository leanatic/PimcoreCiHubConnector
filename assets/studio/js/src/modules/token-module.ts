import { type AbstractModule, container } from '@pimcore/studio-ui-bundle'
import { serviceIds } from '@pimcore/studio-ui-bundle/app'
import { type MainNavRegistry } from '@pimcore/studio-ui-bundle/modules/app'
import { type WidgetRegistry } from '@pimcore/studio-ui-bundle/modules/widget-manager'
import { TokenWidget } from '../tokens/token-widget'

const WIDGET = 'ci-hub-tokens'

/**
 * Main navigation entry System > User & Roles > "CI HUB Tokens" (permission "users", like Users and Roles), which
 * opens the token list in a Studio tab (successor of the CI HUB tab of the Classic Admin user editor)
 */
export const TokenModule: AbstractModule = {
  onInit: (): void => {
    const widgetRegistry = container.get<WidgetRegistry>(serviceIds.widgetManager)
    widgetRegistry.registerWidget({
      name: WIDGET,
      component: TokenWidget
    })

    const mainNavRegistry = container.get<MainNavRegistry>(serviceIds.mainNavRegistry)
    mainNavRegistry.registerMainNavItem({
      path: 'System/User & Roles/CiHubTokens',
      label: 'ci_hub.tokens',
      order: 300,
      permission: 'users',
      widgetConfig: {
        name: 'CI HUB Tokens',
        id: WIDGET,
        component: WIDGET,
        config: {
          translationKey: 'ci_hub.tokens',
          icon: { type: 'name', value: 'key' }
        }
      }
    })
  }
}

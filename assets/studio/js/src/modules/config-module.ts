import { type AbstractModule, container } from '@pimcore/studio-ui-bundle'
import { bundleServiceIds, type DynamicTypeDataHubAdapterRegistry } from '@pimcore/data-hub'
import { DynamicTypeDataHubAdapterCiHub } from '../config/dynamic-type-ci-hub'

/**
 * Registers the "ciHub" config type with DataHub's configuration screen (Automation & Integration > Data Hub)
 */
export const ConfigModule: AbstractModule = {
  onInit: (): void => {
    const adapterRegistry = container.get<DynamicTypeDataHubAdapterRegistry>(
      String(bundleServiceIds['DataHub/DynamicTypes/Adapter/Registry'])
    )
    adapterRegistry.registerDynamicType(new DynamicTypeDataHubAdapterCiHub())
  }
}

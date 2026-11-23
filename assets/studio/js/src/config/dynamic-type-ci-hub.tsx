import React from 'react'
import { DynamicTypeDataHubAdapterAbstract, type DataHubAdapterDetailViewProps } from '@pimcore/data-hub'
import type { ElementIcon } from '@pimcore/studio-ui-bundle/modules/widget-manager'
import { CiHubDetailView } from './detail-view'

/**
 * The "ciHub" config type in DataHub's configuration screen: listed, created and deleted by DataHub, edited here
 */
export class DynamicTypeDataHubAdapterCiHub extends DynamicTypeDataHubAdapterAbstract {
  readonly id = 'ciHub'

  getIcon (): ElementIcon {
    return { type: 'name', value: 'share', colorToken: 'colorCodingBlue4' }
  }

  renderDetailView (props: DataHubAdapterDetailViewProps): React.JSX.Element {
    return <CiHubDetailView { ...props } />
  }
}

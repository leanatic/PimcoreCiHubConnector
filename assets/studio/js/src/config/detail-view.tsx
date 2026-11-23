import React, { useCallback, useEffect, useState } from 'react'
import { useTranslation } from '@pimcore/studio-ui-bundle/app'
import {
  BaseDetailView,
  ConfigToolbar,
  GeneralTab,
  PermissionsTab,
  trackConfigError,
  useDetailView,
  type DataHubAdapterDetailViewProps,
  type TabItem
} from '@pimcore/data-hub'
import { callCiHubApi, type CiHubResponse } from '../api'
import { transformBackendToForm, transformFormToBackend } from './transformers'
import { type CiHubConfig, type CiHubFormValues, type ConfigOptions, type ConfigResponse } from './types'
import { SchemaTab } from './tabs/schema-tab'
import { WorkspacesTab } from './tabs/workspaces-tab'
import { DeliveryTab } from './tabs/delivery-tab'
import { LabelsTab } from './tabs/labels-tab'

const EMPTY_OPTIONS: ConfigOptions = { classes: [], thumbnails: [], languages: [] }

/**
 * Editor of a "ciHub" DataHub config (ConfigController), shown by DataHub's configuration screen for this adapter.
 * Successor of the Classic Admin config panel of the bundle.
 */
export const CiHubDetailView = ({ configName, onChange, onDelete }: DataHubAdapterDetailViewProps): React.JSX.Element => {
  const { t } = useTranslation()
  const [data, setData] = useState<ConfigResponse | undefined>()
  const [options, setOptions] = useState<ConfigOptions>(EMPTY_OPTIONS)
  const [isLoading, setIsLoading] = useState(true)
  const [isSaving, setIsSaving] = useState(false)
  // Changes with every load, so the form takes over the loaded values
  const [requestId, setRequestId] = useState(0)

  const load = useCallback((): void => {
    setIsLoading(true)
    Promise.all([
      callCiHubApi<ConfigResponse & CiHubResponse>('GET', `/configs/${encodeURIComponent(configName)}`),
      callCiHubApi<ConfigOptions & CiHubResponse>('GET', '/config-options')
    ])
      .then(([config, configOptions]) => {
        setData(config)
        setOptions(configOptions)
        setRequestId((id) => id + 1)
      })
      .catch(trackConfigError)
      .finally(() => { setIsLoading(false) })
  }, [configName])

  useEffect(load, [load])

  const isWriteable = data?.writeable === true && data.userPermissions.update
  const canDelete = data?.writeable === true && data.userPermissions.delete

  const onSave = async (configuration: CiHubConfig, modificationDate: number): Promise<{ modificationDate?: number }> => {
    setIsSaving(true)
    try {
      const answer = await callCiHubApi<CiHubResponse & { modificationDate: number }>(
        'PUT',
        `/configs/${encodeURIComponent(configName)}`,
        { configuration, modificationDate }
      )
      setData((current) => current === undefined
        ? current
        : { ...current, configuration, modificationDate: answer.modificationDate })

      return { modificationDate: answer.modificationDate }
    } finally {
      setIsSaving(false)
    }
  }

  const { form, isDirty, initialValues, handleSave, handleValuesChange } = useDetailView<CiHubFormValues, CiHubConfig>({
    configName,
    configData: data?.configuration,
    modificationDate: data?.modificationDate,
    isLoading,
    requestId: String(requestId),
    isWriteable,
    transformToForm: transformBackendToForm,
    transformToBackend: transformFormToBackend,
    onSave,
    onChange
  })

  const tabs: TabItem[] = [
    { key: 'general', label: t('data-hub.tabs.general'), children: <GeneralTab adapterTypeLabel={ t('data-hub.adapter.ciHub') } /> },
    { key: 'schema', label: t('ci_hub.config.tab.schema'), children: <SchemaTab isWriteable={ isWriteable } options={ options } /> },
    { key: 'workspaces', label: t('ci_hub.config.tab.workspaces'), children: <WorkspacesTab isWriteable={ isWriteable } /> },
    { key: 'delivery', label: t('ci_hub.config.tab.delivery'), children: <DeliveryTab isWriteable={ isWriteable } /> },
    {
      key: 'labels',
      label: t('ci_hub.config.tab.labels'),
      children: <LabelsTab configName={ configName } isWriteable={ isWriteable } languages={ options.languages } />
    },
    { key: 'permissions', label: t('data-hub.tabs.permissions'), children: <PermissionsTab isWriteable={ isWriteable } /> }
  ]

  return (
    <BaseDetailView
      disabled={ !isWriteable }
      form={ form }
      initialValues={ initialValues }
      isLoading={ isLoading }
      onValuesChange={ handleValuesChange }
      requestId={ String(requestId) }
      tabs={ tabs }
      toolbar={
        <ConfigToolbar
          canDelete={ canDelete }
          configName={ configName }
          isDirty={ isDirty }
          isLoading={ isLoading }
          isSaving={ isSaving }
          isWriteable={ isWriteable }
          onDelete={ onDelete }
          onRefresh={ load }
          onSave={ handleSave }
        />
      }
    />
  )
}

import { type CiHubConfig, type CiHubFormValues, type ColumnConfig, type PermissionEntry, type WorkspaceEntry } from './types'

const isOn = (value: unknown): boolean => value === true || value === 'on'

const toWorkspaces = (entries: WorkspaceEntry[] | undefined): WorkspaceEntry[] =>
  (entries ?? []).map((entry) => ({ cpath: String(entry.cpath ?? ''), read: entry.read === true || String(entry.read) === 'true' }))

const toPermissions = (
  entries: PermissionEntry[] | undefined
): CiHubFormValues['permissions']['users'] =>
  (entries ?? []).map((entry) => ({
    id: entry.id,
    name: entry.name ?? '',
    read: entry.read ?? false,
    update: entry.update ?? false,
    delete: entry.delete ?? false
  }))

/**
 * Stored config => form values
 */
export const transformBackendToForm = (config: CiHubConfig, configName: string): CiHubFormValues => ({
  active: config.general?.active ?? false,
  name: configName,
  description: config.general?.description ?? '',
  group: config.general?.group ?? '',
  assets: {
    enabled: isOn(config.schema?.assets?.enabled),
    allowOriginalImage: isOn(config.schema?.assets?.allowOriginalImage),
    thumbnails: config.schema?.assets?.thumbnails ?? []
  },
  classes: (config.schema?.dataObjectClasses ?? []).map((schema) => ({
    name: schema.name,
    language: schema.language ?? '',
    columnConfig: schema.columnConfig ?? {}
  })),
  workspaces: {
    asset: toWorkspaces(config.workspaces?.asset),
    object: toWorkspaces(config.workspaces?.object)
  },
  apikey: config.deliverySettings?.apikey ?? '',
  labels: config.labelSettings ?? [],
  permissions: {
    roles: toPermissions(config.permissions?.role),
    users: toPermissions(config.permissions?.user)
  }
})

/**
 * Keeps the columns in the order of the object keys and numbers them like the Classic editor did
 */
const numberColumns = (columnConfig: ColumnConfig): ColumnConfig =>
  Object.fromEntries(Object.entries(columnConfig).map(([key, column], index) => [key, { ...column, position: index + 1 }]))

/**
 * Form values => stored config. Parts the editor does not show stay as they are.
 */
export const transformFormToBackend = (values: CiHubFormValues, existing: CiHubConfig): CiHubConfig => ({
  ...existing,
  general: {
    ...existing.general,
    active: values.active,
    description: values.description,
    group: values.group
  },
  schema: {
    ...existing.schema,
    assets: {
      ...existing.schema?.assets,
      enabled: values.assets.enabled ? 'on' : undefined,
      allowOriginalImage: values.assets.allowOriginalImage ? 'on' : undefined,
      thumbnails: values.assets.thumbnails ?? []
    },
    dataObjectClasses: (values.classes ?? [])
      .filter((schema) => schema.name !== '')
      .map((schema) => ({
        id: schema.name,
        name: schema.name,
        language: schema.language,
        columnConfig: numberColumns(schema.columnConfig ?? {})
      }))
  },
  workspaces: {
    ...existing.workspaces,
    asset: (values.workspaces?.asset ?? []).filter((entry) => entry.cpath !== ''),
    object: (values.workspaces?.object ?? []).filter((entry) => entry.cpath !== '')
  },
  deliverySettings: {
    ...existing.deliverySettings,
    apikey: values.apikey
  },
  labelSettings: (values.labels ?? []).filter((label) => label.id !== ''),
  permissions: {
    role: (values.permissions?.roles ?? []).map(({ id, name, read, update, delete: del }) => ({ id, name, read, update, delete: del })),
    user: (values.permissions?.users ?? []).map(({ id, name, read, update, delete: del }) => ({ id, name, read, update, delete: del }))
  }
})

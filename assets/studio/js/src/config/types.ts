/**
 * A "ciHub" DataHub config as stored (read by src/Reader/ConfigReader.php). Checkboxes of the Classic editor are stored
 * as "on" and left out when unchecked.
 */
export interface ColumnEntry {
  name: string
  position: number
  hidden: boolean
  fieldConfig: {
    key: string
    label: string
    type: string
    layout?: Record<string, unknown>
    attributes?: Record<string, unknown>
  }
  isOperator?: boolean
}

export type ColumnConfig = Record<string, ColumnEntry>

export interface ClassSchema {
  id?: string
  name: string
  language?: string
  columnConfig?: ColumnConfig
}

export interface WorkspaceEntry {
  cpath: string
  read: boolean
}

export type LabelSetting = Record<string, string | boolean | undefined> & {
  id: string
  useInAggs?: boolean
}

export interface PermissionEntry {
  id?: number
  name?: string
  read?: boolean
  update?: boolean
  delete?: boolean
}

export interface CiHubConfig {
  general?: {
    active?: boolean
    type?: string
    name?: string
    description?: string
    group?: string
    path?: string | null
    modificationDate?: number
    createDate?: number
  }
  schema?: {
    assets?: {
      enabled?: string | boolean
      allowOriginalImage?: string | boolean
      thumbnails?: string[]
    }
    dataObjectClasses?: ClassSchema[]
  }
  workspaces?: {
    asset?: WorkspaceEntry[]
    object?: WorkspaceEntry[]
  }
  deliverySettings?: {
    apikey?: string
  }
  labelSettings?: LabelSetting[]
  permissions?: {
    user?: PermissionEntry[]
    role?: PermissionEntry[]
  }
  [key: string]: unknown
}

export interface ConfigResponse {
  configuration: CiHubConfig
  modificationDate: number
  writeable: boolean
  userPermissions: { update: boolean, delete: boolean }
}

export interface ConfigOptions {
  classes: string[]
  thumbnails: string[]
  languages: string[]
}

export interface ClassField {
  key: string
  label: string
  type: string
  group: string
  integer?: boolean
}

/**
 * The form of the editor. General and permissions use the field names of DataHub's GeneralTab and PermissionsTab.
 */
export interface CiHubFormValues {
  active: boolean
  name: string
  description: string
  group: string
  assets: {
    enabled: boolean
    allowOriginalImage: boolean
    thumbnails: string[]
  }
  classes: Array<{ name: string, language: string, columnConfig: ColumnConfig }>
  workspaces: {
    asset: WorkspaceEntry[]
    object: WorkspaceEntry[]
  }
  apikey: string
  labels: LabelSetting[]
  permissions: {
    roles: Array<{ id?: number, name: string, read: boolean, update: boolean, delete: boolean }>
    users: Array<{ id?: number, name: string, read: boolean, update: boolean, delete: boolean }>
  }
}

import React, { useEffect, useMemo, useState } from 'react'
import { Select } from '@pimcore/studio-ui-bundle/components'
import { useTranslation } from '@pimcore/studio-ui-bundle/app'
import { callCiHubApi, type CiHubResponse } from '../../api'
import { type ClassField, type ColumnConfig, type ColumnEntry } from '../types'

interface FieldsResponse extends CiHubResponse {
  fields: ClassField[]
}

interface ClassFieldsSelectProps {
  className: string
  value?: ColumnConfig
  onChange?: (value: ColumnConfig) => void
  disabled?: boolean
}

const createColumn = (field: ClassField): ColumnEntry => ({
  name: field.key,
  position: 0,
  hidden: false,
  fieldConfig: {
    key: field.key,
    label: field.label,
    type: field.type,
    // DataObjectMapping maps numeric fields as integer or float from here
    ...(field.integer === undefined ? {} : { layout: { integer: field.integer } })
  },
  isOperator: false
})

/**
 * The fields of a class schema ("columnConfig" in the Classic format the bundle reads). Columns the list does not offer
 * (Classic operators, classification store fields) stay selected until removed.
 */
export const ClassFieldsSelect = ({ className, value, onChange, disabled }: ClassFieldsSelectProps): React.JSX.Element => {
  const { t } = useTranslation()
  const [fields, setFields] = useState<ClassField[]>([])
  const [loading, setLoading] = useState(false)

  useEffect(() => {
    if (className === '') {
      setFields([])
      return
    }

    setLoading(true)
    callCiHubApi<FieldsResponse>('GET', `/classes/${encodeURIComponent(className)}/fields`)
      .then((answer) => { setFields(answer.fields) })
      .catch(() => { setFields([]) })
      .finally(() => { setLoading(false) })
  }, [className])

  const columns = value ?? {}

  const options = useMemo(() => {
    const groups = new Map<string, Array<{ value: string, label: string }>>()
    for (const field of fields) {
      const group = groups.get(field.group) ?? []
      group.push({ value: field.key, label: field.label === field.key ? field.key : `${field.label} (${field.key})` })
      groups.set(field.group, group)
    }

    const known = new Set(fields.map((field) => field.key))
    const other = Object.keys(columns).filter((key) => !known.has(key)).map((key) => ({ value: key, label: key }))
    if (other.length > 0) {
      groups.set('other', other)
    }

    return [...groups.entries()].map(([group, groupOptions]) => ({
      label: t(`ci_hub.config.field_group.${group}`, { defaultValue: group }),
      options: groupOptions
    }))
  }, [fields, columns, t])

  const change = (keys: string[]): void => {
    const next: ColumnConfig = {}
    for (const key of keys) {
      const field = fields.find((entry) => entry.key === key)
      const column = columns[key] ?? (field === undefined ? undefined : createColumn(field))
      if (column !== undefined) {
        next[key] = column
      }
    }
    onChange?.(next)
  }

  return (
    <Select
      disabled={ disabled === true || className === '' }
      loading={ loading }
      mode="multiple"
      onChange={ change }
      optionFilterProp="label"
      options={ options }
      placeholder={ t('ci_hub.config.fields_placeholder') }
      style={ { width: '100%' } }
      value={ Object.keys(columns) }
    />
  )
}

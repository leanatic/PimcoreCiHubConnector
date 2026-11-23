import React, { useState } from 'react'
import { Button, Checkbox, Flex, Form, FormKit, IconButton, Input, Text, useMessage } from '@pimcore/studio-ui-bundle/components'
import { useTranslation } from '@pimcore/studio-ui-bundle/app'
import { callCiHubApi, type CiHubResponse } from '../../api'
import { type LabelSetting } from '../types'

interface LabelsResponse extends CiHubResponse {
  labels: string[]
}

interface LabelsTabProps {
  configName: string
  languages: string[]
  isWriteable: boolean
}

/**
 * Labels per language for the fields of the index ("labelSettings"); the API returns them with the results
 */
export const LabelsTab = ({ configName, languages, isWriteable }: LabelsTabProps): React.JSX.Element => {
  const { t } = useTranslation()
  const form = Form.useFormInstance()
  const messageApi = useMessage()
  const [loading, setLoading] = useState(false)

  const loadFromIndex = (): void => {
    setLoading(true)
    callCiHubApi<LabelsResponse>('GET', `/configs/${encodeURIComponent(configName)}/labels`)
      .then(({ labels }) => {
        const current = (form.getFieldValue('labels') ?? []) as LabelSetting[]
        const known = new Set(current.map((label) => label.id))
        const added = labels.filter((id) => !known.has(id)).map((id): LabelSetting => ({ id, useInAggs: false }))
        form.setFieldsValue({ labels: [...current, ...added] }, { triggerChange: true })
        void messageApi.success(t('ci_hub.config.labels_loaded', { count: added.length }))
      })
      .catch((error: unknown) => { void messageApi.error(error instanceof Error ? error.message : String(error)) })
      .finally(() => { setLoading(false) })
  }

  return (
    <FormKit.Panel contentPadding="extra-small">
      <Flex align="center" justify="space-between">
        <Text type="secondary">{ t('ci_hub.config.labels_hint') }</Text>
        { isWriteable && (
          <Button loading={ loading } onClick={ loadFromIndex }>{ t('ci_hub.config.labels_load') }</Button>
        ) }
      </Flex>
      <Form.List name="labels">
        { (items, { remove }) => (
          <div style={ { overflowX: 'auto', marginTop: 8 } }>
            <table style={ { borderCollapse: 'collapse', width: '100%' } }>
              <thead>
                <tr>
                  <th style={ { textAlign: 'left', padding: 4, minWidth: 220 } }>{ t('ci_hub.config.label_key') }</th>
                  { languages.map((language) => (
                    <th key={ language } style={ { textAlign: 'left', padding: 4 } }>{ language }</th>
                  )) }
                  <th style={ { padding: 4 } }>{ t('ci_hub.config.label_use_in_aggs') }</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                { items.map(({ key, name }) => (
                  <tr key={ key }>
                    <td style={ { padding: 4 } }>
                      <Form.Item name={ [name, 'id'] } style={ { marginBottom: 0 } }>
                        <Input disabled />
                      </Form.Item>
                    </td>
                    { languages.map((language) => (
                      <td key={ language } style={ { padding: 4 } }>
                        <Form.Item name={ [name, language] } style={ { marginBottom: 0 } }>
                          <Input />
                        </Form.Item>
                      </td>
                    )) }
                    <td style={ { padding: 4, textAlign: 'center' } }>
                      <Form.Item name={ [name, 'useInAggs'] } style={ { marginBottom: 0 } } valuePropName="checked">
                        <Checkbox />
                      </Form.Item>
                    </td>
                    <td style={ { padding: 4 } }>
                      { isWriteable && (
                        <IconButton
                          icon={ { value: 'trash' } }
                          onClick={ () => { remove(name) } }
                          tooltip={ { title: t('ci_hub.config.remove') } }
                        />
                      ) }
                    </td>
                  </tr>
                )) }
              </tbody>
            </table>
          </div>
        ) }
      </Form.List>
    </FormKit.Panel>
  )
}

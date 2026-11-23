import React from 'react'
import { Button, Flex, Form, FormKit, IconButton, Select, Switch, Text } from '@pimcore/studio-ui-bundle/components'
import { useTranslation } from '@pimcore/studio-ui-bundle/app'
import { type ConfigOptions } from '../types'
import { ClassFieldsSelect } from './class-fields-select'

interface SchemaTabProps {
  options: ConfigOptions
  isWriteable: boolean
}

/**
 * What the config indexes: assets (with original and thumbnails) and data object classes with their fields
 */
export const SchemaTab = ({ options, isWriteable }: SchemaTabProps): React.JSX.Element => {
  const { t } = useTranslation()
  const form = Form.useFormInstance()
  const classes = (Form.useWatch('classes', form) ?? []) as Array<{ name?: string }>
  const usedClasses = new Set(classes.map((schema) => schema?.name))

  return (
    <FormKit.Panel contentPadding="extra-small">
      <FormKit.Panel contentPadding="extra-small" title={ t('ci_hub.config.assets') }>
        <Form.Item name={ ['assets', 'enabled'] } valuePropName="checked">
          <Switch labelRight={ t('ci_hub.config.assets_enabled') } />
        </Form.Item>
        <Form.Item name={ ['assets', 'allowOriginalImage'] } valuePropName="checked">
          <Switch labelRight={ t('ci_hub.config.assets_original') } />
        </Form.Item>
        <Form.Item label={ t('ci_hub.config.assets_thumbnails') } name={ ['assets', 'thumbnails'] }>
          <Select
            mode="multiple"
            options={ options.thumbnails.map((thumbnail) => ({ value: thumbnail, label: thumbnail })) }
          />
        </Form.Item>
      </FormKit.Panel>

      <FormKit.Panel contentPadding="extra-small" title={ t('ci_hub.config.classes') }>
        <Text type="secondary">{ t('ci_hub.config.classes_hint') }</Text>
        <Form.List name="classes">
          { (items, { add, remove }) => (
            <Flex gap="small" style={ { marginTop: 8 } } vertical>
              { items.map(({ key, name }) => (
                <Flex
                  gap="small"
                  key={ key }
                  style={ { padding: 8, border: '1px solid var(--ant-color-border-secondary, #eee)', borderRadius: 6 } }
                  vertical
                >
                  <Flex align="flex-start" gap="small">
                    <Form.Item label={ t('ci_hub.config.class') } name={ [name, 'name'] } style={ { flex: 1, minWidth: 0, marginBottom: 0 } }>
                      <Select
                        style={ { width: '100%' } }
                        options={ options.classes.map((className) => ({
                          value: className,
                          label: className,
                          disabled: usedClasses.has(className) && classes[name]?.name !== className
                        })) }
                        showSearch
                      />
                    </Form.Item>
                    <Form.Item label={ t('ci_hub.config.language') } name={ [name, 'language'] } style={ { width: 160, marginBottom: 0 } }>
                      <Select
                        style={ { width: '100%' } }
                        options={ [
                          { value: '', label: t('ci_hub.config.language_default') },
                          ...options.languages.map((language) => ({ value: language, label: language }))
                        ] }
                      />
                    </Form.Item>
                    { isWriteable && (
                      <IconButton
                        icon={ { value: 'trash' } }
                        onClick={ () => { remove(name) } }
                        style={ { marginTop: 30 } }
                        tooltip={ { title: t('ci_hub.config.remove') } }
                      />
                    ) }
                  </Flex>
                  <Form.Item label={ t('ci_hub.config.fields') } name={ [name, 'columnConfig'] } style={ { marginBottom: 0 } }>
                    <ClassFieldsSelect className={ String(classes[name]?.name ?? '') } disabled={ !isWriteable } />
                  </Form.Item>
                </Flex>
              )) }
              { isWriteable && (
                <div>
                  <Button onClick={ () => { add({ name: '', language: '', columnConfig: {} }) } }>
                    { t('ci_hub.config.add_class') }
                  </Button>
                </div>
              ) }
            </Flex>
          ) }
        </Form.List>
      </FormKit.Panel>
    </FormKit.Panel>
  )
}

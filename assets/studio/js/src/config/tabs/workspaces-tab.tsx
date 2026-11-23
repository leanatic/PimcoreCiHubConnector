import React from 'react'
import { Button, Checkbox, Flex, Form, FormKit, IconButton, Input, Text } from '@pimcore/studio-ui-bundle/components'
import { useTranslation } from '@pimcore/studio-ui-bundle/app'

interface WorkspacesTabProps {
  isWriteable: boolean
}

const WorkspaceList = ({ type, isWriteable }: { type: 'asset' | 'object', isWriteable: boolean }): React.JSX.Element => {
  const { t } = useTranslation()

  return (
    <Form.List name={ ['workspaces', type] }>
      { (items, { add, remove }) => (
        <Flex gap="small" vertical>
          { items.map(({ key, name }) => (
            <Flex align="center" gap="small" key={ key }>
              <Form.Item name={ [name, 'cpath'] } style={ { flex: 1, marginBottom: 0 } }>
                <Input placeholder="/" />
              </Form.Item>
              <Form.Item name={ [name, 'read'] } style={ { marginBottom: 0 } } valuePropName="checked">
                <Checkbox>{ t('ci_hub.config.workspace_read') }</Checkbox>
              </Form.Item>
              { isWriteable && (
                <IconButton
                  icon={ { value: 'trash' } }
                  onClick={ () => { remove(name) } }
                  tooltip={ { title: t('ci_hub.config.remove') } }
                />
              ) }
            </Flex>
          )) }
          { isWriteable && (
            <div>
              <Button onClick={ () => { add({ cpath: '/', read: true }) } }>{ t('ci_hub.config.add_workspace') }</Button>
            </div>
          ) }
        </Flex>
      ) }
    </Form.List>
  )
}

/**
 * The folders the config serves (WorkspaceQuery): the most specific path decides, without any path nothing is served
 */
export const WorkspacesTab = ({ isWriteable }: WorkspacesTabProps): React.JSX.Element => {
  const { t } = useTranslation()

  return (
    <FormKit.Panel contentPadding="extra-small">
      <Text type="secondary">{ t('ci_hub.config.workspaces_hint') }</Text>
      <FormKit.Panel contentPadding="extra-small" title={ t('ci_hub.config.workspaces_assets') }>
        <WorkspaceList isWriteable={ isWriteable } type="asset" />
      </FormKit.Panel>
      <FormKit.Panel contentPadding="extra-small" title={ t('ci_hub.config.workspaces_objects') }>
        <WorkspaceList isWriteable={ isWriteable } type="object" />
      </FormKit.Panel>
    </FormKit.Panel>
  )
}

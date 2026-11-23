import React from 'react'
import { Button, Flex, Form, FormKit, Input, Text } from '@pimcore/studio-ui-bundle/components'
import { useTranslation } from '@pimcore/studio-ui-bundle/app'

interface DeliveryTabProps {
  isWriteable: boolean
}

const generateKey = (): string =>
  Array.from(crypto.getRandomValues(new Uint8Array(32)))
    .map((byte) => byte.toString(16).padStart(2, '0'))
    .join('')

/**
 * The API key of the config ("deliverySettings.apikey"): CI HUB clients set up for Simple REST send it as bearer token
 * to /pimcore-datahub-webservices/simplerest/<config>/… (read only). Users of the other endpoints need a user token
 * (System > User & Roles > CI HUB Tokens).
 */
export const DeliveryTab = ({ isWriteable }: DeliveryTabProps): React.JSX.Element => {
  const { t } = useTranslation()
  const form = Form.useFormInstance()

  return (
    <FormKit.Panel contentPadding="extra-small">
      <Text type="secondary">{ t('ci_hub.config.apikey_hint') }</Text>
      <Flex align="flex-end" gap="small" style={ { marginTop: 8 } }>
        <Form.Item label={ t('ci_hub.config.apikey') } name="apikey" style={ { flex: 1, marginBottom: 0 } }>
          <Input />
        </Form.Item>
        { isWriteable && (
          <Button onClick={ () => { form.setFieldsValue({ apikey: generateKey() }, { triggerChange: true }) } }>
            { t('ci_hub.config.apikey_generate') }
          </Button>
        ) }
      </Flex>
      <Text type="secondary">{ t('ci_hub.config.apikey_change_hint') }</Text>
    </FormKit.Panel>
  )
}

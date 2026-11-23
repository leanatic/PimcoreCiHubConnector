import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import {
  Alert,
  Button,
  Content,
  Empty,
  Flex,
  Icon,
  Input,
  Modal,
  Tag,
  Text,
  Toolbar,
  useFormModal,
  useMessage
} from '@pimcore/studio-ui-bundle/components'
import { useTranslation } from '@pimcore/studio-ui-bundle/app'
import { callCiHubApi, type CiHubResponse } from '../api'

const API = '/tokens'

interface TokenUser {
  id: number
  name: string
  active: boolean
  admin: boolean
  hasToken: boolean
}

interface UsersResponse extends CiHubResponse {
  users: TokenUser[]
}

interface TokenResponse extends CiHubResponse {
  token: string
}

/**
 * Studio tab "CI HUB Tokens" (System > User & Roles): which users have a CI HUB token, generating (shown once) and
 * revoking one (Controller\Studio\UserTokenController). Successor of the CI HUB tab of the Classic Admin user editor.
 */
export const TokenWidget = (): React.JSX.Element => {
  const { t } = useTranslation()
  const messageApi = useMessage()
  const modal = useFormModal()

  const [users, setUsers] = useState<TokenUser[] | null>(null)
  const [filter, setFilter] = useState('')
  const [busy, setBusy] = useState<number | null>(null)
  const [newToken, setNewToken] = useState<{ name: string, token: string } | null>(null)

  // Studio's message API is a new object on every render: kept in a ref, so loading does not depend on it
  const messageRef = useRef(messageApi)
  messageRef.current = messageApi

  const fail = useCallback((error: unknown): void => {
    void messageRef.current.error(error instanceof Error ? error.message : String(error))
  }, [])

  const load = useCallback((): void => {
    callCiHubApi<UsersResponse>('GET', API)
      .then((answer) => { setUsers(answer.users) })
      .catch(fail)
  }, [fail])

  useEffect(load, [load])

  const visible = useMemo(() => {
    const needle = filter.trim().toLowerCase()
    return (users ?? []).filter((user) => needle === '' || user.name.toLowerCase().includes(needle))
  }, [users, filter])

  const run = (user: TokenUser, action: () => Promise<void>): void => {
    setBusy(user.id)
    action()
      .then(load)
      .catch(fail)
      .finally(() => { setBusy(null) })
  }

  const generate = (user: TokenUser): void => {
    const doGenerate = (): void => {
      run(user, async () => {
        const answer = await callCiHubApi<TokenResponse>('POST', `${API}/${user.id}`)
        setNewToken({ name: user.name, token: answer.token })
      })
    }

    if (!user.hasToken) {
      doGenerate()
      return
    }

    modal.confirm({
      title: t('ci_hub.tokens.renew'),
      content: t('ci_hub.tokens.renew_question', { name: user.name }),
      onOk: doGenerate
    })
  }

  const revoke = (user: TokenUser): void => {
    modal.confirm({
      title: t('ci_hub.tokens.revoke'),
      content: t('ci_hub.tokens.revoke_question', { name: user.name }),
      onOk: () => {
        run(user, async () => {
          await callCiHubApi('DELETE', `${API}/${user.id}`)
          void messageApi.success(t('ci_hub.tokens.revoked', { name: user.name }))
        })
      }
    })
  }

  if (users === null) {
    return <Content loading padded />
  }

  return (
    <Flex style={ { height: '100%' } } vertical>
      <Toolbar justify="space-between" position="top">
        <Input
          allowClear
          onChange={ (event) => { setFilter(event.target.value) } }
          placeholder={ t('ci_hub.tokens.search') }
          style={ { maxWidth: 320 } }
          value={ filter }
        />
        <Text type="secondary">{ t('ci_hub.tokens.hint') }</Text>
      </Toolbar>

      <Content overflow={ { x: 'auto', y: 'auto' } } padded>
        { visible.length === 0 && <Empty /> }
        { visible.map((user) => (
          <Flex
            align="center"
            gap="small"
            justify="space-between"
            key={ user.id }
            style={ { padding: '8px 0', borderBottom: '1px solid var(--ant-color-border-secondary, #eee)' } }
          >
            <Flex align="center" gap="small">
              <Text strong>{ user.name }</Text>
              { user.admin && <Tag>{ t('ci_hub.tokens.admin') }</Tag> }
              { !user.active && <Tag color="warning">{ t('ci_hub.tokens.inactive') }</Tag> }
            </Flex>
            <Flex align="center" gap="small">
              { user.hasToken
                ? <Tag color="success">{ t('ci_hub.tokens.has_token') }</Tag>
                : <Text type="secondary">{ t('ci_hub.tokens.no_token') }</Text> }
              <Button
                icon={ <Icon value="refresh" /> }
                loading={ busy === user.id }
                onClick={ () => { generate(user) } }
              >
                { t(user.hasToken ? 'ci_hub.tokens.renew' : 'ci_hub.tokens.generate') }
              </Button>
              <Button
                danger
                disabled={ !user.hasToken || busy === user.id }
                icon={ <Icon value="trash" /> }
                onClick={ () => { revoke(user) } }
              >
                { t('ci_hub.tokens.revoke') }
              </Button>
            </Flex>
          </Flex>
        )) }
      </Content>

      <Modal
        footer={ <Button onClick={ () => { setNewToken(null) } } type="primary">{ t('ci_hub.tokens.close') }</Button> }
        onCancel={ () => { setNewToken(null) } }
        open={ newToken !== null }
        title={ t('ci_hub.tokens.new_token', { name: newToken?.name ?? '' }) }
      >
        <Flex gap="normal" vertical>
          <Alert message={ t('ci_hub.tokens.shown_once') } showIcon type="warning" />
          <Text code copyable>{ newToken?.token ?? '' }</Text>
        </Flex>
      </Modal>
    </Flex>
  )
}

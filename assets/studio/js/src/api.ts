import { appConfig } from '@pimcore/studio-ui-bundle/app'

/**
 * Answer of the bundle's Studio endpoints (src/Controller/Studio), or Studio's own error answer, which also carries
 * `message`.
 */
export interface CiHubResponse {
  success?: boolean
  message?: string
}

/**
 * Calls /pimcore-studio/api/ci-hub/<path>; Studio's session cookie authenticates the request. Rejects with the answer's
 * message when the request fails or the answer says `success: false`.
 */
export const callCiHubApi = async <T extends CiHubResponse = CiHubResponse>(
  method: 'GET' | 'POST' | 'DELETE',
  path: string
): Promise<T> => {
  const prefix = (appConfig?.apiPrefix ?? '/pimcore-studio/api').replace(/\/$/, '')

  const response = await fetch(`${prefix}/ci-hub${path}`, { method, credentials: 'same-origin' })

  let payload = {} as T
  try {
    payload = await response.json() as T
  } catch {
    // Not JSON (e.g. a proxy error page): fall back to the status text below.
  }

  if (!response.ok || payload.success === false) {
    throw new Error(payload.message ?? `${response.status} ${response.statusText}`)
  }

  return payload
}

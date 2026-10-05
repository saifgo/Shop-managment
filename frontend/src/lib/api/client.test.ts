import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { authFetch, setAuthFailureHandler, setRefreshHandler } from '@/lib/api/client'
import { clearTokens, storeTokens } from '@/lib/auth/storage'
import { formatWithUnit } from '@/lib/format'

function respond(status: number): Response {
  return new Response('{}', { status, headers: { 'Content-Type': 'application/json' } })
}

function bearer(call: unknown[]): string | null {
  return new Headers((call[1] as RequestInit).headers).get('Authorization')
}

describe('authFetch', () => {
  const fetchMock = vi.fn<typeof fetch>()

  beforeEach(() => {
    vi.stubGlobal('fetch', fetchMock)
    storeTokens({ accessToken: 'stale-token', refreshToken: 'refresh-token' })
  })

  afterEach(() => {
    fetchMock.mockReset()
    vi.unstubAllGlobals()
    clearTokens()
    setRefreshHandler(async () => null)
    setAuthFailureHandler(() => undefined)
  })

  it('sends the stored access token', async () => {
    fetchMock.mockResolvedValueOnce(respond(200))

    await authFetch('/api/reports/stock')

    expect(bearer(fetchMock.mock.calls[0])).toBe('Bearer stale-token')
  })

  it('refreshes an expired session once and retries with the new token', async () => {
    const refresh = vi.fn(async () => 'fresh-token')
    setRefreshHandler(refresh)
    fetchMock.mockResolvedValueOnce(respond(401)).mockResolvedValueOnce(respond(200))

    const response = await authFetch('/api/orders', { method: 'POST', body: '{}' })

    expect(response.status).toBe(200)
    expect(refresh).toHaveBeenCalledTimes(1)
    expect(fetchMock).toHaveBeenCalledTimes(2)
    expect(bearer(fetchMock.mock.calls[1])).toBe('Bearer fresh-token')
    expect((fetchMock.mock.calls[1][1] as RequestInit).method).toBe('POST')
  })

  it('signs the user out when the session cannot be refreshed', async () => {
    const failed = vi.fn()
    setRefreshHandler(async () => null)
    setAuthFailureHandler(failed)
    fetchMock.mockResolvedValueOnce(respond(401))

    const response = await authFetch('/api/orders')

    expect(response.status).toBe(401)
    expect(failed).toHaveBeenCalledTimes(1)
    expect(fetchMock).toHaveBeenCalledTimes(1)
  })

  it('does not retry forever when the refreshed token is rejected too', async () => {
    setRefreshHandler(async () => 'fresh-token')
    fetchMock.mockResolvedValue(respond(401))

    const response = await authFetch('/api/orders')

    expect(response.status).toBe(401)
    expect(fetchMock).toHaveBeenCalledTimes(2)
  })

  it('leaves other errors alone', async () => {
    const refresh = vi.fn(async () => 'fresh-token')
    setRefreshHandler(refresh)
    fetchMock.mockResolvedValueOnce(respond(403))

    expect((await authFetch('/api/orders')).status).toBe(403)
    expect(refresh).not.toHaveBeenCalled()
  })
})

describe('formatWithUnit', () => {
  it('shows a quantity with its unit', () => {
    expect(formatWithUnit('12.5000', 'kg', 4)).toBe('12.5 kg')
    expect(formatWithUnit('3.0000', 'pc')).toBe('3 pc')
  })

  it('keeps the precision recipes need', () => {
    expect(formatWithUnit('0.0333', 'kg', 4)).toBe('0.0333 kg')
    expect(formatWithUnit('0.0333', 'kg')).toBe('0.03 kg')
  })

  it('shows a dash when there is no quantity', () => {
    expect(formatWithUnit(null, 'kg')).toBe('—')
  })
})

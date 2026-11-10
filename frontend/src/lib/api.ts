const apiBaseUrl = import.meta.env.VITE_API_BASE_URL || '/api/v1'
let accessToken: string | null = null

export function setApiAccessToken(token: string | null): void {
  accessToken = token
}

export class ApiError extends Error {
  readonly status: number
  readonly responseBody: unknown

  constructor(
    message: string,
    status: number,
    responseBody: unknown = null,
  ) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.responseBody = responseBody
  }
}

export async function apiRequest<T>(
  path: string,
  options: RequestInit = {},
): Promise<T> {
  const headers = new Headers(options.headers)
  headers.set('Accept', 'application/json')

  if (accessToken) {
    headers.set('Authorization', `Bearer ${accessToken}`)
  }

  if (
    options.body
    && !(options.body instanceof FormData)
    && !headers.has('Content-Type')
  ) {
    headers.set('Content-Type', 'application/json')
  }

  const response = await fetch(`${apiBaseUrl}${path.startsWith('/') ? path : `/${path}`}`, {
    ...options,
    headers,
  })

  if (!response.ok) {
    let message = `Request failed with status ${response.status}.`
    let responseBody: unknown = null
    try {
      responseBody = await response.json()
      if (typeof responseBody === 'object' && responseBody !== null && 'message' in responseBody) {
        const apiMessage = responseBody.message
        if (typeof apiMessage === 'string') {
          message = apiMessage
        }
      }
      if (
        typeof responseBody === 'object'
        && responseBody !== null
        && 'errors' in responseBody
        && typeof responseBody.errors === 'object'
        && responseBody.errors !== null
      ) {
        const firstError = Object.values(responseBody.errors).flat()[0]
        if (typeof firstError === 'string') message = firstError
      }
    } catch {
      // Keep the status-based message when the server does not return JSON.
    }
    throw new ApiError(message, response.status, responseBody)
  }

  if (response.status === 204) {
    return undefined as T
  }

  return response.json() as Promise<T>
}

export async function apiDownload(path: string, filename: string): Promise<void> {
  const headers = new Headers({ Accept: 'application/octet-stream' })
  if (accessToken) headers.set('Authorization', `Bearer ${accessToken}`)

  const response = await fetch(`${apiBaseUrl}${path.startsWith('/') ? path : `/${path}`}`, { headers })
  if (!response.ok) {
    let responseBody: unknown = null
    let message = `Request failed with status ${response.status}.`
    try {
      responseBody = await response.json()
      if (typeof responseBody === 'object' && responseBody !== null && 'message' in responseBody) {
        const apiMessage = responseBody.message
        if (typeof apiMessage === 'string') message = apiMessage
      }
    } catch {
      // Keep the status-based message when the server does not return JSON.
    }
    throw new ApiError(message, response.status, responseBody)
  }

  const blob = await response.blob()
  const fileExtension = ({
    'application/pdf': 'pdf',
    'image/jpeg': 'jpg',
    'image/png': 'png',
  } as Record<string, string>)[blob.type] ?? 'bin'
  const downloadName = filename.includes('.') ? filename : `${filename}.${fileExtension}`
  const objectUrl = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = objectUrl
  link.download = downloadName
  document.body.append(link)
  link.click()
  link.remove()
  window.setTimeout(() => URL.revokeObjectURL(objectUrl), 0)
}

export interface ApiErrorBody {
  error?: {
    code: string;
    message: string;
    correlation_id?: string;
    details?: Array<{ field?: string; message: string }>;
  };
}

const baseURL = import.meta.env.VITE_API_BASE_URL ?? '';

/** Resolves API-relative URLs (e.g. uploaded pictures at /api/media/…) against the API host. */
export function apiUrl(url: string): string {
  return url.startsWith('/') ? `${baseURL}${url}` : url;
}

export class ApiError extends Error {
  constructor(public readonly body: ApiErrorBody) {
    super(body.error?.message ?? 'Request failed');
    this.name = 'ApiError';
  }
}

type RefreshHandler = () => Promise<string | null>;

let refreshHandler: RefreshHandler | null = null;
let authFailureHandler: (() => void) | null = null;

export function setRefreshHandler(handler: RefreshHandler): void {
  refreshHandler = handler;
}

export function setAuthFailureHandler(handler: () => void): void {
  authFailureHandler = handler;
}

async function request<T>(path: string, init?: RequestInit, retry = true): Promise<T> {
  const correlationId = crypto.randomUUID();
  const url = `${baseURL}${path}`;

  const headers: Record<string, string> = {
    Accept: 'application/json',
    // FormData bodies need the browser to set the multipart boundary itself.
    ...(init?.body instanceof FormData ? {} : { 'Content-Type': 'application/json' }),
    'X-Correlation-Id': correlationId,
    ...(init?.headers as Record<string, string> | undefined),
  };

  const response = await fetch(url, {
    ...init,
    headers,
  });

  if (response.status === 401 && retry && refreshHandler && !path.includes('/api/auth/login')) {
    const newAccessToken = await refreshHandler();

    if (newAccessToken) {
      return request<T>(path, {
        ...init,
        headers: {
          ...headers,
          Authorization: `Bearer ${newAccessToken}`,
        },
      }, false);
    }

    authFailureHandler?.();
  }

  if (!response.ok) {
    let body: ApiErrorBody;
    try {
      body = (await response.json()) as ApiErrorBody;
    } catch {
      body = {
        error: {
          code: 'HTTP_ERROR',
          message: `Request failed with status ${response.status}`,
        },
      };
    }
    throw new ApiError(body);
  }

  if (response.status === 204) {
    return undefined as T;
  }

  return (await response.json()) as T;
}

export const apiClient = {
  get<T>(path: string, accessToken?: string): Promise<T> {
    return request<T>(path, {
      method: 'GET',
      headers: accessToken ? { Authorization: `Bearer ${accessToken}` } : undefined,
    });
  },

  post<T>(path: string, body?: unknown, accessToken?: string, extraHeaders?: Record<string, string>): Promise<T> {
    return request<T>(path, {
      method: 'POST',
      body: body instanceof FormData ? body : body !== undefined ? JSON.stringify(body) : undefined,
      headers: {
        ...(accessToken ? { Authorization: `Bearer ${accessToken}` } : {}),
        ...extraHeaders,
      },
    });
  },

  patch<T>(path: string, body?: unknown, accessToken?: string): Promise<T> {
    return request<T>(path, {
      method: 'PATCH',
      body: body !== undefined ? JSON.stringify(body) : undefined,
      headers: accessToken ? { Authorization: `Bearer ${accessToken}` } : undefined,
    });
  },

  put<T>(path: string, body?: unknown, accessToken?: string): Promise<T> {
    return request<T>(path, {
      method: 'PUT',
      body: body !== undefined ? JSON.stringify(body) : undefined,
      headers: accessToken ? { Authorization: `Bearer ${accessToken}` } : undefined,
    });
  },

  delete<T>(path: string, accessToken?: string): Promise<T> {
    return request<T>(path, {
      method: 'DELETE',
      headers: accessToken ? { Authorization: `Bearer ${accessToken}` } : undefined,
    });
  },
};

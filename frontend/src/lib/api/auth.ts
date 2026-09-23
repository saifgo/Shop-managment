import { apiClient } from '@/lib/api/client';
import type { AuthUser, LoginPayload, LoginResponse } from '@/lib/api/auth.types';
import { getAccessToken } from '@/lib/auth/storage';

export const authApi = {
  login(payload: LoginPayload): Promise<LoginResponse> {
    return apiClient.post<LoginResponse>('/api/auth/login', payload);
  },

  refresh(refreshToken: string): Promise<LoginResponse> {
    return apiClient.post<LoginResponse>('/api/auth/refresh', {
      refresh_token: refreshToken,
    });
  },

  logout(refreshToken?: string, accessToken?: string): Promise<void> {
    const token = accessToken ?? getAccessToken() ?? undefined;

    return apiClient.post<void>(
      '/api/auth/logout',
      refreshToken ? { refresh_token: refreshToken } : {},
      token,
    );
  },

  me(accessToken?: string): Promise<AuthUser> {
    const token = accessToken ?? getAccessToken() ?? undefined;

    return apiClient.get<AuthUser>('/api/me', token);
  },
};

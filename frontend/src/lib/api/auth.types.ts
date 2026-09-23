import type { StoredTokens } from '@/lib/auth/storage';

export interface AuthUser {
  id: string;
  email: string;
  first_name: string;
  last_name: string;
  company_id: string;
  is_portal_user: boolean;
  customer_id?: string | null;
  permissions: string[];
  roles: string[];
}

export interface LoginResponse {
  access_token: string;
  refresh_token: string;
  token_type: string;
  expires_in: number;
  user: AuthUser;
}

export interface LoginPayload {
  email: string;
  password: string;
}

export function toStoredTokens(response: LoginResponse): StoredTokens {
  return {
    accessToken: response.access_token,
    refreshToken: response.refresh_token,
  };
}

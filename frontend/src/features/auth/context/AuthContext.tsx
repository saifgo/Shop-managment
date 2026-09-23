import {
  createContext,
  useCallback,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react';
import { authApi } from '@/lib/api/auth';
import type { AuthUser, LoginPayload } from '@/lib/api/auth.types';
import { toStoredTokens } from '@/lib/api/auth.types';
import {
  setAuthFailureHandler,
  setRefreshHandler,
} from '@/lib/api/client';
import { hasPermission, type PermissionCode } from '@/lib/auth/permissions';
import {
  clearTokens,
  getAccessToken,
  getRefreshToken,
  getStoredTokens,
  storeTokens,
} from '@/lib/auth/storage';

export interface AuthContextValue {
  user: AuthUser | null;
  isAuthenticated: boolean;
  isLoading: boolean;
  login: (payload: LoginPayload) => Promise<AuthUser>;
  logout: () => Promise<void>;
  can: (permission: PermissionCode | PermissionCode[]) => boolean;
}

const AuthContext = createContext<AuthContextValue | null>(null);

export { AuthContext };

interface AuthProviderProps {
  children: ReactNode;
}

export function AuthProvider({ children }: AuthProviderProps) {
  const [user, setUser] = useState<AuthUser | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  const refreshSession = useCallback(async (): Promise<string | null> => {
    const refreshToken = getRefreshToken();

    if (!refreshToken) {
      return null;
    }

    try {
      const response = await authApi.refresh(refreshToken);
      storeTokens(toStoredTokens(response));
      setUser(response.user);

      return response.access_token;
    } catch {
      clearTokens();
      setUser(null);

      return null;
    }
  }, []);

  useEffect(() => {
    setRefreshHandler(refreshSession);
    setAuthFailureHandler(() => {
      clearTokens();
      setUser(null);
    });
  }, [refreshSession]);

  useEffect(() => {
    let cancelled = false;

    async function bootstrap() {
      const tokens = getStoredTokens();

      if (!tokens) {
        if (!cancelled) {
          setIsLoading(false);
        }

        return;
      }

      try {
        const me = await authApi.me(tokens.accessToken);

        if (!cancelled) {
          setUser(me);
        }
      } catch {
        const newToken = await refreshSession();

        if (!cancelled && !newToken) {
          clearTokens();
          setUser(null);
        }
      } finally {
        if (!cancelled) {
          setIsLoading(false);
        }
      }
    }

    void bootstrap();

    return () => {
      cancelled = true;
    };
  }, [refreshSession]);

  const login = useCallback(async (payload: LoginPayload) => {
    const response = await authApi.login(payload);
    storeTokens(toStoredTokens(response));
    setUser(response.user);

    return response.user;
  }, []);

  const logout = useCallback(async () => {
    const accessToken = getAccessToken();
    const refreshToken = getRefreshToken();

    try {
      if (accessToken) {
        await authApi.logout(refreshToken ?? undefined, accessToken);
      }
    } finally {
      clearTokens();
      setUser(null);
    }
  }, []);

  const can = useCallback(
    (permission: PermissionCode | PermissionCode[]) => hasPermission(user?.permissions, permission),
    [user?.permissions],
  );

  const value = useMemo<AuthContextValue>(
    () => ({
      user,
      isAuthenticated: user !== null,
      isLoading,
      login,
      logout,
      can,
    }),
    [user, isLoading, login, logout, can],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

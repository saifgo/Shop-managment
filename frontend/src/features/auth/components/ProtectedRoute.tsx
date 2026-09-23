import { Navigate, useLocation } from 'react-router-dom';
import type { ReactNode } from 'react';
import { useAuth } from '@/features/auth/hooks/useAuth';
import type { PermissionCode } from '@/lib/auth/permissions';

interface ProtectedRouteProps {
  children: ReactNode;
  loginPath: string;
  requiredPermissions?: PermissionCode[];
  portalOnly?: boolean;
  adminOnly?: boolean;
}

export function ProtectedRoute({
  children,
  loginPath,
  requiredPermissions,
  portalOnly = false,
  adminOnly = false,
}: ProtectedRouteProps) {
  const { isAuthenticated, isLoading, user, can } = useAuth();
  const location = useLocation();

  if (isLoading) {
    return (
      <p className="p-8 text-center text-muted-foreground">Checking session…</p>
    );
  }

  if (!isAuthenticated) {
    return <Navigate to={loginPath} replace state={{ from: location.pathname }} />;
  }

  if (portalOnly && !user?.is_portal_user) {
    return <Navigate to="/admin" replace />;
  }

  if (adminOnly && user?.is_portal_user) {
    return <Navigate to="/portal" replace />;
  }

  if (requiredPermissions?.length && !requiredPermissions.some((permission) => can(permission))) {
    return <Navigate to={loginPath.startsWith('/portal') ? '/portal' : '/admin'} replace />;
  }

  return children;
}

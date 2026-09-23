import type { ReactNode } from 'react';
import { useAuth } from '@/features/auth/hooks/useAuth';
import type { PermissionCode } from '@/lib/auth/permissions';

interface PermissionGateProps {
  permission: PermissionCode | PermissionCode[];
  children: ReactNode;
  fallback?: ReactNode;
}

export function PermissionGate({ permission, children, fallback = null }: PermissionGateProps) {
  const { can } = useAuth();

  if (!can(permission)) {
    return fallback;
  }

  return children;
}

import type { LucideIcon } from 'lucide-react'
import {
  ClipboardListIcon,
  FileTextIcon,
  HouseIcon,
  ShoppingCartIcon,
  StoreIcon,
  Undo2Icon,
  UserIcon,
} from 'lucide-react'

export interface PortalNavItem {
  to: string
  label: string
  icon: LucideIcon
  end?: boolean
}

export const PORTAL_PRIMARY_NAV: PortalNavItem[] = [
  { to: '/portal', label: 'Home', icon: HouseIcon, end: true },
  { to: '/portal/catalog', label: 'Shop', icon: StoreIcon },
  { to: '/portal/checkout', label: 'Cart', icon: ShoppingCartIcon },
  { to: '/portal/orders', label: 'Orders', icon: ClipboardListIcon },
]

export const PORTAL_ACCOUNT_LINKS: PortalNavItem[] = [
  { to: '/portal/account', label: 'Account', icon: UserIcon, end: true },
  { to: '/portal/invoices', label: 'Invoices', icon: FileTextIcon },
  { to: '/portal/returns', label: 'Returns', icon: Undo2Icon },
]

export function isAccountSection(pathname: string): boolean {
  return PORTAL_ACCOUNT_LINKS.some(
    (item) => pathname === item.to || pathname.startsWith(`${item.to}/`),
  )
}

export function formatCartCount(count: number): string {
  if (!Number.isFinite(count) || count < 0) {
    return '0'
  }

  return String(Math.round(count))
}

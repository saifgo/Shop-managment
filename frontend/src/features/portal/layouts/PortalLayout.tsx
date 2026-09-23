import { useState } from 'react'
import { NavLink, Outlet, useLocation } from 'react-router-dom'
import { LogOutIcon, ShoppingCartIcon } from 'lucide-react'
import { useAuth } from '@/features/auth/hooks/useAuth'
import { useCart } from '@/features/portal/context/CartContext'
import { Badge } from '@/components/ui/badge'
import { Button, buttonVariants } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import {
  PortalAccountDropdown,
  PortalAccountSheet,
} from './PortalAccountMenu'
import { PortalBottomNav } from './PortalBottomNav'
import {
  formatCartCount,
  isAccountSection,
  PORTAL_PRIMARY_NAV,
} from './portalNav'

const desktopLinkClass = ({ isActive }: { isActive: boolean }) =>
  cn(buttonVariants({ variant: 'ghost' }), isActive && 'bg-muted')

function PortalCartButton({ itemCount }: { itemCount: number }) {
  const countLabel = formatCartCount(itemCount)

  return (
    <NavLink
      to="/portal/checkout"
      aria-label={countLabel === '1' ? 'Cart, 1 item' : `Cart, ${countLabel} items`}
      className={cn(buttonVariants({ variant: 'outline', size: 'icon' }), 'relative')}
    >
      <ShoppingCartIcon />
      <Badge className="absolute -top-1 -right-1 min-w-5 px-1">{countLabel}</Badge>
    </NavLink>
  )
}

export function PortalLayout() {
  const { user, logout } = useAuth()
  const { itemCount } = useCart()
  const { pathname } = useLocation()
  const [accountOpen, setAccountOpen] = useState(false)
  const accountActive = isAccountSection(pathname)

  return (
    <div className="flex min-h-[100dvh] flex-col bg-background">
      <a
        href="#portal-main"
        className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded-md focus:bg-background focus:px-3 focus:py-2 focus:text-sm focus:text-foreground focus:ring-2 focus:ring-ring"
      >
        Skip to content
      </a>
      <header className="sticky top-0 z-10 flex h-14 shrink-0 items-center gap-3 border-b bg-background px-4">
        <NavLink to="/portal" end className="min-w-0 font-heading font-medium">
          Tittawin
        </NavLink>
        <nav aria-label="Portal" className="hidden flex-1 items-center gap-1 md:flex">
          {PORTAL_PRIMARY_NAV.map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              end={item.end}
              className={desktopLinkClass}
            >
              {item.label}
            </NavLink>
          ))}
          <PortalAccountDropdown isActive={accountActive} />
        </nav>
        <div className="ml-auto flex items-center gap-2">
          <PortalCartButton itemCount={itemCount} />
          <Button variant="ghost" aria-label="Sign out" onClick={() => void logout()}>
            <LogOutIcon data-icon="inline-start" />
            <span className="hidden sm:inline">Sign out</span>
          </Button>
        </div>
      </header>
      <main
        id="portal-main"
        tabIndex={-1}
        className="mx-auto w-full max-w-[1200px] flex-1 px-4 py-6 pb-[calc(5rem+env(safe-area-inset-bottom))] md:px-8 md:pb-8"
      >
        <Outlet />
      </main>
      <PortalBottomNav
        accountActive={accountActive}
        accountOpen={accountOpen}
        onAccountClick={() => setAccountOpen(true)}
      />
      <PortalAccountSheet
        open={accountOpen}
        onOpenChange={setAccountOpen}
        email={user?.email}
        onLogout={logout}
      />
    </div>
  )
}

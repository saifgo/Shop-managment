import { NavLink } from 'react-router-dom'
import { UserIcon } from 'lucide-react'
import { cn } from '@/lib/utils'
import { PORTAL_PRIMARY_NAV } from './portalNav'

interface PortalBottomNavProps {
  accountActive: boolean
  accountOpen: boolean
  onAccountClick: () => void
}

const itemClass = ({ isActive }: { isActive: boolean }) =>
  cn(
    'flex flex-1 flex-col items-center justify-center gap-1 py-2 text-xs',
    isActive ? 'text-foreground' : 'text-muted-foreground',
  )

export function PortalBottomNav({
  accountActive,
  accountOpen,
  onAccountClick,
}: PortalBottomNavProps) {
  return (
    <nav
      aria-label="Portal"
      className="fixed inset-x-0 bottom-0 z-10 border-t bg-background pb-[env(safe-area-inset-bottom)] md:hidden"
    >
      <div className="flex">
        {PORTAL_PRIMARY_NAV.map((item) => {
          const Icon = item.icon

          return (
            <NavLink
              key={item.to}
              to={item.to}
              end={item.end}
              className={itemClass}
            >
              <Icon className="size-5" />
              {item.label}
            </NavLink>
          )
        })}
        <button
          type="button"
          className={itemClass({ isActive: accountActive })}
          onClick={onAccountClick}
          aria-expanded={accountOpen}
          aria-haspopup="dialog"
          aria-current={accountActive ? 'page' : undefined}
        >
          <UserIcon className="size-5" />
          Account
        </button>
      </div>
    </nav>
  )
}

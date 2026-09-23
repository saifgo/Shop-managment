import { NavLink } from 'react-router-dom'
import { LogOutIcon } from 'lucide-react'
import { Button, buttonVariants } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuGroup,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetFooter,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet'
import { cn } from '@/lib/utils'
import { PORTAL_ACCOUNT_LINKS } from './portalNav'

interface PortalAccountDropdownProps {
  isActive: boolean
}

export function PortalAccountDropdown({ isActive }: PortalAccountDropdownProps) {
  return (
    <DropdownMenu>
      <DropdownMenuTrigger
        render={<Button variant="ghost" className={cn(isActive && 'bg-muted')} />}
      >
        Account
      </DropdownMenuTrigger>
      <DropdownMenuContent align="start" className="min-w-48">
        <DropdownMenuGroup>
          {PORTAL_ACCOUNT_LINKS.map((item) => {
            const Icon = item.icon

            return (
              <DropdownMenuItem
                key={item.to}
                render={<NavLink to={item.to} end={item.end} />}
              >
                <Icon />
                {item.label}
              </DropdownMenuItem>
            )
          })}
        </DropdownMenuGroup>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}

interface PortalAccountSheetProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  email?: string
  onLogout: () => void | Promise<void>
}

export function PortalAccountSheet({
  open,
  onOpenChange,
  email,
  onLogout,
}: PortalAccountSheetProps) {
  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent>
        <SheetHeader>
          <SheetTitle>Account</SheetTitle>
          <SheetDescription>
            {email ?? 'Profile, invoices, and returns.'}
          </SheetDescription>
        </SheetHeader>
        <nav aria-label="Account" className="flex flex-col gap-1 px-4">
          {PORTAL_ACCOUNT_LINKS.map((item) => {
            const Icon = item.icon

            return (
              <NavLink
                key={item.to}
                to={item.to}
                end={item.end}
                onClick={() => onOpenChange(false)}
                className={({ isActive }) =>
                  cn(
                    buttonVariants({ variant: 'ghost' }),
                    'w-full justify-start',
                    isActive && 'bg-muted',
                  )
                }
              >
                <Icon data-icon="inline-start" />
                {item.label}
              </NavLink>
            )
          })}
        </nav>
        <SheetFooter>
          <Button
            variant="ghost"
            className="justify-start"
            onClick={() => {
              onOpenChange(false)
              void onLogout()
            }}
          >
            <LogOutIcon data-icon="inline-start" />
            Sign out
          </Button>
        </SheetFooter>
      </SheetContent>
    </Sheet>
  )
}

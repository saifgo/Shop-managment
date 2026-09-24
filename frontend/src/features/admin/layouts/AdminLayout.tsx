import { Fragment, type ComponentType } from 'react'
import { NavLink, Outlet, useLocation } from 'react-router-dom'
import {
  ChartColumnIcon,
  ClipboardListIcon,
  CreditCardIcon,
  FactoryIcon,
  FilesIcon,
  FileTextIcon,
  LayoutDashboardIcon,
  LogOutIcon,
  PackageIcon,
  SettingsIcon,
  ShoppingCartIcon,
  TagsIcon,
  TrendingUpIcon,
  TruckIcon,
  Undo2Icon,
  UsersIcon,
  WalletIcon,
  WarehouseIcon,
} from 'lucide-react'
import { PermissionGate } from '@/features/auth/components/PermissionGate'
import { useAuth } from '@/features/auth/hooks/useAuth'
import { AdminBreadcrumb } from '@/features/admin/layouts/AdminBreadcrumb'
import { PERMISSIONS, type PermissionCode } from '@/lib/auth/permissions'
import { Avatar, AvatarFallback } from '@/components/ui/avatar'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuGroup,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Separator } from '@/components/ui/separator'
import {
  Sidebar,
  SidebarContent,
  SidebarGroup,
  SidebarGroupContent,
  SidebarGroupLabel,
  SidebarHeader,
  SidebarInset,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
  SidebarProvider,
  SidebarRail,
  SidebarTrigger,
  useSidebar,
} from '@/components/ui/sidebar'

interface AdminNavItem {
  title: string
  to: string
  icon: ComponentType
  permission?: PermissionCode
  end?: boolean
}

interface AdminNavGroup {
  title: string
  items: AdminNavItem[]
}

const NAV_GROUPS: AdminNavGroup[] = [
  {
    title: 'Overview',
    items: [
      {
        title: 'Dashboard',
        to: '/admin',
        icon: LayoutDashboardIcon,
        end: true,
      },
      {
        title: 'Reports',
        to: '/admin/reports',
        icon: ChartColumnIcon,
        permission: PERMISSIONS.financeView,
      },
    ],
  },
  {
    title: 'Sales',
    items: [
      {
        title: 'Orders',
        to: '/admin/orders',
        icon: ShoppingCartIcon,
        permission: PERMISSIONS.salesOrdersView,
      },
      {
        title: 'Demand',
        to: '/admin/demand',
        icon: TrendingUpIcon,
        permission: PERMISSIONS.salesOrdersView,
      },
      {
        title: 'Returns',
        to: '/admin/returns',
        icon: Undo2Icon,
        permission: PERMISSIONS.returnsView,
      },
    ],
  },
  {
    title: 'Catalog',
    items: [
      {
        title: 'Catalog',
        to: '/admin/catalog',
        icon: PackageIcon,
        permission: PERMISSIONS.catalogView,
      },
      {
        title: 'Categories',
        to: '/admin/catalog/categories',
        icon: TagsIcon,
        permission: PERMISSIONS.catalogManage,
      },
    ],
  },
  {
    title: 'Operations',
    items: [
      {
        title: 'Inventory',
        to: '/admin/inventory',
        icon: WarehouseIcon,
        permission: PERMISSIONS.inventoryView,
      },
      {
        title: 'Production',
        to: '/admin/production',
        icon: FactoryIcon,
        permission: PERMISSIONS.productionView,
      },
    ],
  },
  {
    title: 'Finance',
    items: [
      {
        title: 'Invoices',
        to: '/admin/invoices',
        icon: FileTextIcon,
        permission: PERMISSIONS.salesOrdersView,
      },
      {
        title: 'Documents',
        to: '/admin/documents',
        icon: FilesIcon,
        permission: PERMISSIONS.documentsView,
      },
      {
        title: 'Payments',
        to: '/admin/payments',
        icon: CreditCardIcon,
        permission: PERMISSIONS.financeView,
      },
      {
        title: 'Finance',
        to: '/admin/finance',
        icon: WalletIcon,
        permission: PERMISSIONS.financeView,
      },
    ],
  },
  {
    title: 'Purchasing',
    items: [
      {
        title: 'Suppliers',
        to: '/admin/suppliers',
        icon: TruckIcon,
        permission: PERMISSIONS.purchasingView,
      },
      {
        title: 'Purchasing',
        to: '/admin/purchasing',
        icon: ClipboardListIcon,
        permission: PERMISSIONS.purchasingView,
      },
    ],
  },
  {
    title: 'Partners',
    items: [
      {
        title: 'Customers',
        to: '/admin/customers',
        icon: UsersIcon,
        permission: PERMISSIONS.customersView,
      },
    ],
  },
  {
    title: 'System',
    items: [
      {
        title: 'Settings',
        to: '/admin/settings',
        icon: SettingsIcon,
        permission: PERMISSIONS.systemSettingsManage,
      },
    ],
  },
]

function isNavItemActive(pathname: string, item: AdminNavItem): boolean {
  if (item.end) {
    return pathname === item.to
  }

  if (item.to === '/admin/catalog') {
    return (
      pathname === '/admin/catalog' ||
      (pathname.startsWith('/admin/catalog/') &&
        !pathname.startsWith('/admin/catalog/categories'))
    )
  }

  return pathname === item.to || pathname.startsWith(`${item.to}/`)
}

function AdminNavLink({ item }: { item: AdminNavItem }) {
  const { pathname } = useLocation()
  const { isMobile, setOpenMobile } = useSidebar()
  const Icon = item.icon
  const active = isNavItemActive(pathname, item)

  return (
    <SidebarMenuItem>
      <SidebarMenuButton
        render={
          <NavLink
            to={item.to}
            end={item.end}
            onClick={() => {
              if (isMobile) {
                setOpenMobile(false)
              }
            }}
          />
        }
        isActive={active}
        tooltip={item.title}
      >
        <Icon />
        <span>{item.title}</span>
      </SidebarMenuButton>
    </SidebarMenuItem>
  )
}

function AdminSidebarNav() {
  const { can } = useAuth()

  return (
    <>
      {NAV_GROUPS.map((group) => {
        const visibleItems = group.items.filter(
          (item) => !item.permission || can(item.permission),
        )

        if (visibleItems.length === 0) {
          return null
        }

        return (
          <SidebarGroup key={group.title}>
            <SidebarGroupLabel>{group.title}</SidebarGroupLabel>
            <SidebarGroupContent>
              <SidebarMenu>
                {group.items.map((item) => {
                  const link = <AdminNavLink item={item} />

                  if (!item.permission) {
                    return <Fragment key={item.to}>{link}</Fragment>
                  }

                  return (
                    <PermissionGate key={item.to} permission={item.permission}>
                      {link}
                    </PermissionGate>
                  )
                })}
              </SidebarMenu>
            </SidebarGroupContent>
          </SidebarGroup>
        )
      })}
    </>
  )
}

function AdminUserMenu() {
  const { user, logout } = useAuth()
  const initials = user
    ? `${user.first_name.charAt(0)}${user.last_name.charAt(0)}`.toUpperCase()
    : '?'

  return (
    <DropdownMenu>
      <DropdownMenuTrigger
        render={<Button variant="ghost" className="gap-2 px-2" />}
      >
        <Avatar size="sm">
          <AvatarFallback>{initials}</AvatarFallback>
        </Avatar>
        <span className="hidden max-w-48 truncate text-sm md:inline">
          {user?.email ?? 'Account'}
        </span>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="min-w-56">
        <DropdownMenuGroup>
          <DropdownMenuLabel className="font-normal">
            <div className="flex flex-col gap-0.5">
              <span className="truncate text-sm text-foreground">
                {user ? `${user.first_name} ${user.last_name}` : 'Signed out'}
              </span>
              {user?.email ? (
                <span className="truncate text-xs text-muted-foreground">
                  {user.email}
                </span>
              ) : null}
            </div>
          </DropdownMenuLabel>
        </DropdownMenuGroup>
        <DropdownMenuSeparator />
        <DropdownMenuGroup>
          <DropdownMenuItem
            variant="destructive"
            onClick={() => {
              void logout()
            }}
          >
            <LogOutIcon />
            Sign out
          </DropdownMenuItem>
        </DropdownMenuGroup>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}

function AdminSidebar() {
  return (
    <Sidebar collapsible="icon">
      <SidebarHeader>
        <SidebarMenu>
          <SidebarMenuItem>
            <SidebarMenuButton
              size="lg"
              render={<NavLink to="/admin" end />}
              tooltip="Tittawin"
            >
              <div className="flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground">
                <span className="text-sm font-medium">T</span>
              </div>
              <div className="flex min-w-0 flex-col gap-0.5 leading-none">
                <span className="truncate font-medium">Tittawin</span>
                <span className="truncate text-xs text-muted-foreground">
                  Administration
                </span>
              </div>
            </SidebarMenuButton>
          </SidebarMenuItem>
        </SidebarMenu>
      </SidebarHeader>
      <SidebarContent>
        <AdminSidebarNav />
      </SidebarContent>
      <SidebarRail />
    </Sidebar>
  )
}

export function AdminLayout() {
  return (
    <SidebarProvider className="min-h-[100dvh]">
      <a
        href="#admin-main"
        className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded-md focus:bg-background focus:px-3 focus:py-2 focus:text-sm focus:text-foreground focus:ring-2 focus:ring-ring"
      >
        Skip to content
      </a>
      <AdminSidebar />
      <SidebarInset id="admin-main" className="min-h-[100dvh]">
        <header className="sticky top-0 z-10 flex h-14 shrink-0 items-center gap-2 border-b bg-background px-4">
          <SidebarTrigger />
          <Separator orientation="vertical" className="mr-1 h-4" />
          <AdminBreadcrumb />
          <div className="ml-auto flex items-center gap-2">
            <AdminUserMenu />
          </div>
        </header>
        <div className="flex flex-1 flex-col gap-4 p-4">
          <Outlet />
        </div>
      </SidebarInset>
    </SidebarProvider>
  )
}

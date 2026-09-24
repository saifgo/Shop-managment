import { Fragment } from 'react'
import { Link, useLocation } from 'react-router-dom'
import {
  Breadcrumb,
  BreadcrumbItem,
  BreadcrumbLink,
  BreadcrumbList,
  BreadcrumbPage,
  BreadcrumbSeparator,
} from '@/components/ui/breadcrumb'

const SEGMENT_LABELS: Record<string, string> = {
  admin: 'Admin',
  catalog: 'Catalog',
  categories: 'Categories',
  orders: 'Orders',
  demand: 'Demand',
  returns: 'Returns',
  inventory: 'Inventory',
  production: 'Production',
  invoices: 'Invoices',
  documents: 'Documents',
  payments: 'Payments',
  finance: 'Finance',
  suppliers: 'Suppliers',
  purchasing: 'Purchasing',
  customers: 'Customers',
  reports: 'Reports',
  settings: 'Settings',
  new: 'New',
}

function labelForSegment(segment: string): string {
  if (SEGMENT_LABELS[segment]) {
    return SEGMENT_LABELS[segment]
  }

  if (/^[0-9a-f-]{8,}$/i.test(segment)) {
    return 'Detail'
  }

  return segment.charAt(0).toUpperCase() + segment.slice(1)
}

interface Crumb {
  label: string
  href: string
  isCurrent: boolean
}

function buildCrumbs(pathname: string): Crumb[] {
  const segments = pathname.split('/').filter(Boolean)

  if (segments.length === 0) {
    return [{ label: 'Admin', href: '/admin', isCurrent: true }]
  }

  return segments.map((segment, index) => {
    const href = `/${segments.slice(0, index + 1).join('/')}`
    return {
      label: labelForSegment(segment),
      href,
      isCurrent: index === segments.length - 1,
    }
  })
}

export function AdminBreadcrumb() {
  const { pathname } = useLocation()
  const crumbs = buildCrumbs(pathname)

  return (
    <Breadcrumb>
      <BreadcrumbList>
        {crumbs.map((crumb, index) => (
          <Fragment key={crumb.href}>
            {index > 0 ? <BreadcrumbSeparator /> : null}
            <BreadcrumbItem>
              {crumb.isCurrent ? (
                <BreadcrumbPage>{crumb.label}</BreadcrumbPage>
              ) : (
                <BreadcrumbLink render={<Link to={crumb.href} />}>
                  {crumb.label}
                </BreadcrumbLink>
              )}
            </BreadcrumbItem>
          </Fragment>
        ))}
      </BreadcrumbList>
    </Breadcrumb>
  )
}

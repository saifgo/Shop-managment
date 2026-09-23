import { Navigate, Route, Routes } from 'react-router-dom'
import { AdminCatalogPage } from '@/features/admin/catalog/AdminCatalogPage'
import { AdminCategoriesPage } from '@/features/admin/catalog/AdminCategoriesPage'
import { AdminProductEditPage } from '@/features/admin/catalog/AdminProductEditPage'
import { AdminCustomerEditPage } from '@/features/admin/customers/AdminCustomerEditPage'
import { AdminCustomersPage } from '@/features/admin/customers/AdminCustomersPage'
import { AdminLayout } from '@/features/admin/layouts/AdminLayout'
import { AdminHomePage } from '@/features/admin/pages/AdminHomePage'
import { AdminDemandPage } from '@/features/admin/pages/AdminDemandPage'
import { AdminInventoryPage } from '@/features/admin/pages/AdminInventoryPage'
import { AdminOrderDetailPage } from '@/features/admin/pages/AdminOrderDetailPage'
import { AdminOrdersPage } from '@/features/admin/pages/AdminOrdersPage'
import { ProtectedRoute } from '@/features/auth/components/ProtectedRoute'
import { LoginPage } from '@/features/auth/pages/LoginPage'
import { PortalLayout } from '@/features/portal/layouts/PortalLayout'
import { PortalAccountPage } from '@/features/portal/pages/PortalAccountPage'
import { PortalCatalogPage } from '@/features/portal/pages/PortalCatalogPage'
import { PortalHomePage } from '@/features/portal/pages/PortalHomePage'
import { PortalCheckoutPage } from '@/features/portal/pages/PortalCheckoutPage'
import { PortalOrderDetailPage } from '@/features/portal/pages/PortalOrderDetailPage'
import { PortalOrdersPage } from '@/features/portal/pages/PortalOrdersPage'
import { PortalProductPage } from '@/features/portal/pages/PortalProductPage'
import { AdminProductionDetailPage } from '@/features/admin/pages/AdminProductionDetailPage'
import { AdminProductionPage } from '@/features/admin/pages/AdminProductionPage'
import { AdminInvoicesPage, AdminPaymentsPage } from '@/features/admin/pages/AdminFinancePage'
import { AdminLightweightFinancePage } from '@/features/admin/pages/AdminLightweightFinancePage'
import { AdminPurchasingPage, AdminSuppliersPage } from '@/features/admin/pages/AdminPurchasingPage'
import { AdminReturnsPage } from '@/features/admin/pages/AdminReturnsPage'
import { AdminReportsPage } from '@/features/admin/pages/AdminReportsPage'
import { PortalInvoicesPage } from '@/features/finance/FinancePanels'
import { PortalReturnsPage } from '@/features/portal/pages/PortalReturnsPage'
import { PERMISSIONS } from '@/lib/auth/permissions'

export function AppRouter() {
  return (
    <Routes>
      <Route path="/" element={<Navigate to="/portal" replace />} />

      <Route
        path="/portal/login"
        element={
          <LoginPage
            title="Customer Portal"
            subtitle="Sign in to view orders, catalog, and account details."
            homePath="/portal"
            alternateLoginPath="/admin/login"
            alternateLabel="Administration sign in"
          />
        }
      />

      <Route
        path="/admin/login"
        element={
          <LoginPage
            title="Administration"
            subtitle="Sign in to manage catalog, orders, inventory, and production."
            homePath="/admin"
            alternateLoginPath="/portal/login"
            alternateLabel="Customer portal sign in"
          />
        }
      />

      <Route
        path="/portal"
        element={
          <ProtectedRoute loginPath="/portal/login" portalOnly requiredPermissions={[PERMISSIONS.portalOrdersView]}>
            <PortalLayout />
          </ProtectedRoute>
        }
      >
        <Route index element={<PortalHomePage />} />
        <Route
          path="catalog"
          element={
            <ProtectedRoute loginPath="/portal/login" requiredPermissions={[PERMISSIONS.catalogView]}>
              <PortalCatalogPage />
            </ProtectedRoute>
          }
        />
        <Route
          path="catalog/:id"
          element={
            <ProtectedRoute loginPath="/portal/login" requiredPermissions={[PERMISSIONS.catalogView]}>
              <PortalProductPage />
            </ProtectedRoute>
          }
        />
        <Route path="checkout" element={<PortalCheckoutPage />} />
        <Route path="orders" element={<PortalOrdersPage />} />
        <Route path="orders/:id" element={<PortalOrderDetailPage />} />
        <Route path="invoices" element={<PortalInvoicesPage />} />
        <Route path="returns" element={<PortalReturnsPage />} />
        <Route
          path="account"
          element={
            <ProtectedRoute loginPath="/portal/login" requiredPermissions={[PERMISSIONS.portalAccountView]}>
              <PortalAccountPage />
            </ProtectedRoute>
          }
        />
      </Route>

      <Route
        path="/admin"
        element={
          <ProtectedRoute loginPath="/admin/login" adminOnly>
            <AdminLayout />
          </ProtectedRoute>
        }
      >
        <Route index element={<AdminHomePage />} />
        <Route
          path="catalog"
          element={
            <ProtectedRoute loginPath="/admin/login" requiredPermissions={[PERMISSIONS.catalogView]}>
              <AdminCatalogPage />
            </ProtectedRoute>
          }
        />
        <Route
          path="catalog/new"
          element={
            <ProtectedRoute loginPath="/admin/login" requiredPermissions={[PERMISSIONS.catalogManage]}>
              <AdminProductEditPage />
            </ProtectedRoute>
          }
        />
        <Route
          path="catalog/categories"
          element={
            <ProtectedRoute loginPath="/admin/login" requiredPermissions={[PERMISSIONS.catalogManage]}>
              <AdminCategoriesPage />
            </ProtectedRoute>
          }
        />
        <Route
          path="catalog/:id"
          element={
            <ProtectedRoute loginPath="/admin/login" requiredPermissions={[PERMISSIONS.catalogView]}>
              <AdminProductEditPage />
            </ProtectedRoute>
          }
        />
        <Route path="orders" element={<AdminOrdersPage />} />
        <Route path="orders/:id" element={<AdminOrderDetailPage />} />
        <Route path="invoices" element={<AdminInvoicesPage />} />
        <Route path="payments" element={<AdminPaymentsPage />} />
        <Route path="returns" element={<AdminReturnsPage />} />
        <Route path="suppliers" element={<AdminSuppliersPage />} />
        <Route path="purchasing" element={<AdminPurchasingPage />} />
        <Route path="finance" element={<AdminLightweightFinancePage />} />
        <Route path="reports" element={<AdminReportsPage />} />
        <Route path="inventory" element={<AdminInventoryPage />} />
        <Route path="demand" element={<AdminDemandPage />} />
        <Route
          path="production"
          element={
            <ProtectedRoute loginPath="/admin/login" requiredPermissions={[PERMISSIONS.productionView]}>
              <AdminProductionPage />
            </ProtectedRoute>
          }
        />
        <Route
          path="production/:id"
          element={
            <ProtectedRoute loginPath="/admin/login" requiredPermissions={[PERMISSIONS.productionView]}>
              <AdminProductionDetailPage />
            </ProtectedRoute>
          }
        />
        <Route
          path="customers"
          element={
            <ProtectedRoute loginPath="/admin/login" requiredPermissions={[PERMISSIONS.customersView]}>
              <AdminCustomersPage />
            </ProtectedRoute>
          }
        />
        <Route
          path="customers/new"
          element={
            <ProtectedRoute loginPath="/admin/login" requiredPermissions={[PERMISSIONS.customersManage]}>
              <AdminCustomerEditPage />
            </ProtectedRoute>
          }
        />
        <Route
          path="customers/:id"
          element={
            <ProtectedRoute loginPath="/admin/login" requiredPermissions={[PERMISSIONS.customersView]}>
              <AdminCustomerEditPage />
            </ProtectedRoute>
          }
        />
      </Route>

      <Route path="*" element={<Navigate to="/portal" replace />} />
    </Routes>
  )
}

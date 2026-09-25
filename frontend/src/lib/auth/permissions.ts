export const PERMISSIONS = {
  catalogView: 'catalog.products.view',
  catalogManage: 'catalog.products.manage',
  salesOrdersView: 'sales.orders.view',
  salesOrdersManage: 'sales.orders.manage',
  inventoryView: 'inventory.view',
  inventoryAdjust: 'inventory.adjust',
  productionView: 'production.view',
  productionManage: 'production.manage',
  customersView: 'customers.view',
  customersManage: 'customers.manage',
  portalOrdersView: 'portal.orders.view',
  portalAccountView: 'portal.account.view',
  returnsView: 'returns.view',
  returnsManage: 'returns.manage',
  purchasingView: 'purchasing.view',
  purchasingManage: 'purchasing.manage',
  financeView: 'finance.view',
  financeManage: 'finance.manage',
  documentsView: 'documents.view',
  documentsManage: 'documents.manage',
  documentsCancel: 'documents.cancel',
  paymentsView: 'payments.view',
  systemSettingsManage: 'system.settings.manage',
} as const;

export type PermissionCode = (typeof PERMISSIONS)[keyof typeof PERMISSIONS];

export function hasPermission(
  userPermissions: string[] | undefined,
  required: PermissionCode | PermissionCode[],
): boolean {
  if (!userPermissions?.length) {
    return false;
  }

  const requiredList = Array.isArray(required) ? required : [required];

  return requiredList.some((permission) => userPermissions.includes(permission));
}

export function hasAllPermissions(
  userPermissions: string[] | undefined,
  required: PermissionCode[],
): boolean {
  if (!userPermissions?.length) {
    return false;
  }

  return required.every((permission) => userPermissions.includes(permission));
}

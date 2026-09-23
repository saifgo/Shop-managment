<?php

declare(strict_types=1);

namespace App\Domain\Identity;

/**
 * Canonical permission codes used across the system.
 * Authorization checks must use these codes, not role names.
 */
final class PermissionCatalog
{
    public const IDENTITY_USERS_VIEW = 'identity.users.view';
    public const IDENTITY_USERS_MANAGE = 'identity.users.manage';
    public const IDENTITY_ROLES_MANAGE = 'identity.roles.manage';
    public const AUDIT_VIEW = 'audit.view';
    public const CATALOG_PRODUCTS_VIEW = 'catalog.products.view';
    public const CATALOG_PRODUCTS_MANAGE = 'catalog.products.manage';
    public const CUSTOMERS_VIEW = 'customers.view';
    public const CUSTOMERS_MANAGE = 'customers.manage';
    public const SALES_ORDERS_VIEW = 'sales.orders.view';
    public const SALES_ORDERS_MANAGE = 'sales.orders.manage';
    public const INVENTORY_VIEW = 'inventory.view';
    public const INVENTORY_ADJUST = 'inventory.adjust';
    public const PRODUCTION_VIEW = 'production.view';
    public const PRODUCTION_MANAGE = 'production.manage';
    public const PRODUCTION_STAGE_EXECUTE = 'production.stage.execute';
    public const PRODUCTION_LOSS_CORRECT = 'production.loss.correct';
    public const DOCUMENTS_VIEW = 'documents.view';
    public const DOCUMENTS_MANAGE = 'documents.manage';
    public const DOCUMENTS_CANCEL = 'documents.cancel';
    public const PAYMENTS_VIEW = 'payments.view';
    public const PAYMENTS_MANAGE = 'payments.manage';
    public const PAYMENTS_DELETE = 'payments.delete';
    public const RETURNS_VIEW = 'returns.view';
    public const RETURNS_MANAGE = 'returns.manage';
    public const PURCHASING_VIEW = 'purchasing.view';
    public const PURCHASING_MANAGE = 'purchasing.manage';
    public const FINANCE_VIEW = 'finance.view';
    public const FINANCE_MANAGE = 'finance.manage';
    public const SYSTEM_SETTINGS_MANAGE = 'system.settings.manage';
    public const PORTAL_ORDERS_VIEW = 'portal.orders.view';
    public const PORTAL_ACCOUNT_VIEW = 'portal.account.view';

    /**
     * @return list<array{code: string, name: string, module: string}>
     */
    public static function all(): array
    {
        return [
            ['code' => self::IDENTITY_USERS_VIEW, 'name' => 'View users', 'module' => 'identity'],
            ['code' => self::IDENTITY_USERS_MANAGE, 'name' => 'Manage users', 'module' => 'identity'],
            ['code' => self::IDENTITY_ROLES_MANAGE, 'name' => 'Manage roles', 'module' => 'identity'],
            ['code' => self::AUDIT_VIEW, 'name' => 'View audit log', 'module' => 'audit'],
            ['code' => self::CATALOG_PRODUCTS_VIEW, 'name' => 'View catalog', 'module' => 'catalog'],
            ['code' => self::CATALOG_PRODUCTS_MANAGE, 'name' => 'Manage catalog', 'module' => 'catalog'],
            ['code' => self::CUSTOMERS_VIEW, 'name' => 'View customers', 'module' => 'customers'],
            ['code' => self::CUSTOMERS_MANAGE, 'name' => 'Manage customers', 'module' => 'customers'],
            ['code' => self::SALES_ORDERS_VIEW, 'name' => 'View orders', 'module' => 'sales'],
            ['code' => self::SALES_ORDERS_MANAGE, 'name' => 'Manage orders', 'module' => 'sales'],
            ['code' => self::INVENTORY_VIEW, 'name' => 'View inventory', 'module' => 'inventory'],
            ['code' => self::INVENTORY_ADJUST, 'name' => 'Adjust inventory', 'module' => 'inventory'],
            ['code' => self::PRODUCTION_VIEW, 'name' => 'View production', 'module' => 'production'],
            ['code' => self::PRODUCTION_MANAGE, 'name' => 'Manage production', 'module' => 'production'],
            ['code' => self::PRODUCTION_STAGE_EXECUTE, 'name' => 'Execute production stages', 'module' => 'production'],
            ['code' => self::PRODUCTION_LOSS_CORRECT, 'name' => 'Correct production losses', 'module' => 'production'],
            ['code' => self::DOCUMENTS_VIEW, 'name' => 'View documents', 'module' => 'documents'],
            ['code' => self::DOCUMENTS_MANAGE, 'name' => 'Manage documents', 'module' => 'documents'],
            ['code' => self::DOCUMENTS_CANCEL, 'name' => 'Cancel documents', 'module' => 'documents'],
            ['code' => self::PAYMENTS_VIEW, 'name' => 'View payments', 'module' => 'payments'],
            ['code' => self::PAYMENTS_MANAGE, 'name' => 'Manage payments', 'module' => 'payments'],
            ['code' => self::PAYMENTS_DELETE, 'name' => 'Delete payments', 'module' => 'payments'],
            ['code' => self::RETURNS_VIEW, 'name' => 'View returns', 'module' => 'returns'],
            ['code' => self::RETURNS_MANAGE, 'name' => 'Manage returns', 'module' => 'returns'],
            ['code' => self::PURCHASING_VIEW, 'name' => 'View purchasing', 'module' => 'purchasing'],
            ['code' => self::PURCHASING_MANAGE, 'name' => 'Manage purchasing', 'module' => 'purchasing'],
            ['code' => self::FINANCE_VIEW, 'name' => 'View finance', 'module' => 'finance'],
            ['code' => self::FINANCE_MANAGE, 'name' => 'Manage finance', 'module' => 'finance'],
            ['code' => self::SYSTEM_SETTINGS_MANAGE, 'name' => 'Manage system settings', 'module' => 'system'],
            ['code' => self::PORTAL_ORDERS_VIEW, 'name' => 'View own orders', 'module' => 'portal'],
            ['code' => self::PORTAL_ACCOUNT_VIEW, 'name' => 'View own account', 'module' => 'portal'],
        ];
    }
}

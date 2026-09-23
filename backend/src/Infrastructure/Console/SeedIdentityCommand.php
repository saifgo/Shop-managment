<?php

declare(strict_types=1);

namespace App\Infrastructure\Console;

use App\Domain\Identity\PermissionCatalog;
use App\Domain\Shared\EntityId;
use App\Infrastructure\Persistence\Entity\Identity\Permission;
use App\Infrastructure\Persistence\Entity\Identity\Role;
use App\Infrastructure\Persistence\Entity\Identity\User;
use App\Infrastructure\Persistence\UnitOfWork;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:seed-identity',
    description: 'Seed default roles, permissions, and demo users',
)]
final class SeedIdentityCommand extends Command
{
    /** Default demo company ULID — stable for local development. */
    public const DEFAULT_COMPANY_ID = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private UnitOfWork $unitOfWork,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $companyId = EntityId::fromString(self::DEFAULT_COMPANY_ID);

        $permissionEntities = [];

        foreach (PermissionCatalog::all() as $definition) {
            $existing = $this->entityManager->getRepository(Permission::class)->findOneBy(['code' => $definition['code']]);

            if ($existing instanceof Permission) {
                $permissionEntities[$definition['code']] = $existing;
                continue;
            }

            $permission = new Permission(
                id: EntityId::generate(),
                code: $definition['code'],
                name: $definition['name'],
                module: $definition['module'],
            );
            $this->entityManager->persist($permission);
            $permissionEntities[$definition['code']] = $permission;
        }

        $allPermissionCodes = array_keys($permissionEntities);

        $roleDefinitions = [
            'super_admin' => [
                'name' => 'Super Admin',
                'description' => 'Full system access',
                'permissions' => $allPermissionCodes,
            ],
            'sales_admin' => [
                'name' => 'Sales / Admin',
                'description' => 'Catalog, customers, orders, documents, payments, returns',
                'permissions' => $this->filterPermissions($allPermissionCodes, [
                    'catalog.', 'customers.', 'sales.', 'documents.', 'payments.', 'returns.', 'inventory.view',
                ]),
            ],
            'production_manager' => [
                'name' => 'Production Manager',
                'description' => 'Production planning and management',
                'permissions' => $this->filterPermissions($allPermissionCodes, ['production.']),
            ],
            'production_operator' => [
                'name' => 'Production Operator',
                'description' => 'Execute assigned production stages',
                'permissions' => [
                    PermissionCatalog::PRODUCTION_VIEW,
                    PermissionCatalog::PRODUCTION_STAGE_EXECUTE,
                ],
            ],
            'warehouse' => [
                'name' => 'Warehouse / Stock',
                'description' => 'Inventory operations',
                'permissions' => $this->filterPermissions($allPermissionCodes, ['inventory.']),
            ],
            'purchasing' => [
                'name' => 'Purchasing',
                'description' => 'Supplier and purchase order management',
                'permissions' => $this->filterPermissions($allPermissionCodes, ['purchasing.']),
            ],
            'finance' => [
                'name' => 'Finance',
                'description' => 'Finance, payments, and document viewing',
                'permissions' => array_merge(
                    $this->filterPermissions($allPermissionCodes, ['finance.', 'payments.']),
                    [PermissionCatalog::DOCUMENTS_VIEW],
                ),
            ],
            'customer' => [
                'name' => 'Customer',
                'description' => 'Customer portal access',
                'permissions' => [
                    PermissionCatalog::PORTAL_ORDERS_VIEW,
                    PermissionCatalog::PORTAL_ACCOUNT_VIEW,
                    PermissionCatalog::CATALOG_PRODUCTS_VIEW,
                ],
            ],
        ];

        $roleEntities = [];

        foreach ($roleDefinitions as $code => $definition) {
            $existing = $this->entityManager->getRepository(Role::class)->findOneBy(['code' => $code]);

            if ($existing instanceof Role) {
                $roleEntities[$code] = $existing;
                continue;
            }

            $role = new Role(
                id: EntityId::generate(),
                code: $code,
                name: $definition['name'],
                description: $definition['description'],
            );

            foreach ($definition['permissions'] as $permissionCode) {
                if (isset($permissionEntities[$permissionCode])) {
                    $role->addPermission($permissionEntities[$permissionCode]);
                }
            }

            $this->entityManager->persist($role);
            $roleEntities[$code] = $role;
        }

        $this->seedUser(
            email: 'admin@tittawin.local',
            password: 'ChangeMe123!',
            firstName: 'Super',
            lastName: 'Admin',
            role: $roleEntities['super_admin'],
            companyId: $companyId,
            isPortalUser: false,
        );

        $this->seedUser(
            email: 'sales@tittawin.local',
            password: 'ChangeMe123!',
            firstName: 'Sales',
            lastName: 'Admin',
            role: $roleEntities['sales_admin'],
            companyId: $companyId,
            isPortalUser: false,
        );

        $this->seedUser(
            email: 'customer@tittawin.local',
            password: 'ChangeMe123!',
            firstName: 'Portal',
            lastName: 'Customer',
            role: $roleEntities['customer'],
            companyId: $companyId,
            isPortalUser: true,
        );

        $this->unitOfWork->flush();

        $io->success('Identity seed complete.');
        $io->listing([
            'admin@tittawin.local / ChangeMe123! (Super Admin)',
            'sales@tittawin.local / ChangeMe123! (Sales Admin)',
            'customer@tittawin.local / ChangeMe123! (Customer Portal)',
        ]);

        return Command::SUCCESS;
    }

    private function seedUser(
        string $email,
        string $password,
        string $firstName,
        string $lastName,
        Role $role,
        EntityId $companyId,
        bool $isPortalUser,
    ): void {
        $existing = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

        if ($existing instanceof User) {
            return;
        }

        $user = new User(
            id: EntityId::generate(),
            companyId: $companyId,
            email: $email,
            passwordHash: 'pending',
            firstName: $firstName,
            lastName: $lastName,
            isPortalUser: $isPortalUser,
        );
        $user->addRole($role);
        $user->setPasswordHash($this->passwordHasher->hashPassword($user, $password));

        $this->entityManager->persist($user);
    }

    /**
     * @param list<string> $codes
     * @param list<string> $prefixes
     *
     * @return list<string>
     */
    private function filterPermissions(array $codes, array $prefixes): array
    {
        return array_values(array_filter(
            $codes,
            static function (string $code) use ($prefixes): bool {
                foreach ($prefixes as $prefix) {
                    if (str_starts_with($code, $prefix)) {
                        return true;
                    }
                }

                return false;
            },
        ));
    }
}

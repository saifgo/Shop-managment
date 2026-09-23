<?php

declare(strict_types=1);

namespace App\Infrastructure\Console;

use App\Application\Inventory\StockLedgerService;
use App\Domain\Inventory\StockMovementType;
use App\Domain\Shared\EntityId;
use App\Infrastructure\Persistence\Entity\Catalog\ProductVariant;
use App\Infrastructure\Persistence\Entity\Inventory\StockLocation;
use App\Infrastructure\Persistence\UnitOfWork;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seed-inventory',
    description: 'Seed default stock location and demo inventory levels',
)]
final class SeedInventoryCommand extends Command
{
    /** @var array<string, string> */
    private const INITIAL_STOCK = [
        'TAG-S' => '12.0000',
        'TAG-M' => '8.0000',
        'TAG-L' => '5.0000',
        'VAS-M' => '3.0000',
        'BWL-4' => '20.0000',
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private StockLedgerService $stockLedgerService,
        private UnitOfWork $unitOfWork,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $companyId = EntityId::fromString(SeedIdentityCommand::DEFAULT_COMPANY_ID);

        if ($this->entityManager->getRepository(StockLocation::class)->findOneBy(['code' => 'MAIN', 'companyId' => $companyId->toString()]) !== null) {
            $io->note('Inventory seed already applied.');

            return Command::SUCCESS;
        }

        $this->unitOfWork->transactional(function () use ($companyId, $io): void {
            $location = new StockLocation(
                EntityId::generate(),
                $companyId,
                'MAIN',
                'Main Warehouse',
                isDefault: true,
            );
            $this->entityManager->persist($location);

            foreach (self::INITIAL_STOCK as $sku => $quantity) {
                /** @var ProductVariant|null $variant */
                $variant = $this->entityManager->getRepository(ProductVariant::class)->findOneBy([
                    'sku' => $sku,
                    'companyId' => $companyId->toString(),
                ]);

                if ($variant === null) {
                    continue;
                }

                $this->stockLedgerService->postMovement(
                    companyId: $companyId,
                    variant: $variant,
                    location: $location,
                    movementType: StockMovementType::Adjustment,
                    quantityDelta: $quantity,
                    reservedDelta: '0.0000',
                    sourceType: 'inventory_seed',
                    sourceId: EntityId::generate(),
                    reference: 'SEED-INITIAL',
                    notes: 'Initial demo stock',
                );
            }
        });

        $io->success('Inventory seed complete.');

        return Command::SUCCESS;
    }
}

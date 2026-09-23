<?php

declare(strict_types=1);

namespace App\Infrastructure\Console;

use App\Domain\Production\ReconciliationMode;
use App\Domain\Shared\EntityId;
use App\Infrastructure\Persistence\Entity\Production\ProductionLossReason;
use App\Infrastructure\Persistence\Entity\Production\ProductionStage;
use App\Infrastructure\Persistence\UnitOfWork;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:seed-production-config',
    description: 'Seed default production stages and loss reasons',
)]
final class SeedProductionConfigCommand extends Command
{
  /** @var list<array{sequence: int, name: string}> */
    private const DEFAULT_STAGES = [
        ['sequence' => 1, 'name' => 'Preparing Materials'],
        ['sequence' => 2, 'name' => 'Making the Product'],
        ['sequence' => 3, 'name' => 'Refining'],
        ['sequence' => 4, 'name' => 'First Oven'],
        ['sequence' => 5, 'name' => 'Decoration'],
        ['sequence' => 6, 'name' => 'Second Oven'],
        ['sequence' => 7, 'name' => 'Sorting'],
    ];

    /** @var list<array{code: string, label: string}> */
    private const DEFAULT_LOSS_REASONS = [
        ['code' => 'BROKEN', 'label' => 'Broken / unusable'],
        ['code' => 'CRACKS', 'label' => 'Cracks'],
        ['code' => 'KILN_DAMAGE', 'label' => 'Kiln damage'],
        ['code' => 'DECORATION_REJECT', 'label' => 'Decoration reject'],
        ['code' => 'FIRING_DAMAGE', 'label' => 'Firing damage'],
        ['code' => 'QUALITY_REJECT', 'label' => 'Quality reject'],
        ['code' => 'OTHER', 'label' => 'Other'],
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private UnitOfWork $unitOfWork,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $companyId = EntityId::fromString(SeedIdentityCommand::DEFAULT_COMPANY_ID);

        if ($this->entityManager->getRepository(ProductionStage::class)->findOneBy(['companyId' => $companyId->toString()]) !== null) {
            $io->note('Production config seed already applied.');

            return Command::SUCCESS;
        }

        foreach (self::DEFAULT_STAGES as $stage) {
            $this->entityManager->persist(new ProductionStage(
                id: EntityId::generate(),
                companyId: $companyId,
                sequence: $stage['sequence'],
                name: $stage['name'],
                reconciliationMode: ReconciliationMode::Strict,
            ));
        }

        foreach (self::DEFAULT_LOSS_REASONS as $reason) {
            $this->entityManager->persist(new ProductionLossReason(
                id: EntityId::generate(),
                companyId: $companyId,
                code: $reason['code'],
                label: $reason['label'],
            ));
        }

        $this->unitOfWork->flush();
        $io->success('Production config seed complete.');

        return Command::SUCCESS;
    }
}

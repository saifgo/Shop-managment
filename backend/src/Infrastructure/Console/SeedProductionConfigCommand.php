<?php

declare(strict_types=1);

namespace App\Infrastructure\Console;

use App\Application\Production\ProductionConfigService;
use App\Domain\Shared\EntityId;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Optional: production stages and loss reasons are also created automatically the first time a
 * company needs them (see ProductionConfigService::ensureDefaults()).
 */
#[AsCommand(
    name: 'app:seed-production-config',
    description: 'Seed default production stages and loss reasons',
)]
final class SeedProductionConfigCommand extends Command
{
    public function __construct(private ProductionConfigService $productionConfigService)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->productionConfigService->ensureDefaults(EntityId::fromString(SeedIdentityCommand::DEFAULT_COMPANY_ID));
        (new SymfonyStyle($input, $output))->success('Production config is in place.');

        return Command::SUCCESS;
    }
}

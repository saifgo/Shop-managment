<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Application\Audit\AuditRecorder;
use App\Application\Inventory\StockCostService;
use App\Domain\Shared\EntityId;
use App\Domain\Shared\Quantity;
use App\Infrastructure\Persistence\Entity\Catalog\ProductComponent;
use App\Infrastructure\Persistence\Entity\Catalog\ProductVariant;
use App\Infrastructure\Persistence\Entity\Identity\User;
use App\Infrastructure\Persistence\UnitOfWork;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Recipes (bills of materials): the raw materials one unit of a finished variant consumes.
 * Production draws these from stock when an order starts and costs the finished pieces from them.
 */
final class RecipeService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UnitOfWork $unitOfWork,
        private StockCostService $stockCostService,
        private AuditRecorder $auditRecorder,
    ) {
    }

    /** @return array<string, mixed> */
    public function get(User $user, string $variantId): array
    {
        return $this->serialize($user, $this->findVariant($user, $variantId));
    }

    /**
     * Replaces the whole recipe of a variant.
     *
     * @param list<array{component_variant_id?: string|null, quantity_per_unit?: string|null}> $components
     *
     * @return array<string, mixed>
     */
    public function replace(User $user, string $variantId, array $components): array
    {
        return $this->unitOfWork->transactional(function () use ($user, $variantId, $components): array {
            $variant = $this->findVariant($user, $variantId);

            if (!$variant->getProduct()->getKind()->isSellable()) {
                throw new BadRequestHttpException('Raw materials do not have a recipe.');
            }

            $wanted = [];
            foreach ($components as $index => $line) {
                $componentId = $line['component_variant_id'] ?? null;

                if (!is_string($componentId) || $componentId === '') {
                    throw new BadRequestHttpException(sprintf('Recipe line %d: choose a raw material.', $index + 1));
                }

                if (isset($wanted[$componentId])) {
                    throw new BadRequestHttpException('Each raw material can only appear once in a recipe.');
                }

                try {
                    $quantity = Quantity::of(trim((string) ($line['quantity_per_unit'] ?? '')));
                } catch (\InvalidArgumentException) {
                    throw new BadRequestHttpException(sprintf('Recipe line %d: quantity must be a positive number with up to 4 decimals.', $index + 1));
                }

                if ($quantity->isZero()) {
                    throw new BadRequestHttpException(sprintf('Recipe line %d: quantity must be greater than zero.', $index + 1));
                }

                $component = $this->findVariant($user, $componentId);

                if ($component->getProduct()->getKind()->isSellable()) {
                    throw new BadRequestHttpException(sprintf('%s is a finished good, not a raw material.', $component->getSku()));
                }

                $wanted[$componentId] = ['component' => $component, 'quantity' => $quantity];
            }

            foreach ($this->components($variant) as $existing) {
                $id = $existing->getComponent()->getId();

                if (isset($wanted[$id])) {
                    $existing->changeQuantity($wanted[$id]['quantity']);
                    unset($wanted[$id]);
                    continue;
                }

                $this->entityManager->remove($existing);
            }

            foreach ($wanted as $line) {
                $this->entityManager->persist(new ProductComponent(
                    EntityId::generate(),
                    $user->companyId(),
                    $variant,
                    $line['component'],
                    $line['quantity'],
                ));
            }

            $this->entityManager->flush();

            $this->auditRecorder->record(
                action: 'catalog.recipe.updated',
                payload: ['sku' => $variant->getSku(), 'components' => count($components)],
                companyId: $user->companyId(),
                actorUserId: EntityId::fromString($user->getId()),
                entityType: 'product_variant',
                entityId: EntityId::fromString($variant->getId()),
                flush: false,
            );

            return $this->serialize($user, $variant);
        });
    }

    /**
     * What making `$quantity` units of the variant consumes.
     *
     * @return list<array{component: ProductVariant, quantity: Quantity}>
     */
    public function requirementsFor(ProductVariant $variant, Quantity $quantity): array
    {
        $requirements = [];

        foreach ($this->components($variant) as $line) {
            $requirements[] = [
                'component' => $line->getComponent(),
                'quantity' => $line->getQuantityPerUnit()->times($quantity),
            ];
        }

        return $requirements;
    }

    /** @return list<ProductComponent> */
    private function components(ProductVariant $variant): array
    {
        /** @var list<ProductComponent> $components */
        $components = $this->entityManager->getRepository(ProductComponent::class)->findBy(
            ['variant' => $variant],
        );

        usort($components, static fn (ProductComponent $a, ProductComponent $b): int => strcmp($a->getComponent()->getSku(), $b->getComponent()->getSku()));

        return $components;
    }

    /** @return array<string, mixed> */
    private function serialize(User $user, ProductVariant $variant): array
    {
        $lines = [];
        $estimated = '0.0000';

        foreach ($this->components($variant) as $line) {
            $component = $line->getComponent();
            $unitCost = $this->stockCostService->unitCost($user->companyId(), $component);
            $lineCost = bcmul($line->getQuantityPerUnit()->amount(), $unitCost, 4);
            $estimated = bcadd($estimated, $lineCost, 4);

            $lines[] = [
                'id' => $line->getId(),
                'component_variant_id' => $component->getId(),
                'sku' => $component->getSku(),
                'product_name' => $component->getProduct()->getName(),
                'variant_name' => $component->getName(),
                'unit' => $component->getProduct()->getUnit(),
                'quantity_per_unit' => $line->getQuantityPerUnit()->amount(),
                'unit_cost' => $unitCost,
                'line_cost' => $lineCost,
            ];
        }

        return [
            'variant_id' => $variant->getId(),
            'sku' => $variant->getSku(),
            'components' => $lines,
            'estimated_material_cost' => $estimated,
        ];
    }

    private function findVariant(User $user, string $variantId): ProductVariant
    {
        /** @var ProductVariant|null $variant */
        $variant = $this->entityManager->getRepository(ProductVariant::class)->findOneBy([
            'id' => $variantId,
            'companyId' => $user->companyId()->toString(),
        ]);

        if ($variant === null) {
            throw new NotFoundHttpException('Variant not found.');
        }

        return $variant;
    }
}

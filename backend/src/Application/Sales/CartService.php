<?php

declare(strict_types=1);

namespace App\Application\Sales;

use App\Application\Catalog\PricingService;
use App\Application\Inventory\AvailabilityService;
use App\Application\Settings\TaxSettingsService;
use App\Domain\Catalog\BackorderPolicy;
use App\Domain\Shared\EntityId;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use App\Infrastructure\Persistence\Entity\Catalog\ProductVariant;
use App\Infrastructure\Persistence\Entity\Customer\PortalUser;
use App\Infrastructure\Persistence\Entity\Identity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class CartService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PricingService $pricingService,
        private AvailabilityService $availabilityService,
        private TaxSettingsService $taxSettingsService,
    ) {}

    /**
     * @param list<array{variant_id: string, quantity: string}> $items
     *
     * @return array<string, mixed>
     */
    public function validate(User $user, array $items, ?string $customerId = null): array
    {
        if ($items === []) {
            throw new BadRequestHttpException('Cart must contain at least one item.');
        }

        $companyId = $user->companyId();
        $resolvedCustomerId = $this->resolveCustomerId($user, $customerId);

        $lines = [];
        $currency = null;
        $subtotal = Money::zero('TND');
        $taxTotal = Money::zero('TND');
        $grandTotal = Money::zero('TND');
        // Percentage, e.g. "20.0000"; copied onto each order item so later rate changes don't alter this order.
        $taxRate = $this->taxSettingsService->defaultTaxRate($companyId);

        foreach ($items as $index => $item) {
            $variant = $this->findVariant($companyId, $item['variant_id']);
            $quantity = Quantity::of($item['quantity']);

            if ($quantity->isZero()) {
                throw new BadRequestHttpException(sprintf('Line %d quantity must be greater than zero.', $index + 1));
            }

            $product = $variant->getProduct();

            if (!$product->isActive() || !$variant->isActive()) {
                throw new BadRequestHttpException(sprintf('Variant %s is not available.', $variant->getSku()));
            }

            $pricing = $this->pricingService->resolveForVariant(
                $variant,
                $companyId,
                $resolvedCustomerId,
            );

            $unitPrice = Money::of($pricing['amount'], $pricing['currency']);
            $currency ??= $unitPrice->currency();

            if ($currency !== $unitPrice->currency()) {
                throw new BadRequestHttpException('All cart lines must use the same currency.');
            }

            $lineSubtotal = Money::of(
                bcmul($unitPrice->amount(), $quantity->amount(), 4),
                $unitPrice->currency(),
            );
            $lineTax = Money::of(
                bcmul($lineSubtotal->amount(), bcdiv($taxRate, '100', 6), 4),
                $unitPrice->currency(),
            );
            $lineTotal = $lineSubtotal->add($lineTax);

            $availability = $this->availabilityService->forVariant($companyId, $variant);
            $available = Quantity::of($availability['available_to_sell']);
            $canFulfill = $available->compare($quantity) >= 0;
            $backorderAllowed = $product->getBackorderPolicy() === BackorderPolicy::Allow;

            $lines[] = [
                'variant_id' => $variant->getId(),
                'product_id' => $product->getId(),
                'product_name' => $product->getName(),
                'variant_name' => $variant->getName(),
                'sku' => $variant->getSku(),
                'quantity' => $quantity->amount(),
                'unit_price' => [
                    'amount' => $unitPrice->amount(),
                    'currency' => $unitPrice->currency(),
                    'source' => $pricing['source'],
                ],
                'tax_rate' => $taxRate,
                'discount_amount' => ['amount' => '0.0000', 'currency' => $unitPrice->currency()],
                'line_subtotal' => ['amount' => $lineSubtotal->amount(), 'currency' => $unitPrice->currency()],
                'line_tax' => ['amount' => $lineTax->amount(), 'currency' => $unitPrice->currency()],
                'line_total' => ['amount' => $lineTotal->amount(), 'currency' => $unitPrice->currency()],
                'availability' => $availability,
                'can_fulfill_from_stock' => $canFulfill,
                'backorder_allowed' => $backorderAllowed,
                'will_backorder' => !$canFulfill && $backorderAllowed,
                'blocked' => !$canFulfill && !$backorderAllowed,
            ];

            $subtotal = $subtotal->add($lineSubtotal);
            $taxTotal = $taxTotal->add($lineTax);
            $grandTotal = $grandTotal->add($lineTotal);
        }

        $hasBlocked = false;
        foreach ($lines as $line) {
            if ($line['blocked']) {
                $hasBlocked = true;
                break;
            }
        }

        return [
            'customer_id' => $resolvedCustomerId?->toString(),
            'currency' => $currency ?? 'TND',
            'lines' => $lines,
            'subtotal' => ['amount' => $subtotal->amount(), 'currency' => $subtotal->currency()],
            'tax_total' => ['amount' => $taxTotal->amount(), 'currency' => $taxTotal->currency()],
            'discount_total' => ['amount' => '0.0000', 'currency' => $subtotal->currency()],
            'grand_total' => ['amount' => $grandTotal->amount(), 'currency' => $grandTotal->currency()],
            'valid' => !$hasBlocked,
        ];
    }

    private function resolveCustomerId(User $user, ?string $customerId): ?EntityId
    {
        if ($customerId !== null) {
            return EntityId::fromString($customerId);
        }

        if (!$user->isPortalUser()) {
            return null;
        }

        /** @var PortalUser|null $portalUser */
        $portalUser = $this->entityManager->getRepository(PortalUser::class)->findOneBy(['user' => $user]);

        return $portalUser !== null
            ? EntityId::fromString($portalUser->getCustomer()->getId())
            : null;
    }

    private function findVariant(EntityId $companyId, string $variantId): ProductVariant
    {
        /** @var ProductVariant|null $variant */
        $variant = $this->entityManager->getRepository(ProductVariant::class)->findOneBy([
            'id' => $variantId,
            'companyId' => $companyId->toString(),
        ]);

        if ($variant === null) {
            throw new BadRequestHttpException(sprintf('Variant %s not found.', $variantId));
        }

        return $variant;
    }
}

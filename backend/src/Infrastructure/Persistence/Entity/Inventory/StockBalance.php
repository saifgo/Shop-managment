<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Inventory;

use App\Domain\Shared\CompanyScoped;
use App\Domain\Shared\EntityId;
use App\Domain\Shared\Quantity;
use App\Infrastructure\Persistence\Entity\Catalog\ProductVariant;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'stock_balances')]
#[ORM\UniqueConstraint(name: 'UNIQ_STOCK_BALANCE', columns: ['company_id', 'variant_id', 'location_id'])]
class StockBalance implements CompanyScoped
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\Column(name: 'company_id', type: 'string', length: 26)]
    private string $companyId;

    #[ORM\ManyToOne(targetEntity: ProductVariant::class)]
    #[ORM\JoinColumn(name: 'variant_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ProductVariant $variant;

    #[ORM\ManyToOne(targetEntity: StockLocation::class)]
    #[ORM\JoinColumn(name: 'location_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private StockLocation $location;

    #[ORM\Column(name: 'physical_on_hand', type: 'decimal', precision: 19, scale: 4, options: ['default' => '0.0000'])]
    private string $physicalOnHand = '0.0000';

    #[ORM\Column(type: 'decimal', precision: 19, scale: 4, options: ['default' => '0.0000'])]
    private string $reserved = '0.0000';

    /** Moving-average cost of one unit on hand, in the company currency. */
    #[ORM\Column(name: 'average_cost', type: 'decimal', precision: 19, scale: 4, options: ['default' => '0.0000'])]
    private string $averageCost = '0.0000';

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        EntityId $id,
        EntityId $companyId,
        ProductVariant $variant,
        StockLocation $location,
    ) {
        $this->id = $id->toString();
        $this->companyId = $companyId->toString();
        $this->variant = $variant;
        $this->location = $location;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function companyId(): EntityId
    {
        return EntityId::fromString($this->companyId);
    }

    public function getVariant(): ProductVariant
    {
        return $this->variant;
    }

    public function getLocation(): StockLocation
    {
        return $this->location;
    }

    public function getPhysicalOnHand(): Quantity
    {
        return Quantity::of($this->physicalOnHand);
    }

    public function getReserved(): Quantity
    {
        return Quantity::of($this->reserved);
    }

    public function getAvailableToSell(): Quantity
    {
        $available = bcsub($this->physicalOnHand, $this->reserved, 4);

        if (bccomp($available, '0', 4) < 0) {
            return Quantity::zero();
        }

        return Quantity::of($available);
    }

    public function getAverageCost(): string
    {
        return bcadd($this->averageCost, '0', 4);
    }

    /** Value of the stock on hand at the current average cost. */
    public function getValue(): string
    {
        return bcmul($this->physicalOnHand, $this->getAverageCost(), 4);
    }

    /**
     * Applies a movement and returns the unit cost it is valued at.
     *
     * Receipts carrying a unit cost blend into the moving average; receipts without one (found
     * stock, sellable returns) come in at the current average; issues always leave at the average,
     * so cost of goods sold and stock valuation stay consistent.
     */
    public function applyMovement(string $quantityDelta, string $reservedDelta, ?string $unitCost = null): string
    {
        $newPhysical = bcadd($this->physicalOnHand, $quantityDelta, 4);
        $newReserved = bcadd($this->reserved, $reservedDelta, 4);

        if (bccomp($newPhysical, '0', 4) < 0) {
            throw new \DomainException('Physical stock cannot become negative.');
        }

        if (bccomp($newReserved, '0', 4) < 0) {
            throw new \DomainException('Reserved stock cannot become negative.');
        }

        if (bccomp($newReserved, $newPhysical, 4) > 0) {
            throw new \DomainException('Reserved quantity cannot exceed physical on hand.');
        }

        $movementCost = $this->getAverageCost();

        if ($unitCost !== null && bccomp($quantityDelta, '0', 4) > 0) {
            $movementCost = bcadd($unitCost, '0', 4);
            $totalValue = bcadd(bcmul($this->physicalOnHand, $this->averageCost, 6), bcmul($quantityDelta, $unitCost, 6), 6);
            // Round half up: bcdiv truncates.
            $this->averageCost = bcadd(bcdiv($totalValue, $newPhysical, 6), '0.00005', 4);
        }

        $this->physicalOnHand = $newPhysical;
        $this->reserved = $newReserved;
        $this->updatedAt = new \DateTimeImmutable();

        return $movementCost;
    }
}

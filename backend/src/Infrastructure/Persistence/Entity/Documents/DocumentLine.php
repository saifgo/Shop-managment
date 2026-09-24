<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Documents;

use App\Domain\Shared\EntityId;
use App\Domain\Shared\Money;
use App\Domain\Shared\Quantity;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'document_lines')]
class DocumentLine
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: CommercialDocument::class, inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'document_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private CommercialDocument $document;

    #[ORM\Column(name: 'source_line_id', type: 'string', length: 26, nullable: true)]
    private ?string $sourceLineId;

    #[ORM\Column(length: 255)]
    private string $description;

    #[ORM\Column(length: 64)]
    private string $sku;

    #[ORM\Column(type: 'decimal', precision: 19, scale: 4)]
    private string $quantity;

    #[ORM\Column(name: 'unit_price_amount', type: 'decimal', precision: 19, scale: 4)]
    private string $unitPriceAmount;

    #[ORM\Column(name: 'tax_rate', type: 'decimal', precision: 8, scale: 4)]
    private string $taxRate;

    #[ORM\Column(name: 'discount_amount', type: 'decimal', precision: 19, scale: 4, options: ['default' => '0.0000'])]
    private string $discountAmount = '0.0000';

    #[ORM\Column(name: 'line_subtotal_amount', type: 'decimal', precision: 19, scale: 4)]
    private string $lineSubtotalAmount;

    #[ORM\Column(name: 'line_tax_amount', type: 'decimal', precision: 19, scale: 4)]
    private string $lineTaxAmount;

    #[ORM\Column(name: 'line_total_amount', type: 'decimal', precision: 19, scale: 4)]
    private string $lineTotalAmount;

    #[ORM\Column(name: 'sort_order', options: ['default' => 0])]
    private int $sortOrder = 0;

    public function __construct(
        EntityId $id,
        CommercialDocument $document,
        ?string $sourceLineId,
        string $description,
        string $sku,
        Quantity $quantity,
        Money $unitPrice,
        string $taxRate,
        Money $discountAmount,
        Money $lineSubtotal,
        Money $lineTax,
        Money $lineTotal,
        int $sortOrder = 0,
    ) {
        $this->id = $id->toString();
        $this->document = $document;
        $this->sourceLineId = $sourceLineId;
        $this->description = $description;
        $this->sku = $sku;
        $this->quantity = $quantity->amount();
        $this->unitPriceAmount = $unitPrice->amount();
        $this->taxRate = $taxRate;
        $this->discountAmount = $discountAmount->amount();
        $this->lineSubtotalAmount = $lineSubtotal->amount();
        $this->lineTaxAmount = $lineTax->amount();
        $this->lineTotalAmount = $lineTotal->amount();
        $this->sortOrder = $sortOrder;
        $document->addLine($this);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getDocument(): CommercialDocument
    {
        return $this->document;
    }

    public function getSourceLineId(): ?string
    {
        return $this->sourceLineId;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function getQuantity(): Quantity
    {
        return Quantity::of($this->quantity);
    }

    public function getUnitPrice(): Money
    {
        return Money::of($this->unitPriceAmount, $this->document->getCurrency());
    }

    public function getTaxRate(): string
    {
        return $this->taxRate;
    }

    public function getDiscountAmount(): Money
    {
        return Money::of($this->discountAmount, $this->document->getCurrency());
    }

    public function getLineSubtotal(): Money
    {
        return Money::of($this->lineSubtotalAmount, $this->document->getCurrency());
    }

    public function getLineTax(): Money
    {
        return Money::of($this->lineTaxAmount, $this->document->getCurrency());
    }

    public function getLineTotal(): Money
    {
        return Money::of($this->lineTotalAmount, $this->document->getCurrency());
    }
}

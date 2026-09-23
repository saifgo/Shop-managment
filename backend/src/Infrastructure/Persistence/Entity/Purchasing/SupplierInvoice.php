<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Purchasing;

use App\Domain\Purchasing\SupplierInvoiceStatus;
use App\Domain\Shared\CompanyScoped;
use App\Domain\Shared\EntityId;
use App\Domain\Shared\Money;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'supplier_invoices')]
#[ORM\UniqueConstraint(name: 'UNIQ_SUPPLIER_INVOICE_NUMBER', columns: ['company_id', 'invoice_number'])]
class SupplierInvoice implements CompanyScoped
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\Column(name: 'company_id', type: 'string', length: 26)]
    private string $companyId;

    #[ORM\ManyToOne(targetEntity: Supplier::class)]
    #[ORM\JoinColumn(name: 'supplier_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Supplier $supplier;

    #[ORM\ManyToOne(targetEntity: PurchaseOrder::class)]
    #[ORM\JoinColumn(name: 'purchase_order_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?PurchaseOrder $purchaseOrder;

    #[ORM\Column(name: 'invoice_number', length: 64)]
    private string $invoiceNumber;

    #[ORM\Column(length: 32, enumType: SupplierInvoiceStatus::class)]
    private SupplierInvoiceStatus $status;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(name: 'total_amount', type: 'decimal', precision: 19, scale: 4)]
    private string $totalAmount;

    #[ORM\Column(name: 'amount_paid', type: 'decimal', precision: 19, scale: 4, options: ['default' => '0.0000'])]
    private string $amountPaid = '0.0000';

    #[ORM\Column(name: 'issued_at', type: 'date_immutable')]
    private \DateTimeImmutable $issuedAt;

    #[ORM\Column(name: 'due_date', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dueDate;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        EntityId $id,
        EntityId $companyId,
        Supplier $supplier,
        string $invoiceNumber,
        Money $totalAmount,
        \DateTimeImmutable $issuedAt,
        ?PurchaseOrder $purchaseOrder = null,
        ?\DateTimeImmutable $dueDate = null,
        ?string $notes = null,
    ) {
        $this->id = $id->toString();
        $this->companyId = $companyId->toString();
        $this->supplier = $supplier;
        $this->purchaseOrder = $purchaseOrder;
        $this->invoiceNumber = $invoiceNumber;
        $this->status = SupplierInvoiceStatus::Issued;
        $this->currency = $totalAmount->currency();
        $this->totalAmount = $totalAmount->amount();
        $this->issuedAt = $issuedAt;
        $this->dueDate = $dueDate;
        $this->notes = $notes;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function companyId(): EntityId
    {
        return EntityId::fromString($this->companyId);
    }

    public function getSupplier(): Supplier
    {
        return $this->supplier;
    }

    public function getPurchaseOrder(): ?PurchaseOrder
    {
        return $this->purchaseOrder;
    }

    public function getInvoiceNumber(): string
    {
        return $this->invoiceNumber;
    }

    public function getStatus(): SupplierInvoiceStatus
    {
        return $this->status;
    }

    public function getTotalAmount(): Money
    {
        return Money::of($this->totalAmount, $this->currency);
    }

    public function getAmountPaid(): Money
    {
        return Money::of($this->amountPaid, $this->currency);
    }

    public function getAmountDue(): Money
    {
        return $this->getTotalAmount()->subtract($this->getAmountPaid());
    }

    public function getIssuedAt(): \DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function getDueDate(): ?\DateTimeImmutable
    {
        return $this->dueDate;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function applyPayment(Money $amount): void
    {
        $newPaid = $this->getAmountPaid()->add($amount);

        if ($newPaid->compare($this->getTotalAmount()) > 0) {
            throw new \DomainException('Payment exceeds invoice total.');
        }

        $this->amountPaid = $newPaid->amount();

        if ($newPaid->equals($this->getTotalAmount())) {
            $this->status = SupplierInvoiceStatus::Paid;
        } elseif (!$newPaid->isZero()) {
            $this->status = SupplierInvoiceStatus::PartiallyPaid;
        }
    }
}

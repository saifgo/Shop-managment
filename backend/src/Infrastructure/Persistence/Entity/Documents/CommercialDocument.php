<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Documents;

use App\Domain\Documents\DocumentStatus;
use App\Domain\Documents\DocumentType;
use App\Domain\Documents\InvoiceStatus;
use App\Domain\Shared\CompanyScoped;
use App\Domain\Shared\EntityId;
use App\Domain\Shared\Money;
use App\Infrastructure\Persistence\Entity\Customer\Customer;
use App\Infrastructure\Persistence\Entity\Sales\Delivery;
use App\Infrastructure\Persistence\Entity\Sales\Order;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'commercial_documents')]
#[ORM\UniqueConstraint(name: 'UNIQ_DOCUMENT_NUMBER', columns: ['company_id', 'document_type', 'fiscal_year', 'document_number'])]
#[ORM\UniqueConstraint(name: 'UNIQ_DOCUMENT_IDEMPOTENCY', columns: ['company_id', 'idempotency_key'])]
#[ORM\UniqueConstraint(name: 'UNIQ_DOCUMENT_SHARE_TOKEN', columns: ['share_token'])]
class CommercialDocument implements CompanyScoped
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\Column(name: 'company_id', type: 'string', length: 26)]
    private string $companyId;

    #[ORM\Column(name: 'document_type', length: 32, enumType: DocumentType::class)]
    private DocumentType $documentType;

    #[ORM\Column(length: 32)]
    private string $status;

    #[ORM\Column(name: 'document_number', length: 32, nullable: true)]
    private ?string $documentNumber;

    #[ORM\Column(name: 'fiscal_year', type: 'integer', nullable: true)]
    private ?int $fiscalYear;

    #[ORM\ManyToOne(targetEntity: Customer::class)]
    #[ORM\JoinColumn(name: 'customer_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Customer $customer;

    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Order $order;

    #[ORM\ManyToOne(targetEntity: Delivery::class)]
    #[ORM\JoinColumn(name: 'delivery_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?Delivery $delivery;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'source_document_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?CommercialDocument $sourceDocument;

    #[ORM\Column(name: 'customer_display_name', length: 200)]
    private string $customerDisplayName;

    #[ORM\Column(name: 'customer_legal_name', length: 200, nullable: true)]
    private ?string $customerLegalName;

    #[ORM\Column(name: 'customer_tax_id', length: 64, nullable: true)]
    private ?string $customerTaxId;

    #[ORM\Column(name: 'customer_vat_number', length: 64, nullable: true)]
    private ?string $customerVatNumber;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'billing_address', type: 'json', nullable: true)]
    private ?array $billingAddress;

    /** @var array<string, mixed>|null */
    #[ORM\Column(name: 'shipping_address', type: 'json', nullable: true)]
    private ?array $shippingAddress;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(name: 'subtotal_amount', type: 'decimal', precision: 19, scale: 4)]
    private string $subtotalAmount;

    #[ORM\Column(name: 'tax_total_amount', type: 'decimal', precision: 19, scale: 4)]
    private string $taxTotalAmount;

    #[ORM\Column(name: 'discount_total_amount', type: 'decimal', precision: 19, scale: 4)]
    private string $discountTotalAmount;

    /** Invoice stamp duty ("timbre fiscal"), already included in the grand total. */
    #[ORM\Column(name: 'stamp_duty_amount', type: 'decimal', precision: 19, scale: 4, options: ['default' => '0.0000'])]
    private string $stampDutyAmount = '0.0000';

    #[ORM\Column(name: 'grand_total_amount', type: 'decimal', precision: 19, scale: 4)]
    private string $grandTotalAmount;

    #[ORM\Column(name: 'amount_paid', type: 'decimal', precision: 19, scale: 4, options: ['default' => '0.0000'])]
    private string $amountPaid = '0.0000';

    #[ORM\Column(name: 'is_posted', options: ['default' => false])]
    private bool $isPosted = false;

    #[ORM\Column(name: 'posted_at', nullable: true)]
    private ?\DateTimeImmutable $postedAt = null;

    #[ORM\Column(name: 'issued_at', nullable: true)]
    private ?\DateTimeImmutable $issuedAt = null;

    #[ORM\Column(name: 'due_date', type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dueDate = null;

    #[ORM\Column(name: 'cancelled_at', nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes;

    #[ORM\Column(name: 'idempotency_key', length: 255, nullable: true)]
    private ?string $idempotencyKey;

    /** Secret for the public share link; null while the document is not shared. */
    #[ORM\Column(name: 'share_token', length: 64, nullable: true)]
    private ?string $shareToken = null;

    #[ORM\Column(name: 'created_by', type: 'string', length: 26, nullable: true)]
    private ?string $createdBy;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, DocumentLine> */
    #[ORM\OneToMany(mappedBy: 'document', targetEntity: DocumentLine::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $lines;

    public function __construct(
        EntityId $id,
        EntityId $companyId,
        DocumentType $documentType,
        string $status,
        Customer $customer,
        string $customerDisplayName,
        ?string $customerLegalName,
        ?string $customerTaxId,
        ?string $customerVatNumber,
        ?array $billingAddress,
        ?array $shippingAddress,
        string $currency,
        Money $subtotal,
        Money $taxTotal,
        Money $discountTotal,
        Money $grandTotal,
        ?Order $order = null,
        ?Delivery $delivery = null,
        ?CommercialDocument $sourceDocument = null,
        ?string $notes = null,
        ?string $idempotencyKey = null,
        ?EntityId $createdBy = null,
        ?Money $stampDuty = null,
    ) {
        $this->id = $id->toString();
        $this->companyId = $companyId->toString();
        $this->documentType = $documentType;
        $this->status = $status;
        $this->documentNumber = null;
        $this->fiscalYear = null;
        $this->customer = $customer;
        $this->order = $order;
        $this->delivery = $delivery;
        $this->sourceDocument = $sourceDocument;
        $this->customerDisplayName = $customerDisplayName;
        $this->customerLegalName = $customerLegalName;
        $this->customerTaxId = $customerTaxId;
        $this->customerVatNumber = $customerVatNumber;
        $this->billingAddress = $billingAddress;
        $this->shippingAddress = $shippingAddress;
        $this->currency = strtoupper($currency);
        $this->subtotalAmount = $subtotal->amount();
        $this->taxTotalAmount = $taxTotal->amount();
        $this->discountTotalAmount = $discountTotal->amount();
        $this->grandTotalAmount = $grandTotal->amount();
        $this->stampDutyAmount = $stampDuty?->amount() ?? '0.0000';
        $this->notes = $notes;
        $this->idempotencyKey = $idempotencyKey;
        $this->createdBy = $createdBy?->toString();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->lines = new ArrayCollection();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function companyId(): EntityId
    {
        return EntityId::fromString($this->companyId);
    }

    public function getDocumentType(): DocumentType
    {
        return $this->documentType;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getDocumentNumber(): ?string
    {
        return $this->documentNumber;
    }

    public function getFiscalYear(): ?int
    {
        return $this->fiscalYear;
    }

    public function getCustomer(): Customer
    {
        return $this->customer;
    }

    public function getOrder(): ?Order
    {
        return $this->order;
    }

    public function getDelivery(): ?Delivery
    {
        return $this->delivery;
    }

    public function getSourceDocument(): ?CommercialDocument
    {
        return $this->sourceDocument;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getSubtotal(): Money
    {
        return Money::of($this->subtotalAmount, $this->currency);
    }

    public function getTaxTotal(): Money
    {
        return Money::of($this->taxTotalAmount, $this->currency);
    }

    public function getDiscountTotal(): Money
    {
        return Money::of($this->discountTotalAmount, $this->currency);
    }

    public function getStampDuty(): Money
    {
        return Money::of($this->stampDutyAmount, $this->currency);
    }

    public function getGrandTotal(): Money
    {
        return Money::of($this->grandTotalAmount, $this->currency);
    }

    public function getAmountPaid(): Money
    {
        return Money::of($this->amountPaid, $this->currency);
    }

    public function getAmountDue(): Money
    {
        return $this->getGrandTotal()->subtract($this->getAmountPaid());
    }

    public function isPosted(): bool
    {
        return $this->isPosted;
    }

    public function getPostedAt(): ?\DateTimeImmutable
    {
        return $this->postedAt;
    }

    public function getIssuedAt(): ?\DateTimeImmutable
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

    public function getIdempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    public function getShareToken(): ?string
    {
        return $this->shareToken;
    }

    public function enableSharing(string $token): void
    {
        if (!$this->isPosted) {
            throw new \DomainException('Only issued documents can be shared.');
        }

        $this->shareToken = $token;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function disableSharing(): void
    {
        $this->shareToken = null;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, DocumentLine> */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    public function addLine(DocumentLine $line): void
    {
        if (!$this->lines->contains($line)) {
            $this->lines->add($line);
        }
    }

    public function assignNumber(string $documentNumber, int $fiscalYear): void
    {
        $this->documentNumber = $documentNumber;
        $this->fiscalYear = $fiscalYear;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function post(?\DateTimeImmutable $issuedAt = null, ?\DateTimeImmutable $dueDate = null): void
    {
        if ($this->isPosted) {
            throw new \DomainException('Document is already posted and immutable.');
        }

        $this->isPosted = true;
        $this->postedAt = new \DateTimeImmutable();
        $this->issuedAt = $issuedAt ?? $this->postedAt;
        $this->dueDate = $dueDate;
        $this->updatedAt = $this->postedAt;

        if ($this->documentType === DocumentType::Invoice) {
            $this->status = InvoiceStatus::Issued->value;
        } elseif ($this->documentType === DocumentType::CreditNote) {
            $this->status = DocumentStatus::Posted->value;
        } else {
            $this->status = DocumentStatus::Posted->value;
        }
    }

    public function setStatus(string $status): void
    {
        if ($this->isPosted && $this->documentType === DocumentType::Invoice) {
            $allowed = [
                InvoiceStatus::Issued->value,
                InvoiceStatus::PartiallyPaid->value,
                InvoiceStatus::Paid->value,
                InvoiceStatus::Overdue->value,
                InvoiceStatus::Credited->value,
            ];

            if (!in_array($status, $allowed, true)) {
                throw new \DomainException('Posted invoice status can only move through payment lifecycle.');
            }
        }

        $this->status = $status;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function cancel(): void
    {
        if ($this->isPosted) {
            throw new \DomainException('Posted documents cannot be cancelled directly; use credit note workflow.');
        }

        $this->status = $this->documentType === DocumentType::Invoice
            ? InvoiceStatus::Cancelled->value
            : DocumentStatus::Cancelled->value;
        $this->cancelledAt = new \DateTimeImmutable();
        $this->updatedAt = $this->cancelledAt;
    }

    public function applyPayment(Money $amount): void
    {
        if ($this->documentType !== DocumentType::Invoice) {
            throw new \DomainException('Payments apply only to invoices.');
        }

        if (!$this->isPosted) {
            throw new \DomainException('Cannot allocate payment to unissued invoice.');
        }

        $newPaid = $this->getAmountPaid()->add($amount);
        $this->amountPaid = $newPaid->amount();
        $this->updatedAt = new \DateTimeImmutable();

        $due = $this->getGrandTotal();

        if ($newPaid->equals($due) || bccomp($newPaid->amount(), $due->amount(), 4) > 0) {
            $this->status = InvoiceStatus::Paid->value;
        } elseif (!$newPaid->isZero()) {
            $this->status = InvoiceStatus::PartiallyPaid->value;
        }
    }

    public function assertMutable(): void
    {
        if ($this->isPosted) {
            throw new \DomainException('Posted document is immutable. Corrections require a credit note.');
        }
    }

    /** @return array<string, mixed>|null */
    public function getBillingAddress(): ?array
    {
        return $this->billingAddress;
    }

    /** @return array<string, mixed>|null */
    public function getShippingAddress(): ?array
    {
        return $this->shippingAddress;
    }

    public function getCustomerDisplayName(): string
    {
        return $this->customerDisplayName;
    }

    public function getCustomerLegalName(): ?string
    {
        return $this->customerLegalName;
    }

    public function getCustomerTaxId(): ?string
    {
        return $this->customerTaxId;
    }

    public function getCustomerVatNumber(): ?string
    {
        return $this->customerVatNumber;
    }
}

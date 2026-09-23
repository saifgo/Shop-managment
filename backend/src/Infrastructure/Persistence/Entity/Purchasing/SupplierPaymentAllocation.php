<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Purchasing;

use App\Domain\Shared\EntityId;
use App\Domain\Shared\Money;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'supplier_payment_allocations')]
class SupplierPaymentAllocation
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: SupplierPayment::class, inversedBy: 'allocations')]
    #[ORM\JoinColumn(name: 'payment_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private SupplierPayment $payment;

    #[ORM\ManyToOne(targetEntity: SupplierInvoice::class)]
    #[ORM\JoinColumn(name: 'invoice_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private SupplierInvoice $invoice;

    #[ORM\Column(name: 'allocated_amount', type: 'decimal', precision: 19, scale: 4)]
    private string $allocatedAmount;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        EntityId $id,
        SupplierPayment $payment,
        SupplierInvoice $invoice,
        Money $allocatedAmount,
    ) {
        $this->id = $id->toString();
        $this->payment = $payment;
        $this->invoice = $invoice;
        $this->allocatedAmount = $allocatedAmount->amount();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getPayment(): SupplierPayment
    {
        return $this->payment;
    }

    public function getInvoice(): SupplierInvoice
    {
        return $this->invoice;
    }

    public function getAllocatedAmount(): Money
    {
        return Money::of($this->allocatedAmount, $this->payment->getAmount()->currency());
    }
}

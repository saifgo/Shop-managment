<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Payments;

use App\Domain\Shared\EntityId;
use App\Domain\Shared\Money;
use App\Infrastructure\Persistence\Entity\Documents\CommercialDocument;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'payment_allocations')]
class PaymentAllocation
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Payment::class, inversedBy: 'allocations')]
    #[ORM\JoinColumn(name: 'payment_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Payment $payment;

    #[ORM\ManyToOne(targetEntity: CommercialDocument::class)]
    #[ORM\JoinColumn(name: 'invoice_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private CommercialDocument $invoice;

    #[ORM\Column(name: 'allocated_amount', type: 'decimal', precision: 19, scale: 4)]
    private string $allocatedAmount;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        EntityId $id,
        Payment $payment,
        CommercialDocument $invoice,
        Money $allocatedAmount,
    ) {
        $this->id = $id->toString();
        $this->payment = $payment;
        $this->invoice = $invoice;
        $this->allocatedAmount = $allocatedAmount->amount();
        $this->createdAt = new \DateTimeImmutable();
        $payment->addAllocation($this);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getPayment(): Payment
    {
        return $this->payment;
    }

    public function getInvoice(): CommercialDocument
    {
        return $this->invoice;
    }

    public function getAllocatedAmount(): Money
    {
        return Money::of($this->allocatedAmount, $this->payment->getAmount()->currency());
    }
}

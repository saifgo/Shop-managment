<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Finance;

use App\Domain\Finance\RecurrenceFrequency;
use App\Domain\Finance\ScheduledTransactionType;
use App\Domain\Shared\CompanyScoped;
use App\Domain\Shared\EntityId;
use App\Domain\Shared\Money;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'scheduled_transactions')]
class ScheduledTransaction implements CompanyScoped
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\Column(name: 'company_id', type: 'string', length: 26)]
    private string $companyId;

    #[ORM\Column(length: 32, enumType: ScheduledTransactionType::class)]
    private ScheduledTransactionType $type;

    #[ORM\ManyToOne(targetEntity: FinanceCategory::class)]
    #[ORM\JoinColumn(name: 'category_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private FinanceCategory $category;

    #[ORM\Column(length: 200)]
    private string $description;

    #[ORM\Column(name: 'amount', type: 'decimal', precision: 19, scale: 4)]
    private string $amount;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(length: 32, enumType: RecurrenceFrequency::class)]
    private RecurrenceFrequency $recurrence;

    #[ORM\Column(name: 'next_run_at', type: 'date_immutable')]
    private \DateTimeImmutable $nextRunAt;

    #[ORM\Column(name: 'is_active', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(name: 'attachment_ref', length: 512, nullable: true)]
    private ?string $attachmentRef;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        EntityId $id,
        EntityId $companyId,
        ScheduledTransactionType $type,
        FinanceCategory $category,
        string $description,
        Money $amount,
        RecurrenceFrequency $recurrence,
        \DateTimeImmutable $nextRunAt,
        ?string $attachmentRef = null,
    ) {
        $this->id = $id->toString();
        $this->companyId = $companyId->toString();
        $this->type = $type;
        $this->category = $category;
        $this->description = $description;
        $this->amount = $amount->amount();
        $this->currency = $amount->currency();
        $this->recurrence = $recurrence;
        $this->nextRunAt = $nextRunAt;
        $this->attachmentRef = $attachmentRef;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function companyId(): EntityId
    {
        return EntityId::fromString($this->companyId);
    }

    public function getType(): ScheduledTransactionType
    {
        return $this->type;
    }

    public function getCategory(): FinanceCategory
    {
        return $this->category;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getAmount(): Money
    {
        return Money::of($this->amount, $this->currency);
    }

    public function getRecurrence(): RecurrenceFrequency
    {
        return $this->recurrence;
    }

    public function getNextRunAt(): \DateTimeImmutable
    {
        return $this->nextRunAt;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getAttachmentRef(): ?string
    {
        return $this->attachmentRef;
    }

    public function update(
        string $description,
        Money $amount,
        RecurrenceFrequency $recurrence,
        \DateTimeImmutable $nextRunAt,
        bool $isActive,
        ?string $attachmentRef,
    ): void {
        $this->description = $description;
        $this->amount = $amount->amount();
        $this->currency = $amount->currency();
        $this->recurrence = $recurrence;
        $this->nextRunAt = $nextRunAt;
        $this->isActive = $isActive;
        $this->attachmentRef = $attachmentRef;
        $this->updatedAt = new \DateTimeImmutable();
    }
}

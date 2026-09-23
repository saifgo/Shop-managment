<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Finance;

use App\Domain\Shared\CompanyScoped;
use App\Domain\Shared\EntityId;
use App\Domain\Shared\Money;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'incomes')]
class Income implements CompanyScoped
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\Column(name: 'company_id', type: 'string', length: 26)]
    private string $companyId;

    #[ORM\ManyToOne(targetEntity: FinanceCategory::class)]
    #[ORM\JoinColumn(name: 'category_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private FinanceCategory $category;

    #[ORM\Column(length: 200)]
    private string $source;

    #[ORM\Column(name: 'amount', type: 'decimal', precision: 19, scale: 4)]
    private string $amount;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(name: 'income_date', type: 'date_immutable')]
    private \DateTimeImmutable $incomeDate;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $reference;

    #[ORM\Column(name: 'attachment_ref', length: 512, nullable: true)]
    private ?string $attachmentRef;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes;

    #[ORM\Column(name: 'created_by', type: 'string', length: 26, nullable: true)]
    private ?string $createdBy;

    #[ORM\Column(name: 'created_at')]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        EntityId $id,
        EntityId $companyId,
        FinanceCategory $category,
        string $source,
        Money $amount,
        \DateTimeImmutable $incomeDate,
        ?string $reference = null,
        ?string $attachmentRef = null,
        ?string $notes = null,
        ?EntityId $createdBy = null,
    ) {
        $this->id = $id->toString();
        $this->companyId = $companyId->toString();
        $this->category = $category;
        $this->source = $source;
        $this->amount = $amount->amount();
        $this->currency = $amount->currency();
        $this->incomeDate = $incomeDate;
        $this->reference = $reference;
        $this->attachmentRef = $attachmentRef;
        $this->notes = $notes;
        $this->createdBy = $createdBy?->toString();
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

    public function getCategory(): FinanceCategory
    {
        return $this->category;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getAmount(): Money
    {
        return Money::of($this->amount, $this->currency);
    }

    public function getIncomeDate(): \DateTimeImmutable
    {
        return $this->incomeDate;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function getAttachmentRef(): ?string
    {
        return $this->attachmentRef;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

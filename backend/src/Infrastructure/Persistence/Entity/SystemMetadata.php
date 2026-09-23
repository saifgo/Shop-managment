<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Foundation entity for database connectivity verification.
 * Will be replaced/extended by domain entities in later phases.
 */
#[ORM\Entity]
#[ORM\Table(name: 'system_metadata')]
class SystemMetadata
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $key;

    #[ORM\Column(length: 255)]
    private string $value;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $key, string $value)
    {
        $this->key = $key;
        $this->value = $value;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}

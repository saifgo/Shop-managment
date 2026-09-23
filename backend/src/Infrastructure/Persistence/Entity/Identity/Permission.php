<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Entity\Identity;

use App\Domain\Shared\EntityId;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'permissions')]
class Permission
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 26)]
    private string $id;

    #[ORM\Column(length: 128, unique: true)]
    private string $code;

    #[ORM\Column(length: 128)]
    private string $name;

    #[ORM\Column(length: 64)]
    private string $module;

    /** @var Collection<int, Role> */
    #[ORM\ManyToMany(targetEntity: Role::class, mappedBy: 'permissions')]
    private Collection $roles;

    public function __construct(EntityId $id, string $code, string $name, string $module)
    {
        $this->id = $id->toString();
        $this->code = $code;
        $this->name = $name;
        $this->module = $module;
        $this->roles = new ArrayCollection();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getModule(): string
    {
        return $this->module;
    }
}

<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\{{Entity}}Repository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: {{Entity}}Repository::class)]
#[ORM\Table(name: '{{table}}')]
class {{Entity}}
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(enumType: {{Entity}}Status::class)]
    private {{Entity}}Status $status;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    private function __construct()
    {
        $this->id = Uuid::v7();
        $this->status = {{Entity}}Status::Pending;
        $this->createdAt = new \DateTimeImmutable();
    }

    public static function create(): self
    {
        return new self();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getStatus(): {{Entity}}Status
    {
        return $this->status;
    }
}

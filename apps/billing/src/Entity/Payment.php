<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PaymentRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: PaymentRepository::class)]
#[ORM\UniqueConstraint(columns: ['order_id'])]
final readonly class Payment
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\Column(type: Types::GUID)]
        private string $orderId,
        #[ORM\Column]
        private int $amount,
        #[ORM\Column]
        private bool $approved,
    ) {
        $this->id = Uuid::v7()->toRfc4122();
        $this->createdAt = new DateTimeImmutable();
    }

    public function isApproved(): bool
    {
        return $this->approved;
    }
}

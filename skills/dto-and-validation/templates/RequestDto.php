<?php

declare(strict_types=1);

namespace App\Dto\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Request DTO. Immutable, validated by #[MapRequestPayload].
 * Constraints live HERE, never on the entity or the response DTO.
 */
final class Create{{Resource}}Request
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        public readonly string $customerEmail = '',

        /** @var Create{{Resource}}LineRequest[] */
        #[Assert\Valid]
        #[Assert\Count(min: 1, minMessage: 'At least one line is required.')]
        public readonly array $items = [],
    ) {
    }
}

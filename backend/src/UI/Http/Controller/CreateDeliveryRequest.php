<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Payload for POST /api/orders/{id}/create-delivery. Lives in its own file so the autoloader can find it
 * from OrderController without DeliveryController having been loaded first.
 */
final readonly class CreateDeliveryRequest
{
    /** @param list<array{order_item_id: string, quantity: string}> $lines */
    public function __construct(
        #[Assert\Count(min: 1)]
        public array $lines,
        public ?string $notes = null,
    ) {
    }
}

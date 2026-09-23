<?php

declare(strict_types=1);

namespace App\UI\Http\Response;

use Symfony\Component\HttpFoundation\JsonResponse;

final class ApiErrorResponse extends JsonResponse
{
    /**
     * @param array<int, array<string, mixed>>|null $details
     */
    public function __construct(
        string $code,
        string $message,
        int $status,
        ?string $correlationId = null,
        ?array $details = null,
    ) {
        $payload = [
            'error' => array_filter([
                'code' => $code,
                'message' => $message,
                'details' => $details,
                'correlation_id' => $correlationId,
            ], static fn (mixed $value): bool => $value !== null),
        ];

        parent::__construct($payload, $status);
    }
}

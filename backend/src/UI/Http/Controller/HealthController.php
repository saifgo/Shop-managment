<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Infrastructure\Http\Middleware\CorrelationIdSubscriber;
use App\Infrastructure\Http\Middleware\ReadinessChecker;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'System')]
final class HealthController extends AbstractController
{
    public function __construct(
        private ReadinessChecker $readinessChecker,
        private RequestStack $requestStack,
    ) {
    }

    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    #[OA\Get(
        path: '/api/health',
        summary: 'Liveness health check',
        description: 'Returns the current health status of the Tittawin Management System API.',
    )]
    public function liveness(): JsonResponse
    {
        return $this->json([
            'status' => 'ok',
            'service' => 'Tittawin Management System',
            'version' => '0.1.0',
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/api/ready', name: 'api_ready', methods: ['GET'])]
    #[OA\Get(path: '/api/ready', summary: 'Readiness probe with dependency checks')]
    public function readiness(): JsonResponse
    {
        $result = $this->readinessChecker->check();
        $correlationId = $this->requestStack->getCurrentRequest()
            ?->attributes->get(CorrelationIdSubscriber::REQUEST_ATTRIBUTE);

        $payload = [
            'status' => $result['ready'] ? 'ready' : 'not_ready',
            'service' => 'Tittawin Management System',
            'checks' => $result['checks'],
            'timestamp' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        if (is_string($correlationId)) {
            $payload['correlation_id'] = $correlationId;
        }

        return $this->json($payload, $result['ready'] ? 200 : 503);
    }
}

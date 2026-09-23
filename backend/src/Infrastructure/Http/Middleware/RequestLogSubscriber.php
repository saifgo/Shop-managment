<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Middleware;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class RequestLogSubscriber
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 32)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $request->attributes->set('_request_start', microtime(true));
        $correlationId = $request->attributes->get(CorrelationIdSubscriber::REQUEST_ATTRIBUTE);

        $this->logger->info('request.started', [
            'correlation_id' => is_string($correlationId) ? $correlationId : null,
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'client_ip' => $request->getClientIp(),
        ]);
    }

    #[AsEventListener(event: KernelEvents::TERMINATE, priority: -32)]
    public function onTerminate(TerminateEvent $event): void
    {
        $request = $event->getRequest();
        $response = $event->getResponse();
        $start = $request->attributes->get('_request_start');
        $durationMs = is_float($start) ? (int) round((microtime(true) - $start) * 1000) : null;
        $correlationId = $request->attributes->get(CorrelationIdSubscriber::REQUEST_ATTRIBUTE);

        $this->logger->info('request.completed', [
            'correlation_id' => is_string($correlationId) ? $correlationId : null,
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'status' => $response->getStatusCode(),
            'duration_ms' => $durationMs,
        ]);
    }
}

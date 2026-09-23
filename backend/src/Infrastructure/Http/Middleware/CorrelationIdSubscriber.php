<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Middleware;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Uid\Uuid;

final class CorrelationIdSubscriber
{
    public const REQUEST_ATTRIBUTE = 'correlation_id';
    public const HEADER_NAME = 'X-Correlation-ID';

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 256)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $correlationId = $request->headers->get(self::HEADER_NAME)
            ?? $request->headers->get('X-Correlation-Id')
            ?? $request->headers->get('X-Request-Id');

        if (!is_string($correlationId) || !Uuid::isValid($correlationId)) {
            $correlationId = Uuid::v4()->toRfc4122();
        }

        $request->attributes->set(self::REQUEST_ATTRIBUTE, $correlationId);
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -256)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $correlationId = $event->getRequest()->attributes->get(self::REQUEST_ATTRIBUTE);

        if (is_string($correlationId)) {
            $event->getResponse()->headers->set(self::HEADER_NAME, $correlationId);
        }
    }
}

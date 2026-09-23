<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Middleware;

use App\UI\Http\Response\ApiErrorResponse;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Phase 0 stub: validates and echoes Idempotency-Key on mutating requests.
 * Persistent key storage is implemented in Phase 1.
 */
final class IdempotencyKeySubscriber
{
    public const REQUEST_ATTRIBUTE = 'idempotency_key';
    public const HEADER_NAME = 'Idempotency-Key';

    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 128)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), '/api')) {
            return;
        }

        if (in_array($request->getMethod(), self::SAFE_METHODS, true)) {
            return;
        }

        $idempotencyKey = $request->headers->get(self::HEADER_NAME);

        if ($idempotencyKey === null || trim($idempotencyKey) === '') {
            return;
        }

        $idempotencyKey = trim($idempotencyKey);

        if (strlen($idempotencyKey) > 255) {
            throw new BadRequestHttpException(sprintf('Header %s must be 255 characters or fewer.', self::HEADER_NAME));
        }

        $request->attributes->set(self::REQUEST_ATTRIBUTE, $idempotencyKey);
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -128)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $idempotencyKey = $event->getRequest()->attributes->get(self::REQUEST_ATTRIBUTE);

        if (!is_string($idempotencyKey)) {
            return;
        }

        if ($event->getResponse()->getStatusCode() >= Response::HTTP_BAD_REQUEST) {
            return;
        }

        $event->getResponse()->headers->set(self::HEADER_NAME, $idempotencyKey);
    }
}

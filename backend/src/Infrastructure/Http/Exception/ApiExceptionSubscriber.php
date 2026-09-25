<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Exception;

use App\Infrastructure\Http\Middleware\CorrelationIdSubscriber;
use App\UI\Http\Response\ApiErrorResponse;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Validator\Exception\ValidationFailedException;

#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 0)]
final class ApiExceptionSubscriber
{
    public function __invoke(ExceptionEvent $event): void
    {
        $request = $event->getRequest();

        if (!str_starts_with($request->getPathInfo(), '/api')) {
            return;
        }

        $exception = $event->getThrowable();
        $correlationId = $request->attributes->get(CorrelationIdSubscriber::REQUEST_ATTRIBUTE);
        $correlationId = is_string($correlationId) ? $correlationId : null;

        if ($exception instanceof ValidationFailedException) {
            $details = [];

            foreach ($exception->getViolations() as $violation) {
                $details[] = [
                    'field' => $violation->getPropertyPath(),
                    'message' => $violation->getMessage(),
                ];
            }

            $event->setResponse(new ApiErrorResponse(
                code: 'VALIDATION_ERROR',
                message: 'Request validation failed.',
                status: Response::HTTP_UNPROCESSABLE_ENTITY,
                correlationId: $correlationId,
                details: $details,
            ));

            return;
        }

        if ($exception instanceof HttpExceptionInterface) {
            $code = match ($exception->getStatusCode()) {
                Response::HTTP_NOT_FOUND => 'NOT_FOUND',
                Response::HTTP_UNAUTHORIZED => 'UNAUTHORIZED',
                Response::HTTP_FORBIDDEN => 'FORBIDDEN',
                Response::HTTP_BAD_REQUEST => 'BAD_REQUEST',
                Response::HTTP_CONFLICT => 'CONFLICT',
                Response::HTTP_TOO_MANY_REQUESTS => 'TOO_MANY_REQUESTS',
                default => 'HTTP_ERROR',
            };

            $event->setResponse(new ApiErrorResponse(
                code: $code,
                message: $exception->getMessage() !== '' ? $exception->getMessage() : Response::$statusTexts[$exception->getStatusCode()] ?? 'Error',
                status: $exception->getStatusCode(),
                correlationId: $correlationId,
            ));

            return;
        }

        // A unique index rejected the write (duplicate SKU, slug, email, ...).
        if ($exception instanceof UniqueConstraintViolationException) {
            $event->setResponse(new ApiErrorResponse(
                code: 'CONFLICT',
                message: 'A record with the same unique value already exists.',
                status: Response::HTTP_CONFLICT,
                correlationId: $correlationId,
            ));

            return;
        }

        // State machines and domain invariants throw \DomainException with a message meant for the user.
        if ($exception instanceof \DomainException) {
            $event->setResponse(new ApiErrorResponse(
                code: 'BUSINESS_RULE_VIOLATION',
                message: $exception->getMessage() !== '' ? $exception->getMessage() : 'The action is not allowed.',
                status: Response::HTTP_UNPROCESSABLE_ENTITY,
                correlationId: $correlationId,
            ));

            return;
        }

        $event->setResponse(new ApiErrorResponse(
            code: 'INTERNAL_ERROR',
            message: 'An unexpected error occurred.',
            status: Response::HTTP_INTERNAL_SERVER_ERROR,
            correlationId: $correlationId,
        ));
    }
}

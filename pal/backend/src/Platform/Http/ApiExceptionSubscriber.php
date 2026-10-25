<?php

declare(strict_types=1);

namespace App\Platform\Http;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Turns exceptions on API routes into a predictable JSON error contract without
 * leaking internals. Runs after the security exception listener (priority 1), so
 * authentication and authorization failures are already converted to 401/403.
 */
final class ApiExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly RequestContext $context,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', -64]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $path = $event->getRequest()->getPathInfo();
        if (!str_starts_with($path, '/api') && !str_starts_with($path, '/auth') && !str_starts_with($path, '/health')) {
            return;
        }
        $exception = $event->getThrowable();

        if ($exception instanceof ApiProblem) {
            $body = ['error' => $exception->errorCode, 'message' => $exception->getMessage()];
            if ([] !== $exception->fieldErrors) {
                $body['fields'] = $exception->fieldErrors;
            }
            $event->setResponse(new JsonResponse($body, $exception->getStatusCode(), $exception->getHeaders()));

            return;
        }

        if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
            $status = $exception->getStatusCode();
            $event->setResponse(new JsonResponse(
                ['error' => self::codeFor($status), 'message' => self::messageFor($status)],
                $status,
                $exception->getHeaders(),
            ));

            return;
        }

        $this->logger->error('Unhandled exception on API route', ['exception' => $exception, 'path' => $path]);
        $event->setResponse(new JsonResponse([
            'error' => 'internal_error',
            'message' => 'An unexpected error occurred.',
            'correlationId' => $this->context->correlationId(),
        ], 500));
    }

    private static function codeFor(int $status): string
    {
        return match ($status) {
            400 => 'bad_request',
            401 => 'unauthenticated',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            409 => 'conflict',
            429 => 'too_many_requests',
            default => 'client_error',
        };
    }

    private static function messageFor(int $status): string
    {
        return match ($status) {
            404 => 'The requested resource was not found.',
            405 => 'This method is not allowed here.',
            429 => 'Too many requests. Try again shortly.',
            default => 'The request could not be completed.',
        };
    }
}

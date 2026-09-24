<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

final class JwtAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private JwtTokenService $jwtTokenService,
        private UserRepository $userRepository,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        if (!str_starts_with($request->getPathInfo(), '/api')) {
            return false;
        }

        $path = $request->getPathInfo();

        if (str_starts_with($path, '/api/media/') && $request->isMethod('GET')) {
            return false;
        }

        // Exact matches only: a '/api/doc' prefix check would also skip /api/documents.
        return !in_array($path, ['/api/health', '/api/ready', '/api/auth/login', '/api/auth/refresh', '/api/doc', '/api/doc.json'], true);
    }

    public function authenticate(Request $request): SelfValidatingPassport
    {
        $authorization = $request->headers->get('Authorization', '');

        if (!str_starts_with($authorization, 'Bearer ')) {
            throw new UnauthorizedHttpException('Bearer', 'Missing or invalid authorization header.');
        }

        $token = trim(substr($authorization, 7));
        $payload = $this->jwtTokenService->validateAccessToken($token);

        if ($payload === null) {
            throw new UnauthorizedHttpException('Bearer', 'Invalid or expired access token.');
        }

        return new SelfValidatingPassport(
            new UserBadge($payload->sub, function (string $userId) {
                $user = $this->userRepository->findActiveById($userId);

                if ($user === null) {
                    throw new AuthenticationException('User not found or inactive.');
                }

                return $user;
            }),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        throw new UnauthorizedHttpException('Bearer', $exception->getMessage());
    }
}

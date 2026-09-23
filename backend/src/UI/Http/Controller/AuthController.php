<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Application\Identity\AuthService;
use App\Infrastructure\Http\Middleware\CorrelationIdSubscriber;
use App\Infrastructure\Persistence\Entity\Identity\User;
use App\Infrastructure\Security\AuthRateLimiter;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

#[OA\Tag(name: 'Authentication')]
final class AuthController extends AbstractController
{
    public function __construct(
        private AuthService $authService,
        private AuthRateLimiter $rateLimiter,
    ) {
    }

    #[Route('/api/auth/login', name: 'api_auth_login', methods: ['POST'])]
    #[OA\Post(
        path: '/api/auth/login',
        summary: 'Authenticate with email and password',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'password'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email'),
                    new OA\Property(property: 'password', type: 'string', format: 'password'),
                ],
            ),
        ),
    )]
    #[OA\Response(
        response: 200,
        description: 'Authentication successful',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'access_token', type: 'string'),
                new OA\Property(property: 'refresh_token', type: 'string'),
                new OA\Property(property: 'token_type', type: 'string', example: 'Bearer'),
                new OA\Property(property: 'expires_in', type: 'integer'),
                new OA\Property(property: 'user', type: 'object'),
            ],
        ),
    )]
    #[OA\Response(response: 401, description: 'Invalid credentials')]
    #[OA\Response(response: 429, description: 'Too many requests')]
    public function login(#[MapRequestPayload] LoginRequest $payload, Request $request): JsonResponse
    {
        $this->assertRateLimit($request, 'login');

        $tokens = $this->authService->login(
            email: $payload->email,
            password: $payload->password,
            ipAddress: $request->getClientIp(),
            userAgent: $request->headers->get('User-Agent'),
            correlationId: $this->correlationId($request),
        );

        return $this->json($tokens);
    }

    #[Route('/api/auth/refresh', name: 'api_auth_refresh', methods: ['POST'])]
    #[OA\Post(
        path: '/api/auth/refresh',
        summary: 'Refresh access token',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['refresh_token'],
                properties: [
                    new OA\Property(property: 'refresh_token', type: 'string'),
                ],
            ),
        ),
    )]
    #[OA\Response(response: 200, description: 'Token refreshed')]
    #[OA\Response(response: 401, description: 'Invalid refresh token')]
    public function refresh(#[MapRequestPayload] RefreshRequest $payload, Request $request): JsonResponse
    {
        $this->assertRateLimit($request, 'refresh');

        $tokens = $this->authService->refresh(
            refreshTokenValue: $payload->refreshToken,
            correlationId: $this->correlationId($request),
        );

        return $this->json($tokens);
    }

    #[Route('/api/auth/logout', name: 'api_auth_logout', methods: ['POST'])]
    #[OA\Post(
        path: '/api/auth/logout',
        summary: 'Logout and revoke refresh token',
        security: [['Bearer' => []]],
    )]
    #[OA\RequestBody(
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'refresh_token', type: 'string', nullable: true),
            ],
        ),
    )]
    #[OA\Response(response: 204, description: 'Logged out')]
    public function logout(Request $request, #[CurrentUser] User $user): Response
    {
        /** @var array{refresh_token?: string}|null $body */
        $body = json_decode($request->getContent(), true);
        $refreshToken = is_array($body) ? ($body['refresh_token'] ?? null) : null;

        $this->authService->logout(
            user: $user,
            refreshTokenValue: is_string($refreshToken) ? $refreshToken : null,
            correlationId: $this->correlationId($request),
        );

        return new Response('', Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    #[OA\Get(
        path: '/api/me',
        summary: 'Get current authenticated user',
        security: [['Bearer' => []]],
    )]
    #[OA\Response(response: 200, description: 'Current user profile')]
    public function me(#[CurrentUser] User $user): JsonResponse
    {
        return $this->json($this->authService->currentUser($user));
    }

    private function assertRateLimit(Request $request, string $action): void
    {
        $key = sprintf('%s:%s', $action, $request->getClientIp() ?? 'unknown');

        if (!$this->rateLimiter->isAllowed($key)) {
            throw new TooManyRequestsHttpException(message: 'Too many authentication attempts. Please try again later.');
        }
    }

    private function correlationId(Request $request): ?string
    {
        $value = $request->attributes->get(CorrelationIdSubscriber::REQUEST_ATTRIBUTE);

        return is_string($value) ? $value : null;
    }
}

final readonly class LoginRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        public string $email,
        #[Assert\NotBlank]
        #[Assert\Length(min: 8)]
        public string $password,
    ) {
    }
}

final readonly class RefreshRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[SerializedName('refresh_token')]
        public string $refreshToken,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Audit\AuditRecorder;
use App\Domain\Shared\EntityId;
use App\Infrastructure\Persistence\Entity\Customer\PortalUser;
use App\Infrastructure\Persistence\Entity\Identity\RefreshToken;
use App\Infrastructure\Persistence\Entity\Identity\User;
use App\Infrastructure\Persistence\UnitOfWork;
use App\Infrastructure\Security\JwtTokenService;
use App\Infrastructure\Security\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AuthService
{
    private const REFRESH_TTL_DAYS = 7;

    public function __construct(
        private UserRepository $userRepository,
        private UserPasswordHasherInterface $passwordHasher,
        private JwtTokenService $jwtTokenService,
        private EntityManagerInterface $entityManager,
        private UnitOfWork $unitOfWork,
        private AuditRecorder $auditRecorder,
    ) {
    }

    /**
     * @return array{
     *     access_token: string,
     *     refresh_token: string,
     *     token_type: string,
     *     expires_in: int,
     *     user: array<string, mixed>
     * }
     */
    public function login(
        string $email,
        string $password,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $correlationId = null,
    ): array {
        $user = $this->userRepository->findActiveByEmail($email);

        if ($user === null || !$this->passwordHasher->isPasswordValid($user, $password)) {
            $this->auditRecorder->record(
                action: 'auth.login_failed',
                payload: ['email' => strtolower($email)],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                correlationId: $correlationId,
            );

            throw new UnauthorizedHttpException('Bearer', 'Invalid credentials.');
        }

        return $this->issueTokens($user, $ipAddress, $userAgent, $correlationId);
    }

    /**
     * @return array{
     *     access_token: string,
     *     refresh_token: string,
     *     token_type: string,
     *     expires_in: int,
     *     user: array<string, mixed>
     * }
     */
    public function refresh(string $refreshTokenValue, ?string $correlationId = null): array
    {
        $hash = $this->jwtTokenService->hashRefreshToken($refreshTokenValue);

        /** @var RefreshToken|null $stored */
        $stored = $this->entityManager->getRepository(RefreshToken::class)->findOneBy(['tokenHash' => $hash]);

        if ($stored === null || !$stored->isValid()) {
            throw new UnauthorizedHttpException('Bearer', 'Invalid or expired refresh token.');
        }

        $stored->revoke();
        $user = $stored->getUser();

        return $this->unitOfWork->transactional(function () use ($user, $correlationId): array {
            $this->unitOfWork->flush();

            return $this->issueTokens($user, correlationId: $correlationId, flushAudit: false);
        });
    }

    public function logout(User $user, ?string $refreshTokenValue = null, ?string $correlationId = null): void
    {
        if ($refreshTokenValue !== null) {
            $hash = $this->jwtTokenService->hashRefreshToken($refreshTokenValue);
            /** @var RefreshToken|null $stored */
            $stored = $this->entityManager->getRepository(RefreshToken::class)->findOneBy(['tokenHash' => $hash]);

            if ($stored !== null && $stored->getUser()->getId() === $user->getId()) {
                $stored->revoke();
            }
        }

        $this->auditRecorder->record(
            action: 'auth.logout',
            payload: ['user_id' => $user->getId()],
            companyId: $user->companyId(),
            actorUserId: EntityId::fromString($user->getId()),
            correlationId: $correlationId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function currentUser(User $user): array
    {
        return $this->serializeUser($user);
    }

    /**
     * @return array{
     *     access_token: string,
     *     refresh_token: string,
     *     token_type: string,
     *     expires_in: int,
     *     user: array<string, mixed>
     * }
     */
    private function issueTokens(
        User $user,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $correlationId = null,
        bool $flushAudit = true,
    ): array {
        $accessToken = $this->jwtTokenService->createAccessToken($user);
        $refreshValue = $this->jwtTokenService->generateRefreshTokenValue();
        $refreshToken = new RefreshToken(
            id: EntityId::generate(),
            user: $user,
            tokenHash: $this->jwtTokenService->hashRefreshToken($refreshValue),
            expiresAt: new \DateTimeImmutable(sprintf('+%d days', self::REFRESH_TTL_DAYS)),
        );

        $this->entityManager->persist($refreshToken);
        $this->unitOfWork->flush();

        $this->auditRecorder->record(
            action: 'auth.login_success',
            payload: ['user_id' => $user->getId(), 'email' => $user->getEmail()],
            companyId: $user->companyId(),
            actorUserId: EntityId::fromString($user->getId()),
            ipAddress: $ipAddress,
            userAgent: $userAgent,
            correlationId: $correlationId,
            flush: $flushAudit,
        );

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshValue,
            'token_type' => 'Bearer',
            'expires_in' => $this->jwtTokenService->getAccessTtl(),
            'user' => $this->serializeUser($user),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeUser(User $user): array
    {
        $customerId = null;

        if ($user->isPortalUser()) {
            /** @var PortalUser|null $portalUser */
            $portalUser = $this->entityManager->getRepository(PortalUser::class)->findOneBy(['user' => $user]);
            $customerId = $portalUser?->getCustomer()->getId();
        }

        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'first_name' => $user->getFirstName(),
            'last_name' => $user->getLastName(),
            'company_id' => $user->companyId()->toString(),
            'is_portal_user' => $user->isPortalUser(),
            'customer_id' => $customerId,
            'permissions' => $user->getPermissionCodes(),
            'roles' => array_map(
                static fn ($role) => $role->getCode(),
                $user->getRoleEntities()->toArray(),
            ),
        ];
    }
}

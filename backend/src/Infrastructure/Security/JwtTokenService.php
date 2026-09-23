<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

use App\Infrastructure\Persistence\Entity\Identity\User;

final class JwtTokenService
{
    private const ACCESS_TTL = 900;

    public function __construct(private string $secret)
    {
    }

    public function createAccessToken(User $user): string
    {
        $now = time();

        return $this->encode([
            'sub' => $user->getId(),
            'company_id' => $user->companyId()->toString(),
            'type' => 'access',
            'iat' => $now,
            'exp' => $now + self::ACCESS_TTL,
        ]);
    }

    public function getAccessTtl(): int
    {
        return self::ACCESS_TTL;
    }

    public function validateAccessToken(string $token): ?JwtPayload
    {
        $payload = $this->decode($token);

        if ($payload === null) {
            return null;
        }

        if (($payload['type'] ?? null) !== 'access') {
            return null;
        }

        if (!isset($payload['sub'], $payload['company_id'], $payload['exp'], $payload['iat'])) {
            return null;
        }

        if ($payload['exp'] < time()) {
            return null;
        }

        return new JwtPayload(
            sub: (string) $payload['sub'],
            companyId: (string) $payload['company_id'],
            exp: (int) $payload['exp'],
            iat: (int) $payload['iat'],
            type: (string) $payload['type'],
        );
    }

    public function generateRefreshTokenValue(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    }

    public function hashRefreshToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encode(array $payload): string
    {
        $header = $this->base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'HS256'], JSON_THROW_ON_ERROR));
        $body = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = $this->base64UrlEncode(hash_hmac('sha256', $header.'.'.$body, $this->secret, true));

        return $header.'.'.$body.'.'.$signature;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(string $token): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        [$header, $body, $signature] = $parts;
        $expected = $this->base64UrlEncode(hash_hmac('sha256', $header.'.'.$body, $this->secret, true));

        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $decoded = json_decode($this->base64UrlDecode($body), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;

        if ($remainder > 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }
}

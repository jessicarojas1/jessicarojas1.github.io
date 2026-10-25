<?php

declare(strict_types=1);

namespace App\Identity\Oidc;

/**
 * One in-flight sign-in attempt: CSRF state, replay-protection nonce, PKCE verifier and
 * where to send the user afterwards.
 */
final class AuthorizationRequest
{
    public function __construct(
        public readonly string $state,
        public readonly string $nonce,
        public readonly string $codeVerifier,
        public readonly string $returnTo,
        public readonly int $createdAt,
    ) {
    }

    public static function start(string $returnTo, int $now): self
    {
        return new self(self::randomToken(), self::randomToken(), self::randomToken(), $returnTo, $now);
    }

    public function codeChallenge(): string
    {
        return self::base64Url(hash('sha256', $this->codeVerifier, true));
    }

    /**
     * @return array{state: string, nonce: string, code_verifier: string, return_to: string, created_at: int}
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'nonce' => $this->nonce,
            'code_verifier' => $this->codeVerifier,
            'return_to' => $this->returnTo,
            'created_at' => $this->createdAt,
        ];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        foreach (['state', 'nonce', 'code_verifier', 'return_to'] as $key) {
            if (!isset($data[$key]) || !\is_string($data[$key])) {
                return null;
            }
        }
        if (!isset($data['created_at']) || !\is_int($data['created_at'])) {
            return null;
        }

        return new self($data['state'], $data['nonce'], $data['code_verifier'], $data['return_to'], $data['created_at']);
    }

    public static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function randomToken(): string
    {
        return self::base64Url(random_bytes(32));
    }
}

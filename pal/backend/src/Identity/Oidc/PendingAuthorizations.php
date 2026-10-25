<?php

declare(strict_types=1);

namespace App\Identity\Oidc;

use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Stores in-flight sign-in attempts in the session, keyed by state. Each entry is
 * single-use, expires after ten minutes, and at most five are kept (several tabs).
 */
final class PendingAuthorizations
{
    private const KEY = 'pal.oidc.pending';
    private const MAX_AGE_SECONDS = 600;
    private const MAX_ENTRIES = 5;

    public static function put(SessionInterface $session, AuthorizationRequest $request, int $now): void
    {
        $entries = self::fresh($session, $now);
        $entries[$request->state] = $request->toArray();
        uasort($entries, fn (array $a, array $b): int => $b['created_at'] <=> $a['created_at']);
        $session->set(self::KEY, \array_slice($entries, 0, self::MAX_ENTRIES, true));
    }

    public static function take(SessionInterface $session, string $state, int $now): ?AuthorizationRequest
    {
        $entries = self::fresh($session, $now);
        $entry = $entries[$state] ?? null;
        unset($entries[$state]);
        $session->set(self::KEY, $entries);

        return \is_array($entry) ? AuthorizationRequest::fromArray($entry) : null;
    }

    /**
     * @return array<string, array{state: string, nonce: string, code_verifier: string, return_to: string, created_at: int}>
     */
    private static function fresh(SessionInterface $session, int $now): array
    {
        $stored = $session->get(self::KEY, []);
        $entries = [];
        foreach (\is_array($stored) ? $stored : [] as $state => $data) {
            $request = \is_array($data) ? AuthorizationRequest::fromArray($data) : null;
            if (null !== $request && \is_string($state) && $now - $request->createdAt <= self::MAX_AGE_SECONDS) {
                $entries[$state] = $request->toArray();
            }
        }

        return $entries;
    }
}

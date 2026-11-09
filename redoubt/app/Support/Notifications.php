<?php

declare(strict_types=1);

namespace Redoubt\Support;

/**
 * In-portal notifications. When an event is published (announcement, task-order
 * award, job posting), the raising module fans out a notification to exactly the
 * program members who are authorized to see that item — reusing that module's own
 * visibility predicate, so a notification never reveals something the recipient
 * could not otherwise access. Email/Teams digests are a later delivery channel;
 * the signed webhook already covers external integrations.
 *
 * Stored in the `notification` table: type = event key, ref = JSON {title,url}.
 */
final class Notifications
{
    /** Create one notification for a user. */
    public static function add(int $userId, string $type, string $title, string $url): void
    {
        Db::insert('notification', [
            'user_id' => $userId,
            'type'    => $type,
            'ref'     => ['title' => $title, 'url' => $url],
        ]);
    }

    /**
     * Fan out to program members for whom $pred returns true (actor excluded).
     * @param callable(array):bool $pred receives an in-session-shaped user array
     * @return int number notified
     */
    public static function fanOut(int $programId, ?int $actorId, string $type, string $title, string $url, callable $pred): int
    {
        if (!Db::isConfigured()) {
            return 0;
        }
        $count = 0;
        foreach (self::programMembers($programId) as $m) {
            if ($actorId !== null && $m['id'] === $actorId) {
                continue;
            }
            if (!$pred($m)) {
                continue;
            }
            self::add($m['id'], $type, $title, $url);
            $count++;
        }
        return $count;
    }

    /** @return array<int,array<string,mixed>> recent notifications for a user */
    public static function listFor(int $userId, int $limit = 50): array
    {
        $rows = Db::fetchAll(
            'SELECT id, type, ref, read_at, created_at FROM notification WHERE user_id = :u ORDER BY created_at DESC, id DESC LIMIT :l',
            ['u' => $userId, 'l' => $limit]
        );
        return array_map(static function (array $r): array {
            $ref = is_string($r['ref']) ? (json_decode($r['ref'], true) ?: []) : (is_array($r['ref']) ? $r['ref'] : []);
            return [
                'id'      => (int) $r['id'],
                'type'    => $r['type'],
                'title'   => $ref['title'] ?? $r['type'],
                'url'     => $ref['url'] ?? '#',
                'read'    => $r['read_at'] !== null,
                'created' => $r['created_at'],
            ];
        }, $rows);
    }

    public static function unreadCount(int $userId): int
    {
        if (!Db::isConfigured()) {
            return 0;
        }
        return (int) (Db::fetchOne('SELECT count(*) c FROM notification WHERE user_id = :u AND read_at IS NULL', ['u' => $userId])['c'] ?? 0);
    }

    public static function markRead(int $userId, int $id): void
    {
        Db::query('UPDATE notification SET read_at = NOW() WHERE id = :id AND user_id = :u AND read_at IS NULL', ['id' => $id, 'u' => $userId]);
    }

    public static function markAllRead(int $userId): void
    {
        Db::query('UPDATE notification SET read_at = NOW() WHERE user_id = :u AND read_at IS NULL', ['u' => $userId]);
    }

    /**
     * Program members as in-session-shaped user arrays (for Authorize predicate
     * evaluation), each also carrying 'email' and 'name' for digest delivery.
     * Public so other delivery channels (e.g. Digest) reuse the exact same
     * membership + explicit-grant resolution as the in-portal fan-out.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function programMembers(int $programId): array
    {
        $rows = Db::fetchAll(
            "SELECT u.id, u.kind, u.is_us_person, u.email, u.display_name, pm.company_id,
                    string_agg(DISTINCT r.key, ',') AS roles
               FROM program_membership pm
               JOIN app_user u ON u.id = pm.user_id
               JOIN role r ON r.id = pm.role_id
              WHERE pm.program_id = :p AND (pm.expires_at IS NULL OR pm.expires_at > NOW())
              GROUP BY u.id, u.kind, u.is_us_person, u.email, u.display_name, pm.company_id",
            ['p' => $programId]
        );

        // Explicit per-user grants/denials for this program, so fan-out resolves
        // recipients exactly as the Authorize engine does (role defaults + grants
        // − denials) — not role defaults alone. Otherwise a user granted, say,
        // review.qa only by an explicit grant would never be notified.
        $grantsByUser = [];
        foreach (Db::fetchAll('SELECT user_id, permission_key, effect FROM user_permission_grant WHERE program_id = :p', ['p' => $programId]) as $g) {
            $uid = (int) $g['user_id'];
            $bucket = ($g['effect'] === 'deny') ? 'deny' : 'grant';
            $grantsByUser[$uid][$bucket][] = (string) $g['permission_key'];
        }

        $out = [];
        foreach ($rows as $r) {
            $companyId = $r['company_id'] !== null ? (int) $r['company_id'] : null;
            $uid = (int) $r['id'];
            $out[] = [
                'id'           => $uid,
                'kind'         => (string) $r['kind'],
                'email'        => $r['email'] ?? null,
                'name'         => $r['display_name'] ?? ($r['email'] ?? null),
                'is_us_person' => $r['is_us_person'] === null ? null : (bool) $r['is_us_person'],
                'memberships'  => [$programId => ['roles' => $r['roles'] !== null ? explode(',', $r['roles']) : [], 'company_id' => $companyId]],
                'companies'    => $companyId !== null ? [$companyId] : [],
                'grants'       => isset($grantsByUser[$uid]) ? [$programId => $grantsByUser[$uid]] : [],
            ];
        }
        return $out;
    }
}

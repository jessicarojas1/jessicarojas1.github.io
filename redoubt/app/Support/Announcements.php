<?php

declare(strict_types=1);

namespace Redoubt\Support;

/**
 * Announcements module service (Annex H). All access is program-scoped and
 * audience-trimmed; publishing emits an audit event and a signed webhook.
 *
 * Audience tokens (JSONB array on the row): 'all', 'internal', 'customer',
 * 'role:<roleKey>', 'company:<id>'. Empty or ['all'] means every program member.
 */
final class Announcements
{
    /** Announcements a user may see in a program (audience + publish-window trimmed). */
    public static function listForUser(array $user, int $programId): array
    {
        $rows = Db::fetchAll(
            'SELECT * FROM announcement WHERE program_id = :p ORDER BY COALESCE(publish_at, created_at) DESC',
            ['p' => $programId]
        );
        $isEditor = Authorize::can($user, 'announcement.edit', ['program_id' => $programId]);
        $out = [];
        foreach ($rows as $r) {
            if (!$isEditor && !self::isLive($r)) {
                continue;
            }
            if (!$isEditor && !self::visibleTo($user, $r, $programId)) {
                continue;
            }
            $out[] = self::hydrate($r);
        }
        return $out;
    }

    /** Published + within window. */
    private static function isLive(array $r): bool
    {
        $now = time();
        $pub = $r['publish_at'] ? strtotime((string) $r['publish_at']) : null;
        $exp = $r['expire_at'] ? strtotime((string) $r['expire_at']) : null;
        if ($pub === null || $pub > $now) {
            return false;
        }
        if ($exp !== null && $exp <= $now) {
            return false;
        }
        return true;
    }

    private static function visibleTo(array $user, array $r, int $programId): bool
    {
        $aud = self::decodeAudience($r['audience'] ?? null);
        if ($aud === [] || in_array('all', $aud, true)) {
            return true;
        }
        $roles = $user['memberships'][$programId]['roles'] ?? [];
        $companyId = $user['memberships'][$programId]['company_id'] ?? null;
        $internal = ['enterprise_admin', 'program_admin', 'program_manager', 'content_manager', 'contracts', 'finance', 'recruiting', 'internal_member', 'security_admin'];
        $isInternal = array_intersect($internal, $roles) !== [];

        if (in_array('internal', $aud, true) && $isInternal) {
            return true;
        }
        if (in_array('customer', $aud, true) && (($user['kind'] ?? '') === 'customer' || in_array('customer_cor', $roles, true))) {
            return true;
        }
        foreach ($roles as $rk) {
            if (in_array('role:' . $rk, $aud, true)) {
                return true;
            }
        }
        if ($companyId !== null && in_array('company:' . $companyId, $aud, true)) {
            return true;
        }
        return $isInternal; // internal roles see all program announcements
    }

    public static function get(int $id, int $programId): ?array
    {
        $r = Db::fetchOne('SELECT * FROM announcement WHERE id = :id AND program_id = :p', ['id' => $id, 'p' => $programId]);
        return $r ? self::hydrate($r) : null;
    }

    /** @param array<string,mixed> $data */
    public static function create(int $programId, array $data, ?int $actorId): int
    {
        $id = Db::insert('announcement', [
            'program_id' => $programId,
            'title'      => $data['title'],
            'body'       => $data['body'],
            'audience'   => $data['audience'] ?? [],
            'priority'   => $data['priority'] ?? 'normal',
            'publish_at' => $data['publish_at'] ?? null,
            'expire_at'  => $data['expire_at'] ?? null,
            'created_by' => $actorId,
        ]);
        Audit::log('announcement.create', 'announcement#' . $id, $programId, $actorId);
        return $id;
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, int $programId, array $data, ?int $actorId): void
    {
        Db::update('announcement', [
            'title'      => $data['title'],
            'body'       => $data['body'],
            'audience'   => $data['audience'] ?? [],
            'priority'   => $data['priority'] ?? 'normal',
            'expire_at'  => $data['expire_at'] ?? null,
        ], ['id' => $id, 'program_id' => $programId]);
        Audit::log('announcement.edit', 'announcement#' . $id, $programId, $actorId);
    }

    /** Publish now and notify subscribers. */
    public static function publish(int $id, int $programId, ?int $actorId): void
    {
        Db::update('announcement', ['publish_at' => date('c')], ['id' => $id, 'program_id' => $programId]);
        Audit::log('announcement.published', 'announcement#' . $id, $programId, $actorId);
        $a = self::get($id, $programId);
        Webhooks::dispatch('announcement.published', [
            'id' => $id, 'program_id' => $programId,
            'title' => $a['title'] ?? null, 'priority' => $a['priority'] ?? 'normal',
        ], $programId);
    }

    public static function delete(int $id, int $programId, ?int $actorId): void
    {
        Db::query('DELETE FROM announcement WHERE id = :id AND program_id = :p', ['id' => $id, 'p' => $programId]);
        Audit::log('announcement.delete', 'announcement#' . $id, $programId, $actorId);
    }

    /** Published announcements for an API client (program-scoped). */
    public static function listPublished(int $programId): array
    {
        $rows = Db::fetchAll(
            "SELECT * FROM announcement
              WHERE program_id = :p AND publish_at IS NOT NULL AND publish_at <= NOW()
                AND (expire_at IS NULL OR expire_at > NOW())
              ORDER BY publish_at DESC",
            ['p' => $programId]
        );
        return array_map([self::class, 'hydrate'], $rows);
    }

    // --- helpers ------------------------------------------------------------

    private static function decodeAudience(mixed $aud): array
    {
        if (is_array($aud)) {
            return $aud;
        }
        if (is_string($aud) && $aud !== '') {
            $d = json_decode($aud, true);
            return is_array($d) ? $d : [];
        }
        return [];
    }

    /** @return array<string,mixed> */
    private static function hydrate(array $r): array
    {
        return [
            'id'         => (int) $r['id'],
            'title'      => $r['title'],
            'body'       => $r['body'],
            'priority'   => $r['priority'],
            'audience'   => self::decodeAudience($r['audience'] ?? null),
            'publish_at' => $r['publish_at'],
            'expire_at'  => $r['expire_at'],
            'is_live'    => self::isLive($r),
        ];
    }
}

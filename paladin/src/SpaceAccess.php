<?php
declare(strict_types=1);

/**
 * SpaceAccess — space-level permissions layered over the global RBAC.
 *
 * A space is either open (any user with the global `space.view` permission can
 * read it) or private/restricted (only its members can). Membership roles live
 * in space_members; `owner` and `admin` can manage the space (members,
 * settings). System admins always have full access.
 */
final class SpaceAccess
{
    /** Roles that may administer a space (manage members/settings). */
    private const MANAGER_ROLES = ['owner', 'admin'];
    /** Roles that may add/edit content in a space. */
    private const CONTRIBUTOR_ROLES = ['owner', 'admin', 'contributor', 'reviewer', 'approver'];

    /** @var array<string,?string> per-request cache of "spaceId:userId" => role */
    private static array $cache = [];

    private static function truthy(mixed $v): bool
    {
        return $v === true || in_array(strtolower((string)$v), ['1', 't', 'true', 'yes', 'on'], true);
    }

    /** The current user's membership role in a space, or null if not a member. */
    public static function role(int $spaceId, ?int $userId = null): ?string
    {
        $userId ??= Auth::id();
        if ($userId === null) return null;
        $k = $spaceId . ':' . $userId;
        if (array_key_exists($k, self::$cache)) return self::$cache[$k];
        try {
            $row = Database::fetchOne("SELECT role FROM space_members WHERE space_id = ? AND user_id = ?", [$spaceId, $userId]);
        } catch (\Throwable) { $row = null; }
        return self::$cache[$k] = $row['role'] ?? null;
    }

    /** Can the current user view this space (array with id + is_private)? */
    public static function canView(array $space): bool
    {
        if (Auth::role() === 'admin') return true;
        if (!self::truthy($space['is_private'] ?? false)) return Auth::can('space.view');
        return self::role((int)$space['id']) !== null;
    }

    /** Can the current user administer this space (members/settings)? */
    public static function canManage(array $space): bool
    {
        if (Auth::role() === 'admin') return true;
        return in_array(self::role((int)$space['id']), self::MANAGER_ROLES, true);
    }

    /** Can the current user add/edit content in this space? */
    public static function canContribute(array $space): bool
    {
        if (Auth::role() === 'admin') return true;
        if (in_array(self::role((int)$space['id']), self::CONTRIBUTOR_ROLES, true)) return true;
        // Open space: fall back to the global content permission.
        return !self::truthy($space['is_private'] ?? false) && Auth::can('page.create');
    }

    public static function clearCache(): void { self::$cache = []; }
}

<?php

declare(strict_types=1);

namespace Redoubt\Support;

/**
 * Shared audience/visibility evaluation for content that carries an audience
 * token list. Tokens: 'all', 'internal', 'customer', 'role:<key>', 'company:<id>'.
 * Empty or ['all'] means every program member. Internal roles see everything.
 */
final class Audience
{
    private const INTERNAL_ROLES = [
        'enterprise_admin', 'program_admin', 'program_manager', 'content_manager',
        'contracts', 'finance', 'recruiting', 'internal_member', 'security_admin',
    ];

    /** @param string[] $tokens */
    public static function visible(array $user, array $tokens, int $programId): bool
    {
        if ($tokens === [] || in_array('all', $tokens, true)) {
            return true;
        }
        $roles = $user['memberships'][$programId]['roles'] ?? [];
        $companyId = $user['memberships'][$programId]['company_id'] ?? null;
        $isInternal = array_intersect(self::INTERNAL_ROLES, $roles) !== [];

        if (in_array('internal', $tokens, true) && $isInternal) {
            return true;
        }
        if (in_array('customer', $tokens, true) && (($user['kind'] ?? '') === 'customer' || in_array('customer_cor', $roles, true))) {
            return true;
        }
        foreach ($roles as $rk) {
            if (in_array('role:' . $rk, $tokens, true)) {
                return true;
            }
        }
        if ($companyId !== null && in_array('company:' . $companyId, $tokens, true)) {
            return true;
        }
        return $isInternal;
    }

    /** Decode a JSONB audience/visibility value to an array. */
    public static function decode(mixed $v): array
    {
        if (is_array($v)) {
            return $v;
        }
        if (is_string($v) && $v !== '') {
            $d = json_decode($v, true);
            return is_array($d) ? $d : [];
        }
        return [];
    }
}

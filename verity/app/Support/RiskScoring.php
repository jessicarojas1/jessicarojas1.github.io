<?php

declare(strict_types=1);

namespace Verity\Support;

/**
 * Identity risk scoring (OPEN_ITEMS.md: "entitlement.risk_level and
 * application.classification are stored but nothing computes or aggregates
 * a risk score. Define a scoring model against existing fields before
 * adding new ones."). This is exactly that — a v1 heuristic scoring model
 * computed entirely from fields that already exist (entitlement.risk_level,
 * entitlement.is_privileged, application.classification, system_account
 * .account_type, entitlement_assignment.assignment_type/expires_at, and
 * person.employment_status), with NO new schema. The per-factor weights
 * below (WEIGHTS/TIER_THRESHOLDS) are a documented default, not a business
 * decision this app can make on its own — see the "Decisions required"
 * note in OPEN_ITEMS.md. They are plain class constants specifically so
 * they are trivial to retune later without touching the query logic.
 *
 * Every score is computed live (no stored/materialized column) — identical
 * to how Matrix.php and Dashboard.php already compute everything from the
 * base tables on every request rather than caching a derived value that
 * could drift from the data it was computed from.
 */
final class RiskScoring
{
    /** Per-assignment weight for the entitlement's own risk_level. */
    private const ENTITLEMENT_RISK_WEIGHT = ['critical' => 20, 'high' => 10, 'medium' => 4, 'low' => 1];
    /** Added on top of the risk_level weight for each PRIVILEGED entitlement held. */
    private const PRIVILEGED_ENTITLEMENT_WEIGHT = 6;
    /** Per DISTINCT application the person has any account in (not per entitlement, to avoid one app's many entitlements dominating the score). */
    private const APPLICATION_CLASSIFICATION_WEIGHT = ['restricted' => 12, 'confidential' => 5, 'internal' => 1, 'public' => 0];
    /** Per DISTINCT account of account_type='privileged'. */
    private const PRIVILEGED_ACCOUNT_WEIGHT = 10;
    /** Per expired-but-still-assigned temporary entitlement — a real exception-view condition, not a hypothetical one. */
    private const EXPIRED_TEMPORARY_WEIGHT = 12;
    /** Per enabled account belonging to a terminated identity — the single worst condition this model flags. */
    private const TERMINATED_ENABLED_ACCOUNT_WEIGHT = 50;

    private const TIER_THRESHOLDS = ['critical' => 60, 'high' => 30, 'medium' => 10];

    public static function tierFor(int $score): string
    {
        if ($score >= self::TIER_THRESHOLDS['critical']) {
            return 'critical';
        }
        if ($score >= self::TIER_THRESHOLDS['high']) {
            return 'high';
        }
        if ($score >= self::TIER_THRESHOLDS['medium']) {
            return 'medium';
        }
        return 'low';
    }

    /** Full breakdown for one identity — the identity detail page's "why". */
    public static function identityScore(int $personId): array
    {
        $rows = self::query('p.id = :pid', ['pid' => $personId]);
        $row = $rows[0] ?? self::zeroRow($personId);
        return self::shape($row);
    }

    /**
     * Batch scores for exactly the given person ids, keyed by person_id —
     * for the Identity Directory list, so displaying/sorting by risk never
     * costs one query per row.
     * @param int[] $personIds
     * @return array<int, array{score:int, tier:string}>
     */
    public static function scoresFor(array $personIds): array
    {
        $personIds = array_values(array_unique(array_map('intval', $personIds)));
        if ($personIds === []) {
            return [];
        }
        $in = [];
        $params = [];
        foreach ($personIds as $i => $pid) {
            $key = 'pid' . $i;
            $in[] = ':' . $key;
            $params[$key] = $pid;
        }
        $rows = self::query('p.id IN (' . implode(',', $in) . ')', $params);
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['person_id']] = ['score' => (int) $row['total_score'], 'tier' => self::tierFor((int) $row['total_score'])];
        }
        // Anyone in the batch with zero contributing factors never appears in
        // the query's result set at all (every join is LEFT-able away) —
        // fill those in explicitly as a real, honest zero rather than a gap.
        foreach ($personIds as $pid) {
            if (!isset($out[$pid])) {
                $out[$pid] = ['score' => 0, 'tier' => self::tierFor(0)];
            }
        }
        return $out;
    }

    /** Top-risk identities enterprise-wide, for the dashboard/report. */
    public static function topRisks(int $limit = 25, int $offset = 0): array
    {
        $rows = self::query('1=1', [], $limit, $offset);
        return array_map([self::class, 'shape'], $rows);
    }

    public static function countScored(): int
    {
        return (int) Db::fetchValue(
            "SELECT COUNT(*) FROM (" . self::baseSql('1=1') . ") scored WHERE total_score > 0",
            []
        );
    }

    /**
     * Every person_id whose score falls in $tier — for deep-linking from
     * the Dashboard's Critical/High-Risk Identity KPIs (and any future
     * filter) straight to a correctly-filtered Identity Directory list,
     * composed with IdentitiesController's existing person_ids filter the
     * same way supervisor-chain scoping already is.
     * @return int[]
     */
    public static function personIdsInTier(string $tier): array
    {
        [$min, $max] = self::tierBounds($tier);
        $sql = 'SELECT person_id FROM (' . self::baseSql('1=1') . ') scored WHERE total_score >= :min' . ($max !== null ? ' AND total_score < :max' : '');
        $params = ['min' => $min];
        if ($max !== null) {
            $params['max'] = $max;
        }
        return array_map(static fn ($r) => (int) $r['person_id'], Db::fetchAll($sql, $params));
    }

    /** @return array{0:int,1:?int} [inclusive min, exclusive max or null for no ceiling] — kept in sync with tierFor()'s own thresholds. */
    private static function tierBounds(string $tier): array
    {
        return match ($tier) {
            'critical' => [self::TIER_THRESHOLDS['critical'], null],
            'high' => [self::TIER_THRESHOLDS['high'], self::TIER_THRESHOLDS['critical']],
            'medium' => [self::TIER_THRESHOLDS['medium'], self::TIER_THRESHOLDS['high']],
            'low' => [0, self::TIER_THRESHOLDS['medium']],
            default => throw new \InvalidArgumentException('Invalid risk tier.'),
        };
    }

    /** @return array{critical:int, high:int} counts for the dashboard KPIs. */
    public static function countsByTier(): array
    {
        $rows = Db::fetchAll("SELECT total_score FROM (" . self::baseSql('1=1') . ") scored WHERE total_score >= :threshold", ['threshold' => self::TIER_THRESHOLDS['high']]);
        $critical = 0;
        $high = 0;
        foreach ($rows as $r) {
            if ((int) $r['total_score'] >= self::TIER_THRESHOLDS['critical']) {
                $critical++;
            } else {
                $high++;
            }
        }
        return ['critical' => $critical, 'high' => $high];
    }

    private static function zeroRow(int $personId): array
    {
        return [
            'person_id' => $personId, 'display_name' => null, 'department' => null, 'employment_status' => null,
            'entitlement_weight' => 0, 'entitlement_count' => 0,
            'application_weight' => 0, 'application_count' => 0,
            'privileged_account_weight' => 0, 'privileged_account_count' => 0,
            'expired_temp_weight' => 0, 'expired_temp_count' => 0,
            'terminated_enabled_weight' => 0, 'terminated_enabled_account_count' => 0,
            'total_score' => 0,
        ];
    }

    private static function shape(array $row): array
    {
        $score = (int) $row['total_score'];
        return [
            'person_id' => (int) $row['person_id'],
            'display_name' => $row['display_name'],
            'department' => $row['department'],
            'employment_status' => $row['employment_status'],
            'score' => $score,
            'tier' => self::tierFor($score),
            'factors' => [
                'entitlement' => ['weight' => (int) $row['entitlement_weight'], 'count' => (int) $row['entitlement_count']],
                'application' => ['weight' => (int) $row['application_weight'], 'count' => (int) $row['application_count']],
                'privileged_account' => ['weight' => (int) $row['privileged_account_weight'], 'count' => (int) $row['privileged_account_count']],
                'expired_temporary' => ['weight' => (int) $row['expired_temp_weight'], 'count' => (int) $row['expired_temp_count']],
                'terminated_enabled' => ['weight' => (int) $row['terminated_enabled_weight'], 'count' => (int) $row['terminated_enabled_account_count']],
            ],
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private static function query(string $where, array $params, ?int $limit = null, int $offset = 0): array
    {
        $sql = self::baseSql($where) . ' ORDER BY total_score DESC, p.id';
        if ($limit !== null) {
            $sql .= ' LIMIT :limit OFFSET :offset';
            $params['limit'] = $limit;
            $params['offset'] = $offset;
        }
        return Db::fetchAll($sql, $params);
    }

    private static function baseSql(string $personWhere): string
    {
        $entRisk = "CASE e.risk_level"
            . " WHEN 'critical' THEN " . self::ENTITLEMENT_RISK_WEIGHT['critical']
            . " WHEN 'high' THEN " . self::ENTITLEMENT_RISK_WEIGHT['high']
            . " WHEN 'medium' THEN " . self::ENTITLEMENT_RISK_WEIGHT['medium']
            . " ELSE " . self::ENTITLEMENT_RISK_WEIGHT['low'] . " END"
            . " + CASE WHEN e.is_privileged THEN " . self::PRIVILEGED_ENTITLEMENT_WEIGHT . " ELSE 0 END";
        $appWeight = "CASE classification"
            . " WHEN 'restricted' THEN " . self::APPLICATION_CLASSIFICATION_WEIGHT['restricted']
            . " WHEN 'confidential' THEN " . self::APPLICATION_CLASSIFICATION_WEIGHT['confidential']
            . " WHEN 'internal' THEN " . self::APPLICATION_CLASSIFICATION_WEIGHT['internal']
            . " ELSE " . self::APPLICATION_CLASSIFICATION_WEIGHT['public'] . " END";

        return "
            WITH entitlement_component AS (
                SELECT sa.person_id, SUM($entRisk) AS entitlement_weight, COUNT(*) AS entitlement_count
                FROM entitlement_assignment ea
                JOIN system_account sa ON sa.id = ea.system_account_id
                JOIN entitlement e ON e.id = ea.entitlement_id
                WHERE sa.person_id IS NOT NULL
                GROUP BY sa.person_id
            ),
            application_component AS (
                SELECT person_id, SUM($appWeight) AS application_weight, COUNT(*) AS application_count
                FROM (
                    SELECT DISTINCT sa.person_id, a.id AS application_id, a.classification
                    FROM system_account sa
                    JOIN application a ON a.id = sa.application_id
                    WHERE sa.person_id IS NOT NULL
                ) distinct_apps
                GROUP BY person_id
            ),
            privileged_account_component AS (
                SELECT person_id, COUNT(*) * " . self::PRIVILEGED_ACCOUNT_WEIGHT . " AS privileged_account_weight, COUNT(*) AS privileged_account_count
                FROM system_account
                WHERE person_id IS NOT NULL AND account_type = 'privileged'
                GROUP BY person_id
            ),
            expired_temp_component AS (
                SELECT sa.person_id, COUNT(*) * " . self::EXPIRED_TEMPORARY_WEIGHT . " AS expired_temp_weight, COUNT(*) AS expired_temp_count
                FROM entitlement_assignment ea
                JOIN system_account sa ON sa.id = ea.system_account_id
                WHERE sa.person_id IS NOT NULL AND ea.assignment_type = 'temporary' AND ea.expires_at < NOW()
                GROUP BY sa.person_id
            ),
            terminated_enabled_component AS (
                SELECT sa.person_id,
                       COUNT(DISTINCT sa.id) * " . self::TERMINATED_ENABLED_ACCOUNT_WEIGHT . " AS terminated_enabled_weight,
                       COUNT(DISTINCT sa.id) AS terminated_enabled_account_count
                FROM system_account sa
                JOIN person p2 ON p2.id = sa.person_id
                WHERE p2.employment_status = 'terminated' AND sa.status = 'enabled'
                GROUP BY sa.person_id
            )
            SELECT p.id AS person_id, p.display_name, p.department, p.employment_status,
                   COALESCE(ec.entitlement_weight, 0) AS entitlement_weight, COALESCE(ec.entitlement_count, 0) AS entitlement_count,
                   COALESCE(ac.application_weight, 0) AS application_weight, COALESCE(ac.application_count, 0) AS application_count,
                   COALESCE(pac.privileged_account_weight, 0) AS privileged_account_weight, COALESCE(pac.privileged_account_count, 0) AS privileged_account_count,
                   COALESCE(etc.expired_temp_weight, 0) AS expired_temp_weight, COALESCE(etc.expired_temp_count, 0) AS expired_temp_count,
                   COALESCE(tec.terminated_enabled_weight, 0) AS terminated_enabled_weight, COALESCE(tec.terminated_enabled_account_count, 0) AS terminated_enabled_account_count,
                   (COALESCE(ec.entitlement_weight, 0) + COALESCE(ac.application_weight, 0) + COALESCE(pac.privileged_account_weight, 0)
                    + COALESCE(etc.expired_temp_weight, 0) + COALESCE(tec.terminated_enabled_weight, 0)) AS total_score
            FROM person p
            LEFT JOIN entitlement_component ec ON ec.person_id = p.id
            LEFT JOIN application_component ac ON ac.person_id = p.id
            LEFT JOIN privileged_account_component pac ON pac.person_id = p.id
            LEFT JOIN expired_temp_component etc ON etc.person_id = p.id
            LEFT JOIN terminated_enabled_component tec ON tec.person_id = p.id
            WHERE $personWhere
        ";
    }
}

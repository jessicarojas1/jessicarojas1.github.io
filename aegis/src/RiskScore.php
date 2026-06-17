<?php
declare(strict_types=1);

/**
 * RiskScore — the single source of truth for risk scoring and level banding.
 *
 * Before this class, the score formula (likelihood × impact) and the level
 * thresholds (critical > 14, high 10–14, medium 5–9, low ≤ 4) were duplicated as
 * magic numbers across RiskController and ~10 views. Centralizing them keeps the
 * heatmap, dashboard counts, list filters, and badges consistent, and makes the
 * banding configurable in one place.
 *
 * The bands match the historical AEGIS 5×5 matrix exactly, so this is a
 * behavior-preserving consolidation.
 */
final class RiskScore
{
    /** Inclusive score bands: level => [min, max] on a 1..25 scale. */
    public const BANDS = [
        'low'      => [1, 4],
        'medium'   => [5, 9],
        'high'     => [10, 14],
        'critical' => [15, 25],
    ];

    /** Human-readable labels. */
    public const LABELS = [
        'low'      => 'Low',
        'medium'   => 'Medium',
        'high'     => 'High',
        'critical' => 'Critical',
    ];

    /**
     * CSS custom-property tokens per level (never hard-coded hex — honors the
     * dark-mode rule). Callers use e.g. style="color:<?= RiskScore::colorVar($lvl) ?>".
     */
    private const COLOR_VARS = [
        'low'      => 'var(--success)',
        'medium'   => 'var(--warning)',
        'high'     => 'var(--danger)',
        'critical' => 'var(--danger)',
    ];

    /** Clamp a likelihood/impact axis value to the valid 1..5 range. */
    public static function clampAxis(int $v): int
    {
        return max(1, min(5, $v));
    }

    /** Compute a score from likelihood × impact (axes clamped to 1..5 → 1..25). */
    public static function score(int $likelihood, int $impact): int
    {
        return self::clampAxis($likelihood) * self::clampAxis($impact);
    }

    /** Map a numeric score (1..25) to its level key. */
    public static function level(int $score): string
    {
        foreach (self::BANDS as $level => [$min, $max]) {
            if ($score >= $min && $score <= $max) {
                return $level;
            }
        }
        // Scores below 1 fall to low; above 25 to critical.
        return $score < 1 ? 'low' : 'critical';
    }

    public static function label(string $level): string
    {
        return self::LABELS[$level] ?? ucfirst($level);
    }

    public static function colorVar(string $level): string
    {
        return self::COLOR_VARS[$level] ?? 'var(--text-muted)';
    }

    /** Convenience: score → label directly. */
    public static function scoreLabel(int $score): string
    {
        return self::label(self::level($score));
    }

    // ── Derived scores from a risk row ──────────────────────────────────────

    public static function inherent(array $risk): int
    {
        if (isset($risk['likelihood'], $risk['impact'])) {
            return self::score((int)$risk['likelihood'], (int)$risk['impact']);
        }
        return (int)($risk['inherent_score'] ?? 0);
    }

    /** Residual score, or null if residual axes aren't set. */
    public static function residual(array $risk): ?int
    {
        if (!empty($risk['residual_likelihood']) && !empty($risk['residual_impact'])) {
            return self::score((int)$risk['residual_likelihood'], (int)$risk['residual_impact']);
        }
        $stored = $risk['residual_score'] ?? null;
        return $stored !== null && (int)$stored > 0 ? (int)$stored : null;
    }

    /** Target score, or null if target axes aren't set. */
    public static function target(array $risk): ?int
    {
        if (!empty($risk['target_likelihood']) && !empty($risk['target_impact'])) {
            return self::score((int)$risk['target_likelihood'], (int)$risk['target_impact']);
        }
        $stored = $risk['target_score'] ?? null;
        return $stored !== null && (int)$stored > 0 ? (int)$stored : null;
    }

    /** True when a score is above the category's appetite ceiling. */
    public static function exceedsAppetite(int $score, ?int $maxScore): bool
    {
        return $maxScore !== null && $score > $maxScore;
    }

    /**
     * SQL predicate for a level band against a (trusted, caller-supplied) column.
     * Produces strings identical to the legacy inline conditions, e.g.
     *   RiskScore::sqlCondition('critical', 'r.inherent_score')  // "r.inherent_score > 14"
     *
     * $column must be a bare/qualified identifier — it is never user input, but
     * we validate the shape defensively so this can't become an injection vector.
     */
    public static function sqlCondition(string $level, string $column = 'inherent_score'): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $column)) {
            throw new InvalidArgumentException('Unsafe column identifier');
        }
        return match ($level) {
            'critical' => "{$column} > 14",
            'high'     => "{$column} BETWEEN 10 AND 14",
            'medium'   => "{$column} BETWEEN 5 AND 9",
            'low'      => "{$column} <= 4",
            default    => throw new InvalidArgumentException("Unknown risk level: {$level}"),
        };
    }

    /** All level keys, low → critical. */
    public static function levels(): array
    {
        return array_keys(self::BANDS);
    }
}

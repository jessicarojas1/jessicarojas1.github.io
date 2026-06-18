<?php
declare(strict_types=1);

/**
 * DueStatus — consistent due-date / SLA banding across modules.
 *
 * POA&M milestones, audit-finding remediation, policy reviews, incident SLAs,
 * risk reviews, and account reviews all answer the same question: "is this
 * overdue, due soon, or on track?" Centralizing the logic keeps every aging
 * dashboard, badge, and filter consistent, and keeps the colors dark-mode safe
 * (CSS custom properties, never hard-coded hex).
 *
 * Pure logic — no database, fully unit-tested.
 */
final class DueStatus
{
    public const OVERDUE   = 'overdue';
    public const DUE_SOON  = 'due_soon';
    public const ON_TRACK  = 'on_track';
    public const COMPLETE  = 'complete';
    public const NONE      = 'none';

    /** Default window (days) within which an item counts as "due soon". */
    public const DEFAULT_SOON_DAYS = 7;

    private const LABELS = [
        self::OVERDUE  => 'Overdue',
        self::DUE_SOON => 'Due Soon',
        self::ON_TRACK => 'On Track',
        self::COMPLETE => 'Complete',
        self::NONE     => 'No Due Date',
    ];

    private const COLOR_VARS = [
        self::OVERDUE  => 'var(--danger)',
        self::DUE_SOON => 'var(--warning)',
        self::ON_TRACK => 'var(--success)',
        self::COMPLETE => 'var(--text-muted)',
        self::NONE     => 'var(--text-muted)',
    ];

    /**
     * Classify a due date relative to "now".
     *
     * @param string|null $dueDate    any strtotime-parseable date, or null/empty
     * @param bool        $completed  true if the item is already closed/done
     * @param int         $soonDays   "due soon" window in days
     * @param int|null    $now        unix time override (for testing); defaults to time()
     */
    public static function classify(
        ?string $dueDate,
        bool $completed = false,
        int $soonDays = self::DEFAULT_SOON_DAYS,
        ?int $now = null
    ): string {
        if ($completed) {
            return self::COMPLETE;
        }
        if ($dueDate === null || trim($dueDate) === '') {
            return self::NONE;
        }
        $ts = strtotime($dueDate);
        if ($ts === false) {
            return self::NONE;
        }
        $now ??= time();

        // Compare on calendar-day boundaries so "due today" is on-track, not overdue.
        $dueDay = (int)floor($ts / 86400);
        $nowDay = (int)floor($now / 86400);
        $diff   = $dueDay - $nowDay;

        if ($diff < 0)          return self::OVERDUE;
        if ($diff <= $soonDays) return self::DUE_SOON;
        return self::ON_TRACK;
    }

    /** Whole days until due (negative = days overdue); null when no/!parseable date. */
    public static function daysUntil(?string $dueDate, ?int $now = null): ?int
    {
        if ($dueDate === null || trim($dueDate) === '') return null;
        $ts = strtotime($dueDate);
        if ($ts === false) return null;
        $now ??= time();
        return (int)floor($ts / 86400) - (int)floor($now / 86400);
    }

    /**
     * Aging bucket for reporting: '0-30', '31-60', '61-90', '90+' days overdue,
     * or null if not overdue. Drives finding/POA&M aging dashboards.
     */
    public static function agingBucket(?string $dueDate, bool $completed = false, ?int $now = null): ?string
    {
        if ($completed) return null;
        $days = self::daysUntil($dueDate, $now);
        if ($days === null || $days >= 0) return null;
        $overdue = -$days;
        return match (true) {
            $overdue <= 30 => '0-30',
            $overdue <= 60 => '31-60',
            $overdue <= 90 => '61-90',
            default        => '90+',
        };
    }

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }

    public static function colorVar(string $status): string
    {
        return self::COLOR_VARS[$status] ?? 'var(--text-muted)';
    }

    /** Convenience: is this item overdue right now? */
    public static function isOverdue(?string $dueDate, bool $completed = false, ?int $now = null): bool
    {
        return self::classify($dueDate, $completed, self::DEFAULT_SOON_DAYS, $now) === self::OVERDUE;
    }
}

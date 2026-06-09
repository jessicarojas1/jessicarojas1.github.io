<?php
/**
 * Mentions — parse @handle mentions out of free text (comments) and notify the
 * referenced users via in-app alerts. A user can be mentioned by:
 *   - their email local-part   (jordan.blake)
 *   - their full name, spaces removed   (jordanblake)
 *   - their first name, when unambiguous   (jordan)
 * Matching is case-insensitive. Never throws.
 */
final class Mentions {

    /** Tokens like @jordan, @pal.admin, @jordan_blake (2+ chars). */
    private const TOKEN_RE = '/@([A-Za-z0-9._-]{2,})/';

    /**
     * Find mentions in $body and create an alert for each matched user
     * (excluding the author). Returns the list of notified user IDs.
     */
    public static function process(string $body, string $entityType, int $entityId, ?string $title = null): array {
        if (!preg_match_all(self::TOKEN_RE, $body, $m) || empty($m[1])) return [];
        $tokens = array_unique(array_map('strtolower', $m[1]));

        try {
            $users = Database::fetchAll("SELECT id, name, email FROM users WHERE is_active = TRUE");
        } catch (Throwable) { return []; }

        // Build candidate handle => [userId,...]
        $byHandle = []; $firstNameCount = [];
        foreach ($users as $u) {
            $email = strtolower((string)$u['email']);
            $local = strpos($email, '@') !== false ? substr($email, 0, strpos($email, '@')) : $email;
            $nameNoSpace = strtolower(preg_replace('/\s+/', '', (string)$u['name']));
            $first = strtolower(strtok((string)$u['name'], ' ') ?: '');
            foreach (array_filter([$local, $nameNoSpace]) as $h) {
                $byHandle[$h][(int)$u['id']] = true;
            }
            if ($first !== '') {
                $byHandle[$first][(int)$u['id']] = true;
                $firstNameCount[$first] = ($firstNameCount[$first] ?? 0) + 1;
            }
        }

        $notify = [];
        foreach ($tokens as $tok) {
            if (!isset($byHandle[$tok])) continue;
            // Ambiguous first-name-only tokens (shared by >1 user) are skipped
            if (($firstNameCount[$tok] ?? 0) > 1 && count($byHandle[$tok]) > 1) continue;
            foreach (array_keys($byHandle[$tok]) as $uid) $notify[$uid] = true;
        }

        $self = Auth::id();
        $link = self::link($entityType, $entityId);
        $who  = Auth::user()['name'] ?? 'Someone';
        $ctx  = $title ? (' on “' . $title . '”') : '';
        $notified = [];
        foreach (array_keys($notify) as $uid) {
            if ($uid === $self) continue;
            try {
                Database::insert('alerts', [
                    'user_id'  => $uid,
                    'title'    => $who . ' mentioned you',
                    'body'     => $who . ' mentioned you in a comment' . $ctx . '.',
                    'severity' => 'info',
                    'link'     => $link,
                ]);
                $notified[] = $uid;
            } catch (Throwable) {}
        }
        if ($notified) {
            try { Auth::log('mention', $entityType, $entityId, ['users' => $notified]); } catch (Throwable) {}
        }
        return $notified;
    }

    private static function link(string $entityType, int $entityId): string {
        return match ($entityType) {
            'page'     => '/pages/' . $entityId . '#comments',
            'document' => '/documents/' . $entityId . '#comments',
            'blog'     => '/blog/' . $entityId . '#comments',
            'process'  => '/processes/' . $entityId,
            default    => '/',
        };
    }
}

<?php
declare(strict_types=1);

class UnsubscribeController {

    public function unsubscribe(string $token): void {
        $token = Security::sanitizeInput($token);

        $row = Database::fetchOne(
            "SELECT * FROM email_unsubscribes WHERE token = ?",
            [$token]
        );

        if (!$row) {
            $notificationType = null;
            $error = true;
        } else {
            $error = false;
            $notificationType = $row['notification_type'];

            // Apply the unsubscribe preference
            if ($row['user_id']) {
                if ($notificationType) {
                    // Disable just this notification type for this user
                    Database::query(
                        "INSERT INTO user_notification_prefs (user_id, notification_type, enabled)
                         VALUES (?, ?, FALSE)
                         ON CONFLICT (user_id, notification_type) DO UPDATE SET enabled = FALSE",
                        [$row['user_id'], $notificationType]
                    );
                } else {
                    // Unsubscribe from all notification types
                    $types = [
                        'overdue_controls','policy_review_due','pending_approval',
                        'new_risk_assigned','open_incident_aging','risk_review_overdue',
                        'treatment_due','risk_score_worsened','vendor_assessment_expiring',
                        'document_expiring','assessment_pending_stale',
                    ];
                    foreach ($types as $type) {
                        Database::query(
                            "INSERT INTO user_notification_prefs (user_id, notification_type, enabled)
                             VALUES (?, ?, FALSE)
                             ON CONFLICT (user_id, notification_type) DO UPDATE SET enabled = FALSE",
                            [$row['user_id'], $type]
                        );
                    }
                }
            }
        }

        $notificationType = $notificationType ?? 'all';
        require AEGIS_ROOT . '/views/auth/unsubscribed.php';
    }

    public function verifyEmail(string $token): void {
        $token    = Security::sanitizeInput($token);
        $verified = false;

        $row = Database::fetchOne(
            "SELECT * FROM email_verification_tokens
             WHERE token_hash = ? AND expires_at > NOW() AND used_at IS NULL",
            [hash('sha256', $token)]
        );

        if ($row) {
            Database::query(
                "UPDATE users SET email_verified_at = NOW() WHERE id = ?",
                [$row['user_id']]
            );
            Database::query(
                "UPDATE email_verification_tokens SET used_at = NOW() WHERE id = ?",
                [$row['id']]
            );
            $verified = true;
        }

        require AEGIS_ROOT . '/views/auth/verify_email.php';
    }
}

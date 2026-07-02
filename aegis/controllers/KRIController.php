<?php
declare(strict_types=1);

class KRIController {

    public function index(): void {
        Auth::requirePermission('kri.view');
        $kris = Database::fetchAll(
            "SELECT k.*,
                    u.name as owner_name,
                    r.title as risk_title,
                    kv.value as latest_value,
                    kv.recorded_at as latest_date
             FROM kris k
             LEFT JOIN users u ON u.id = k.owner_id
             LEFT JOIN risks r ON r.id = k.linked_risk_id
             LEFT JOIN LATERAL (
                 SELECT value, recorded_at FROM kri_values WHERE kri_id = k.id
                 ORDER BY recorded_at DESC, id DESC LIMIT 1
             ) kv ON TRUE
             WHERE k.is_active = TRUE
             ORDER BY k.title"
        );
        // Add RAG status + measurement-cadence status to each KRI
        foreach ($kris as &$k) {
            $k['rag']     = self::ragStatus($k);
            $k['measure'] = self::measurementStatus($k['frequency'] ?? 'monthly', $k['latest_date'] ?? null, $k['created_at'] ?? null);
        }
        unset($k);
        $pageTitle    = 'Key Risk Indicators';
        $activeModule = 'kris';
        $breadcrumbs  = [['KRI Dashboard', null]];
        ob_start();
        require AEGIS_ROOT . '/views/kri/index.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function createForm(): void {
        Auth::requirePermission('kri.manage');
        $users = Database::fetchAll("SELECT id, name FROM users WHERE is_active=TRUE ORDER BY name");
        $risks = Database::fetchAll("SELECT id, title FROM risks WHERE status='open' ORDER BY title");
        $pageTitle    = 'New KRI';
        $activeModule = 'kris';
        $breadcrumbs  = [['KRI Dashboard', '/kris'], ['New KRI', null]];
        ob_start();
        require AEGIS_ROOT . '/views/kri/create.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function create(): void {
        Auth::requirePermission('kri.manage');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $title = trim(Security::sanitizeInput($_POST['title'] ?? ''));
        if (!$title) {
            $_SESSION['flash_error'] = 'Title is required.';
            header('Location: /kris/create'); return;
        }
        $validDirs  = ['higher_worse','lower_worse'];
        $validFreqs = ['daily','weekly','monthly','quarterly'];
        $direction  = in_array($_POST['direction'] ?? '', $validDirs, true) ? $_POST['direction'] : 'higher_worse';
        $frequency  = in_array($_POST['frequency'] ?? '', $validFreqs, true) ? $_POST['frequency'] : 'monthly';

        // Thresholds must be ordered consistently with the direction, otherwise
        // ragStatus() mis-classifies values (e.g. amber below green for higher_worse).
        $tGreen = (float)($_POST['threshold_green'] ?? 0);
        $tAmber = (float)($_POST['threshold_amber'] ?? 0);
        $tRed   = (float)($_POST['threshold_red'] ?? 0);
        $ordered = $direction === 'higher_worse'
            ? ($tGreen <= $tAmber && $tAmber <= $tRed)
            : ($tGreen >= $tAmber && $tAmber >= $tRed);
        if (!$ordered) {
            $_SESSION['flash_error'] = $direction === 'higher_worse'
                ? 'Thresholds must satisfy green ≤ amber ≤ red for a "higher is worse" KRI.'
                : 'Thresholds must satisfy green ≥ amber ≥ red for a "lower is worse" KRI.';
            header('Location: /kris/create'); return;
        }

        $id = Database::insert('kris', [
            'title'           => $title,
            'description'     => Security::sanitizeInput($_POST['description'] ?? ''),
            'unit'            => Security::sanitizeInput($_POST['unit'] ?? 'count'),
            'direction'       => $direction,
            'threshold_green' => $tGreen,
            'threshold_amber' => $tAmber,
            'threshold_red'   => $tRed,
            'frequency'       => $frequency,
            'owner_id'        => (int)($_POST['owner_id'] ?? 0) ?: null,
            'linked_risk_id'  => (int)($_POST['linked_risk_id'] ?? 0) ?: null,
            'created_by'      => Auth::id(),
        ]);
        Auth::log('kri_created', 'kris', $id, ['title' => $title]);
        $_SESSION['flash_success'] = 'KRI created.';
        header("Location: /kris/{$id}");
    }

    public function view(string $id): void {
        Auth::requirePermission('kri.view');
        $id = (int)$id;
        $kri = Database::fetchOne(
            "SELECT k.*, u.name as owner_name, r.title as risk_title
             FROM kris k LEFT JOIN users u ON u.id=k.owner_id
             LEFT JOIN risks r ON r.id=k.linked_risk_id WHERE k.id=?", [$id]
        );
        if (!$kri) { http_response_code(404); require AEGIS_ROOT.'/views/errors/404.php'; return; }
        $values = Database::fetchAll(
            "SELECT kv.*, u.name as recorder_name FROM kri_values kv
             LEFT JOIN users u ON u.id=kv.recorded_by
             WHERE kv.kri_id=? ORDER BY kv.recorded_at DESC LIMIT 24", [$id]
        );
        $kri['rag'] = self::ragStatus(array_merge($kri, ['latest_value' => $values[0]['value'] ?? null]));
        $pageTitle    = $kri['title'];
        $activeModule = 'kris';
        $breadcrumbs  = [['KRI Dashboard', '/kris'], [$kri['title'], null]];
        ob_start();
        require AEGIS_ROOT . '/views/kri/view.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    public function recordValue(string $id): void {
        Auth::requirePermission('kri.record');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $id  = (int)$id;
        $kri = Database::fetchOne("SELECT id FROM kris WHERE id=?", [$id]);
        if (!$kri) { http_response_code(404); return; }
        $value = $_POST['value'] ?? '';
        if (!is_numeric($value)) {
            $_SESSION['flash_error'] = 'Value must be numeric.';
            header("Location: /kris/{$id}"); return;
        }
        $date = Security::sanitizeInput($_POST['recorded_at'] ?? date('Y-m-d'));
        Database::insert('kri_values', [
            'kri_id'      => $id,
            'value'       => (float)$value,
            'recorded_at' => $date,
            'notes'       => Security::sanitizeInput($_POST['notes'] ?? ''),
            'recorded_by' => Auth::id(),
        ]);
        Auth::log('kri_value_recorded', 'kri_values', $id, ['value' => $value]);
        $_SESSION['flash_success'] = 'Value recorded.';
        header("Location: /kris/{$id}");
    }

    public function toggle(string $id): void {
        Auth::requirePermission('kri.manage');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        Database::query("UPDATE kris SET is_active = NOT is_active WHERE id=?", [(int)$id]);
        header('Location: /kris');
    }

    /**
     * RAG classification of a KRI's latest value vs its thresholds, honouring
     * direction. Returns 'grey' (no data), 'green', 'amber' or 'red'. A 'red'
     * result is a threshold breach. Public + static so breach detection is
     * reusable by the notifier and unit-testable in isolation.
     */
    /**
     * The measurement cadence expressed in days — how long a recorded value
     * "lasts" before the KRI is due for its next reading. Pure + public static.
     */
    public static function measurementWindowDays(string $frequency): int {
        return match ($frequency) {
            'daily'     => 1,
            'weekly'    => 7,
            'monthly'   => 31,
            'quarterly' => 92,
            default     => 31,
        };
    }

    /**
     * Whether an active KRI is behind on measurement, based on its cadence and
     * the last time a value was recorded (falling back to the KRI's creation
     * date when it has never been measured): 'overdue' (past the window),
     * 'due' (within the last 20% of the window) or 'ok'. Pure + public static so
     * the notifier and the dashboard share one definition and it is unit-tested.
     */
    public static function measurementStatus(string $frequency, ?string $lastRecordedAt, ?string $createdAt): string {
        $baseline = $lastRecordedAt ?: $createdAt;
        if (empty($baseline)) return 'ok';
        $ts = strtotime($baseline);
        if ($ts === false) return 'ok';
        $window  = self::measurementWindowDays($frequency);
        $elapsed = (int) floor((strtotime('today') - $ts) / 86400);
        if ($elapsed > $window) return 'overdue';
        if ($elapsed >= (int) ceil($window * 0.8)) return 'due';
        return 'ok';
    }

    public static function ragStatus(array $kri): string {
        $val = $kri['latest_value'] ?? null;
        if ($val === null) return 'grey';
        $val = (float)$val;
        $green = (float)$kri['threshold_green'];
        $amber = (float)$kri['threshold_amber'];
        $red   = (float)$kri['threshold_red'];
        if ($kri['direction'] === 'higher_worse') {
            if ($val <= $green) return 'green';
            if ($val <= $amber) return 'amber';
            return 'red';
        } else {
            // lower_worse: low values are bad
            if ($val >= $green) return 'green';
            if ($val >= $amber) return 'amber';
            return 'red';
        }
    }
}

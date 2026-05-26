<?php
declare(strict_types=1);

class BowTieController {

    // ─────────────────────────────────────────── view ───────────────────────
    public function view(string $riskId): void {
        Auth::requireAuth();

        $riskId = (int)$riskId;

        $risk = Database::fetchOne(
            "SELECT id, title, risk_id, inherent_score, description
             FROM risks
             WHERE id = ?",
            [$riskId]
        );

        if (!$risk) {
            http_response_code(404);
            require AEGIS_ROOT . '/views/errors/404.php';
            return;
        }

        $causes = Database::fetchAll(
            "SELECT * FROM risk_bowtie_causes
             WHERE risk_id = ?
             ORDER BY sort_order ASC, id ASC",
            [$riskId]
        );

        $consequences = Database::fetchAll(
            "SELECT * FROM risk_bowtie_consequences
             WHERE risk_id = ?
             ORDER BY sort_order ASC, id ASC",
            [$riskId]
        );

        $leftBarriers = Database::fetchAll(
            "SELECT * FROM risk_bowtie_barriers
             WHERE risk_id = ? AND side = 'left'
             ORDER BY sort_order ASC, id ASC",
            [$riskId]
        );

        $rightBarriers = Database::fetchAll(
            "SELECT * FROM risk_bowtie_barriers
             WHERE risk_id = ? AND side = 'right'
             ORDER BY sort_order ASC, id ASC",
            [$riskId]
        );

        // Available controls for barrier linking
        $availableControls = Database::fetchAll(
            "SELECT ci.id, co.code, co.title, cp.name AS package_name, ci.status
             FROM control_implementations ci
             JOIN compliance_objectives co ON co.id = ci.objective_id
             JOIN compliance_packages cp ON cp.id = co.package_id
             ORDER BY cp.name, co.code
             LIMIT 300"
        );

        $pageTitle    = Security::h($risk['risk_id'] ?? 'Risk') . ' Bow-Tie Analysis';
        $activeModule = 'risk';
        $breadcrumbs  = [
            ['Risk Register', '/risk'],
            [Security::h($risk['risk_id'] ?? 'Risk'), '/risk/' . $riskId],
            ['Bow-Tie Analysis', null],
        ];

        ob_start();
        require AEGIS_ROOT . '/views/risk/bowtie.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    // ─────────────────────────────────────────── causes ─────────────────────
    public function addCause(string $riskId): void {
        Auth::requirePermission('risk.write');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $riskId = (int)$riskId;

        $description            = Security::sanitizeInput($_POST['description'] ?? '');
        $causeType              = Security::sanitizeInput($_POST['cause_type'] ?? 'threat');
        $likelihoodContribution = Security::sanitizeInput($_POST['likelihood_contribution'] ?? 'medium');
        $sortOrder              = (int)($_POST['sort_order'] ?? 0);

        if (!$description) {
            $_SESSION['flash_error'] = 'Cause description is required.';
            header('Location: /risk/' . $riskId . '/bowtie');
            return;
        }

        if (!in_array($causeType, ['threat', 'vulnerability', 'hazard', 'event'], true)) {
            $causeType = 'threat';
        }
        if (!in_array($likelihoodContribution, ['low', 'medium', 'high'], true)) {
            $likelihoodContribution = 'medium';
        }

        $id = Database::insert('risk_bowtie_causes', [
            'risk_id'                 => $riskId,
            'description'             => $description,
            'cause_type'              => $causeType,
            'likelihood_contribution' => $likelihoodContribution,
            'sort_order'              => $sortOrder,
            'created_by'              => Auth::id(),
        ]);

        Auth::log('bowtie_add_cause', 'risk_bowtie_causes', $id, [
            'risk_id'    => $riskId,
            'cause_type' => $causeType,
        ]);

        $_SESSION['flash_success'] = 'Cause added to bow-tie.';
        header('Location: /risk/' . $riskId . '/bowtie');
    }

    public function removeCause(string $id): void {
        Auth::requirePermission('risk.write');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $id   = (int)$id;
        $row  = Database::fetchOne(
            "SELECT risk_id FROM risk_bowtie_causes WHERE id = ?",
            [$id]
        );

        if ($row) {
            Database::query("DELETE FROM risk_bowtie_causes WHERE id = ?", [$id]);
            Auth::log('bowtie_remove_cause', 'risk_bowtie_causes', $id, [
                'risk_id' => (int)$row['risk_id'],
            ]);
            $_SESSION['flash_success'] = 'Cause removed.';
            header('Location: /risk/' . (int)$row['risk_id'] . '/bowtie');
            return;
        }

        header('Location: /risk');
    }

    // ─────────────────────────────────────────── consequences ────────────────
    public function addConsequence(string $riskId): void {
        Auth::requirePermission('risk.write');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $riskId = (int)$riskId;

        $description     = Security::sanitizeInput($_POST['description'] ?? '');
        $consequenceType = Security::sanitizeInput($_POST['consequence_type'] ?? 'impact');
        $severity        = Security::sanitizeInput($_POST['severity'] ?? 'medium');
        $sortOrder       = (int)($_POST['sort_order'] ?? 0);

        if (!$description) {
            $_SESSION['flash_error'] = 'Consequence description is required.';
            header('Location: /risk/' . $riskId . '/bowtie');
            return;
        }

        if (!in_array($consequenceType, ['financial', 'operational', 'reputational', 'legal', 'safety', 'impact'], true)) {
            $consequenceType = 'impact';
        }
        if (!in_array($severity, ['low', 'medium', 'high', 'critical'], true)) {
            $severity = 'medium';
        }

        $id = Database::insert('risk_bowtie_consequences', [
            'risk_id'          => $riskId,
            'description'      => $description,
            'consequence_type' => $consequenceType,
            'severity'         => $severity,
            'sort_order'       => $sortOrder,
            'created_by'       => Auth::id(),
        ]);

        Auth::log('bowtie_add_consequence', 'risk_bowtie_consequences', $id, [
            'risk_id'          => $riskId,
            'consequence_type' => $consequenceType,
        ]);

        $_SESSION['flash_success'] = 'Consequence added to bow-tie.';
        header('Location: /risk/' . $riskId . '/bowtie');
    }

    public function removeConsequence(string $id): void {
        Auth::requirePermission('risk.write');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $id  = (int)$id;
        $row = Database::fetchOne(
            "SELECT risk_id FROM risk_bowtie_consequences WHERE id = ?",
            [$id]
        );

        if ($row) {
            Database::query("DELETE FROM risk_bowtie_consequences WHERE id = ?", [$id]);
            Auth::log('bowtie_remove_consequence', 'risk_bowtie_consequences', $id, [
                'risk_id' => (int)$row['risk_id'],
            ]);
            $_SESSION['flash_success'] = 'Consequence removed.';
            header('Location: /risk/' . (int)$row['risk_id'] . '/bowtie');
            return;
        }

        header('Location: /risk');
    }

    // ─────────────────────────────────────────── barriers ───────────────────
    public function addBarrier(string $riskId): void {
        Auth::requirePermission('risk.write');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $riskId = (int)$riskId;

        $side                    = Security::sanitizeInput($_POST['side'] ?? 'left');
        $description             = Security::sanitizeInput($_POST['description'] ?? '');
        $barrierType             = Security::sanitizeInput($_POST['barrier_type'] ?? 'control');
        $effectiveness           = Security::sanitizeInput($_POST['effectiveness'] ?? 'partial');
        $controlImplementationId = !empty($_POST['control_implementation_id'])
                                   ? (int)$_POST['control_implementation_id']
                                   : null;
        $sortOrder               = (int)($_POST['sort_order'] ?? 0);

        if (!$description) {
            $_SESSION['flash_error'] = 'Barrier description is required.';
            header('Location: /risk/' . $riskId . '/bowtie');
            return;
        }

        if (!in_array($side, ['left', 'right'], true)) {
            $side = 'left';
        }
        if (!in_array($barrierType, ['control', 'procedure', 'training', 'technology', 'monitoring'], true)) {
            $barrierType = 'control';
        }
        if (!in_array($effectiveness, ['degraded', 'partial', 'substantial', 'full'], true)) {
            $effectiveness = 'partial';
        }

        $id = Database::insert('risk_bowtie_barriers', [
            'risk_id'                    => $riskId,
            'side'                       => $side,
            'description'                => $description,
            'barrier_type'               => $barrierType,
            'effectiveness'              => $effectiveness,
            'control_implementation_id'  => $controlImplementationId,
            'sort_order'                 => $sortOrder,
            'created_by'                 => Auth::id(),
        ]);

        Auth::log('bowtie_add_barrier', 'risk_bowtie_barriers', $id, [
            'risk_id'       => $riskId,
            'side'          => $side,
            'barrier_type'  => $barrierType,
            'effectiveness' => $effectiveness,
        ]);

        $_SESSION['flash_success'] = ucfirst($side === 'left' ? 'Preventive' : 'Recovery') . ' barrier added.';
        header('Location: /risk/' . $riskId . '/bowtie');
    }

    public function removeBarrier(string $id): void {
        Auth::requirePermission('risk.write');

        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $id  = (int)$id;
        $row = Database::fetchOne(
            "SELECT risk_id FROM risk_bowtie_barriers WHERE id = ?",
            [$id]
        );

        if ($row) {
            Database::query("DELETE FROM risk_bowtie_barriers WHERE id = ?", [$id]);
            Auth::log('bowtie_remove_barrier', 'risk_bowtie_barriers', $id, [
                'risk_id' => (int)$row['risk_id'],
            ]);
            $_SESSION['flash_success'] = 'Barrier removed.';
            header('Location: /risk/' . (int)$row['risk_id'] . '/bowtie');
            return;
        }

        header('Location: /risk');
    }
}

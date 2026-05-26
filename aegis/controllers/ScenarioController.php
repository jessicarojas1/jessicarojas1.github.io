<?php
declare(strict_types=1);

class ScenarioController {

    private const SCENARIO_TYPES = ['stress', 'base', 'optimistic', 'catastrophic', 'regulatory'];

    // ─────────────────────────────────────────── index ──────────────────────
    public function index(): void {
        Auth::requireAuth();

        $scenarios = Database::fetchAll(
            "SELECT rs.*,
                    r.title AS risk_title, r.risk_id AS risk_code, r.inherent_score,
                    u.name AS created_by_name
             FROM risk_scenarios rs
             JOIN risks r ON r.id = rs.risk_id
             LEFT JOIN users u ON u.id = rs.created_by
             ORDER BY rs.scenario_score DESC NULLS LAST, rs.created_at DESC"
        );

        // Summary stats
        $countByType = [];
        foreach (self::SCENARIO_TYPES as $t) {
            $countByType[$t] = 0;
        }
        $highestScore     = 0;
        $totalFinancial   = 0.0;

        foreach ($scenarios as $s) {
            $type = $s['scenario_type'];
            if (isset($countByType[$type])) {
                $countByType[$type]++;
            }
            if ((int)$s['scenario_score'] > $highestScore) {
                $highestScore = (int)$s['scenario_score'];
            }
            if (!empty($s['financial_impact_est'])) {
                $totalFinancial += (float)$s['financial_impact_est'];
            }
        }

        $pageTitle    = 'Risk Scenarios';
        $activeModule = 'risk';
        $breadcrumbs  = [['Risk Register', '/risk'], ['Scenarios', null]];
        ob_start();
        require AEGIS_ROOT . '/views/risk/scenarios_index.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    // ─────────────────────────────────────────── createForm ─────────────────
    public function createForm(string $riskId): void {
        Auth::requirePermission('risk.write');
        $riskId = (int)$riskId;

        $risk = Database::fetchOne(
            "SELECT r.id, r.risk_id, r.title, r.likelihood, r.impact, r.inherent_score,
                    r.financial_min, r.financial_likely, r.financial_max
             FROM risks r
             WHERE r.id = ?",
            [$riskId]
        );
        if (!$risk) {
            http_response_code(404);
            require AEGIS_ROOT . '/views/errors/404.php';
            return;
        }

        $pageTitle    = 'New Risk Scenario';
        $activeModule = 'risk';
        $breadcrumbs  = [
            ['Risk Register', '/risk'],
            [Security::h($risk['title']), '/risk/' . $riskId],
            ['New Scenario', null],
        ];
        ob_start();
        require AEGIS_ROOT . '/views/risk/scenario_form.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    // ─────────────────────────────────────────── create ─────────────────────
    public function create(string $riskId): void {
        Auth::requirePermission('risk.write');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $riskId = (int)$riskId;
        $risk   = Database::fetchOne(
            "SELECT id, likelihood, impact FROM risks WHERE id = ?",
            [$riskId]
        );
        if (!$risk) {
            http_response_code(404);
            return;
        }

        $name         = trim(Security::sanitizeInput($_POST['name'] ?? ''));
        $description  = trim(Security::sanitizeInput($_POST['description'] ?? ''));
        $scenarioType = Security::sanitizeInput($_POST['scenario_type'] ?? 'stress');
        $assumptions  = trim(Security::sanitizeInput($_POST['assumptions'] ?? ''));

        if (!$name) {
            $_SESSION['flash_error'] = 'Scenario name is required.';
            header("Location: /risk/{$riskId}/scenario/create");
            return;
        }

        if (!in_array($scenarioType, self::SCENARIO_TYPES, true)) {
            $scenarioType = 'stress';
        }

        $lMult = isset($_POST['likelihood_multiplier']) ? (float)$_POST['likelihood_multiplier'] : 1.0;
        $iMult = isset($_POST['impact_multiplier'])     ? (float)$_POST['impact_multiplier']     : 1.0;
        $lMult = max(0.1, min(5.0, $lMult));
        $iMult = max(0.1, min(5.0, $iMult));

        $scenarioLikelihood = min(5, (int)round((int)$risk['likelihood'] * $lMult));
        $scenarioImpact     = min(5, (int)round((int)$risk['impact']     * $iMult));
        $scenarioScore      = $scenarioLikelihood * $scenarioImpact;

        $financialImpact = !empty($_POST['financial_impact_est'])
            ? (float)$_POST['financial_impact_est'] : null;

        $probability = null;
        if (isset($_POST['probability']) && $_POST['probability'] !== '') {
            $probability = max(0.0, min(100.0, (float)$_POST['probability']));
        }

        $id = Database::insert('risk_scenarios', [
            'risk_id'                => $riskId,
            'name'                   => $name,
            'description'            => $description ?: null,
            'scenario_type'          => $scenarioType,
            'likelihood_multiplier'  => $lMult,
            'impact_multiplier'      => $iMult,
            'scenario_likelihood'    => $scenarioLikelihood,
            'scenario_impact'        => $scenarioImpact,
            'scenario_score'         => $scenarioScore,
            'financial_impact_est'   => $financialImpact,
            'probability'            => $probability,
            'assumptions'            => $assumptions ?: null,
            'created_by'             => Auth::id(),
        ]);

        Auth::log('create_scenario', 'risk_scenarios', $id, [
            'risk_id'        => $riskId,
            'scenario_type'  => $scenarioType,
            'scenario_score' => $scenarioScore,
        ]);

        $_SESSION['flash_success'] = 'Scenario "' . $name . '" created.';
        header("Location: /risk/{$riskId}");
    }

    // ─────────────────────────────────────────── delete ─────────────────────
    public function delete(string $id): void {
        Auth::requirePermission('risk.write');
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $id       = (int)$id;
        $scenario = Database::fetchOne(
            "SELECT id, risk_id, name FROM risk_scenarios WHERE id = ?",
            [$id]
        );
        if (!$scenario) {
            http_response_code(404);
            return;
        }

        $riskId = (int)$scenario['risk_id'];
        Database::query("DELETE FROM risk_scenarios WHERE id = ?", [$id]);

        Auth::log('delete_scenario', 'risk_scenarios', $id, [
            'risk_id' => $riskId,
            'name'    => $scenario['name'],
        ]);

        $_SESSION['flash_success'] = 'Scenario deleted.';
        header("Location: /risk/{$riskId}");
    }
}

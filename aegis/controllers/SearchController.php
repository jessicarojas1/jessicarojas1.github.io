<?php
/**
 * SearchController — Global full-text search across all AEGIS modules.
 *
 * Runs up to 7 parallel ILIKE queries (risks, policies, audits, incidents,
 * vendors, controls, assets), merges results keyed by type, and renders
 * views/search/index.php.
 */
class SearchController {

    public function index(): void {
        Auth::requireAuth();

        $q = Security::sanitizeInput($_GET['q'] ?? '');

        $results   = [];
        $totalHits = 0;
        $tooShort  = false;

        if ($q !== '' && strlen($q) < 2) {
            $tooShort = true;
        } elseif ($q !== '') {
            $like = '%' . $q . '%';

            // ── risks ─────────────────────────────────────────────────────────
            try {
                $risks = Database::fetchAll(
                    "SELECT id, title AS label, risk_id AS sub, 'risk' AS type,
                            '/risk/'||id AS url, inherent_score AS score_num
                     FROM risks
                     WHERE (title ILIKE ? OR description ILIKE ?)
                       AND status != 'closed'
                     ORDER BY inherent_score DESC
                     LIMIT 10",
                    [$like, $like]
                );
            } catch (\Throwable $e) {
                error_log('Search risks error: ' . $e->getMessage());
                $risks = [];
            }

            // ── policies ──────────────────────────────────────────────────────
            try {
                $policies = Database::fetchAll(
                    "SELECT id, title AS label, status AS sub, 'policy' AS type,
                            '/policy/'||id AS url, NULL AS score_num
                     FROM policies
                     WHERE title ILIKE ?
                     LIMIT 10",
                    [$like]
                );
            } catch (\Throwable $e) {
                error_log('Search policies error: ' . $e->getMessage());
                $policies = [];
            }

            // ── audits ────────────────────────────────────────────────────────
            try {
                $audits = Database::fetchAll(
                    "SELECT id, name AS label, status AS sub, 'audit' AS type,
                            '/audit/'||id AS url, score AS score_num
                     FROM audits
                     WHERE name ILIKE ? OR description ILIKE ?
                     LIMIT 10",
                    [$like, $like]
                );
            } catch (\Throwable $e) {
                error_log('Search audits error: ' . $e->getMessage());
                $audits = [];
            }

            // ── incidents ─────────────────────────────────────────────────────
            try {
                $incidents = Database::fetchAll(
                    "SELECT id, title AS label, severity AS sub, 'incident' AS type,
                            '/incident/'||id AS url, NULL AS score_num
                     FROM incidents
                     WHERE (title ILIKE ? OR description ILIKE ?)
                       AND status NOT IN ('resolved','closed')
                     LIMIT 10",
                    [$like, $like]
                );
            } catch (\Throwable $e) {
                error_log('Search incidents error: ' . $e->getMessage());
                $incidents = [];
            }

            // ── vendors ───────────────────────────────────────────────────────
            try {
                $vendors = Database::fetchAll(
                    "SELECT id, name AS label, vendor_type AS sub, 'vendor' AS type,
                            '/vendor/'||id AS url, NULL AS score_num
                     FROM vendors
                     WHERE name ILIKE ?
                     LIMIT 10",
                    [$like]
                );
            } catch (\Throwable $e) {
                error_log('Search vendors error: ' . $e->getMessage());
                $vendors = [];
            }

            // ── controls (compliance_objectives level=2) ───────────────────────
            try {
                $controls = Database::fetchAll(
                    "SELECT co.id, co.title AS label, co.code AS sub, 'control' AS type,
                            '/compliance/'||co.package_id AS url, NULL AS score_num
                     FROM compliance_objectives co
                     WHERE (co.title ILIKE ? OR co.code ILIKE ?)
                       AND co.level = 2
                     LIMIT 10",
                    [$like, $like]
                );
            } catch (\Throwable $e) {
                error_log('Search controls error: ' . $e->getMessage());
                $controls = [];
            }

            // ── assets (table may not exist yet) ──────────────────────────────
            try {
                $assets = Database::fetchAll(
                    "SELECT id, name AS label, asset_type AS sub, 'asset' AS type,
                            '/assets/'||id AS url, NULL AS score_num
                     FROM assets
                     WHERE name ILIKE ?
                     LIMIT 10",
                    [$like]
                );
            } catch (\Throwable $e) {
                error_log('Search assets error: ' . $e->getMessage());
                $assets = [];
            }

            // ── merge into keyed array ─────────────────────────────────────────
            foreach ([
                'risk'     => $risks,
                'policy'   => $policies,
                'audit'    => $audits,
                'incident' => $incidents,
                'vendor'   => $vendors,
                'control'  => $controls,
                'asset'    => $assets,
            ] as $type => $rows) {
                if (!empty($rows)) {
                    $results[$type] = $rows;
                    $totalHits += count($rows);
                }
            }
        }

        $pageTitle   = $q !== '' ? 'Search: ' . $q : 'Search';
        $activeModule = '';
        $breadcrumbs  = [['Search', '/search']];

        ob_start();
        require AEGIS_ROOT . '/views/search/index.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }
}

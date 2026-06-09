<?php
/**
 * Workflow — runtime helper for stateful (Comala-style) workflows applied to
 * content (pages, documents). Loads current state, available transitions,
 * history, and the workflows applicable to a piece of content. Never throws.
 */
final class Workflow {

    /** Current workflow status of a content item, with state + template info. */
    public static function status(string $type, int $id): ?array {
        try {
            return Database::fetchOne(
                "SELECT ws.*, st.name AS state_name, st.color AS state_color, st.kind AS state_kind,
                        wt.name AS workflow_name, u.name AS updated_by_name
                 FROM wf_status ws
                 JOIN wf_states st ON st.id = ws.state_id
                 JOIN workflow_templates wt ON wt.id = ws.template_id
                 LEFT JOIN users u ON u.id = ws.updated_by
                 WHERE ws.entity_type = ? AND ws.entity_id = ?", [$type, $id]
            );
        } catch (Throwable) { return null; }
    }

    /** Transitions leaving the given state (with target state + approver info). */
    public static function transitions(int $templateId, int $stateId): array {
        try {
            return Database::fetchAll(
                "SELECT tr.*, ts.name AS to_name, ts.color AS to_color, ts.kind AS to_kind, u.name AS approver_name
                 FROM wf_transitions tr
                 JOIN wf_states ts ON ts.id = tr.to_state_id
                 LEFT JOIN users u ON u.id = tr.approver_user_id
                 WHERE tr.template_id = ? AND tr.from_state_id = ? ORDER BY tr.id", [$templateId, $stateId]
            );
        } catch (Throwable) { return []; }
    }

    public static function history(string $type, int $id): array {
        try {
            return Database::fetchAll(
                "SELECT h.*, fs.name AS from_name, ts.name AS to_name, u.name AS user_name
                 FROM wf_history h
                 LEFT JOIN wf_states fs ON fs.id = h.from_state_id
                 LEFT JOIN wf_states ts ON ts.id = h.to_state_id
                 LEFT JOIN users u ON u.id = h.user_id
                 WHERE h.entity_type = ? AND h.entity_id = ? ORDER BY h.created_at DESC", [$type, $id]
            );
        } catch (Throwable) { return []; }
    }

    /**
     * Active stateful workflows applicable to content in $spaceId:
     * those assigned to the space, plus global ones (assigned to no space).
     */
    public static function applicable(?int $spaceId): array {
        try {
            return Database::fetchAll(
                "SELECT DISTINCT wt.id, wt.name FROM workflow_templates wt
                 JOIN wf_states st ON st.template_id = wt.id
                 WHERE wt.is_active = TRUE
                   AND ( NOT EXISTS (SELECT 1 FROM workflow_space_assignments a WHERE a.template_id = wt.id)
                         OR EXISTS (SELECT 1 FROM workflow_space_assignments a WHERE a.template_id = wt.id AND a.space_id = ?) )
                 ORDER BY wt.name", [$spaceId ?? 0]
            );
        } catch (Throwable) { return []; }
    }

    public static function initialState(int $templateId): ?array {
        return Database::fetchOne(
            "SELECT * FROM wf_states WHERE template_id = ? ORDER BY is_initial DESC, sort_order, id LIMIT 1", [$templateId]
        );
    }

    /** May the current user perform this transition? */
    public static function canAct(array $transition): bool {
        if (Auth::role() === 'admin') return true;
        if (!empty($transition['approver_user_id'])) return (int)$transition['approver_user_id'] === Auth::id();
        if (!empty($transition['approver_role']))    return Auth::role() === $transition['approver_role'];
        return true; // open transition
    }

    public static function esignatureRequired(): bool {
        try {
            $r = Database::fetchOne("SELECT value FROM settings WHERE key = 'require_esignature'");
            return ($r['value'] ?? '0') === '1';
        } catch (Throwable) { return false; }
    }
}

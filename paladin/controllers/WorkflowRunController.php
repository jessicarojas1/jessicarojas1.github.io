<?php
declare(strict_types=1);

/**
 * WorkflowRunController — apply a stateful workflow to a page/document and
 * move it through states via transitions, recording an audit trail (and an
 * optional e-signature). Gated by the underlying entity's edit permission;
 * each transition additionally checks the transition's approver role/user.
 */
class WorkflowRunController {

    private const EDIT_PERM = ['page' => 'page.edit', 'document' => 'document.edit'];
    private const PATH = ['page' => '/pages/', 'document' => '/documents/'];

    /** [spaceId, backUrl] for an entity, or [null,'/'] if it doesn't exist. */
    private function resolve(string $type, int $id): array {
        if ($type === 'page') {
            $r = Database::fetchOne("SELECT space_id FROM pages WHERE id = ?", [$id]);
        } elseif ($type === 'document') {
            $r = Database::fetchOne("SELECT space_id FROM documents WHERE id = ?", [$id]);
        } else { return [null, '/']; }
        if (!$r) return [null, '/'];
        return [$r['space_id'] !== null ? (int)$r['space_id'] : null, self::PATH[$type] . $id];
    }

    private function guard(string $type): bool {
        $perm = self::EDIT_PERM[$type] ?? null;
        if (!$perm || !Auth::can($perm)) { http_response_code(403); require PALADIN_ROOT . '/views/errors/403.php'; return false; }
        return true;
    }

    public function apply(): void {
        Auth::requireAuth();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $type = Security::sanitizeInput($_POST['entity_type'] ?? '');
        $id   = (int)($_POST['entity_id'] ?? 0);
        $tpl  = (int)($_POST['template_id'] ?? 0);
        if (!isset(self::PATH[$type]) || $id <= 0) { http_response_code(400); return; }
        if (!$this->guard($type)) return;
        [$spaceId, $back] = $this->resolve($type, $id);
        if ($back === '/') { http_response_code(404); return; }

        // Template must be active, stateful, and applicable to this content's space
        $ok = false;
        foreach (Workflow::applicable($spaceId) as $a) { if ((int)$a['id'] === $tpl) { $ok = true; break; } }
        $initial = $ok ? Workflow::initialState($tpl) : null;
        if (!$initial) { $_SESSION['flash_error'] = 'That workflow can’t be applied here.'; header('Location: ' . $back); return; }

        Database::query(
            "INSERT INTO wf_status (entity_type, entity_id, template_id, state_id, updated_by, updated_at)
             VALUES (?,?,?,?,?,NOW())
             ON CONFLICT (entity_type, entity_id)
             DO UPDATE SET template_id = EXCLUDED.template_id, state_id = EXCLUDED.state_id, updated_by = EXCLUDED.updated_by, updated_at = NOW()",
            [$type, $id, $tpl, (int)$initial['id'], Auth::id()]
        );
        Database::insert('wf_history', [
            'entity_type' => $type, 'entity_id' => $id, 'template_id' => $tpl,
            'from_state_id' => null, 'to_state_id' => (int)$initial['id'], 'action_label' => 'Workflow applied',
            'user_id' => Auth::id(),
        ]);
        Auth::log('workflow_apply', $type, $id, ['template' => $tpl]);
        $_SESSION['flash_success'] = 'Workflow applied — now in “' . $initial['name'] . '”.';
        header('Location: ' . $back);
    }

    public function transition(): void {
        Auth::requireAuth();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $type = Security::sanitizeInput($_POST['entity_type'] ?? '');
        $id   = (int)($_POST['entity_id'] ?? 0);
        $trId = (int)($_POST['transition_id'] ?? 0);
        if (!isset(self::PATH[$type]) || $id <= 0) { http_response_code(400); return; }
        if (!$this->guard($type)) return;
        [, $back] = $this->resolve($type, $id);
        if ($back === '/') { http_response_code(404); return; }

        $status = Workflow::status($type, $id);
        if (!$status) { $_SESSION['flash_error'] = 'No workflow is applied.'; header('Location: ' . $back); return; }
        $tr = Database::fetchOne(
            "SELECT * FROM wf_transitions WHERE id = ? AND template_id = ? AND from_state_id = ?",
            [$trId, (int)$status['template_id'], (int)$status['state_id']]
        );
        if (!$tr) { $_SESSION['flash_error'] = 'That transition isn’t available from the current state.'; header('Location: ' . $back); return; }
        if (!Workflow::canAct($tr)) { $_SESSION['flash_error'] = 'You’re not authorized to perform this transition.'; header('Location: ' . $back); return; }

        // Optional e-signature (password re-authentication)
        $signed = false;
        if (Workflow::esignatureRequired()) {
            $pw = (string)($_POST['esign_password'] ?? '');
            $me = Database::fetchOne("SELECT password_hash FROM users WHERE id = ?", [Auth::id()]);
            if (!$me || !Security::verifyPassword($pw, $me['password_hash'])) {
                $_SESSION['flash_error'] = 'E-signature failed: incorrect password.';
                header('Location: ' . $back); return;
            }
            $signed = true;
        }

        $fromState = (int)$status['state_id'];
        Database::query("UPDATE wf_status SET state_id = ?, updated_by = ?, updated_at = NOW() WHERE entity_type = ? AND entity_id = ?",
            [(int)$tr['to_state_id'], Auth::id(), $type, $id]);
        Database::insert('wf_history', [
            'entity_type' => $type, 'entity_id' => $id, 'template_id' => (int)$status['template_id'],
            'from_state_id' => $fromState, 'to_state_id' => (int)$tr['to_state_id'],
            'action_label' => $tr['action_label'], 'user_id' => Auth::id(),
            'signed' => $signed ? 't' : 'f', 'comment' => Security::sanitizeInput($_POST['comment'] ?? '') ?: null,
        ]);
        Auth::log('workflow_transition', $type, $id, ['action' => $tr['action_label'], 'signed' => $signed]);
        Webhook::dispatch('workflow.transitioned', [
            'entity_type' => $type, 'entity_id' => $id,
            'action' => $tr['action_label'], 'to_state_id' => (int)$tr['to_state_id'],
            'signed' => $signed, 'actor' => Auth::id(),
        ]);
        $_SESSION['flash_success'] = 'Moved via “' . $tr['action_label'] . '”.';
        header('Location: ' . $back);
    }

    public function remove(): void {
        Auth::requireAuth();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) { http_response_code(403); return; }
        $type = Security::sanitizeInput($_POST['entity_type'] ?? '');
        $id   = (int)($_POST['entity_id'] ?? 0);
        if (!isset(self::PATH[$type]) || $id <= 0) { http_response_code(400); return; }
        if (!$this->guard($type)) return;
        [, $back] = $this->resolve($type, $id);
        Database::query("DELETE FROM wf_status WHERE entity_type = ? AND entity_id = ?", [$type, $id]);
        Auth::log('workflow_remove', $type, $id);
        $_SESSION['flash_success'] = 'Workflow removed.';
        header('Location: ' . ($back === '/' ? '/' : $back));
    }
}

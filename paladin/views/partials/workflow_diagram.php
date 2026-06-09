<?php
/**
 * Visual workflow state-transition diagram (Comala-style).
 * Expects: $states, $transitions, $wf (template row). Optional: $wfDiagramEditable (bool).
 * Renders an SVG canvas + a JSON data island; app.js draws nodes/arrows and,
 * when editable, supports drag-to-reposition and click-to-connect.
 */
$editable = !empty($wfDiagramEditable);
$diagramStates = array_map(static fn($s) => [
    'id'        => (int)$s['id'],
    'name'      => $s['name'],
    'color'     => $s['color'] ?: '#64748b',
    'kind'      => $s['kind'],
    'isInitial' => !empty($s['is_initial']) && $s['is_initial'] !== 'f',
    'x'         => isset($s['pos_x']) && $s['pos_x'] !== null ? (int)$s['pos_x'] : null,
    'y'         => isset($s['pos_y']) && $s['pos_y'] !== null ? (int)$s['pos_y'] : null,
], $states);
$diagramTransitions = array_map(static fn($t) => [
    'id'    => (int)$t['id'],
    'from'  => (int)$t['from_state_id'],
    'to'    => (int)$t['to_state_id'],
    'label' => $t['action_label'],
], $transitions);
?>
<div class="card" style="margin-bottom:18px">
  <div class="card-header">
    <div class="card-header-left"><span class="card-title"><i class="bi bi-diagram-3-fill"></i> Workflow Diagram</span></div>
    <?php if ($editable): ?><span class="form-hint">Drag states to arrange · click <strong>Connect</strong>, then two states to add a transition.</span><?php endif; ?>
  </div>
  <div class="card-body">
    <?php if ($editable): ?>
    <div style="display:flex;gap:8px;margin-bottom:10px;align-items:center">
      <button type="button" class="btn btn-sm btn-light" data-wf-connect-toggle><i class="bi bi-bezier2"></i> Connect</button>
      <button type="button" class="btn btn-sm btn-light" data-wf-autolayout><i class="bi bi-grid-3x3-gap"></i> Auto-arrange</button>
      <span class="form-hint" data-wf-hint></span>
    </div>
    <!-- Hidden form the diagram submits to create a transition between two clicked states -->
    <form method="POST" action="/workflows/<?= (int)$wf['id'] ?>/transitions" data-wf-connect-form hidden>
      <?= Security::csrfField() ?>
      <input type="hidden" name="from_state_id" data-wf-from>
      <input type="hidden" name="to_state_id"   data-wf-to>
      <input type="hidden" name="action_label" value="Transition">
    </form>
    <?php endif; ?>
    <div style="overflow:auto;border:1px solid var(--border-light);border-radius:10px;background:var(--bg-subtle,transparent)">
      <svg data-wf-diagram
           data-wf-id="<?= (int)$wf['id'] ?>"
           <?= $editable ? 'data-wf-editable="1"' : '' ?>
           width="960" height="360" style="display:block;min-width:960px;font-family:inherit;touch-action:none">
        <defs>
          <marker id="wf-arrow" viewBox="0 0 10 10" refX="9" refY="5" markerWidth="7" markerHeight="7" orient="auto-start-reverse">
            <path d="M0 0 L10 5 L0 10 z" fill="var(--text-muted, #64748b)"></path>
          </marker>
        </defs>
        <g data-wf-edges></g>
        <g data-wf-nodes></g>
      </svg>
    </div>
    <?php if (!$states): ?><div class="form-hint" style="margin-top:8px">No states yet — add states below and they'll appear here.</div><?php endif; ?>
  </div>
</div>
<script type="application/json" data-wf-data="<?= (int)$wf['id'] ?>" nonce="<?= Security::nonce() ?>"><?= json_encode(['states' => $diagramStates, 'transitions' => $diagramTransitions], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) ?></script>

  (function() {

    // Pre-populated threat scenarios
    // Fields: id, threat, vulnerability, likelihood, impact, controls
    const defaultRisks = [
      {
        id: 'R-001',
        threat: 'CUI/CDI exposure via AI prompt logging by vendor',
        vuln: 'Vendor retains user prompts containing sensitive data in server-side logs accessible to vendor staff',
        l: 4, i: 5,
        controls: 'Contractual prohibition on training data use; DPA executed',
        treatment: 'Mitigate'
      },
      {
        id: 'R-002',
        threat: 'Unauthorized model training on proprietary engineering data',
        vuln: 'Default AI service settings enable data reuse for model improvement without explicit opt-out',
        l: 3, i: 5,
        controls: 'Vendor attestation required; enterprise plan with no-training clause',
        treatment: 'Mitigate'
      },
      {
        id: 'R-003',
        threat: 'Prompt injection via malicious document processing',
        vuln: 'AI tool processes externally-supplied documents that contain adversarial instructions embedded in content',
        l: 3, i: 4,
        controls: 'Prompt injection testing; input validation; sandbox document processing',
        treatment: 'Mitigate'
      },
      {
        id: 'R-004',
        threat: 'AI-generated engineering errors (hallucination) in safety-critical designs',
        vuln: 'Engineers rely on AI outputs for specifications, calculations, or code without mandatory independent verification',
        l: 4, i: 5,
        controls: 'Mandatory human review policy; AI output cannot substitute for PE review',
        treatment: 'Mitigate'
      },
      {
        id: 'R-005',
        threat: 'Foreign adversary access to AI vendor infrastructure',
        vuln: 'Vendor employs foreign nationals or operates data centers in adversary-accessible jurisdictions',
        l: 3, i: 5,
        controls: 'Vendor screening questionnaire (TMP-AI-003); FedRAMP authorization check',
        treatment: 'Mitigate'
      },
      {
        id: 'R-006',
        threat: 'ITAR-controlled technical data submitted to non-compliant AI service',
        vuln: 'Users unknowingly input export-controlled design data, schematics, or specifications into a commercial AI tool',
        l: 4, i: 5,
        controls: 'ITAR training; acceptable use policy; DLP monitoring on AI prompts',
        treatment: 'Mitigate'
      },
      {
        id: 'R-007',
        threat: 'Insider threat: employee exfiltrating data via AI prompts',
        vuln: 'Malicious or negligent insider uses AI tool to aggregate and exfiltrate sensitive data through prompt queries',
        l: 2, i: 4,
        controls: 'User activity monitoring; DLP rules; behavior analytics (UEBA)',
        treatment: 'Mitigate'
      },
      {
        id: 'R-008',
        threat: 'Vendor security breach exposing organizational AI interaction logs',
        vuln: 'Vendor infrastructure compromised; attacker gains access to stored prompts and responses',
        l: 3, i: 4,
        controls: 'Vendor SOC 2 Type II review; breach notification SLA in contract; encryption at rest',
        treatment: 'Transfer'
      },
      {
        id: 'R-009',
        threat: 'AI tool used to bypass peer-review and configuration management processes',
        vuln: 'Engineers submit AI-generated artifacts directly to production without standard review gates',
        l: 3, i: 4,
        controls: 'Acceptable use policy; CM process updated to flag AI-generated artifacts for additional review',
        treatment: 'Mitigate'
      },
      {
        id: 'R-010',
        threat: 'Loss of audit trail due to AI tool logging failure',
        vuln: 'AI tool logging is disabled, misconfigured, or not integrated with SIEM, leaving no record of AI interactions',
        l: 2, i: 3,
        controls: 'SIEM integration requirement; logging configuration verification during onboarding',
        treatment: 'Mitigate'
      }
    ];

    function getRiskLevel(score) {
      if (score >= 20) return { label: 'Critical', cls: 'badge-critical' };
      if (score >= 15) return { label: 'High',     cls: 'badge-high' };
      if (score >= 7)  return { label: 'Medium',   cls: 'badge-medium' };
      return                  { label: 'Low',      cls: 'badge-low' };
    }

    function buildRow(risk) {
      const score = risk.l * risk.i;
      const rl = getRiskLevel(score);
      return `
        <tr>
          <td class="text-center fw-semibold">${risk.id}</td>
          <td>${risk.threat}</td>
          <td><textarea class="form-control form-control-sm" rows="3">${risk.vuln}</textarea></td>
          <td class="text-center">
            <select class="form-select form-select-sm likelihood-sel" aria-label="Likelihood">
              ${[1,2,3,4,5].map(n=>`<option value="${n}"${n===risk.l?' selected':''}>${n}</option>`).join('')}
            </select>
          </td>
          <td class="text-center">
            <select class="form-select form-select-sm impact-sel" aria-label="Impact">
              ${[1,2,3,4,5].map(n=>`<option value="${n}"${n===risk.i?' selected':''}>${n}</option>`).join('')}
            </select>
          </td>
          <td class="risk-score text-center score-cell">${score}</td>
          <td class="text-center level-cell"><span class="${rl.cls}">${rl.label}</span></td>
          <td><textarea class="form-control form-control-sm" rows="3">${risk.controls}</textarea></td>
          <td>
            <select class="form-select form-select-sm treatment-sel" aria-label="Treatment">
              <option${risk.treatment==='Accept'?' selected':''}>Accept</option>
              <option${risk.treatment==='Mitigate'?' selected':''}>Mitigate</option>
              <option${risk.treatment==='Transfer'?' selected':''}>Transfer</option>
              <option${risk.treatment==='Avoid'?' selected':''}>Avoid</option>
            </select>
          </td>
          <td><input type="text" class="form-control form-control-sm" placeholder="Name / Role" /></td>
          <td><input type="date" class="form-control form-control-sm" /></td>
        </tr>`;
    }

    function renderTable() {
      const tbody = document.getElementById('riskTableBody');
      tbody.innerHTML = defaultRisks.map(buildRow).join('');
      attachRowListeners();
      updateSummary();
    }

    function attachRowListeners() {
      document.querySelectorAll('#riskTableBody tr').forEach(row => {
        const lSel = row.querySelector('.likelihood-sel');
        const iSel = row.querySelector('.impact-sel');
        const scoreCell = row.querySelector('.score-cell');
        const levelCell = row.querySelector('.level-cell');

        function recalc() {
          const l = parseInt(lSel.value, 10);
          const i = parseInt(iSel.value, 10);
          const score = l * i;
          const rl = getRiskLevel(score);
          scoreCell.textContent = score;
          levelCell.innerHTML = `<span class="${rl.cls}">${rl.label}</span>`;
          updateSummary();
        }

        lSel.addEventListener('change', recalc);
        iSel.addEventListener('change', recalc);
      });
    }

    function updateSummary() {
      let counts = { Critical: 0, High: 0, Medium: 0, Low: 0, total: 0 };
      document.querySelectorAll('#riskTableBody .score-cell').forEach(cell => {
        const score = parseInt(cell.textContent, 10);
        const rl = getRiskLevel(score);
        counts[rl.label]++;
        counts.total++;
      });
      document.getElementById('count-critical').textContent = counts.Critical;
      document.getElementById('count-high').textContent = counts.High;
      document.getElementById('count-medium').textContent = counts.Medium;
      document.getElementById('count-low').textContent = counts.Low;
      document.getElementById('count-total').textContent = counts.total;
    }

    // Treatment table
    function buildTreatmentRow() {
      return `<tr>
        <td><input type="text" class="form-control form-control-sm" placeholder="e.g., R-001" /></td>
        <td><textarea class="form-control form-control-sm" rows="2" placeholder="Describe treatment action..."></textarea></td>
        <td><textarea class="form-control form-control-sm" rows="2" placeholder="Describe compensating controls..."></textarea></td>
        <td><input type="date" class="form-control form-control-sm" /></td>
        <td>
          <select class="form-select form-select-sm">
            <option>Not Started</option>
            <option>In Progress</option>
            <option>Completed</option>
            <option>Accepted</option>
          </select>
        </td>
        <td class="text-center no-print"><button class="btn btn-sm btn-outline-danger remove-treatment-row" title="Remove row">&times;</button></td>
      </tr>`;
    }

    document.getElementById('addTreatmentRow').addEventListener('click', function() {
      document.getElementById('treatmentBody').insertAdjacentHTML('beforeend', buildTreatmentRow());
    });

    document.getElementById('treatmentBody').addEventListener('click', function(e) {
      if (e.target.classList.contains('remove-treatment-row')) {
        const row = e.target.closest('tr');
        if (document.querySelectorAll('#treatmentBody tr').length > 1) row.remove();
      }
    });

    // Init
    renderTable();
  })();

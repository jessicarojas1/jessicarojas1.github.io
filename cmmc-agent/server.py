#!/usr/bin/env python3
"""CMMC 2.0 Level 2 Compliance Agent — Web GUI (Flask)"""

import os, json, datetime, sys
from pathlib import Path
from flask import Flask, request, jsonify, Response
from dotenv import load_dotenv
import anthropic

load_dotenv()

# ── Import shared data & tools from agent.py ─────────────────────────────────
sys.path.insert(0, str(Path(__file__).parent))
from agent import (CONTROLS, DOMAIN_NAMES, TOOLS, SYSTEM_PROMPT, STATUS_FILE,
                   load_status, save_status,
                   tool_check_control, tool_list_gaps, tool_score_program,
                   tool_generate_poam, tool_mark_control,
                   tool_search_controls, tool_list_domains)

app = Flask(__name__)

# ── Routes ────────────────────────────────────────────────────────────────────
@app.get("/")
def index():
    return Response(UI_HTML, mimetype="text/html")

@app.post("/api/chat")
def chat():
    data     = request.get_json(force=True)
    history  = data.get("history", [])   # [{role, content}]
    status   = load_status()
    api_key  = os.environ.get("ANTHROPIC_API_KEY")
    if not api_key:
        return jsonify({"error": "ANTHROPIC_API_KEY not set"}), 500

    client   = anthropic.Anthropic(api_key=api_key)
    messages = list(history)
    tool_log = []

    while True:
        response = client.messages.create(
            model="claude-opus-4-5",
            max_tokens=4096,
            system=SYSTEM_PROMPT,
            tools=TOOLS,
            messages=messages,
        )
        messages.append({"role": "assistant", "content": response.content})

        if response.stop_reason == "end_turn":
            reply = " ".join(b.text for b in response.content if hasattr(b,"text"))
            return jsonify({"reply": reply, "tool_log": tool_log})

        if response.stop_reason == "tool_use":
            tool_results = []
            for block in response.content:
                if block.type != "tool_use":
                    continue
                name, inputs = block.name, block.input
                tool_log.append({"tool": name, "inputs": inputs})

                if name == "check_control":
                    result = tool_check_control(inputs["control_id"], status)
                elif name == "list_gaps":
                    result = tool_list_gaps(inputs["domain"], status)
                elif name == "score_program":
                    result = tool_score_program(status)
                elif name == "generate_poam":
                    result = tool_generate_poam(inputs["control_id"], inputs["weakness"], status)
                elif name == "mark_control":
                    result = tool_mark_control(inputs["control_id"], inputs["impl_status"], inputs["notes"], status)
                elif name == "search_controls":
                    result = tool_search_controls(inputs["query"])
                elif name == "list_domains":
                    result = tool_list_domains()
                else:
                    result = f"Unknown tool: {name}"

                tool_results.append({"type":"tool_result","tool_use_id":block.id,"content":result})

            messages.append({"role":"user","content":tool_results})
        else:
            break

    return jsonify({"reply": "Unexpected response from agent.", "tool_log": tool_log})


@app.get("/api/dashboard")
def dashboard():
    status = load_status()
    raw = json.loads(tool_score_program(status))
    return jsonify(raw)


@app.post("/api/mark")
def mark():
    data = request.get_json(force=True)
    status = load_status()
    result = tool_mark_control(
        data.get("control_id",""),
        data.get("impl_status","not_assessed"),
        data.get("notes",""),
        status,
    )
    return jsonify({"message": result})


# ── Embedded UI ───────────────────────────────────────────────────────────────
UI_HTML = r"""<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>CMMC 2.0 Compliance Agent</title>
<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
  integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
  :root { --accent:#0dcaf0; --accent2:#0d6efd; --chat-bg:#0d1117; }
  body  { background:#0d1117; color:#e6edf3; font-family:'Segoe UI',system-ui,sans-serif; height:100vh; overflow:hidden; }
  /* Layout */
  .app  { display:flex; height:100vh; }
  .sidebar { width:290px; min-width:230px; background:#161b22; border-right:1px solid #30363d;
             display:flex; flex-direction:column; overflow:hidden; }
  .main    { flex:1; display:flex; flex-direction:column; overflow:hidden; }
  /* Header */
  .app-header { background:#161b22; border-bottom:1px solid #30363d; padding:.65rem 1rem;
                display:flex; align-items:center; gap:.75rem; }
  .app-header .title { font-weight:700; font-size:1rem; letter-spacing:.01em; }
  .app-header .subtitle { font-size:.72rem; color:#8b949e; }
  /* Score ring */
  .score-wrap  { padding:1.1rem 1rem .5rem; border-bottom:1px solid #30363d; text-align:center; }
  .score-ring  { position:relative; width:90px; height:90px; margin:auto auto .5rem; }
  .score-ring svg { transform:rotate(-90deg); }
  .score-text  { position:absolute; inset:0; display:flex; align-items:center;
                 justify-content:center; font-weight:700; font-size:1.25rem; }
  .score-label { font-size:.7rem; color:#8b949e; text-transform:uppercase; letter-spacing:.05em; }
  /* Domain list */
  .domain-list { flex:1; overflow-y:auto; padding:.5rem .6rem; }
  .domain-item { padding:.35rem .5rem; border-radius:.35rem; margin-bottom:2px;
                 font-size:.75rem; cursor:default; }
  .domain-item:hover { background:#21262d; }
  .domain-bar  { height:4px; border-radius:2px; background:#21262d; margin-top:3px; }
  .domain-bar-fill { height:100%; border-radius:2px; background:var(--accent2);
                     transition:width .4s ease; }
  .domain-code { font-weight:700; font-size:.7rem; color:var(--accent); width:26px; flex-shrink:0; }
  .domain-score { font-size:.7rem; color:#8b949e; white-space:nowrap; margin-left:auto; }
  /* Chat */
  .chat-area { flex:1; overflow-y:auto; padding:1rem 1.25rem; display:flex; flex-direction:column; gap:.75rem; }
  .bubble { max-width:82%; border-radius:.6rem; padding:.6rem .85rem; font-size:.875rem;
            line-height:1.6; word-break:break-word; }
  .bubble.user  { background:#1c2d4a; border:1px solid #264878; margin-left:auto;
                  border-bottom-right-radius:.1rem; }
  .bubble.agent { background:#161b22; border:1px solid #30363d;
                  border-bottom-left-radius:.1rem; }
  .bubble.agent pre { background:#0d1117; border:1px solid #30363d; border-radius:.4rem;
                      padding:.65rem; font-size:.75rem; overflow-x:auto; margin:.5rem 0 0; }
  .bubble.agent code { background:#0d1117; border-radius:.2rem; padding:.1rem .25rem; font-size:.8em; }
  .bubble.agent pre code { background:none; padding:0; }
  .bubble.thinking { background:#21262d; border-color:#388bfd; color:#8b949e;
                     font-size:.78rem; font-style:italic; }
  .tool-pill { display:inline-block; font-size:.65rem; background:#21262d; border:1px solid #30363d;
               border-radius:10rem; padding:.1rem .45rem; margin:.15rem .1rem; color:var(--accent); }
  /* Input bar */
  .input-bar { background:#161b22; border-top:1px solid #30363d; padding:.75rem 1rem;
               display:flex; gap:.5rem; align-items:flex-end; }
  .input-bar textarea { flex:1; background:#0d1117; border:1px solid #30363d; color:#e6edf3;
                        border-radius:.4rem; padding:.5rem .75rem; font-size:.875rem;
                        resize:none; min-height:42px; max-height:120px; outline:none;
                        transition:border-color .15s; font-family:inherit; }
  .input-bar textarea:focus { border-color:var(--accent2); }
  .send-btn { background:var(--accent2); border:none; color:#fff; border-radius:.4rem;
              padding:.5rem .9rem; cursor:pointer; font-size:.875rem; transition:background .15s;
              height:42px; white-space:nowrap; }
  .send-btn:hover:not(:disabled) { background:#0b5ed7; }
  .send-btn:disabled { opacity:.5; cursor:not-allowed; }
  .sidebar-footer { padding:.5rem .6rem; border-top:1px solid #30363d; }
  /* Scrollbar */
  ::-webkit-scrollbar { width:5px; }
  ::-webkit-scrollbar-track { background:transparent; }
  ::-webkit-scrollbar-thumb { background:#30363d; border-radius:3px; }
  /* Welcome */
  .welcome { text-align:center; margin:auto; padding:2rem; max-width:420px; color:#8b949e; }
  .welcome h2 { color:#e6edf3; font-size:1.1rem; margin-bottom:.5rem; }
  .prompt-chip { display:inline-block; background:#21262d; border:1px solid #30363d; border-radius:.35rem;
                 padding:.3rem .6rem; font-size:.75rem; cursor:pointer; margin:.2rem;
                 transition:border-color .15s, color .15s; text-align:left; }
  .prompt-chip:hover { border-color:var(--accent2); color:#e6edf3; }
  /* Refresh btn */
  .refresh-btn { background:none; border:none; color:#8b949e; cursor:pointer; padding:.2rem .4rem;
                 border-radius:.3rem; font-size:.8rem; }
  .refresh-btn:hover { color:var(--accent); background:#21262d; }
</style>
</head>
<body>
<div class="app">

  <!-- Sidebar -->
  <div class="sidebar">
    <div class="app-header" style="border-bottom:1px solid #30363d">
      <i class="bi bi-shield-check" style="color:var(--accent);font-size:1.1rem"></i>
      <div>
        <div class="title" style="font-size:.85rem">Program Score</div>
      </div>
      <button class="refresh-btn ms-auto" id="refreshBtn" title="Refresh scores"><i class="bi bi-arrow-clockwise"></i></button>
    </div>
    <div class="score-wrap">
      <div class="score-ring">
        <svg viewBox="0 0 90 90" width="90" height="90">
          <circle cx="45" cy="45" r="38" fill="none" stroke="#21262d" stroke-width="9"/>
          <circle id="scoreArc" cx="45" cy="45" r="38" fill="none" stroke="var(--accent2)" stroke-width="9"
                  stroke-dasharray="0 239" stroke-linecap="round" style="transition:stroke-dasharray .6s ease"/>
        </svg>
        <div class="score-text" id="scoreText">—</div>
      </div>
      <div class="score-label">Overall Compliance</div>
      <div class="d-flex justify-content-center gap-3 mt-2" style="font-size:.7rem;color:#8b949e">
        <span id="implCount">— impl</span>
        <span id="gapCount">— gaps</span>
      </div>
    </div>
    <div class="domain-list" id="domainList">
      <div class="text-center py-3" style="color:#8b949e;font-size:.75rem">Loading…</div>
    </div>
    <div class="sidebar-footer">
      <div style="font-size:.65rem;color:#8b949e;text-align:center">CMMC 2.0 Level 2 · 110 Practices</div>
    </div>
  </div>

  <!-- Main -->
  <div class="main">
    <!-- Header -->
    <div class="app-header">
      <i class="bi bi-robot" style="color:var(--accent2);font-size:1.2rem"></i>
      <div>
        <div class="title">CMMC 2.0 Compliance Agent</div>
        <div class="subtitle">Powered by Claude · NIST 800-171 · All 110 practices</div>
      </div>
      <div class="ms-auto d-flex gap-2">
        <button class="btn btn-sm btn-outline-secondary" id="clearBtn" title="Clear conversation"
                style="font-size:.75rem;padding:.25rem .55rem">
          <i class="bi bi-trash"></i>
        </button>
        <button class="btn btn-sm" id="helpBtn" title="How to use this agent"
                style="background:var(--accent2);color:#fff;font-size:.75rem;padding:.25rem .65rem">
          <i class="bi bi-question-circle"></i> Help
        </button>
      </div>
    </div>

    <!-- Chat -->
    <div class="chat-area" id="chatArea">
      <div class="welcome" id="welcome">
        <i class="bi bi-shield-shaded" style="font-size:2.5rem;color:var(--accent2);display:block;margin-bottom:.75rem"></i>
        <h2>CMMC Compliance Agent</h2>
        <p style="font-size:.82rem;margin-bottom:1rem">Ask anything about your CMMC 2.0 Level 2 compliance posture. Try a prompt below or type your own.</p>
        <div id="chips"></div>
      </div>
    </div>

    <!-- Input -->
    <div class="input-bar">
      <textarea id="msgInput" placeholder="Ask about your compliance posture…" rows="1"
                onkeydown="handleKey(event)"></textarea>
      <button class="send-btn" id="sendBtn" onclick="sendMessage()">
        <i class="bi bi-send-fill"></i> Send
      </button>
    </div>
  </div>
</div>

<!-- Help / Instruction Modal -->
<div class="modal fade" id="helpModal" tabindex="-1" aria-modal="true">
  <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
    <div class="modal-content" style="background:#161b22;border:1px solid #30363d">
      <div class="modal-header" style="border-color:#30363d">
        <h5 class="modal-title"><i class="bi bi-question-circle me-2" style="color:var(--accent)"></i>How to Use the CMMC Compliance Agent</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" style="font-size:.875rem">

        <h6 class="fw-bold mb-2" style="color:var(--accent)">What This Agent Does</h6>
        <p>This is an AI agent powered by Claude that helps you assess and improve your CMMC 2.0 Level 2 compliance posture across all 110 NIST 800-171 practices. It uses tool calls to look up real control data, track your implementation status, and generate actionable outputs.</p>

        <h6 class="fw-bold mt-3 mb-2" style="color:var(--accent)">Example Prompts</h6>
        <table class="table table-sm table-dark" style="font-size:.8rem;border-color:#30363d">
          <thead><tr><th>Prompt</th><th>What It Does</th></tr></thead>
          <tbody>
            <tr><td><code>score my program</code></td><td>Shows overall % and per-domain breakdown</td></tr>
            <tr><td><code>what are my gaps in AC</code></td><td>Lists unimplemented Access Control practices</td></tr>
            <tr><td><code>check control 3.5.3</code></td><td>Shows MFA requirement text + your status</td></tr>
            <tr><td><code>search controls for encryption</code></td><td>Finds all encryption-related practices</td></tr>
            <tr><td><code>mark 3.5.3 as implemented — using Okta FIDO2</code></td><td>Records your implementation with notes</td></tr>
            <tr><td><code>generate a POA&M for 3.13.8</code></td><td>Creates a structured Plan of Action</td></tr>
            <tr><td><code>list all domains</code></td><td>Shows all 14 CMMC domains + practice counts</td></tr>
            <tr><td><code>what are the highest-risk gaps</code></td><td>Prioritised gap analysis</td></tr>
            <tr><td><code>show all gaps across every domain</code></td><td>Full program gap report</td></tr>
          </tbody>
        </table>

        <h6 class="fw-bold mt-3 mb-2" style="color:var(--accent)">Agent Tools</h6>
        <div class="row g-2">
          <div class="col-md-6"><div class="p-2 rounded" style="background:#21262d;border:1px solid #30363d">
            <div class="fw-semibold" style="font-size:.78rem;color:var(--accent)">check_control</div>
            <div style="font-size:.72rem;color:#8b949e">Look up any control by ID (e.g. 3.5.3, 3.13.8)</div>
          </div></div>
          <div class="col-md-6"><div class="p-2 rounded" style="background:#21262d;border:1px solid #30363d">
            <div class="fw-semibold" style="font-size:.78rem;color:var(--accent)">list_gaps</div>
            <div style="font-size:.72rem;color:#8b949e">Find unimplemented controls by domain or ALL</div>
          </div></div>
          <div class="col-md-6"><div class="p-2 rounded" style="background:#21262d;border:1px solid #30363d">
            <div class="fw-semibold" style="font-size:.78rem;color:var(--accent)">score_program</div>
            <div style="font-size:.72rem;color:#8b949e">Calculate overall and per-domain compliance scores</div>
          </div></div>
          <div class="col-md-6"><div class="p-2 rounded" style="background:#21262d;border:1px solid #30363d">
            <div class="fw-semibold" style="font-size:.78rem;color:var(--accent)">generate_poam</div>
            <div style="font-size:.72rem;color:#8b949e">Build a POA&amp;M entry with milestones for a gap</div>
          </div></div>
          <div class="col-md-6"><div class="p-2 rounded" style="background:#21262d;border:1px solid #30363d">
            <div class="fw-semibold" style="font-size:.78rem;color:var(--accent)">mark_control</div>
            <div style="font-size:.72rem;color:#8b949e">Record implementation status + evidence notes</div>
          </div></div>
          <div class="col-md-6"><div class="p-2 rounded" style="background:#21262d;border:1px solid #30363d">
            <div class="fw-semibold" style="font-size:.78rem;color:var(--accent)">search_controls</div>
            <div style="font-size:.72rem;color:#8b949e">Search by keyword: encryption, MFA, audit, CUI…</div>
          </div></div>
        </div>

        <h6 class="fw-bold mt-3 mb-2" style="color:var(--accent)">Status Values</h6>
        <div class="d-flex gap-2 flex-wrap">
          <span class="badge" style="background:#198754">implemented</span>
          <span class="badge" style="background:#ffc107;color:#000">partial</span>
          <span class="badge" style="background:#dc3545">not_implemented</span>
          <span class="badge" style="background:#6c757d">not_assessed</span>
        </div>
        <p class="mt-2" style="font-size:.78rem;color:#8b949e">Status is saved to <code>status.json</code> and persists between sessions. The sidebar score updates automatically after each conversation turn.</p>

        <h6 class="fw-bold mt-3 mb-2" style="color:var(--accent)">Scoring Guide</h6>
        <table class="table table-sm table-dark" style="font-size:.8rem;border-color:#30363d">
          <tbody>
            <tr><td><span class="badge bg-success">90–100%</span></td><td>Assessment-ready</td></tr>
            <tr><td><span class="badge bg-primary">70–89%</span></td><td>Manageable gaps, POA&amp;Ms sufficient</td></tr>
            <tr><td><span class="badge bg-warning text-dark">50–69%</span></td><td>Significant remediation needed</td></tr>
            <tr><td><span class="badge bg-danger">&lt; 50%</span></td><td>High risk — major program gaps</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
  integrity="sha384-YvpcrYf0tY3lHB60NNkmXc4s9bIOgUxi8T/jzmR6yxBvFf/AQgDLRFrS5dQ1LAv" crossorigin="anonymous"></script>
<script>
const SAMPLE_PROMPTS = [
  "Score my program",
  "What are my gaps in the IA domain?",
  "Check control 3.5.3",
  "Search controls for encryption",
  "List all 14 domains",
  "What are the highest-risk gaps?",
  "Generate a POA&M for 3.13.8 — CUI transmitted over unencrypted links",
  "Mark 3.5.3 as implemented — using Okta MFA with FIDO2",
];

let conversationHistory = [];

// Render prompt chips
const chips = document.getElementById('chips');
SAMPLE_PROMPTS.forEach(p => {
  const btn = document.createElement('button');
  btn.className = 'prompt-chip';
  btn.textContent = p;
  btn.onclick = () => { document.getElementById('msgInput').value = p; sendMessage(); };
  chips.appendChild(btn);
});

// Help modal
document.getElementById('helpBtn').addEventListener('click', () => {
  new bootstrap.Modal(document.getElementById('helpModal')).show();
});

// Clear
document.getElementById('clearBtn').addEventListener('click', () => {
  conversationHistory = [];
  const chat = document.getElementById('chatArea');
  chat.innerHTML = '';
  chat.appendChild(document.getElementById('welcome') || (() => {
    const w = document.createElement('div');
    w.id = 'welcome'; w.className = 'welcome';
    w.innerHTML = document.querySelector('.welcome')?.innerHTML || '';
    return w;
  })());
});

function handleKey(e) {
  if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
}

function autoResize(el) {
  el.style.height = 'auto';
  el.style.height = Math.min(el.scrollHeight, 120) + 'px';
}
document.getElementById('msgInput').addEventListener('input', function() { autoResize(this); });

function appendBubble(role, html, isHtml=false) {
  const welcome = document.getElementById('welcome');
  if (welcome) welcome.remove();
  const chat = document.getElementById('chatArea');
  const div = document.createElement('div');
  div.className = `bubble ${role}`;
  if (isHtml) div.innerHTML = html; else div.textContent = html;
  chat.appendChild(div);
  chat.scrollTop = chat.scrollHeight;
  return div;
}

function formatReply(text) {
  // Code blocks
  text = text.replace(/```(\w*)\n?([\s\S]*?)```/g, (_,lang,code) =>
    `<pre><code>${escHtml(code.trim())}</code></pre>`);
  // Inline code
  text = text.replace(/`([^`]+)`/g, (_,c) => `<code>${escHtml(c)}</code>`);
  // Bold
  text = text.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
  // Line breaks
  text = text.replace(/\n/g, '<br>');
  return text;
}

function escHtml(s) {
  return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

async function sendMessage() {
  const input = document.getElementById('msgInput');
  const msg = input.value.trim();
  if (!msg) return;

  input.value = '';
  input.style.height = 'auto';
  document.getElementById('sendBtn').disabled = true;

  appendBubble('user', msg);
  conversationHistory.push({role:'user', content: msg});

  const thinkBubble = appendBubble('agent thinking', '⏳ Thinking…');

  try {
    const res = await fetch('/api/chat', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({history: conversationHistory}),
    });
    const data = await res.json();

    thinkBubble.remove();

    if (data.error) {
      appendBubble('agent', `⚠️ Error: ${data.error}`);
    } else {
      // Show tool calls
      if (data.tool_log?.length) {
        const tools = document.createElement('div');
        tools.className = 'bubble agent';
        tools.style.cssText = 'font-size:.72rem;color:#8b949e;padding:.4rem .7rem';
        tools.innerHTML = data.tool_log.map(t =>
          `<span class="tool-pill"><i class="bi bi-gear-fill"></i> ${t.tool}(${Object.values(t.inputs).slice(0,1).map(v=>JSON.stringify(v)).join(',')})</span>`
        ).join('');
        document.getElementById('chatArea').appendChild(tools);
      }
      // Agent reply
      const bubble = appendBubble('agent', formatReply(data.reply), true);
      conversationHistory.push({role:'assistant', content: data.reply});
      // Refresh score after each turn
      loadDashboard();
    }
  } catch(e) {
    thinkBubble.remove();
    appendBubble('agent', `⚠️ Request failed: ${e.message}`);
  }
  document.getElementById('sendBtn').disabled = false;
  document.getElementById('msgInput').focus();
}

// Dashboard / sidebar
async function loadDashboard() {
  try {
    const res = await fetch('/api/dashboard');
    const data = await res.json();
    const score = data.overall_score_pct || 0;

    // Score ring
    const circ = 2 * Math.PI * 38;
    const arc  = (score / 100) * circ;
    document.getElementById('scoreArc').setAttribute('stroke-dasharray', `${arc} ${circ - arc}`);
    const color = score >= 90 ? '#198754' : score >= 70 ? '#0d6efd' : score >= 50 ? '#ffc107' : '#dc3545';
    document.getElementById('scoreArc').setAttribute('stroke', color);
    document.getElementById('scoreText').textContent = score + '%';

    // Counts
    let impl = 0, gap = 0;
    Object.values(data.domains || {}).forEach(d => { impl += d.implemented; gap += (d.total - d.implemented - d.partial); });
    document.getElementById('implCount').textContent = impl + ' impl';
    document.getElementById('gapCount').textContent  = gap  + ' gaps';

    // Domain list
    const list = document.getElementById('domainList');
    list.innerHTML = Object.entries(data.domains || {}).map(([code, d]) => `
      <div class="domain-item d-flex align-items-center gap-1">
        <span class="domain-code">${code}</span>
        <div class="flex-grow-1">
          <div class="d-flex justify-content-between" style="font-size:.7rem">
            <span style="color:#c9d1d9">${d.domain.replace('System & ','')}</span>
            <span class="domain-score">${d.implemented}/${d.total}</span>
          </div>
          <div class="domain-bar">
            <div class="domain-bar-fill" style="width:${d.score_pct}%;background:${d.score_pct>=80?'#198754':d.score_pct>=50?'#0d6efd':'#dc3545'}"></div>
          </div>
        </div>
      </div>`).join('');
  } catch(e) {
    document.getElementById('domainList').innerHTML =
      `<div class="text-center py-3" style="color:#8b949e;font-size:.72rem">⚠️ Could not load scores</div>`;
  }
}

document.getElementById('refreshBtn').addEventListener('click', loadDashboard);
loadDashboard();
</script>
</body>
</html>"""

if __name__ == "__main__":
    port = int(os.environ.get("PORT", 5050))
    host = "0.0.0.0"   # required for Render / Railway / Fly.io
    print(f"\n  CMMC Agent GUI → http://localhost:{port}\n")
    app.run(debug=False, host=host, port=port)

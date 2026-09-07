/* MERIDIAN — LLM providers (Anthropic Claude + OpenAI).
 * Runs the analyst system prompt against the user's chosen provider, with an
 * optional live web-search tool so the brief reflects current open-source
 * reporting. The API key is read from local settings and sent directly to the
 * provider over HTTPS; MERIDIAN has no server and stores nothing remotely.
 *
 * Robustness: if a request fails specifically because the web-search tool is not
 * available for the account/model, it retries once WITHOUT the tool and flags
 * that live search was unavailable (so the brief is honestly marked lower
 * confidence rather than failing outright).
 * window.MERIDIAN.llm
 */
(function (root) {
  'use strict';
  const M = root.MERIDIAN = root.MERIDIAN || {};
  const store = M.store, prompt = M.prompt;

  const ANTHROPIC_URL = 'https://api.anthropic.com/v1/messages';
  const OPENAI_URL = 'https://api.openai.com/v1/responses';
  const ANTHROPIC_WEB_TOOL = { type: 'web_search_20250305', name: 'web_search', max_uses: 8 };

  function looksLikeToolError(status, text) {
    if (status !== 400 && status !== 404 && status !== 422) return false;
    return /tool|web_search|not\s*supported|unknown|unsupported|invalid.*tool/i.test(text || '');
  }

  // Pull a JSON object out of model text that may include fences or prose.
  function extractJSON(text) {
    if (!text) throw new Error('Empty model response.');
    let t = String(text).trim();
    // strip ```json ... ``` fences if present
    const fence = t.match(/```(?:json)?\s*([\s\S]*?)```/i);
    if (fence) t = fence[1].trim();
    // fast path
    try { return JSON.parse(t); } catch (e) { /* fall through */ }
    // balanced-object scan from first { to matching }
    const start = t.indexOf('{');
    if (start === -1) throw new Error('No JSON object found in model response.');
    let depth = 0, inStr = false, esc = false;
    for (let i = start; i < t.length; i++) {
      const c = t[i];
      if (inStr) {
        if (esc) esc = false;
        else if (c === '\\') esc = true;
        else if (c === '"') inStr = false;
      } else {
        if (c === '"') inStr = true;
        else if (c === '{') depth++;
        else if (c === '}') { depth--; if (depth === 0) { return JSON.parse(t.slice(start, i + 1)); } }
      }
    }
    throw new Error('Could not parse JSON from model response.');
  }

  async function callAnthropic(sys, user, engine, useTool) {
    const body = {
      model: engine.model || store.DEFAULT_MODELS.anthropic,
      max_tokens: 8000,
      system: sys,
      messages: [{ role: 'user', content: user }]
    };
    if (useTool) body.tools = [ANTHROPIC_WEB_TOOL];

    const res = await fetch(ANTHROPIC_URL, {
      method: 'POST',
      headers: {
        'content-type': 'application/json',
        'x-api-key': engine.apiKey,
        'anthropic-version': '2023-06-01',
        'anthropic-dangerous-direct-browser-access': 'true'
      },
      body: JSON.stringify(body)
    });
    const raw = await res.text();
    if (!res.ok) { const err = new Error(raw || ('Anthropic HTTP ' + res.status)); err.status = res.status; err.body = raw; throw err; }
    const data = JSON.parse(raw);
    const text = (data.content || []).filter(b => b.type === 'text').map(b => b.text).join('\n').trim();
    if (!text) throw new Error('Anthropic returned no text content.');
    return text;
  }

  async function callOpenAI(sys, user, engine, useTool) {
    const body = {
      model: engine.model || store.DEFAULT_MODELS.openai,
      instructions: sys,
      input: user,
      max_output_tokens: 8000
    };
    if (useTool) { body.tools = [{ type: 'web_search' }]; body.tool_choice = 'auto'; }

    const res = await fetch(OPENAI_URL, {
      method: 'POST',
      headers: { 'content-type': 'application/json', 'Authorization': 'Bearer ' + engine.apiKey },
      body: JSON.stringify(body)
    });
    const raw = await res.text();
    if (!res.ok) { const err = new Error(raw || ('OpenAI HTTP ' + res.status)); err.status = res.status; err.body = raw; throw err; }
    const data = JSON.parse(raw);
    // Responses API: prefer output_text convenience; else concatenate text parts.
    let text = data.output_text;
    if (!text && Array.isArray(data.output)) {
      text = data.output
        .flatMap(o => (o.content || []))
        .filter(c => c.type === 'output_text' || c.type === 'text')
        .map(c => c.text).join('\n');
    }
    text = (text || '').trim();
    if (!text) throw new Error('OpenAI returned no text content.');
    return text;
  }

  async function callProvider(sys, user, engine, wantTool, onProgress) {
    const call = engine.provider === 'openai' ? callOpenAI : callAnthropic;
    try {
      return { text: await call(sys, user, engine, wantTool), toolUsed: wantTool };
    } catch (e) {
      if (wantTool && looksLikeToolError(e.status, e.body)) {
        if (onProgress) onProgress('Web-search tool unavailable — retrying without it…', 55);
        return { text: await call(sys, user, engine, false), toolUsed: false, toolDegraded: true };
      }
      throw e;
    }
  }

  function friendlyError(e, provider) {
    const s = e && e.status;
    const b = (e && e.body) || (e && e.message) || '';
    if (s === 401 || s === 403) return 'Authentication failed — check the API key for ' + provider + '.';
    if (s === 429) return 'Rate limited or out of quota (' + provider + '). Wait and retry.';
    if (s === 400 && /credit|balance|billing/i.test(b)) return 'Billing/credit issue on the ' + provider + ' account.';
    if (/Failed to fetch|NetworkError|CORS/i.test(b)) return 'Network/CORS error reaching ' + provider + '. Check connectivity and that direct browser access is permitted.';
    return (b && b.length < 400 ? b : (e && e.message)) || 'Unknown error.';
  }

  // Build a compact intelligence-thread context from the most recent prior brief
  // (its watchboard + top-3), so client-side generation carries continuity.
  function buildThreadContext(dateStr) {
    try {
      const prior = store.listBriefs().filter(b => b && b.date && b.date !== dateStr)[0];
      if (!prior) return '';
      const lines = [];
      (prior.watchboard || prior.watchlist || []).forEach(w => {
        lines.push('- THREAD: ' + (w.issue || w.development || '') + ' | status: ' + (w.status || '') + ' | direction: ' + (w.direction || '') + ' | next indicator: ' + (w.nextIndicator || w.watchNext || ''));
      });
      (prior.forecastReview || []).forEach(f => { if (f && f.priorForecast) lines.push('- PRIOR FORECAST (' + (f.date || prior.date) + '): ' + f.priorForecast + ' [outcome so far: ' + (f.outcome || 'PENDING') + ']'); });
      if (!lines.length) return '';
      return 'From the ' + prior.date + ' brief:\n' + lines.join('\n');
    } catch (e) { return ''; }
  }

  // Generate a full brief object for dateStr. Returns { brief, meta }.
  async function generate(dateStr, onProgress) {
    const engine = store.getEngine();
    if (!engine.apiKey) { const err = new Error('No API key configured. Add one in Settings.'); err.userFacing = true; throw err; }
    const profile = store.getProfile();
    const material = store.getMaterial();
    const sys = prompt.buildSystemPrompt(profile);
    const user = prompt.buildUserPrompt(dateStr, material, buildThreadContext(dateStr));

    if (onProgress) onProgress(engine.webSearch ? 'Researching open sources…' : 'Composing brief…', 25);
    let result;
    try {
      result = await callProvider(sys, user, engine, !!engine.webSearch, onProgress);
    } catch (e) {
      const err = new Error(friendlyError(e, engine.provider)); err.userFacing = true; throw err;
    }

    if (onProgress) onProgress('Structuring the brief…', 80);
    let brief;
    try { brief = extractJSON(result.text); }
    catch (e) { const err = new Error('The model did not return valid JSON. ' + e.message); err.userFacing = true; err.rawText = result.text; throw err; }

    brief = M.render.normalize(brief);
    brief.date = brief.date || dateStr;
    brief.meta = {
      generatedBy: engine.provider,
      model: engine.model || store.DEFAULT_MODELS[engine.provider],
      generatedAt: new Date().toISOString(),
      webSearch: result.toolUsed,
      webSearchDegraded: !!result.toolDegraded
    };
    return brief;
  }

  // Lightweight connection test.
  async function test() {
    const engine = store.getEngine();
    if (!engine.apiKey) throw new Error('No API key set.');
    const sys = 'Reply with the single word OK.';
    const user = 'ping';
    const call = engine.provider === 'openai' ? callOpenAI : callAnthropic;
    const text = await call(sys, user, Object.assign({}, engine), false);
    return text.trim().slice(0, 40);
  }

  M.llm = { generate, test, extractJSON, friendlyError };
})(window);

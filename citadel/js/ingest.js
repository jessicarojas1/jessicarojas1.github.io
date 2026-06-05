/* CITADEL — Ingest Engine
 * Normalizes dropped/selected files into a flat list of entries. Transparently
 * expands ZIP archives (via JSZip) and classifies text vs binary content.
 * window.CITADEL.ingest
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL = root.CITADEL || {};

  const TEXT_EXT = new Set(Object.keys(CITADEL.lang.EXT).concat(
    ['txt', 'cfg', 'conf', 'env', 'lock', 'gradle', 'properties', 'gitignore', 'dockerignore', 'editorconfig', 'csproj', 'sln']
  ));
  const SKIP_DIR = /(^|\/)(node_modules|\.git|vendor|dist|build|\.venv|venv|__pycache__|\.next|target|bin|obj)\//i;
  const MAX_TEXT = 2 * 1024 * 1024; // 2 MB per text file

  function isProbablyText(name, bytes) {
    const ext = name.split('.').pop().toLowerCase();
    if (TEXT_EXT.has(ext)) return true;
    if (!ext && /(^|\/)(dockerfile|makefile|gemfile|rakefile|jenkinsfile|license|readme)$/i.test(name)) return true;
    // sniff: scan first 512 bytes for NUL / high control density
    const n = Math.min(bytes.length, 512);
    let ctrl = 0;
    for (let i = 0; i < n; i++) {
      const c = bytes[i];
      if (c === 0) return false;
      if (c < 0x09 || (c > 0x0D && c < 0x20)) ctrl++;
    }
    return ctrl / Math.max(1, n) < 0.1;
  }

  function decodeText(bytes) {
    try { return new TextDecoder('utf-8', { fatal: false }).decode(bytes); }
    catch (e) { return ''; }
  }

  async function expandZip(name, bytes, onProgress) {
    if (!root.JSZip) throw new Error('JSZip not loaded');
    const zip = await root.JSZip.loadAsync(bytes);
    const entries = [];
    const names = Object.keys(zip.files);
    let i = 0;
    for (const path of names) {
      const f = zip.files[path];
      i++;
      if (onProgress) onProgress(i, names.length, path);
      if (f.dir) continue;
      if (SKIP_DIR.test('/' + path)) continue;
      const data = await f.async('uint8array');
      entries.push(await toEntry(name + '!/' + path, data));
    }
    return entries;
  }

  async function toEntry(path, bytes) {
    const text = isProbablyText(path, bytes);
    const lang = CITADEL.lang.detect(path);
    const entry = {
      path, size: bytes.length, isBinary: !text, lang,
      content: null, bytes: null
    };
    if (text && bytes.length <= MAX_TEXT) entry.content = decodeText(bytes);
    else entry.bytes = bytes; // keep raw bytes for binary analysis
    return entry;
  }

  // Accepts an array of File objects, returns flat entry list (zips expanded).
  async function ingestFiles(files, onProgress) {
    const out = [];
    for (let i = 0; i < files.length; i++) {
      const file = files[i];
      const path = file.webkitRelativePath || file.name;
      const buf = new Uint8Array(await file.arrayBuffer());
      const lower = path.toLowerCase();
      if (lower.endsWith('.zip') || lower.endsWith('.jar') || lower.endsWith('.war') ||
          lower.endsWith('.apk') || lower.endsWith('.nupkg')) {
        try {
          const expanded = await expandZip(path, buf, onProgress);
          out.push(...expanded);
          // also keep the archive itself for binary/provenance view
          out.push(Object.assign(await toEntry(path, buf), { archive: true }));
        } catch (e) {
          out.push(await toEntry(path, buf));
        }
      } else {
        if (SKIP_DIR.test('/' + path)) continue;
        out.push(await toEntry(path, buf));
      }
      if (onProgress) onProgress(i + 1, files.length, path);
    }
    return out;
  }

  CITADEL.ingest = { ingestFiles, expandZip, isProbablyText };
})(window);

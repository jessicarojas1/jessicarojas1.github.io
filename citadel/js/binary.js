/* CITADEL — Binary / Executable Analyzer
 * Inspects uploaded binaries (PE / ELF / Mach-O / archives) from raw bytes:
 * format detection, Shannon entropy (packing indicator), printable-string
 * extraction, and suspicious-capability heuristics. window.CITADEL.binary
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL = root.CITADEL || {};

  function format(bytes) {
    const b = bytes;
    if (b.length < 4) return { format: 'Unknown', kind: 'unknown' };
    const u32 = (b[0] << 24) | (b[1] << 16) | (b[2] << 8) | b[3];
    // PE (MZ)
    if (b[0] === 0x4D && b[1] === 0x5A) return { format: 'PE / Windows executable (MZ)', kind: 'executable', platform: 'Windows' };
    // ELF
    if (b[0] === 0x7F && b[1] === 0x45 && b[2] === 0x4C && b[3] === 0x46) {
      const bits = b[4] === 2 ? '64-bit' : '32-bit';
      return { format: `ELF ${bits} (Linux/Unix)`, kind: 'executable', platform: 'Linux/Unix' };
    }
    // Mach-O (incl. fat)
    if ([0xFEEDFACE, 0xFEEDFACF, 0xCAFEBABE, 0xCFFAEDFE, 0xCEFAEDFE].includes(u32 >>> 0))
      return { format: 'Mach-O (macOS/iOS)', kind: 'executable', platform: 'macOS' };
    // Java class
    if (u32 >>> 0 === 0xCAFEBABE) return { format: 'Java class', kind: 'bytecode', platform: 'JVM' };
    // Archives
    if (b[0] === 0x50 && b[1] === 0x4B) return { format: 'ZIP / JAR / APK / Office', kind: 'archive' };
    if (b[0] === 0x1F && b[1] === 0x8B) return { format: 'GZIP archive', kind: 'archive' };
    if (b[0] === 0x42 && b[1] === 0x5A && b[2] === 0x68) return { format: 'BZIP2 archive', kind: 'archive' };
    if (b[0] === 0xFD && b[1] === 0x37 && b[2] === 0x7A) return { format: 'XZ archive', kind: 'archive' };
    if (b[0] === 0x52 && b[1] === 0x61 && b[2] === 0x72 && b[3] === 0x21) return { format: 'RAR archive', kind: 'archive' };
    if (b[0] === 0x25 && b[1] === 0x50 && b[2] === 0x44 && b[3] === 0x46) return { format: 'PDF document', kind: 'document' };
    if (b[0] === 0x23 && b[1] === 0x21) return { format: 'Script with shebang (#!)', kind: 'script' };
    return { format: 'Unknown / data', kind: 'unknown' };
  }

  function entropy(bytes, sampleMax) {
    const n = Math.min(bytes.length, sampleMax || 1 << 20);
    if (n === 0) return 0;
    const counts = new Uint32Array(256);
    for (let i = 0; i < n; i++) counts[bytes[i]]++;
    let h = 0;
    for (let i = 0; i < 256; i++) {
      if (!counts[i]) continue;
      const p = counts[i] / n;
      h -= p * Math.log2(p);
    }
    return Math.round(h * 1000) / 1000; // bits per byte (0..8)
  }

  function strings(bytes, minLen) {
    minLen = minLen || 6;
    const out = [];
    let cur = '';
    const n = Math.min(bytes.length, 1 << 20);
    for (let i = 0; i < n; i++) {
      const c = bytes[i];
      if (c >= 0x20 && c < 0x7F) { cur += String.fromCharCode(c); }
      else { if (cur.length >= minLen) out.push(cur); cur = ''; if (out.length > 4000) break; }
    }
    if (cur.length >= minLen) out.push(cur);
    return out;
  }

  // Suspicious capability indicators found in extracted strings.
  const INDICATORS = [
    { re: /\b(VirtualAllocEx|WriteProcessMemory|CreateRemoteThread|SetWindowsHookEx)\b/, label: 'Process injection / hooking API', severity: 'high' },
    { re: /\b(URLDownloadToFile|InternetOpenUrl|WinHttpConnect|socket|connect)\b/, label: 'Network / download capability', severity: 'medium' },
    { re: /\b(RegSetValueEx|RegCreateKeyEx|CurrentVersion\\\\Run)\b/, label: 'Registry persistence', severity: 'high' },
    { re: /\b(CryptEncrypt|BCryptEncrypt|AES_set_encrypt_key)\b/, label: 'Embedded cryptography', severity: 'low' },
    { re: /\b(IsDebuggerPresent|CheckRemoteDebuggerPresent|NtQueryInformationProcess)\b/, label: 'Anti-debugging / evasion', severity: 'high' },
    { re: /\b(cmd\.exe|powershell|\/bin\/sh|\/bin\/bash|system\()/i, label: 'Shell / command execution', severity: 'medium' },
    { re: /\b(keybd_event|GetAsyncKeyState|SetClipboardData)\b/, label: 'Keylogging / clipboard capture', severity: 'high' },
    { re: /(AKIA[0-9A-Z]{16}|-----BEGIN [A-Z ]*PRIVATE KEY-----)/, label: 'Embedded credential / key', severity: 'critical' },
    { re: /\b(ptrace|fork|execve|setuid|chmod \+x)\b/, label: 'Privilege / process manipulation (Unix)', severity: 'medium' },
    { re: /\.onion\b|tor2web|169\.254\.169\.254/, label: 'Anonymization / metadata endpoint reference', severity: 'high' }
  ];

  function analyze(name, bytes) {
    const fmt = format(bytes);
    const ent = entropy(bytes);
    const strs = strings(bytes);
    const joined = strs.join('\n');
    const indicators = [];
    INDICATORS.forEach(ind => { if (ind.re.test(joined)) indicators.push({ label: ind.label, severity: ind.severity }); });

    const findings = [];
    if (ent >= 7.2 && fmt.kind === 'executable') {
      findings.push({
        ruleId: 'bin-entropy', name: 'High entropy — likely packed/encrypted binary',
        category: 'malware', severity: 'medium', cwe: 'CWE-506', confidence: 'low',
        snippet: `Shannon entropy ${ent}/8.0`,
        remediation: 'Packed binaries resist analysis; obtain the unpacked artifact and verify provenance/signature.'
      });
    }
    indicators.forEach(ind => {
      findings.push({
        ruleId: 'bin-indicator', name: 'Suspicious capability: ' + ind.label,
        category: 'malware', severity: ind.severity, cwe: 'CWE-507', confidence: 'low',
        snippet: ind.label,
        remediation: 'Review the binary in a sandbox; confirm it is an authorized, signed artifact.'
      });
    });

    // Interesting strings to surface (URLs, IPs, paths)
    const urls = [...new Set(joined.match(/https?:\/\/[^\s"'<>]{4,80}/g) || [])].slice(0, 25);
    const ips = [...new Set(joined.match(/\b(?:\d{1,3}\.){3}\d{1,3}\b/g) || [])].slice(0, 25);

    return {
      name, format: fmt.format, kind: fmt.kind, platform: fmt.platform || '—',
      size: bytes.length, entropy: ent, stringCount: strs.length,
      indicators, urls, ips, findings,
      packed: ent >= 7.2
    };
  }

  CITADEL.binary = { analyze, format, entropy, strings };
})(window);

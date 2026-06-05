/* CITADEL — Demo Project
 * A small synthetic project with intentional weaknesses so the analyzer can be
 * demonstrated without uploading anything. window.CITADEL.demo
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL = root.CITADEL || {};

  const FILES = {
    'demo-app/server.js': `const express = require('express');
const { exec } = require('child_process');
const mysql = require('mysql');
const app = express();

// Hardcoded credentials (intentional)
const DB_PASSWORD = "Sup3rSecret!2024";
const AWS_ACCESS_KEY = "AKIAIOSFODNN7EXAMPLE";
const apiKey = "DEMO-PLACEHOLDER-not-a-real-key-000000000000";

app.get('/user', (req, res) => {
  // SQL injection
  const q = "SELECT * FROM users WHERE id = " + req.query.id;
  db.query(q, (e, r) => res.send(r));
  // XSS
  res.send("<h1>Hello " + req.query.name + "</h1>");
});

app.get('/ping', (req, res) => {
  // OS command injection
  exec("ping -c 1 " + req.query.host, (e, out) => res.send(out));
});

app.get('/token', (req, res) => {
  // Insecure randomness for a token
  res.send(String(Math.random()).slice(2));
});
app.listen(3000);
`,
    'demo-app/auth.py': `import hashlib, pickle, subprocess, requests

def hash_pw(p):
    # Weak hashing
    return hashlib.md5(p.encode()).hexdigest()

def load(data):
    # Insecure deserialization
    return pickle.loads(data)

def run(cmd):
    # shell=True command injection
    return subprocess.call(cmd, shell=True)

def fetch(url):
    # SSRF + disabled TLS verification
    return requests.get(url, verify=False)

PASSWORD = "admin123"
SECRET_TOKEN = "ghp_DEMOplaceholderTOKENnotREAL00000000000"
`,
    'demo-app/crypto.go': `package main

import "crypto/des"

func encrypt(key, data []byte) {
    // Broken cipher
    block, _ := des.NewCipher(key)
    _ = block
}
// TODO: replace with AES-GCM before release
`,
    'demo-app/index.html': `<!doctype html><html><body>
<script>
  // DOM XSS
  document.getElementById('out').innerHTML = location.hash;
  document.write(document.referrer);
</script>
</body></html>`,
    'demo-app/Dockerfile': `FROM node:18
WORKDIR /app
COPY . .
RUN npm install
EXPOSE 3000
CMD ["node","server.js"]
`,
    'demo-app/.github/workflows/ci.yml': `name: CI
on: [push]
jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - run: npm ci && npm test
`,
    'demo-app/package.json': JSON.stringify({
      name: 'demo-app', version: '0.4.1',
      dependencies: { express: '^4.18.2', mysql: '2.18.1', lodash: '*', request: '2.88.2', jsonwebtoken: '8.5.1' },
      devDependencies: { jest: '^29.0.0', 'eslint': 'latest' }
    }, null, 2),
    'demo-app/requirements.txt': `Flask==1.1.2\nrequests>=2.0\nPyYAML==5.1\ncryptography\n`,
    'demo-app/infra/main.tf': `provider "aws" { region = "us-gov-west-1" }
resource "aws_s3_bucket" "data" { bucket = "demo-cui-data" }
`
  };

  function buildEntries() {
    const entries = [];
    for (const path in FILES) {
      const content = FILES[path];
      entries.push({
        path, size: content.length, isBinary: false,
        lang: CITADEL.lang.detect(path), content, bytes: null
      });
    }
    // Synthetic "executable" with suspicious capability strings
    const strings = 'MZ\x90\x00 this program cannot be run in DOS mode ' +
      'VirtualAllocEx WriteProcessMemory CreateRemoteThread IsDebuggerPresent ' +
      'powershell -enc URLDownloadToFile http://malicious.example/payload.bin ' +
      'CurrentVersion\\\\Run AKIAIOSFODNN7EXAMPLE GetAsyncKeyState';
    const bytes = new Uint8Array(strings.length + 4);
    bytes[0] = 0x4D; bytes[1] = 0x5A; // MZ
    for (let i = 0; i < strings.length; i++) bytes[i + 4] = strings.charCodeAt(i) & 0xff;
    entries.push({ path: 'demo-app/dist/agent.exe', size: bytes.length, isBinary: true, lang: 'Other', content: null, bytes });
    return entries;
  }

  CITADEL.demo = { buildEntries, FILES };
})(window);

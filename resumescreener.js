/* ============================================================
   resumescreener.js
   ATS Resume Screener — client-side only
   - PDF.js for reading uploaded PDF resumes
   - Keyword extraction from job description
   - Skill taxonomy categorization
   - Weighted ATS scoring
   - Results display + export
   ============================================================ */

// Set PDF.js worker
if (typeof pdfjsLib !== 'undefined') {
  pdfjsLib.GlobalWorkerOptions.workerSrc =
    'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
}

// ── Skill taxonomy ────────────────────────────────────────────
const TAXONOMY = {
  'Cybersecurity Frameworks': {
    weight: 0.25,
    terms: [
      'cmmc','nist','nist 800-171','nist 800-53','rmf','grc','iso 27001','iso/iec 27001',
      'fedramp','fisma','hipaa','gdpr','pci dss','soc 2','dfars','dod','zero trust',
      'cis controls','cobit','itil','stigs','dod stigs','itar','far','dfar',
      'risk management','risk assessment','security assessment','continuous monitoring',
      'authorization','authority to operate','ato','poam','plan of action',
      'system security plan','ssp','security controls','control families',
    ]
  },
  'Certifications': {
    weight: 0.20,
    terms: [
      'cissp','cism','cisa','security+','comptia security','casp','cysa','pentest+',
      'ceh','certified ethical hacker','eces','certified encryption specialist',
      'ccna','ccnp','aws certified','azure certified','google cloud certified',
      'pmp','itil','oscp','gpen','gwapt','gsec','sans','isc2','isaca','ec-council',
    ]
  },
  'Technical Skills': {
    weight: 0.20,
    terms: [
      'python','javascript','powershell','bash','linux','windows server','active directory',
      'azure','aws','gcp','cloud','docker','kubernetes','terraform','ansible','git',
      'sql','networking','tcp/ip','firewall','vpn','endpoint','pki','cryptography',
      'encryption','rest api','json','xml','html','css','react','node','typescript',
      'devops','ci/cd','siem','soar','ids','ips','dlp','iam','pam',
    ]
  },
  'Security Tools': {
    weight: 0.15,
    terms: [
      'crowdstrike','splunk','sentinelone','microsoft sentinel','azure sentinel',
      'defender','microsoft defender','tenable','nessus','qualys','rapid7','nexpose',
      'metasploit','burp suite','wireshark','ghidra','nmap','nikto','openvas',
      'servicenow','jira','confluence','ninja','ninjarmm','ninjaone',
      'filecloud','crowdstrike falcon','carbon black','cylance','microsoft 365',
      'office 365','sharepoint','teams','intune','azure ad','active directory',
    ]
  },
  'Soft Skills': {
    weight: 0.10,
    terms: [
      'communication','leadership','teamwork','collaboration','problem solving',
      'analytical','detail oriented','organized','project management','presentation',
      'written communication','verbal communication','cross functional','stakeholder',
      'mentoring','coaching','training','documentation','report writing',
      'time management','adaptability','critical thinking','decision making',
    ]
  },
  'Education & Clearance': {
    weight: 0.10,
    terms: [
      'bachelor','master','degree','computer science','information technology',
      'cybersecurity','information assurance','secret clearance','top secret',
      'ts/sci','clearance','dod clearance','security clearance','active clearance',
      'wgu','western governors','university','college',
    ]
  },
};

const STOP_WORDS = new Set([
  'the','and','for','are','with','this','that','will','have','from','they','been',
  'has','its','but','not','you','all','can','her','was','one','our','out','day',
  'get','him','his','how','man','new','now','old','see','two','way','who','boy',
  'did','let','put','say','she','too','use','about','also','must','should','would',
  'could','their','there','these','those','what','when','where','which','while',
  'each','both','into','more','some','such','than','then','they','this','able',
  'also','back','been','best','both','call','come','does','each','even','find',
  'give','good','great','hand','here','high','hold','home','into','just','keep',
  'know','last','left','like','live','long','look','made','make','many','most',
  'move','much','must','next','open','over','part','play','same','seem','side',
  'take','tell','turn','used','very','well','went','were','work','year','years',
  'including','required','experience','ability','skills','skill','working','work',
  'team','must','will','position','role','job','responsibilities','qualifications',
  'preferred','strong','excellent','minimum','plus','required','familiarity',
  'understanding','knowledge','demonstrated','proven','responsible',
]);

// ── State ─────────────────────────────────────────────────────
let resumeText = '';
let lastResults = null;
let activeTab   = 'upload';

// ── PDF text extraction ───────────────────────────────────────
async function extractPDFText(file) {
  const buf = await file.arrayBuffer();
  const pdf = await pdfjsLib.getDocument({ data: buf }).promise;
  let text = '';
  for (let i = 1; i <= pdf.numPages; i++) {
    const page    = await pdf.getPage(i);
    const content = await page.getTextContent();
    text += content.items.map(item => item.str).join(' ') + '\n';
  }
  return text;
}

// ── Tokenization & keyword extraction ────────────────────────
function tokenize(text) {
  return text.toLowerCase().replace(/[^a-z0-9\s\-]/g, ' ').split(/\s+/).filter(w => w.length > 2);
}

function buildCandidates(tokens) {
  const candidates = new Map();
  // Unigrams
  tokens.forEach(t => {
    if (!STOP_WORDS.has(t)) candidates.set(t, (candidates.get(t) || 0) + 1);
  });
  // Bigrams (higher weight)
  for (let i = 0; i < tokens.length - 1; i++) {
    const bg = `${tokens[i]} ${tokens[i + 1]}`;
    if (!STOP_WORDS.has(tokens[i]) && !STOP_WORDS.has(tokens[i + 1])) {
      candidates.set(bg, (candidates.get(bg) || 0) + 2);
    }
  }
  // Trigrams (even higher)
  for (let i = 0; i < tokens.length - 2; i++) {
    const tg = `${tokens[i]} ${tokens[i+1]} ${tokens[i+2]}`;
    if (!STOP_WORDS.has(tokens[i]) && !STOP_WORDS.has(tokens[i+2])) {
      candidates.set(tg, (candidates.get(tg) || 0) + 3);
    }
  }
  return candidates;
}

function categorizeJD(jdText) {
  const tokens    = tokenize(jdText);
  const candidates = buildCandidates(tokens);
  const result    = {};

  for (const [cat, { terms }] of Object.entries(TAXONOMY)) {
    result[cat] = [];
    for (const term of terms) {
      // Check if term appears in JD (direct substring match on normalized text)
      const normJD = jdText.toLowerCase();
      if (normJD.includes(term) || candidates.has(term)) {
        // Compute frequency score
        const freq = candidates.get(term) || (normJD.includes(term) ? 1 : 0);
        if (freq > 0) result[cat].push({ keyword: term, freq });
      }
    }
    // Sort by freq desc, deduplicate by preference for longer matches
    result[cat].sort((a, b) => b.freq - a.freq || b.keyword.length - a.keyword.length);
    // Remove shorter substrings if longer version exists
    const kept = [];
    result[cat].forEach(item => {
      const dominated = kept.some(k => k.keyword.includes(item.keyword) && k.keyword !== item.keyword);
      if (!dominated) kept.push(item);
    });
    result[cat] = kept.slice(0, 20);
  }

  return result;
}

// ── Scoring ───────────────────────────────────────────────────
function scoreResume(resumeTxt, categories) {
  const normResume = resumeTxt.toLowerCase().replace(/[^a-z0-9\s\-]/g, ' ');
  const matched    = [];
  const missing    = [];
  const catScores  = {};

  for (const [cat, keywords] of Object.entries(categories)) {
    if (!keywords.length) { catScores[cat] = { matched: 0, total: 0, pct: 0 }; continue; }
    let catMet = 0;
    keywords.forEach(({ keyword }) => {
      if (normResume.includes(keyword)) {
        matched.push({ keyword, category: cat });
        catMet++;
      } else {
        missing.push({ keyword, category: cat });
      }
    });
    const pct = Math.round((catMet / keywords.length) * 100);
    catScores[cat] = { matched: catMet, total: keywords.length, pct };
  }

  // Weighted ATS score
  let weightedSum = 0, totalWeight = 0;
  for (const [cat, { weight }] of Object.entries(TAXONOMY)) {
    if (catScores[cat] && catScores[cat].total > 0) {
      weightedSum  += catScores[cat].pct * weight;
      totalWeight  += weight;
    }
  }
  const atsScore = totalWeight > 0 ? Math.round(weightedSum / totalWeight) : 0;

  return { atsScore, matched, missing, catScores };
}

// ── Recommendations ───────────────────────────────────────────
function makeRecommendations(results) {
  const { atsScore, missing, catScores } = results;
  const recs = [];

  if (atsScore >= 75) {
    recs.push('Strong match! Your resume aligns well with this role. Focus on tailoring your work experience narratives to mirror the exact language in the job description.');
  } else if (atsScore >= 50) {
    recs.push('Moderate match. Adding the missing keywords in context — within your experience bullets — will meaningfully improve your ATS score.');
  } else {
    recs.push('Low match. Consider significantly tailoring your resume to this specific role, or assess whether your background aligns with the requirements.');
  }

  // Certs
  const certScore = catScores['Certifications'];
  if (certScore && certScore.total > 0 && certScore.pct < 60) {
    const missingCerts = missing.filter(m => m.category === 'Certifications').map(m => m.keyword);
    if (missingCerts.length) {
      recs.push(`Missing certifications detected: ${missingCerts.slice(0, 4).join(', ')}. If you hold any of these, add them explicitly — use the full name and acronym.`);
    }
  }

  // Technical skills
  const techScore = catScores['Technical Skills'];
  if (techScore && techScore.total > 0 && techScore.pct < 40) {
    recs.push('Add a dedicated "Technical Skills" or "Core Competencies" section that lists tools, platforms, and languages by name.');
  }

  // Clearance/Education
  const eduScore = catScores['Education & Clearance'];
  if (eduScore && eduScore.total > 0 && eduScore.pct < 50) {
    recs.push('If you hold a security clearance or relevant degree, make sure it appears prominently — clearance level, granting agency, and expiration if applicable.');
  }

  // Top missing across all
  const topMissing = missing.slice(0, 6).map(m => m.keyword);
  if (topMissing.length) {
    recs.push(`Priority keywords to incorporate (where truthful): ${topMissing.join(', ')}.`);
  }

  // Frameworks
  const fwScore = catScores['Cybersecurity Frameworks'];
  if (fwScore && fwScore.total > 0 && fwScore.pct < 30) {
    recs.push('Spell out compliance framework experience explicitly (e.g., "CMMC Level 2 implementation", "NIST SP 800-171 compliance") rather than generic security descriptions.');
  }

  return recs;
}

// ── Gauge animation ───────────────────────────────────────────
function animateGauge(score) {
  const arc        = document.getElementById('gaugeArc');
  const numEl      = document.getElementById('scoreValue');
  const totalLen   = 173; // approximate arc length for our SVG half-circle
  const targetDash = (score / 100) * totalLen;
  const color      = score >= 75 ? '#4ade80' : score >= 50 ? '#facc15' : '#f87171';

  arc.style.stroke = color;
  arc.style.strokeDasharray = `${targetDash} ${totalLen}`;

  let current = 0;
  const step  = Math.ceil(score / 40);
  const timer = setInterval(() => {
    current = Math.min(current + step, score);
    numEl.textContent = current;
    if (current >= score) clearInterval(timer);
  }, 25);
}

// ── Render results ────────────────────────────────────────────
function renderResults(results, jdText) {
  const { atsScore, matched, missing, catScores } = results;
  lastResults = { ...results, jdText };

  // Score label
  const scoreLabel = atsScore >= 75 ? '🟢 Strong Match'
    : atsScore >= 50 ? '🟡 Moderate Match'
    : '🔴 Weak Match';
  const subLabel = `${matched.length} keywords matched · ${missing.length} missing`;

  document.getElementById('scoreLabel').textContent   = scoreLabel;
  document.getElementById('scoreSubLabel').textContent = subLabel;
  animateGauge(atsScore);

  // Reset filter state on each new analysis
  activeFilterCat = 'all';
  filterQuery = '';
  document.getElementById('kwSearchInput').value = '';
  document.querySelectorAll('.kw-pill').forEach(p => p.classList.remove('active'));
  document.querySelector('.kw-pill[data-cat="all"]').classList.add('active');

  // Matched keywords
  const matchedEl = document.getElementById('matchedKeywords');
  matchedEl.innerHTML = matched.length
    ? matched.map(m => `<span class="kw-tag" data-kw="${m.keyword}" data-cat="${m.category}" title="${m.category}">${m.keyword}</span>`).join('')
    : '<em style="color:#484f58">None found</em>';

  // Missing keywords
  const missingEl = document.getElementById('missingKeywords');
  missingEl.innerHTML = missing.length
    ? missing.map(m => `<span class="kw-tag" data-kw="${m.keyword}" data-cat="${m.category}" title="${m.category}">${m.keyword}</span>`).join('')
    : '<em style="color:#4ade80">All keywords found!</em>';

  // Initialize counts
  document.getElementById('matchedCount').textContent = `(${matched.length})`;
  document.getElementById('missingCount').textContent = `(${missing.length})`;

  // Category breakdown
  const catEl = document.getElementById('categoryBreakdown');
  catEl.innerHTML = Object.entries(catScores)
    .filter(([, s]) => s.total > 0)
    .map(([cat, s]) => {
      const fillClass = s.pct >= 75 ? 'good' : s.pct >= 40 ? 'partial' : 'low';
      return `
        <div class="cat-row">
          <div class="cat-label">
            <span>${cat}</span>
            <span class="cat-pct">${s.matched}/${s.total} (${s.pct}%)</span>
          </div>
          <div class="cat-track">
            <div class="cat-fill ${fillClass}" style="width:${s.pct}%"></div>
          </div>
        </div>`;
    }).join('');

  // Recommendations
  const recs = makeRecommendations(results);
  document.getElementById('recommendations').innerHTML =
    recs.map(r => `<li>${r}</li>`).join('');

  // Show results
  const resultsEl = document.getElementById('results');
  resultsEl.classList.remove('hidden');
  resultsEl.scrollIntoView({ behavior: 'smooth' });
}

// ── Analyze ───────────────────────────────────────────────────
async function analyze() {
  const jdText = document.getElementById('jobDescText').value.trim();
  if (!jdText) { alert('Please paste a job description.'); return; }

  // Get resume text
  let rText = '';
  if (activeTab === 'paste') {
    rText = document.getElementById('resumeText').value.trim();
    if (!rText) { alert('Please paste your resume text.'); return; }
  } else {
    if (!resumeText) { alert('Please upload a resume file first.'); return; }
    rText = resumeText;
  }

  const btn = document.getElementById('analyzeBtn');
  btn.textContent = '⏳ Analyzing…';
  btn.disabled = true;

  try {
    const categories = categorizeJD(jdText);
    const results    = scoreResume(rText, categories);
    renderResults(results, jdText);
  } catch (err) {
    console.error(err);
    alert('Analysis failed. Please check your inputs and try again.');
  } finally {
    btn.textContent = '🔍 Analyze Match';
    btn.disabled = false;
  }
}

// ── Export results ────────────────────────────────────────────
function exportResults() {
  if (!lastResults) return;
  const { atsScore, matched, missing, catScores } = lastResults;
  const recs = makeRecommendations(lastResults);

  const lines = [
    'ATS RESUME ANALYSIS REPORT',
    `Generated: ${new Date().toLocaleString()}`,
    '='.repeat(50),
    '',
    `ATS Score: ${atsScore}/100`,
    '',
    '── MATCHED KEYWORDS ──',
    ...matched.map(m => `  [${m.category}] ${m.keyword}`),
    '',
    '── MISSING KEYWORDS ──',
    ...missing.map(m => `  [${m.category}] ${m.keyword}`),
    '',
    '── CATEGORY BREAKDOWN ──',
    ...Object.entries(catScores)
      .filter(([, s]) => s.total > 0)
      .map(([cat, s]) => `  ${cat}: ${s.matched}/${s.total} (${s.pct}%)`),
    '',
    '── RECOMMENDATIONS ──',
    ...recs.map((r, i) => `${i + 1}. ${r}`),
    '',
    '='.repeat(50),
    'Generated by jessicarojas1.github.io/resumescreener.html',
  ];

  const blob = new Blob([lines.join('\n')], { type: 'text/plain' });
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href     = url;
  a.download = `ats_analysis_${new Date().toISOString().split('T')[0]}.txt`;
  a.click();
  URL.revokeObjectURL(url);
}

// ── Event listeners ───────────────────────────────────────────

// Tabs
document.getElementById('tabUploadBtn').addEventListener('click', () => {
  activeTab = 'upload';
  document.getElementById('tabUploadBtn').classList.add('active');
  document.getElementById('tabPasteBtn').classList.remove('active');
  document.getElementById('tabUpload').classList.remove('hidden');
  document.getElementById('tabPaste').classList.add('hidden');
});

document.getElementById('tabPasteBtn').addEventListener('click', () => {
  activeTab = 'paste';
  document.getElementById('tabPasteBtn').classList.add('active');
  document.getElementById('tabUploadBtn').classList.remove('active');
  document.getElementById('tabPaste').classList.remove('hidden');
  document.getElementById('tabUpload').classList.add('hidden');
});

// File upload
document.getElementById('resumeFile').addEventListener('change', async e => {
  const file = e.target.files[0];
  if (!file) return;
  await handleFileUpload(file);
});

// Drag & drop
const dropZone = document.getElementById('dropZone');

dropZone.addEventListener('dragover', e => {
  e.preventDefault();
  dropZone.classList.add('drag-over');
});

dropZone.addEventListener('dragleave', () => {
  dropZone.classList.remove('drag-over');
});

dropZone.addEventListener('drop', async e => {
  e.preventDefault();
  dropZone.classList.remove('drag-over');
  const file = e.dataTransfer.files[0];
  if (file) await handleFileUpload(file);
});

async function handleFileUpload(file) {
  const status = document.getElementById('fileStatus');
  const dropText = document.getElementById('dropText');
  status.className = 'file-status';
  status.textContent = 'Reading file…';

  try {
    if (file.type === 'application/pdf' || file.name.endsWith('.pdf')) {
      if (typeof pdfjsLib === 'undefined') {
        status.className = 'file-status error';
        status.textContent = '⚠ PDF.js failed to load. Try pasting your resume text instead.';
        return;
      }
      resumeText = await extractPDFText(file);
    } else if (file.type === 'text/plain' || file.name.endsWith('.txt')) {
      resumeText = await file.text();
    } else {
      status.className = 'file-status error';
      status.textContent = '⚠ Unsupported file type. Please upload a PDF or .txt file.';
      return;
    }

    if (!resumeText.trim()) {
      status.className = 'file-status error';
      status.textContent = '⚠ Could not extract text from this file. Try the Paste Text option.';
      return;
    }

    dropText.textContent = `✅ ${file.name}`;
    status.textContent   = `${Math.round(file.size / 1024)} KB · ~${resumeText.split(/\s+/).length} words extracted`;
  } catch (err) {
    console.error(err);
    status.className = 'file-status error';
    status.textContent = '⚠ Error reading file. Try the Paste Text option.';
    resumeText = '';
  }
}

// Analyze
document.getElementById('analyzeBtn').addEventListener('click', analyze);

// Export
document.getElementById('exportResultsBtn').addEventListener('click', exportResults);

// ── Keyword filter ────────────────────────────────────────────
let activeFilterCat = 'all';
let filterQuery     = '';

function applyKeywordFilter() {
  const q   = filterQuery.toLowerCase().trim();
  const cat = activeFilterCat;

  let shownMatched = 0, shownMissing = 0;

  document.querySelectorAll('#matchedKeywords .kw-tag').forEach(tag => {
    const kw      = tag.dataset.kw  || '';
    const tagCat  = tag.dataset.cat || '';
    const catOk   = cat === 'all' || tagCat === cat;
    const queryOk = !q || kw.includes(q);
    if (catOk && queryOk) { tag.classList.remove('kw-hidden'); shownMatched++; }
    else                   tag.classList.add('kw-hidden');
  });

  document.querySelectorAll('#missingKeywords .kw-tag').forEach(tag => {
    const kw      = tag.dataset.kw  || '';
    const tagCat  = tag.dataset.cat || '';
    const catOk   = cat === 'all' || tagCat === cat;
    const queryOk = !q || kw.includes(q);
    if (catOk && queryOk) { tag.classList.remove('kw-hidden'); shownMissing++; }
    else                   tag.classList.add('kw-hidden');
  });

  document.getElementById('matchedCount').textContent = `(${shownMatched})`;
  document.getElementById('missingCount').textContent = `(${shownMissing})`;
  document.getElementById('kwFilterCount').textContent =
    (cat !== 'all' || q) ? `${shownMatched + shownMissing} shown` : '';
}

document.getElementById('kwSearchInput').addEventListener('input', e => {
  filterQuery = e.target.value;
  applyKeywordFilter();
});

document.getElementById('kwCatPills').addEventListener('click', e => {
  const pill = e.target.closest('.kw-pill');
  if (!pill) return;
  document.querySelectorAll('.kw-pill').forEach(p => p.classList.remove('active'));
  pill.classList.add('active');
  activeFilterCat = pill.dataset.cat;
  applyKeywordFilter();
});

// Reset
document.getElementById('resetBtn').addEventListener('click', () => {
  resumeText = '';
  lastResults = null;
  document.getElementById('resumeFile').value = '';
  document.getElementById('dropText').textContent = 'Drag & drop a PDF or .txt file, or click to browse';
  document.getElementById('fileStatus').textContent = '';
  document.getElementById('resumeText').value = '';
  document.getElementById('jobDescText').value = '';
  document.getElementById('results').classList.add('hidden');
  document.getElementById('scoreValue').textContent = '0';
  document.getElementById('gaugeArc').style.strokeDasharray = '0 173';
  document.getElementById('kwSearchInput').value = '';
  document.getElementById('kwFilterCount').textContent = '';
  document.getElementById('matchedCount').textContent = '';
  document.getElementById('missingCount').textContent = '';
  activeFilterCat = 'all';
  filterQuery = '';
  document.querySelectorAll('.kw-pill').forEach(p => p.classList.remove('active'));
  document.querySelector('.kw-pill[data-cat="all"]').classList.add('active');
  window.scrollTo({ top: 0, behavior: 'smooth' });
});

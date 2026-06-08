const fs=require('fs');
const path=require('path');
const engine=require(path.join(__dirname,'..','..','server','lib','engine'));
const ROOT=process.env.OWASP_BENCH_DIR||'/tmp/BenchmarkJava';
const TESTDIR=ROOT+'/src/main/java/org/owasp/benchmark/testcode';
const truth={};
fs.readFileSync(ROOT+'/expectedresults-1.2.csv','utf8').trim().split('\n').slice(1).forEach(l=>{
  const [name,cat,real,cwe]=l.split(',');
  if(name) truth[name]={cat,real:real==='true','cwe':'CWE-'+cwe};
});
// per-category acceptable CWEs (the engine may label with a sibling CWE)
const ACCEPT={cmdi:['CWE-78'],sqli:['CWE-89'],xss:['CWE-79'],pathtraver:['CWE-22'],
  crypto:['CWE-327','CWE-326'],hash:['CWE-328','CWE-327'],weakrand:['CWE-330'],
  ldapi:['CWE-90'],securecookie:['CWE-614','CWE-1004'],trustbound:['CWE-501'],xpathi:['CWE-643','CWE-91']};
(async()=>{
  const t0=Date.now();
  const r=await engine.analyzeDir(TESTDIR,{findings:[]});
  const byFile={};
  r.findings.forEach(f=>{const b=(f.file||'').split('/').pop().replace(/\.java$/,'');(byFile[b]=byFile[b]||new Set()).add(f.cwe);});
  const cats={};
  for(const [name,tr] of Object.entries(truth)){
    const acc=ACCEPT[tr.cat]||[tr.cwe];
    const flagged=byFile[name]&&acc.some(c=>byFile[name].has(c));
    const c=cats[tr.cat]=cats[tr.cat]||{tp:0,fp:0,fn:0,tn:0};
    if(tr.real){flagged?c.tp++:c.fn++;}else{flagged?c.fp++:c.tn++;}
  }
  let TP=0,FP=0,FN=0,TN=0;
  console.log('category        TPR%   FPR%  prec%  score   n');
  for(const k of Object.keys(cats).sort()){const c=cats[k];TP+=c.tp;FP+=c.fp;FN+=c.fn;TN+=c.tn;
    const tpr=c.tp/(c.tp+c.fn||1),fpr=c.fp/(c.fp+c.tn||1),prec=c.tp/((c.tp+c.fp)||1);
    console.log(k.padEnd(14),(tpr*100).toFixed(0).padStart(4),(fpr*100).toFixed(0).padStart(6),(prec*100).toFixed(0).padStart(6),((tpr-fpr)*100).toFixed(0).padStart(7),(c.tp+c.fp+c.fn+c.tn).toString().padStart(5));
  }
  const tpr=TP/(TP+FN),fpr=FP/(FP+TN),prec=TP/(TP+FP),f1=2*prec*tpr/(prec+tpr);
  console.log('\nOVERALL  TPR/recall '+(tpr*100).toFixed(1)+'%  FPR '+(fpr*100).toFixed(1)+'%  precision '+(prec*100).toFixed(1)+'%  F1 '+f1.toFixed(3)+'  Youden(score) '+((tpr-fpr)*100).toFixed(1));
  console.log('cases:',Object.keys(truth).length,' engine findings:',r.findings.length,' time:',((Date.now()-t0)/1000).toFixed(1)+'s');
})().catch(e=>{console.error('ERR',e.message);process.exit(1)});

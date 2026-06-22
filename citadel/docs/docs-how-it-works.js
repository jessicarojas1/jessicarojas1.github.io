/* CITADEL docs — how-it-works page logic (externalized so the CSP can drop
   script-src 'unsafe-inline'). */
(function(){
  function icon(){var t=document.documentElement.getAttribute('data-bs-theme');var i=document.querySelector('#themeToggleBtn .theme-icon');if(i)i.textContent=t==='dark'?'☀️':'🌙';}
  document.getElementById('themeToggleBtn').addEventListener('click',function(){var c=document.documentElement.getAttribute('data-bs-theme');var n=c==='dark'?'light':'dark';document.documentElement.setAttribute('data-bs-theme',n);try{localStorage.setItem('bsTheme',n);}catch(e){}icon();});icon();
})();

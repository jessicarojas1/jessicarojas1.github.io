// ─── Theme: apply saved theme immediately to prevent flash ───
(function () {
  if (localStorage.getItem('theme') === 'dark') {
    document.documentElement.classList.add('dark-mode');
  }
})();

document.addEventListener('DOMContentLoaded', () => {
  const toggle = document.getElementById('darkModeToggle');

  // Sync button label to current state
  function syncLabel() {
    if (!toggle) return;
    const isDark = document.documentElement.classList.contains('dark-mode');
    toggle.textContent = isDark ? '☀️ Light Mode' : '🌙 Dark Mode';
  }

  if (toggle) {
    toggle.addEventListener('click', () => {
      document.documentElement.classList.toggle('dark-mode');
      const isDark = document.documentElement.classList.contains('dark-mode');
      localStorage.setItem('theme', isDark ? 'dark' : 'light');
      syncLabel();
    });
    syncLabel();
  }

  // Dynamic copyright year
  const yr = document.getElementById('yr');
  if (yr) yr.textContent = new Date().getFullYear();

  // Scroll reveal
  const reveals = document.querySelectorAll('.reveal');
  const observer = new IntersectionObserver(
    entries => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          entry.target.classList.add('visible');
          observer.unobserve(entry.target);
        }
      });
    },
    { threshold: 0.1 }
  );
  reveals.forEach(el => observer.observe(el));
});

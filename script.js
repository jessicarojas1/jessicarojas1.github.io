document.getElementById('darkModeToggle').addEventListener('click', function () {
  document.body.classList.toggle('dark-mode');
  this.textContent = document.body.classList.contains('dark-mode') ? '☀️ Light Mode' : '🌙 Dark Mode';
});

function toggleDescription(block) {
  const content = block.querySelector('.timeline-content');
  content.classList.toggle('expanded');
}

document.addEventListener("DOMContentLoaded", () => {
  const items = document.querySelectorAll(".timeline-content");
  const observer = new IntersectionObserver(entries => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        entry.target.classList.add("visible");
      }
    });
  }, { threshold: 0.1 });
  items.forEach(item => observer.observe(item));
});

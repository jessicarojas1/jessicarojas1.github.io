document.getElementById('darkModeToggle').addEventListener('click', function () {
  document.body.classList.toggle('dark-mode');
  this.textContent = document.body.classList.contains('dark-mode') ? '☀️ Light Mode' : '🌙 Dark Mode';
});

function toggleDescription(block) {
  const content = block.querySelector('.timeline-content');
  content.classList.toggle('expanded');
}

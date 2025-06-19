document.getElementById('darkModeToggle').addEventListener('click', function () {
  document.body.classList.toggle('dark-mode');
  this.textContent = document.body.classList.contains('dark-mode') ? '☀️ Light Mode' : '🌙 Dark Mode';
});
document.querySelectorAll('.counter').forEach(counter => {
  const target = +counter.getAttribute('data-target');
  let count = 0;
  const update = () => {
    count += 1;
    counter.textContent = count;
    if (count < target) setTimeout(update, 100);
    else counter.textContent = target;
  };
  update();
});
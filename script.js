document.getElementById('darkModeToggle').addEventListener('click', function () {
  document.body.classList.toggle('dark-mode');
  this.textContent = document.body.classList.contains('dark-mode') ? '☀️ Light Mode' : '🌙 Dark Mode';
});
document.querySelectorAll('.counter').forEach(counter => {
  let count = 0;
  const target = +counter.getAttribute('data-target');
  const update = () => {
    if (count < target) {
      count++;
      counter.textContent = count;
      setTimeout(update, 60);
    } else {
      counter.textContent = target;
    }
  };
  update();
});
document.addEventListener("DOMContentLoaded", () => {
  const revealElements = document.querySelectorAll(".reveal");
  const observer = new IntersectionObserver(entries => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        entry.target.classList.add("visible");
      }
    });
  }, { threshold: 0.1 });

  revealElements.forEach(el => observer.observe(el));
});


function toggleExpand(el) {
      el.classList.toggle('expanded');
      const desc = el.querySelector('p');
      desc.classList.toggle('hidden');
    }

function filterProjects(category) {
      document.querySelectorAll('.card').forEach(card => {
        if (category === 'all' || card.classList.contains(category)) {
          card.classList.remove('hidden');
        } else {
          card.classList.add('hidden');
        }
      });
    }
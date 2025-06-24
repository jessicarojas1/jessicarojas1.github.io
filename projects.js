fetch('projects.json')
  .then(res => res.json())
  .then(data => {
    const container = document.getElementById('project-list');
    data.forEach(project => {
      const card = document.createElement('div');
      card.className = 'project-card';
      card.innerHTML = `<h3>${project.title}</h3><p>${project.description}</p><a href="${project.github_url}" target="_blank">View on GitHub</a>`;
      container.appendChild(card);
    });
  });

  // Example rendering (simplified)
data.forEach(project => {
  const card = `
    <div class="project-card">
      <img src="${project.image}" alt="${project.title}" />
      <h3>${project.title}</h3>
      <p>${project.description}</p>
      <a href="${project.link}" class="btn">View Project</a>
    </div>
  `;
  container.innerHTML += card;
});


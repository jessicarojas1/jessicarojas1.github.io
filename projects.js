fetch('projects.json')
  .then(res => res.json())
  .then(data => {
    const container = document.getElementById('project-list');
    data.forEach(project => {
      const card = document.createElement('div');
      card.className = 'project-card reveal';
      card.innerHTML = `
        <img src="${project.image}" alt="${project.title}" class="project-thumbnail" />
        <div class="project-title">${project.title}</div>
        <div class="project-desc">${project.description}</div>
        <div class="project-links">
          <a href="${project.link}" target="_blank">Live</a>
          <a href="${project.github_url}" target="_blank">Code</a>
        </div>
      `;
      container.appendChild(card);
    });
  })
  .catch(err => console.error('Error loading projects:', err));

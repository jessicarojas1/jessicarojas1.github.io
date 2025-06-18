function loadData() {
  fetch('data.php')
    .then(response => response.text())
    .then(data => {
      document.getElementById('message').innerHTML = data;
    })
    .catch(error => console.error('Error:', error));
}

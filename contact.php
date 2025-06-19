<?php include 'header.php'; ?><nav class="navbar navbar-expand-lg navbar-light bg-light sticky-top">
  <div class="container justify-content-center">
    <a class="nav-link" href="index.php">Home</a>
    <a class="nav-link" href="projects.php">Projects</a>
    <a class="nav-link" href="resume.php">Resume</a>
    <a class="nav-link" href="contact.php">Contact</a>
  </div>
</nav>
<main class="container py-5">
  <h1 class="text-center mb-4">Contact Me</h1>
  <form method="POST" action="send_mail.php" class="mx-auto" style="max-width: 600px;">
    <div class="mb-3">
      <input type="text" name="name" class="form-control" placeholder="Your Name" required />
    </div>
    <div class="mb-3">
      <input type="email" name="email" class="form-control" placeholder="Your Email" required />
    </div>
    <div class="mb-3">
      <textarea name="message" class="form-control" placeholder="Your Message" required></textarea>
    </div>
    <button type="submit" class="btn btn-primary">Send Message</button>
  </form>
</main>
<footer class="bg-dark text-white text-center py-3">
  <p>&copy; 2025 Jessica Rojas</p>
</footer>
<?php include 'footer.php'; ?>
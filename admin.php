<?php
session_start();
if (!isset($_SESSION['logged_in'])) {
  header("Location: login.php");
  exit;
}
?>
<?php include 'header.php'; ?>
<?php include 'nav.php'; ?>
<main class="container py-5">
  <h1>Admin Panel</h1>
  <p>Welcome, admin. You can manage content here.</p>
  <!-- Future CMS features go here -->
</main>
<?php include 'footer.php'; ?>

<?php
session_start();
$error = "";
if ($_SERVER["REQUEST_METHOD"] == "POST") {
  $user = $_POST['username'];
  $pass = $_POST['password'];
  if ($user === "admin" && $pass === "password123") {
    $_SESSION['logged_in'] = true;
    header("Location: admin.php");
    exit;
  } else {
    $error = "Invalid login";
  }
}
?>
<?php include 'header.php'; ?>
<main class="container py-5">
  <h1 class="text-center mb-4">Admin Login</h1>
  <form method="POST" class="mx-auto" style="max-width: 400px;">
    <div class="mb-3">
      <input type="text" name="username" class="form-control" placeholder="Username" required>
    </div>
    <div class="mb-3">
      <input type="password" name="password" class="form-control" placeholder="Password" required>
    </div>
    <button type="submit" class="btn btn-primary w-100">Login</button>
    <?php if ($error): ?><div class="text-danger mt-2"><?= $error ?></div><?php endif; ?>
  </form>
</main>
<?php include 'footer.php'; ?>

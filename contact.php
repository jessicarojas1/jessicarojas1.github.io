<?php
if ($_SERVER["REQUEST_METHOD"] == "POST") {
  $name    = htmlspecialchars($_POST['name']);
  $email   = htmlspecialchars($_POST['email']);
  $message = htmlspecialchars($_POST['message']);

  $to = "your@email.com";  // Replace with your real email
  $subject = "Contact Form Submission from $name";
  $headers = "From: $email";

  if (mail($to, $subject, $message, $headers)) {
    echo "Message sent successfully.";
  } else {
    echo "Failed to send message.";
  }
}
?>

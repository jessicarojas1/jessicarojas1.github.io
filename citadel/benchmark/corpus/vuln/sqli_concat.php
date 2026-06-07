<?php
$id = $_GET['id'];
$res = mysqli_query($conn, "SELECT * FROM users WHERE id = " . $id);

<?php
$host = "127.0.0.1:3307"; 
$user = "root";
$pass = "";
$dbname = "twezimbesacco"; 

$conn = new mysqli($host, $user, $pass, $dbname);

if ($conn->connect_error) {
    die("Database Infrastructure Connection Fault: " . $conn->connect_error);
}
?>

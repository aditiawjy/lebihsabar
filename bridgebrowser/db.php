<?php
$host = 'REDACTED_HOST';
$db_name = 'REDACTED_DBNAME';
$username = 'REDACTED_CRED';
$password = 'REDACTED_CRED';

try {
    $conn = new PDO("mysql:host=$host;dbname=$db_name;charset=utf8", $username, $password);
    // Set PDO error mode to exception
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo 'Connection failed: ' . $e->getMessage();
    exit;
}
?>
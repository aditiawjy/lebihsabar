<?php
$host = 'hipbiz.id';
$db_name = 'u2823579_test2';
$username = 'u2823579_test';
$password = 'u2823579_test';

try {
    $conn = new PDO("mysql:host=$host;dbname=$db_name;charset=utf8", $username, $password);
    // Set PDO error mode to exception
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo 'Connection failed: ' . $e->getMessage();
    exit;
}
?>
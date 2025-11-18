<?php
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', 'E:/sldpl.tokenmanager.com/logs/php_errors.log');
error_reporting(E_ALL);

$host = 'localhost:3306';
$user = 'root';
$pass = '1234567';

try {
    $pdo = new PDO("mysql:host=$host;dbname=sld_accounts;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "Connected to sld_accounts<br>";
    $stmt = $pdo->query("SELECT id, bank_name, account_number FROM bank_accounts");
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Bank accounts: " . count($accounts) . "<br>";
    print_r($accounts);
} catch (PDOException $e) {
    echo "sld_accounts failed: " . $e->getMessage() . "<br>";
    error_log("sld_accounts failed: " . $e->getMessage());
}

try {
    $pdo = new PDO("mysql:host=$host;dbname=sldiagnostic;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "Connected to sldiagnostic<br>";
} catch (PDOException $e) {
    echo "sldiagnostic failed: " . $e
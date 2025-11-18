<?php
session_start();
require_once 'includes/db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = $_POST['date'];
    $source = $_POST['source'];
    $type = $_POST['type'];
    $amount = $_POST['amount'];
    $description = $_POST['description'];

    $stmt = $pdo->prepare("INSERT INTO cash_receipts (date, source, type, amount, description) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$date, $source, $type, $amount, $description]);

    header('Location: cash_management.php');
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add Cash Receipt</title>
    <link rel="stylesheet" href="css/styles.css">
</head>
<body>
    <h1>Add Cash Receipt</h1>
    <form method="POST">
        <label>Date:</label>
        <input type="date" name="date" required><br>
        <label>Source:</label>
        <select name="source" required>
            <option value="GCC">GCC</option>
            <option value="Asra">Asra</option>
            <option value="Shailaja">Shailaja</option>
            <option value="Manjula">Manjula</option>
            <option value="Anuradha">Anuradha</option>
            <option value="Radha">Radha</option>
            <option value="Neo BBC">Neo BBC</option>
        </select><br>
        <label>Type:</label>
        <select name="type" required>
            <option value="Medical">Medical</option>
            <option value="Vaccine">Vaccine</option>
            <option value="Voucher">Voucher</option>
            <option value="Paytm">Paytm QR UPI</option>
            <option value="HDFC_QR">HDFC QR UPI</option>
            <option value="HDFC_Card">HDFC Credit Card</option>
            <option value="Cash">Cash</option>
        </select><br>
        <label>Amount (Rs):</label>
        <input type="number" step="0.01" name="amount" required><br>
        <label>Description:</label>
        <textarea name="description"></textarea><br>
        <button type="submit">Add Receipt</button>
    </form>
    <a href="cash_management.php">Back to Dashboard</a>
</body>
</html>
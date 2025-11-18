<?php
// Set the header to output JSON and set the correct timezone
header('Content-Type: application/json');
date_default_timezone_set('Asia/Kolkata');

// --- 🚨 DATABASE CONFIGURATION (Source Server) 🚨 ---
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'sld_accounts');
define('DB_USER', 'root');
define('DB_PASS', '1234567');
// --------------------------------------------------

try {
    // --- Connect to the financial database using PDO ---
    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

    // --- Define the 7-day date range ---
    $end_date = new DateTime('today');
    $start_date = (new DateTime('today'))->modify('-6 days');
    
    // --- Initialize arrays to hold data, pre-filled with 0s ---
    $period = new DatePeriod($start_date, new DateInterval('P1D'), (clone $end_date)->modify('+1 day'));
    $daily_income = [];
    $daily_expenses = [];
    foreach ($period as $date) {
        $date_key = $date->format('Y-m-d');
        $daily_income[$date_key] = 0;
        $daily_expenses[$date_key] = 0;
    }
    
    $start_date_str = $start_date->format('Y-m-d');
    $end_date_str = $end_date->format('Y-m-d');

    // --- 1. Fetch all income for the date range in a single query ---
    $sql_income = "
        SELECT date, SUM(amount) as total_income FROM (
            SELECT date, amount FROM cash_receipts WHERE date BETWEEN :start_date AND :end_date
            UNION ALL
            SELECT date, amount FROM other_receipts WHERE date BETWEEN :start_date AND :end_date
        ) as combined_income
        GROUP BY date
    ";
    $stmt_income = $pdo->prepare($sql_income);
    $stmt_income->execute(['start_date' => $start_date_str, 'end_date' => $end_date_str]);
    $income_results = $stmt_income->fetchAll();

    // Populate the income array from query results
    foreach ($income_results as $row) {
        $daily_income[$row['date']] = (float)$row['total_income'];
    }

    // --- 2. Fetch all expenses for the date range in a single query ---
    $sql_expenses = "
        SELECT date, SUM(total_amount) as total_expenses FROM (
            SELECT date, amount as total_amount FROM cash_payments WHERE date BETWEEN :start_date AND :end_date
            UNION ALL
            SELECT date, amount as total_amount FROM bank_payments WHERE date BETWEEN :start_date AND :end_date
            UNION ALL
            SELECT date, fuel_amount as total_amount FROM vehicle_fuel_records WHERE date BETWEEN :start_date AND :end_date
        ) as combined_expenses
        GROUP BY date
    ";
    $stmt_expenses = $pdo->prepare($sql_expenses);
    $stmt_expenses->execute(['start_date' => $start_date_str, 'end_date' => $end_date_str]);
    $expense_results = $stmt_expenses->fetchAll();
    
    // Populate the expenses array from query results
    foreach ($expense_results as $row) {
        $daily_expenses[$row['date']] = (float)$row['total_expenses'];
    }

    // --- 3. Format the final output for the chart ---
    $labels_output = [];
    foreach ($period as $date) {
        $labels_output[] = $date->format('M d'); // Format like "Jul 19"
    }

    $response = [
        'labels'   => $labels_output,
        'income'   => array_values($daily_income),
        'expenses' => array_values($daily_expenses)
    ];
    
    // Send the successful JSON response
    echo json_encode($response);

} catch (PDOException $e) {
    // In case of error, send an error response
    http_response_code(500);
    echo json_encode([
        'error' => 'Database Error',
        'message' => $e->getMessage()
    ]);
}
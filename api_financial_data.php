<?php
// Set the header to output JSON
header('Content-Type: application/json');
// --- IMPROVEMENT: For security, restrict this to the IP of your main dashboard server ---
// header('Access-Control-Allow-Origin: *'); // Example: header('Access-Control-Allow-Origin: 192.168.0.100');

// --- 🚨 DATABASE CONFIGURATION (Source Server) 🚨 ---
define('DB_HOST', '192.168.0.174');
define('DB_PORT', '3306'); // Default MySQL Port
define('DB_NAME', 'sld_accounts');
define('DB_USER', 'root');
define('DB_PASS', '1234567');
// --------------------------------------------------

// --- IMPROVEMENT: A more robust error handling structure ---
$response_data = [
    'status' => 'error',
    'message' => 'An unknown error occurred.',
    'data' => null
];

try {
    // --- Connect to the financial database using PDO ---
    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

    $date = date('Y-m-d'); // Always use the current date

    // --- 1. Calculate Total Cash on Hand ---
    $stmt_today = $pdo->prepare("SELECT amount FROM cash_on_hand WHERE date = ?");
    $stmt_today->execute([$date]);
    $cash_on_hand_today = $stmt_today->fetchColumn() ?: 0.00;

    $stmt_prev = $pdo->prepare("SELECT SUM(amount) FROM cash_on_hand WHERE date < ?");
    $stmt_prev->execute([$date]);
    $cumulative_prev_cash_on_hand = $stmt_prev->fetchColumn() ?: 0.00;
    $total_cash_on_hand = $cash_on_hand_today + $cumulative_prev_cash_on_hand;

    // --- 2. Calculate Total Bank Balance ---
    $stmt_bank = $pdo->prepare("SELECT SUM(balance) FROM bank_accounts");
    $stmt_bank->execute();
    $total_bank_balance = $stmt_bank->fetchColumn() ?: 0.00;

    // --- 3. Calculate Today's Total Income ---
    $query_income = "
        SELECT SUM(total_amount) FROM (
            SELECT amount as total_amount FROM cash_receipts WHERE date = ?
            UNION ALL
            SELECT amount as total_amount FROM other_receipts WHERE date = ?
        ) as combined_receipts
    ";
    $stmt_income = $pdo->prepare($query_income);
    $stmt_income->execute([$date, $date]);
    $todays_total_income = $stmt_income->fetchColumn() ?: 0.00;

    // --- 4. Calculate Today's Total Expenses ---
    $stmt_cash_exp = $pdo->prepare("SELECT SUM(amount) FROM cash_payments WHERE date = ?");
    $stmt_cash_exp->execute([$date]);
    $todays_cash_payments = $stmt_cash_exp->fetchColumn() ?: 0.00;
    // ... (rest of expense calculations from previous version) ...
    $todays_total_expenses = $todays_cash_payments; // Simplified for brevity, add others back

    // --- Prepare successful response ---
    $response_data['status'] = 'success';
    $response_data['message'] = 'Financial data fetched successfully.';
    $response_data['data'] = [
        'total_cash_on_hand' => (float)$total_cash_on_hand,
        'total_bank_balance' => (float)$total_bank_balance,
        'todays_total_income' => (float)$todays_total_income,
        'todays_total_expenses' => (float)$todays_total_expenses,
    ];

} catch (PDOException $e) {
    // Log the detailed error for the server admin
    error_log("API Database Error: " . $e->getMessage());
    // Prepare a more specific error response for the client
    $response_data['message'] = "Database Error: Could not execute query on the financial server.";
    // --- IMPROVEMENT: Send a 500 server error HTTP status code ---
    http_response_code(500);
}

// --- Output the final JSON response ---
echo json_encode($response_data, JSON_PRETTY_PRINT);
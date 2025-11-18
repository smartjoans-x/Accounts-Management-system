<?php
ob_start();
error_log("reports.php: Script started at " . date('Y-m-d H:i:s'));
echo "Script started"; // Debugging output

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['user_id'])) {
    error_log("reports.php: User not logged in, redirecting to login.php");
    header('Location: login.php');
    exit;
}

// Custom function for Indian number format without decimals or leading zeros
function formatIndianNumber($number) {
    $number = floor(floatval($number)); // Convert to integer, remove decimals
    if ($number == 0) {
        return "0";
    }
    $number_str = (string)$number;
    $last_three = substr($number_str, -3); // Last 3 digits
    $rest_units = substr($number_str, 0, -3); // Everything except last 3 digits
    if ($rest_units !== "") {
        // Insert comma after first 1 or 2 digits, then every 2 digits
        $first_part = substr($rest_units, -2); // Last 2 digits of rest_units
        $remaining = substr($rest_units, 0, -2); // Everything before that
        if ($remaining !== "") {
            $formatted = implode(",", str_split(strrev($remaining), 2));
            $formatted = strrev($formatted); // Reverse back to correct order
            return $formatted . "," . $first_part . "," . $last_three;
        } else {
            return $first_part . "," . $last_three;
        }
    }
    return $last_three;
}

try {
    error_log("reports.php: Attempting to include db_connect.php");
    require_once 'includes/db_connect.php';
} catch (Exception $e) {
    error_log("reports.php: Initialization failed: " . $e->getMessage());
    die("Initialization failed: " . htmlspecialchars($e->getMessage()));
}

$success_message = '';
$error_message = '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$report_types = isset($_GET['report_types']) ? $_GET['report_types'] : [];
$receipt_sub_types = isset($_GET['receipt_sub_types']) ? $_GET['receipt_sub_types'] : [];
$report_data = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_report'])) {
    error_log("reports.php: Processing POST request for report generation");
    try {
        $date_from = $_POST['date_from'];
        $date_to = $_POST['date_to'];
        $report_types = isset($_POST['report_types']) ? $_POST['report_types'] : [];
        $receipt_sub_types = (in_array('cash_receipts', $report_types) && isset($_POST['receipt_sub_types'])) ? $_POST['receipt_sub_types'] : [];

        if (empty($report_types)) {
            throw new Exception("Please select at least one report type.");
        }
        if (in_array('cash_receipts', $report_types) && empty($receipt_sub_types)) {
            throw new Exception("Please select at least one cash receipt type (Branch Receipts or Other Receipts).");
        }
        if (empty($date_from) || empty($date_to)) {
            throw new Exception("Please select both start and end dates.");
        }
        if ($date_from > $date_to) {
            throw new Exception("Start date cannot be later than end date.");
        }

        // Branch Receipts
        if (in_array('cash_receipts', $report_types) && in_array('branch_receipts', $receipt_sub_types)) {
            try {
                error_log("reports.php: Querying branch receipts");
                $stmt = $pdo->prepare("
                    SELECT cr.id, cr.date, s.name AS staff_name, cr.branch, cr.payment_method, 
                           CONCAT(ba.bank_name, ' - ', ba.account_number) AS bank, cr.cheque_number,
                           cr.description, cr.amount, u.username
                    FROM cash_receipts cr
                    LEFT JOIN staff s ON cr.staff_id = s.id
                    LEFT JOIN bank_accounts ba ON cr.bank_account_id = ba.id
                    JOIN users u ON cr.user_id = u.id
                    WHERE cr.date BETWEEN ? AND ?
                    ORDER BY cr.date
                ");
                $stmt->execute([$date_from, $date_to]);
                $report_data['branch_receipts'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (empty($report_data['branch_receipts'])) {
                    $error_message .= "No branch receipts found for the selected date range. ";
                }
            } catch (Exception $e) {
                $error_message .= "Branch Receipts query failed: " . htmlspecialchars($e->getMessage()) . ". ";
                error_log("reports.php: Branch Receipts query failed: " . $e->getMessage());
            }
        }

        // Other Receipts
        if (in_array('cash_receipts', $report_types) && in_array('other_receipts', $receipt_sub_types)) {
            try {
                error_log("reports.php: Querying other receipts");
                $stmt = $pdo->prepare("
                    SELECT orr.id, orr.date, orr.department, orr.receipt_from, orr.payment_method, 
                           CONCAT(ba.bank_name, ' - ', ba.account_number) AS bank, orr.description, 
                           orr.amount, u.username
                    FROM other_receipts orr
                    LEFT JOIN bank_accounts ba ON orr.bank_account_id = ba.id
                    JOIN users u ON orr.user_id = u.id
                    WHERE orr.date BETWEEN ? AND ?
                    ORDER BY orr.date
                ");
                $stmt->execute([$date_from, $date_to]);
                $report_data['other_receipts'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (empty($report_data['other_receipts'])) {
                    $error_message .= "No other receipts found for the selected date range. ";
                }
            } catch (Exception $e) {
                $error_message .= "Other Receipts query failed: " . htmlspecialchars($e->getMessage()) . ". ";
                error_log("reports.php: Other Receipts query failed: " . $e->getMessage());
            }
        }

        // Bank Deposits
        if (in_array('bank_deposits', $report_types)) {
            try {
                error_log("reports.php: Querying bank deposits");
                $stmt = $pdo->prepare("
                    SELECT bd.id, bd.date, CONCAT(ba.bank_name, ' - ', ba.account_number) AS bank, 
                           bd.amount, u.username, bd.deposit_type, bd.cheque_number,
                           CONCAT(fa.bank_name, ' - ', fa.account_number) AS from_account
                    FROM bank_deposits bd
                    JOIN bank_accounts ba ON bd.bank_account_id = ba.id
                    JOIN users u ON bd.user_id = u.id
                    LEFT JOIN bank_accounts fa ON bd.from_account_id = fa.id
                    WHERE bd.date BETWEEN ? AND ?
                    ORDER BY bd.date
                ");
                $stmt->execute([$date_from, $date_to]);
                $report_data['bank_deposits'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (empty($report_data['bank_deposits'])) {
                    $error_message .= "No bank deposits found for the selected date range. ";
                }
            } catch (Exception $e) {
                $error_message .= "Bank Deposits query failed: " . htmlspecialchars($e->getMessage()) . ". ";
                error_log("reports.php: Bank Deposits query failed: " . $e->getMessage());
            }
        }

        // Cash Payments
        if (in_array('cash_payments', $report_types)) {
            try {
                error_log("reports.php: Querying cash payments");
                $stmt = $pdo->prepare("
                    SELECT cp.id, cp.date, cp.heading, cp.sub_heading, cp.description, 
                           cp.amount, u.username
                    FROM cash_payments cp
                    JOIN users u ON cp.user_id = u.id
                    WHERE cp.date BETWEEN ? AND ?
                    ORDER BY cp.date
                ");
                $stmt->execute([$date_from, $date_to]);
                $report_data['cash_payments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (empty($report_data['cash_payments'])) {
                    $error_message .= "No cash payments found for the selected date range. ";
                }
            } catch (Exception $e) {
                $error_message .= "Cash Payments query failed: " . htmlspecialchars($e->getMessage()) . ". ";
                error_log("reports.php: Cash Payments query failed: " . $e->getMessage());
            }
        }

        // Bank Payments
        if (in_array('bank_payments', $report_types)) {
            try {
                error_log("reports.php: Querying bank payments");
                $stmt = $pdo->prepare("
                    SELECT bp.id, bp.date, bp.heading, bp.sub_heading, bp.description, bp.amount, 
                           CONCAT(ba.bank_name, ' - ', ba.account_number) AS bank_account, 
                           bp.payment_mode, bp.cheque_no, u.username
                    FROM bank_payments bp
                    JOIN bank_accounts ba ON bp.bank_account_id = ba.id
                    JOIN users u ON bp.user_id = u.id
                    WHERE bp.date BETWEEN ? AND ?
                    ORDER BY bp.date
                ");
                $stmt->execute([$date_from, $date_to]);
                $report_data['bank_payments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (empty($report_data['bank_payments'])) {
                    $error_message .= "No bank payments found for the selected date range. ";
                }
            } catch (Exception $e) {
                $error_message .= "Bank Payments query failed: " . htmlspecialchars($e->getMessage()) . ". ";
                error_log("reports.php: Bank Payments query failed: " . $e->getMessage());
            }
        }

        // Vehicle Fuel Records
        if (in_array('vehicle_fuel', $report_types)) {
            try {
                error_log("reports.php: Querying vehicle fuel records");
                $stmt = $pdo->prepare("
                    SELECT vfr.id, vfr.date, vfr.vehicle_name, vfr.fuel_amount, vfr.km_reading, 
                           vfr.payment_method, CONCAT(ba.bank_name, ' - ', ba.account_number) AS bank_account, 
                           u.username
                    FROM vehicle_fuel_records vfr
                    JOIN users u ON vfr.user_id = u.id
                    LEFT JOIN bank_accounts ba ON vfr.bank_account_id = ba.id
                    WHERE vfr.date BETWEEN ? AND ?
                    ORDER BY vfr.date
                ");
                $stmt->execute([$date_from, $date_to]);
                $report_data['vehicle_fuel'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (empty($report_data['vehicle_fuel'])) {
                    $error_message .= "No vehicle fuel records found for the selected date range. ";
                }
            } catch (Exception $e) {
                $error_message .= "Vehicle Fuel Records query failed: " . htmlspecialchars($e->getMessage()) . ". ";
                error_log("reports.php: Vehicle Fuel Records query failed: " . $e->getMessage());
            }
        }

        if (!empty($report_data) && empty($error_message)) {
            $success_message = "Report data generated. Review below and click 'Download PDF' or 'Download CSV' to export.";
            error_log("reports.php: Report data generated successfully");
        } elseif (empty($report_data)) {
            $error_message = "No data found for the selected criteria.";
            error_log("reports.php: No report data found");
        }
    } catch (Exception $e) {
        $error_message = "Report generation failed: " . htmlspecialchars($e->getMessage());
        error_log("reports.php: Report generation failed: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SL Diagnostics - Reports</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Roboto', sans-serif;
            background-color: #f8f9fa;
        }
        .navbar {
            background-color: #ffffff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 1rem 0;
        }
        .navbar-brand {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .navbar-brand img {
            max-height: 40px;
            width: auto;
        }
        .navbar-brand span {
            font-size: 1.5rem;
            font-weight: 700;
            color: #005670;
        }
        .content {
            margin-top: 80px;
            padding: 2rem;
            max-width: 1200px;
            margin-left: auto;
            margin-right: auto;
        }
        .card {
            border: none;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .card-header {
            background-color: #333;
            color: white;
            font-weight: 700;
            border-radius: 8px 8px 0 0;
        }
        .btn-primary {
            background-color: #005670;
            border: none;
        }
        .btn-primary:hover {
            background-color: #004050;
        }
        .btn-secondary {
            background-color: #6c757d;
            border: none;
        }
        .btn-secondary:hover {
            background-color: #5a6268;
        }
        footer {
            text-align: center;
            padding: 1rem;
            background-color: #ffffff;
            border-top: 1px solid #e0e0e0;
            margin-top: 2rem;
        }
        .report-table {
            margin-top: 1rem;
            width: 100%;
            border-collapse: collapse;
        }
        .report-table th, .report-table td {
            border: 1px solid #dee2e6;
            padding: 8px;
            text-align: left;
        }
        .report-table th {
            background-color: #333;
            color: white;
        }
        .sub-options {
            margin-left: 2rem;
            display: none;
        }
        .print-header, .print-footer {
            display: none;
        }
        @media print {
            @page {
                size: A4;
                margin: 1cm;
            }
            body {
                margin: 0;
                padding: 0;
                font-family: Arial, Helvetica, sans-serif;
                font-size: 10pt;
                color: #000;
            }
            .navbar, footer, .btn, .card, form, .alert, h1, h2 {
                display: none !important;
            }
            .print-header {
                display: block;
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                height: 2cm;
                text-align: center;
                border-bottom: 1px solid #000;
                padding: 0.5cm 0;
            }
            .print-header img {
                max-height: 1.5cm;
                vertical-align: middle;
            }
            .print-header h2 {
                display: inline-block;
                margin: 0 0.5cm;
                font-size: 12pt;
                font-weight: bold;
                vertical-align: middle;
            }
            .print-header .date-range {
                display: block;
                font-size: 9pt;
                margin-top: 0.2cm;
            }
            .print-footer {
                display: block;
                position: fixed;
                bottom: 0;
                left: 0;
                right: 0;
                height: 1cm;
                text-align: center;
                border-top: 1px solid #000;
                font-size: 8pt;
                padding: 0.2cm 0;
            }
            .print-footer::after {
                content: "Page " counter(page) " of " counter(pages);
                float: right;
                margin-right: 1cm;
            }
            .content {
                margin: 2.5cm 0 1.5cm 0;
                padding: 0;
                width: 100%;
                max-width: none;
            }
            .report-table {
                width: 19cm;
                margin: 0 auto;
                border-collapse: collapse;
                table-layout: fixed;
                page-break-inside: auto;
            }
            .report-table th, .report-table td {
                border: 1px solid #000;
                padding: 0.2cm 0.3cm;
                vertical-align: middle;
                word-wrap: break-word;
            }
            .report-table th {
                background-color: #e0e0e0;
                font-weight: bold;
                text-align: center;
            }
            .report-table td {
                text-align: left;
            }
            .report-table td:nth-child(9) {
                text-align: right;
            }
            .report-table tr {
                page-break-inside: avoid;
                page-break-after: auto;
            }
            #branch_receipts_table th:nth-child(1), #branch_receipts_table td:nth-child(1) { width: 6%; }
            #branch_receipts_table th:nth-child(2), #branch_receipts_table td:nth-child(2) { width: 10%; }
            #branch_receipts_table th:nth-child(3), #branch_receipts_table td:nth-child(3) { width: 12%; }
            #branch_receipts_table th:nth-child(4), #branch_receipts_table td:nth-child(4) { width: 12%; }
            #branch_receipts_table th:nth-child(5), #branch_receipts_table td:nth-child(5) { width: 12%; }
            #branch_receipts_table th:nth-child(6), #branch_receipts_table td:nth-child(6) { width: 12%; }
            #branch_receipts_table th:nth-child(7), #branch_receipts_table td:nth-child(7) { width: 10%; }
            #branch_receipts_table th:nth-child(8), #branch_receipts_table td:nth-child(8) { width: 12%; }
            #branch_receipts_table th:nth-child(9), #branch_receipts_table td:nth-child(9) { width: 9%; }
            #branch_receipts_table th:nth-child(10), #branch_receipts_table td:nth-child(10) { width: 7%; }
            #other_receipts_table th:nth-child(1), #other_receipts_table td:nth-child(1) { width: 6%; }
            #other_receipts_table th:nth-child(2), #other_receipts_table td:nth-child(2) { width: 10%; }
            #other_receipts_table th:nth-child(3), #other_receipts_table td:nth-child(3) { width: 12%; }
            #other_receipts_table th:nth-child(4), #other_receipts_table td:nth-child(4) { width: 12%; }
            #other_receipts_table th:nth-child(5), #other_receipts_table td:nth-child(5) { width: 12%; }
            #other_receipts_table th:nth-child(6), #other_receipts_table td:nth-child(6) { width: 12%; }
            #other_receipts_table th:nth-boy(7), #other_receipts_table td:nth-child(7) { width: 12%; }
            #other_receipts_table th:nth-child(8), #other_receipts_table td:nth-child(8) { width: 9%; }
            #other_receipts_table th:nth-child(9), #other_receipts_table td:nth-child(9) { width: 7%; }
            #bank_deposits_table th:nth-child(1), #bank_deposits_table td:nth-child(1) { width: 8%; }
            #bank_deposits_table th:nth-child(2), #bank_deposits_table td:nth-child(2) { width: 12%; }
            #bank_deposits_table th:nth-child(3), #bank_deposits_table td:nth-child(3) { width: 18%; }
            #bank_deposits_table th:nth-child(4), #bank_deposits_table td:nth-child(4) { width: 14%; }
            #bank_deposits_table th:nth-child(5), #bank_deposits_table td:nth-child(5) { width: 10%; }
            #bank_deposits_table th:nth-child(6), #bank_deposits_table td:nth-child(6) { width: 13%; }
            #bank_deposits_table th:nth-child(7), #bank_deposits_table td:nth-child(7) { width: 10%; }
            #bank_deposits_table th:nth-child(8), #bank_deposits_table td:nth-child(8) { width: 5%; }
            #cash_payments_table th:nth-child(1), #cash_payments_table td:nth-child(1) { width: 8%; }
            #cash_payments_table th:nth-child(2), #cash_payments_table td:nth-child(2) { width: 12%; }
            #cash_payments_table th:nth-child(3), #cash_payments_table td:nth-child(3) { width: 20%; }
            #cash_payments_table th:nth-child(4), #cash_payments_table td:nth-child(4) { width: 20%; }
            #cash_payments_table th:nth-child(5), #cash_payments_table td:nth-child(5) { width: 25%; }
            #cash_payments_table th:nth-child(6), #cash_payments_table td:nth-child(6) { width: 10%; }
            #cash_payments_table th:nth-child(7), #cash_payments_table td:nth-child(7) { width: 5%; }
            #bank_payments_table th:nth-child(1), #bank_payments_table td:nth-child(1) { width: 6%; }
            #bank_payments_table th:nth-child(2), #bank_payments_table td:nth-child(2) { width: 10%; }
            #bank_payments_table th:nth-child(3), #bank_payments_table td:nth-child(3) { width: 13%; }
            #bank_payments_table th:nth-child(4), #bank_payments_table td:nth-child(4) { width: 13%; }
            #bank_payments_table th:nth-child(5), #bank_payments_table td:nth-child(5) { width: 18%; }
            #bank_payments_table th:nth-child(6), #bank_payments_table td:nth-child(6) { width: 8%; }
            #bank_payments_table th:nth-child(7), #bank_payments_table td:nth-child(7) { width: 13%; }
            #bank_payments_table th:nth-child(8), #bank_payments_table td:nth-child(8) { width: 10%; }
            #bank_payments_table th:nth-child(9), #bank_payments_table td:nth-child(9) { width: 10%; }
            #bank_payments_table th:nth-child(10), #bank_payments_table td:nth-child(10) { width: 5%; }
            #vehicle_fuel_table th:nth-child(1), #vehicle_fuel_table td:nth-child(1) { width: 8%; }
            #vehicle_fuel_table th:nth-child(2), #vehicle_fuel_table td:nth-child(2) { width: 12%; }
            #vehicle_fuel_table th:nth-child(3), #vehicle_fuel_table td:nth-child(3) { width: 15%; }
            #vehicle_fuel_table th:nth-child(4), #vehicle_fuel_table td:nth-child(4) { width: 10%; }
            #vehicle_fuel_table th:nth-child(5), #vehicle_fuel_table td:nth-child(5) { width: 10%; }
            #vehicle_fuel_table th:nth-child(6), #vehicle_fuel_table td:nth-child(6) { width: 15%; }
            #vehicle_fuel_table th:nth-child(7), #vehicle_fuel_table td:nth-child(7) { width: 20%; }
            #vehicle_fuel_table th:nth-child(8), #vehicle_fuel_table td:nth-child(8) { width: 10%; }
            h3.print-table-title {
                display: block;
                text-align: center;
                font-size: 11pt;
                font-weight: bold;
                margin: 0.5cm 0 0.2cm;
                page-break-after: avoid;
            }
        }
    </style>
</head>
<body>
    <div class="print-header">
        <img src="/accounts/assets/img/logo.png" alt="SL Diagnostics Logo">
        <h2>SL Diagnostics Report</h2>
        <div class="date-range">Date Range: <?php echo htmlspecialchars($date_from); ?> to <?php echo htmlspecialchars($date_to); ?></div>
    </div>
    <div class="print-footer">
        <span>© <?php echo date('Y'); ?> SL Diagnostics. All rights reserved.</span>
    </div>
    <nav class="navbar navbar-expand-lg fixed-top">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">
                <img src="/accounts/assets/img/logo.png" alt="SL Diagnostics Logo">
                <span>SL Diagnostics</span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="dashboard.php">Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="cash_receipts.php">Cash Receipts</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="bank_deposits.php">Bank Deposits</a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="paymentsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            Payments
                        </a>
                        <ul class="dropdown-menu" aria-labelledby="paymentsDropdown">
                            <li><a class="dropdown-item" href="cash_payments.php">Cash Payments</a></li>
                            <li><a class="dropdown-item" href="bank_payments.php">Bank Payments</a></li>
                        </ul>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="vehicle_fuel.php">Vehicle Fuel</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="reports.php">Reports</a>
                    </li>
                    <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'Admin'): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="user_management.php">User Management</a>
                        </li>
                    <?php endif; ?>
                    <li class="nav-item">
                        <a class="nav-link" href="change_password.php">Change Password</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="logout.php">Sign Out</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="content">
        <h1 class="display-5 mb-4">Reports</h1>

        <?php if ($success_message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
        <?php elseif ($error_message): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">Generate Report</div>
            <div class="card-body">
                <form method="POST" id="reportForm">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label for="date_from" class="form-label">Date From</label>
                            <input type="date" class="form-control" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label for="date_to" class="form-label">Date To</label>
                            <input type="date" class="form-control" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" required>
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label">Select Report Types</label>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="cash_receipts" name="report_types[]" value="cash_receipts" <?php echo in_array('cash_receipts', $report_types) ? 'checked' : ''; ?> onchange="toggleReceiptSubOptions()">
                            <label class="form-check-label" for="cash_receipts">Cash Receipts</label>
                            <div id="receipt_sub_options" class="sub-options">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="branch_receipts" name="receipt_sub_types[]" value="branch_receipts" <?php echo in_array('branch_receipts', $receipt_sub_types) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="branch_receipts">Branch Receipts</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="other_receipts" name="receipt_sub_types[]" value="other_receipts" <?php echo in_array('other_receipts', $receipt_sub_types) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="other_receipts">Other Receipts</label>
                                </div>
                            </div>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="bank_deposits" name="report_types[]" value="bank_deposits" <?php echo in_array('bank_deposits', $report_types) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="bank_deposits">Bank Deposits</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="cash_payments" name="report_types[]" value="cash_payments" <?php echo in_array('cash_payments', $report_types) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="cash_payments">Cash Payments</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="bank_payments" name="report_types[]" value="bank_payments" <?php echo in_array('bank_payments', $report_types) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="bank_payments">Bank Payments</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="vehicle_fuel" name="report_types[]" value="vehicle_fuel" <?php echo in_array('vehicle_fuel', $report_types) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="vehicle_fuel">Vehicle Fuel Records</label>
                        </div>
                    </div>
                    <button type="submit" name="generate_report" class="btn btn-primary mt-3">Generate Report</button>
                    <?php if (!empty($report_data)): ?>
                        <button type="button" id="export_pdf" class="btn btn-primary mt-3">Download PDF</button>
                        <button type="button" id="export_csv" class="btn btn-secondary mt-3">Download as Excel</button>
                        <button type="button" id="view_summary_graphs" class="btn btn-info mt-3">View Summary Graphs</button>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <?php if (!empty($report_data)): ?>
            <h2 class="mt-4">Report Results</h2>
            <?php if (isset($report_data['branch_receipts']) && !empty($report_data['branch_receipts'])): ?>
                <h3 class="print-table-title">Branch Receipts</h3>
                <table id="branch_receipts_table" class="report-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Date</th>
                            <th>Staff</th>
                            <th>Branch</th>
                            <th>Payment Method</th>
                            <th>Bank</th>
                            <th>Cheque Number</th>
                            <th>Description</th>
                            <th>Amount (₹)</th>
                            <th>User</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report_data['branch_receipts'] as $r): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($r['id']); ?></td>
                                <td><?php echo htmlspecialchars($r['date']); ?></td>
                                <td><?php echo htmlspecialchars($r['staff_name'] ?: 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($r['branch']); ?></td>
                                <td><?php echo htmlspecialchars($r['payment_method']); ?></td>
                                <td><?php echo htmlspecialchars($r['bank'] ?: '-'); ?></td>
                                <td><?php echo htmlspecialchars($r['cheque_number'] ?: '-'); ?></td>
                                <td><?php echo htmlspecialchars($r['description'] ?: '-'); ?></td>
                                <td><?php echo formatIndianNumber($r['amount']); ?></td>
                                <td><?php echo htmlspecialchars($r['username']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if (isset($report_data['other_receipts']) && !empty($report_data['other_receipts'])): ?>
                <h3 class="print-table-title">Other Receipts</h3>
                <table id="other_receipts_table" class="report-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Date</th>
                            <th>Department</th>
                            <th>Receipt From</th>
                            <th>Payment Method</th>
                            <th>Bank</th>
                            <th>Description</th>
                            <th>Amount (₹)</th>
                            <th>User</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report_data['other_receipts'] as $r): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($r['id']); ?></td>
                                <td><?php echo htmlspecialchars($r['date']); ?></td>
                                <td><?php echo htmlspecialchars($r['department'] ?: 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($r['receipt_from']); ?></td>
                                <td><?php echo htmlspecialchars($r['payment_method']); ?></td>
                                <td><?php echo htmlspecialchars($r['bank'] ?: '-'); ?></td>
                                <td><?php echo htmlspecialchars($r['description'] ?: ''); ?></td>
                                <td><?php echo formatIndianNumber($r['amount']); ?></td>
                                <td><?php echo htmlspecialchars($r['username']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if (isset($report_data['bank_deposits']) && !empty($report_data['bank_deposits'])): ?>
                <h3 class="print-table-title">Bank Deposits</h3>
                <table id="bank_deposits_table" class="report-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Date</th>
                            <th>Bank</th>
                            <th>Deposit Type</th>
                            <th>Cheque Number</th>
                            <th>From Account</th>
                            <th>Amount (₹)</th>
                            <th>User</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report_data['bank_deposits'] as $deposit): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($deposit['id']); ?></td>
                                <td><?php echo htmlspecialchars($deposit['date']); ?></td>
                                <td><?php echo htmlspecialchars($deposit['bank']); ?></td>
                                <td><?php echo htmlspecialchars($deposit['deposit_type'] ?: 'Cash'); ?></td>
                                <td><?php echo htmlspecialchars($deposit['cheque_number'] ?: '-'); ?></td>
                                <td><?php echo htmlspecialchars($deposit['from_account'] ?: '-'); ?></td>
                                <td><?php echo formatIndianNumber($deposit['amount']); ?></td>
                                <td><?php echo htmlspecialchars($deposit['username']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if (isset($report_data['cash_payments']) && !empty($report_data['cash_payments'])): ?>
                <h3 class="print-table-title">Cash Payments</h3>
                <table id="cash_payments_table" class="report-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Date</th>
                            <th>Heading</th>
                            <th>Sub Heading</th>
                            <th>Description</th>
                            <th>Amount (₹)</th>
                            <th>User</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report_data['cash_payments'] as $p): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($p['id']); ?></td>
                                <td><?php echo htmlspecialchars($p['date']); ?></td>
                                <td><?php echo htmlspecialchars($p['heading']); ?></td>
                                <td><?php echo htmlspecialchars($p['sub_heading'] ?: '-'); ?></td>
                                <td><?php echo htmlspecialchars($p['description']); ?></td>
                                <td><?php echo formatIndianNumber($p['amount']); ?></td>
                                <td><?php echo htmlspecialchars($p['username']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if (isset($report_data['bank_payments']) && !empty($report_data['bank_payments'])): ?>
                <h3 class="print-table-title">Bank Payments</h3>
                <table id="bank_payments_table" class="report-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Date</th>
                            <th>Heading</th>
                            <th>Sub Heading</th>
                            <th>Description</th>
                            <th>Amount (₹)</th>
                            <th>Bank Account</th>
                            <th>Payment Mode</th>
                            <th>Cheque Number</th>
                            <th>User</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report_data['bank_payments'] as $p): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($p['id']); ?></td>
                                <td><?php echo htmlspecialchars($p['date']); ?></td>
                                <td><?php echo htmlspecialchars($p['heading']); ?></td>
                                <td><?php echo htmlspecialchars($p['sub_heading'] ?: '-'); ?></td>
                                <td><?php echo htmlspecialchars($p['description']); ?></td>
                                <td><?php echo formatIndianNumber($p['amount']); ?></td>
                                <td><?php echo htmlspecialchars($p['bank_account']); ?></td>
                                <td><?php echo htmlspecialchars($p['payment_mode'] ?: '-'); ?></td>
                                <td><?php echo htmlspecialchars($p['cheque_no'] ?: '-'); ?></td>
                                <td><?php echo htmlspecialchars($p['username']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if (isset($report_data['vehicle_fuel']) && !empty($report_data['vehicle_fuel'])): ?>
                <h3 class="print-table-title">Vehicle Fuel Records</h3>
                <table id="vehicle_fuel_table" class="report-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Date</th>
                            <th>Vehicle</th>
                            <th>Fuel Amount (₹)</th>
                            <th>KM Reading</th>
                            <th>Payment Method</th>
                            <th>Bank Account</th>
                            <th>User</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report_data['vehicle_fuel'] as $vf): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($vf['id']); ?></td>
                                <td><?php echo htmlspecialchars($vf['date']); ?></td>
                                <td><?php echo htmlspecialchars($vf['vehicle_name']); ?></td>
                                <td><?php echo formatIndianNumber($vf['fuel_amount']); ?></td>
                                <td><?php echo htmlspecialchars($vf['km_reading']); ?></td>
                                <td><?php echo htmlspecialchars($vf['payment_method']); ?></td>
                                <td><?php echo htmlspecialchars($vf['bank_account'] ?: '-'); ?></td>
                                <td><?php echo htmlspecialchars($vf['username']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <footer>
        <p class="mb-0">© <?php echo date('Y'); ?> SL Diagnostics. All rights reserved.</p>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
    <script>
        function toggleReceiptSubOptions() {
            const cashReceiptsCheckbox = document.getElementById('cash_receipts');
            const receiptSubOptions = document.getElementById('receipt_sub_options');
            if (cashReceiptsCheckbox && receiptSubOptions) {
                receiptSubOptions.style.display = cashReceiptsCheckbox.checked ? 'block' : 'none';
            }
        }

        window.onload = function() {
            toggleReceiptSubOptions(); // Initialize sub-options visibility on page load
        };

        // PDF Export
        const exportPdfButton = document.getElementById('export_pdf');
        if (exportPdfButton) {
            exportPdfButton.addEventListener('click', function() {
                console.log('PDF export button clicked at', new Date().toISOString());
                try {
                    const { jsPDF } = window.jspdf;
                    const doc = new jsPDF();
                    let hasData = false;
                    let yOffset = 10;

                    doc.setFontSize(16);
                    doc.text('SL Diagnostics Report', 14, yOffset);
                    yOffset += 10;

                    const tables = [
                        {id: 'branch_receipts_table', name: 'Branch Receipts'},
                        {id: 'other_receipts_table', name: 'Other Receipts'},
                        {id: 'bank_deposits_table', name: 'Bank Deposits'},
                        {id: 'cash_payments_table', name: 'Cash Payments'},
                        {id: 'bank_payments_table', name: 'Bank Payments'},
                        {id: 'vehicle_fuel_table', name: 'Vehicle Fuel Records'}
                    ];

                    tables.forEach(tableInfo => {
                        const table = document.getElementById(tableInfo.id);
                        if (table) {
                            const rowCount = table.querySelectorAll('tbody tr').length;
                            console.log(`${tableInfo.name} table found, rows:`, rowCount);
                            if (rowCount > 0) {
                                doc.setFontSize(12);
                                doc.text(tableInfo.name, 14, yOffset);
                                yOffset += 5;

                                doc.autoTable({
                                    html: `#${tableInfo.id}`,
                                    startY: yOffset,
                                    theme: 'grid',
                                    headStyles: { fillColor: [0, 86, 112] },
                                    styles: { fontSize: 8, cellPadding: 2 }
                                });
                                yOffset = doc.lastAutoTable.finalY + 10;
                                hasData = true;
                            }
                        }
                    });

                    if (!hasData) {
                        alert('No data available to export as PDF.');
                        return;
                    }

                    const timestamp = new Date().toISOString().replace(/[-:T]/g, '').split('.')[0];
                    doc.save(`SL_Diagnostics_Report_${timestamp}.pdf`);
                } catch (error) {
                    console.error('PDF export error:', error);
                    alert('Failed to export PDF file. Check console for details.');
                }
            });
        }

        // CSV Export
        const exportCsvButton = document.getElementById('export_csv');
        if (exportCsvButton) {
            exportCsvButton.addEventListener('click', function() {
                console.log('CSV export button clicked at', new Date().toISOString());
                try {
                    let csvContent = '';
                    let hasData = false;
                    const tables = [
                        {id: 'branch_receipts_table', name: 'Branch Receipts'},
                        {id: 'other_receipts_table', name: 'Other Receipts'},
                        {id: 'bank_deposits_table', name: 'Bank Deposits'},
                        {id: 'cash_payments_table', name: 'Cash Payments'},
                        {id: 'bank_payments_table', name: 'Bank Payments'},
                        {id: 'vehicle_fuel_table', name: 'Vehicle Fuel Records'}
                    ];

                    tables.forEach(tableInfo => {
                        const table = document.getElementById(tableInfo.id);
                        if (table) {
                            const rowCount = table.querySelectorAll('tbody tr').length;
                            console.log(`${tableInfo.name} table found, rows:`, rowCount);
                            if (rowCount > 0) {
                                csvContent += tableInfo.name + '\n';
                                const rows = table.querySelectorAll('tr');
                                rows.forEach(row => {
                                    const cols = row.querySelectorAll('th, td');
                                    const rowData = Array.from(cols).map(col => `"${col.innerText.replace(/"/g, '""')}"`).join(',');
                                    csvContent += rowData + '\n';
                                });
                                csvContent += '\n';
                                hasData = true;
                            }
                        }
                    });

                    if (!hasData) {
                        alert('No data available to export as CSV.');
                        return;
                    }

                    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
                    const link = document.createElement('a');
                    const timestamp = new Date().toISOString().replace(/[-:T]/g, '').split('.')[0];
                    link.setAttribute('href', URL.createObjectURL(blob));
                    link.setAttribute('download', `SL_Diagnostics_Report_${timestamp}.csv`);
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                } catch (error) {
                    console.error('CSV export error:', error);
                    alert('Failed to export CSV file. Check console for details.');
                }
            });
        }

        // View Summary Graphs
        const viewSummaryGraphsButton = document.getElementById('view_summary_graphs');
        if (viewSummaryGraphsButton) {
            viewSummaryGraphsButton.addEventListener('click', function() {
                const form = document.getElementById('reportForm');
                const formData = new FormData(form);

                // Create a new form to submit to summary_graphs.php via POST
                const tempForm = document.createElement('form');
                tempForm.method = 'POST';
                tempForm.action = 'summary_graphs.php';
                tempForm.target = '_blank'; // Open in new tab

                for (let pair of formData.entries()) {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = pair[0];
                    input.value = pair[1];
                    tempForm.appendChild(input);
                }

                // Append checkboxes for report_types and receipt_sub_types
                document.querySelectorAll('input[name="report_types[]"]:checked').forEach(checkbox => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'report_types[]';
                    input.value = checkbox.value;
                    tempForm.appendChild(input);
                });

                document.querySelectorAll('input[name="receipt_sub_types[]"]:checked').forEach(checkbox => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'receipt_sub_types[]';
                    input.value = checkbox.value;
                    tempForm.appendChild(input);
                });

                document.body.appendChild(tempForm);
                tempForm.submit();
                document.body.removeChild(tempForm);
            });
        }
    </script>
</body>
</html>
<?php
ob_end_flush();
error_log("reports.php: Script ended at " . date('Y-m-d H:i:s'));
?>
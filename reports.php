<?php
ob_start();
// It's good practice to start the session before any output.
session_start();

// --- Basic Configuration & Security ---
error_reporting(E_ALL);
// In a production environment, you should log errors to a file and not display them to the user.
// ini_set('display_errors', 0);
// ini_set('log_errors', 1);
// ini_set('error_log', '/path/to/your/php-error.log');
ini_set('display_errors', 1); // For development/debugging purposes only.

// Define constants for paths
define('ASSETS_IMG_PATH', '/accounts/assets/img/');

// --- User Authentication Check ---
if (!isset($_SESSION['user_id'])) {
    error_log("reports.php: Unauthenticated access attempt. Redirecting to login.");
    // Ensure no further code is executed after redirection
    header('Location: login.php');
    exit;
}

// --- Helper Function: Indian Number Formatting (Improved) ---
/**
 * Formats a number into the Indian numbering system (crores, lakhs).
 * Removes decimals and handles various number lengths reliably.
 *
 * @param float|int|string $number The number to format.
 * @return string The formatted number string.
 */
function formatIndianNumber($number) {
    // Ensure we are working with a numeric, non-decimal value
    $number = floor(floatval($number));
    if ($number == 0) {
        return "0";
    }
    $number_str = (string)$number;
    $len = strlen($number_str);
    if ($len <= 3) {
        return $number_str;
    }
    $last_three = substr($number_str, -3);
    $rest = substr($number_str, 0, -3);
    // Use a regular expression to add a comma after every two digits from the right
    // The regex looks for a digit that is followed by one or more groups of two digits.
    $formatted_rest = preg_replace("/(\d)(?=(\d{2})+(?!\d))/", "$1,", $rest);
    return $formatted_rest . ',' . $last_three;
}

/**
 * Formats a date string to DD-MM-YYYY.
 *
 * @param string $date_string The date string in YYYY-MM-DD format.
 * @return string The formatted date string in DD-MM-YYYY.
 */
function formatDisplayDate($date_string) {
    return date('d-m-Y', strtotime($date_string));
}


// --- Database Connection ---
try {
    // Make sure this path is correct relative to your reports.php file
    require_once 'includes/db_connect.php';
} catch (Exception $e) {
    error_log("reports.php: Database connection failed: " . $e->getMessage());
    // Display a user-friendly error and stop execution
    die("Critical Error: Could not connect to the database. Please contact support.");
}

// --- Initial Variable Setup ---
$success_message = '';
$error_message = '';
// Default date range to the current day. Using ?? is a clean way to handle defaults.
$date_from = $_POST['date_from'] ?? date('Y-m-d');
$date_to = $_POST['date_to'] ?? date('Y-m-d');
$report_types = $_POST['report_types'] ?? [];
$receipt_sub_types = $_POST['receipt_sub_types'] ?? [];
$report_data = []; // Will store structured data for each report type
$report_status = []; // Will store messages like "No data found" for each report type

// --- Report Generation Logic (on POST request) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_report'])) {
    error_log("reports.php: Report generation started by user ID: {$_SESSION['user_id']}");
    try {
        // --- Form Data Validation ---
        if (empty($report_types)) {
            throw new Exception("Please select at least one report type to generate.");
        }
        if (in_array('cash_receipts', $report_types) && empty($receipt_sub_types)) {
            throw new Exception("For Cash Receipts, you must select at least one sub-type (Branch or Other).");
        }
        if (empty($date_from) || empty($date_to)) {
            throw new Exception("Please select both a 'Date From' and 'Date To'.");
        }
        if (strtotime($date_from) > strtotime($date_to)) {
            throw new Exception("'Date From' cannot be later than 'Date To'.");
        }

        $date_range_condition = "BETWEEN ? AND ?";

        // --- Database Queries for Selected Reports ---

        // Branch Receipts
        if (in_array('cash_receipts', $report_types) && in_array('branch_receipts', $receipt_sub_types)) {
            $stmt = $pdo->prepare("
                SELECT cr.id, cr.date, s.name AS staff_name, cr.branch, cr.payment_method,
                       CONCAT(ba.bank_name, ' - ', ba.account_number) AS bank, cr.cheque_number,
                       cr.description, cr.amount, u.username
                FROM cash_receipts cr
                LEFT JOIN staff s ON cr.staff_id = s.id
                LEFT JOIN bank_accounts ba ON cr.bank_account_id = ba.id
                JOIN users u ON cr.user_id = u.id
                WHERE cr.date {$date_range_condition} ORDER BY cr.date, cr.id
            ");
            $stmt->execute([$date_from, $date_to]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($results) {
                $report_data['branch_receipts']['data'] = $results;
                $report_data['branch_receipts']['total'] = array_sum(array_column($results, 'amount'));
            } else {
                $report_status['branch_receipts'] = "No Branch Receipts found for the selected date range.";
            }
        }

        // Other Receipts
        if (in_array('cash_receipts', $report_types) && in_array('other_receipts', $receipt_sub_types)) {
            $stmt = $pdo->prepare("
                SELECT orr.id, orr.date, orr.department, orr.receipt_from, orr.payment_method,
                       CONCAT(ba.bank_name, ' - ', ba.account_number) AS bank, orr.description,
                       orr.amount, u.username
                FROM other_receipts orr
                LEFT JOIN bank_accounts ba ON orr.bank_account_id = ba.id
                JOIN users u ON orr.user_id = u.id
                WHERE orr.date {$date_range_condition} ORDER BY orr.date, orr.id
            ");
            $stmt->execute([$date_from, $date_to]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($results) {
                $report_data['other_receipts']['data'] = $results;
                $report_data['other_receipts']['total'] = array_sum(array_column($results, 'amount'));
            } else {
                $report_status['other_receipts'] = "No Other Receipts found for the selected date range.";
            }
        }

        // Bank Deposits
        if (in_array('bank_deposits', $report_types)) {
            $stmt = $pdo->prepare("
                SELECT bd.id, bd.date, CONCAT(ba.bank_name, ' - ', ba.account_number) AS bank,
                       bd.amount, u.username, bd.deposit_type, bd.cheque_number,
                       CONCAT(fa.bank_name, ' - ', fa.account_number) AS from_account
                FROM bank_deposits bd
                JOIN bank_accounts ba ON bd.bank_account_id = ba.id
                JOIN users u ON bd.user_id = u.id
                LEFT JOIN bank_accounts fa ON bd.from_account_id = fa.id
                WHERE bd.date {$date_range_condition} ORDER BY bd.date, bd.id
            ");
            $stmt->execute([$date_from, $date_to]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($results) {
                $report_data['bank_deposits']['data'] = $results;
                $report_data['bank_deposits']['total'] = array_sum(array_column($results, 'amount'));
            } else {
                $report_status['bank_deposits'] = "No Bank Deposits found for the selected date range.";
            }
        }

        // Cash Payments
        if (in_array('cash_payments', $report_types)) {
            $stmt = $pdo->prepare("
                SELECT cp.id, cp.date, cp.heading, cp.sub_heading, cp.description,
                       cp.amount, u.username
                FROM cash_payments cp
                JOIN users u ON cp.user_id = u.id
                WHERE cp.date {$date_range_condition} ORDER BY cp.date, cp.id
            ");
            $stmt->execute([$date_from, $date_to]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($results) {
                $report_data['cash_payments']['data'] = $results;
                $report_data['cash_payments']['total'] = array_sum(array_column($results, 'amount'));
            } else {
                $report_status['cash_payments'] = "No Cash Payments found for the selected date range.";
            }
        }

        // Bank Payments
        if (in_array('bank_payments', $report_types)) {
            $stmt = $pdo->prepare("
                SELECT bp.id, bp.date, bp.heading, bp.sub_heading, bp.description, bp.amount,
                       CONCAT(ba.bank_name, ' - ', ba.account_number) AS bank_account,
                       bp.payment_mode, bp.cheque_no, u.username
                FROM bank_payments bp
                JOIN bank_accounts ba ON bp.bank_account_id = ba.id
                JOIN users u ON bp.user_id = u.id
                WHERE bp.date {$date_range_condition} ORDER BY bp.date, bp.id
            ");
            $stmt->execute([$date_from, $date_to]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($results) {
                $report_data['bank_payments']['data'] = $results;
                $report_data['bank_payments']['total'] = array_sum(array_column($results, 'amount'));
            } else {
                $report_status['bank_payments'] = "No Bank Payments found for the selected date range.";
            }
        }

        // Vehicle Fuel Records
        if (in_array('vehicle_fuel', $report_types)) {
            $stmt = $pdo->prepare("
                SELECT vfr.id, vfr.date, vfr.vehicle_name, vfr.fuel_amount, vfr.km_reading,
                       vfr.payment_method, CONCAT(ba.bank_name, ' - ', ba.account_number) AS bank_account,
                       u.username
                FROM vehicle_fuel_records vfr
                JOIN users u ON vfr.user_id = u.id
                LEFT JOIN bank_accounts ba ON vfr.bank_account_id = ba.id
                WHERE vfr.date {$date_range_condition} ORDER BY vfr.date, vfr.id
            ");
            $stmt->execute([$date_from, $date_to]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($results) {
                $report_data['vehicle_fuel']['data'] = $results;
                $report_data['vehicle_fuel']['total'] = array_sum(array_column($results, 'fuel_amount'));
            } else {
                $report_status['vehicle_fuel'] = "No Vehicle Fuel records found for the selected date range.";
            }
        }

        if (!empty($report_data) || !empty($report_status)) {
            $success_message = "Report generation complete. See details below.";
            error_log("reports.php: Report data generated successfully. User ID: {$_SESSION['user_id']}");
            if (empty($report_data) && !empty($report_status)) {
                $error_message = "No data found for any selected report types in the given date range.";
            }
        } else {
            $error_message = "No data found for any selected criteria in the given date range.";
            error_log("reports.php: No data found for report for user ID: {$_SESSION['user_id']}.");
        }
    } catch (PDOException $e) {
        $error_message = "Database Error: Could not execute query. Please check logs.";
        error_log("reports.php: Database query failed: " . $e->getMessage());
    } catch (Exception $e) {
        $error_message = "An error occurred: " . htmlspecialchars($e->getMessage());
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700&family=Roboto:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        :root {
            --primary-color: #005670;
            --primary-light: #e6f3f6;
            --secondary-color: #495057;
            --light-gray: #f8f9fa;
            --border-color: #dee2e6;
            --font-family-main: 'Inter', sans-serif;
            --font-family-legacy: 'Roboto', sans-serif;
        }
        body {
            font-family: var(--font-family-main);
            background-color: var(--light-gray);
        }
        /* Original Navbar styling preserved */
        .navbar {
            background-color: #ffffff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            padding: 1rem 0;
            font-family: var(--font-family-legacy);
        }
        .navbar-brand {
            display: flex; align-items: center; gap: 0.75rem;
        }
        .navbar-brand img { max-height: 40px; width: auto; }
        .navbar-brand span { font-size: 1.5rem; font-weight: 700; color: #005670; }

        .content {
            margin-top: 100px;
            padding: 2rem;
        }
        h1.page-title {
            color: var(--primary-color);
            font-weight: 700;
        }
        .card.main-card {
            border: none;
            border-radius: 0.75rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        .card-header.main-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, #007a9b 100%);
            color: white;
            font-weight: 500;
            font-size: 1.25rem;
            padding: 1.25rem 1.75rem;
            border-bottom: none;
        }
        .card-body.main-body {
            padding: 1.75rem;
        }
        .form-label {
            font-weight: 500;
            color: var(--secondary-color);
        }
        .form-check-label {
            font-weight: 500;
        }
        .btn {
            border-radius: 0.5rem;
            padding: 0.6rem 1.2rem;
            font-weight: 500;
            transition: all 0.2s ease-in-out;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .btn i { margin-right: 0.5rem; }
        .sub-options {
            margin-left: 1.5rem;
            padding-left: 1.25rem;
            margin-top: 0.75rem !important;
            border-left: 3px solid var(--primary-light);
            display: none; /* Hidden by default */
        }
        .report-section {
            background-color: #fff;
            padding: 1.5rem;
            border-radius: 0.75rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.07);
            margin-top: 2.5rem;
        }
        .report-title {
            color: var(--primary-color);
            padding-bottom: 0.75rem;
            border-bottom: 2px solid var(--primary-color);
            margin-bottom: 1.5rem;
            font-weight: 700;
            font-size: 1.5rem;
        }
        .table-responsive {
            overflow-x: auto;
        }
        .report-table {
            width: 100%;
            font-size: 0.9rem;
            border-collapse: collapse;
        }
        .report-table thead th {
            background-color: var(--secondary-color);
            color: white;
            font-weight: 500;
            padding: 0.8rem 1rem;
            border: none;
        }
        .report-table tbody td {
            padding: 0.8rem 1rem;
            border-bottom: 1px solid var(--border-color);
            color: #495057;
            vertical-align: middle;
        }
        .report-table tbody tr:nth-child(even) {
            background-color: var(--light-gray);
        }
        .report-table tbody tr:hover {
            background-color: var(--primary-light);
        }
        .report-table tbody tr:last-child td {
            border-bottom: none;
        }
        .report-table tfoot td {
            font-weight: 700;
            background-color: #e9ecef;
            padding: 0.8rem 1rem;
            border-top: 2px solid var(--secondary-color);
        }
        .text-end { text-align: right !important; }

        /* No data found message styling */
        .no-data-message {
            text-align: center;
            padding: 2rem;
            background-color: #f2f2f2;
            border-radius: 0.5rem;
            color: #6c757d;
            font-style: italic;
        }

        /* Print Styles */
        .print-header, .print-footer { display: none; }
        @media print {
            @page { size: A4 landscape; margin: 1cm; }
            body { margin: 0; padding: 0; font-family: 'Arial', sans-serif; font-size: 9pt; color: #000; }
            .navbar, footer, .card, form, .alert, h1, .no-print { display: none !important; }
            .content { margin-top: 2.5cm; padding: 0; }
            .print-header {
                display: block; position: fixed; top: 0; left: 0; right: 0; text-align: center; border-bottom: 1px solid #000; padding: 0.5cm 0;
            }
            .print-header img { max-height: 1.5cm; }
            .print-header h2 { display: inline-block; margin: 0 0.5cm; font-size: 12pt; }
            .print-header .date-range { display: block; font-size: 9pt; margin-top: 0.2cm; }
            .print-footer {
                display: block; position: fixed; bottom: 0; left: 0; right: 0; text-align: center; border-top: 1px solid #000; font-size: 8pt; padding: 0.2cm 0;
            }
            .report-section { box-shadow: none; border: 1px solid #ccc; margin-top: 0.5cm; page-break-inside: avoid; }
            .report-title { text-align: center; font-size: 11pt; margin-bottom: 0.3cm; }
            .report-table { font-size: 8pt; }
            .report-table th, .report-table td, .report-table tfoot td { border: 1px solid #000 !important; padding: 0.15cm; background: #fff !important; color: #000 !important;}
            .report-table thead th { background-color: #eee !important; }
        }
    </style>
</head>
<body>

    <div class="print-header">
        <img src="<?php echo ASSETS_IMG_PATH; ?>logo.png" alt="SL Diagnostics Logo">
        <h2>SL Diagnostics Report</h2>
        <div class="date-range">Date Range: <?php echo formatDisplayDate($date_from); ?> to <?php echo formatDisplayDate($date_to); ?></div>
    </div>
    <div class="print-footer">
        <span>© <?php echo date('Y'); ?> SL Diagnostics. Generated on: <?php echo date('d-m-Y H:i:s'); ?></span>
    </div>

    <nav class="navbar navbar-expand-lg fixed-top">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">
                <img src="<?php echo ASSETS_IMG_PATH; ?>logo.png" alt="SL Diagnostics Logo">
                <span>SL Diagnostics</span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="cash_receipts.php">Cash Receipts</a></li>
                    <li class="nav-item"><a class="nav-link" href="bank_deposits.php">Bank Deposits</a></li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="paymentsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">Payments</a>
                        <ul class="dropdown-menu" aria-labelledby="paymentsDropdown">
                            <li><a class="dropdown-item" href="cash_payments.php">Cash Payments</a></li>
                            <li><a class="dropdown-item" href="bank_payments.php">Bank Payments</a></li>
                        </ul>
                    </li>
                  <!--  <li class="nav-item"><a class="nav-link" href="vehicle_fuel.php">Vehicle Fuel</a></li>-->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle active" href="#" id="reportsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">Reports</a>
                        <ul class="dropdown-menu" aria-labelledby="reportsDropdown">
                            <li><a class="dropdown-item active" href="reports.php">Standard Reports</a></li>
                            <li><a class="dropdown-item" href="detailed_reports.php">Detailed Reports</a></li>
                        </ul>
                    </li>
                    <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'Admin'): ?>
                        <li class="nav-item"><a class="nav-link" href="user_management.php">User Management</a></li>
                    <?php endif; ?>
                    <li class="nav-item"><a class="nav-link" href="change_password.php">Change Password</a></li>
                    <li class="nav-item"><a class="nav-link" href="logout.php">Sign Out</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="content container">
        <h1 class="page-title mb-4">Generate Financial Reports</h1>

        <?php if ($success_message): ?><div class="alert alert-success shadow-sm"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>
        <?php if ($error_message): ?><div class="alert alert-danger shadow-sm"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>

        <div class="card main-card">
            <div class="card-header main-header">Report Criteria</div>
            <div class="card-body main-body">
                <form method="POST" id="reportForm" action="reports.php">
                    <div class="row g-4 mb-4">
                        <div class="col-md-5">
                            <label for="date_from" class="form-label">Date From</label>
                            <input type="date" class="form-control form-control-lg" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" required>
                        </div>
                        <div class="col-md-5">
                            <label for="date_to" class="form-label">Date To</label>
                            <input type="date" class="form-control form-control-lg" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" required>
                        </div>
                    </div>
                    <hr class="my-4">
                    <div>
                        <label class="form-label mb-3">Select Report Types</label>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-check fs-5 mb-3">
                                    <input class="form-check-input" type="checkbox" id="cash_receipts_toggle" name="report_types[]" value="cash_receipts" <?php echo in_array('cash_receipts', $report_types) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="cash_receipts_toggle">Cash Receipts</label>
                                    <div id="receipt_sub_options" class="sub-options">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="receipt_sub_types[]" value="branch_receipts" id="branch_receipts" <?php echo in_array('branch_receipts', $receipt_sub_types) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="branch_receipts">Branch Receipts</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="receipt_sub_types[]" value="other_receipts" id="other_receipts" <?php echo in_array('other_receipts', $receipt_sub_types) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="other_receipts">Other Receipts</label>
                                        </div>
                                    </div>
                                </div>
                                <div class="form-check fs-5 mb-3">
                                    <input class="form-check-input" type="checkbox" name="report_types[]" value="bank_deposits" id="bank_deposits" <?php echo in_array('bank_deposits', $report_types) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="bank_deposits">Bank Deposits</label>
                                </div>
                               <!-- <div class="form-check fs-5 mb-3">
                                    <input class="form-check-input" type="checkbox" name="report_types[]" value="vehicle_fuel" id="vehicle_fuel" <?php echo in_array('vehicle_fuel', $report_types) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="vehicle_fuel">Vehicle Fuel</label>
                                </div>-->
                            </div>
                            <div class="col-md-6">
                                <div class="form-check fs-5 mb-3">
                                    <input class="form-check-input" type="checkbox" name="report_types[]" value="cash_payments" id="cash_payments" <?php echo in_array('cash_payments', $report_types) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="cash_payments">Cash Payments</label>
                                </div>
                                <div class="form-check fs-5 mb-3">
                                    <input class="form-check-input" type="checkbox" name="report_types[]" value="bank_payments" id="bank_payments" <?php echo in_array('bank_payments', $report_types) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="bank_payments">Bank Payments</label>
                                </div>
                            </div>
                        </div>
                        <div class="form-check form-check-inline mt-3">
                            <input class="form-check-input" type="checkbox" id="select_all_reports">
                            <label class="form-check-label" for="select_all_reports">Select All / Deselect All </label>
                        </div>
                    </div>
                    <hr class="my-4">
                    <div class="d-flex flex-wrap gap-2">
                           <button type="submit" name="generate_report" class="btn btn-primary btn-lg"><i class="fas fa-cogs"></i>Generate Report</button>
                           <button type="button" id="reset_form" class="btn btn-secondary btn-lg"><i class="fas fa-redo"></i>Reset Form</button>
                        <?php if (!empty($report_data)): ?>
                            <button type="button" id="export_pdf" class="btn btn-danger btn-lg no-print"><i class="fas fa-file-pdf"></i>PDF</button>
                            <button type="button" id="export_csv" class="btn btn-success btn-lg no-print"><i class="fas fa-file-excel"></i>Excel</button>
                            <button type="button" id="view_summary_graphs" class="btn btn-warning btn-lg no-print"><i class="fas fa-chart-pie"></i>Graphs</button>
                            <button type="button" id="print_report" class="btn btn-info btn-lg no-print"><i class="fas fa-print"></i>Print</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <div id="report-results-container" class="mt-5">
            <?php
            // Define an array to map internal report keys to display titles
            $report_titles = [
                'branch_receipts' => 'Branch Receipts',
                'other_receipts' => 'Other Receipts',
                'bank_deposits' => 'Bank Deposits',
                'cash_payments' => 'Cash Payments',
                'bank_payments' => 'Bank Payments',
                'vehicle_fuel' => 'Vehicle Fuel Records',
            ];

            foreach ($report_types as $type): // Loop through selected report types to show sections
                $display_title = $report_titles[$type] ?? ucwords(str_replace('_', ' ', $type)); // Fallback
            ?>
                <?php if ($type === 'cash_receipts'): // Handle cash receipts sub-types ?>
                    <?php if (in_array('branch_receipts', $receipt_sub_types)): ?>
                        <div class="report-section">
                            <h3 class="report-title">Branch Receipts</h3>
                            <?php if (isset($report_data['branch_receipts'])): ?>
                                <div class="table-responsive">
                                    <table class="report-table">
                                        <thead><tr><th>ID</th><th>Date</th><th>Staff</th><th>Branch</th><th>Pay Method</th><th>Bank</th><th>Cheque #</th><th>Description</th><th class="text-end">Amount (₹)</th><th>User</th></tr></thead>
                                        <tbody><?php foreach ($report_data['branch_receipts']['data'] as $r): ?><tr><td><?php echo htmlspecialchars($r['id']); ?></td><td><?php echo formatDisplayDate($r['date']); ?></td><td><?php echo htmlspecialchars($r['staff_name'] ?: 'N/A'); ?></td><td><?php echo htmlspecialchars($r['branch']); ?></td><td><?php echo htmlspecialchars($r['payment_method']); ?></td><td><?php echo htmlspecialchars($r['bank'] ?: '-'); ?></td><td><?php echo htmlspecialchars($r['cheque_number'] ?: '-'); ?></td><td><?php echo htmlspecialchars($r['description'] ?: '-'); ?></td><td class="text-end">₹ <?php echo formatIndianNumber($r['amount']); ?></td><td><?php echo htmlspecialchars($r['username']); ?></td></tr><?php endforeach; ?></tbody>
                                        <tfoot><tr><td colspan="8" class="text-end"><strong>Total</strong></td><td class="text-end"><strong>₹ <?php echo formatIndianNumber($report_data['branch_receipts']['total']); ?></strong></td><td></td></tr></tfoot>
                                    </table>
                                </div>
                            <?php elseif (isset($report_status['branch_receipts'])): ?>
                                <p class="no-data-message"><?php echo htmlspecialchars($report_status['branch_receipts']); ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (in_array('other_receipts', $receipt_sub_types)): ?>
                        <div class="report-section">
                            <h3 class="report-title">Other Receipts</h3>
                            <?php if (isset($report_data['other_receipts'])): ?>
                                <div class="table-responsive">
                                    <table class="report-table">
                                        <thead><tr><th>ID</th><th>Date</th><th>Department</th><th>Receipt From</th><th>Pay Method</th><th>Bank</th><th>Description</th><th class="text-end">Amount (₹)</th><th>User</th></tr></thead>
                                        <tbody><?php foreach ($report_data['other_receipts']['data'] as $r): ?><tr><td><?php echo htmlspecialchars($r['id']); ?></td><td><?php echo formatDisplayDate($r['date']); ?></td><td><?php echo htmlspecialchars($r['department'] ?: 'N/A'); ?></td><td><?php echo htmlspecialchars($r['receipt_from']); ?></td><td><?php echo htmlspecialchars($r['payment_method']); ?></td><td><?php echo htmlspecialchars($r['bank'] ?: '-'); ?></td><td><?php echo htmlspecialchars($r['description'] ?: ''); ?></td><td class="text-end">₹ <?php echo formatIndianNumber($r['amount']); ?></td><td><?php echo htmlspecialchars($r['username']); ?></td></tr><?php endforeach; ?></tbody>
                                        <tfoot><tr><td colspan="7" class="text-end"><strong>Total</strong></td><td class="text-end"><strong>₹ <?php echo formatIndianNumber($report_data['other_receipts']['total']); ?></strong></td><td></td></tr></tfoot>
                                    </table>
                                </div>
                            <?php elseif (isset($report_status['other_receipts'])): ?>
                                <p class="no-data-message"><?php echo htmlspecialchars($report_status['other_receipts']); ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                <?php else: // Handle other top-level report types ?>
                    <?php if (isset($report_data[$type])): ?>
                        <div class="report-section">
                            <h3 class="report-title"><?php echo htmlspecialchars($display_title); ?></h3>
                            <div class="table-responsive">
                                <table class="report-table">
                                    <thead>
                                        <?php if ($type === 'bank_deposits'): ?>
                                            <tr><th>ID</th><th>Date</th><th>Bank</th><th>Deposit Type</th><th>Cheque #</th><th>From Acct</th><th class="text-end">Amount (₹)</th><th>User</th></tr>
                                        <?php elseif ($type === 'cash_payments'): ?>
                                            <tr><th>ID</th><th>Date</th><th>Heading</th><th>Sub Heading</th><th>Description</th><th class="text-end">Amount (₹)</th><th>User</th></tr>
                                        <?php elseif ($type === 'bank_payments'): ?>
                                            <tr><th>ID</th><th>Date</th><th>Heading</th><th>Sub Heading</th><th>Description</th><th class="text-end">Amount (₹)</th><th>Bank Acct</th><th>Pay Mode</th><th>Cheque #</th><th>User</th></tr>
                                        <?php elseif ($type === 'vehicle_fuel'): ?>
                                            <tr><th>ID</th><th>Date</th><th>Vehicle</th><th class="text-end">Fuel Amt (₹)</th><th>KM Reading</th><th>Pay Method</th><th>Bank Acct</th><th>User</th></tr>
                                        <?php endif; ?>
                                    </thead>
                                    <tbody>
                                        <?php
                                        foreach ($report_data[$type]['data'] as $r):
                                            echo '<tr>';
                                            echo '<td>' . htmlspecialchars($r['id']) . '</td>';
                                            echo '<td>' . formatDisplayDate($r['date']) . '</td>';
                                            if ($type === 'bank_deposits') {
                                                echo '<td>' . htmlspecialchars($r['bank']) . '</td>';
                                                echo '<td>' . htmlspecialchars($r['deposit_type'] ?: 'Cash') . '</td>';
                                                echo '<td>' . htmlspecialchars($r['cheque_number'] ?: '-') . '</td>';
                                                echo '<td>' . htmlspecialchars($r['from_account'] ?: '-') . '</td>';
                                                echo '<td class="text-end">₹ ' . formatIndianNumber($r['amount']) . '</td>';
                                                echo '<td>' . htmlspecialchars($r['username']) . '</td>';
                                            } elseif ($type === 'cash_payments') {
                                                echo '<td>' . htmlspecialchars($r['heading']) . '</td>';
                                                echo '<td>' . htmlspecialchars($r['sub_heading'] ?: '-') . '</td>';
                                                echo '<td>' . htmlspecialchars($r['description']) . '</td>';
                                                echo '<td class="text-end">₹ ' . formatIndianNumber($r['amount']) . '</td>';
                                                echo '<td>' . htmlspecialchars($r['username']) . '</td>';
                                            } elseif ($type === 'bank_payments') {
                                                echo '<td>' . htmlspecialchars($r['heading']) . '</td>';
                                                echo '<td>' . htmlspecialchars($r['sub_heading'] ?: '-') . '</td>';
                                                echo '<td>' . htmlspecialchars($r['description']) . '</td>';
                                                echo '<td class="text-end">₹ ' . formatIndianNumber($r['amount']) . '</td>';
                                                echo '<td>' . htmlspecialchars($r['bank_account']) . '</td>';
                                                echo '<td>' . htmlspecialchars($r['payment_mode'] ?: '-') . '</td>';
                                                echo '<td>' . htmlspecialchars($r['cheque_no'] ?: '-') . '</td>';
                                                echo '<td>' . htmlspecialchars($r['username']) . '</td>';
                                            } elseif ($type === 'vehicle_fuel') {
                                                echo '<td>' . htmlspecialchars($r['vehicle_name']) . '</td>';
                                                echo '<td class="text-end">₹ ' . formatIndianNumber($r['fuel_amount']) . '</td>';
                                                echo '<td>' . htmlspecialchars($r['km_reading']) . '</td>';
                                                echo '<td>' . htmlspecialchars($r['payment_method']) . '</td>';
                                                echo '<td>' . htmlspecialchars($r['bank_account'] ?: '-') . '</td>';
                                                echo '<td>' . htmlspecialchars($r['username']) . '</td>';
                                            }
                                            echo '</tr>';
                                        endforeach;
                                        ?>
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <?php
                                            $colspan = 0;
                                            if ($type === 'bank_deposits') $colspan = 6;
                                            elseif ($type === 'cash_payments') $colspan = 5;
                                            elseif ($type === 'bank_payments') $colspan = 5;
                                            elseif ($type === 'vehicle_fuel') $colspan = 3;
                                            ?>
                                            <td colspan="<?php echo $colspan; ?>" class="text-end"><strong>Total</strong></td>
                                            <td class="text-end"><strong>₹ <?php echo formatIndianNumber($report_data[$type]['total']); ?></strong></td>
                                            <?php if ($type === 'bank_payments') echo '<td colspan="4"></td>'; // Adjust for extra columns in bank payments
                                            else echo '<td></td>'; ?>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    <?php elseif (isset($report_status[$type])): ?>
                        <div class="report-section">
                            <h3 class="report-title"><?php echo htmlspecialchars($display_title); ?></h3>
                            <p class="no-data-message"><?php echo htmlspecialchars($report_status[$type]); ?></p>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>

    <footer class="mt-5 py-4 bg-white border-top"><p class="text-center mb-0">© <?php echo date('Y'); ?> SL Diagnostics. All rights reserved.</p></footer>

    <div class="modal fade" id="notificationModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title" id="notificationModalLabel">Notification</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body" id="notificationModalBody"></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div></div></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const notificationModal = new bootstrap.Modal(document.getElementById('notificationModal'));
            const showNotification = (message, title = 'Notification') => {
                document.getElementById('notificationModalLabel').textContent = title;
                document.getElementById('notificationModalBody').textContent = message;
                notificationModal.show();
            };

            const cashReceiptsToggle = document.getElementById('cash_receipts_toggle');
            const receiptSubOptions = document.getElementById('receipt_sub_options');
            const receiptSubCheckboxes = receiptSubOptions ? receiptSubOptions.querySelectorAll('input[type="checkbox"]') : [];

            const toggleSubOptions = () => {
                if (receiptSubOptions && cashReceiptsToggle) {
                    receiptSubOptions.style.display = cashReceiptsToggle.checked ? 'block' : 'none';
                    if (!cashReceiptsToggle.checked) {
                        receiptSubCheckboxes.forEach(cb => cb.checked = false); // Deselect sub-options if parent is unchecked
                    }
                }
            };
            if(cashReceiptsToggle) {
                cashReceiptsToggle.addEventListener('change', toggleSubOptions);
                toggleSubOptions(); // Run on page load
            }

            // Select All / Deselect All Reports functionality
            const selectAllCheckbox = document.getElementById('select_all_reports');
            const reportTypeCheckboxes = document.querySelectorAll('input[name="report_types[]"]');
            
            selectAllCheckbox.addEventListener('change', function() {
                reportTypeCheckboxes.forEach(checkbox => {
                    checkbox.checked = this.checked;
                });
                // Also toggle cash receipts sub-options
                toggleSubOptions();
                // Select/deselect sub-options for cash receipts if the main toggle is checked/unchecked
                if (this.checked && cashReceiptsToggle.checked) {
                    receiptSubCheckboxes.forEach(cb => cb.checked = true);
                } else if (!this.checked) {
                     receiptSubCheckboxes.forEach(cb => cb.checked = false);
                }
            });

            // If a sub-checkbox is checked, ensure the parent is also checked
            receiptSubCheckboxes.forEach(cb => {
                cb.addEventListener('change', function() {
                    if (this.checked) {
                        cashReceiptsToggle.checked = true;
                        toggleSubOptions();
                    }
                     // If all sub-checkboxes are unchecked, uncheck the parent
                    if (!this.checked && !Array.from(receiptSubCheckboxes).some(subCb => subCb.checked)) {
                        cashReceiptsToggle.checked = false;
                        toggleSubOptions(); // Hide if no sub-options are selected
                    }
                });
            });

            // Reset Form Button
            document.getElementById('reset_form')?.addEventListener('click', function() {
                document.getElementById('reportForm').reset();
                // Reset date fields to current date
                const today = new Date().toISOString().slice(0, 10);
                document.getElementById('date_from').value = today;
                document.getElementById('date_to').value = today;
                toggleSubOptions(); // Hide sub-options on reset
            });
            
            // --- Action Button Handlers ---
            document.getElementById('print_report')?.addEventListener('click', () => window.print());

            document.getElementById('export_pdf')?.addEventListener('click', function() {
                try {
                    const { jsPDF } = window.jspdf;
                    const doc = new jsPDF({ orientation: 'landscape' });
                    const reportContainer = document.getElementById('report-results-container');
                    if (!reportContainer.querySelector('.report-table')) {
                        return showNotification('No report data found to export.', 'Export Error');
                    }
                    
                    const dateFrom = document.getElementById('date_from').value;
                    const dateTo = document.getElementById('date_to').value;
                    let yPos = 20;

                    doc.setFontSize(18);
                    doc.text('SL Diagnostics Report', 14, yPos);
                    yPos += 8;
                    doc.setFontSize(10);
                    doc.text(`Date Range: ${formatDisplayDate(dateFrom)} to ${formatDisplayDate(dateTo)}`, 14, yPos);
                    yPos += 12;

                    reportContainer.querySelectorAll('.report-section').forEach(section => {
                        const title = section.querySelector('.report-title');
                        const table = section.querySelector('.report-table');
                        const noDataMessage = section.querySelector('.no-data-message');

                        if (noDataMessage) {
                            // If there's a no-data message, add it as text
                            if (yPos > 180) { doc.addPage(); yPos = 20; }
                            doc.setFontSize(12);
                            doc.text(title.innerText, 14, yPos);
                            yPos += 7;
                            doc.setFontSize(9);
                            doc.setTextColor(100); // Gray color for no data message
                            doc.text(noDataMessage.innerText, 14, yPos);
                            doc.setTextColor(0); // Reset color
                            yPos += 15;
                        } else if (table) {
                            // Only add table if it exists (i.e., data was found)
                            if (yPos > 180) { // Add new page if not enough space
                                doc.addPage();
                                yPos = 20;
                            }
                            doc.setFontSize(12);
                            doc.text(title.innerText, 14, yPos);
                            yPos += 7;
                            doc.autoTable({
                                html: table,
                                startY: yPos,
                                theme: 'grid',
                                headStyles: { fillColor: [0, 86, 112] },
                                styles: { fontSize: 7, cellPadding: 1.5 },
                                didDrawPage: function (data) { // Add header/footer to each page
                                    doc.setFontSize(8);
                                    doc.text(`Page ${doc.internal.getNumberOfPages()}`, doc.internal.pageSize.getWidth() - 20, doc.internal.pageSize.getHeight() - 10, null, null, "right");
                                    doc.text(`SL Diagnostics Report - Date: ${formatDisplayDate(dateFrom)} to ${formatDisplayDate(dateTo)}`, 14, doc.internal.pageSize.getHeight() - 10);
                                }
                            });
                            yPos = doc.lastAutoTable.finalY + 15;
                        }
                    });
                    
                    doc.save(`SL_Report_${new Date().toISOString().slice(0,10)}.pdf`);
                } catch (error) {
                    console.error('PDF export failed:', error);
                    showNotification('Failed to generate PDF. See console for details.', 'Export Error');
                }
            });

            document.getElementById('export_csv')?.addEventListener('click', function() {
                try {
                    const reportContainer = document.getElementById('report-results-container');
                    if (!reportContainer.querySelector('.report-table') && !reportContainer.querySelector('.no-data-message')) {
                        return showNotification('No report data found to export.', 'Export Error');
                    }
                    let csvContent = `SL Diagnostics Report\nDate Range:,"${document.getElementById('date_from').value}" to "${document.getElementById('date_to').value}"\n\n`;
                    reportContainer.querySelectorAll('.report-section').forEach(section => {
                        csvContent += `"${section.querySelector('.report-title').innerText}"\n`;
                        const table = section.querySelector('.report-table');
                        const noDataMessage = section.querySelector('.no-data-message');

                        if (table) {
                            section.querySelector('.report-table').querySelectorAll('tr').forEach(row => {
                                const rowData = Array.from(row.querySelectorAll('th, td')).map(col => `"${col.innerText.replace(/"/g, '""').replace(/₹ /g, '')}"`).join(','); // Remove ₹ for CSV
                                csvContent += rowData + '\n';
                            });
                        } else if (noDataMessage) {
                             csvContent += `"${noDataMessage.innerText}"\n`;
                        }
                        csvContent += '\n';
                    });
                    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
                    const link = document.createElement('a');
                    link.setAttribute('href', URL.createObjectURL(blob));
                    link.setAttribute('download', `SL_Report_${new Date().toISOString().slice(0,10)}.csv`);
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                } catch(error) {
                    console.error('CSV export failed:', error);
                    showNotification('Failed to generate CSV file. See console for details.', 'Export Error');
                }
            });

            document.getElementById('view_summary_graphs')?.addEventListener('click', function() {
                const tempForm = document.createElement('form');
                tempForm.method = 'POST';
                tempForm.action = 'summary_graphs.php';
                tempForm.target = '_blank'; // Open in a new tab

                // Add date range
                ['date_from', 'date_to'].forEach(id => {
                    const input = document.getElementById(id);
                    const hidden = document.createElement('input');
                    hidden.type = 'hidden'; hidden.name = input.name; hidden.value = input.value;
                    tempForm.appendChild(hidden);
                });

                // Add selected report types
                document.querySelectorAll('#reportForm input[name="report_types[]"]:checked').forEach(cb => {
                    const hidden = document.createElement('input');
                    hidden.type = 'hidden'; hidden.name = cb.name; hidden.value = cb.value;
                    tempForm.appendChild(hidden);
                });

                // Add selected receipt sub-types if cash_receipts is selected
                if (cashReceiptsToggle.checked) {
                    document.querySelectorAll('#receipt_sub_options input[name="receipt_sub_types[]"]:checked').forEach(cb => {
                        const hidden = document.createElement('input');
                        hidden.type = 'hidden'; hidden.name = cb.name; hidden.value = cb.value;
                        tempForm.appendChild(hidden);
                    });
                }
                
                document.body.appendChild(tempForm);
                tempForm.submit();
                document.body.removeChild(tempForm);
            });
        });

        // Function to format date for display (DD-MM-YYYY)
        function formatDisplayDate(dateString) {
            if (!dateString) return '';
            const [year, month, day] = dateString.split('-');
            return `${day}-${month}-${year}`;
        }
    </script>
</body>
</html>
<?php
ob_end_flush();
?>
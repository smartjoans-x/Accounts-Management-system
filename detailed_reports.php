<?php
ob_start();
error_log("detailed_reports.php: Script started at " . date('Y-m-d H:i:s'));

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['user_id'])) {
    error_log("detailed_reports.php: User not logged in, redirecting to login.php");
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
    $last_three = substr($number_str, -3);
    $rest_units = substr($number_str, 0, -3);
    if ($rest_units !== '') {
        $first_part = substr($rest_units, -2);
        $remaining = substr($rest_units, 0, -2);
        if ($remaining !== '') {
            $formatted = implode(',', str_split(strrev($remaining), 2));
            $formatted = strrev($formatted);
            return $formatted . ',' . $first_part . ',' . $last_three;
        } else {
            return $first_part . ',' . $last_three;
        }
    }
    return $last_three;
}

try {
    error_log("detailed_reports.php: Attempting to include db_connect.php");
    require_once 'includes/db_connect.php';
} catch (Exception $e) {
    error_log("detailed_reports.php: Initialization failed: " . $e->getMessage());
    die("Initialization failed: " . htmlspecialchars($e->getMessage()));
}

// Fetch filter options for dropdowns
try {
    // Fetch branches
    $stmt = $pdo->query("SELECT DISTINCT branch FROM cash_receipts ORDER BY branch");
    $branches = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Fetch staff
    $stmt = $pdo->query("SELECT id, name FROM staff ORDER BY name");
    $staff_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch departments
    $stmt = $pdo->query("SELECT DISTINCT department FROM other_receipts ORDER BY department");
    $departments = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Fetch receipt_from
    $stmt = $pdo->query("SELECT DISTINCT receipt_from FROM other_receipts ORDER BY receipt_from");
    $receipt_from_list = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Fetch deposit types
    $stmt = $pdo->query("SELECT DISTINCT deposit_type FROM bank_deposits ORDER BY deposit_type");
    $deposit_types = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Fetch headings for cash payments
    $stmt = $pdo->query("SELECT DISTINCT heading FROM cash_payments ORDER BY heading");
    $cash_payment_headings = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Fetch headings for bank payments
    $stmt = $pdo->query("SELECT DISTINCT heading FROM bank_payments ORDER BY heading");
    $bank_payment_headings = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Fetch headings for bank withdrawals
    $stmt = $pdo->query("SELECT DISTINCT heading FROM bank_withdrawals ORDER BY heading");
    $bank_withdrawal_headings = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Fetch vehicles
    $stmt = $pdo->query("SELECT DISTINCT vehicle_name FROM vehicle_fuel_records ORDER BY vehicle_name");
    $vehicles = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    error_log("detailed_reports.php: Failed to fetch filter options: " . $e->getMessage());
    $error_message = "Failed to load filter options: " . htmlspecialchars($e->getMessage());
}

$success_message = '';
$error_message = isset($error_message) ? $error_message : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$report_types = isset($_GET['report_types']) ? $_GET['report_types'] : [];
$receipt_sub_types = isset($_GET['receipt_sub_types']) ? $_GET['receipt_sub_types'] : [];
$branch_filter = isset($_GET['branch_filter']) ? $_GET['branch_filter'] : '';
$staff_filter = isset($_GET['staff_filter']) ? $_GET['staff_filter'] : '';
$department_filter = isset($_GET['department_filter']) ? $_GET['department_filter'] : '';
$receipt_from_filter = isset($_GET['receipt_from_filter']) ? $_GET['receipt_from_filter'] : '';
$deposit_type_filter = isset($_GET['deposit_type_filter']) ? $_GET['deposit_type_filter'] : '';
$cash_payment_heading_filter = isset($_GET['cash_payment_heading_filter']) ? $_GET['cash_payment_heading_filter'] : '';
$bank_payment_heading_filter = isset($_GET['bank_payment_heading_filter']) ? $_GET['bank_payment_heading_filter'] : '';
$bank_withdrawal_heading_filter = isset($_GET['bank_withdrawal_heading_filter']) ? $_GET['bank_withdrawal_heading_filter'] : '';
$vehicle_filter = isset($_GET['vehicle_filter']) ? $_GET['vehicle_filter'] : '';
$report_data = [];
$totals = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_report'])) {
    error_log("detailed_reports.php: Processing POST request for report generation");
    try {
        $date_from = $_POST['date_from'];
        $date_to = $_POST['date_to'];
        $report_types = isset($_POST['report_types']) ? $_POST['report_types'] : [];
        $receipt_sub_types = (in_array('cash_receipts', $report_types) && isset($_POST['receipt_sub_types'])) ? $_POST['receipt_sub_types'] : [];
        $branch_filter = isset($_POST['branch_filter']) ? $_POST['branch_filter'] : '';
        $staff_filter = isset($_POST['staff_filter']) ? $_POST['staff_filter'] : '';
        $department_filter = isset($_POST['department_filter']) ? $_POST['department_filter'] : '';
        $receipt_from_filter = isset($_POST['receipt_from_filter']) ? $_POST['receipt_from_filter'] : '';
        $deposit_type_filter = isset($_POST['deposit_type_filter']) ? $_POST['deposit_type_filter'] : '';
        $cash_payment_heading_filter = isset($_POST['cash_payment_heading_filter']) ? $_POST['cash_payment_heading_filter'] : '';
        $bank_payment_heading_filter = isset($_POST['bank_payment_heading_filter']) ? $_POST['bank_payment_heading_filter'] : '';
        $bank_withdrawal_heading_filter = isset($_POST['bank_withdrawal_heading_filter']) ? $_POST['bank_withdrawal_heading_filter'] : '';
        $vehicle_filter = isset($_POST['vehicle_filter']) ? $_POST['vehicle_filter'] : '';

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
                error_log("detailed_reports.php: Querying branch receipts");
                $query = "
                    SELECT cr.id, cr.date, s.name AS staff_name, cr.branch, cr.payment_method, 
                           CONCAT(ba.bank_name, ' - ', ba.account_number) AS bank, cr.cheque_number,
                           cr.description, cr.amount, u.username
                    FROM cash_receipts cr
                    LEFT JOIN staff s ON cr.staff_id = s.id
                    LEFT JOIN bank_accounts ba ON cr.bank_account_id = ba.id
                    JOIN users u ON cr.user_id = u.id
                    WHERE cr.date BETWEEN ? AND ?
                ";
                $params = [$date_from, $date_to];
                if ($branch_filter) {
                    $query .= " AND cr.branch = ?";
                    $params[] = $branch_filter;
                }
                if ($staff_filter) {
                    $query .= " AND cr.staff_id = ?";
                    $params[] = $staff_filter;
                }
                $query .= " ORDER BY cr.date";
                $stmt = $pdo->prepare($query);
                $stmt->execute($params);
                $report_data['branch_receipts'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $totals['branch_receipts'] = array_sum(array_column($report_data['branch_receipts'], 'amount'));
                if (empty($report_data['branch_receipts'])) {
                    $error_message .= "No branch receipts found for the selected criteria. ";
                }
            } catch (Exception $e) {
                $error_message .= "Branch Receipts query failed: " . htmlspecialchars($e->getMessage()) . ". ";
                error_log("detailed_reports.php: Branch Receipts query failed: " . $e->getMessage());
            }
        }

        // Other Receipts
        if (in_array('cash_receipts', $report_types) && in_array('other_receipts', $receipt_sub_types)) {
            try {
                error_log("detailed_reports.php: Querying other receipts");
                $query = "
                    SELECT orr.id, orr.date, orr.department, orr.receipt_from, orr.payment_method, 
                           CONCAT(ba.bank_name, ' - ', ba.account_number) AS bank, orr.description, 
                           orr.amount, u.username
                    FROM other_receipts orr
                    LEFT JOIN bank_accounts ba ON orr.bank_account_id = ba.id
                    JOIN users u ON orr.user_id = u.id
                    WHERE orr.date BETWEEN ? AND ?
                ";
                $params = [$date_from, $date_to];
                if ($department_filter) {
                    $query .= " AND orr.department = ?";
                    $params[] = $department_filter;
                }
                if ($receipt_from_filter) {
                    $query .= " AND orr.receipt_from = ?";
                    $params[] = $receipt_from_filter;
                }
                $query .= " ORDER BY orr.date";
                $stmt = $pdo->prepare($query);
                $stmt->execute($params);
                $report_data['other_receipts'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $totals['other_receipts'] = array_sum(array_column($report_data['other_receipts'], 'amount'));
                if (empty($report_data['other_receipts'])) {
                    $error_message .= "No other receipts found for the selected criteria. ";
                }
            } catch (Exception $e) {
                $error_message .= "Other Receipts query failed: " . htmlspecialchars($e->getMessage()) . ". ";
                error_log("detailed_reports.php: Other Receipts query failed: " . $e->getMessage());
            }
        }

        // Bank Deposits
        if (in_array('bank_deposits', $report_types)) {
            try {
                error_log("detailed_reports.php: Querying bank deposits");
                $query = "
                    SELECT bd.id, bd.date, CONCAT(ba.bank_name, ' - ', ba.account_number) AS bank, 
                           bd.amount, u.username, bd.deposit_type, bd.cheque_number,
                           CONCAT(fa.bank_name, ' - ', fa.account_number) AS from_account
                    FROM bank_deposits bd
                    JOIN bank_accounts ba ON bd.bank_account_id = ba.id
                    JOIN users u ON bd.user_id = u.id
                    LEFT JOIN bank_accounts fa ON bd.from_account_id = fa.id
                    WHERE bd.date BETWEEN ? AND ?
                ";
                $params = [$date_from, $date_to];
                if ($deposit_type_filter) {
                    $query .= " AND bd.deposit_type = ?";
                    $params[] = $deposit_type_filter;
                }
                $query .= " ORDER BY bd.date";
                $stmt = $pdo->prepare($query);
                $stmt->execute($params);
                $report_data['bank_deposits'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $totals['bank_deposits'] = array_sum(array_column($report_data['bank_deposits'], 'amount'));
                if (empty($report_data['bank_deposits'])) {
                    $error_message .= "No bank deposits found for the selected criteria. ";
                }
            } catch (Exception $e) {
                $error_message .= "Bank Deposits query failed: " . htmlspecialchars($e->getMessage()) . ". ";
                error_log("detailed_reports.php: Bank Deposits query failed: " . $e->getMessage());
            }
        }

        // Cash Payments
        if (in_array('cash_payments', $report_types)) {
            try {
                error_log("detailed_reports.php: Querying cash payments");
                $query = "
                    SELECT cp.id, cp.date, cp.heading, cp.sub_heading, cp.description, 
                           cp.amount, u.username
                    FROM cash_payments cp
                    JOIN users u ON cp.user_id = u.id
                    WHERE cp.date BETWEEN ? AND ?
                ";
                $params = [$date_from, $date_to];
                if ($cash_payment_heading_filter) {
                    $query .= " AND cp.heading = ?";
                    $params[] = $cash_payment_heading_filter;
                }
                $query .= " ORDER BY cp.date";
                $stmt = $pdo->prepare($query);
                $stmt->execute($params);
                $report_data['cash_payments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $totals['cash_payments'] = array_sum(array_column($report_data['cash_payments'], 'amount'));
                if (empty($report_data['cash_payments'])) {
                    $error_message .= "No cash payments found for the selected criteria. ";
                }
            } catch (Exception $e) {
                $error_message .= "Cash Payments query failed: " . htmlspecialchars($e->getMessage()) . ". ";
                error_log("detailed_reports.php: Cash Payments query failed: " . $e->getMessage());
            }
        }

        // Bank Payments
        if (in_array('bank_payments', $report_types)) {
            try {
                error_log("detailed_reports.php: Querying bank payments");
                $query = "
                    SELECT bp.id, bp.date, bp.heading, bp.sub_heading, bp.description, bp.amount, 
                           CONCAT(ba.bank_name, ' - ', ba.account_number) AS bank_account, 
                           bp.payment_mode, bp.cheque_no, u.username
                    FROM bank_payments bp
                    JOIN bank_accounts ba ON bp.bank_account_id = ba.id
                    JOIN users u ON bp.user_id = u.id
                    WHERE bp.date BETWEEN ? AND ?
                ";
                $params = [$date_from, $date_to];
                if ($bank_payment_heading_filter) {
                    $query .= " AND bp.heading = ?";
                    $params[] = $bank_payment_heading_filter;
                }
                $query .= " ORDER BY bp.date";
                $stmt = $pdo->prepare($query);
                $stmt->execute($params);
                $report_data['bank_payments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $totals['bank_payments'] = array_sum(array_column($report_data['bank_payments'], 'amount'));
                if (empty($report_data['bank_payments'])) {
                    $error_message .= "No bank payments found for the selected criteria. ";
                }
            } catch (Exception $e) {
                $error_message .= "Bank Payments query failed: " . htmlspecialchars($e->getMessage()) . ". ";
                error_log("detailed_reports.php: Bank Payments query failed: " . $e->getMessage());
            }
        }

        // Bank Withdrawals
        if (in_array('bank_withdrawals', $report_types)) {
            try {
                error_log("detailed_reports.php: Querying bank withdrawals");
                $query = "
                    SELECT bw.id, bw.date, CONCAT(ba.bank_name, ' - ', ba.account_number) AS bank, 
                           bw.amount, bw.heading, bw.description, u.username
                    FROM bank_withdrawals bw
                    JOIN bank_accounts ba ON bw.bank_account_id = ba.id
                    JOIN users u ON bw.user_id = u.id
                    WHERE bw.date BETWEEN ? AND ?
                ";
                $params = [$date_from, $date_to];
                if ($bank_withdrawal_heading_filter) {
                    $query .= " AND bw.heading = ?";
                    $params[] = $bank_withdrawal_heading_filter;
                }
                $query .= " ORDER BY bw.date";
                $stmt = $pdo->prepare($query);
                $stmt->execute($params);
                $report_data['bank_withdrawals'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $totals['bank_withdrawals'] = array_sum(array_column($report_data['bank_withdrawals'], 'amount'));
                if (empty($report_data['bank_withdrawals'])) {
                    $error_message .= "No bank withdrawals found for the selected criteria. ";
                }
            } catch (Exception $e) {
                $error_message .= "Bank Withdrawals query failed: " . htmlspecialchars($e->getMessage()) . ". ";
                error_log("detailed_reports.php: Bank Withdrawals query failed: " . $e->getMessage());
            }
        }

        // Vehicle Fuel Records
        if (in_array('vehicle_fuel', $report_types)) {
            try {
                error_log("detailed_reports.php: Querying vehicle fuel records");
                $query = "
                    SELECT vfr.id, vfr.date, vfr.vehicle_name, vfr.fuel_amount, vfr.km_reading, 
                           vfr.payment_method, CONCAT(ba.bank_name, ' - ', ba.account_number) AS bank_account, 
                           u.username
                    FROM vehicle_fuel_records vfr
                    JOIN users u ON vfr.user_id = u.id
                    LEFT JOIN bank_accounts ba ON vfr.bank_account_id = ba.id
                    WHERE vfr.date BETWEEN ? AND ?
                ";
                $params = [$date_from, $date_to];
                if ($vehicle_filter) {
                    $query .= " AND vfr.vehicle_name = ?";
                    $params[] = $vehicle_filter;
                }
                $query .= " ORDER BY vfr.date";
                $stmt = $pdo->prepare($query);
                $stmt->execute($params);
                $report_data['vehicle_fuel'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $totals['vehicle_fuel'] = array_sum(array_column($report_data['vehicle_fuel'], 'fuel_amount'));
                if (empty($report_data['vehicle_fuel'])) {
                    $error_message .= "No vehicle fuel records found for the selected criteria. ";
                }
            } catch (Exception $e) {
                $error_message .= "Vehicle Fuel Records query failed: " . htmlspecialchars($e->getMessage()) . ". ";
                error_log("detailed_reports.php: Vehicle Fuel Records query failed: " . $e->getMessage());
            }
        }

        if (!empty($report_data) && empty($error_message)) {
            $success_message = "Report data generated. Review below and click 'Download PDF' or 'Download CSV' to export.";
            error_log("detailed_reports.php: Report data generated successfully");
        } elseif (empty($report_data)) {
            $error_message = "No data found for the selected criteria.";
            error_log("detailed_reports.php: No report data found");
        }
    } catch (Exception $e) {
        $error_message = "Report generation failed: " . htmlspecialchars($e->getMessage());
        error_log("detailed_reports.php: Report generation failed: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SL Diagnostics - Detailed Reports</title>
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
        .report-table tfoot td {
            font-weight: bold;
            background-color: #f1f1f1;
        }
        .sub-options, .filter-options {
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
            .report-table tfoot td {
                font-weight: bold;
                background-color: #d0d0d0;
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
            #other_receipts_table th:nth-child(7), #other_receipts_table td:nth-child(7) { width: 12%; }
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
            #bank_withdrawals_table th:nth-child(1), #bank_withdrawals_table td:nth-child(1) { width: 8%; }
            #bank_withdrawals_table th:nth-child(2), #bank_withdrawals_table td:nth-child(2) { width: 12%; }
            #bank_withdrawals_table th:nth-child(3), #bank_withdrawals_table td:nth-child(3) { width: 20%; }
            #bank_withdrawals_table th:nth-child(4), #bank_withdrawals_table td:nth-child(4) { width: 15%; }
            #bank_withdrawals_table th:nth-child(5), #bank_withdrawals_table td:nth-child(5) { width: 20%; }
            #bank_withdrawals_table th:nth-child(6), #bank_withdrawals_table td:nth-child(6) { width: 10%; }
            #bank_withdrawals_table th:nth-child(7), #bank_withdrawals_table td:nth-child(7) { width: 5%; }
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
        <h2>SL Diagnostics Detailed Report</h2>
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
                        <a class="nav-link" href="bank_withdrawals.php">Bank Withdrawals</a>
                    </li>
                    <!--   <li class="nav-item">
                        <a class="nav-link" href="vehicle_fuel.php">Vehicle Fuel</a>
                    </li>-->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle active" href="#" id="reportsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            Reports
                        </a>
                        <ul class="dropdown-menu" aria-labelledby="reportsDropdown">
                            <li><a class="dropdown-item" href="reports.php">Standard Reports</a></li>
                            <li><a class="dropdown-item active" href="detailed_reports.php">Detailed Reports</a></li>
                        </ul>
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
        <h1 class="display-5 mb-4">Detailed Reports</h1>

        <?php if ($success_message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
        <?php elseif ($error_message): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">Generate Detailed Report</div>
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
                                    <input class="form-check-input" type="checkbox" id="branch_receipts" name="receipt_sub_types[]" value="branch_receipts" <?php echo in_array('branch_receipts', $receipt_sub_types) ? 'checked' : ''; ?> onchange="toggleBranchFilters()">
                                    <label class="form-check-label" for="branch_receipts">Branch Receipts</label>
                                    <div id="branch_filter_options" class="filter-options">
                                        <div class="mt-2">
                                            <label for="branch_filter" class="form-label">Branch</label>
                                            <select class="form-select" id="branch_filter" name="branch_filter">
                                                <option value="">All Branches</option>
                                                <?php foreach ($branches as $branch): ?>
                                                    <option value="<?php echo htmlspecialchars($branch); ?>" <?php echo $branch_filter === $branch ? 'selected' : ''; ?>><?php echo htmlspecialchars($branch); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="mt-2">
                                            <label for="staff_filter" class="form-label">Staff</label>
                                            <select class="form-select" id="staff_filter" name="staff_filter">
                                                <option value="">All Staff</option>
                                                <?php foreach ($staff_list as $staff): ?>
                                                    <option value="<?php echo htmlspecialchars($staff['id']); ?>" <?php echo $staff_filter === $staff['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($staff['name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="other_receipts" name="receipt_sub_types[]" value="other_receipts" <?php echo in_array('other_receipts', $receipt_sub_types) ? 'checked' : ''; ?> onchange="toggleOtherReceiptFilters()">
                                    <label class="form-check-label" for="other_receipts">Other Receipts</label>
                                    <div id="other_receipt_filter_options" class="filter-options">
                                        <div class="mt-2">
                                            <label for="department_filter" class="form-label">Department</label>
                                            <select class="form-select" id="department_filter" name="department_filter">
                                                <option value="">All Departments</option>
                                                <?php foreach ($departments as $department): ?>
                                                    <option value="<?php echo htmlspecialchars($department); ?>" <?php echo $department_filter === $department ? 'selected' : ''; ?>><?php echo htmlspecialchars($department); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="mt-2">
                                            <label for="receipt_from_filter" class="form-label">Receipt From</label>
                                            <select class="form-select" id="receipt_from_filter" name="receipt_from_filter">
                                                <option value="">All Sources</option>
                                                <?php foreach ($receipt_from_list as $receipt_from): ?>
                                                    <option value="<?php echo htmlspecialchars($receipt_from); ?>" <?php echo $receipt_from_filter === $receipt_from ? 'selected' : ''; ?>><?php echo htmlspecialchars($receipt_from); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="bank_deposits" name="report_types[]" value="bank_deposits" <?php echo in_array('bank_deposits', $report_types) ? 'checked' : ''; ?> onchange="toggleBankDepositFilters()">
                            <label class="form-check-label" for="bank_deposits">Bank Deposits</label>
                            <div id="bank_deposit_filter_options" class="filter-options">
                                <div class="mt-2">
                                    <label for="deposit_type_filter" class="form-label">Deposit Type</label>
                                    <select class="form-select" id="deposit_type_filter" name="deposit_type_filter">
                                        <option value="">All Deposit Types</option>
                                        <?php foreach ($deposit_types as $deposit_type): ?>
                                            <option value="<?php echo htmlspecialchars($deposit_type); ?>" <?php echo $deposit_type_filter === $deposit_type ? 'selected' : ''; ?>><?php echo htmlspecialchars($deposit_type); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="cash_payments" name="report_types[]" value="cash_payments" <?php echo in_array('cash_payments', $report_types) ? 'checked' : ''; ?> onchange="toggleCashPaymentFilters()">
                            <label class="form-check-label" for="cash_payments">Cash Payments</label>
                            <div id="cash_payment_filter_options" class="filter-options">
                                <div class="mt-2">
                                    <label for="cash_payment_heading_filter" class="form-label">Heading</label>
                                    <select class="form-select" id="cash_payment_heading_filter" name="cash_payment_heading_filter">
                                        <option value="">All Headings</option>
                                        <?php foreach ($cash_payment_headings as $heading): ?>
                                            <option value="<?php echo htmlspecialchars($heading); ?>" <?php echo $cash_payment_heading_filter === $heading ? 'selected' : ''; ?>><?php echo htmlspecialchars($heading); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="bank_payments" name="report_types[]" value="bank_payments" <?php echo in_array('bank_payments', $report_types) ? 'checked' : ''; ?> onchange="toggleBankPaymentFilters()">
                            <label class="form-check-label" for="bank_payments">Bank Payments</label>
                            <div id="bank_payment_filter_options" class="filter-options">
                                <div class="mt-2">
                                    <label for="bank_payment_heading_filter" class="form-label">Heading</label>
                                    <select class="form-select" id="bank_payment_heading_filter" name="bank_payment_heading_filter">
                                        <option value="">All Headings</option>
                                        <?php foreach ($bank_payment_headings as $heading): ?>
                                            <option value="<?php echo htmlspecialchars($heading); ?>" <?php echo $bank_payment_heading_filter === $heading ? 'selected' : ''; ?>><?php echo htmlspecialchars($heading); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="bank_withdrawals" name="report_types[]" value="bank_withdrawals" <?php echo in_array('bank_withdrawals', $report_types) ? 'checked' : ''; ?> onchange="toggleBankWithdrawalFilters()">
                            <label class="form-check-label" for="bank_withdrawals">Bank Withdrawals</label>
                            <div id="bank_withdrawal_filter_options" class="filter-options">
                                <div class="mt-2">
                                    <label for="bank_withdrawal_heading_filter" class="form-label">Heading</label>
                                    <select class="form-select" id="bank_withdrawal_heading_filter" name="bank_withdrawal_heading_filter">
                                        <option value="">All Headings</option>
                                        <?php foreach ($bank_withdrawal_headings as $heading): ?>
                                            <option value="<?php echo htmlspecialchars($heading); ?>" <?php echo $bank_withdrawal_heading_filter === $heading ? 'selected' : ''; ?>><?php echo htmlspecialchars($heading); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    <!--    <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="vehicle_fuel" name="report_types[]" value="vehicle_fuel" <?php echo in_array('vehicle_fuel', $report_types) ? 'checked' : ''; ?> onchange="toggleVehicleFuelFilters()">
                            <label class="form-check-label" for="vehicle_fuel">Vehicle Fuel Records</label>
                            <div id="vehicle_fuel_filter_options" class="filter-options">
                                <div class="mt-2">
                                    <label for="vehicle_filter" class="form-label">Vehicle</label>
                                    <select class="form-select" id="vehicle_filter" name="vehicle_filter">
                                        <option value="">All Vehicles</option>
                                        <?php foreach ($vehicles as $vehicle): ?>
                                            <option value="<?php echo htmlspecialchars($vehicle); ?>" <?php echo $vehicle_filter === $vehicle ? 'selected' : ''; ?>><?php echo htmlspecialchars($vehicle); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>-->
                    <button type="submit" name="generate_report" class="btn btn-primary mt-3">Generate Report</button>
                    <?php if (!empty($report_data)): ?>
                        <button type="button" id="export_pdf" class="btn btn-primary mt-3">Download PDF</button>
                        <button type="button" id="export_csv" class="btn btn-secondary mt-3">Download CSV</button>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <?php if (!empty($report_data)): ?>
            <h2 class="mt-4">Detailed Report Results</h2>
            
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
                                <td><?php echo htmlspecialchars($r['staff_name'] ?: '-'); ?></td>
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
                    <tfoot>
                        <tr>
                            <td colspan="8">Total</td>
                            <td><?php echo formatIndianNumber($totals['branch_receipts']); ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
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
                                <td><?php echo htmlspecialchars($r['department'] ?: '-'); ?></td>
                                <td><?php echo htmlspecialchars($r['receipt_from'] ?: '-'); ?></td>
                                <td><?php echo htmlspecialchars($r['payment_method']); ?></td>
                                <td><?php echo htmlspecialchars($r['bank'] ?: '-'); ?></td>
                                <td><?php echo htmlspecialchars($r['description'] ?: '-'); ?></td>
                                <td><?php echo formatIndianNumber($r['amount']); ?></td>
                                <td><?php echo htmlspecialchars($r['username']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="7">Total</td>
                            <td><?php echo formatIndianNumber($totals['other_receipts']); ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
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
                    <tfoot>
                        <tr>
                            <td colspan="6">Total</td>
                            <td><?php echo formatIndianNumber($totals['bank_deposits']); ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
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
                    <tfoot>
                        <tr>
                            <td colspan="5">Total</td>
                            <td><?php echo formatIndianNumber($totals['cash_payments']); ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
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
                    <tfoot>
                        <tr>
                            <td colspan="5">Total</td>
                            <td><?php echo formatIndianNumber($totals['bank_payments']); ?></td>
                            <td colspan="4"></td>
                        </tr>
                    </tfoot>
                </table>
            <?php endif; ?>

            <?php if (isset($report_data['bank_withdrawals']) && !empty($report_data['bank_withdrawals'])): ?>
                <h3 class="print-table-title">Bank Withdrawals</h3>
                <table id="bank_withdrawals_table" class="report-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Date</th>
                            <th>Bank</th>
                            <th>Heading</th>
                            <th>Description</th>
                            <th>Amount (₹)</th>
                            <th>User</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report_data['bank_withdrawals'] as $w): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($w['id']); ?></td>
                                <td><?php echo htmlspecialchars($w['date']); ?></td>
                                <td><?php echo htmlspecialchars($w['bank']); ?></td>
                                <td><?php echo htmlspecialchars($w['heading']); ?></td>
                                <td><?php echo htmlspecialchars($w['description'] ?: '-'); ?></td>
                                <td><?php echo formatIndianNumber($w['amount']); ?></td>
                                <td><?php echo htmlspecialchars($w['username']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="5">Total</td>
                            <td><?php echo formatIndianNumber($totals['bank_withdrawals']); ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
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
                    <tfoot>
                        <tr>
                            <td colspan="3">Total</td>
                            <td><?php echo formatIndianNumber($totals['vehicle_fuel']); ?></td>
                            <td colspan="4"></td>
                        </tr>
                    </tfoot>
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
        receiptSubOptions.style.display = cashReceiptsCheckbox.checked ? 'block' : 'none';
        toggleBranchFilters();
        toggleOtherReceiptFilters();
    }

    function toggleBranchFilters() {
        const branchReceiptsCheckbox = document.getElementById('branch_receipts');
        const branchFilterOptions = document.getElementById('branch_filter_options');
        branchFilterOptions.style.display = branchReceiptsCheckbox.checked ? 'block' : 'none';
    }

    function toggleOtherReceiptFilters() {
        const otherReceiptsCheckbox = document.getElementById('other_receipts');
        const otherReceiptFilterOptions = document.getElementById('other_receipt_filter_options');
        otherReceiptFilterOptions.style.display = otherReceiptsCheckbox.checked ? 'block' : 'none';
    }

    function toggleBankDepositFilters() {
        const bankDepositsCheckbox = document.getElementById('bank_deposits');
        const bankDepositFilterOptions = document.getElementById('bank_deposit_filter_options');
        bankDepositFilterOptions.style.display = bankDepositsCheckbox.checked ? 'block' : 'none';
    }

    function toggleCashPaymentFilters() {
        const cashPaymentsCheckbox = document.getElementById('cash_payments');
        const cashPaymentFilterOptions = document.getElementById('cash_payment_filter_options');
        cashPaymentFilterOptions.style.display = cashPaymentsCheckbox.checked ? 'block' : 'none';
    }

    function toggleBankPaymentFilters() {
        const bankPaymentsCheckbox = document.getElementById('bank_payments');
        const bankPaymentFilterOptions = document.getElementById('bank_payment_filter_options');
        bankPaymentFilterOptions.style.display = bankPaymentsCheckbox.checked ? 'block' : 'none';
    }

    function toggleBankWithdrawalFilters() {
        const bankWithdrawalsCheckbox = document.getElementById('bank_withdrawals');
        const bankWithdrawalFilterOptions = document.getElementById('bank_withdrawal_filter_options');
        bankWithdrawalFilterOptions.style.display = bankWithdrawalsCheckbox.checked ? 'block' : 'none';
    }

    function toggleVehicleFuelFilters() {
        const vehicleFuelCheckbox = document.getElementById('vehicle_fuel');
        const vehicleFuelFilterOptions = document.getElementById('vehicle_fuel_filter_options');
        vehicleFuelFilterOptions.style.display = vehicleFuelCheckbox.checked ? 'block' : 'none';
    }

    window.onload = function() {
        toggleReceiptSubOptions();
        toggleBranchFilters();
        toggleOtherReceiptFilters();
        toggleBankDepositFilters();
        toggleCashPaymentFilters();
        toggleBankPaymentFilters();
        toggleBankWithdrawalFilters();
        toggleVehicleFuelFilters();
    };

    document.getElementById('export_pdf')?.addEventListener('click', function() {
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF();
        let yOffset = 10;

        doc.setFontSize(16);
        doc.text('SL Diagnostics Detailed Report', 14, yOffset);
        yOffset += 10;

        const tables = [
            {id: 'branch_receipts_table', name: 'Branch Receipts'},
            {id: 'other_receipts_table', name: 'Other Receipts'},
            {id: 'bank_deposits_table', name: 'Bank Deposits'},
            {id: 'cash_payments_table', name: 'Cash Payments'},
            {id: 'bank_payments_table', name: 'Bank Payments'},
            {id: 'bank_withdrawals_table', name: 'Bank Withdrawals'},
            {id: 'vehicle_fuel_table', name: 'Vehicle Fuel Records'}
        ];

        tables.forEach(tableInfo => {
            const table = document.getElementById(tableInfo.id);
            if (table) {
                doc.setFontSize(12);
                doc.text(tableInfo.name, 14, yOffset);
                yOffset += 5;
                doc.autoTable({
                    html: `#${tableInfo.id}`,
                    startY: yOffset,
                    theme: 'grid',
                    headStyles: { fillColor: [0, 86, 112] },
                    footStyles: { fillColor: [208, 208, 208], fontStyle: 'bold' },
                    styles: { fontSize: 8, cellPadding: 2 }
                });
                yOffset = doc.lastAutoTable.finalY + 10;
            }
        });

        doc.save('SL_Diagnostics_Detailed_Report.pdf');
    });

    document.getElementById('export_csv')?.addEventListener('click', function() {
        let csvContent = '';
        const tables = [
            {id: 'branch_receipts_table', name: 'Branch Receipts'},
            {id: 'other_receipts_table', name: 'Other Receipts'},
            {id: 'bank_deposits_table', name: 'Bank Deposits'},
            {id: 'cash_payments_table', name: 'Cash Payments'},
            {id: 'bank_payments_table', name: 'Bank Payments'},
            {id: 'bank_withdrawals_table', name: 'Bank Withdrawals'},
            {id: 'vehicle_fuel_table', name: 'Vehicle Fuel Records'}
        ];

        tables.forEach(tableInfo => {
            const table = document.getElementById(tableInfo.id);
            if (table) {
                csvContent += tableInfo.name + '\n';
                const rows = table.querySelectorAll('tr');
                rows.forEach(row => {
                    const cols = row.querySelectorAll('th, td');
                    const rowData = Array.from(cols).map(col => `"${col.innerText.replace(/"/g, '""')}"`).join(',');
                    csvContent += rowData + '\n';
                });
                csvContent += '\n';
            }
        });

        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.setAttribute('href', URL.createObjectURL(blob));
        link.setAttribute('download', 'SL_Diagnostics_Detailed_Report.csv');
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    });
    </script>
</body>
</html>
<?php
ob_end_flush();
error_log("detailed_reports.php: Script ended at " . date('Y-m-d H:i:s'));
?>
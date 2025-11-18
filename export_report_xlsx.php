<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

try {
    require_once 'includes/db_connect.php';
} catch (Exception $e) {
    header("Location: reports_new.php?error=" . urlencode("Database connection failed: " . $e->getMessage()));
    exit;
}

// Include SimpleXLSXGen with case-insensitive path checking
$simpleXlsxGenPath = __DIR__ . '/lib/SimpleXLSXGen.php';
if (!file_exists($simpleXlsxGenPath)) {
    $possiblePaths = glob(__DIR__ . '/lib/[Ss][Ii][Mm][Pp][Ll][Ee][Xx][Ll][Ss][Xx][Gg][Ee][Nn].php');
    if (!empty($possiblePaths)) {
        $simpleXlsxGenPath = $possiblePaths[0];
    } else {
        header("Location: reports_new.php?error=" . urlencode("SimpleXLSXGen library is missing. Please download it from https://github.com/shuchkin/simplexlsxgen and place SimpleXLSXGen.php in the 'lib' directory."));
        exit;
    }
}

if (!is_readable($simpleXlsxGenPath)) {
    header("Location: reports_new.php?error=" . urlencode("SimpleXLSXGen.php is not readable. Check file permissions."));
    exit;
}

require_once $simpleXlsxGenPath;

if (!class_exists('Shuchkin\SimpleXLSXGen')) {
    header("Location: reports_new.php?error=" . urlencode("SimpleXLSXGen class not found. Ensure SimpleXLSXGen.php is valid and contains the SimpleXLSXGen class."));
    exit;
}

// Sanitize input function
function sanitizeInput($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

// Validate request method
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header("Location: reports_new.php?error=" . urlencode("Invalid request method. Please use the report form."));
    exit;
}

// Get and sanitize input
$from_date = sanitizeInput($_GET['from_date'] ?? '');
$to_date = sanitizeInput($_GET['to_date'] ?? '');
$report_types = isset($_GET['report_types']) && is_array($_GET['report_types']) ? array_map('sanitizeInput', $_GET['report_types']) : [];

if (empty($from_date) || empty($to_date) || empty($report_types)) {
    header("Location: reports_new.php?error=" . urlencode("Please provide both dates and select at least one report type."));
    exit;
}
if (strtotime($from_date) > strtotime($to_date)) {
    header("Location: reports_new.php?error=" . urlencode("From date cannot be later than to date."));
    exit;
}

try {
    $sheets = [];

    foreach ($report_types as $type) {
        $sheetData = [];
        $sheetData[] = [ucfirst(str_replace('_', ' ', $type)) . ' Report'];
        $sheetData[] = ["From: $from_date To: $to_date"];
        $sheetData[] = [];

        switch ($type) {
            case 'cash_receipts':
                $sheetData[] = ['ID', 'Date', 'Staff', 'Branch', 'Payment Method', 'Bank', 'Amount (₹)', 'User'];
                $query = "
                    SELECT cr.id, cr.date, s.name AS staff_name, cr.branch, cr.payment_method, 
                           CONCAT(ba.bank_name, ' - ', ba.account_number) AS bank, cr.amount, u.username
                    FROM cash_receipts cr 
                    LEFT JOIN staff s ON cr.staff_id = s.id 
                    LEFT JOIN bank_accounts ba ON cr.bank_account_id = ba.id 
                    JOIN users u ON cr.user_id = u.id 
                    WHERE cr.date BETWEEN ? AND ?
                    ORDER BY cr.date, cr.id
                ";
                $stmt = $pdo->prepare($query);
                $stmt->execute([$from_date, $to_date . ' 23:59:59']);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (empty($rows)) {
                    $sheetData[] = ['No data found for the selected date range.'];
                } else {
                    foreach ($rows as $row) {
                        $sheetData[] = [
                            $row['id'],
                            $row['date'],
                            $row['staff_name'] ?: 'N/A',
                            $row['branch'] ?: 'N/A',
                            $row['payment_method'] ?: 'N/A',
                            $row['bank'] ?: '-',
                            number_format($row['amount'], 2),
                            $row['username']
                        ];
                    }
                }
                $sheets['Cash Receipts'] = $sheetData;
                break;

            case 'bank_deposits':
                $sheetData[] = ['ID', 'Date', 'Bank', 'Amount (₹)', 'User'];
                $query = "
                    SELECT bd.id, bd.date, CONCAT(ba.bank_name, ' - ', ba.account_number) AS bank, 
                           bd.amount, u.username
                    FROM bank_deposits bd 
                    JOIN bank_accounts ba ON bd.bank_account_id = ba.id 
                    JOIN users u ON bd.user_id = u.id 
                    WHERE bd.date BETWEEN ? AND ?
                    ORDER BY bd.date, bd.id
                ";
                $stmt = $pdo->prepare($query);
                $stmt->execute([$from_date, $to_date . ' 23:59:59']);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (empty($rows)) {
                    $sheetData[] = ['No data found for the selected date range.'];
                } else {
                    foreach ($rows as $row) {
                        $sheetData[] = [
                            $row['id'],
                            $row['date'],
                            $row['bank'] ?: 'N/A',
                            number_format($row['amount'], 2),
                            $row['username']
                        ];
                    }
                }
                $sheets['Bank Deposits'] = $sheetData;
                break;

            case 'cash_payments':
                $sheetData[] = ['ID', 'Date', 'Description', 'Staff/Department', 'Amount (₹)', 'User'];
                $query = "
                    SELECT cp.id, cp.date, cp.description, cp.staff_or_dept AS staff_department, 
                           cp.amount, u.username 
                    FROM cash_payments cp 
                    JOIN users u ON cp.user_id = u.id 
                    WHERE cp.date BETWEEN ? AND ?
                    ORDER BY cp.date, cp.id
                ";
                $stmt = $pdo->prepare($query);
                $stmt->execute([$from_date, $to_date . ' 23:59:59']);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (empty($rows)) {
                    $sheetData[] = ['No data found for the selected date range.'];
                } else {
                    foreach ($rows as $row) {
                        $sheetData[] = [
                            $row['id'],
                            $row['date'],
                            $row['description'] ?: 'N/A',
                            $row['staff_department'] ?: 'N/A',
                            number_format($row['amount'], 2),
                            $row['username']
                        ];
                    }
                }
                $sheets['Cash Payments'] = $sheetData;
                break;

            case 'bank_payments':
                $sheetData[] = ['ID', 'Date', 'Description', 'Staff/Department', 'Amount (₹)', 'Bank Account', 'User'];
                $query = "
                    SELECT bp.id, bp.date, bp.description, bp.staff_department, bp.amount, 
                           CONCAT(ba.bank_name, ' - ', ba.account_number) AS bank_account, 
                           u.username 
                    FROM bank_payments bp 
                    JOIN bank_accounts ba ON bp.bank_account_id = ba.id 
                    JOIN users u ON bp.user_id = u.id 
                    WHERE bp.date BETWEEN ? AND ?
                    ORDER BY bp.date, bp.id
                ";
                $stmt = $pdo->prepare($query);
                $stmt->execute([$from_date, $to_date . ' 23:59:59']);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (empty($rows)) {
                    $sheetData[] = ['No data found for the selected date range.'];
                } else {
                    foreach ($rows as $row) {
                        $sheetData[] = [
                            $row['id'],
                            $row['date'],
                            $row['description'] ?: 'N/A',
                            $row['staff_department'] ?: 'N/A',
                            number_format($row['amount'], 2),
                            $row['bank_account'] ?: 'N/A',
                            $row['username']
                        ];
                    }
                }
                $sheets['Bank Payments'] = $sheetData;
                break;
        }
    }

    // Generate XLSX
    $xlsx = Shuchkin\SimpleXLSXGen::fromArray($sheets);
    $xlsx->setTitle("SL Diagnostics Financial Report");
    $xlsx->downloadAs('SL_Diagnostics_Report_' . date('Y-m-d_His') . '.xlsx');
    exit;
} catch (PDOException $e) {
    error_log("Error generating report: " . $e->getMessage());
    header("Location: reports_new.php?error=" . urlencode("Failed to generate report: " . $e->getMessage()));
    exit;
} catch (Exception $e) {
    error_log("Error generating Excel file: " . $e->getMessage());
    header("Location: reports_new.php?error=" . urlencode("Failed to generate Excel file: " . $e->getMessage()));
    exit;
}
?>
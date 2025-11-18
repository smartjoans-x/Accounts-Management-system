<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/reports/error_log.txt');

// Set timezone to IST
date_default_timezone_set('Asia/Kolkata');

// Check if running via CLI to bypass session (for Task Scheduler)
if (!isset($_SESSION['user_id']) && php_sapi_name() !== 'cli') {
    $error = "Unauthorized access. Please log in.";
    file_put_contents(__DIR__ . '/reports/error_log.txt', "[" . date('Y-m-d H:i:s') . "] $error\n", FILE_APPEND);
    die($error);
}

// Verify autoloader exists
if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    $error = "Autoloader not found at " . __DIR__ . "/vendor/autoload.php. Please run 'composer require phpoffice/phpspreadsheet'.";
    file_put_contents(__DIR__ . '/reports/error_log.txt', "[" . date('Y-m-d H:i:s') . "] $error\n", FILE_APPEND);
    die($error);
}

require_once __DIR__ . '/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Verify PhpSpreadsheet class exists
if (!class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
    $error = "PhpSpreadsheet class not found. Check vendor/autoload.php and Composer installation.";
    file_put_contents(__DIR__ . '/reports/error_log.txt', "[" . date('Y-m-d H:i:s') . "] $error\n", FILE_APPEND);
    die($error);
}

try {
    require_once 'includes/db_connect.php';
} catch (Exception $e) {
    $error = "Database connection failed: " . $e->getMessage();
    file_put_contents(__DIR__ . '/reports/error_log.txt', "[" . date('Y-m-d H:i:s') . "] $error\n", FILE_APPEND);
    die($error);
}

// Directory to save Excel files
$saveDir = __DIR__ . '/reports/';
if (!is_dir($saveDir)) {
    mkdir($saveDir, 0755, true);
}
if (!is_writable($saveDir)) {
    $error = "Reports directory ($saveDir) is not writable.";
    file_put_contents($saveDir . 'error_log.txt', "[" . date('Y-m-d H:i:s') . "] $error\n", FILE_APPEND);
    die($error);
}

// Get current date for report
$date = date('Y-m-d');
$date_from = $date;
$date_to = $date;

// Report types to include
$report_types = ['cash_receipts', 'bank_deposits', 'cash_payments', 'bank_payments', 'vehicle_fuel'];
$report_data = [];
$cash_on_hand_data = [];
$bank_balances = [];
$error_message = '';

try {
    // Cash Receipts
    if (in_array('cash_receipts', $report_types)) {
        try {
            $stmt = $pdo->prepare("
                SELECT cr.id, cr.date, s.name AS staff_name, cr.branch, cr.payment_method, 
                       CONCAT(ba.bank_name, ' - ', ba.account_number) AS bank, cr.amount, u.username
                FROM cash_receipts cr
                LEFT JOIN staff s ON cr.staff_id = s.id
                LEFT JOIN bank_accounts ba ON cr.bank_account_id = ba.id
                JOIN users u ON cr.user_id = u.id
                WHERE cr.date = ?
                ORDER BY cr.date
            ");
            $stmt->execute([$date]);
            $report_data['cash_receipts'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $error_message .= "Cash Receipts query failed: " . $e->getMessage() . ". ";
        }
    }

    // Bank Deposits
    if (in_array('bank_deposits', $report_types)) {
        try {
            $stmt = $pdo->prepare("
                SELECT bd.id, bd.date, CONCAT(ba.bank_name, ' - ', ba.account_number) AS bank, 
                       bd.amount, u.username, bd.deposit_type, bd.cheque_number,
                       CONCAT(fa.bank_name, ' - ', fa.account_number) AS from_account
                FROM bank_deposits bd
                JOIN bank_accounts ba ON bd.bank_account_id = ba.id
                JOIN users u ON bd.user_id = u.id
                LEFT JOIN bank_accounts fa ON bd.from_account_id = fa.id
                WHERE bd.date = ?
                ORDER BY bd.date
            ");
            $stmt->execute([$date]);
            $report_data['bank_deposits'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $error_message .= "Bank Deposits query failed: " . $e->getMessage() . ". ";
        }
    }

    // Cash Payments
    if (in_array('cash_payments', $report_types)) {
        try {
            $stmt = $pdo->prepare("
                SELECT cp.id, cp.date, cp.heading, cp.description, 
                       cp.amount, u.username
                FROM cash_payments cp
                JOIN users u ON cp.user_id = u.id
                WHERE cp.date = ?
                ORDER BY cp.date
            ");
            $stmt->execute([$date]);
            $report_data['cash_payments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $error_message .= "Cash Payments query failed: " . $e->getMessage() . ". ";
        }
    }

    // Bank Payments
    if (in_array('bank_payments', $report_types)) {
        try {
            $stmt = $pdo->prepare("
                SELECT bp.id, bp.date, bp.heading, bp.description, bp.amount, 
                       CONCAT(ba.bank_name, ' - ', ba.account_number) AS bank_account, u.username
                FROM bank_payments bp
                JOIN bank_accounts ba ON bp.bank_account_id = ba.id
                JOIN users u ON bp.user_id = u.id
                WHERE bp.date = ?
                ORDER BY bp.date
            ");
            $stmt->execute([$date]);
            $report_data['bank_payments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $error_message .= "Bank Payments query failed: " . $e->getMessage() . ". ";
        }
    }

    // Vehicle Fuel Records
    if (in_array('vehicle_fuel', $report_types)) {
        try {
            $stmt = $pdo->prepare("
                SELECT vfr.id, vfr.date, vfr.vehicle_name, vfr.fuel_amount, vfr.km_reading, 
                       vfr.payment_method, CONCAT(ba.bank_name, ' - ', ba.account_number) AS bank_account, 
                       u.username
                FROM vehicle_fuel_records vfr
                JOIN users u ON vfr.user_id = u.id
                LEFT JOIN bank_accounts ba ON vfr.bank_account_id = ba.id
                WHERE vfr.date = ?
                ORDER BY vfr.date
            ");
            $stmt->execute([$date]);
            $report_data['vehicle_fuel'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $error_message .= "Vehicle Fuel Records query failed: " . $e->getMessage() . ". ";
        }
    }

    // Cash on Hand Calculations
    try {
        // Daily Cash on Hand (Cash Receipts - Cash Payments)
        $daily_cash_in = 0;
        if (!empty($report_data['cash_receipts'])) {
            $stmt = $pdo->prepare("
                SELECT SUM(amount) as total
                FROM cash_receipts
                WHERE date = ?
            ");
            $stmt->execute([$date]);
            $daily_cash_in = (float) ($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
        }

        $daily_cash_out = 0;
        if (!empty($report_data['cash_payments'])) {
            $stmt = $pdo->prepare("
                SELECT SUM(amount) as total
                FROM cash_payments
                WHERE date = ?
            ");
            $stmt->execute([$date]);
            $daily_cash_out = (float) ($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
        }

        $daily_cash_on_hand = $daily_cash_in - $daily_cash_out;

        // Cumulative Previous Cash on Hand (sum of all previous days)
        $stmt = $pdo->prepare("
            SELECT SUM(amount) as total
            FROM cash_on_hand
            WHERE date < ?
        ");
        $stmt->execute([$date]);
        $cumulative_previous = (float) ($stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        // Total Cash on Hand
        $total_cash_on_hand = $cumulative_previous + $daily_cash_on_hand;

        // Store daily Cash on Hand for future cumulative calculations
        $stmt = $pdo->prepare("
            INSERT INTO cash_on_hand (date, amount, created_at)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE amount = ?, created_at = NOW()
        ");
        $stmt->execute([$date, $daily_cash_on_hand, $daily_cash_on_hand]);

        $cash_on_hand_data = [
            'daily_cash_on_hand' => $daily_cash_on_hand,
            'cumulative_previous' => $cumulative_previous,
            'total_cash_on_hand' => $total_cash_on_hand
        ];
    } catch (Exception $e) {
        $error_message .= "Cash on Hand calculation failed: " . $e->getMessage() . ". ";
    }

    // Bank Account Balances
    try {
        $stmt = $pdo->prepare("
            SELECT ba.id, CONCAT(ba.bank_name, ' - ', ba.account_number) AS bank_account,
                   COALESCE((
                       SELECT SUM(bd.amount)
                       FROM bank_deposits bd
                       WHERE bd.bank_account_id = ba.id
                       AND bd.date <= ?
                   ), 0) - COALESCE((
                       SELECT SUM(bp.amount)
                       FROM bank_payments bp
                       WHERE bp.bank_account_id = ba.id
                       AND bp.date <= ?
                   ), 0) as balance
            FROM bank_accounts ba
            ORDER BY ba.bank_name, ba.account_number
        ");
        $stmt->execute([$date, $date]);
        $bank_balances = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $error_message .= "Bank Balances query failed: " . $e->getMessage() . ". ";
    }

    // Create Excel file if there's data
    if (!empty($report_data) || !empty($cash_on_hand_data) || !empty($bank_balances)) {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0); // Remove default sheet

        // Cash Receipts Sheet
        if (!empty($report_data['cash_receipts'])) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle('Cash Receipts');
            $sheet->setCellValue('A1', 'SL Diagnostics Cash Receipts Report');
            $sheet->setCellValue('A2', "Date: $date");
            $sheet->fromArray(
                ['ID', 'Date', 'Staff', 'Branch', 'Payment Method', 'Bank', 'Amount (₹)', 'User'],
                NULL,
                'A4'
            );
            $row = 5;
            foreach ($report_data['cash_receipts'] as $r) {
                $sheet->fromArray([
                    $r['id'],
                    $r['date'],
                    $r['staff_name'] ?: 'N/A',
                    $r['branch'],
                    $r['payment_method'],
                    $r['bank'] ?: '-',
                    number_format($r['amount'], 2),
                    $r['username']
                ], NULL, "A$row");
                $row++;
            }
        }

        // Bank Deposits Sheet
        if (!empty($report_data['bank_deposits'])) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle('Bank Deposits');
            $sheet->setCellValue('A1', 'SL Diagnostics Bank Deposits Report');
            $sheet->setCellValue('A2', "Date: $date");
            $sheet->fromArray(
                ['ID', 'Date', 'Bank', 'Deposit Type', 'Cheque Number', 'From Account', 'Amount (₹)', 'User'],
                NULL,
                'A4'
            );
            $row = 5;
            foreach ($report_data['bank_deposits'] as $r) {
                $sheet->fromArray([
                    $r['id'],
                    $r['date'],
                    $r['bank'],
                    $r['deposit_type'] ?: 'Cash',
                    $r['cheque_number'] ?: '-',
                    $r['from_account'] ?: '-',
                    number_format($r['amount'], 2),
                    $r['username']
                ], NULL, "A$row");
                $row++;
            }
        }

        // Cash Payments Sheet
        if (!empty($report_data['cash_payments'])) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle('Cash Payments');
            $sheet->setCellValue('A1', 'SL Diagnostics Cash Payments Report');
            $sheet->setCellValue('A2', "Date: $date");
            $sheet->fromArray(
                ['ID', 'Date', 'Heading', 'Description', 'Amount (₹)', 'User'],
                NULL,
                'A4'
            );
            $row = 5;
            foreach ($report_data['cash_payments'] as $r) {
                $sheet->fromArray([
                    $r['id'],
                    $r['date'],
                    $r['heading'],
                    $r['description'],
                    number_format($r['amount'], 2),
                    $r['username']
                ], NULL, "A$row");
                $row++;
            }
        }

        // Bank Payments Sheet
        if (!empty($report_data['bank_payments'])) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle('Bank Payments');
            $sheet->setCellValue('A1', 'SL Diagnostics Bank Payments Report');
            $sheet->setCellValue('A2', "Date: $date");
            $sheet->fromArray(
                ['ID', 'Date', 'Heading', 'Description', 'Amount (₹)', 'Bank Account', 'User'],
                NULL,
                'A4'
            );
            $row = 5;
            foreach ($report_data['bank_payments'] as $r) {
                $sheet->fromArray([
                    $r['id'],
                    $r['date'],
                    $r['heading'],
                    $r['description'],
                    number_format($r['amount'], 2),
                    $r['bank_account'],
                    $r['username']
                ], NULL, "A$row");
                $row++;
            }
        }

        // Vehicle Fuel Records Sheet
        if (!empty($report_data['vehicle_fuel'])) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle('Vehicle Fuel Records');
            $sheet->setCellValue('A1', 'SL Diagnostics Vehicle Fuel Records Report');
            $sheet->setCellValue('A2', "Date: $date");
            $sheet->fromArray(
                ['ID', 'Date', 'Vehicle', 'Fuel Amount (₹)', 'KM Reading', 'Payment Method', 'Bank Account', 'User'],
                NULL,
                'A4'
            );
            $row = 5;
            foreach ($report_data['vehicle_fuel'] as $r) {
                $sheet->fromArray([
                    $r['id'],
                    $r['date'],
                    $r['vehicle_name'],
                    number_format($r['fuel_amount'], 2),
                    $r['km_reading'],
                    $r['payment_method'],
                    $r['bank_account'] ?: '-',
                    $r['username']
                ], NULL, "A$row");
                $row++;
            }
        }

        // Cash on Hand and Bank Balances Sheet
        if (!empty($cash_on_hand_data) || !empty($bank_balances)) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle('Cash and Bank Balances');
            $sheet->setCellValue('A1', 'SL Diagnostics Cash and Bank Balances Report');
            $sheet->setCellValue('A2', "Date: $date");

            // Cash on Hand Section
            $sheet->setCellValue('A4', 'Cash on Hand Summary');
            $sheet->fromArray(
                ['Description', 'Amount (₹)'],
                NULL,
                'A5'
            );
            $sheet->fromArray(
                [
                    ['Daily Cash on Hand', number_format($cash_on_hand_data['daily_cash_on_hand'] ?? 0, 2)],
                    ['Cumulative Previous Cash on Hand', number_format($cash_on_hand_data['cumulative_previous'] ?? 0, 2)],
                    ['Total Cash on Hand', number_format($cash_on_hand_data['total_cash_on_hand'] ?? 0, 2)]
                ],
                NULL,
                'A6'
            );

            // Bank Balances Section
            if (!empty($bank_balances)) {
                $sheet->setCellValue('A10', 'Bank Account Balances');
                $sheet->fromArray(
                    ['Bank Account', 'Balance (₹)'],
                    NULL,
                    'A11'
                );
                $row = 12;
                foreach ($bank_balances as $b) {
                    $sheet->fromArray([
                        $b['bank_account'],
                        number_format($b['balance'], 2)
                    ], NULL, "A$row");
                    $row++;
                }
            }
        }

        // Save the Excel file
        $filename = $saveDir . "SL_Diagnostics_Report_$date.xlsx";
        $writer = new Xlsx($spreadsheet);
        $writer->save($filename);
        file_put_contents($saveDir . 'cron_log.txt', "[" . date('Y-m-d H:i:s') . "] Report for $date saved successfully as $filename\n", FILE_APPEND);

    } else {
        $message = "No data found for $date.";
        file_put_contents($saveDir . 'cron_log.txt', "[" . date('Y-m-d H:i:s') . "] $message\n", FILE_APPEND);
        echo $message;
    }

} catch (Exception $e) {
    $error = "Report generation failed: " . $e->getMessage();
    file_put_contents($saveDir . 'error_log.txt', "[" . date('Y-m-d H:i:s') . "] $error\n", FILE_APPEND);
    echo $error;
}
?>
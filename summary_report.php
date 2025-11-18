<?php
ob_start();
error_log("summary_report.php: Script started at " . date('Y-m-d H:i:s'));

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['user_id'])) {
    error_log("summary_report.php: User not logged in, redirecting to login.php");
    header('Location: login.php');
    exit;
}

try {
    error_log("summary_report.php: Attempting to include db_connect.php");
    require_once 'includes/db_connect.php';
} catch (Exception $e) {
    error_log("summary_report.php: Initialization failed: " . $e->getMessage());
    die("Initialization failed: " . htmlspecialchars($e->getMessage()));
}

$success_message = '';
$error_message = '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-d');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');

$summary_data = [
    'total_cash_receipts' => 0,
    'total_bank_deposits' => 0,
    'total_cash_payments' => 0,
    'total_bank_payments' => 0,
    'total_vehicle_fuel' => 0
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_summary'])) {
    error_log("summary_report.php: Processing POST request for summary report generation");
    try {
        $date_from = $_POST['date_from'];
        $date_to = $_POST['date_to'];

        if (empty($date_from) || empty($date_to)) {
            throw new Exception("Please select both start and end dates.");
        }
        if ($date_from > $date_to) {
            throw new Exception("Start date cannot be later than end date.");
        }

        // Total Cash Receipts
        try {
            $stmt = $pdo->prepare("SELECT SUM(amount) AS total FROM cash_receipts WHERE date BETWEEN ? AND ?");
            $stmt->execute([$date_from, $date_to]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $summary_data['total_cash_receipts'] = $result['total'] ?: 0;
            error_log("summary_report.php: Total Cash Receipts: " . $summary_data['total_cash_receipts']);
        } catch (Exception $e) {
            $error_message .= "Failed to get Total Cash Receipts: " . htmlspecialchars($e->getMessage()) . ". ";
            error_log("summary_report.php: Total Cash Receipts query failed: " . $e->getMessage());
        }

        // Total Bank Deposits
        try {
            $stmt = $pdo->prepare("SELECT SUM(amount) AS total FROM bank_deposits WHERE date BETWEEN ? AND ?");
            $stmt->execute([$date_from, $date_to]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $summary_data['total_bank_deposits'] = $result['total'] ?: 0;
            error_log("summary_report.php: Total Bank Deposits: " . $summary_data['total_bank_deposits']);
        } catch (Exception $e) {
            $error_message .= "Failed to get Total Bank Deposits: " . htmlspecialchars($e->getMessage()) . ". ";
            error_log("summary_report.php: Total Bank Deposits query failed: " . $e->getMessage());
        }

        // Total Cash Payments
        try {
            $stmt = $pdo->prepare("SELECT SUM(amount) AS total FROM cash_payments WHERE date BETWEEN ? AND ?");
            $stmt->execute([$date_from, $date_to]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $summary_data['total_cash_payments'] = $result['total'] ?: 0;
            error_log("summary_report.php: Total Cash Payments: " . $summary_data['total_cash_payments']);
        } catch (Exception $e) {
            $error_message .= "Failed to get Total Cash Payments: " . htmlspecialchars($e->getMessage()) . ". ";
            error_log("summary_report.php: Total Cash Payments query failed: " . $e->getMessage());
        }

        // Total Bank Payments
        try {
            $stmt = $pdo->prepare("SELECT SUM(amount) AS total FROM bank_payments WHERE date BETWEEN ? AND ?");
            $stmt->execute([$date_from, $date_to]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $summary_data['total_bank_payments'] = $result['total'] ?: 0;
            error_log("summary_report.php: Total Bank Payments: " . $summary_data['total_bank_payments']);
        } catch (Exception $e) {
            $error_message .= "Failed to get Total Bank Payments: " . htmlspecialchars($e->getMessage()) . ". ";
            error_log("summary_report.php: Total Bank Payments query failed: " . $e->getMessage());
        }

        // Total Vehicle Fuel Records
        try {
            $stmt = $pdo->prepare("SELECT SUM(fuel_amount) AS total FROM vehicle_fuel_records WHERE date BETWEEN ? AND ?");
            $stmt->execute([$date_from, $date_to]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $summary_data['total_vehicle_fuel'] = $result['total'] ?: 0;
            error_log("summary_report.php: Total Vehicle Fuel Records: " . $summary_data['total_vehicle_fuel']);
        } catch (Exception $e) {
            $error_message .= "Failed to get Total Vehicle Fuel Records: " . htmlspecialchars($e->getMessage()) . ". ";
            error_log("summary_report.php: Total Vehicle Fuel Records query failed: " . $e->getMessage());
        }

        $success_message = "Summary report generated for the selected date range.";

    } catch (Exception $e) {
        $error_message = "Summary report generation failed: " . htmlspecialchars($e->getMessage());
        error_log("summary_report.php: Summary report generation failed: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SL Diagnostics - Summary Report</title>
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
        .summary-table {
            margin-top: 1.5rem;
            width: 100%;
            border-collapse: collapse;
        }
        .summary-table th, .summary-table td {
            border: 1px solid #dee2e6;
            padding: 10px;
            text-align: left;
        }
        .summary-table th {
            background-color: #f2f2f2;
            font-weight: bold;
        }
        .summary-table td:last-child {
            text-align: right;
            font-weight: bold;
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
            .summary-table {
                width: 19cm;
                margin: 0 auto;
                border-collapse: collapse;
                table-layout: fixed;
                page-break-inside: auto;
            }
            .summary-table th, .summary-table td {
                border: 1px solid #000;
                padding: 0.2cm 0.3cm;
                vertical-align: middle;
                word-wrap: break-word;
            }
            .summary-table th {
                background-color: #e0e0e0;
                font-weight: bold;
                text-align: left; /* Keep left aligned for summary */
            }
            .summary-table td {
                text-align: left;
            }
            .summary-table td:last-child {
                text-align: right;
                font-weight: bold;
            }
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
        <h2>SL Diagnostics Summary Report</h2>
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
                        <a class="nav-link" href="reports.php">Detailed Reports</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="summary_report.php">Summary Reports</a>
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
        <h1 class="display-5 mb-4">Summary Report</h1>

        <?php if ($success_message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
        <?php elseif ($error_message): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">Generate Summary</div>
            <div class="card-body">
                <form method="POST">
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
                    <button type="submit" name="generate_summary" class="btn btn-primary mt-3">Generate Summary</button>
                    <?php if (!empty($summary_data) && array_sum($summary_data) > 0): // Only show export buttons if there's actual data ?>
                        <button type="button" id="export_pdf" class="btn btn-primary mt-3">Download PDF</button>
                        <button type="button" id="export_csv" class="btn btn-secondary mt-3">Download as Excel</button>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <?php if (!empty($summary_data) && array_sum($summary_data) > 0): ?>
            <h2 class="mt-4">Summary Results for <?php echo htmlspecialchars($date_from); ?> to <?php echo htmlspecialchars($date_to); ?></h2>
            <table id="summary_table" class="summary-table">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Total Amount (₹)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Total Cash Receipts</td>
                        <td><?php echo number_format($summary_data['total_cash_receipts'], 2); ?></td>
                    </tr>
                    <tr>
                        <td>Total Bank Deposits</td>
                        <td><?php echo number_format($summary_data['total_bank_deposits'], 2); ?></td>
                    </tr>
                    <tr>
                        <td>Total Cash Payments</td>
                        <td><?php echo number_format($summary_data['total_cash_payments'], 2); ?></td>
                    </tr>
                    <tr>
                        <td>Total Bank Payments</td>
                        <td><?php echo number_format($summary_data['total_bank_payments'], 2); ?></td>
                    </tr>
                     <tr>
                        <td>Total Vehicle Fuel Expenses</td>
                        <td><?php echo number_format($summary_data['total_vehicle_fuel'], 2); ?></td>
                    </tr>
                    <tr style="background-color: #e0e0e0; font-weight: bold;">
                        <td>Net Cash Flow (Receipts - Payments)</td>
                        <td><?php echo number_format($summary_data['total_cash_receipts'] + $summary_data['total_bank_deposits'] - $summary_data['total_cash_payments'] - $summary_data['total_bank_payments'] - $summary_data['total_vehicle_fuel'], 2); ?></td>
                    </tr>
                </tbody>
            </table>
        <?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && array_sum($summary_data) == 0 && empty($error_message)): ?>
            <div class="alert alert-info mt-4">No summary data found for the selected date range.</div>
        <?php endif; ?>
    </div>

    <footer>
        <p class="mb-0">© <?php echo date('Y'); ?> SL Diagnostics. All rights reserved.</p>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
    <script>
        // PDF Export
        const exportPdfButton = document.getElementById('export_pdf');
        if (exportPdfButton) {
            exportPdfButton.addEventListener('click', function() {
                console.log('PDF export button clicked for summary at', new Date().toISOString());
                try {
                    const { jsPDF } = window.jspdf;
                    const doc = new jsPDF();
                    let yOffset = 10;

                    doc.setFontSize(16);
                    doc.text('SL Diagnostics Summary Report', 14, yOffset);
                    yOffset += 10;
                    doc.setFontSize(10);
                    doc.text(`Date Range: <?php echo htmlspecialchars($date_from); ?> to <?php echo htmlspecialchars($date_to); ?>`, 14, yOffset);
                    yOffset += 10;


                    const table = document.getElementById('summary_table');
                    if (table) {
                        doc.autoTable({
                            html: `#summary_table`,
                            startY: yOffset,
                            theme: 'grid',
                            headStyles: { fillColor: [0, 86, 112] },
                            styles: { fontSize: 9, cellPadding: 2 },
                            columnStyles: {
                                1: { halign: 'right' } // Align the amount column to the right
                            }
                        });
                        yOffset = doc.lastAutoTable.finalY + 10;
                    } else {
                        alert('No summary data available to export as PDF.');
                        return;
                    }

                    const timestamp = new Date().toISOString().replace(/[-:T]/g, '').split('.')[0];
                    doc.save(`SL_Diagnostics_Summary_Report_${timestamp}.pdf`);
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
                console.log('CSV export button clicked for summary at', new Date().toISOString());
                try {
                    let csvContent = '';
                    csvContent += `SL Diagnostics Summary Report\n`;
                    csvContent += `Date Range: <?php echo htmlspecialchars($date_from); ?> to <?php echo htmlspecialchars($date_to); ?>\n\n`;

                    const table = document.getElementById('summary_table');
                    if (table) {
                        const rows = table.querySelectorAll('tr');
                        rows.forEach(row => {
                            const cols = row.querySelectorAll('th, td');
                            const rowData = Array.from(cols).map(col => `"${col.innerText.replace(/"/g, '""')}"`).join(',');
                            csvContent += rowData + '\n';
                        });
                    } else {
                        alert('No summary data available to export as CSV.');
                        return;
                    }

                    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
                    const link = document.createElement('a');
                    const timestamp = new Date().toISOString().replace(/[-:T]/g, '').split('.')[0];
                    link.setAttribute('href', URL.createObjectURL(blob));
                    link.setAttribute('download', `SL_Diagnostics_Summary_Report_${timestamp}.csv`);
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                } catch (error) {
                    console.error('CSV export error:', error);
                    alert('Failed to export CSV file. Check console for details.');
                }
            });
        }
    </script>
</body>
</html>
<?php
ob_end_flush();
error_log("summary_report.php: Script ended at " . date('Y-m-d H:i:s'));
?>
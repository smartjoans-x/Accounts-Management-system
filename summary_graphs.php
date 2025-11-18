<?php
ob_start();
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1); // For development/debugging purposes only.

// Define constants for paths
define('ASSETS_IMG_PATH', '/accounts/assets/img/');

// --- User Authentication & Prerequisite Check ---
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
// This page should only be accessed via POST from reports.php with selections
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['report_types'])) {
    // Redirect back to reports.php with an error if accessed directly
    $_SESSION['error_message'] = "Please generate a report from the Reports page to view graphs.";
    header('Location: reports.php');
    exit;
}

// --- Database Connection ---
try {
    require_once 'includes/db_connect.php'; // Ensure this path is correct
} catch (Exception $e) {
    error_log("summary_graphs.php: Database connection failed: " . $e->getMessage());
    die("Critical Error: Could not connect to the database. Please contact support.");
}

// --- Helper Functions ---
/**
 * Formats a number into the Indian numbering system (crores, lakhs) with 2 decimal places.
 * Uses NumberFormatter if intl extension is available, otherwise falls back to regex.
 *
 * @param float|int|string $number The number to format.
 * @return string The formatted number string with Rupee symbol.
 */
function formatIndianCurrency($number) {
    $number = (float)$number;

    // Check if the intl extension is loaded for NumberFormatter
    if (extension_loaded('intl')) {
        $formatter = new NumberFormatter('en_IN', NumberFormatter::CURRENCY);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 2);
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, 2);
        // NumberFormatter automatically adds the currency symbol
        return $formatter->format($number);
    } else {
        // Fallback to manual regex formatting if intl is not available
        $formatted = number_format($number, 2, '.', ''); // Ensure 2 decimal places

        $parts = explode('.', $formatted);
        $integer_part = $parts[0];
        $decimal_part = $parts[1] ?? '00'; // Default to '00' if no decimal part

        if ($integer_part === '0') {
            return "₹ 0." . $decimal_part;
        }

        $num_length = strlen($integer_part);
        $formatted_integer = '';

        if ($num_length > 3) {
            $last_three = substr($integer_part, -3);
            $rest = substr($integer_part, 0, -3);
            // This regex adds commas after every two digits from the right, excluding the last three
            $formatted_rest = preg_replace("/(\d+?)(?=(\d{2})+(?!\d))/", "$1,", $rest);
            $formatted_integer = $formatted_rest . ',' . $last_three;
        } else {
            $formatted_integer = $integer_part;
        }

        return '₹ ' . $formatted_integer . '.' . $decimal_part;
    }
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

// --- Initial Variable Setup ---
$date_from = $_POST['date_from'] ?? date('Y-m-d');
$date_to = $_POST['date_to'] ?? date('Y-m-d');
$report_types = $_POST['report_types'] ?? [];
$receipt_sub_types = $_POST['receipt_sub_types'] ?? [];

// Initialize summary data structure
$summary_data = [
    'cash_receipts_total' => 0,
    'branch_receipts_total' => 0,
    'other_receipts_total' => 0,
    'bank_deposits_total' => 0,
    'cash_payments_total' => 0,
    'bank_payments_total' => 0,
    'vehicle_fuel_total' => 0,
    'cash_receipts_by_date' => [],
    'cash_payments_by_date' => [],
    'bank_deposits_by_date' => [], // Added for completeness, though not currently charted
    'bank_payments_by_date' => [],
    'cash_receipts_by_payment_mode' => [],
];

try {
    // Pre-fill all dates in the range with 0 for time-series charts
    $period = new DatePeriod(new DateTime($date_from), new DateInterval('P1D'), (new DateTime($date_to))->modify('+1 day'));
    foreach ($period as $date) {
        $date_key = $date->format('Y-m-d');
        $summary_data['cash_receipts_by_date'][$date_key] = 0;
        $summary_data['cash_payments_by_date'][$date_key] = 0;
        $summary_data['bank_deposits_by_date'][$date_key] = 0; // Initialize
        $summary_data['bank_payments_by_date'][$date_key] = 0;
    }

    // Helper function to fetch and sum data by date
    function fetchAndSumDataByDate($pdo, $table, $amountColumn, $dateColumn, $date_from, $date_to) {
        $query = "SELECT $dateColumn as date, SUM($amountColumn) as total_amount FROM $table WHERE $dateColumn BETWEEN ? AND ? GROUP BY $dateColumn ORDER BY $dateColumn";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$date_from, $date_to]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // --- Data Fetching Logic ---
    // Cash Receipts (Branch and Other)
    if (in_array('cash_receipts', $report_types)) {
        if (in_array('branch_receipts', $receipt_sub_types)) {
            $stmt = $pdo->prepare("SELECT SUM(amount) AS total FROM cash_receipts WHERE date BETWEEN ? AND ?");
            $stmt->execute([$date_from, $date_to]);
            $summary_data['branch_receipts_total'] = $stmt->fetchColumn() ?: 0;
            
            foreach (fetchAndSumDataByDate($pdo, 'cash_receipts', 'amount', 'date', $date_from, $date_to) as $row) {
                $summary_data['cash_receipts_by_date'][$row['date']] += $row['total_amount'];
            }
            
            $stmt_pm = $pdo->prepare("SELECT payment_method, SUM(amount) AS total_amount FROM cash_receipts WHERE date BETWEEN ? AND ? GROUP BY payment_method");
            $stmt_pm->execute([$date_from, $date_to]);
            foreach ($stmt_pm->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $summary_data['cash_receipts_by_payment_mode'][$row['payment_method']] = ($summary_data['cash_receipts_by_payment_mode'][$row['payment_method']] ?? 0) + $row['total_amount'];
            }
        }
        if (in_array('other_receipts', $receipt_sub_types)) {
            $stmt = $pdo->prepare("SELECT SUM(amount) AS total FROM other_receipts WHERE date BETWEEN ? AND ?");
            $stmt->execute([$date_from, $date_to]);
            $summary_data['other_receipts_total'] = $stmt->fetchColumn() ?: 0;

            foreach (fetchAndSumDataByDate($pdo, 'other_receipts', 'amount', 'date', $date_from, $date_to) as $row) {
                $summary_data['cash_receipts_by_date'][$row['date']] += $row['total_amount'];
            }

            $stmt_pm = $pdo->prepare("SELECT payment_method, SUM(amount) AS total_amount FROM other_receipts WHERE date BETWEEN ? AND ? GROUP BY payment_method");
            $stmt_pm->execute([$date_from, $date_to]);
            foreach ($stmt_pm->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $summary_data['cash_receipts_by_payment_mode'][$row['payment_method']] = ($summary_data['cash_receipts_by_payment_mode'][$row['payment_method']] ?? 0) + $row['total_amount'];
            }
        }
        $summary_data['cash_receipts_total'] = $summary_data['branch_receipts_total'] + $summary_data['other_receipts_total'];
    }

    // Bank Deposits
    if (in_array('bank_deposits', $report_types)) {
        $stmt = $pdo->prepare("SELECT SUM(amount) AS total FROM bank_deposits WHERE date BETWEEN ? AND ?");
        $stmt->execute([$date_from, $date_to]);
        $summary_data['bank_deposits_total'] = $stmt->fetchColumn() ?: 0;
        foreach (fetchAndSumDataByDate($pdo, 'bank_deposits', 'amount', 'date', $date_from, $date_to) as $row) {
            $summary_data['bank_deposits_by_date'][$row['date']] += $row['total_amount'];
        }
    }

    // Cash Payments
    if (in_array('cash_payments', $report_types)) {
        $stmt = $pdo->prepare("SELECT SUM(amount) AS total FROM cash_payments WHERE date BETWEEN ? AND ?");
        $stmt->execute([$date_from, $date_to]);
        $summary_data['cash_payments_total'] = $stmt->fetchColumn() ?: 0;
        foreach (fetchAndSumDataByDate($pdo, 'cash_payments', 'amount', 'date', $date_from, $date_to) as $row) {
            $summary_data['cash_payments_by_date'][$row['date']] += $row['total_amount'];
        }
    }

    // Bank Payments
    if (in_array('bank_payments', $report_types)) {
        $stmt = $pdo->prepare("SELECT SUM(amount) AS total FROM bank_payments WHERE date BETWEEN ? AND ?");
        $stmt->execute([$date_from, $date_to]);
        $summary_data['bank_payments_total'] = $stmt->fetchColumn() ?: 0;
        foreach (fetchAndSumDataByDate($pdo, 'bank_payments', 'amount', 'date', $date_from, $date_to) as $row) {
            $summary_data['bank_payments_by_date'][$row['date']] += $row['total_amount'];
        }
    }

    // Vehicle Fuel Records
    if (in_array('vehicle_fuel', $report_types)) {
        $stmt = $pdo->prepare("SELECT SUM(fuel_amount) AS total FROM vehicle_fuel_records WHERE date BETWEEN ? AND ?");
        $stmt->execute([$date_from, $date_to]);
        $summary_data['vehicle_fuel_total'] = $stmt->fetchColumn() ?: 0;
    }

} catch (Exception $e) {
    error_log("summary_graphs.php: Data fetching failed: " . $e->getMessage());
    die("Error fetching summary data: " . htmlspecialchars($e->getMessage()));
}

// --- Prepare data for Chart.js ---
// Format labels for display (DD-MM-YYYY)
$chart_display_labels = array_map('formatDisplayDate', array_keys($summary_data['cash_receipts_by_date']));

$chart_data_js = [
    'labels' => $chart_display_labels,
    'datasets' => [],
    'payment_mode_labels' => array_keys($summary_data['cash_receipts_by_payment_mode']),
    'payment_mode_amounts' => array_values($summary_data['cash_receipts_by_payment_mode']),
];

if (in_array('cash_receipts', $report_types)) {
    $chart_data_js['datasets'][] = [
        'label' => 'Cash Receipts',
        'data' => array_values($summary_data['cash_receipts_by_date']),
        'borderColor' => 'rgb(75, 192, 192)',
        'backgroundColor' => 'rgba(75, 192, 192, 0.1)',
        'fill' => true,
        'tension' => 0.3
    ];
}
if (in_array('cash_payments', $report_types)) {
    $chart_data_js['datasets'][] = [
        'label' => 'Cash Payments',
        'data' => array_values($summary_data['cash_payments_by_date']),
        'borderColor' => 'rgb(255, 99, 132)',
        'backgroundColor' => 'rgba(255, 99, 132, 0.1)',
        'fill' => true,
        'tension' => 0.3
    ];
}
if (in_array('bank_payments', $report_types)) {
    $chart_data_js['datasets'][] = [
        'label' => 'Bank Payments',
        'data' => array_values($summary_data['bank_payments_by_date']),
        'borderColor' => 'rgb(54, 162, 235)',
        'backgroundColor' => 'rgba(54, 162, 235, 0.1)',
        'fill' => true,
        'tension' => 0.3
    ];
}

// Filter out datasets that are all zeros if no data was found for that type for the date range
$chart_data_js['datasets'] = array_filter($chart_data_js['datasets'], function($dataset) {
    return array_sum($dataset['data']) > 0;
});

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SL Diagnostics - Summary Graphs</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700&family=Roboto:wght@400;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/js/all.min.js"></script>
    <style>
        :root {
            --primary-color: #005670;
            --secondary-color: #495057;
            --light-gray: #f8f9fa;
            --border-color: #dee2e6;
            --font-family-main: 'Inter', sans-serif;
        }
        body {
            font-family: var(--font-family-main);
            background-color: var(--light-gray);
        }
        .navbar {
            background-color: #ffffff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .navbar-brand img { max-height: 40px; }
        .navbar-brand span { font-size: 1.5rem; font-weight: 700; color: var(--primary-color); }
        .content {
            margin-top: 100px;
            padding: 2rem;
        }
        .page-header {
            border-bottom: 1px solid var(--border-color);
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap; /* Added for responsiveness */
        }
        h1.page-title {
            color: var(--primary-color);
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        .chart-card {
            background-color: #fff;
            padding: 1.5rem;
            border-radius: 0.75rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.07);
            margin-bottom: 2rem;
            height: 100%;
            display: flex;
            flex-direction: column;
        }
        .chart-card-header {
            color: var(--primary-color);
            font-weight: 700;
            font-size: 1.25rem;
            margin-bottom: 0.5rem;
        }
        .chart-card-summary {
            font-size: 1rem;
            color: var(--secondary-color);
            margin-bottom: 1.5rem;
        }
        /* --- Chart container specific styles for better control --- */
        .chart-container {
            position: relative;
            flex-grow: 1; 
            /* Instead of fixed height, use aspect-ratio for flexible height based on width */
            /* This makes the height 40% of the width */
            aspect-ratio: 2.5 / 1; /* Adjust this ratio as needed (width / height) */
            min-height: 300px; /* Ensure a minimum height for very narrow screens */
            max-height: 600px; /* Prevent it from becoming excessively tall on very wide screens */
            display: flex;
            align-items: center;
            justify-content: center;
            padding-bottom: 10px; /* Give a little space below chart */
        }
        /* For very small screens, if aspect-ratio makes it too short */
        @media (max-width: 575.98px) {
            .chart-container {
                aspect-ratio: 1.5 / 1; /* Make it a bit taller on very small screens */
                min-height: 250px;
            }
        }
        /* --- End Chart container specific styles --- */

        .no-data-overlay {
            font-size: 1.2rem;
            color: #6c757d;
            text-align: center;
            padding: 20px;
        }
        .breadcrumb-item + .breadcrumb-item::before {
            content: var(--bs-breadcrumb-divider, "/") !important;
        }
        .btn-back-to-reports {
            margin-top: 1rem;
        }
        @media (min-width: 768px) {
            .btn-back-to-reports {
                margin-top: 0;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg fixed-top">
        <div class="container-fluid">
            <a class="navbar-brand d-flex align-items-center gap-2" href="dashboard.php">
                <img src="<?php echo ASSETS_IMG_PATH; ?>logo.png" alt="SL Diagnostics Logo">
                <span>SL Diagnostics</span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav"><span class="navbar-toggler-icon"></span></button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="cash_receipts.php">Cash Receipts</a></li>
                    <li class="nav-item"><a class="nav-link" href="bank_deposits.php">Bank Deposits</a></li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">Payments</a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="cash_payments.php">Cash Payments</a></li>
                            <li><a class="dropdown-item" href="bank_payments.php">Bank Payments</a></li>
                        </ul>
                    </li>
                    <li class="nav-item"><a class="nav-link" href="vehicle_fuel.php">Vehicle Fuel</a></li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle active" href="#" role="button" data-bs-toggle="dropdown">Reports</a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="reports.php">Standard Reports</a></li>
                            <li><a class="dropdown-item active" href="summary_graphs.php">Summary Graphs</a></li>
                        </ul>
                    </li>
                    <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'Admin'): ?>
                        <li class="nav-item"><a class="nav-link" href="user_management.php">User Management</a></li>
                    <?php endif; ?>
                    <li class="nav-item"><a class="nav-link" href="logout.php">Sign Out</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="content container-fluid">
        <div class="page-header pb-3">
            <div>
                <h1 class="page-title">Financial Summary Graphs</h1>
                <p class="date-range-text mb-0">Showing summary for: <strong><?php echo htmlspecialchars(formatDisplayDate($date_from)); ?></strong> to <strong><?php echo htmlspecialchars(formatDisplayDate($date_to)); ?></strong></p>
            </div>
            <div>
                <form id="backToReportsForm" action="reports.php" method="POST">
                    <input type="hidden" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                    <input type="hidden" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                    <?php foreach ($_POST['report_types'] ?? [] as $type): // Use $_POST directly to retain all selected values ?>
                        <input type="hidden" name="report_types[]" value="<?php echo htmlspecialchars($type); ?>">
                    <?php endforeach; ?>
                    <?php foreach ($_POST['receipt_sub_types'] ?? [] as $sub_type): // Use $_POST directly ?>
                        <input type="hidden" name="receipt_sub_types[]" value="<?php echo htmlspecialchars($sub_type); ?>">
                    <?php endforeach; ?>
                    <input type="hidden" name="generate_report" value="1"> 
                    <button type="submit" class="btn btn-secondary btn-back-to-reports"><i class="fas fa-arrow-left me-2"></i>Back to Reports</button>
                </form>
            </div>
        </div>
        
        <div class="row">
            <?php
            // Check if there is any data to display in the financial flow chart
            $has_financial_flow_data = false;
            foreach ($chart_data_js['datasets'] as $dataset) {
                if (array_sum($dataset['data']) > 0) {
                    $has_financial_flow_data = true;
                    break;
                }
            }

            // Only display the financial flow chart card if any related report type was selected
            if (!empty(array_intersect(['cash_receipts', 'cash_payments', 'bank_payments'], $report_types))):
            ?>
            <div class="col-xl-12">
                <div class="chart-card">
                    <div class="chart-card-header">Daily Financial Flow (Receipts & Payments)</div>
                    <div class="chart-card-summary">Comparison of daily cash receipts, cash payments, and bank payments.</div>
                    <div class="chart-container">
                        <?php if ($has_financial_flow_data): ?>
                            <canvas id="financialFlowChart"></canvas>
                        <?php else: ?>
                            <div class="no-data-overlay">No data available for this chart in the selected date range.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php
            // Check if there is any data for the payment mode chart
            $has_payment_mode_data = in_array('cash_receipts', $report_types) && !empty($chart_data_js['payment_mode_labels']) && array_sum($chart_data_js['payment_mode_amounts']) > 0;
            
            // Only display the payment mode chart card if cash receipts were selected
            if (in_array('cash_receipts', $report_types)):
            ?>
            <div class="col-xl-6 col-lg-12">
                <div class="chart-card">
                    <div class="chart-card-header">Receipts by Payment Mode</div>
                    <p class="chart-card-summary">Total: <strong><?php echo formatIndianCurrency($summary_data['cash_receipts_total']); ?></strong></p>
                    <div class="chart-container">
                        <?php if ($has_payment_mode_data): ?>
                            <canvas id="paymentModeChart"></canvas>
                        <?php else: ?>
                            <div class="no-data-overlay">No payment mode data found for cash receipts in the selected date range.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <?php
            // Only display the vehicle fuel card if vehicle fuel was selected
            if (in_array('vehicle_fuel', $report_types)):
            ?>
            <div class="col-xl-6 col-lg-12">
                <div class="chart-card">
                    <div class="chart-card-header">Vehicle Fuel Expenses</div>
                    <p class="chart-card-summary">Total: <strong><?php echo formatIndianCurrency($summary_data['vehicle_fuel_total']); ?></strong></p>
                    <div class="chart-container d-flex align-items-center justify-content-center">
                        <div class="text-center p-4">
                            <?php if ($summary_data['vehicle_fuel_total'] > 0): ?>
                                <p class="lead">Total Vehicle Fuel Expenses:</p>
                                <h3><?php echo formatIndianCurrency($summary_data['vehicle_fuel_total']); ?></h3>
                                <small class="text-muted">(A daily breakdown is not charted for fuel as it's typically tracked as a total expense.)</small>
                            <?php else: ?>
                                <div class="no-data-overlay">No vehicle fuel records found for the selected date range.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <footer class="mt-5 py-4 bg-white border-top"><p class="text-center mb-0">© <?php echo date('Y'); ?> SL Diagnostics. All rights reserved.</p></footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const chartDataJs = <?php echo json_encode($chart_data_js); ?>;
        
        // --- Utility to format tooltips in Indian currency format ---
        const tooltipCallback = (context) => {
            let label = context.dataset.label || '';
            if (label) {
                label += ': ';
            }
            if (context.parsed.y !== null) {
                // Use a standard approach for formatting currency in JS
                return label + new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR' }).format(context.parsed.y);
            }
            return label;
        };

        const pieTooltipCallback = (context) => {
            let label = context.label || '';
            if (label) {
                label += ': ';
            }
            if (context.parsed !== null) {
                // Use a standard approach for formatting currency in JS
                return label + new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR' }).format(context.parsed);
            }
            return label;
        };
        
        // --- Chart Configurations ---

        // Financial Flow Chart
        const financialFlowCanvas = document.getElementById('financialFlowChart');
        // Only attempt to create chart if canvas exists AND there is data
        if (financialFlowCanvas && chartDataJs.datasets.length > 0) {
            new Chart(financialFlowCanvas.getContext('2d'), {
                type: 'line',
                data: {
                    labels: chartDataJs.labels,
                    datasets: chartDataJs.datasets
                },
                options: { 
                    responsive: true, 
                    maintainAspectRatio: false, // Keep this false to let our CSS control
                    plugins: { 
                        tooltip: { callbacks: { label: tooltipCallback } },
                        legend: {
                            display: true,
                            position: 'top'
                        }
                    }, 
                    scales: { 
                        y: { 
                            beginAtZero: true, 
                            title: { display: true, text: 'Amount (INR)'},
                            ticks: {
                                callback: function(value, index, ticks) {
                                    // Use Intl.NumberFormat for compact display on y-axis
                                    // This is a JavaScript API, not related to PHP's intl extension
                                    return new Intl.NumberFormat('en-IN', { 
                                        notation: 'compact',
                                        compactDisplay: 'short',
                                        minimumFractionDigits: 0, 
                                        maximumFractionDigits: 1
                                    }).format(value);
                                }
                            }
                        },
                        x: { 
                            title: { display: true, text: 'Date'}
                        }
                    }
                }
            });
        }

        // Payment Mode Chart
        const paymentModeCanvas = document.getElementById('paymentModeChart');
        // Only attempt to create chart if canvas exists AND there is data
        if (paymentModeCanvas && chartDataJs.payment_mode_labels.length > 0 && chartDataJs.payment_mode_amounts.some(val => val > 0)) {
            new Chart(paymentModeCanvas.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: chartDataJs.payment_mode_labels,
                    datasets: [{
                        data: chartDataJs.payment_mode_amounts,
                        backgroundColor: ['#007bff', '#28a745', '#ffc107', '#dc3545', '#17a2b8', '#6c757d', '#fd7e14', '#e83e8c'], // More colors
                        hoverOffset: 4
                    }]
                },
                options: { 
                    responsive: true, 
                    maintainAspectRatio: false, // Keep this false
                    plugins: { 
                        legend: { position: 'top' }, 
                        tooltip: { callbacks: { label: pieTooltipCallback } } 
                    } 
                }
            });
        }
    });
    </script>
</body>
</html>
<?php
ob_end_flush();
?>
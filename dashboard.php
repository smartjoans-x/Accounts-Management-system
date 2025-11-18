<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// --- REFACTORED & SIMPLIFIED FUNCTION ---
// Custom function for Indian number format without decimals.
function formatIndianNumber($number) {
    // Ensure the number is treated as a float and then floored
    $number = floor(floatval($number));
    if ($number == 0) {
        return "0";
    }
    $number_str = (string)$number;
    $last_three = substr($number_str, -3);
    $rest_units = substr($number_str, 0, -3);
    if ($rest_units != '') {
        // Add commas for every two digits in the 'rest_units' part
        $rest_units = preg_replace("/\B(?=(\d{2})+(?!\d))/", ",", $rest_units);
        return $rest_units . ',' . $last_three;
    }
    return $last_three;
}

try {
    require_once 'includes/db_connect.php'; // Assuming this file connects to PDO
} catch (Exception | PDOException $e) { // Catch both base Exception and PDOException
    // Log the error and display a user-friendly message
    error_log("Database connection failed: " . $e->getMessage());
    die("Unable to connect to the database. Please try again later.");
}

// --- MAIN DASHBOARD DATE HANDLING (SINGLE DAY) ---
// Allow user to select a date, default to today.
$date = isset($_GET['date']) && preg_match("/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/", $_GET['date']) ? $_GET['date'] : date('Y-m-d');
$prev_date = date('Y-m-d', strtotime($date . ' -1 day'));
$next_date = date('Y-m-d', strtotime($date . ' +1 day'));
$today_date = date('Y-m-d');

// --- INITIALIZE VARIABLES FOR KPIs (based on $date) ---
$cash_on_hand_today = 0.00; // Cash on hand for the selected specific date
$cumulative_prev_cash_on_hand = 0.00; // Cumulative up to the day BEFORE the selected date
$total_cash_on_hand = 0.00; // Sum of cumulative prev + today's cash on hand
$total_bank_balance = 0.00;
$todays_total_income = 0.00; // Income for the selected date
$bank_accounts = [];

$todays_cash_payments = 0.00;
$todays_bank_payments = 0.00;
$todays_vehicle_fuel = 0.00;
$todays_total_expenses = 0.00;

// Initialize this variable to avoid the "Undefined variable" warning
$has_income_data_for_chart = false; 

// Fetch Cash on Hand for the selected date
try {
    $stmt = $pdo->prepare("SELECT amount FROM cash_on_hand WHERE date = ?");
    $stmt->execute([$date]);
    $cash_on_hand_today = $stmt->fetchColumn() ?: 0.00;
} catch (PDOException $e) {
    error_log("Error fetching cash on hand for selected date: " . $e->getMessage());
}

// Fetch Cumulative Previous Cash on Hand (sum of all entries BEFORE the selected date)
try {
    $stmt = $pdo->prepare("SELECT SUM(amount) FROM cash_on_hand WHERE date < ?");
    $stmt->execute([$date]);
    $cumulative_prev_cash_on_hand = $stmt->fetchColumn() ?: 0.00;
} catch (PDOException $e) {
    error_log("Error fetching cumulative previous cash on hand: " . $e->getMessage());
}

$total_cash_on_hand = $cash_on_hand_today + $cumulative_prev_cash_on_hand;

// Fetch Bank Accounts and calculate total balance (this is usually real-time or end-of-day balance, so not date-range dependent for this view)
try {
    $stmt = $pdo->prepare("SELECT bank_name, account_number, balance FROM bank_accounts");
    $stmt->execute();
    $bank_accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total_bank_balance = array_sum(array_column($bank_accounts, 'balance'));
} catch (PDOException $e) {
    error_log("Error fetching bank accounts: " . $e->getMessage());
}

// --- FETCH TODAY'S EXPENSES (FOR SELECTED DATE) ---
try {
    $stmt = $pdo->prepare("SELECT SUM(amount) FROM cash_payments WHERE date = ?");
    $stmt->execute([$date]);
    $todays_cash_payments = $stmt->fetchColumn() ?: 0.00;

    $stmt = $pdo->prepare("SELECT SUM(amount) FROM bank_payments WHERE date = ?");
    $stmt->execute([$date]);
    $todays_bank_payments = $stmt->fetchColumn() ?: 0.00;

    $stmt = $pdo->prepare("SELECT SUM(fuel_amount) FROM vehicle_fuel_records WHERE date = ?");
    $stmt->execute([$date]);
    $todays_vehicle_fuel = $stmt->fetchColumn() ?: 0.00;

    $todays_total_expenses = $todays_cash_payments + $todays_bank_payments + $todays_vehicle_fuel;
} catch (PDOException $e) {
    error_log("Error fetching today's expenses: " . $e->getMessage());
}

// --- Fetch Today's Income (for the main KPI) ---
// This needs to be calculated for the specific $date
try {
    $query_todays_income = "
        SELECT SUM(total_amount) FROM (
            SELECT amount as total_amount FROM cash_receipts WHERE date = ?
            UNION ALL
            SELECT amount as total_amount FROM other_receipts WHERE date = ?
        ) as combined_todays_receipts
    ";
    $stmt = $pdo->prepare($query_todays_income);
    $stmt->execute([$date, $date]);
    $todays_total_income = $stmt->fetchColumn() ?: 0.00;
} catch (PDOException $e) {
    error_log("Error fetching today's total income: " . $e->getMessage());
}

// CSV Export Logic (for the 7-day chart data, if requested)
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    // This part now uses the logic from get_chart_data.php but integrated here for direct export
    $export_start_date = isset($_GET['export_start_date']) ? $_GET['export_start_date'] : date('Y-m-d', strtotime('-6 days', strtotime($date)));
    $export_end_date = isset($_GET['export_end_date']) ? $_GET['export_end_date'] : $date;

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="income_data_' . $export_start_date . '_to_' . $export_end_date . '.csv"');

    $output = fopen('php://output', 'w');

    // Re-fetch branches and data specifically for CSV to ensure consistency
    $branches_for_csv = [];
    $dates_for_csv_chart = [];
    $income_data_for_csv = [];

    try {
        $stmt_csv_branches = $pdo->query("
            (SELECT DISTINCT branch FROM cash_receipts WHERE branch IS NOT NULL AND branch != '')
            UNION
            (SELECT DISTINCT department AS branch FROM other_receipts WHERE department IS NOT NULL AND department != '')
            ORDER BY branch
        ");
        $branches_for_csv = $stmt_csv_branches->fetchAll(PDO::FETCH_COLUMN);

        $period_csv = new DatePeriod(
            new DateTime($export_start_date),
            new DateInterval('P1D'),
            new DateTime($export_end_date . ' +1 day')
        );
        foreach ($period_csv as $dt) {
            $dates_for_csv_chart[] = $dt->format('Y-m-d');
        }

        foreach ($branches_for_csv as $branch) {
            $income_data_for_csv[$branch] = array_fill_keys($dates_for_csv_chart, 0.00);
        }
        
        $query_csv = "
            SELECT date, branch, SUM(amount) as total_amount FROM (
                SELECT date, branch, amount FROM cash_receipts WHERE date BETWEEN ? AND ?
                UNION ALL
                SELECT date, department as branch, amount FROM other_receipts WHERE date BETWEEN ? AND ?
            ) as combined_receipts
            GROUP BY date, branch
            ORDER BY date ASC
        ";
        $stmt_csv_data = $pdo->prepare($query_csv);
        $stmt_csv_data->execute([$export_start_date, $export_end_date, $export_start_date, $export_end_date]);
        $all_receipts_csv = $stmt_csv_data->fetchAll(PDO::FETCH_ASSOC);

        foreach ($all_receipts_csv as $receipt) {
            if (isset($income_data_for_csv[$receipt['branch']][$receipt['date']])) {
                $income_data_for_csv[$receipt['branch']][$receipt['date']] += $receipt['total_amount'];
            }
        }
        
        // This is where $has_income_data_for_chart would be set for the main page rendering
        // But since it's an export block, we just proceed with CSV generation.
        // For the main page rendering, it's now initialized to false above.

        // CSV Header Row
        $csv_header = ['Branch'];
        foreach ($dates_for_csv_chart as $d) {
            $csv_header[] = date('M d, Y', strtotime($d));
        }
        fputcsv($output, $csv_header);

        // CSV Data Rows
        foreach ($income_data_for_csv as $branch => $amounts) {
            $row = [$branch];
            foreach ($amounts as $amount) {
                $row[] = $amount;
            }
            fputcsv($output, $row);
        }
        
    } catch (PDOException $e) {
        error_log("Error during CSV export: " . $e->getMessage());
        fputcsv($output, ["Error: Could not retrieve data for export."]);
    }

    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financial Dashboard - SL Diagnostics</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css"> <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-zoom@2.0.1/dist/chartjs-plugin-zoom.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script> <style>
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f4f7f6;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .navbar {
            background-color: #ffffff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 1rem 0;
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        .navbar-brand {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .navbar-brand img { max-height: 40px; }
        .navbar-brand span { font-size: 1.5rem; font-weight: 700; color: #005670; }
        .content {
            padding: 2rem;
            margin-top: 20px;
            flex-grow: 1;
        }
        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 6px 20px rgba(0,0,0,0.1); /* Stronger shadow */
            transition: transform 0.3s ease-in-out, box-shadow 0.3s ease-in-out;
            height: 100%;
        }
        .card:hover { 
            transform: translateY(-8px); 
            box-shadow: 0 10px 30px rgba(0,0,0,0.15); /* Enhanced hover shadow */
        }
        .card-header {
            background-color: #005670;
            color: white;
            font-weight: 600;
            border-radius: 12px 12px 0 0 !important;
            padding: 1rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap; /* Allow wrapping on smaller screens */
        }
        .card-header .chart-header-controls {
            flex-grow: 1; /* Allow controls to take available space */
            justify-content: flex-start; /* Align date picker to start */
            margin-bottom: 0.5rem; /* Add margin for wrapping */
        }
        .card-header .btn-group {
            margin-left: auto; /* Push buttons to the right */
        }

        .card-body { padding: 1.5rem; }
        
        .kpi-card { text-align: left; }
        .kpi-card .card-body { display: flex; align-items: center; gap: 1.5rem; }
        .kpi-card .kpi-icon {
            font-size: 2.5rem;
            color: #005670;
            background-color: rgba(0, 86, 112, 0.1);
            border-radius: 50%;
            height: 70px;
            width: 70px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.06); /* Inner shadow for depth */
        }
        .kpi-card .kpi-content { flex-grow: 1; }
        .kpi-card .kpi-title { font-size: 0.9rem; font-weight: 600; color: #6c757d; margin-bottom: 0.25rem; }
        .kpi-card .kpi-value { font-size: 2rem; font-weight: 700; color: #343a40; line-height: 1.2; }
        .kpi-breakdown { 
            margin-top: 0.75rem; 
            font-size: 0.8rem; 
            color: #495057; 
            line-height: 1.5; 
            padding-top: 0.5rem;
            border-top: 1px dashed #e0e0e0;
        }
        .kpi-breakdown .label { color: #6c757d; }

        .balance-column { text-align: right; }
        .table-hover tbody tr:hover { background-color: rgba(0, 86, 112, 0.05); }
        tfoot { font-weight: 700; }

        .chart-container { 
            position: relative; 
            height: 450px; /* Main chart height */
            width: 100%; 
            background-color: #fff;
            border-radius: 8px;
            padding: 1rem;
            box-shadow: inset 0 0 8px rgba(0,0,0,0.05);
        }
        footer { 
            text-align: center; 
            padding: 1.5rem; 
            background-color: #ffffff; 
            border-top: 1px solid #e0e0e0; 
            margin-top: 2rem; 
            color: #555;
            box-shadow: 0 -2px 4px rgba(0,0,0,0.05);
        }
        
        /* Date picker styling */
        .form-control[type="date"] {
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            border: 1px solid #ced4da;
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
            font-weight: 500;
            width: 170px; /* Adjust width for single date input */
            text-align: center;
        }
        .form-control[type="date"]:focus {
            border-color: #005670;
            box-shadow: 0 0 0 0.25rem rgba(0, 86, 112, 0.25);
        }

        .btn-group .btn {
            border-radius: 0.375rem !important;
            margin-left: 0.25rem;
            margin-right: 0.25rem;
        }
        .btn-group .btn.active {
            background-color: #005670 !important;
            color: white !important;
        }
        .btn-group .btn:first-child { border-top-left-radius: 0.375rem !important; border-bottom-left-radius: 0.375rem !important; }
        .btn-group .btn:last-child { border-top-right-radius: 0.375rem !important; border-bottom-right-radius: 0.375rem !important; }

        /* Loading Spinner */
        .loading-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.8);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 10;
            border-radius: 12px;
        }
        .spinner-border {
            width: 3rem;
            height: 3rem;
            color: #005670;
        }

        /* Chart Header specific styles for date range */
        .chart-header-controls {
            display: flex;
            align-items: center;
            gap: 10px; /* Space between date picker and buttons */
        }
        .chart-header-controls .form-control {
             width: 200px; /* Specific width for chart date picker */
             text-align: center;
             font-size: 0.9rem;
        }

        @media print {
            .no-print { display: none; }
            .content { margin-top: 0; padding: 0; }
            .card { box-shadow: none; border: 1px solid #ddd; }
            .navbar, footer, .btn-group, .date-controls { display: none; }
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg no-print">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">
                <img src="/accounts/assets/img/logo.png" alt="SL Diagnostics Logo">
                <span>SL Diagnostics</span>
                <span class="ms-3 text-muted">Welcome, <?php echo isset($_SESSION['username']) ? htmlspecialchars($_SESSION['username']) : 'User'; ?>!</span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link active" aria-current="page" href="dashboard.php">Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="cash_receipts.php">Cash Receipts</a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="bankDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            Bank
                        </a>
                        <ul class="dropdown-menu" aria-labelledby="bankDropdown">
                            <li><a class="dropdown-item" href="bank_deposits.php">Bank Deposits</a></li>
                            <li><a class="dropdown-item" href="bank_withdrawals.php">Bank Withdrawals</a></li>
                        </ul>
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
                    <!--   <li class="nav-item">
                        <a class="nav-link" href="vehicle_fuel.php">Vehicle Fuel</a>
                    </li>-->
                    <li class="nav-item">
                        <a class="nav-link" href="reports.php">Reports</a>
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
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-4 date-controls">
            <h1 class="display-6 mb-3 mb-md-0">Financial Dashboard</h1>
            <div class="d-flex align-items-center gap-2 no-print">
                <a href="?date=<?php echo htmlspecialchars($prev_date); ?>" class="btn btn-outline-secondary" aria-label="Previous Day"
                   data-bs-toggle="tooltip" data-bs-placement="bottom" title="Go to previous day">
                    <i class="fas fa-chevron-left"></i>
                </a>
                <form method="GET" action="" class="d-flex align-items-center gap-2">
                    <label for="date-picker" class="form-label mb-0 visually-hidden">Select Date:</label>
                    <input type="date" id="date-picker" name="date" class="form-control" value="<?php echo htmlspecialchars($date); ?>" 
                           aria-label="Select Date"
                           onchange="this.form.submit()"
                           data-bs-toggle="tooltip" data-bs-placement="bottom" title="Select a specific date for dashboard KPIs">
                </form>
                <a href="?date=<?php echo htmlspecialchars($next_date); ?>" class="btn btn-outline-secondary <?php echo ($date == $today_date) ? 'disabled' : ''; ?>" aria-label="Next Day"
                   data-bs-toggle="tooltip" data-bs-placement="bottom" title="Go to next day">
                    <i class="fas fa-chevron-right"></i>
                </a>
                <button type="button" class="btn btn-primary ms-3" onclick="window.print()" aria-label="Print Dashboard"
                        data-bs-toggle="tooltip" data-bs-placement="bottom" title="Print this dashboard view">
                    <i class="fas fa-print"></i> Print
                </button>
            </div>
        </div>
        
        <div class="row g-4 mb-4">
            <div class="col-lg-3 col-md-6">
                <div class="card kpi-card">
                    <div class="card-body">
                        <div class="kpi-icon"><i class="fas fa-wallet" aria-hidden="true"></i></div>
                        <div class="kpi-content">
                            <div class="kpi-title">TOTAL CASH ON HAND <br>(as of <?php echo htmlspecialchars(date('d M Y', strtotime($date))); ?>)</div>
                            <div class="kpi-value" data-bs-toggle="tooltip" data-bs-placement="top" title="Total cash available up to and including this date">₹<?php echo formatIndianNumber($total_cash_on_hand); ?></div>
                            <div class="kpi-breakdown">
                                <div><span class="label">Cumulative Previous:</span> ₹<?php echo formatIndianNumber($cumulative_prev_cash_on_hand); ?></div>
								
                                <div><span class="label">Today's Cash:</span> ₹<?php echo formatIndianNumber($cash_on_hand_today); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="card kpi-card">
                    <div class="card-body">
                        <div class="kpi-icon"><i class="fas fa-landmark" aria-hidden="true"></i></div>
                        <div class="kpi-content">
                            <div class="kpi-title">TOTAL BANK BALANCE</div>
                            <div class="kpi-value" data-bs-toggle="tooltip" data-bs-placement="top" title="Total balance across all bank accounts">₹<?php echo formatIndianNumber($total_bank_balance); ?></div>
                            <div class="kpi-breakdown">
                                <div><span class="label">Across:</span> <?php echo count($bank_accounts); ?> Accounts</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="card kpi-card">
                    <div class="card-body">
                        <div class="kpi-icon"><i class="fas fa-rupee-sign" aria-hidden="true"></i></div>
                        <div class="kpi-content">
                            <div class="kpi-title">INCOME FOR <br><?php echo strtoupper(date('d M Y', strtotime($date))); ?></div>
                            <div class="kpi-value" data-bs-toggle="tooltip" data-bs-placement="top" title="Total income recorded on this specific date">₹<?php echo formatIndianNumber($todays_total_income); ?></div>
                            <div class="kpi-breakdown">
                                <div><span class="label">Total Receipts:</span> ₹<?php echo formatIndianNumber($todays_total_income); ?></div>
                                <div><span class="label">On <?php echo htmlspecialchars(date('d M', strtotime($date))); ?></span></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="card kpi-card">
                    <div class="card-body">
                        <div class="kpi-icon"><i class="fas fa-file-invoice-dollar" aria-hidden="true"></i></div>
                        <div class="kpi-content">
                            <div class="kpi-title">EXPENSES FOR <br><?php echo strtoupper(date('d M Y', strtotime($date))); ?></div>
                            <div class="kpi-value" data-bs-toggle="tooltip" data-bs-placement="top" title="Total expenses recorded on this specific date">₹<?php echo formatIndianNumber($todays_total_expenses); ?></div>
                            <div class="kpi-breakdown">
                                <div><span class="label">Cash Payments:</span> ₹<?php echo formatIndianNumber($todays_cash_payments); ?></div>
                                <div><span class="label">Bank Payments:</span> ₹<?php echo formatIndianNumber($todays_bank_payments); ?></div>
                               <!-- <div><span class="label">Vehicle Fuel:</span> ₹<?php echo formatIndianNumber($todays_vehicle_fuel); ?></div>-->
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-12">
                <div class="card">
                    <div class="card-header">
                        <div class="d-flex flex-grow-1 align-items-center justify-content-between chart-header-controls">
                            <span id="chartDateRangeTitle">Income by Branch (Last 7 Days Ending <?php echo htmlspecialchars(date('d M Y', strtotime($date))); ?>)</span>
                            <input type="text" id="chart-date-range-picker" class="form-control form-control-sm ms-3" 
                                   value="<?php echo htmlspecialchars(date('Y-m-d', strtotime($date . ' -6 days')) . ' to ' . $date); ?>" 
                                   data-start-date="<?php echo htmlspecialchars(date('Y-m-d', strtotime($date . ' -6 days'))); ?>"
                                   data-end-date="<?php echo htmlspecialchars($date); ?>"
                                   aria-label="Select Date Range for Chart"
                                   title="Select a date range for the chart data">
                        </div>
                        <div class="btn-group btn-group-sm no-print ms-3" role="group" aria-label="Chart Options">
                            <button type="button" class="btn btn-light active" id="barChartBtn" onclick="changeChartType('bar')"
                                    data-bs-toggle="tooltip" data-bs-placement="top" title="View as Bar Chart">
                                <i class="fas fa-chart-bar"></i> Bar
                            </button>
                            <button type="button" class="btn btn-light" id="lineChartBtn" onclick="changeChartType('line')"
                                    data-bs-toggle="tooltip" data-bs-placement="top" title="View as Line Chart">
                                <i class="fas fa-chart-line"></i> Line
                            </button>
                            <button type="button" class="btn btn-light" id="resetZoomBtn" 
                                    data-bs-toggle="tooltip" data-bs-placement="top" title="Reset chart zoom">
                                <i class="fas fa-search-minus"></i> Reset Zoom
                            </button>
                             <button type="button" class="btn btn-light dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"
                                    data-bs-toggle="tooltip" data-bs-placement="top" title="Download Chart">
                                <i class="fas fa-download"></i> Download
                            </button>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item" href="#" onclick="downloadChart('png')">Download PNG</a></li>
                                <li><a class="dropdown-item" href="#" onclick="downloadChart('jpeg')">Download JPEG</a></li>
                            </ul>
                            <button type="button" class="btn btn-light" onclick="exportChartDataToCsv()"
                                    data-bs-toggle="tooltip" data-bs-placement="top" title="Export chart data to CSV">
                                <i class="fas fa-file-csv"></i> Export CSV
                            </button>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="chart-container" id="incomeChartContainer">
                            <div class="loading-overlay d-none" id="chartLoadingOverlay">
                                <div class="spinner-border" role="status">
                                    <span class="visually-hidden">Loading chart...</span>
                                </div>
                            </div>
                            <canvas id="incomeChart" aria-label="Income by Branch Chart"></canvas>
                            <p class="text-muted text-center py-5 d-none" id="chartNoDataMessage">No income data found for the selected date range for any branch. Please check your data entries or adjust the date.</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-12">
                <div class="card">
                    <div class="card-header">Bank Account Balances</div>
                    <div class="card-body p-0">
                        <?php if (empty($bank_accounts)): ?>
                            <p class="text-muted text-center p-4">No bank accounts have been added yet. Please add bank accounts to view their balances here.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th class="ps-4">Bank Name</th>
                                            <th>Account Number</th>
                                            <th class="balance-column pe-4">Balance</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($bank_accounts as $account): ?>
                                            <tr>
                                                <td class="ps-4"><?php echo htmlspecialchars($account['bank_name']); ?></td>
                                                <td><?php echo htmlspecialchars($account['account_number']); ?></td>
                                                <td class="balance-column pe-4">₹<?php echo formatIndianNumber($account['balance']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr class="table-light">
                                            <td colspan="2" class="text-end ps-4"><strong>Total Balance:</strong></td>
                                            <td class="balance-column pe-4"><strong>₹<?php echo formatIndianNumber($total_bank_balance); ?></strong></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <footer class="no-print">
        <p class="mb-0">© <?php echo date('Y'); ?> SL Diagnostics. All rights reserved.</p>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let myIncomeChart; // Make chart instance globally accessible
        let currentChartStartDate = "<?php echo htmlspecialchars(date('Y-m-d', strtotime($date . ' -6 days'))); ?>";
        let currentChartEndDate = "<?php echo htmlspecialchars($date); ?>";

        document.addEventListener("DOMContentLoaded", function() {
            // Initialize Tooltips
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl)
            });

            // Initialize main dashboard date picker
            const mainDateInput = document.getElementById('date-picker');
            if (mainDateInput) {
                mainDateInput.addEventListener('change', function() {
                    const selectedDate = new Date(this.value);
                    const minDate = new Date('2000-01-01'); // Example minimum date for historical data
                    const maxDate = new Date('<?php echo $today_date; ?>'); // Max date is today

                    selectedDate.setHours(0,0,0,0);
                    minDate.setHours(0,0,0,0);
                    maxDate.setHours(0,0,0,0);

                    if (selectedDate < minDate || selectedDate > maxDate) {
                        alert('Please select a valid date. Dates cannot be in the future and must be after ' + minDate.toLocaleDateString('en-IN') + '.');
                        this.value = '<?php echo htmlspecialchars($date); ?>'; // Reset to current valid date
                    } else {
                        this.form.submit();
                    }
                });
            }

            // Initialize Flatpickr Date Range Picker for the CHART
            flatpickr("#chart-date-range-picker", {
                mode: "range",
                dateFormat: "Y-m-d",
                defaultDate: [currentChartStartDate, currentChartEndDate],
                maxDate: "today", // Prevent selecting future dates
                onChange: function(selectedDates, dateStr, instance) {
                    if (selectedDates.length === 2) {
                        const newStartDate = instance.formatDate(selectedDates[0], "Y-m-d");
                        const newEndDate = instance.formatDate(selectedDates[1], "Y-m-d");
                        if (newStartDate !== currentChartStartDate || newEndDate !== currentChartEndDate) {
                            currentChartStartDate = newStartDate;
                            currentChartEndDate = newEndDate;
                            fetchChartData(currentChartStartDate, currentChartEndDate);
                        }
                    }
                }
            });

            // Initial chart rendering
            fetchChartData(currentChartStartDate, currentChartEndDate);

            // Reset Zoom Button Handler
            document.getElementById('resetZoomBtn').addEventListener('click', function() {
                if (myIncomeChart) {
                    myIncomeChart.resetZoom();
                }
            });
        });

        async function fetchChartData(startDate, endDate) {
            const chartLoadingOverlay = document.getElementById('chartLoadingOverlay');
            const chartNoDataMessage = document.getElementById('chartNoDataMessage');
            const chartCanvas = document.getElementById('incomeChart');
            const chartTitle = document.getElementById('chartDateRangeTitle');

            if (chartLoadingOverlay) chartLoadingOverlay.classList.remove('d-none');
            if (chartNoDataMessage) chartNoDataMessage.classList.add('d-none');
            if (chartCanvas) chartCanvas.style.opacity = '0.5'; // Dim chart while loading

            chartTitle.textContent = `Income by Branch (${formatDateForTitle(startDate)} - ${formatDateForTitle(endDate)})`;

            try {
                const response = await fetch(`get_chart_data.php?start_date=${startDate}&end_date=${endDate}`);
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                const data = await response.json();

                if (data.error) {
                    console.error("Error fetching chart data:", data.error);
                    if (chartNoDataMessage) {
                        chartNoDataMessage.textContent = `Error loading chart data: ${data.error}`;
                        chartNoDataMessage.classList.remove('d-none');
                    }
                    if (myIncomeChart) myIncomeChart.destroy(); // Destroy existing chart on error
                    return;
                }

                // Check if any dataset has non-zero data
                const hasData = data.datasets.some(dataset => dataset.data.some(val => val > 0));

                if (!hasData) {
                    if (myIncomeChart) myIncomeChart.destroy(); // Destroy old chart
                    if (chartNoDataMessage) {
                        chartNoDataMessage.textContent = `No income data found for ${formatDateForTitle(startDate)} - ${formatDateForTitle(endDate)} for any branch. Please check your data entries or adjust the date range.`;
                        chartNoDataMessage.classList.remove('d-none');
                    }
                    return; // Exit if no data
                }

                // If there was a "No data" message previously, hide it and make canvas visible
                if (chartNoDataMessage) chartNoDataMessage.classList.add('d-none');
                if (chartCanvas) chartCanvas.classList.remove('d-none'); // Ensure canvas is visible

                if (myIncomeChart) {
                    myIncomeChart.data.labels = data.labels;
                    myIncomeChart.data.datasets = data.datasets.map(dataset => {
                        // Preserve current chart type fill if it was line
                        if (myIncomeChart.config.type === 'line') {
                            dataset.fill = {target: 'origin', above: dataset.backgroundColor + '33'};
                        }
                        return dataset;
                    });
                    myIncomeChart.update();
                } else {
                    // Create new chart if it doesn't exist
                    const ctx = document.getElementById('incomeChart');
                    myIncomeChart = new Chart(ctx.getContext('2d'), {
                        type: 'bar', // Default type
                        data: {
                            labels: data.labels,
                            datasets: data.datasets
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            scales: {
                                x: { stacked: true, title: { display: true, text: 'Date', font: { weight: '600' } } },
                                y: {
                                    stacked: true, beginAtZero: true,
                                    title: { display: true, text: 'Income (₹)', font: { weight: '600' } },
                                    ticks: {
                                        callback: function(value) { return '₹' + value.toLocaleString('en-IN', { maximumFractionDigits: 0 }); }
                                    }
                                }
                            },
                            plugins: {
                                legend: { position: 'top' },
                                tooltip: {
                                    callbacks: {
                                        label: function(context) {
                                            let label = context.dataset.label || '';
                                            if (label) label += ': ';
                                            if (context.parsed.y !== null) label += '₹' + context.parsed.y.toLocaleString('en-IN', { maximumFractionDigits: 0 });
                                            return label;
                                        }
                                    }
                                },
                                zoom: {
                                    pan: { enabled: true, mode: 'x' },
                                    zoom: { wheel: { enabled: true }, pinch: { enabled: true }, mode: 'x' }
                                }
                            },
                            interaction: { intersect: false, mode: 'index' },
                        }
                    });
                }
            } catch (error) {
                console.error("Failed to fetch chart data:", error);
                if (chartNoDataMessage) {
                    chartNoDataMessage.textContent = 'Failed to load chart data. Please try again.';
                    chartNoDataMessage.classList.remove('d-none');
                }
                if (chartCanvas) chartCanvas.classList.add('d-none'); // Hide canvas on error
                if (myIncomeChart) myIncomeChart.destroy(); // Destroy existing chart on error
            } finally {
                if (chartLoadingOverlay) chartLoadingOverlay.classList.add('d-none');
                if (chartCanvas) chartCanvas.style.opacity = '1';
            }
        }

        function changeChartType(type) {
            if (myIncomeChart) {
                // Ensure the chart exists and is not currently showing no data message
                const chartNoDataMessage = document.getElementById('chartNoDataMessage');
                if (chartNoDataMessage && !chartNoDataMessage.classList.contains('d-none')) {
                    // Chart is in 'no data' state, so no type change possible
                    return;
                }

                document.getElementById('chartLoadingOverlay').classList.remove('d-none');
                document.getElementById('incomeChart').style.opacity = '0.5';

                setTimeout(() => {
                    const isLine = type === 'line';
                    myIncomeChart.options.scales.x.stacked = !isLine;
                    myIncomeChart.options.scales.y.stacked = !isLine;
                    
                    myIncomeChart.data.datasets.forEach(dataset => {
                        dataset.fill = isLine ? {target: 'origin', above: dataset.backgroundColor + '33'} : false;
                    });

                    myIncomeChart.config.type = type;
                    myIncomeChart.update();

                    document.getElementById('barChartBtn').classList.remove('active');
                    document.getElementById('lineChartBtn').classList.remove('active');
                    document.getElementById(type + 'ChartBtn').classList.add('active');

                    document.getElementById('chartLoadingOverlay').classList.add('d-none');
                    document.getElementById('incomeChart').style.opacity = '1';
                }, 300);
            }
        }

        function downloadChart(format) {
            if (myIncomeChart && !document.getElementById('chartNoDataMessage').classList.contains('d-none')) {
                alert("Cannot download chart as no data is currently displayed.");
                return;
            }
            if (myIncomeChart) {
                const a = document.createElement('a');
                a.href = myIncomeChart.toBase64Image('image/' + format, 1);
                a.download = `income_chart_${currentChartStartDate}_to_${currentChartEndDate}.${format}`;
                a.click();
            }
        }

        function exportChartDataToCsv() {
            // Trigger PHP export with the chart's current date range
            window.location.href = `?export=csv&export_start_date=${currentChartStartDate}&export_end_date=${currentChartEndDate}`;
        }

        function formatDateForTitle(dateString) {
            const options = { month: 'short', day: 'numeric', year: 'numeric' };
            return new Date(dateString).toLocaleDateString('en-IN', options);
        }
    </script>
</body>
</html>
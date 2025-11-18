<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401); // Unauthorized
    echo json_encode(['error' => 'Unauthorized access.']);
    exit;
}

try {
    require_once 'includes/db_connect.php'; // Ensure your database connection is here
} catch (Exception $e) {
    http_response_code(500); // Internal Server Error
    error_log("Database connection failed for chart data: " . $e->getMessage());
    echo json_encode(['error' => 'Database connection failed.']);
    exit;
}

header('Content-Type: application/json');

// Get date range from GET parameters, default to last 7 days if not provided
$end_date = isset($_GET['end_date']) && preg_match("/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/", $_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$start_date = isset($_GET['start_date']) && preg_match("/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/", $_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-6 days', strtotime($end_date)));

// Ensure start_date is not after end_date
if (strtotime($start_date) > strtotime($end_date)) {
    // Swap if dates are inverted
    list($start_date, $end_date) = [$end_date, $start_date];
}

$income_data = [];
$branches = [];
$dates_for_chart = [];

try {
    // Get distinct branches from both receipts tables
    $stmt = $pdo->query("
        (SELECT DISTINCT branch FROM cash_receipts WHERE branch IS NOT NULL AND branch != '')
        UNION
        (SELECT DISTINCT department AS branch FROM other_receipts WHERE department IS NOT NULL AND department != '')
        ORDER BY branch
    ");
    $branches = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Get all dates within the specified range for the chart
    $period = new DatePeriod(
        new DateTime($start_date),
        new DateInterval('P1D'),
        new DateTime($end_date . ' +1 day') // Add 1 day to include the end_date itself
    );
    foreach ($period as $dt) {
        $dates_for_chart[] = $dt->format('Y-m-d');
    }

    // Initialize income data structure for all branches and all dates in the range
    foreach ($branches as $branch) {
        $income_data[$branch] = array_fill_keys($dates_for_chart, 0.00);
    }
    
    // Combine receipt queries for efficiency
    $query = "
        SELECT date, branch, SUM(amount) as total_amount FROM (
            SELECT date, branch, amount FROM cash_receipts WHERE date BETWEEN ? AND ?
            UNION ALL
            SELECT date, department as branch, amount FROM other_receipts WHERE date BETWEEN ? AND ?
        ) as combined_receipts
        GROUP BY date, branch
        ORDER BY date ASC
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$start_date, $end_date, $start_date, $end_date]);
    $all_receipts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Populate income_data with fetched amounts
    foreach ($all_receipts as $receipt) {
        if (isset($income_data[$receipt['branch']][$receipt['date']])) {
            $income_data[$receipt['branch']][$receipt['date']] += $receipt['total_amount'];
        }
    }

    // Prepare data for Chart.js
    $chart_labels = array_map(function($d) { return date('M d', strtotime($d)); }, $dates_for_chart);
    $chart_datasets = [];
    $colors = ['#005670', '#E63946', '#2a9d8f', '#f4a261', '#e76f51', '#8338ec', '#3a86ff', '#fb5607', '#ff006e', '#0ead69'];
    $color_index = 0;

    foreach ($income_data as $branch => $amounts) {
        $chart_datasets[] = [
            'label' => $branch,
            'data' => array_values($amounts),
            'backgroundColor' => $colors[$color_index % count($colors)],
            'borderColor' => $colors[$color_index % count($colors)],
            'borderWidth' => 1,
            'fill' => false // Default, will be set by JS if line chart
        ];
        $color_index++;
    }

    echo json_encode([
        'labels' => $chart_labels,
        'datasets' => $chart_datasets,
        'start_date' => $start_date,
        'end_date' => $end_date
    ]);

} catch (PDOException $e) {
    http_response_code(500); // Internal Server Error
    error_log("Error fetching income data for chart: " . $e->getMessage());
    echo json_encode(['error' => 'Failed to retrieve chart data.']);
}
?>
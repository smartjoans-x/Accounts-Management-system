<?php
// Ensure UTF-8 encoding without BOM
ini_set('display_errors', 1); // Enable for debugging (set to 0 in production)
ini_set('display_startup_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', 'E:/sldpl.tokenmanager.com/logs/php_errors.log');
error_reporting(E_ALL);

session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Log script start
error_log("Starting vehicle_fuel.php execution at " . date('Y-m-d H:i:s'));

// Check if PDO is enabled
if (!extension_loaded('pdo_mysql')) {
    error_log("PDO MySQL extension is not enabled.");
    die("System error: PDO MySQL extension missing. Contact administrator.");
}

// Load environment variables
$env_file = __DIR__ . '/.env';
if (file_exists($env_file)) {
    $env = parse_ini_file($env_file);
    if ($env === false) {
        error_log("Failed to parse .env file at $env_file");
    }
} else {
    error_log(".env file not found at $env_file; using default config values");
    $env = []; // Ensure $env is an array
}

// MySQL configuration for sld_accounts
$accounts_db_host = $env['ACCOUNTS_DB_HOST'] ?? 'localhost:3306';
$accounts_db_name = $env['ACCOUNTS_DB_NAME'] ?? 'sld_accounts';
$accounts_db_user = $env['ACCOUNTS_DB_USER'] ?? 'root';
$accounts_db_pass = $env['ACCOUNTS_DB_PASS'] ?? '1234567';

// Log configuration
error_log("sld_accounts Config: Host=$accounts_db_host, User=$accounts_db_user, DB=$accounts_db_name, Pass=" . ($accounts_db_pass ? '[set]' : '[unset]'));

// PDO connection for sld_accounts
$pdo_accounts = null;
try {
    error_log("Attempting connection to sld_accounts...");
    $pdo_accounts = new PDO(
        "mysql:host=$accounts_db_host;dbname=$accounts_db_name;charset=utf8mb4",
        $accounts_db_user,
        $accounts_db_pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $pdo_accounts->query("SELECT 1");
    error_log("Successfully connected to sld_accounts database.");
} catch (PDOException $e) {
    error_log("sld_accounts database connection failed: " . $e->getMessage());
    echo "Database connection error: Unable to connect to accounts database. " . htmlspecialchars($e->getMessage());
    exit;
}

// Verify PDO object
if (!$pdo_accounts instanceof PDO) {
    error_log("PDO object for sld_accounts not properly initialized.");
    die("System error: Database connection not initialized.");
}

// Calculate total_cash_on_hand
$date = isset($_GET['date']) && !empty($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$prev_date = date('Y-m-d', strtotime($date . ' -1 day'));
$cash_on_hand = 0.00;
$cumulative_prev_cash_on_hand = 0.00;
$total_cash_on_hand = 0.00;

try {
    $stmt = $pdo_accounts->prepare("SELECT amount FROM cash_on_hand WHERE date = ?");
    $stmt->execute([$date]);
    $cash_on_hand = $stmt->fetchColumn() ?: 0.00;

    $stmt = $pdo_accounts->prepare("SELECT SUM(amount) FROM cash_on_hand WHERE date <= ?");
    $stmt->execute([$prev_date]);
    $cumulative_prev_cash_on_hand = $stmt->fetchColumn() ?: 0.00;

    $total_cash_on_hand = $cash_on_hand + $cumulative_prev_cash_on_hand;
} catch (PDOException $e) {
    error_log("Error fetching cash on hand details: " . $e->getMessage());
}


// Fetch bank accounts
$bank_accounts = [];
try {
    $stmt = $pdo_accounts->query("SELECT id, bank_name, account_number, balance FROM bank_accounts");
    $bank_accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("Fetched " . count($bank_accounts) . " bank accounts.");
} catch (PDOException $e) {
    error_log("Error fetching bank accounts: " . $e->getMessage());
    echo "Error loading bank accounts: " . htmlspecialchars($e->getMessage()) . "<br>";
}

// Fetch existing vehicle names for the datalist suggestion
$existing_vehicles = [];
try {
    $stmt = $pdo_accounts->query("SELECT DISTINCT vehicle_name FROM vehicle_fuel_records ORDER BY vehicle_name ASC");
    $existing_vehicles = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log("Error fetching existing vehicle names: " . $e->getMessage());
    // Non-fatal error, the page can still work without suggestions.
}


// Handle add fuel record
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_fuel'])) {
    try {
        $date = $_POST['date'];
        $vehicle_name = trim($_POST['vehicle_name']);
        $fuel_amount = floatval($_POST['fuel_amount']);
        $km_reading = intval($_POST['km_reading']);
        $payment_method = $_POST['payment_method'];
        $bank_account_id = $payment_method === 'Bank' ? $_POST['bank_account_id'] : null;
        $user_id = $_SESSION['user_id'];

        if ($payment_method === 'Cash' && $fuel_amount > $total_cash_on_hand) {
            throw new Exception("Insufficient cash on hand.");
        }

        $pdo_accounts->beginTransaction();

        $stmt = $pdo_accounts->prepare("INSERT INTO vehicle_fuel_records (date, vehicle_name, fuel_amount, km_reading, payment_method, bank_account_id, user_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$date, $vehicle_name, $fuel_amount, $km_reading, $payment_method, $bank_account_id, $user_id]);

        if ($payment_method === 'Cash') {
            $stmt = $pdo_accounts->prepare("INSERT INTO cash_on_hand (date, amount) VALUES (?, ?) ON DUPLICATE KEY UPDATE amount = amount - ?");
            $stmt->execute([$date, -$fuel_amount, $fuel_amount]);
        } else { // Bank payment
            $stmt = $pdo_accounts->prepare("UPDATE bank_accounts SET balance = balance - ? WHERE id = ?");
            $stmt->execute([$fuel_amount, $bank_account_id]);
        }

        $pdo_accounts->commit();
        $success_message = "Fuel record added successfully!";
        // Refresh vehicle list if a new one was added
        if (!in_array($vehicle_name, $existing_vehicles)) {
            $existing_vehicles[] = $vehicle_name;
            sort($existing_vehicles);
        }
    } catch (Exception $e) {
        if ($pdo_accounts->inTransaction()) {
            $pdo_accounts->rollBack();
        }
        error_log("Error adding fuel record: " . $e->getMessage());
        $error_message = $e->getMessage() ?: "Failed to add fuel record.";
    }
}

// Handle edit fuel record
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_fuel'])) {
    try {
        $fuel_id = $_POST['fuel_id'];
        $date = $_POST['date'];
        $vehicle_name = trim($_POST['vehicle_name']);
        $fuel_amount = floatval($_POST['fuel_amount']);
        $new_km_reading = intval($_POST['km_reading']);
        $payment_method = $_POST['payment_method'];
        $new_bank_account_id = $payment_method === 'Bank' ? $_POST['bank_account_id'] : null;

        // Fetch original record
        $stmt = $pdo_accounts->prepare("SELECT fuel_amount, payment_method, bank_account_id FROM vehicle_fuel_records WHERE id = ?");
        $stmt->execute([$fuel_id]);
        $original_fuel = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$original_fuel) {
            throw new Exception("Fuel record not found.");
        }
        $original_fuel_amount = $original_fuel['fuel_amount'];
        $original_payment_method = $original_fuel['payment_method'];
        $original_bank_account_id = $original_fuel['bank_account_id'];

        $pdo_accounts->beginTransaction();

        // Revert original transaction
        if ($original_payment_method === 'Cash') {
            $stmt = $pdo_accounts->prepare("INSERT INTO cash_on_hand (date, amount) VALUES (?, ?) ON DUPLICATE KEY UPDATE amount = amount + ?");
            $stmt->execute([$date, $original_fuel_amount, $original_fuel_amount]);
        } else if ($original_bank_account_id) {
            $stmt = $pdo_accounts->prepare("UPDATE bank_accounts SET balance = balance + ? WHERE id = ?");
            $stmt->execute([$original_fuel_amount, $original_bank_account_id]);
        }

        // Apply new transaction
        if ($payment_method === 'Cash') {
            if ($fuel_amount > $total_cash_on_hand + $original_fuel_amount) {
                throw new Exception("Insufficient cash on hand for this transaction.");
            }
            $stmt = $pdo_accounts->prepare("INSERT INTO cash_on_hand (date, amount) VALUES (?, ?) ON DUPLICATE KEY UPDATE amount = amount - ?");
            $stmt->execute([$date, -$fuel_amount, $fuel_amount]);
        } else if ($new_bank_account_id) {
            $stmt = $pdo_accounts->prepare("UPDATE bank_accounts SET balance = balance - ? WHERE id = ?");
            $stmt->execute([$fuel_amount, $new_bank_account_id]);
        }

        // Update the fuel record itself
        $stmt = $pdo_accounts->prepare("UPDATE vehicle_fuel_records SET date = ?, vehicle_name = ?, fuel_amount = ?, km_reading = ?, payment_method = ?, bank_account_id = ? WHERE id = ?");
        $stmt->execute([$date, $vehicle_name, $fuel_amount, $new_km_reading, $payment_method, $new_bank_account_id, $fuel_id]);

        $pdo_accounts->commit();
        $success_message = "Fuel record updated successfully!";
    } catch (Exception $e) {
        if ($pdo_accounts->inTransaction()) {
            $pdo_accounts->rollBack();
        }
        error_log("Error updating fuel record: " . $e->getMessage());
        $error_message = $e->getMessage() ?: "Failed to update fuel record.";
    }
}


// Fetch fuel records for the selected date
$fuel_records = [];
try {
    $stmt = $pdo_accounts->prepare("
        SELECT vfr.id, vfr.date, vfr.vehicle_name, vfr.fuel_amount, vfr.km_reading, vfr.payment_method, vfr.bank_account_id, ba.account_number, u.username
        FROM vehicle_fuel_records vfr
        JOIN users u ON vfr.user_id = u.id
        LEFT JOIN bank_accounts ba ON vfr.bank_account_id = ba.id
        WHERE vfr.date = ?
        ORDER BY vfr.id DESC
    ");
    $stmt->execute([$date]);
    $fuel_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching fuel records: " . $e->getMessage());
    $error_message = "Failed to load fuel records.";
}

// Log script completion
error_log("vehicle_fuel.php execution completed at " . date('Y-m-d H:i:s'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SL Diagnostics - Vehicle Fuel Records</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Roboto', sans-serif; background-color: #f8f9fa; }
        .navbar { background-color: #ffffff; box-shadow: 0 2px 4px rgba(0,0,0,0.1); padding: 1rem 0; }
        .navbar-brand { display: flex; align-items: center; gap: 0.75rem; }
        .navbar-brand img { max-height: 40px; width: auto; }
        .navbar-brand span { font-size: 1.5rem; font-weight: 700; color: #005670; }
        .content { margin-top: 80px; padding: 2rem; max-width: 1200px; margin-left: auto; margin-right: auto; }
        .card { border: none; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .card-header { background-color: #005670; color: white; font-weight: 700; border-radius: 8px 8px 0 0; }
        .btn-primary { background-color: #005670; border: none; }
        .btn-primary:hover { background-color: #004050; }
        footer { text-align: center; padding: 1rem; background-color: #ffffff; border-top: 1px solid #e0e0e0; margin-top: 2rem; }
        #bank_account_id_container, .edit_bank_account_id_container { display: none; }
        @media print {
            body * { display: none !important; }
            .fuel-table, .fuel-table * { display: block !important; }
            .fuel-table { margin: 0 !important; padding: 0 !important; }
            .fuel-table .card { box-shadow: none !important; border: 1px solid #ddd !important; }
            .fuel-table .card-header { background-color: transparent !important; color: black !important; border-bottom: 1px solid #ddd !important; }
            .fuel-table .card-body { padding: 0 !important; }
            .fuel-table .table { width: 100% !important; border-collapse: collapse !important; }
            .fuel-table th, .fuel-table td { border: 1px solid #ddd !important; padding: 8px !important; }
            .fuel-table .actions { display: none !important; }
        }
    </style>
</head>
<body>
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
                    <li class="nav-item"><a class="nav-link active" href="vehicle_fuel.php">Vehicle Fuel</a></li>
                    <li class="nav-item"><a class="nav-link" href="reports.php">Reports</a></li>
                    <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'Admin'): ?>
                        <li class="nav-item"><a class="nav-link" href="user_management.php">User Management</a></li>
                    <?php endif; ?>
                    <li class="nav-item"><a class="nav-link" href="change_password.php">Change Password</a></li>
                    <li class="nav-item"><a class="nav-link" href="logout.php">Sign Out</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="content">
        <h1 class="display-5 mb-4">Vehicle Fuel Records</h1>

        <?php if (isset($success_message)): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
        <?php elseif (isset($error_message)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <form class="mb-4">
            <div class="row g-3">
                <div class="col-md-4 col-lg-3">
                    <label for="date-filter" class="form-label">Select Date</label>
                    <input type="date" class="form-control" id="date-filter" name="date" value="<?php echo htmlspecialchars($date); ?>" onchange="this.form.submit()">
                </div>
            </div>
        </form>

        <div class="card mb-4">
            <div class="card-header">Add New Fuel Record</div>
            <div class="card-body">
                <form method="POST">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label for="date" class="form-label">Date</label>
                            <input type="date" class="form-control" id="date" name="date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label for="vehicle_name" class="form-label">Vehicle Name</label>
                            <input class="form-control" list="vehicle-list" id="vehicle_name" name="vehicle_name" placeholder="Type or select vehicle" required>
                            <datalist id="vehicle-list">
                                <?php foreach ($existing_vehicles as $vehicle): ?>
                                    <option value="<?php echo htmlspecialchars($vehicle); ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="col-md-3">
                            <label for="fuel_amount" class="form-label">Fuel Amount (₹)</label>
                            <input type="number" class="form-control" id="fuel_amount" name="fuel_amount" step="0.01" min="0" required>
                        </div>
                        <div class="col-md-3">
                            <label for="km_reading" class="form-label">KM Reading</label>
                            <input type="number" class="form-control" id="km_reading" name="km_reading" min="0" required>
                        </div>
                        <div class="col-md-3">
                            <label for="payment_method" class="form-label">Payment Method</label>
                            <select class="form-control" id="payment_method" name="payment_method" required onchange="toggleBankAccount()">
                                <option value="Cash" selected>Cash</option>
                                <option value="Bank">Bank</option>
                            </select>
                        </div>
                        <div class="col-md-3" id="bank_account_id_container">
                            <label for="bank_account_id" class="form-label">Bank Account</label>
                            <select class="form-control" id="bank_account_id" name="bank_account_id">
                                <option value="">Select Account</option>
                                <?php if (empty($bank_accounts)): ?>
                                    <option value="" disabled>No bank accounts available</option>
                                <?php else: ?>
                                    <?php foreach ($bank_accounts as $account): ?>
                                        <option value="<?php echo $account['id']; ?>">
                                            <?php echo htmlspecialchars($account['bank_name'] . ' - ' . $account['account_number']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>
                    <button type="submit" name="add_fuel" class="btn btn-primary mt-3">Add Fuel Record</button>
                </form>
            </div>
        </div>

        <div class="card fuel-table">
            <div class="card-header">Fuel Records for <?php echo htmlspecialchars($date); ?></div>
            <div class="card-body">
                <?php if (empty($fuel_records)): ?>
                    <p class="text-muted">No fuel records found for this date.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
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
                                    <th class="actions">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($fuel_records as $record): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($record['id']); ?></td>
                                        <td><?php echo htmlspecialchars($record['date']); ?></td>
                                        <td><?php echo htmlspecialchars($record['vehicle_name']); ?></td>
                                        <td>₹<?php echo number_format($record['fuel_amount'], 2); ?></td>
                                        <td><?php echo htmlspecialchars($record['km_reading']); ?></td>
                                        <td><?php echo htmlspecialchars($record['payment_method']); ?></td>
                                        <td><?php echo $record['account_number'] ? htmlspecialchars($record['account_number']) : '-'; ?></td>
                                        <td><?php echo htmlspecialchars($record['username']); ?></td>
                                        <td class="actions">
                                            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editFuelModal<?php echo $record['id']; ?>">Edit</button>
                                        </td>
                                    </tr>
                                    <div class="modal fade" id="editFuelModal<?php echo $record['id']; ?>" tabindex="-1" aria-labelledby="editFuelModalLabel<?php echo $record['id']; ?>" aria-hidden="true">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title" id="editFuelModalLabel<?php echo $record['id']; ?>">Edit Fuel Record #<?php echo $record['id']; ?></h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <form method="POST">
                                                        <input type="hidden" name="fuel_id" value="<?php echo $record['id']; ?>">
                                                        <div class="mb-3">
                                                            <label for="edit_date_<?php echo $record['id']; ?>" class="form-label">Date</label>
                                                            <input type="date" class="form-control" id="edit_date_<?php echo $record['id']; ?>" name="date" value="<?php echo htmlspecialchars($record['date']); ?>" required>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label for="edit_vehicle_name_<?php echo $record['id']; ?>" class="form-label">Vehicle</label>
                                                            <input type="text" class="form-control" id="edit_vehicle_name_<?php echo $record['id']; ?>" name="vehicle_name" value="<?php echo htmlspecialchars($record['vehicle_name']); ?>" list="vehicle-list" required>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label for="edit_fuel_amount_<?php echo $record['id']; ?>" class="form-label">Fuel Amount (₹)</label>
                                                            <input type="number" class="form-control" id="edit_fuel_amount_<?php echo $record['id']; ?>" name="fuel_amount" step="0.01" min="0" value="<?php echo htmlspecialchars($record['fuel_amount']); ?>" required>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label for="edit_km_reading_<?php echo $record['id']; ?>" class="form-label">KM Reading</label>
                                                            <input type="number" class="form-control" id="edit_km_reading_<?php echo $record['id']; ?>" name="km_reading" min="0" value="<?php echo htmlspecialchars($record['km_reading']); ?>" required>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label for="edit_payment_method_<?php echo $record['id']; ?>" class="form-label">Payment Method</label>
                                                            <select class="form-control" id="edit_payment_method_<?php echo $record['id']; ?>" name="payment_method" required onchange="toggleEditBankAccount(<?php echo $record['id']; ?>)">
                                                                <option value="Cash" <?php echo $record['payment_method'] == 'Cash' ? 'selected' : ''; ?>>Cash</option>
                                                                <option value="Bank" <?php echo $record['payment_method'] == 'Bank' ? 'selected' : ''; ?>>Bank</option>
                                                            </select>
                                                        </div>
                                                        <div class="mb-3 edit_bank_account_id_container" id="edit_bank_account_id_container_<?php echo $record['id']; ?>">
                                                            <label for="edit_bank_account_id_<?php echo $record['id']; ?>" class="form-label">Bank Account</label>
                                                            <select class="form-control" id="edit_bank_account_id_<?php echo $record['id']; ?>" name="bank_account_id">
                                                                <option value="">Select Account</option>
                                                                <?php foreach ($bank_accounts as $account): ?>
                                                                    <option value="<?php echo $account['id']; ?>" <?php echo $account['id'] == $record['bank_account_id'] ? 'selected' : ''; ?>>
                                                                        <?php echo htmlspecialchars($account['bank_name'] . ' - ' . $account['account_number']); ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        <button type="submit" name="edit_fuel" class="btn btn-primary">Save Changes</button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <button class="btn btn-primary mt-3" onclick="window.print()">Print Fuel Records</button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <footer>
        <p class="mb-0">© <?php echo date('Y'); ?> SL Diagnostics. All rights reserved.</p>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleBankAccount() {
            const paymentMethod = document.getElementById('payment_method').value;
            const container = document.getElementById('bank_account_id_container');
            const select = document.getElementById('bank_account_id');
            container.style.display = paymentMethod === 'Bank' ? 'block' : 'none';
            select.required = paymentMethod === 'Bank';
        }

        function toggleEditBankAccount(recordId) {
            const paymentMethod = document.getElementById('edit_payment_method_' + recordId).value;
            const container = document.getElementById('edit_bank_account_id_container_' + recordId);
            const select = document.getElementById('edit_bank_account_id_' + recordId);
            container.style.display = paymentMethod === 'Bank' ? 'block' : 'none';
            select.required = paymentMethod === 'Bank';
        }

        // Initialize visibility on page load
        document.addEventListener('DOMContentLoaded', () => {
            toggleBankAccount();
            <?php foreach ($fuel_records as $record): ?>
                toggleEditBankAccount(<?php echo $record['id']; ?>);
            <?php endforeach; ?>
        });
    </script>
</body>
</html>
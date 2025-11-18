<?php
session_start();
if (!isset($_SESSION['user_id'])) {
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
    require_once 'includes/db_connect.php';
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}

$success_message = '';
$error_message = '';
$bank_accounts = [];
$headings = [];
$withdrawals = [];
$filter_date_from = $_POST['filter_date_from'] ?? date('Y-m-d'); // Default to current date
$filter_date_to = $_POST['filter_date_to'] ?? date('Y-m-d'); // Default to current date

try {
    // Fetch bank accounts
    $stmt = $pdo->query("SELECT id, bank_name, account_number, balance FROM bank_accounts ORDER BY bank_name");
    $bank_accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch existing withdrawal headings
    $stmt = $pdo->query("SELECT DISTINCT heading FROM bank_withdrawals ORDER BY heading");
    $headings = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Fetch withdrawals with bank and user details, applying date filter if set
    $query = "
        SELECT bw.id, bw.bank_account_id, bw.amount, bw.heading, bw.description, bw.date, bw.user_id,
               ba.bank_name, ba.account_number, u.username
        FROM bank_withdrawals bw
        JOIN bank_accounts ba ON bw.bank_account_id = ba.id
        JOIN users u ON bw.user_id = u.id
    ";
    $params = [];
    if ($filter_date_from && $filter_date_to) {
        $query .= " WHERE bw.date BETWEEN ? AND ?";
        $params = [$filter_date_from, $filter_date_to];
    } elseif ($filter_date_from) {
        $query .= " WHERE bw.date >= ?";
        $params = [$filter_date_from];
    } elseif ($filter_date_to) {
        $query .= " WHERE bw.date <= ?";
        $params = [$filter_date_to];
    }
    $query .= " ORDER BY bw.date DESC, bw.id DESC";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $withdrawals = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching data: " . $e->getMessage());
    $error_message = "Failed to load data: " . htmlspecialchars($e->getMessage());
}

// Handle new withdrawal submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_withdrawal'])) {
    try {
        $bank_account_id = $_POST['bank_account_id'];
        $amount = floatval($_POST['amount']);
        $heading = trim($_POST['heading']);
        $new_heading = trim($_POST['new_heading'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $date = $_POST['date'] ?? date('Y-m-d');
        $user_id = $_SESSION['user_id'];

        // Validate date
        if (!DateTime::createFromFormat('Y-m-d', $date)) {
            throw new Exception("Invalid date format.");
        }

        // Use new heading if provided, else use selected heading
        $final_heading = !empty($new_heading) ? $new_heading : $heading;

        if (empty($bank_account_id) || $amount <= 0 || empty($final_heading)) {
            throw new Exception("Please fill all required fields with valid values.");
        }

        // Fetch current bank balance
        $stmt = $pdo->prepare("SELECT balance FROM bank_accounts WHERE id = ?");
        $stmt->execute([$bank_account_id]);
        $current_balance = $stmt->fetchColumn();

        if ($current_balance === false) {
            throw new Exception("Invalid bank account selected.");
        }

        if ($amount > $current_balance) {
            throw new Exception("Withdrawal amount exceeds available balance.");
        }

        // Begin transaction
        $pdo->beginTransaction();

        // Update bank account balance
        $new_balance = $current_balance - $amount;
        $stmt = $pdo->prepare("UPDATE bank_accounts SET balance = ? WHERE id = ?");
        $stmt->execute([$new_balance, $bank_account_id]);

        // Insert withdrawal record
        $stmt = $pdo->prepare("
            INSERT INTO bank_withdrawals (bank_account_id, amount, heading, description, date, user_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$bank_account_id, $amount, $final_heading, $description, $date, $user_id]);

        // Update or insert cash on hand
        $stmt = $pdo->prepare("SELECT amount FROM cash_on_hand WHERE date = ?");
        $stmt->execute([$date]);
        $existing_cash = $stmt->fetchColumn();

        if ($existing_cash !== false) {
            $new_cash_amount = $existing_cash + $amount;
            $stmt = $pdo->prepare("UPDATE cash_on_hand SET amount = ? WHERE date = ?");
            $stmt->execute([$new_cash_amount, $date]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO cash_on_hand (date, amount) VALUES (?, ?)");
            $stmt->execute([$date, $amount]);
        }

        // Commit transaction
        $pdo->commit();
        $success_message = "Withdrawal of ₹" . formatIndianNumber($amount) . " processed successfully.";

        // Refresh data
        $stmt = $pdo->query("SELECT id, bank_name, account_number, balance FROM bank_accounts ORDER BY bank_name");
        $bank_accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($new_heading)) {
            $stmt = $pdo->query("SELECT DISTINCT heading FROM bank_withdrawals ORDER BY heading");
            $headings = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $withdrawals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error processing withdrawal: " . $e->getMessage());
        $error_message = "Withdrawal failed: " . htmlspecialchars($e->getMessage());
    }
}

// Handle edit withdrawal submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_withdrawal'])) {
    try {
        $withdrawal_id = $_POST['withdrawal_id'];
        $new_amount = floatval($_POST['amount']);
        $new_heading = trim($_POST['heading']);
        $new_new_heading = trim($_POST['new_heading'] ?? '');
        $new_description = trim($_POST['description'] ?? '');
        $final_heading = !empty($new_new_heading) ? $new_new_heading : $new_heading;

        if ($new_amount < 0 || empty($final_heading)) {
            throw new Exception("Please provide valid amount and heading.");
        }

        // Begin transaction
        $pdo->beginTransaction();

        // Fetch existing withdrawal details and lock the row for update
        $stmt = $pdo->prepare("SELECT bank_account_id, amount, date FROM bank_withdrawals WHERE id = ? FOR UPDATE");
        $stmt->execute([$withdrawal_id]);
        $withdrawal = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$withdrawal) {
            throw new Exception("Withdrawal record not found.");
        }

        $bank_account_id = $withdrawal['bank_account_id'];
        $old_amount = floatval($withdrawal['amount']);
        $date = $withdrawal['date'];

        // Calculate the difference between the new and old amount
        $amount_difference = $new_amount - $old_amount;

        // Fetch current bank balance and check for sufficient funds
        $stmt = $pdo->prepare("SELECT balance FROM bank_accounts WHERE id = ? FOR UPDATE");
        $stmt->execute([$bank_account_id]);
        $current_balance = $stmt->fetchColumn();

        if ($current_balance === false) {
            throw new Exception("Invalid bank account associated with the withdrawal.");
        }
        
        $updated_bank_balance = $current_balance - $amount_difference;

        if ($updated_bank_balance < 0) {
            throw new Exception("The edited amount exceeds the available bank balance.");
        }

        // Update bank account balance with the difference
        $stmt = $pdo->prepare("UPDATE bank_accounts SET balance = ? WHERE id = ?");
        $stmt->execute([$updated_bank_balance, $bank_account_id]);

        // Update or insert cash on hand for the withdrawal's date based on the difference
        $stmt = $pdo->prepare("SELECT amount FROM cash_on_hand WHERE date = ? FOR UPDATE");
        $stmt->execute([$date]);
        $existing_cash = $stmt->fetchColumn();

        if ($existing_cash !== false) {
            $new_cash_amount = $existing_cash + $amount_difference;
            if ($new_cash_amount > 0) {
                $stmt = $pdo->prepare("UPDATE cash_on_hand SET amount = ? WHERE date = ?");
                $stmt->execute([$new_cash_amount, $date]);
            } else {
                $stmt = $pdo->prepare("DELETE FROM cash_on_hand WHERE date = ?");
                $stmt->execute([$date]);
            }
        } elseif ($amount_difference > 0) {
            $stmt = $pdo->prepare("INSERT INTO cash_on_hand (date, amount) VALUES (?, ?)");
            $stmt->execute([$date, $amount_difference]);
        }

        // Update the withdrawal record with the new details
        $stmt = $pdo->prepare("
            UPDATE bank_withdrawals
            SET amount = ?, heading = ?, description = ?
            WHERE id = ?
        ");
        $stmt->execute([$new_amount, $final_heading, $new_description, $withdrawal_id]);
        
        // Commit transaction
        $pdo->commit();
        $success_message = "Withdrawal updated successfully.";

        // Refresh data to show changes
        $stmt = $pdo->query("SELECT id, bank_name, account_number, balance FROM bank_accounts ORDER BY bank_name");
        $bank_accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->query("SELECT DISTINCT heading FROM bank_withdrawals ORDER BY heading");
        $headings = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $withdrawals = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error updating withdrawal: " . $e->getMessage());
        $error_message = "Update failed: " . htmlspecialchars($e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SL Diagnostics - Bank Withdrawals</title>
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
            background-color: #005670;
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
        .btn-warning {
            background-color: #ffc107;
            border: none;
        }
        .btn-warning:hover {
            background-color: #e0a800;
        }
        footer {
            text-align: center;
            padding: 1rem;
            background-color: #ffffff;
            border-top: 1px solid #e0e0e0;
            margin-top: 2rem;
        }
        .new-heading-field {
            display: none;
        }
        .table-responsive {
            margin-top: 1rem;
        }
        .balance-column {
            text-align: right;
        }
        .filter-form {
            margin-bottom: 1rem;
        }
        @media print {
            .navbar, footer, .btn, .modal, .filter-form {
                display: none;
            }
            .content {
                margin-top: 0;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg fixed-top">
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
                        <a class="nav-link" href="dashboard.php">Dashboard</a>
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
        <h1 class="display-5 mb-4">Bank Withdrawals</h1>

        <?php if ($success_message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
        <?php elseif ($error_message): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

        <div class="card mb-4">
            <div class="card-header">Record Bank Withdrawal</div>
            <div class="card-body">
                <form method="POST">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="bank_account_id" class="form-label">Bank Account</label>
                            <select class="form-select" id="bank_account_id" name="bank_account_id" required>
                                <option value="">Select Bank Account</option>
                                <?php foreach ($bank_accounts as $account): ?>
                                    <option value="<?php echo htmlspecialchars($account['id']); ?>">
                                        <?php echo htmlspecialchars($account['bank_name'] . ' - ' . $account['account_number'] . ' (₹' . formatIndianNumber($account['balance']) . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="amount" class="form-label">Withdrawal Amount (₹)</label>
                            <input type="number" class="form-control" id="amount" name="amount" step="0.01" min="0" required>
                        </div>
                        <div class="col-md-6">
                            <label for="date" class="form-label">Withdrawal Date</label>
                            <input type="date" class="form-control" id="date" name="date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label for="heading" class="form-label">Heading</label>
                            <select class="form-select" id="heading" name="heading" onchange="toggleNewHeadingField()">
                                <option value="">Select Heading</option>
                                <option value="new">Add New Heading</option>
                                <?php foreach ($headings as $heading): ?>
                                    <option value="<?php echo htmlspecialchars($heading); ?>"><?php echo htmlspecialchars($heading); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 new-heading-field" id="new_heading_field">
                            <label for="new_heading" class="form-label">New Heading</label>
                            <input type="text" class="form-control" id="new_heading" name="new_heading">
                        </div>
                        <div class="col-12">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="4"></textarea>
                        </div>
                    </div>
                    <button type="submit" name="submit_withdrawal" class="btn btn-primary mt-3">Record Withdrawal</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Withdrawal Records</div>
            <div class="card-body">
                <form method="POST" class="filter-form">
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label for="filter_date_from" class="form-label">Date From</label>
                            <input type="date" class="form-control" id="filter_date_from" name="filter_date_from" value="<?php echo htmlspecialchars($filter_date_from); ?>">
                        </div>
                        <div class="col-md-4">
                            <label for="filter_date_to" class="form-label">Date To</label>
                            <input type="date" class="form-control" id="filter_date_to" name="filter_date_to" value="<?php echo htmlspecialchars($filter_date_to); ?>">
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary">Filter</button>
                            <a href="bank_withdrawals.php" class="btn btn-secondary ms-2">Clear</a>
                        </div>
                    </div>
                </form>
                <?php if (empty($withdrawals)): ?>
                    <p class="text-muted">No withdrawals recorded<?php echo ($filter_date_from || $filter_date_to) ? ' for the selected date range' : ''; ?>.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Bank Name</th>
                                    <th>Account Number</th>
                                    <th class="balance-column">Amount (₹)</th>
                                    <th>Heading</th>
                                    <th>Description</th>
                                    <th>User</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($withdrawals as $row): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['date']); ?></td>
                                        <td><?php echo htmlspecialchars($row['bank_name']); ?></td>
                                        <td><?php echo htmlspecialchars($row['account_number']); ?></td>
                                        <td class="balance-column">₹<?php echo formatIndianNumber($row['amount']); ?></td>
                                        <td><?php echo htmlspecialchars($row['heading']); ?></td>
                                        <td><?php echo htmlspecialchars($row['description'] ?: '-'); ?></td>
                                        <td><?php echo htmlspecialchars($row['username']); ?></td>
                                        <td>
                                            <button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#editModal<?php echo $row['id']; ?>">Edit</button>
                                        </td>
                                    </tr>

                                    <div class="modal fade" id="editModal<?php echo $row['id']; ?>" tabindex="-1" aria-labelledby="editModalLabel<?php echo $row['id']; ?>" aria-hidden="true">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title" id="editModalLabel<?php echo $row['id']; ?>">Edit Withdrawal</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <form method="POST">
                                                        <input type="hidden" name="withdrawal_id" value="<?php echo $row['id']; ?>">
                                                        <div class="mb-3">
                                                            <label for="edit_amount_<?php echo $row['id']; ?>" class="form-label">Withdrawal Amount (₹)</label>
                                                            <input type="number" class="form-control" id="edit_amount_<?php echo $row['id']; ?>" name="amount" step="0.01" min="0" value="<?php echo htmlspecialchars($row['amount']); ?>" required>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label for="edit_heading_<?php echo $row['id']; ?>" class="form-label">Heading</label>
                                                            <select class="form-select" id="edit_heading_<?php echo $row['id']; ?>" name="heading" onchange="toggleEditNewHeadingField('<?php echo $row['id']; ?>')">
                                                                <option value="">Select Heading</option>
                                                                <option value="new">Add New Heading</option>
                                                                <?php foreach ($headings as $heading): ?>
                                                                    <option value="<?php echo htmlspecialchars($heading); ?>" <?php echo $row['heading'] === $heading ? 'selected' : ''; ?>>
                                                                        <?php echo htmlspecialchars($heading); ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        <div class="mb-3 new-heading-field" id="edit_new_heading_field_<?php echo $row['id']; ?>">
                                                            <label for="edit_new_heading_<?php echo $row['id']; ?>" class="form-label">New Heading</label>
                                                            <input type="text" class="form-control" id="edit_new_heading_<?php echo $row['id']; ?>" name="new_heading">
                                                        </div>
                                                        <div class="mb-3">
                                                            <label for="edit_description_<?php echo $row['id']; ?>" class="form-label">Description</label>
                                                            <textarea class="form-control" id="edit_description_<?php echo $row['id']; ?>" name="description" rows="4"><?php echo htmlspecialchars($row['description'] ?: ''); ?></textarea>
                                                        </div>
                                                        <button type="submit" name="edit_withdrawal" class="btn btn-primary">Save Changes</button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <footer>
        <p class="mb-0">© <?php echo date('Y'); ?> SL Diagnostics. All rights reserved.</p>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleNewHeadingField() {
            const headingSelect = document.getElementById('heading');
            const newHeadingField = document.getElementById('new_heading_field');
            newHeadingField.style.display = headingSelect.value === 'new' ? 'block' : 'none';
        }

        function toggleEditNewHeadingField(id) {
            const headingSelect = document.getElementById('edit_heading_' + id);
            const newHeadingField = document.getElementById('edit_new_heading_field_' + id);
            newHeadingField.style.display = headingSelect.value === 'new' ? 'block' : 'none';
        }

        // Initialize field visibility on page load
        document.addEventListener('DOMContentLoaded', function() {
            toggleNewHeadingField();
            <?php foreach ($withdrawals as $row): ?>
                // Also check if the modal's heading select is already 'new' on load (e.g., due to form re-submission)
                const editHeadingSelect = document.getElementById('edit_heading_<?php echo $row['id']; ?>');
                if (editHeadingSelect) {
                    toggleEditNewHeadingField('<?php echo $row['id']; ?>');
                }
            <?php endforeach; ?>
        });
    </script>
</body>
</html>
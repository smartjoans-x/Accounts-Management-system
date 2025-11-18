<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

try {
    require_once 'includes/db_connect.php';
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}

// FIX: Validate that the user_id from the session exists in the database.
// This prevents foreign key constraint errors if the user was deleted or the session is invalid.
try {
    $user_check_stmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
    $user_check_stmt->execute([$_SESSION['user_id']]);
    if ($user_check_stmt->fetch() === false) {
        // User ID in session not found in the database.
        // Destroy the invalid session and redirect to login.
        session_unset();
        session_destroy();
        header('Location: login.php?error=invalid_session');
        exit;
    }
} catch (PDOException $e) {
    // If the check fails for some reason, log it and show a generic error.
    error_log("Session user validation failed: " . $e->getMessage());
    die("An error occurred while verifying user session. Please try again later.");
}


$date = !empty($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$staff_id = isset($_GET['staff_id']) ? $_GET['staff_id'] : '';
$branch = isset($_GET['branch']) ? $_GET['branch'] : '';
$branch_receipts = [];
$other_receipts = [];
$staff_list = [];
$branches = [];
$banks = [];
$departments = [];
$receipt_from_list = [];
$branch_total_cash = 0;
$branch_total_card = 0;
$branch_total_upi = 0;
$branch_total_cheque = 0;
$other_total_cash = 0;
$other_total_bank = 0;
$other_total_cheque = 0;
$error = null;
$success = null;

// Fetch branch receipts
try {
    $query = "SELECT cr.*, s.name AS staff_name, ba.bank_name, ba.account_number, u.username 
              FROM cash_receipts cr 
              LEFT JOIN staff s ON cr.staff_id = s.id 
              LEFT JOIN bank_accounts ba ON cr.bank_account_id = ba.id 
              JOIN users u ON cr.user_id = u.id 
              WHERE cr.date = ?";
    $params = [$date];
    if ($staff_id) {
        $query .= " AND cr.staff_id = ?";
        $params[] = $staff_id;
    }
    if ($branch) {
        $query .= " AND cr.branch = ?";
        $params[] = $branch;
    }
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $branch_receipts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($branch_receipts as $r) {
        if ($r['payment_method'] == 'Cash') $branch_total_cash += $r['amount'];
        elseif ($r['payment_method'] == 'Card') $branch_total_card += $r['amount'];
        elseif ($r['payment_method'] == 'UPI') $branch_total_upi += $r['amount'];
        elseif ($r['payment_method'] == 'Cheque') $branch_total_cheque += $r['amount'];
    }
} catch (PDOException $e) {
    error_log("Error fetching branch receipts: " . $e->getMessage());
    $error = "Failed to load branch receipts.";
}

// Fetch other receipts
try {
    $query = "SELECT orr.*, u.username, ba.bank_name, ba.account_number 
              FROM other_receipts orr 
              JOIN users u ON orr.user_id = u.id 
              LEFT JOIN bank_accounts ba ON orr.bank_account_id = ba.id 
              WHERE orr.date = ?";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$date]);
    $other_receipts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($other_receipts as $r) {
        if ($r['payment_method'] == 'Cash') $other_total_cash += $r['amount'];
        elseif ($r['payment_method'] == 'Bank') $other_total_bank += $r['amount'];
        elseif ($r['payment_method'] == 'Cheque') $other_total_cheque += $r['amount'];
    }
} catch (PDOException $e) {
    error_log("Error fetching other receipts: " . $e->getMessage());
    $error = "Failed to load other receipts.";
}

// Fetch additional data
try {
    $staff_list = $pdo->query("SELECT id, name, branch FROM staff ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    $branches = $pdo->query("SELECT DISTINCT branch FROM staff ORDER BY branch")->fetchAll(PDO::FETCH_COLUMN);
    $banks = $pdo->query("SELECT id, bank_name, account_number FROM bank_accounts ORDER BY bank_name")->fetchAll(PDO::FETCH_ASSOC);
    $departments = $pdo->query("SELECT DISTINCT department FROM other_receipts WHERE department IS NOT NULL ORDER BY department")->fetchAll(PDO::FETCH_COLUMN);
    $receipt_from_list = $pdo->query("SELECT DISTINCT receipt_from FROM other_receipts WHERE receipt_from IS NOT NULL ORDER BY receipt_from")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log("Error fetching data: " . $e->getMessage());
    $error = "Failed to load data.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['add_staff'])) {
            $staff_name = trim($_POST['staff_name']);
            $staff_branch = trim($_POST['staff_branch']);
            $new_branch = isset($_POST['new_branch']) ? trim($_POST['new_branch']) : '';
            $branch_to_save = ($staff_branch === 'new' && !empty($new_branch)) ? $new_branch : $staff_branch;
            if (empty($staff_name) || empty($branch_to_save)) {
                throw new Exception("Staff name and branch are required.");
            }
            $stmt = $pdo->prepare("INSERT INTO staff (name, branch) VALUES (?, ?)");
            $stmt->execute([$staff_name, $branch_to_save]);
            $success = "Staff added successfully.";
            header('Location: cash_receipts.php?date=' . urlencode($date));
            exit;
        } elseif (isset($_POST['add_receipt'])) {
            $receipt_mode = $_POST['receipt_mode'];
            $receipt_date = !empty($_POST['receipt_date']) ? $_POST['receipt_date'] : $date;
            if ($receipt_mode === 'branch') {
                $staff_id = $_POST['staff_id'];
                $branch = $_POST['branch'];
                $amounts = [
                    'Cash' => (float)(isset($_POST['amount_cash']) && is_numeric($_POST['amount_cash']) ? $_POST['amount_cash'] : 0),
                    'Card' => (float)(isset($_POST['amount_card']) && is_numeric($_POST['amount_card']) ? $_POST['amount_card'] : 0),
                    'UPI' => (float)(isset($_POST['amount_upi']) && is_numeric($_POST['amount_upi']) ? $_POST['amount_upi'] : 0),
                    'Cheque' => (float)(isset($_POST['amount_cheque']) && is_numeric($_POST['amount_cheque']) ? $_POST['amount_cheque'] : 0)
                ];
                $bank_account_ids = [
                    'Card' => !empty($_POST['bank_account_id_card']) ? $_POST['bank_account_id_card'] : null,
                    'UPI' => !empty($_POST['bank_account_id_upi']) ? $_POST['bank_account_id_upi'] : null,
                    'Cheque' => null
                ];
                $cheque_number = !empty($_POST['cheque_number']) ? $_POST['cheque_number'] : null;
                $description = !empty($_POST['description']) ? $_POST['description'] : null;
                if (empty($staff_id) || empty($branch)) {
                    throw new Exception("Staff and branch are required.");
                }
                $total_amount = array_sum($amounts);
                if ($total_amount <= 0) {
                    throw new Exception("At least one payment amount must be greater than zero.");
                }
                if ($amounts['Card'] > 0 && empty($bank_account_ids['Card'])) {
                    throw new Exception("Bank account required for Card payment.");
                }
                if ($amounts['UPI'] > 0 && empty($bank_account_ids['UPI'])) {
                    throw new Exception("Bank account required for UPI payment.");
                }
                if ($amounts['Cheque'] > 0 && empty($cheque_number)) {
                    throw new Exception("Cheque number required for Cheque payment.");
                }
                $pdo->beginTransaction();
                foreach ($amounts as $method => $amount) {
                    if ($amount > 0) {
                        $bank_account_id = ($method === 'Cash' || $method === 'Cheque') ? null : $bank_account_ids[$method];
                        $stmt = $pdo->prepare("INSERT INTO cash_receipts (date, staff_id, branch, payment_method, bank_account_id, amount, user_id, cheque_number, description, is_deposited) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, FALSE)");
                        $stmt->execute([$receipt_date, $staff_id, $branch, $method, $bank_account_id, $amount, $_SESSION['user_id'], $method === 'Cheque' ? $cheque_number : null, $method === 'Cheque' ? $description : null]);
                        if ($method === 'Cash') {
                            $stmt = $pdo->prepare("INSERT INTO cash_on_hand (date, amount) VALUES (?, ?) ON DUPLICATE KEY UPDATE amount = amount + ?");
                            $stmt->execute([$receipt_date, $amount, $amount]);
                        } elseif ($method !== 'Cheque' && $bank_account_id) {
                            $stmt = $pdo->prepare("UPDATE bank_accounts SET balance = balance + ? WHERE id = ?");
                            $stmt->execute([$amount, $bank_account_id]);
                        }
                    }
                }
                $pdo->commit();
                $success = "Branch Receipt saved successfully.";
            } elseif ($receipt_mode === 'other') {
                $department = $_POST['department'];
                $new_department = isset($_POST['new_department']) ? trim($_POST['new_department']) : '';
                $department_to_save = ($department === 'new' && !empty($new_department)) ? $new_department : ($department ?: null);
                $receipt_from = $_POST['receipt_from'] === 'new' && !empty($_POST['new_receipt_from']) ? trim($_POST['new_receipt_from']) : ($_POST['receipt_from'] ?: null);
                $description = isset($_POST['description_other']) ? trim($_POST['description_other']) : null;
                $amount = (float)(isset($_POST['amount']) && is_numeric($_POST['amount']) ? $_POST['amount'] : 0);
                $payment_mode = $_POST['payment_mode'];
                $bank_account_id = ($payment_mode === 'Bank' && !empty($_POST['bank_account_id'])) ? $_POST['bank_account_id'] : null;
                $cheque_number = ($payment_mode === 'Cheque' && !empty($_POST['cheque_number_other'])) ? $_POST['cheque_number_other'] : null;

                if ($payment_mode === 'Bank' && empty($bank_account_id)) {
                    throw new Exception("Bank account required for Bank payment.");
                }
                if ($payment_mode === 'Cheque' && empty($cheque_number)) {
                    throw new Exception("Cheque number is required for Cheque payment.");
                }
                if ($department === 'new' && empty($new_department)) {
                    throw new Exception("New department name is required when adding a new department.");
                }
                if ($_POST['receipt_from'] === 'new' && empty($_POST['new_receipt_from'])) {
                    throw new Exception("New receipt from is required when adding a new receipt from.");
                }
                if ($amount <= 0) {
                    throw new Exception("Amount must be greater than zero.");
                }

                $pdo->beginTransaction();
                $stmt = $pdo->prepare("INSERT INTO other_receipts (date, department, receipt_from, payment_method, bank_account_id, amount, user_id, description, cheque_number, is_deposited) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, FALSE)");
                $stmt->execute([$receipt_date, $department_to_save, $receipt_from, $payment_mode, $bank_account_id, $amount, $_SESSION['user_id'], $description, $cheque_number]);
                
                if ($payment_mode === 'Cash' && $amount > 0) {
                    $stmt = $pdo->prepare("INSERT INTO cash_on_hand (date, amount) VALUES (?, ?) ON DUPLICATE KEY UPDATE amount = amount + ?");
                    $stmt->execute([$receipt_date, $amount, $amount]);
                } elseif ($payment_mode === 'Bank' && $bank_account_id && $amount > 0) {
                    $stmt = $pdo->prepare("UPDATE bank_accounts SET balance = balance + ? WHERE id = ?");
                    $stmt->execute([$amount, $bank_account_id]);
                }
                $pdo->commit();
                $success = "Other Receipt saved successfully.";
            }
            header('Location: cash_receipts.php?date=' . urlencode($receipt_date));
            exit;
        } elseif (isset($_POST['edit_receipt'])) {
            $id = $_POST['id'];
            $receipt_date = !empty($_POST['receipt_date']) ? $_POST['receipt_date'] : $date;
            $amount = (float)(isset($_POST['amount']) && is_numeric($_POST['amount']) ? $_POST['amount'] : 0);
            $payment_method = $_POST['payment_method'];
            $bank_account_id = ($payment_method === 'Cash' || $payment_method === 'Cheque') ? null : (isset($_POST['bank_account_id']) ? $_POST['bank_account_id'] : null);
            $cheque_number = ($payment_method === 'Cheque') ? (isset($_POST['cheque_number']) ? $_POST['cheque_number'] : null) : null;
            $description = ($payment_method === 'Cheque') ? (isset($_POST['description']) ? $_POST['description'] : null) : null;
            $staff_id = $_POST['staff_id'];
            $branch = $_POST['branch'];
            if (($payment_method === 'Card' || $payment_method === 'UPI') && empty($bank_account_id)) {
                throw new Exception("Bank account required for $payment_method payment.");
            }
            if ($payment_method === 'Cheque' && empty($cheque_number)) {
                throw new Exception("Cheque number required for Cheque payment.");
            }
            if (empty($staff_id) || empty($branch)) {
                throw new Exception("Staff and branch are required.");
            }
            $stmt = $pdo->prepare("SELECT amount, payment_method, bank_account_id, date FROM cash_receipts WHERE id = ?");
            $stmt->execute([$id]);
            $old_receipt = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$old_receipt) {
                throw new Exception("Receipt not found.");
            }
            $pdo->beginTransaction();
            if ($old_receipt['payment_method'] === 'Cash' && $old_receipt['amount'] > 0) {
                $stmt = $pdo->prepare("UPDATE cash_on_hand SET amount = amount - ? WHERE date = ?");
                $stmt->execute([$old_receipt['amount'], $old_receipt['date']]);
            } elseif ($old_receipt['bank_account_id'] && $old_receipt['amount'] > 0) {
                $stmt = $pdo->prepare("UPDATE bank_accounts SET balance = balance - ? WHERE id = ?");
                $stmt->execute([$old_receipt['amount'], $old_receipt['bank_account_id']]);
            }
            $stmt = $pdo->prepare("UPDATE cash_receipts SET date = ?, amount = ?, payment_method = ?, bank_account_id = ?, staff_id = ?, branch = ?, user_id = ?, cheque_number = ?, description = ? WHERE id = ?");
            $stmt->execute([$receipt_date, $amount, $payment_method, $bank_account_id, $staff_id, $branch, $_SESSION['user_id'], $cheque_number, $description, $id]);
            if ($payment_method === 'Cash' && $amount > 0) {
                $stmt = $pdo->prepare("INSERT INTO cash_on_hand (date, amount) VALUES (?, ?) ON DUPLICATE KEY UPDATE amount = amount + ?");
                $stmt->execute([$receipt_date, $amount, $amount]);
            } elseif ($payment_method !== 'Cheque' && $bank_account_id && $amount > 0) {
                $stmt = $pdo->prepare("UPDATE bank_accounts SET balance = balance + ? WHERE id = ?");
                $stmt->execute([$amount, $bank_account_id]);
            }
            $pdo->commit();
            $success = "Receipt updated successfully.";
            header('Location: cash_receipts.php?date=' . urlencode($receipt_date));
            exit;
        } elseif (isset($_POST['edit_other_receipt'])) {
            $id = $_POST['id'];
            $receipt_date = !empty($_POST['receipt_date']) ? $_POST['receipt_date'] : $date;
            $amount = (float)(isset($_POST['amount']) && is_numeric($_POST['amount']) ? $_POST['amount'] : 0);
            $payment_method = $_POST['payment_method'];
            $bank_account_id = ($payment_method === 'Bank') ? (isset($_POST['bank_account_id']) ? $_POST['bank_account_id'] : null) : null;
            $cheque_number = ($payment_method === 'Cheque') ? (isset($_POST['cheque_number']) ? $_POST['cheque_number'] : null) : null;
            $department = trim($_POST['department']);
            $new_department = isset($_POST['new_department']) ? trim($_POST['new_department']) : '';
            $department_to_save = ($department === 'new' && !empty($new_department)) ? $new_department : ($department ?: null);
            $receipt_from = $_POST['receipt_from'] === 'new' && !empty($_POST['new_receipt_from']) ? trim($_POST['new_receipt_from']) : ($_POST['receipt_from'] ?: null);
            $description = isset($_POST['description']) ? trim($_POST['description']) : null;
            if ($payment_method === 'Bank' && empty($bank_account_id)) {
                throw new Exception("Bank account required for Bank payment.");
            }
            if ($payment_method === 'Cheque' && empty($cheque_number)) {
                throw new Exception("Cheque number required for Cheque payment.");
            }
            if ($department === 'new' && empty($new_department)) {
                throw new Exception("New department name is required when adding a new department.");
            }
            if ($_POST['receipt_from'] === 'new' && empty($_POST['new_receipt_from'])) {
                throw new Exception("New receipt from is required when adding a new receipt from.");
            }
            $stmt = $pdo->prepare("SELECT amount, payment_method, bank_account_id, date FROM other_receipts WHERE id = ?");
            $stmt->execute([$id]);
            $old_receipt = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$old_receipt) {
                throw new Exception("Receipt not found.");
            }
            $pdo->beginTransaction();
            if ($old_receipt['payment_method'] === 'Cash' && $old_receipt['amount'] > 0) {
                $stmt = $pdo->prepare("UPDATE cash_on_hand SET amount = amount - ? WHERE date = ?");
                $stmt->execute([$old_receipt['amount'], $old_receipt['date']]);
            } elseif ($old_receipt['bank_account_id'] && $old_receipt['amount'] > 0) {
                $stmt = $pdo->prepare("UPDATE bank_accounts SET balance = balance - ? WHERE id = ?");
                $stmt->execute([$old_receipt['amount'], $old_receipt['bank_account_id']]);
            }
            $stmt = $pdo->prepare("UPDATE other_receipts SET date = ?, amount = ?, payment_method = ?, bank_account_id = ?, department = ?, receipt_from = ?, user_id = ?, description = ?, cheque_number = ? WHERE id = ?");
            $stmt->execute([$receipt_date, $amount, $payment_method, $bank_account_id, $department_to_save, $receipt_from, $_SESSION['user_id'], $description, $cheque_number, $id]);
            if ($payment_method === 'Cash' && $amount > 0) {
                $stmt = $pdo->prepare("INSERT INTO cash_on_hand (date, amount) VALUES (?, ?) ON DUPLICATE KEY UPDATE amount = amount + ?");
                $stmt->execute([$receipt_date, $amount, $amount]);
            } elseif ($payment_method === 'Bank' && $bank_account_id && $amount > 0) {
                $stmt = $pdo->prepare("UPDATE bank_accounts SET balance = balance + ? WHERE id = ?");
                $stmt->execute([$amount, $bank_account_id]);
            }
            $pdo->commit();
            $success = "Other Receipt updated successfully.";
            header('Location: cash_receipts.php?date=' . urlencode($receipt_date));
            exit;
        }
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
        error_log("Save error: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SL Diagnostics - Cash Receipts</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Roboto', sans-serif; background-color: #f8f9fa; }
        .navbar { background-color: #ffffff; box-shadow: 0 2px 4px rgba(0,0,0,0.1); padding: 1rem 0; }
        .navbar-brand { display: flex; align-items: center; gap: 0.75rem; }
        .navbar-brand img { max-height: 40px; width: auto; }
        .navbar-brand span { font-size: 1.5rem; font-weight: 700; color: #005670; }
        .content { margin-top: 80px; padding: 2rem; max-width: 1400px; margin-left: auto; margin-right: auto; }
        .card { border: none; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-bottom: 2rem; }
        .card-header { background-color: #005670; color: white; font-weight: 700; border-radius: 8px 8px 0 0; }
        .print-btn { background-color: #005670; border: none; }
        .print-btn:hover { background-color: #004050; }
        .modal-content { border-radius: 8px; }
        .modal-header { background-color: #005670; color: white; }
        footer { text-align: center; padding: 1rem; background-color: #ffffff; border-top: 1px solid #e0e0e0; margin-top: 2rem; }
        @media print {
            .navbar, footer, .print-btn, .modal, form, button[data-bs-toggle="modal"] { display: none; }
            .content { margin-top: 0; }
            .card { box-shadow: none; border: 1px solid #e0e0e0; }
            .card-header { background-color: transparent; color: black; border-bottom: 1px solid #e0e0e0; }
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
        <h1 class="display-5 mb-4">Cash Receipts</h1>
        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <form class="mb-4" method="GET">
            <div class="row g-3">
                <div class="col-md-4 col-lg-3">
                    <label for="date" class="form-label">Select Date</label>
                    <input type="date" class="form-control" id="date" name="date" value="<?php echo htmlspecialchars($date); ?>" onchange="this.form.submit()">
                </div>
                <div class="col-md-4 col-lg-3">
                    <label for="staff_id" class="form-label">Staff</label>
                    <select class="form-select" id="staff_id" name="staff_id" onchange="this.form.submit()">
                        <option value="">All Staff</option>
                        <?php foreach ($staff_list as $staff): ?>
                            <option value="<?php echo $staff['id']; ?>" <?php echo $staff_id == $staff['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($staff['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 col-lg-3">
                    <label for="branch" class="form-label">Branch</label>
                    <select class="form-select" id="branch" name="branch" onchange="this.form.submit()">
                        <option value="">All Branches</option>
                        <?php foreach ($branches as $b): ?>
                            <option value="<?php echo $b; ?>" <?php echo $branch == $b ? 'selected' : ''; ?>><?php echo htmlspecialchars($b); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </form>
        <div class="mb-4">
            <button class="btn btn-primary me-2" data-bs-toggle="modal" data-bs-target="#addStaffModal">Add Staff</button>
        </div>
        <div class="card mb-4">
            <div class="card-header">Add Receipt</div>
            <div class="card-body">
                <form method="POST" action="cash_receipts.php">
                    <input type="hidden" name="add_receipt" value="1">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="receipt_date" class="form-label">Receipt Date</label>
                            <input type="date" class="form-control" id="receipt_date" name="receipt_date" value="<?php echo htmlspecialchars($date); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label for="receipt_mode" class="form-label">Receipt Mode</label>
                            <select class="form-select" id="receipt_mode" name="receipt_mode" onchange="toggleReceiptFields()" required>
                                <option value="branch">Branch Receipts</option>
                                <option value="other">Other Receipts</option>
                            </select>
                        </div>
                    </div>
                    <div id="branch_receipt_fields">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="amount_cash" class="form-label">Cash Amount</label>
                                <input type="number" step="0.01" class="form-control" id="amount_cash" name="amount_cash" placeholder="0.00" min="0">
                            </div>
                            <div class="col-md-6">
                                <label for="amount_card" class="form-label">Card Amount</label>
                                <input type="number" step="0.01" class="form-control" id="amount_card" name="amount_card" placeholder="0.00" min="0" oninput="toggleBankField('card', this.value)">
                                <select class="form-select mt-2" id="bank_account_id_card" name="bank_account_id_card" style="display: none;">
                                    <option value="">Select Bank</option>
                                    <?php foreach ($banks as $bank): ?>
                                        <option value="<?php echo $bank['id']; ?>"><?php echo htmlspecialchars($bank['bank_name'] . ' - ' . $bank['account_number']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row g-3 mt-2">
                            <div class="col-md-6">
                                <label for="amount_upi" class="form-label">UPI Amount</label>
                                <input type="number" step="0.01" class="form-control" id="amount_upi" name="amount_upi" placeholder="0.00" min="0" oninput="toggleBankField('upi', this.value)">
                                <select class="form-select mt-2" id="bank_account_id_upi" name="bank_account_id_upi" style="display: none;">
                                    <option value="">Select Bank</option>
                                    <?php foreach ($banks as $bank): ?>
                                        <option value="<?php echo $bank['id']; ?>"><?php echo htmlspecialchars($bank['bank_name'] . ' - ' . $bank['account_number']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="amount_cheque" class="form-label">Cheque Amount</label>
                                <input type="number" step="0.01" class="form-control" id="amount_cheque" name="amount_cheque" placeholder="0.00" min="0" oninput="toggleChequeFields('cheque_fields', this.value, 'cheque_number', 'description_cheque')">
                                <div id="cheque_fields" style="display: none;">
                                    <div class="mt-2">
                                        <label for="cheque_number" class="form-label">Cheque Number</label>
                                        <input type="text" class="form-control" id="cheque_number" name="cheque_number">
                                    </div>
                                    <div class="mt-2">
                                        <label for="description_cheque" class="form-label">Description</label>
                                        <textarea class="form-control" id="description_cheque" name="description"></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="row g-3 mt-2">
                            <div class="col-md-6">
                                <label for="staff_id_branch" class="form-label">Staff</label>
                                <select class="form-select" id="staff_id_branch" name="staff_id" required>
                                    <option value="">Select Staff</option>
                                    <?php foreach ($staff_list as $staff): ?>
                                        <option value="<?php echo $staff['id']; ?>"><?php echo htmlspecialchars($staff['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="branch_select" class="form-label">Branch</label>
                                <select class="form-select" id="branch_select" name="branch" required>
                                    <option value="">Select Branch</option>
                                    <?php foreach ($branches as $b): ?>
                                        <option value="<?php echo $b; ?>"><?php echo htmlspecialchars($b); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div id="other_receipt_fields" style="display: none;">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="department" class="form-label">Department</label>
                                <select class="form-select" id="department" name="department" onchange="toggleNewDepartmentField()">
                                    <option value="">Select Department</option>
                                    <?php foreach ($departments as $d): ?>
                                        <option value="<?php echo htmlspecialchars($d); ?>"><?php echo htmlspecialchars($d); ?></option>
                                    <?php endforeach; ?>
                                    <option value="new">Add new department</option>
                                </select>
                                <div id="new_department_field" style="display: none;" class="mt-2">
                                    <label for="new_department" class="form-label">New Department Name</label>
                                    <input type="text" class="form-control" id="new_department" name="new_department">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label for="receipt_from" class="form-label">Receipt From</label>
                                <select class="form-select" id="receipt_from" name="receipt_from" onchange="toggleNewReceiptFromField()">
                                    <option value="">Select Receipt From</option>
                                    <?php foreach ($receipt_from_list as $rf): ?>
                                        <option value="<?php echo htmlspecialchars($rf); ?>"><?php echo htmlspecialchars($rf); ?></option>
                                    <?php endforeach; ?>
                                    <option value="new">Add new receipt from</option>
                                </select>
                                <div id="new_receipt_from_field" style="display: none;" class="mt-2">
                                    <label for="new_receipt_from" class="form-label">New Receipt From</label>
                                    <input type="text" class="form-control" id="new_receipt_from" name="new_receipt_from">
                                </div>
                            </div>
                        </div>
                        <div class="row g-3 mt-2">
                            <div class="col-md-6">
                                <label for="payment_mode" class="form-label">Payment Mode</label>
                                <select class="form-select" id="payment_mode" name="payment_mode" onchange="toggleOtherPaymentFields()" required>
                                    <option value="Cash">Cash</option>
                                    <option value="Bank">Bank</option>
                                    <option value="Cheque">Cheque</option>
                                </select>
                                <select class="form-select mt-2" id="bank_account_id" name="bank_account_id" style="display: none;">
                                    <option value="">Select Bank</option>
                                    <?php foreach ($banks as $bank): ?>
                                        <option value="<?php echo $bank['id']; ?>"><?php echo htmlspecialchars($bank['bank_name'] . ' - ' . $bank['account_number']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="amount" class="form-label">Amount</label>
                                <input type="number" step="0.01" class="form-control" id="amount" name="amount" placeholder="0.00" min="0" required>
                            </div>
                        </div>
                         <div class="row g-3 mt-2">
                              <div class="col-md-12">
                                   <div id="cheque_fields_other" style="display: none;">
                                       <div class="mb-3">
                                           <label for="cheque_number_other" class="form-label">Cheque Number</label>
                                           <input type="text" class="form-control" id="cheque_number_other" name="cheque_number_other">
                                       </div>
                                       <div class="mb-3">
                                           <label for="description_other" class="form-label">Description</label>
                                           <textarea class="form-control" id="description_other" name="description_other"></textarea>
                                       </div>
                                  </div>
                              </div>
                          </div>
                    </div>
                    <button type="submit" class="btn btn-primary mt-3">Save Receipt</button>
                </form>
            </div>
        </div>
        <div class="card">
            <div class="card-header">Branch Receipts</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
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
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($branch_receipts)): ?>
                                <tr><td colspan="11" class="text-center text-muted">No branch receipts found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($branch_receipts as $r): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($r['id']); ?></td>
                                        <td><?php echo htmlspecialchars($r['date']); ?></td>
                                        <td><?php echo htmlspecialchars($r['staff_name'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($r['branch'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($r['payment_method']); ?></td>
                                        <td><?php echo $r['bank_name'] ? htmlspecialchars($r['bank_name'] . ' - ' . $r['account_number']) : '-'; ?></td>
                                        <td><?php echo $r['cheque_number'] ? htmlspecialchars($r['cheque_number']) : '-'; ?></td>
                                        <td><?php echo $r['description'] ? htmlspecialchars($r['description']) : '-'; ?></td>
                                        <td>₹<?php echo number_format($r['amount'], 2); ?></td>
                                        <td><?php echo htmlspecialchars($r['username']); ?></td>
                                        <td>
                                            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#editReceiptModal<?php echo $r['id']; ?>">Edit</button>
                                        </td>
                                    </tr>
                                    <div class="modal fade" id="editReceiptModal<?php echo $r['id']; ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Edit Branch Receipt #<?php echo $r['id']; ?></h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <form method="POST">
                                                    <div class="modal-body">
                                                        <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                                                        <input type="hidden" name="edit_receipt" value="1">
                                                        <div class="mb-3">
                                                            <label for="receipt_date_<?php echo $r['id']; ?>" class="form-label">Receipt Date</label>
                                                            <input type="date" class="form-control" id="receipt_date_<?php echo $r['id']; ?>" name="receipt_date" value="<?php echo htmlspecialchars($r['date']); ?>" required>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label for="payment_method_<?php echo $r['id']; ?>" class="form-label">Payment Method</label>
                                                            <select class="form-select" id="payment_method_<?php echo $r['id']; ?>" name="payment_method" onchange="toggleEditPaymentFields(<?php echo $r['id']; ?>)" required>
                                                                <option value="Cash" <?php echo $r['payment_method'] === 'Cash' ? 'selected' : ''; ?>>Cash</option>
                                                                <option value="Card" <?php echo $r['payment_method'] === 'Card' ? 'selected' : ''; ?>>Card</option>
                                                                <option value="UPI" <?php echo $r['payment_method'] === 'UPI' ? 'selected' : ''; ?>>UPI</option>
                                                                <option value="Cheque" <?php echo $r['payment_method'] === 'Cheque' ? 'selected' : ''; ?>>Cheque</option>
                                                            </select>
                                                        </div>
                                                        <div class="mb-3" id="bank_field_<?php echo $r['id']; ?>" style="display: <?php echo in_array($r['payment_method'], ['Card', 'UPI', 'Bank']) ? 'block' : 'none'; ?>;">
                                                            <label for="bank_account_id_<?php echo $r['id']; ?>" class="form-label">Bank Account</label>
                                                            <select class="form-select" id="bank_account_id_<?php echo $r['id']; ?>" name="bank_account_id">
                                                                <option value="">Select Bank</option>
                                                                <?php foreach ($banks as $bank): ?>
                                                                    <option value="<?php echo $bank['id']; ?>" <?php echo $r['bank_account_id'] == $bank['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($bank['bank_name'] . ' - ' . $bank['account_number']); ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        <div class="mb-3" id="cheque_fields_<?php echo $r['id']; ?>" style="display: <?php echo ($r['payment_method'] === 'Cheque') ? 'block' : 'none'; ?>;">
                                                            <label for="cheque_number_<?php echo $r['id']; ?>" class="form-label">Cheque Number</label>
                                                            <input type="text" class="form-control" id="cheque_number_<?php echo $r['id']; ?>" name="cheque_number" value="<?php echo htmlspecialchars($r['cheque_number'] ?? ''); ?>">
                                                            <label for="description_<?php echo $r['id']; ?>" class="form-label mt-2">Description</label>
                                                            <textarea class="form-control" id="description_<?php echo $r['id']; ?>" name="description"><?php echo htmlspecialchars($r['description'] ?? ''); ?></textarea>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label for="amount_<?php echo $r['id']; ?>" class="form-label">Amount</label>
                                                            <input type="number" step="0.01" class="form-control" id="amount_<?php echo $r['id']; ?>" name="amount" value="<?php echo $r['amount']; ?>" required min="0">
                                                        </div>
                                                        <div class="mb-3">
                                                            <label for="staff_id_<?php echo $r['id']; ?>" class="form-label">Staff</label>
                                                            <select class="form-select" id="staff_id_<?php echo $r['id']; ?>" name="staff_id" required>
                                                                <option value="">Select Staff</option>
                                                                <?php foreach ($staff_list as $staff): ?>
                                                                    <option value="<?php echo $staff['id']; ?>" <?php echo $r['staff_id'] == $staff['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($staff['name']); ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label for="branch_<?php echo $r['id']; ?>" class="form-label">Branch</label>
                                                            <select class="form-select" id="branch_<?php echo $r['id']; ?>" name="branch" required>
                                                                <option value="">Select Branch</option>
                                                                <?php foreach ($branches as $b): ?>
                                                                    <option value="<?php echo $b; ?>" <?php echo $r['branch'] === $b ? 'selected' : ''; ?>><?php echo htmlspecialchars($b); ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="submit" class="btn btn-primary">Save</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr><th colspan="8">Total Cash:</th><th>₹<?php echo number_format($branch_total_cash, 2); ?></th><th colspan="2"></th></tr>
                            <tr><th colspan="8">Total Card:</th><th>₹<?php echo number_format($branch_total_card, 2); ?></th><th colspan="2"></th></tr>
                            <tr><th colspan="8">Total UPI:</th><th>₹<?php echo number_format($branch_total_upi, 2); ?></th><th colspan="2"></th></tr>
                            <tr><th colspan="8">Total Cheque:</th><th>₹<?php echo number_format($branch_total_cheque, 2); ?></th><th colspan="2"></th></tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-header">Other Receipts</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Date</th>
                                <th>Department</th>
                                <th>Receipt From</th>
                                <th>Payment Method</th>
                                <th>Bank</th>
                                <th>Cheque Number</th>
                                <th>Description</th>
                                <th>Amount (₹)</th>
                                <th>User</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($other_receipts)): ?>
                                <tr><td colspan="11" class="text-center text-muted">No other receipts found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($other_receipts as $r): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($r['id']); ?></td>
                                        <td><?php echo htmlspecialchars($r['date']); ?></td>
                                        <td><?php echo htmlspecialchars($r['department'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($r['receipt_from'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($r['payment_method']); ?></td>
                                        <td><?php echo $r['bank_name'] ? htmlspecialchars($r['bank_name'] . ' - ' . $r['account_number']) : '-'; ?></td>
                                        <td><?php echo $r['cheque_number'] ? htmlspecialchars($r['cheque_number']) : '-'; ?></td>
                                        <td><?php echo $r['description'] ? htmlspecialchars($r['description']) : '-'; ?></td>
                                        <td>₹<?php echo number_format($r['amount'], 2); ?></td>
                                        <td><?php echo htmlspecialchars($r['username']); ?></td>
                                        <td>
                                            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#editOtherReceiptModal<?php echo $r['id']; ?>">Edit</button>
                                        </td>
                                    </tr>
                                    <div class="modal fade" id="editOtherReceiptModal<?php echo $r['id']; ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Edit Other Receipt #<?php echo $r['id']; ?></h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <form method="POST">
                                                    <div class="modal-body">
                                                        <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                                                        <input type="hidden" name="edit_other_receipt" value="1">
                                                        <div class="mb-3">
                                                            <label for="receipt_date_other_<?php echo $r['id']; ?>" class="form-label">Receipt Date</label>
                                                            <input type="date" class="form-control" id="receipt_date_other_<?php echo $r['id']; ?>" name="receipt_date" value="<?php echo htmlspecialchars($r['date']); ?>" required>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label for="department_<?php echo $r['id']; ?>" class="form-label">Department</label>
                                                            <select class="form-select" id="department_<?php echo $r['id']; ?>" name="department" onchange="toggleEditNewDepartmentField(<?php echo $r['id']; ?>)">
                                                                <option value="">Select Department</option>
                                                                <?php foreach ($departments as $d): ?>
                                                                    <option value="<?php echo htmlspecialchars($d); ?>" <?php echo $r['department'] === $d ? 'selected' : ''; ?>><?php echo htmlspecialchars($d); ?></option>
                                                                <?php endforeach; ?>
                                                                <option value="new">Add new department</option>
                                                            </select>
                                                            <div id="new_department_field_<?php echo $r['id']; ?>" style="display: none;" class="mt-2">
                                                                <label for="new_department_<?php echo $r['id']; ?>" class="form-label">New Department Name</label>
                                                                <input type="text" class="form-control" id="new_department_<?php echo $r['id']; ?>" name="new_department">
                                                            </div>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label for="receipt_from_<?php echo $r['id']; ?>" class="form-label">Receipt From</label>
                                                            <select class="form-select" id="receipt_from_<?php echo $r['id']; ?>" name="receipt_from" onchange="toggleEditNewReceiptFromField(<?php echo $r['id']; ?>)">
                                                                <option value="">Select Receipt From</option>
                                                                <?php foreach ($receipt_from_list as $rf): ?>
                                                                    <option value="<?php echo htmlspecialchars($rf); ?>" <?php echo $r['receipt_from'] === $rf ? 'selected' : ''; ?>><?php echo htmlspecialchars($rf); ?></option>
                                                                <?php endforeach; ?>
                                                                <option value="new">Add new receipt from</option>
                                                            </select>
                                                            <div id="new_receipt_from_field_<?php echo $r['id']; ?>" style="display: none;" class="mt-2">
                                                                <label for="new_receipt_from_<?php echo $r['id']; ?>" class="form-label">New Receipt From</label>
                                                                <input type="text" class="form-control" id="new_receipt_from_<?php echo $r['id']; ?>" name="new_receipt_from">
                                                            </div>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label for="payment_method_other_<?php echo $r['id']; ?>" class="form-label">Payment Method</label>
                                                            <select class="form-select" id="payment_method_other_<?php echo $r['id']; ?>" name="payment_method" onchange="toggleEditPaymentFields('other_<?php echo $r['id']?>', 'other')" required>
                                                                <option value="Cash" <?php echo $r['payment_method'] === 'Cash' ? 'selected' : ''; ?>>Cash</option>
                                                                <option value="Bank" <?php echo $r['payment_method'] === 'Bank' ? 'selected' : ''; ?>>Bank</option>
                                                                <option value="Cheque" <?php echo $r['payment_method'] === 'Cheque' ? 'selected' : ''; ?>>Cheque</option>
                                                            </select>
                                                        </div>
                                                        <div class="mb-3" id="bank_field_other_<?php echo $r['id']; ?>" style="display: <?php echo ($r['payment_method'] === 'Bank') ? 'block' : 'none'; ?>;">
                                                            <label for="bank_account_id_other_<?php echo $r['id']; ?>" class="form-label">Bank Account</label>
                                                            <select class="form-select" id="bank_account_id_other_<?php echo $r['id']; ?>" name="bank_account_id">
                                                                <option value="">Select Bank</option>
                                                                <?php foreach ($banks as $bank): ?>
                                                                    <option value="<?php echo $bank['id']; ?>" <?php echo $r['bank_account_id'] == $bank['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($bank['bank_name'] . ' - ' . $bank['account_number']); ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        <div class="mb-3" id="cheque_fields_other_<?php echo $r['id']; ?>" style="display: <?php echo ($r['payment_method'] === 'Cheque') ? 'block' : 'none'; ?>;">
                                                            <label for="cheque_number_other_<?php echo $r['id']; ?>" class="form-label">Cheque Number</label>
                                                            <input type="text" class="form-control" id="cheque_number_other_<?php echo $r['id']; ?>" name="cheque_number" value="<?php echo htmlspecialchars($r['cheque_number'] ?? ''); ?>">
                                                            <label for="description_other_<?php echo $r['id']; ?>" class="form-label mt-2">Description</label>
                                                            <textarea class="form-control" id="description_other_<?php echo $r['id']; ?>" name="description"><?php echo htmlspecialchars($r['description'] ?? ''); ?></textarea>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label for="amount_other_<?php echo $r['id']; ?>" class="form-label">Amount</label>
                                                            <input type="number" step="0.01" class="form-control" id="amount_other_<?php echo $r['id']; ?>" name="amount" value="<?php echo $r['amount']; ?>" required min="0">
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="submit" class="btn btn-primary">Save</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr><th colspan="8">Total Cash:</th><th>₹<?php echo number_format($other_total_cash, 2); ?></th><th colspan="2"></th></tr>
                            <tr><th colspan="8">Total Bank:</th><th>₹<?php echo number_format($other_total_bank, 2); ?></th><th colspan="2"></th></tr>
                            <tr><th colspan="8">Total Cheque:</th><th>₹<?php echo number_format($other_total_cheque, 2); ?></th><th colspan="2"></th></tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
        <div class="modal fade" id="addStaffModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Add Staff</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST">
                        <div class="modal-body">
                            <input type="hidden" name="add_staff" value="1">
                            <div class="mb-3">
                                <label for="staff_name" class="form-label">Staff Name</label>
                                <input type="text" class="form-control" id="staff_name" name="staff_name" required>
                            </div>
                            <div class="mb-3">
                                <label for="staff_branch" class="form-label">Branch</label>
                                <select class="form-select" id="staff_branch" name="staff_branch" onchange="toggleNewBranchField()" required>
                                    <option value="">Select Branch</option>
                                    <?php foreach ($branches as $b): ?>
                                        <option value="<?php echo htmlspecialchars($b); ?>"><?php echo htmlspecialchars($b); ?></option>
                                    <?php endforeach; ?>
                                    <option value="new">New Branch</option>
                                </select>
                                <div id="new_branch_field" style="display: none;" class="mt-2">
                                    <label for="new_branch" class="form-label">New Branch Name</label>
                                    <input type="text" class="form-control" id="new_branch" name="new_branch">
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="submit" class="btn btn-primary">Save</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <footer>
        <p>© <?php echo date('Y'); ?> SL Diagnostics. All Rights Reserved.</p>
    </footer>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleBankField(method, value) {
            const bankSelect = document.getElementById(`bank_account_id_${method}`);
            if (bankSelect) {
                const isVisible = parseFloat(value) > 0;
                bankSelect.style.display = isVisible ? 'block' : 'none';
                bankSelect.required = isVisible;
            }
        }

        function toggleChequeFields(containerId, value, numberId, descId) {
            const chequeFields = document.getElementById(containerId);
            if (chequeFields) {
                const isVisible = parseFloat(value) > 0;
                chequeFields.style.display = isVisible ? 'block' : 'none';
                const chequeNumber = document.getElementById(numberId);
                if (chequeNumber) chequeNumber.required = isVisible;
            }
        }
        
        function toggleEditPaymentFields(id, type = 'branch') {
            const prefix = type === 'other' ? 'other_' : '';
            const method = document.getElementById(`payment_method_${prefix}${id}`).value;
            const bankField = document.getElementById(`bank_field_${prefix}${id}`);
            const chequeFields = document.getElementById(`cheque_fields_${prefix}${id}`);
            const bankSelect = document.getElementById(`bank_account_id_${prefix}${id}`);
            const chequeNumber = document.getElementById(`cheque_number_${prefix}${id}`);

            const bankMethods = type === 'other' ? ['Bank'] : ['Card', 'UPI'];

            if (bankField) {
                const showBank = bankMethods.includes(method);
                bankField.style.display = showBank ? 'block' : 'none';
                if (bankSelect) bankSelect.required = showBank;
            }

            if (chequeFields) {
                const showCheque = method === 'Cheque';
                chequeFields.style.display = showCheque ? 'block' : 'none';
                if (chequeNumber) chequeNumber.required = showCheque;
            }
        }

        function toggleEditNewDepartmentField(id) {
            const departmentSelect = document.getElementById(`department_${id}`);
            const newDepartmentField = document.getElementById(`new_department_field_${id}`);
            const newDepartmentInput = document.getElementById(`new_department_${id}`);
            if (departmentSelect && newDepartmentField && newDepartmentInput) {
                const isNew = departmentSelect.value === 'new';
                newDepartmentField.style.display = isNew ? 'block' : 'none';
                newDepartmentInput.required = isNew;
            }
        }

        function toggleEditNewReceiptFromField(id) {
            const receiptFromSelect = document.getElementById(`receipt_from_${id}`);
            const newReceiptFromField = document.getElementById(`new_receipt_from_field_${id}`);
            const newReceiptFromInput = document.getElementById(`new_receipt_from_${id}`);
            if (receiptFromSelect && newReceiptFromField && newReceiptFromInput) {
                const isNew = receiptFromSelect.value === 'new';
                newReceiptFromField.style.display = isNew ? 'block' : 'none';
                newReceiptFromInput.required = isNew;
            }
        }

        function toggleNewBranchField() {
            const branchSelect = document.getElementById('staff_branch');
            const newBranchField = document.getElementById('new_branch_field');
            const newBranchInput = document.getElementById('new_branch');
            if (branchSelect && newBranchField && newBranchInput) {
                const isNew = branchSelect.value === 'new';
                newBranchField.style.display = isNew ? 'block' : 'none';
                newBranchInput.required = isNew;
            }
        }

        function toggleReceiptFields() {
            const receiptMode = document.getElementById('receipt_mode').value;
            const branchFields = document.getElementById('branch_receipt_fields');
            const otherFields = document.getElementById('other_receipt_fields');

            const isBranch = receiptMode === 'branch';
            branchFields.style.display = isBranch ? 'block' : 'none';
            otherFields.style.display = !isBranch ? 'block' : 'none';

            // Set required attributes based on visible form
            document.getElementById('staff_id_branch').required = isBranch;
            document.getElementById('branch_select').required = isBranch;

            document.getElementById('amount').required = !isBranch;
            document.getElementById('payment_mode').required = !isBranch;
            
            // At least one amount is needed for branch, this is handled in PHP
        }

        function toggleNewDepartmentField() {
            const departmentSelect = document.getElementById('department');
            const newDepartmentField = document.getElementById('new_department_field');
            const newDepartmentInput = document.getElementById('new_department');
            if (departmentSelect && newDepartmentField && newDepartmentInput) {
                const isNew = departmentSelect.value === 'new';
                newDepartmentField.style.display = isNew ? 'block' : 'none';
                newDepartmentInput.required = isNew;
            }
        }

        function toggleNewReceiptFromField() {
            const receiptFromSelect = document.getElementById('receipt_from');
            const newReceiptFromField = document.getElementById('new_receipt_from_field');
            const newReceiptFromInput = document.getElementById('new_receipt_from');
            if (receiptFromSelect && newReceiptFromField && newReceiptFromInput) {
                const isNew = receiptFromSelect.value === 'new';
                newReceiptFromField.style.display = isNew ? 'block' : 'none';
                newReceiptFromInput.required = isNew;
            }
        }
        
        function toggleOtherPaymentFields() {
            const paymentMode = document.getElementById('payment_mode').value;
            const bankAccount = document.getElementById('bank_account_id');
            const chequeFields = document.getElementById('cheque_fields_other');
            const chequeNumberInput = document.getElementById('cheque_number_other');

            const showBank = paymentMode === 'Bank';
            const showCheque = paymentMode === 'Cheque';

            bankAccount.style.display = showBank ? 'block' : 'none';
            bankAccount.required = showBank;

            chequeFields.style.display = showCheque ? 'block' : 'none';
            chequeNumberInput.required = showCheque;
        }

        window.onload = function() {
            toggleReceiptFields();
            toggleNewDepartmentField();
            toggleNewReceiptFromField();
            toggleOtherPaymentFields();

            document.querySelectorAll('select[id^="payment_method_"]').forEach(select => {
                const idWithPrefix = select.id.replace('payment_method_', '');
                const parts = idWithPrefix.split('_');
                if (parts[0] === 'other') {
                    toggleEditPaymentFields(parts[1], 'other');
                } else {
                    toggleEditPaymentFields(idWithPrefix, 'branch');
                }
            });

            document.querySelectorAll('select[id^="department_"]').forEach(select => {
                const id = select.id.replace('department_', '');
                if (!isNaN(id)) toggleEditNewDepartmentField(id);
            });

            document.querySelectorAll('select[id^="receipt_from_"]').forEach(select => {
                const id = select.id.replace('receipt_from_', '');
                if (!isNaN(id)) toggleEditNewReceiptFromField(id);
            });
        };
    </script>
</body>
</html>
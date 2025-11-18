<?php
session_start();
require_once 'includes/db_connect.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$stmt = $pdo->prepare("SELECT bd.*, ba.bank_name, ba.account_number, u.username FROM bank_deposits bd JOIN bank_accounts ba ON bd.bank_account_id = ba.id JOIN users u ON bd.user_id = u.id WHERE bd.date = ?");
$stmt->execute([$date]);
$deposits = $stmt->fetchAll();

$banks = $pdo->query("SELECT id, bank_name, account_number FROM bank_accounts")->fetchAll();

// MODIFIED: Fetch undeposited cheques from both branch and other receipts
$undeposited_cheques_query = "
    (SELECT id, cheque_number, amount, 'cash_receipts' as source_table FROM cash_receipts WHERE payment_method = 'Cheque' AND is_deposited = FALSE AND cheque_number IS NOT NULL)
    UNION ALL
    (SELECT id, cheque_number, amount, 'other_receipts' as source_table FROM other_receipts WHERE payment_method = 'Cheque' AND is_deposited = FALSE AND cheque_number IS NOT NULL)
";
$undeposited_cheques = $pdo->query($undeposited_cheques_query)->fetchAll(PDO::FETCH_ASSOC);


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['add_bank'])) {
            $bank_name = $_POST['bank_name'];
            $account_number = $_POST['account_number'];
            $stmt = $pdo->prepare("INSERT INTO bank_accounts (bank_name, account_number) VALUES (?, ?)");
            $stmt->execute([$bank_name, $account_number]);
            header('Location: bank_deposits.php?date=' . $date);
            exit;
        } elseif (isset($_POST['add_deposit'])) {
            $bank_account_id = $_POST['bank_account_id'];
            $amount = $_POST['amount'];
            $deposit_type = $_POST['deposit_type'];
            
            // MODIFIED: Initialize cheque variables
            $cheque_identifier = ($deposit_type == 'Cheque') ? $_POST['cheque_id'] : null;
            $from_account_id = ($deposit_type == 'Online Transaction') ? $_POST['from_account_id'] : null;
            $cheque_number = null;
            $cheque_receipt_id = null;
            $cheque_source_table = null;

            if ($deposit_type == 'Cheque' && empty($cheque_identifier)) {
                throw new Exception("Cheque selection is required for Cheque deposit.");
            }
            if ($deposit_type == 'Online Transaction' && empty($from_account_id)) {
                throw new Exception("Source account selection is required for Online Transaction.");
            }
            if ($deposit_type == 'Online Transaction' && $from_account_id == $bank_account_id) {
                throw new Exception("Source and destination accounts cannot be the same.");
            }

            $pdo->beginTransaction();
            if ($deposit_type == 'Cheque') {
                // MODIFIED: Parse identifier and set table name
                list($cheque_source_table, $cheque_receipt_id) = explode('-', $cheque_identifier);
                if (!in_array($cheque_source_table, ['cash_receipts', 'other_receipts'])) {
                    throw new Exception("Invalid cheque source specified.");
                }

                $stmt = $pdo->prepare("SELECT amount, cheque_number FROM {$cheque_source_table} WHERE id = ? AND is_deposited = FALSE");
                $stmt->execute([$cheque_receipt_id]);
                $cheque = $stmt->fetch();
                if (!$cheque) {
                    throw new Exception("Invalid or already deposited cheque selected.");
                }
                $amount = $cheque['amount'];
                $cheque_number = $cheque['cheque_number'];

                $stmt = $pdo->prepare("UPDATE {$cheque_source_table} SET is_deposited = TRUE WHERE id = ?");
                $stmt->execute([$cheque_receipt_id]);

            } elseif ($deposit_type == 'Online Transaction') {
                $stmt = $pdo->prepare("SELECT balance FROM bank_accounts WHERE id = ?");
                $stmt->execute([$from_account_id]);
                $from_account = $stmt->fetch();
                if ($from_account['balance'] < $amount) {
                    throw new Exception("Insufficient balance in source account.");
                }
                $stmt = $pdo->prepare("UPDATE bank_accounts SET balance = balance - ? WHERE id = ?");
                $stmt->execute([$amount, $from_account_id]);
            } 
            
            // MODIFIED: Insert with new cheque tracking fields
            $stmt = $pdo->prepare("INSERT INTO bank_deposits (date, bank_account_id, amount, user_id, deposit_type, cheque_number, from_account_id, cheque_receipt_id, cheque_source_table) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$date, $bank_account_id, $amount, $_SESSION['user_id'], $deposit_type, $cheque_number, $from_account_id, $cheque_receipt_id, $cheque_source_table]);

            if ($deposit_type == 'Cash') {
                $stmt = $pdo->prepare("INSERT INTO cash_on_hand (date, amount) VALUES (?, ?) ON DUPLICATE KEY UPDATE amount = amount - ?");
                $stmt->execute([$date, -$amount, $amount]);
            }
            
            $stmt = $pdo->prepare("UPDATE bank_accounts SET balance = balance + ? WHERE id = ?");
            $stmt->execute([$amount, $bank_account_id]);

            $pdo->commit();
            header('Location: bank_deposits.php?date=' . $date);
            exit;
        } elseif (isset($_POST['edit_deposit'])) {
            $id = $_POST['id'];
            $bank_account_id = $_POST['bank_account_id'];
            $amount = $_POST['amount'];
            $deposit_type = $_POST['deposit_type'];

            // MODIFIED: Initialize cheque variables
            $cheque_identifier = ($deposit_type == 'Cheque') ? $_POST['cheque_id'] : null;
            $from_account_id = ($deposit_type == 'Online Transaction') ? $_POST['from_account_id'] : null;
            $cheque_number = null;
            $cheque_receipt_id = null;
            $cheque_source_table = null;


            if ($deposit_type == 'Cheque' && empty($cheque_identifier)) {
                throw new Exception("Cheque selection is required for Cheque deposit.");
            }
            if ($deposit_type == 'Online Transaction' && empty($from_account_id)) {
                throw new Exception("Source account selection is required for Online Transaction.");
            }
            if ($deposit_type == 'Online Transaction' && $from_account_id == $bank_account_id) {
                throw new Exception("Source and destination accounts cannot be the same.");
            }
            
            // MODIFIED: Select new fields from bank_deposits
            $stmt = $pdo->prepare("SELECT amount, bank_account_id, deposit_type, from_account_id, cheque_receipt_id, cheque_source_table FROM bank_deposits WHERE id = ?");
            $stmt->execute([$id]);
            $old_deposit = $stmt->fetch();

            $pdo->beginTransaction();
            
            // Revert old transaction
            if ($old_deposit['deposit_type'] == 'Cash') {
                $stmt = $pdo->prepare("UPDATE cash_on_hand SET amount = amount + ? WHERE date = ?");
                $stmt->execute([$old_deposit['amount'], $date]);
            } elseif ($old_deposit['deposit_type'] == 'Cheque' && !empty($old_deposit['cheque_source_table']) && !empty($old_deposit['cheque_receipt_id'])) {
                // MODIFIED: Revert using source table and id
                $old_table = $old_deposit['cheque_source_table'];
                if (in_array($old_table, ['cash_receipts', 'other_receipts'])) {
                    $stmt = $pdo->prepare("UPDATE {$old_table} SET is_deposited = FALSE WHERE id = ?");
                    $stmt->execute([$old_deposit['cheque_receipt_id']]);
                }
            } elseif ($old_deposit['deposit_type'] == 'Online Transaction' && !empty($old_deposit['from_account_id'])) {
                $stmt = $pdo->prepare("UPDATE bank_accounts SET balance = balance + ? WHERE id = ?");
                $stmt->execute([$old_deposit['amount'], $old_deposit['from_account_id']]);
            }

            $stmt = $pdo->prepare("UPDATE bank_accounts SET balance = balance - ? WHERE id = ?");
            $stmt->execute([$old_deposit['amount'], $old_deposit['bank_account_id']]);

            // Apply new transaction
            if ($deposit_type == 'Cheque') {
                 // MODIFIED: Parse identifier and set table name
                list($cheque_source_table, $cheque_receipt_id) = explode('-', $cheque_identifier);
                if (!in_array($cheque_source_table, ['cash_receipts', 'other_receipts'])) {
                    throw new Exception("Invalid cheque source specified.");
                }

                $stmt = $pdo->prepare("SELECT amount, cheque_number FROM {$cheque_source_table} WHERE id = ? AND is_deposited = FALSE");
                $stmt->execute([$cheque_receipt_id]);
                $cheque = $stmt->fetch();
                if (!$cheque) {
                    throw new Exception("Invalid or already deposited cheque selected.");
                }
                $amount = $cheque['amount'];
                $cheque_number = $cheque['cheque_number'];
                $stmt = $pdo->prepare("UPDATE {$cheque_source_table} SET is_deposited = TRUE WHERE id = ?");
                $stmt->execute([$cheque_receipt_id]);
            } elseif ($deposit_type == 'Online Transaction') {
                $stmt = $pdo->prepare("SELECT balance FROM bank_accounts WHERE id = ?");
                $stmt->execute([$from_account_id]);
                $from_account = $stmt->fetch();
                if ($from_account['balance'] < $amount) {
                    throw new Exception("Insufficient balance in source account.");
                }
                $stmt = $pdo->prepare("UPDATE bank_accounts SET balance = balance - ? WHERE id = ?");
                $stmt->execute([$amount, $from_account_id]);
            }

            // MODIFIED: Update with new cheque tracking fields
            $stmt = $pdo->prepare("UPDATE bank_deposits SET bank_account_id = ?, amount = ?, user_id = ?, deposit_type = ?, cheque_number = ?, from_account_id = ?, cheque_receipt_id = ?, cheque_source_table = ? WHERE id = ?");
            $stmt->execute([$bank_account_id, $amount, $_SESSION['user_id'], $deposit_type, $cheque_number, $from_account_id, $cheque_receipt_id, $cheque_source_table, $id]);

            if ($deposit_type == 'Cash') {
                $stmt = $pdo->prepare("INSERT INTO cash_on_hand (date, amount) VALUES (?, ?) ON DUPLICATE KEY UPDATE amount = amount - ?");
                $stmt->execute([$date, -$amount, $amount]);
            }
            
            $stmt = $pdo->prepare("UPDATE bank_accounts SET balance = balance + ? WHERE id = ?");
            $stmt->execute([$amount, $bank_account_id]);

            $pdo->commit();
            header('Location: bank_deposits.php?date=' . $date);
            exit;
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SL Diagnostics - Bank Deposits</title>
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
        .modal-content {
            border-radius: 8px;
        }
        .modal-header {
            background-color: #005670;
            color: white;
        }
        footer {
            text-align: center;
            padding: 1rem;
            background-color: #ffffff;
            border-top: 1px solid #e0e0e0;
            margin-top: 2rem;
        }
        .header-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
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
        <div class="header-container">
            <h1 class="display-5">Bank Deposits</h1>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addBankModal">Add Bank Account</button>
        </div>
        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <form class="mb-4">
            <div class="row g-3">
                <div class="col-md-4 col-lg-3">
                    <label for="date" class="form-label">Select Date</label>
                    <input type="date" class="form-control" id="date" name="date" value="<?php echo htmlspecialchars($date); ?>" onchange="this.form.submit()">
                </div>
            </div>
        </form>
        <div class="mb-4">
            <button class="btn btn-primary me-2" data-bs-toggle="modal" data-bs-target="#addDepositModal">Add Deposit</button>
        </div>
        <div class="card">
            <div class="card-header">Deposits</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Date</th>
                                <th>Bank</th>
                                <th>Deposit Type</th>
                                <th>Cheque No</th>
                                <th>From Account</th>
                                <th>Amount (₹)</th>
                                <th>User</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($deposits)): ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted">No deposits found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($deposits as $d): ?>
                                    <tr>
                                        <td><?php echo $d['id']; ?></td>
                                        <td><?php echo htmlspecialchars($d['date']); ?></td>
                                        <td><?php echo htmlspecialchars($d['bank_name'] . ' - ' . $d['account_number']); ?></td>
                                        <td><?php echo htmlspecialchars($d['deposit_type'] ?? 'Cash'); ?></td>
                                        <td><?php echo $d['cheque_number'] ? htmlspecialchars($d['cheque_number']) : '-'; ?></td>
                                        <td><?php 
                                            if ($d['deposit_type'] == 'Online Transaction' && !empty($d['from_account_id'])) {
                                                $stmt = $pdo->prepare("SELECT bank_name, account_number FROM bank_accounts WHERE id = ?");
                                                $stmt->execute([$d['from_account_id']]);
                                                $from_account = $stmt->fetch();
                                                echo $from_account ? htmlspecialchars($from_account['bank_name'] . ' - ' . $from_account['account_number']) : '-';
                                            } else {
                                                echo '-';
                                            }
                                        ?></td>
                                        <td>₹<?php echo number_format($d['amount'], 2); ?></td>
                                        <td><?php echo htmlspecialchars($d['username']); ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#editDepositModal<?php echo $d['id']; ?>">Edit</button>
                                        </td>
                                    </tr>
                                    <div class="modal fade" id="editDepositModal<?php echo $d['id']; ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Edit Deposit #<?php echo $d['id']; ?></h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <form method="POST">
                                                    <div class="modal-body">
                                                        <input type="hidden" name="edit_deposit" value="1">
                                                        <input type="hidden" name="id" value="<?php echo $d['id']; ?>">
                                                        <div class="mb-3">
                                                            <label for="deposit_type_<?php echo $d['id']; ?>" class="form-label">Deposit Type</label>
                                                            <select class="form-select" id="deposit_type_<?php echo $d['id']; ?>" name="deposit_type" onchange="toggleChequeField(<?php echo $d['id']; ?>)">
                                                                <option value="Cash" <?php echo ($d['deposit_type'] ?? 'Cash') == 'Cash' ? 'selected' : ''; ?>>Cash</option>
                                                                <option value="Cheque" <?php echo ($d['deposit_type'] ?? '') == 'Cheque' ? 'selected' : ''; ?>>Cheque</option>
                                                                <option value="Online Transaction" <?php echo ($d['deposit_type'] ?? '') == 'Online Transaction' ? 'selected' : ''; ?>>Online Transaction</option>
                                                            </select>
                                                        </div>
                                                        <div class="mb-3" id="cheque_field_<?php echo $d['id']; ?>" style="display: <?php echo ($d['deposit_type'] ?? '') == 'Cheque' ? 'block' : 'none'; ?>;">
                                                            <label for="cheque_id_<?php echo $d['id']; ?>" class="form-label">Cheque Number</label>
                                                            <select class="form-select" id="cheque_id_<?php echo $d['id']; ?>" name="cheque_id" <?php echo ($d['deposit_type'] ?? '') == 'Cheque' ? 'required' : ''; ?>>
                                                                <option value="">Select Cheque</option>
                                                                <?php if (($d['deposit_type'] ?? '') == 'Cheque'): ?>
                                                                    <option value="<?php echo $d['cheque_source_table'] . '-' . $d['cheque_receipt_id']; ?>" selected>
                                                                        <?php echo htmlspecialchars($d['cheque_number'] . ' (₹' . number_format($d['amount'], 2) . ')'); ?>
                                                                    </option>
                                                                <?php endif; ?>
                                                                <?php foreach ($undeposited_cheques as $cheque): ?>
                                                                    <option value="<?php echo $cheque['source_table'] . '-' . $cheque['id']; ?>" data-amount="<?php echo $cheque['amount']; ?>"><?php echo htmlspecialchars($cheque['cheque_number'] . ' (' . ($cheque['source_table'] == 'cash_receipts' ? 'Branch' : 'Other') . ' - ₹' . number_format($cheque['amount'], 2) . ')'); ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        <div class="mb-3" id="from_account_field_<?php echo $d['id']; ?>" style="display: <?php echo ($d['deposit_type'] ?? '') == 'Online Transaction' ? 'block' : 'none'; ?>;">
                                                            <label for="from_account_id_<?php echo $d['id']; ?>" class="form-label">From Account</label>
                                                            <select class="form-select" id="from_account_id_<?php echo $d['id']; ?>" name="from_account_id" <?php echo ($d['deposit_type'] ?? '') == 'Online Transaction' ? 'required' : ''; ?>>
                                                                <option value="">Select Source Account</option>
                                                                <?php foreach ($banks as $bank): ?>
                                                                     <option value="<?php echo $bank['id']; ?>" <?php echo (isset($d['from_account_id']) && $d['from_account_id'] == $bank['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($bank['bank_name'] . ' - ' . $bank['account_number']); ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label for="bank_account_id_<?php echo $d['id']; ?>" class="form-label">Bank Account</label>
                                                            <select class="form-select" id="bank_account_id_<?php echo $d['id']; ?>" name="bank_account_id" required>
                                                                <?php foreach ($banks as $bank): ?>
                                                                    <option value="<?php echo $bank['id']; ?>" <?php echo $d['bank_account_id'] == $bank['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($bank['bank_name'] . ' - ' . $bank['account_number']); ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        <div class="mb-3" id="amount_field_<?php echo $d['id']; ?>">
                                                            <label for="amount_<?php echo $d['id']; ?>" class="form-label">Amount</label>
                                                            <input type="number" step="0.01" class="form-control" id="amount_<?php echo $d['id']; ?>" name="amount" value="<?php echo $d['amount']; ?>" <?php echo ($d['deposit_type'] ?? '') == 'Cheque' ? 'readonly' : 'required'; ?>>
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
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="addDepositModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Deposit</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                         <input type="hidden" name="add_deposit" value="1">
                        <div class="mb-3">
                            <label for="deposit_type" class="form-label">Deposit Type</label>
                            <select class="form-select" id="deposit_type" name="deposit_type" onchange="toggleChequeField()">
                                <option value="Cash">Cash</option>
                                <option value="Cheque">Cheque</option>
                                <option value="Online Transaction">Online Transaction</option>
                            </select>
                        </div>
                        <div class="mb-3" id="cheque_field" style="display: none;">
                            <label for="cheque_id" class="form-label">Cheque Number</label>
                            <select class="form-select" id="cheque_id" name="cheque_id">
                                <option value="">Select Cheque</option>
                                <?php foreach ($undeposited_cheques as $cheque): ?>
                                    <option value="<?php echo $cheque['source_table'] . '-' . $cheque['id']; ?>" data-amount="<?php echo $cheque['amount']; ?>"><?php echo htmlspecialchars($cheque['cheque_number'] . ' (' . ($cheque['source_table'] == 'cash_receipts' ? 'Branch' : 'Other') . ' - ₹' . number_format($cheque['amount'], 2) . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3" id="from_account_field" style="display: none;">
                            <label for="from_account_id" class="form-label">From Account</label>
                            <select class="form-select" id="from_account_id" name="from_account_id">
                                <option value="">Select Source Account</option>
                                <?php foreach ($banks as $bank): ?>
                                    <option value="<?php echo $bank['id']; ?>"><?php echo htmlspecialchars($bank['bank_name'] . ' - ' . $bank['account_number']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="bank_account_id" class="form-label">Bank Account (To)</label>
                            <select class="form-select" id="bank_account_id" name="bank_account_id" required>
                                <option value="">Select Bank</option>
                                <?php foreach ($banks as $bank): ?>
                                    <option value="<?php echo $bank['id']; ?>"><?php echo htmlspecialchars($bank['bank_name'] . ' - ' . $bank['account_number']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3" id="amount_field">
                            <label for="amount" class="form-label">Amount</label>
                            <input type="number" step="0.01" class="form-control" id="amount" name="amount" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-primary">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <div class="modal fade" id="addBankModal" tabindex="-1">
       </div>


    <footer>
        <p class="mb-0">© <?php echo date('Y'); ?> SL Diagnostics. All rights reserved.</p>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleChequeField(id = '') {
            const suffix = id ? '_' + id : '';
            const depositType = document.getElementById('deposit_type' + suffix).value;
            const chequeField = document.getElementById('cheque_field' + suffix);
            const fromAccountField = document.getElementById('from_account_field' + suffix);
            const amountInput = document.getElementById('amount' + suffix);
            const chequeSelect = document.getElementById('cheque_id' + suffix);
            const fromAccountSelect = document.getElementById('from_account_id' + suffix);
            
            chequeField.style.display = depositType === 'Cheque' ? 'block' : 'none';
            fromAccountField.style.display = depositType === 'Online Transaction' ? 'block' : 'none';
            
            chequeSelect.required = depositType === 'Cheque';
            fromAccountSelect.required = depositType === 'Online Transaction';
            
            amountInput.readOnly = depositType === 'Cheque';
            amountInput.required = depositType !== 'Cheque';
            
            if (depositType !== 'Cheque') {
                if(document.activeElement !== amountInput) {
                   amountInput.value = '';
                }
            } else {
                 updateAmountFromCheque(suffix);
            }
        }

        function updateAmountFromCheque(suffix) {
            const chequeSelect = document.getElementById('cheque_id' + suffix);
            const amountInput = document.getElementById('amount' + suffix);
            const selectedOption = chequeSelect.options[chequeSelect.selectedIndex];
            
            if (chequeSelect.value && selectedOption.dataset.amount) {
                amountInput.value = selectedOption.dataset.amount;
            } else {
                amountInput.value = '';
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Listener for Add form
            const addChequeSelect = document.getElementById('cheque_id');
            if (addChequeSelect) {
                addChequeSelect.addEventListener('change', () => updateAmountFromCheque(''));
            }

            // Listeners for Edit forms
            <?php foreach ($deposits as $d): ?>
            const editChequeSelect_<?php echo $d['id']; ?> = document.getElementById('cheque_id_<?php echo $d['id']; ?>');
            if (editChequeSelect_<?php echo $d['id']; ?>) {
                editChequeSelect_<?php echo $d['id']; ?>.addEventListener('change', () => updateAmountFromCheque('_<?php echo $d['id']; ?>'));
            }
            <?php endforeach; ?>
        });
    </script>
</body>
</html>
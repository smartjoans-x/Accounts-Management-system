<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

try {
    require_once 'includes/db_connect.php';
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Fetch existing headings and sub-headings for the dropdowns
try {
    $stmt = $pdo->prepare("SELECT DISTINCT heading FROM bank_payments WHERE heading IS NOT NULL ORDER BY heading");
    $stmt->execute();
    $headings = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $stmt = $pdo->prepare("SELECT DISTINCT sub_heading FROM bank_payments WHERE sub_heading IS NOT NULL ORDER BY sub_heading");
    $stmt->execute();
    $sub_headings = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log("Error fetching headings/sub-headings: " . $e->getMessage());
    $headings = [];
    $sub_headings = [];
}

// Handle add payment form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_payment'])) {
    try {
        $date = $_POST['date'];
        $description = $_POST['description'];
        $heading = $_POST['heading_select'] === 'new' ? $_POST['new_heading'] : $_POST['heading_select'];
        $sub_heading = $_POST['sub_heading_select'] === 'new' ? $_POST['new_sub_heading'] : $_POST['sub_heading_select'];
        $amount = floatval($_POST['amount']);
        $bank_account_id = $_POST['bank_account_id'];
        $payment_mode = $_POST['payment_mode'];
        $cheque_no = $payment_mode === 'Cheque' ? $_POST['cheque_no'] : null;
        $user_id = $_SESSION['user_id'];

        // Start transaction
        $pdo->beginTransaction();

        // Insert payment record
        $stmt = $pdo->prepare("INSERT INTO bank_payments (date, description, heading, sub_heading, amount, bank_account_id, payment_mode, cheque_no, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$date, $description, $heading, $sub_heading, $amount, $bank_account_id, $payment_mode, $cheque_no, $user_id]);

        // Update bank account balance
        $stmt = $pdo->prepare("UPDATE bank_accounts SET balance = balance - ? WHERE id = ?");
        $stmt->execute([$amount, $bank_account_id]);

        $pdo->commit();
        $success_message = "Payment added successfully!";
        
        // Refresh headings and sub-headings lists after adding new payment
        $stmt = $pdo->prepare("SELECT DISTINCT heading FROM bank_payments WHERE heading IS NOT NULL ORDER BY heading");
        $stmt->execute();
        $headings = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $stmt = $pdo->prepare("SELECT DISTINCT sub_heading FROM bank_payments WHERE sub_heading IS NOT NULL ORDER BY sub_heading");
        $stmt->execute();
        $sub_headings = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Error adding payment: " . $e->getMessage());
        $error_message = "Failed to add payment. Please try again.";
    }
}

// Handle edit payment form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_payment'])) {
    try {
        $payment_id = $_POST['payment_id'];
        $date = $_POST['date'];
        $description = $_POST['description'];
        $heading = $_POST['heading_select'] === 'new' ? $_POST['new_heading'] : $_POST['heading_select'];
        $sub_heading = $_POST['sub_heading_select'] === 'new' ? $_POST['new_sub_heading'] : $_POST['sub_heading_select'];
        $new_amount = floatval($_POST['amount']);
        $new_bank_account_id = $_POST['bank_account_id'];
        $payment_mode = $_POST['payment_mode'];
        $cheque_no = $payment_mode === 'Cheque' ? $_POST['cheque_no'] : null;

        // Fetch original payment details
        $stmt = $pdo->prepare("SELECT amount, bank_account_id FROM bank_payments WHERE id = ?");
        $stmt->execute([$payment_id]);
        $original_payment = $stmt->fetch(PDO::FETCH_ASSOC);
        $original_amount = $original_payment['amount'];
        $original_bank_account_id = $original_payment['bank_account_id'];

        // Start transaction
        $pdo->beginTransaction();

        // Update payment record
        $stmt = $pdo->prepare("UPDATE bank_payments SET date = ?, description = ?, heading = ?, sub_heading = ?, amount = ?, bank_account_id = ?, payment_mode = ?, cheque_no = ? WHERE id = ?");
        $stmt->execute([$date, $description, $heading, $sub_heading, $new_amount, $new_bank_account_id, $payment_mode, $cheque_no, $payment_id]);

        // Reverse original amount from original bank account
        $stmt = $pdo->prepare("UPDATE bank_accounts SET balance = balance + ? WHERE id = ?");
        $stmt->execute([$original_amount, $original_bank_account_id]);

        // Deduct new amount from new bank account
        $stmt = $pdo->prepare("UPDATE bank_accounts SET balance = balance - ? WHERE id = ?");
        $stmt->execute([$new_amount, $new_bank_account_id]);

        $pdo->commit();
        $success_message = "Payment updated successfully!";
        
        // Refresh headings and sub-headings lists after editing payment
        $stmt = $pdo->prepare("SELECT DISTINCT heading FROM bank_payments WHERE heading IS NOT NULL ORDER BY heading");
        $stmt->execute();
        $headings = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $stmt = $pdo->prepare("SELECT DISTINCT sub_heading FROM bank_payments WHERE sub_heading IS NOT NULL ORDER BY sub_heading");
        $stmt->execute();
        $sub_headings = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        $pdo->rollBack();
        error_log("Error updating payment: " . $e->getMessage());
        $error_message = "Failed to update payment. Please try again.";
    }
}

// Fetch bank accounts for dropdown
try {
    $stmt = $pdo->query("SELECT id, bank_name, account_number, balance FROM bank_accounts");
    $bank_accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching bank accounts: " . $e->getMessage());
    $bank_accounts = [];
}

// Fetch payment records
try {
    $date = isset($_GET['date']) && !empty($_GET['date']) ? $_GET['date'] : date('Y-m-d');
    $stmt = $pdo->prepare("
        SELECT bp.id, bp.date, bp.description, bp.heading, bp.sub_heading, bp.amount, bp.bank_account_id, bp.payment_mode, bp.cheque_no, ba.account_number, u.username 
        FROM bank_payments bp 
        JOIN bank_accounts ba ON bp.bank_account_id = ba.id 
        JOIN users u ON bp.user_id = u.id
        WHERE bp.date = ?
        ORDER BY bp.date DESC
    ");
    $stmt->execute([$date]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching payments: " . $e->getMessage());
    $payments = [];
    $error_message = "Failed to load payments. Please try again.";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SL Diagnostics - Bank Payments</title>
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
        footer {
            text-align: center;
            padding: 1rem;
            background-color: #ffffff;
            border-top: 1px solid #e0e0e0;
            margin-top: 2rem;
        }
        #new_heading_container, #new_sub_heading_container, #cheque_no_container, .edit_cheque_no_container {
            display: none;
        }
        @media print {
            body * {
                display: none !important;
            }
            .payment-table,
            .payment-table * {
                display: block !important;
            }
            .payment-table {
                margin: 0 !important;
                padding: 0 !important;
            }
            .payment-table .card {
                box-shadow: none !important;
                border: 1px solid #ddd !important;
            }
            .payment-table .card-header {
                background-color: transparent !important;
                color: black !important;
                border-bottom: 1px solid #ddd !important;
            }
            .payment-table .card-body {
                padding: 0 !important;
            }
            .payment-table .table {
                width: 100% !important;
                border-collapse: collapse !important;
            }
            .payment-table th,
            .payment-table td {
                border: 1px solid #ddd !important;
                padding: 8px !important;
            }
            .payment-table .actions {
                display: none !important;
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
        <h1 class="display-5 mb-4">Bank Payments</h1>

        <?php if (isset($success_message)): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
        <?php elseif (isset($error_message)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>

      
        <!-- Add Payment Form -->
        <div class="card mb-4">
            <div class="card-header">Add New Payment</div>
            <div class="card-body">
                <form method="POST">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label for="date" class="form-label">Date</label>
                            <input type="date" class="form-control" id="date" name="date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label for="heading_select" class="form-label">Heading</label>
                            <select class="form-control" id="heading_select" name="heading_select" onchange="toggleNewHeading(this, 'heading')" required>
                                <option value="" disabled selected>Select a heading</option>
                                <?php foreach ($headings as $h): ?>
                                    <option value="<?php echo htmlspecialchars($h); ?>"><?php echo htmlspecialchars($h); ?></option>
                                <?php endforeach; ?>
                                <option value="new">Add New Heading</option>
                            </select>
                            <input type="text" class="form-control mt-2" id="new_heading" name="new_heading" placeholder="Enter new heading" style="display: none;">
                        </div>
                        <div class="col-md-3">
                            <label for="sub_heading_select" class="form-label">Sub Heading</label>
                            <select class="form-control" id="sub_heading_select" name="sub_heading_select" onchange="toggleNewHeading(this, 'sub_heading')">
                                <option value="" disabled selected>Select a sub heading</option>
                                <?php foreach ($sub_headings as $sh): ?>
                                    <option value="<?php echo htmlspecialchars($sh); ?>"><?php echo htmlspecialchars($sh); ?></option>
                                <?php endforeach; ?>
                                <option value="new">Add New Sub Heading</option>
                            </select>
                            <input type="text" class="form-control mt-2" id="new_sub_heading" name="new_sub_heading" placeholder="Enter new sub heading" style="display: none;">
                        </div>
                        <div class="col-md-3">
                            <label for="description" class="form-label">Description</label>
                            <input type="text" class="form-control" id="description" name="description" required>
                        </div>
                        <div class="col-md-3">
                            <label for="amount" class="form-label">Amount (₹)</label>
                            <input type="number" class="form-control" id="amount" name="amount" step="0.01" min="0" required>
                        </div>
                        <div class="col-md-3">
                            <label for="bank_account_id" class="form-label">Bank Account</label>
                            <select class="form-control" id="bank_account_id" name="bank_account_id" required>
                                <option value="">Select Account</option>
                                <?php foreach ($bank_accounts as $account): ?>
                                    <option value="<?php echo $account['id']; ?>">
                                        <?php echo htmlspecialchars($account['bank_name'] . ' - ' . $account['account_number']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="payment_mode" class="form-label">Mode of Payment</label>
                            <select class="form-control" id="payment_mode" name="payment_mode" onchange="toggleChequeNo(this)" required>
                                <option value="" disabled selected>Select payment mode</option>
                                <option value="Online Transaction">Online Transaction</option>
                                <option value="Card">Card</option>
                                <option value="Cheque">Cheque</option>
                            </select>
                        </div>
                        <div class="col-md-3" id="cheque_no_container">
                            <label for="cheque_no" class="form-label">Cheque Number</label>
                            <input type="text" class="form-control" id="cheque_no" name="cheque_no" placeholder="Enter cheque number">
                        </div>
                    </div>
                    <button type="submit" name="add_payment" class="btn btn-primary mt-3">Add Payment</button>
                </form>
            </div>
        </div>

        <!-- Payments Table -->
        <div class="card payment-table">
		
            <div class="card-header"><!-- Date Filter Form -->
        <form class="mb-4">
            <div class="row g-3">
                <div class="col-md-4 col-lg-3">
                    <label for="date" class="form-label">Select Date</label>
                    <input type="date" class="form-control" id="date" name="date" value="<?php echo htmlspecialchars($date); ?>" onchange="this.form.submit()">
                </div>
            </div>
        </form>

			Payment Records</div>
			  
			
            <div class="card-body">
                <?php if (empty($payments)): ?>
                    <p class="text-muted">No payment records found.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Date</th>
                                    <th>Heading</th>
                                    <th>Sub Heading</th>
                                    <th>Description</th>
                                    <th>Amount (₹)</th>
                                    <th>Bank Account</th>
                                    <th>Payment Mode</th>
                                    <th>Cheque No</th>
                                    <th>User</th>
                                    <th class="actions">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payments as $payment): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($payment['id']); ?></td>
                                        <td><?php echo htmlspecialchars($payment['date']); ?></td>
                                        <td><?php echo htmlspecialchars($payment['heading']); ?></td>
                                        <td><?php echo htmlspecialchars($payment['sub_heading'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($payment['description']); ?></td>
                                        <td>₹<?php echo number_format($payment['amount'], 2); ?></td>
                                        <td><?php echo htmlspecialchars($payment['account_number']); ?></td>
                                        <td><?php echo htmlspecialchars($payment['payment_mode'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($payment['cheque_no'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($payment['username']); ?></td>
                                        <td class="actions">
                                            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editPaymentModal<?php echo $payment['id']; ?>">Edit</button>
                                        </td>
                                    </tr>
                                    <!-- Edit Payment Modal -->
                                    <div class="modal fade" id="editPaymentModal<?php echo $payment['id']; ?>" tabindex="-1" aria-labelledby="editPaymentModalLabel<?php echo $payment['id']; ?>" aria-hidden="true">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title" id="editPaymentModalLabel<?php echo $payment['id']; ?>">Edit Payment #<?php echo $payment['id']; ?></h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <form method="POST">
                                                        <input type="hidden" name="payment_id" value="<?php echo $payment['id']; ?>">
                                                        <div class="mb-3">
                                                            <label for="edit_date_<?php echo $payment['id']; ?>" class="form-label">Date</label>
                                                            <input type="date" class="form-control" id="edit_date_<?php echo $payment['id']; ?>" name="date" value="<?php echo htmlspecialchars($payment['date']); ?>" required>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label for="edit_heading_select_<?php echo $payment['id']; ?>" class="form-label">Heading</label>
                                                            <select class="form-control" id="edit_heading_select_<?php echo $payment['id']; ?>" name="heading_select" onchange="toggleNewHeading(this, 'heading', '<?php echo $payment['id']; ?>')" required>
                                                                <option value="" disabled>Select a heading</option>
                                                                <?php foreach ($headings as $h): ?>
                                                                    <option value="<?php echo htmlspecialchars($h); ?>" <?php echo $h === $payment['heading'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($h); ?></option>
                                                                <?php endforeach; ?>
                                                                <option value="new">Add New Heading</option>
                                                            </select>
                                                            <input type="text" class="form-control mt-2" id="edit_new_heading_<?php echo $payment['id']; ?>" name="new_heading" placeholder="Enter new heading" style="display: none;">
                                                        </div>
                                                        <div class="mb-3">
                                                            <label for="edit_sub_heading_select_<?php echo $payment['id']; ?>" class="form-label">Sub Heading</label>
                                                            <select class="form-control" id="edit_sub_heading_select_<?php echo $payment['id']; ?>" name="sub_heading_select" onchange="toggleNewHeading(this, 'sub_heading', '<?php echo $payment['id']; ?>')">
                                                                <option value="" <?php echo empty($payment['sub_heading']) ? 'selected' : ''; ?>>Select a sub heading</option>
                                                                <?php foreach ($sub_headings as $sh): ?>
                                                                    <option value="<?php echo htmlspecialchars($sh); ?>" <?php echo $sh === $payment['sub_heading'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($sh); ?></option>
                                                                <?php endforeach; ?>
                                                                <option value="new">Add New Sub Heading</option>
                                                            </select>
                                                            <input type="text" class="form-control mt-2" id="edit_new_sub_heading_<?php echo $payment['id']; ?>" name="new_sub_heading" placeholder="Enter new sub heading" style="display: none;">
                                                        </div>
                                                        <div class="mb-3">
                                                            <label for="edit_description_<?php echo $payment['id']; ?>" class="form-label">Description</label>
                                                            <input type="text" class="form-control" id="edit_description_<?php echo $payment['id']; ?>" name="description" value="<?php echo htmlspecialchars($payment['description']); ?>" required>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label for="edit_amount_<?php echo $payment['id']; ?>" class="form-label">Amount (₹)</label>
                                                            <input type="number" class="form-control" id="edit_amount_<?php echo $payment['id']; ?>" name="amount" step="0.01" min="0" value="<?php echo htmlspecialchars($payment['amount']); ?>" required>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label for="edit_bank_account_id_<?php echo $payment['id']; ?>" class="form-label">Bank Account</label>
                                                            <select class="form-control" id="edit_bank_account_id_<?php echo $payment['id']; ?>" name="bank_account_id" required>
                                                                <option value="">Select Account</option>
                                                                <?php foreach ($bank_accounts as $account): ?>
                                                                    <option value="<?php echo $account['id']; ?>" <?php echo $account['id'] == $payment['bank_account_id'] ? 'selected' : ''; ?>>
                                                                        <?php echo htmlspecialchars($account['bank_name'] . ' - ' . $account['account_number']); ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label for="edit_payment_mode_<?php echo $payment['id']; ?>" class="form-label">Mode of Payment</label>
                                                            <select class="form-control" id="edit_payment_mode_<?php echo $payment['id']; ?>" name="payment_mode" onchange="toggleEditChequeNo(this, '<?php echo $payment['id']; ?>')" required>
                                                                <option value="" disabled>Select payment mode</option>
                                                                <option value="Online Transaction" <?php echo $payment['payment_mode'] === 'Online Transaction' ? 'selected' : ''; ?>>Online Transaction</option>
                                                                <option value="Card" <?php echo $payment['payment_mode'] === 'Card' ? 'selected' : ''; ?>>Card</option>
                                                                <option value="Cheque" <?php echo $payment['payment_mode'] === 'Cheque' ? 'selected' : ''; ?>>Cheque</option>
                                                            </select>
                                                        </div>
                                                        <div class="mb-3 edit_cheque_no_container" id="edit_cheque_no_container_<?php echo $payment['id']; ?>">
                                                            <label for="edit_cheque_no_<?php echo $payment['id']; ?>" class="form-label">Cheque Number</label>
                                                            <input type="text" class="form-control" id="edit_cheque_no_<?php echo $payment['id']; ?>" name="cheque_no" value="<?php echo htmlspecialchars($payment['cheque_no'] ?? ''); ?>" placeholder="Enter cheque number">
                                                        </div>
                                                        <button type="submit" name="edit_payment" class="btn btn-primary">Save Changes</button>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <button class="btn btn-primary mt-3" onclick="window.print()">Print Payment Records</button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <footer>
        <p class="mb-0">© <?php echo date('Y'); ?> SL Diagnostics. All rights reserved.</p>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleNewHeading(select, type, paymentId = '') {
            const prefix = paymentId ? 'edit_' : '';
            const newHeadingInput = document.getElementById(prefix + 'new_' + type);
            newHeadingInput.style.display = select.value === 'new' ? 'block' : 'none';
            newHeadingInput.required = select.value === 'new';
        }

        function toggleChequeNo(select) {
            const chequeNoContainer = document.getElementById('cheque_no_container');
            const chequeNoInput = document.getElementById('cheque_no');
            chequeNoContainer.style.display = select.value === 'Cheque' ? 'block' : 'none';
            chequeNoInput.required = select.value === 'Cheque';
        }

        function toggleEditChequeNo(select, paymentId) {
            const chequeNoContainer = document.getElementById('edit_cheque_no_container_' + paymentId);
            const chequeNoInput = document.getElementById('edit_cheque_no_' + paymentId);
            chequeNoContainer.style.display = select.value === 'Cheque' ? 'block' : 'none';
            chequeNoInput.required = select.value === 'Cheque';
        }

        // Initialize cheque number visibility for edit modals
        document.addEventListener('DOMContentLoaded', function() {
            <?php foreach ($payments as $payment): ?>
                const editPaymentMode<?php echo $payment['id']; ?> = document.getElementById('edit_payment_mode_<?php echo $payment['id']; ?>');
                toggleEditChequeNo(editPaymentMode<?php echo $payment['id']; ?>, '<?php echo $payment['id']; ?>');
            <?php endforeach; ?>
        });
    </script>
</body>
</html>
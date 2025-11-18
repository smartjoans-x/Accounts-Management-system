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

// Initialize variables
$upload_message = '';
$errors = [];
$selected_year = isset($_GET['year']) ? $_GET['year'] : date('Y');

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_file'])) {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $month = $_POST['month'] ?? '';
    $year = $_POST['year'] ?? '';
    $uploaded_by = $_SESSION['user_id'];

    // Validate inputs
    if (empty($name)) {
        $errors[] = "File name is required.";
    }
    if (empty($year) || !is_numeric($year) || strlen($year) != 4) {
        $errors[] = "Valid year is required.";
    }
    if (!empty($month) && !in_array($month, ['01','02','03','04','05','06','07','08','09','10','11','12'])) {
        $errors[] = "Invalid month selected.";
    }
    if (!isset($_FILES['file']) || $_FILES['file']['error'] == UPLOAD_ERR_NO_FILE) {
        $errors[] = "No file was uploaded.";
    } else {
        $file = $_FILES['file'];
        $allowed_types = ['application/pdf', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        $max_size = 15 * 1024 * 1024; // 15MB

        if (!in_array($file['type'], $allowed_types)) {
            $errors[] = "Only PDF or Excel files are allowed.";
        }
        if ($file['size'] > $max_size) {
            $errors[] = "File size must not exceed 15MB.";
        }
        if ($file['error'] != UPLOAD_ERR_OK) {
            $errors[] = "Error uploading file.";
        }
    }

    // If no errors, process the upload
    if (empty($errors)) {
        $file_name = uniqid() . '_' . basename($file['name']);
        $upload_dir = 'uploads/';
        $upload_path = $upload_dir . $file_name;

        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        if (move_uploaded_file($file['tmp_name'], $upload_path)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO file_uploads (name, description, month, year, file_path, uploaded_by, upload_date) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$name, $description, $month ?: null, $year, $upload_path, $uploaded_by]);
                $upload_message = "File uploaded successfully!";
            } catch (PDOException $e) {
                error_log("Error saving file info: " . $e->getMessage());
                $errors[] = "Failed to save file information.";
                unlink($upload_path); // Remove file if DB insert fails
            }
        } else {
            $errors[] = "Failed to move uploaded file.";
        }
    }
}

// Fetch years for filter
try {
    $stmt = $pdo->query("SELECT DISTINCT year FROM file_uploads ORDER BY year DESC");
    $years = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log("Error fetching years: " . $e->getMessage());
    $years = [date('Y')];
}

// Fetch uploaded files
try {
    $stmt = $pdo->prepare("SELECT id, name, description, month, year, file_path, upload_date FROM file_uploads WHERE year = ? ORDER BY upload_date DESC");
    $stmt->execute([$selected_year]);
    $uploads = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching uploads: " . $e->getMessage());
    $uploads = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SL Diagnostics - File Uploads</title>
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
                        <a class="nav-link" href="reports.php">Reports</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="file_uploads.php">File Uploads</a>
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
        <h1 class="display-5 mb-4">File Uploads</h1>

        <?php if ($upload_message): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($upload_message); ?></div>
        <?php endif; ?>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <button class="btn btn-primary mb-4" data-bs-toggle="modal" data-bs-target="#uploadModal">Upload New File</button>

        <form class="mb-4">
            <div class="row g-3">
                <div class="col-md-4 col-lg-3">
                    <label for="year" class="form-label">Select Year</label>
                    <select class="form-select" id="year" name="year" onchange="this.form.submit()">
                        <?php foreach ($years as $y): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y == $selected_year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </form>

        <div class="card">
            <div class="card-header">Uploaded Files</div>
            <div class="card-body">
                <?php if (empty($uploads)): ?>
                    <p class="text-muted">No files uploaded for <?php echo $selected_year; ?>.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Description</th>
                                    <th>Month</th>
                                    <th>Year</th>
                                    <th>Upload Date</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($uploads as $upload): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($upload['name']); ?></td>
                                        <td><?php echo htmlspecialchars($upload['description'] ?: 'N/A'); ?></td>
                                        <td><?php echo $upload['month'] ? date('F', mktime(0, 0, 0, $upload['month'], 10)) : 'N/A'; ?></td>
                                        <td><?php echo $upload['year']; ?></td>
                                        <td><?php echo date('Y-m-d H:i', strtotime($upload['upload_date'])); ?></td>
                                        <td>
                                            <a href="<?php echo htmlspecialchars($upload['file_path']); ?>" class="btn btn-sm btn-primary" download>Download</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Upload Modal -->
    <div class="modal fade" id="uploadModal" tabindex="-1" aria-labelledby="uploadModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="uploadModalLabel">Upload File</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form method="POST" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label for="name" class="form-label">File Name</label>
                            <input type="text" class="form-control" id="name" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="4"></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="month" class="form-label">Month (Optional)</label>
                            <select class="form-select" id="month" name="month">
                                <option value="">Select Month</option>
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?php echo sprintf('%02d', $m); ?>"><?php echo date('F', mktime(0, 0, 0, $m, 10)); ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="year" class="form-label">Year</label>
                            <input type="number" class="form-control" id="year" name="year" min="2000" max="2100" value="<?php echo date('Y'); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="file" class="form-label">Select File (PDF or Excel, Max 15MB)</label>
                            <input type="file" class="form-control" id="file" name="file" accept=".pdf,.xls,.xlsx" required>
                        </div>
                        <button type="submit" name="upload_file" class="btn btn-primary">Upload</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <footer>
        <p class="mb-0">© <?php echo date('Y'); ?> SL Diagnostics. All rights reserved.</p>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
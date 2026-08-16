<?php
/**
 * Staff Management - Admin Panel
 * Manage staff accounts, passwords, and status
 */

require_once __DIR__ . '/../session_check.php';
require_once __DIR__ . '/../db.php';

// Check if user is admin
if (!isAdmin()) {
    header('Location: /LibraryBorrowingSystem/login.php');
    exit();
}

$message = '';
$message_type = '';
$staff_accounts = [];

// Get all staff accounts
try {
    $result = $conn->query("SELECT user_id, full_name, username, status, created_at FROM users WHERE role = 'admin' ORDER BY created_at DESC");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $staff_accounts[] = $row;
        }
    }
} catch (Exception $e) {
    $message = 'Unable to load staff accounts right now.';
    $message_type = 'error';
    logError('Staff list error: ' . $e->getMessage());
}

// Handle add staff account
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try { requireValidCsrf($_POST['csrf_token'] ?? ''); } catch (Throwable $e) { $message = $e->getMessage(); $message_type = 'error'; }
    $action = $_POST['action'];
    if ($message_type === 'error') { $action = ''; }
    
    if ($action === 'add') {
        $full_name = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $confirm_password = (string)($_POST['confirm_password'] ?? '');
        
        // Validate input
        if (empty($full_name) || empty($username) || empty($password)) {
            $message = 'All fields are required.';
            $message_type = 'error';
        } elseif (!preg_match('/^[A-Za-z0-9_.-]{4,50}$/', $username)) {
            $message = 'Username must be 4-50 characters and use only letters, numbers, dot, underscore, or hyphen.';
            $message_type = 'error';
        } elseif (!empty(passwordPolicyErrors($password))) {
            $message = strongPasswordMessage(passwordPolicyErrors($password));
            $message_type = 'error';
        } elseif (!hash_equals($password, $confirm_password)) {
            $message = 'Password confirmation does not match.';
            $message_type = 'error';
        } else {
            try {
                // Check if username already exists
                $check_stmt = $conn->prepare("SELECT user_id FROM users WHERE username = ?");
                $check_stmt->bind_param('s', $username);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                
                if ($check_result->num_rows > 0) {
                    $message = 'Username already exists.';
                    $message_type = 'error';
                } else {
                    // Hash password and insert
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    
                    $insert_stmt = $conn->prepare("INSERT INTO users (full_name, username, password, role, status) VALUES (?, ?, ?, 'admin', 'active')");
                    $insert_stmt->bind_param('sss', $full_name, $username, $hashed_password);
                    
                    if ($insert_stmt->execute()) {
                        $message = 'Staff account created successfully!';
                        $message_type = 'success';
                        
                        // Refresh staff list
                        $staff_accounts = [];
                        $result = $conn->query("SELECT user_id, full_name, username, status, created_at FROM users WHERE role = 'admin' ORDER BY created_at DESC");
                        if ($result) {
                            while ($row = $result->fetch_assoc()) {
                                $staff_accounts[] = $row;
                            }
                        }
                    } else {
                        $message = 'Unable to create the staff account right now.';
                        $message_type = 'error';
                        logError('Staff insert error: ' . $conn->error);
                    }
                    $insert_stmt->close();
                }
                $check_stmt->close();
            } catch (Exception $e) {
                $message = 'Unable to complete the staff account request right now.';
                $message_type = 'error';
                logError('Staff management error: ' . $e->getMessage());
            }
        }
    }
    
    // Handle reset password
    elseif ($action === 'reset_password') {
        $user_id = intval($_POST['user_id']);
        $new_password = (string)($_POST['new_password'] ?? '');
        $confirm_new_password = (string)($_POST['confirm_new_password'] ?? '');
        
        if ($new_password === '' || !empty(passwordPolicyErrors($new_password))) {
            $message = $new_password === '' ? 'Password is required.' : strongPasswordMessage(passwordPolicyErrors($new_password));
            $message_type = 'error';
        } elseif (!hash_equals($new_password, $confirm_new_password)) {
            $message = 'Password confirmation does not match.';
            $message_type = 'error';
        } else {
            try {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                
                $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ? AND role = 'admin'");
                $update_stmt->bind_param('si', $hashed_password, $user_id);
                
                if ($update_stmt->execute() && $update_stmt->affected_rows > 0) {
                    $message = 'Password reset successfully!';
                    $message_type = 'success';
                } else {
                    $message = 'Error updating password.';
                    $message_type = 'error';
                }
                $update_stmt->close();
            } catch (Exception $e) {
                $message = 'Unable to complete the staff account request right now.';
                $message_type = 'error';
                logError('Staff management error: ' . $e->getMessage());
            }
        }
    }
    
    // Handle activate/deactivate
    elseif ($action === 'toggle_status') {
        $user_id = intval($_POST['user_id']);
        $new_status = $_POST['new_status'] === 'active' ? 'active' : 'inactive';
        if ($user_id === (int)($_SESSION['user_id'] ?? 0) && $new_status === 'inactive') {
            $message = 'You cannot deactivate the admin account you are currently using.';
            $message_type = 'error';
        } else try {
            $update_stmt = $conn->prepare("UPDATE users SET status = ? WHERE user_id = ? AND role = 'admin'");
            $update_stmt->bind_param('si', $new_status, $user_id);
            
            if ($update_stmt->execute() && $update_stmt->affected_rows > 0) {
                $status_text = $new_status === 'active' ? 'activated' : 'deactivated';
                $message = 'Staff account ' . $status_text . ' successfully!';
                $message_type = 'success';
                
                // Refresh staff list
                $staff_accounts = [];
                $result = $conn->query("SELECT user_id, full_name, username, status, created_at FROM users WHERE role = 'admin' ORDER BY created_at DESC");
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $staff_accounts[] = $row;
                    }
                }
            } else {
                $message = 'Error updating staff status.';
                $message_type = 'error';
            }
            $update_stmt->close();
        } catch (Exception $e) {
            $message = 'Error: ' . htmlspecialchars($e->getMessage());
            $message_type = 'error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Management - Library Borrowing System</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #F3F7FC;
            color: #202A44;
        }
        
        .container {
            max-width: 1200px;
            margin: 30px auto;
            padding: 0 20px;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        
        .page-header h2 {
            color: #202A44;
            font-size: 28px;
        }
        
        .alert {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-success {
            background-color: #EDF5DD;
            color: #344E15;
            border: 1px solid #B5D27A;
        }
        
        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .section {
            background: white;
            padding: 25px;
            border-radius: 8px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
        }
        
        .section h3 {
            color: #202A44;
            margin-bottom: 20px;
            font-size: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #141F52;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            color: #202A44;
            font-weight: 500;
            font-size: 14px;
        }
        
        input[type="text"],
        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 12px;
            border: 1px solid #D2E2F6;
            border-radius: 5px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        input[type="text"]:focus,
        input[type="email"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: #141F52;
            box-shadow: 0 0 0 3px rgba(244, 249, 22, 0.35);
        }
        
        .button-group {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        button {
            padding: 12px 24px;
            background: #141F52;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        button:hover {
            background: #52618D;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(20, 31, 82, 0.4);
        }
        
        button:active {
            transform: translateY(0);
        }
        
        button[type="reset"] {
            background: #999;
        }
        
        button[type="reset"]:hover {
            box-shadow: 0 5px 15px rgba(150, 150, 150, 0.4);
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }
        
        /* Table styles */
        .table-wrapper {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        thead {
            background: #F7F9FC;
        }
        
        th {
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #202A44;
            border-bottom: 2px solid #D2E2F6;
            font-size: 14px;
        }
        
        td {
            padding: 15px;
            border-bottom: 1px solid #eee;
            font-size: 14px;
        }
        
        tbody tr:hover {
            background: #F7F9FC;
        }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-align: center;
            display: inline-block;
        }
        
        .status-active {
            background: #EDF5DD;
            color: #344E15;
        }
        
        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .btn-small {
            padding: 8px 12px;
            font-size: 12px;
            font-weight: 600;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            transition: transform 0.2s;
        }
        
        .btn-reset {
            background: #141F52;
            color: white;
        }
        
        .btn-reset:hover {
            background: #141F52;
            transform: translateY(-1px);
        }
        
        .btn-deactivate {
            background: #f44336;
            color: white;
        }
        
        .btn-deactivate:hover {
            background: #da190b;
            transform: translateY(-1px);
        }
        
        .btn-activate {
            background: #52618D;
            color: white;
        }
        
        .btn-activate:hover {
            background: #141F52;
            transform: translateY(-1px);
        }
        
        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }
        
        .modal.show {
            display: flex;
            justify-content: center;
            align-items: center;
        }
        
        .modal-content {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
            max-width: 400px;
            width: 90%;
        }
        
        .modal-header {
            margin-bottom: 20px;
        }
        
        .modal-header h3 {
            margin: 0;
            color: #202A44;
        }
        
        .modal-buttons {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
        }
        
        .btn-cancel {
            background: #999;
            padding: 10px 20px;
            font-size: 14px;
            font-weight: 600;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            color: white;
        }
        
        .btn-cancel:hover {
            background: #777;
        }
        
        .btn-submit {
            background: #141F52;
            padding: 10px 20px;
            font-size: 14px;
            font-weight: 600;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            color: white;
        }
        
        .btn-submit:hover {
            transform: translateY(-1px);
            box-shadow: 0 3px 10px rgba(20, 31, 82, 0.3);
        }
        
        .no-data {
            text-align: center;
            padding: 40px;
            color: #999;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../navbar.php'; ?>
    <?php include __DIR__ . '/../header.php'; ?>
    
    <div class="container">
        <div class="page-header">
            <h2>Staff Management</h2>
        </div>
        
        <?php if (!empty($message)): ?>
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    showToast(<?php echo json_encode($message); ?>, <?php echo json_encode($message_type ?: 'info'); ?>, 4200);
                });
            </script>
        <?php endif; ?>
        
        <!-- Add Staff Section -->
        <div class="section">
            <h3>Add New Staff Account</h3>
            <form method="POST">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="add">
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="full_name">Full Name</label>
                        <input type="text" id="full_name" name="full_name" required placeholder="e.g. John Doe">
                    </div>
                    
                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" required placeholder="e.g. john_doe">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" maxlength="128" autocomplete="new-password" required placeholder="10+ chars, upper/lower/number/symbol">
                        <div style="font-size:12px;color:#52618D;margin-top:7px;">Minimum 10 characters with uppercase, lowercase, number, and special character.</div>
                    </div>
                    <div class="form-group">
                        <label for="confirm_password">Confirm Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" maxlength="128" autocomplete="new-password" required placeholder="Enter the same password again">
                    </div>
                </div>
                
                <div class="button-group">
                    <button type="submit">Create Staff Account</button>
                    <button type="reset">Clear</button>
                </div>
            </form>
        </div>
        
        <!-- Staff Accounts List -->
        <div class="section">
            <h3>Staff Accounts (<?php echo count($staff_accounts); ?>)</h3>
            
            <?php if (count($staff_accounts) > 0): ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Full Name</th>
                                <th>Username</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($staff_accounts as $staff): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($staff['full_name']); ?></td>
                                    <td><code><?php echo htmlspecialchars($staff['username']); ?></code></td>
                                    <td>
                                        <span class="status-badge status-<?php echo $staff['status']; ?>">
                                            <?php echo ucfirst($staff['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($staff['created_at'])); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn-small btn-reset" onclick="openResetModal(<?php echo $staff['user_id']; ?>, '<?php echo htmlspecialchars($staff['full_name']); ?>')">
                                                🔑 Reset
                                            </button>
                                            
                                            <?php if ($staff['status'] === 'active'): ?>
                                                <form method="POST" style="display: inline;"
                                                      data-confirm-title="Deactivate Staff Account"
                                                      data-confirm-message="Deactivate this staff account? The account will not be able to sign in until it is activated again."
                                                      data-confirm-text="Deactivate"
                                                      data-confirm-danger="1">
                                                    <?php echo csrfField(); ?>
                                                    <input type="hidden" name="action" value="toggle_status">
                                                    <input type="hidden" name="user_id" value="<?php echo $staff['user_id']; ?>">
                                                    <input type="hidden" name="new_status" value="inactive">
                                                    <button type="submit" class="btn-small btn-deactivate">
                                                        ⏸ Deactivate
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <form method="POST" style="display: inline;"
                                                      data-confirm-title="Activate Staff Account"
                                                      data-confirm-message="Activate this staff account? The account will be able to sign in again."
                                                      data-confirm-text="Activate">
                                                    <?php echo csrfField(); ?>
                                                    <input type="hidden" name="action" value="toggle_status">
                                                    <input type="hidden" name="user_id" value="<?php echo $staff['user_id']; ?>">
                                                    <input type="hidden" name="new_status" value="active">
                                                    <button type="submit" class="btn-small btn-activate">
                                                        ✓ Activate
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="no-data">
                    <p>No staff accounts found.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Reset Password Modal -->
    <div id="resetModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>🔑 Reset Password</h3>
                <p id="staffName" style="color: #666; margin-top: 5px; font-size: 14px;"></p>
            </div>
            
            <form method="POST" id="resetForm">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="user_id" id="resetUserId" value="">
                
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input type="password" id="new_password" name="new_password" maxlength="128" autocomplete="new-password" required placeholder="10+ chars, upper/lower/number/symbol">
                    <div style="font-size:12px;color:#52618D;margin-top:7px;">Minimum 10 characters with uppercase, lowercase, number, and special character.</div>
                </div>
                <div class="form-group">
                    <label for="confirm_new_password">Confirm New Password</label>
                    <input type="password" id="confirm_new_password" name="confirm_new_password" maxlength="128" autocomplete="new-password" required placeholder="Enter the same password again">
                </div>
                
                <div class="modal-buttons">
                    <button type="button" class="btn-cancel" onclick="closeResetModal()">Cancel</button>
                    <button type="submit" class="btn-submit">Reset Password</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function openResetModal(userId, staffName) {
            document.getElementById('resetUserId').value = userId;
            document.getElementById('staffName').textContent = 'Staff: ' + staffName;
            document.getElementById('new_password').value = '';
            document.getElementById('confirm_new_password').value = '';
            document.getElementById('resetModal').classList.add('show');
        }
        
        function closeResetModal() {
            document.getElementById('resetModal').classList.remove('show');
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('resetModal');
            if (event.target == modal) {
                modal.classList.remove('show');
            }
        }
    </script>
</body>
</html>

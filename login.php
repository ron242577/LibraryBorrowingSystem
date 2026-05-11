<?php
/**
 * Login Page - Library Borrowing System
 * Handles user authentication with sessions and role-based redirects
 */

session_start();

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'super_admin') {
        header('Location: /LibraryBorrowingSystem/superadmin/dashboard.php');
    } else {
        header('Location: /LibraryBorrowingSystem/librarian/dashboard.php');
    }
    exit();
}

$error = '';
$success = '';

// Handle logout message
if (isset($_GET['logout'])) {
    $success = 'You have been logged out successfully.';
}

// Handle session expired message
if (isset($_GET['expired'])) {
    $error = 'Your session has expired. Please login again.';
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    
    // Validate input
    if (empty($username) || empty($password)) {
        $error = 'Username and password are required.';
    } else {
        // Include database connection
        require_once __DIR__ . '/db.php';
        
        try {
            // Prepare query to fetch user by username
            $query = 'SELECT user_id, full_name, password, role, status FROM users WHERE username = ? LIMIT 1';
            $stmt = $conn->prepare($query);
            
            if (!$stmt) {
                throw new Exception('Database error: ' . $conn->error);
            }
            
            // Bind parameter
            $stmt->bind_param('s', $username);
            
            // Execute query
            if (!$stmt->execute()) {
                throw new Exception('Query execution failed: ' . $stmt->error);
            }
            
            // Get result
            $result = $stmt->get_result();
            $user = $result->fetch_assoc();
            
            // Verify user exists and password is correct
            if ($user && hash('sha256', $password) === $user['password']) {
                // Check if user account is active
                if ($user['status'] !== 'active') {
                    $error = 'Your account has been deactivated. Please contact the administrator.';
                } else {
                    // Start session and set user data
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['username'] = $username;
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['login_time'] = time();
                    
                    // Redirect based on role
                    if ($user['role'] === 'super_admin') {
                        header('Location: /LibraryBorrowingSystem/superadmin/dashboard.php');
                    } else {
                        header('Location: /LibraryBorrowingSystem/librarian/dashboard.php');
                    }
                    exit();
                }
            } else {
                $error = 'Invalid username or password.';
            }
            
            $stmt->close();
        } catch (Exception $e) {
            $error = 'An error occurred during login. Please try again later.';
            logError('Login error: ' . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Library Borrowing System</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #003366;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        
        .login-container {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
            width: 100%;
            max-width: 400px;
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .login-header h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 28px;
        }
        
        .login-header p {
            color: #666;
            font-size: 14px;
        }
        
        .alert {
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        
        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
            font-size: 14px;
        }
        
        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        input[type="text"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: #8B0000;
            box-shadow: 0 0 5px rgba(139, 0, 0, 0.1);
        }
        
        button {
            width: 100%;
            padding: 12px;
            background: #8B0000;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(139, 0, 0, 0.4);
        }
        
        button:active {
            transform: translateY(0);
        }
        
        .demo-info {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eee;
            font-size: 13px;
            color: #666;
        }
        
        .demo-info h3 {
            color: #333;
            margin-bottom: 10px;
            font-size: 14px;
        }
        
        .demo-info p {
            margin-bottom: 5px;
            font-family: monospace;
        }
        
        .role {
            background: #f5f5f5;
            padding: 8px;
            border-radius: 3px;
            margin-bottom: 5px;
        }
        
        .logo-container {
            text-align: center;
            margin-bottom: 20px;
        }
        
        .logo-container img {
            height: 80px;
            width: auto;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo-container">
            <img src="/LibraryBorrowingSystem/Img/Arellano_University_logo.png" alt="Arellano University Logo">
        </div>
        <div class="login-header">
            <h1>Library Borrowing System</h1>
            <p>Login to manage your library</p>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label for="username">Username</label>
                <input 
                    type="text" 
                    id="username" 
                    name="username" 
                    required 
                    autocomplete="username"
                    value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>"
                >
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <input 
                    type="password" 
                    id="password" 
                    name="password" 
                    required 
                    autocomplete="current-password"
                >
            </div>
            <div class="form-group">
                <a href="student/portal.php" for="password" style="font-size: 13px; color: #8B0000; text-decoration: none;">Login as Student</a>
            </div>
            <button type="submit">Login</button>
        </form>
        
    </div>
</body>
</html>

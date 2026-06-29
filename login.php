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
        :root {
            --cmrhs-navy: #141F52;
            --cmrhs-blue: #52618D;
            --cmrhs-sky: #91B0E0;
            --cmrhs-light-blue: #D2E2F6;
            --cmrhs-yellow: #F4F916;
            --cmrhs-green: #B5D27A;
            --cmrhs-orange: #BB5716;
            --cmrhs-white: #FEFEF9;
            --cmrhs-text: #202A44;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--cmrhs-light-blue);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        
        .login-container {
            background: var(--cmrhs-white);
            padding: 40px;
            border: 1px solid var(--cmrhs-sky);
            border-top: 6px solid var(--cmrhs-navy);
            border-radius: 8px;
            box-shadow: 0 12px 30px rgba(20, 31, 82, 0.18);
            width: 100%;
            max-width: 400px;
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .login-header h1 {
            color: var(--cmrhs-navy);
            margin-bottom: 10px;
            font-size: 28px;
        }
        
        .login-header p {
            color: var(--cmrhs-blue);
            font-size: 14px;
        }
        
        .alert {
            padding: 12px;
            border-radius: 5px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        
        .alert-error {
            background-color: #FBE8DC;
            color: #7A3107;
            border: 1px solid var(--cmrhs-orange);
        }
        
        .alert-success {
            background-color: #EDF5DD;
            color: #344E15;
            border: 1px solid var(--cmrhs-green);
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            color: var(--cmrhs-text);
            font-weight: 500;
            font-size: 14px;
        }
        
        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--cmrhs-sky);
            border-radius: 5px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        input[type="text"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: var(--cmrhs-navy);
            box-shadow: 0 0 0 3px rgba(244, 249, 22, 0.35);
        }
        
        button {
            width: 100%;
            padding: 12px;
            background: var(--cmrhs-navy);
            color: var(--cmrhs-white);
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s, transform 0.2s, box-shadow 0.2s;
        }
        
        button:hover {
            transform: translateY(-2px);
            background: var(--cmrhs-blue);
            box-shadow: 0 5px 15px rgba(20, 31, 82, 0.3);
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
            height: 96px;
            width: auto;
        }

        .student-login-link {
            color: var(--cmrhs-navy);
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
        }

        .student-login-link:hover,
        .student-login-link:focus {
            color: var(--cmrhs-blue);
            text-decoration: underline;
        }

        @media (max-width: 480px) {
            .login-container {
                padding: 30px 24px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo-container">
            <img src="/LibraryBorrowingSystem/Img/Claro_M_Recto_Logo.png" alt="Claro M. Recto High School Logo">
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
                <a href="student/portal.php" class="student-login-link">Login as Student</a>
            </div>
            <button type="submit">Login</button>
        </form>
        
    </div>
</body>
</html>

<?php
/**
 * Student Portal - View Borrowed Books and Penalties
 * Session-less access via QR code scanning
 */

require_once __DIR__ . '/../db.php';

$student = null;
$borrowed_books = [];
$total_penalty = 0;
$qr_error = null;
$qr_validated = false;

// Check if student QR code is provided
$student_qr = isset($_GET['qr']) ? trim($_GET['qr']) : null;

if ($student_qr) {
    try {
        // Get student by QR code
        $student_stmt = $conn->prepare("SELECT student_id, full_name, contact_number, qr_code FROM students WHERE qr_code = ? AND status = 'active'");
        $student_stmt->bind_param('s', $student_qr);
        $student_stmt->execute();
        $student_result = $student_stmt->get_result();
        
        if ($student_result->num_rows > 0) {
            $student = $student_result->fetch_assoc();
            $qr_validated = true;
            
            // Redirect to borrow.php with QR parameter
            $qr_code_param = '?qr=' . urlencode($student_qr);
            header('Location: /LibraryBorrowingSystem/student/profile.php' . $qr_code_param);
            exit();
        } else {
            $qr_error = 'Invalid or inactive QR code. Please check your code and try again or use manual entry.';
        }
        $student_stmt->close();
    } catch (Exception $e) {
        logError('Student portal error: ' . $e->getMessage());
        $qr_error = 'An error occurred while validating your QR code. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Portal - Library Borrowing System</title>
    <style>
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes slideUp {
            from {
                opacity: 1;
                transform: translateY(0);
            }
            to {
                opacity: 0;
                transform: translateY(-10px);
            }
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Oxygen', 'Ubuntu', 'Cantarell', sans-serif;
            background: #141F52;
            color: #202A44;
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            min-height: calc(100vh - 40px);
            display: flex;
            flex-direction: column;
        }
        
        /* Header Section */
        .header {
            text-align: center;
            color: white;
            margin-bottom: 40px;
            padding-top: 20px;
        }
        
        .header h1 {
            font-size: 32px;
            margin-bottom: 8px;
            font-weight: 700;
            letter-spacing: -0.5px;
        }
        
        .header p {
            font-size: 15px;
            opacity: 0.95;
            font-weight: 300;
        }
        
        /* Cards */
        .card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
            margin-bottom: 30px;
            overflow: hidden;
            transition: box-shadow 0.3s ease;
        }
        
        .card:hover {
            box-shadow: 0 12px 32px rgba(0, 0, 0, 0.15);
        }
        
        .card-header {
            background: #141F52;
            color: white;
            padding: 24px 30px;
            font-size: 18px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 4px solid #F4F916;
        }
        
        .card-body {
            padding: 30px;
        }
        
        /* Student Info Grid */
        .student-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 18px;
            margin-bottom: 25px;
        }
        
        .info-item {
            background: #F3F7FC;
            padding: 20px;
            border-radius: 12px;
            border-left: 4px solid #141F52;
            transition: all 0.3s ease;
        }
        
        .info-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }
        
        .info-label {
            font-size: 11px;
            color: #52618D;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 8px;
            font-weight: 600;
        }
        
        .info-value {
            font-size: 18px;
            color: #202A44;
            font-weight: 700;
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 12px;
            margin-top: 25px;
            flex-wrap: wrap;
            justify-content: center;
        }
        
        a, button {
            padding: 11px 26px;
            background: #141F52;
            color: white;
            text-decoration: none;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(20, 31, 82, 0.3);
        }
        
        a:hover, button:hover {
            background: #52618D;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(20, 31, 82, 0.4);
        }
        
        .btn-secondary {
            background: #E7EEF7;
            color: #202A44;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        
        .btn-secondary:hover {
            background: #D2E2F6;
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.15);
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 30px;
        }
        
        .empty-state-icon {
            font-size: 56px;
            margin-bottom: 18px;
            display: block;
            animation: float 3s ease-in-out infinite;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }
        
        .empty-state p {
            color: #52618D;
            font-size: 16px;
            margin-bottom: 20px;
            font-weight: 500;
        }
        
        .empty-state a {
            background: #141F52;
        }
        
        /* Book List */
        .book-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
        }
        
        .book-item {
            background: white;
            border: 1px solid #E7EEF7;
            border-radius: 12px;
            padding: 22px;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }
        
        .book-item:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.12);
            border-color: #52618D;
        }
        
        .book-item-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 16px;
            gap: 12px;
        }
        
        .book-info h3 {
            color: #202A44;
            margin-bottom: 6px;
            font-size: 17px;
            font-weight: 700;
            line-height: 1.3;
        }
        
        .book-info p {
            color: #52618D;
            font-size: 13px;
            margin: 6px 0;
            line-height: 1.5;
        }
        
        .book-info .author {
            color: #141F52;
            font-weight: 600;
            margin-bottom: 10px;
        }
        
        .status-badge {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }
        
        .status-available {
            background: #EDF5DD;
            color: #344E15;
        }
        
        .status-overdue {
            background: #f8d7da;
            color: #721c24;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
        
        .status-borrowed {
            background: #FBFDCB;
            color: #5C5F05;
        }
        
        .book-dates {
            background: #F7F9FC;
            padding: 12px;
            border-radius: 8px;
            margin: 12px 0;
            border-left: 3px solid #141F52;
        }
        
        .date-row {
            display: flex;
            justify-content: space-between;
            font-size: 13px;
            margin: 6px 0;
        }
        
        .date-label {
            color: #52618D;
            font-weight: 600;
        }
        
        .date-value {
            color: #202A44;
            font-weight: 600;
        }
        
        .penalty-info {
            background: #FBE8DC;
            border: 1px solid #E6A370;
            color: #7A3A0E;
            padding: 16px 20px;
            border-radius: 10px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 14px;
            font-weight: 600;
            box-shadow: 0 2px 8px rgba(255, 179, 0, 0.1);
        }
        
        .penalty-info strong {
            font-size: 18px;
            color: #BB5716;
        }
        
        /* QR Display */
        .qr-section {
            display: grid;
            grid-template-columns: 1fr;
            gap: 24px;
            margin-top: 30px;
        }
        
        .qr-display {
            background: #F3F7FC;
            text-align: center;
            padding: 30px;
            border-radius: 12px;
            border: 2px dashed #141F52;
        }
        
        .qr-display h4 {
            color: #202A44;
            margin-bottom: 16px;
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .qr-display img {
            max-width: 180px;
            height: auto;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(20, 31, 82, 0.2);
            background: white;
            padding: 8px;
        }
        
        .qr-code-text {
            margin-top: 14px;
            font-size: 12px;
            color: #141F52;
            font-weight: 600;
            font-family: 'Courier New', monospace;
            letter-spacing: 0.5px;
        }
        
        /* Login Page Container */
        .login-page {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 20px;
        }
        
        .welcome-section {
            text-align: center;
            color: white;
            margin-bottom: 50px;
            animation: fadeInDown 0.6s ease;
        }
        
        @keyframes fadeInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .welcome-icon {
            width: 120px;
            height: 120px;
            margin-bottom: 16px;
            display: inline-block;
            animation: float 3s ease-in-out infinite;
            object-fit: contain;
        }
        
        .welcome-section h1 {
            font-size: 44px;
            font-weight: 700;
            margin-bottom: 12px;
            letter-spacing: -0.5px;
        }
        
        .welcome-section p {
            font-size: 18px;
            opacity: 0.95;
            font-weight: 300;
            margin-bottom: 8px;
        }
        
        .welcome-section .subtitle {
            font-size: 14px;
            opacity: 0.85;
            font-weight: 300;
        }
        
        /* Login Form Container */
        .login-form-container {
            width: 100%;
            max-width: 450px;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
            overflow: hidden;
            animation: fadeInUp 0.8s ease;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .form-header {
            background: #141F52;
            padding: 24px;
            text-align: center;
            border-bottom: 4px solid #F4F916;
        }
        
        .form-header h2 {
            color: white;
            font-size: 22px;
            font-weight: 700;
            margin: 0;
        }
        
        .form-content {
            padding: 40px 30px;
        }
        
        /* Toggle Buttons */
        .input-mode-toggle {
            display: flex;
            gap: 12px;
            margin-bottom: 30px;
            background: #F3F7FC;
            border-radius: 12px;
            padding: 6px;
        }
        
        .toggle-btn {
            flex: 1;
            padding: 12px 20px;
            border: none;
            background: transparent;
            color: #52618D;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            border-radius: 10px;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .toggle-btn.active {
            background: #F4F916;
            color: #141F52;
            box-shadow: 0 4px 15px rgba(20, 31, 82, 0.3);
        }
        
        .toggle-btn:hover:not(.active) {
            color: #202A44;
        }
        
        /* Input Forms */
        .input-form {
            display: none;
            animation: fadeIn 0.3s ease;
        }
        
        .input-form.active {
            display: block;
        }
        
        .manual-entry-form {
            display: block;
            margin-top: 18px;
            padding-top: 18px;
            border-top: 1px solid #e5e7eb;
        }
        
        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }
        
        .form-group-custom {
            margin-bottom: 24px;
        }
        
        .form-group-custom label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: #52618D;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 10px;
        }
        
        .form-group-custom input {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #E7EEF7;
            border-radius: 10px;
            font-size: 15px;
            font-family: inherit;
            color: #202A44;
            background: #F7F9FC;
            transition: all 0.3s ease;
        }
        
        .form-group-custom input::placeholder {
            color: #91B0E0;
        }
        
        .form-group-custom input:focus {
            outline: none;
            border-color: #141F52;
            background: white;
            box-shadow: 0 0 0 3px rgba(244, 249, 22, 0.35);
        }
        
        .helper-text {
            font-size: 12px;
            color: #52618D;
            margin-top: 8px;
            line-height: 1.5;
        }
        
        /* Submit Button */
        .btn-login {
            width: 100%;
            padding: 14px 24px;
            background: #141F52;
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(20, 31, 82, 0.3);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .btn-login:hover {
            background: #52618D;
            transform: translateY(-2px);
            box-shadow: 0 6px 25px rgba(20, 31, 82, 0.4);
        }
        
        .btn-login:active {
            transform: translateY(0);
        }
        
        /* Info Box */
        .info-box {
            background: #F3F7FC;
            border-left: 4px solid #52618D;
            padding: 16px;
            border-radius: 10px;
            margin-top: 24px;
            font-size: 13px;
            color: #4a5568;
            line-height: 1.6;
        }
        
        .info-box strong {
            color: #52618D;
            font-weight: 600;
        }
        
        /* Footer */
        .login-footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 24px;
            border-top: 1px solid #E7EEF7;
            color: #52618D;
            font-size: 13px;
        }
        
        .login-footer a {
            color: #52618D;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s ease;
        }
        
        .login-footer a:hover {
            color: #141F52;
        }
        
        /* Error Banner */
        .qr-error-banner {
            background: #FBE8DC;
            border-left: 4px solid #BB5716;
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 30px;
            color: #7A3A0E;
            display: flex;
            align-items: flex-start;
            gap: 16px;
            box-shadow: 0 4px 12px rgba(211, 84, 0, 0.1);
            animation: slideDown 0.3s ease;
        }
        
        .qr-error-banner strong {
            display: block;
            font-size: 14px;
            font-weight: 700;
            margin-bottom: 4px;
        }
        
        .qr-error-banner p {
            margin: 0;
            font-size: 13px;
            line-height: 1.5;
        }
        
        /* QR Scanner Styles */
        .qr-scanner-container {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        
        #qr-reader {
            width: 100% !important;
            border-radius: 12px !important;
            overflow: hidden;
            margin-bottom: 12px;
        }
        
        #qr-reader-controls {
            display: flex;
            gap: 10px;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .scanner-btn {
            padding: 10px 18px;
            border: 2px solid #52618D;
            background: transparent;
            color: #52618D;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .scanner-btn:hover {
            background: #141F52;
            color: white;
            border-color: transparent;
        }
        
        .scanner-result {
            background: #EDF5DD;
            border-left: 4px solid #567D1F;
            padding: 12px 16px;
            border-radius: 8px;
            color: #344E15;
            font-size: 13px;
            font-weight: 600;
            display: none;
            animation: slideDown 0.3s ease;
        }
        
        .scanner-result.show {
            display: block;
        }
        
        .scanner-error {
            background: #f8d7da;
            border-left: 4px solid #dc3545;
            padding: 12px 16px;
            border-radius: 8px;
            color: #721c24;
            font-size: 13px;
            font-weight: 600;
            display: none;
        }
        
        .scanner-error.show {
            display: block;
        }
        
        /* Responsive */

        @media (max-width: 768px) {
            .welcome-section h1 {
                font-size: 32px;
            }
            
            .welcome-section p {
                font-size: 16px;
            }
            
            .login-form-container {
                max-width: 100%;
            }
            
            .form-content {
                padding: 30px 24px;
            }
            
            .header h1 {
                font-size: 24px;
            }
            
            .card-body {
                padding: 20px;
            }
            
            .student-info {
                grid-template-columns: repeat(2, 1fr);
                gap: 14px;
            }
            
            .book-list {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                justify-content: flex-start;
            }
            
            a, button {
                flex: 1;
                min-width: 140px;
            }
        }
        
        @media (max-width: 480px) {
            .welcome-section h1 {
                font-size: 28px;
            }
            
            .welcome-icon {
                width: 80px;
                height: 80px;
                margin-bottom: 12px;
            }
            
            .form-header h2 {
                font-size: 18px;
            }
            
            .form-content {
                padding: 24px 18px;
            }
            
            .toggle-btn {
                padding: 10px 14px;
                font-size: 12px;
            }
            
            .form-group-custom input {
                padding: 12px 14px;
                font-size: 14px;
            }
            
            .btn-login {
                padding: 12px 20px;
                font-size: 14px;
            }
        }
    </style>
    <!-- Html5Qrcode Library for QR Scanning -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.4/html5-qrcode.min.js"></script>
</head>
<body>
    <div class="container">
        <?php if ($qr_error): ?>
        <div class="qr-error-banner" id="error-banner">
            <span style="font-size: 20px; margin-right: 12px;"></span>
            <div>
                <strong>Entry Failed</strong>
                <p><?php echo htmlspecialchars($qr_error); ?></p>
            </div>
            <button type="button" onclick="dismissError()" style="background: none; border: none; color: inherit; cursor: pointer; font-size: 20px; padding: 0;">&times;</button>
        </div>
        <?php endif; ?>
        
        <?php if (!$student && !$qr_validated): ?>
    <div class="login-page">
        <div class="welcome-section">
            <img src="/LibraryBorrowingSystem/Img/Claro_M_Recto_Logo.png" alt="Claro M. Recto High School Logo" class="welcome-icon">
            <h1>Welcome to the Student Portal</h1>
            <p>Search books and view your profile separately</p>
            <span class="subtitle">Search books from the library catalog</span>
        </div>
        
        <div class="login-form-container">
            <div class="form-header">
                <h2>Access Your Account</h2>
            </div>
            
            <div class="form-content">
                <!-- QR Code Scanner Form -->
                <div id="qr-form" class="input-form active">
                    <div class="form-group-custom">
                        <label>QR Code Scanner</label>
                        <div class="qr-scanner-container">
                            <div id="qr-reader"></div>
                            <div id="qr-result" class="scanner-result">QR code scanned successfully! Processing...</div>
                            <div id="qr-scan-error" class="scanner-error"></div>
                            <div id="qr-reader-controls">
                                <button type="button" class="scanner-btn" id="stop-scan-btn" onclick="stopQrScanner()" style="display:none;">Stop Camera</button>
                            </div>
                        </div>
                        <div class="helper-text">Camera opens automatically. Point it at your QR code to scan.</div>
                    </div>
                    <form method="GET" id="qr-submit-form" style="display:none;">
                        <input type="hidden" id="qr-result-value" name="qr">
                    </form>
                    <form method="GET" id="manual-form" class="manual-entry-form">
                        <div class="form-group-custom">
                            <label for="student-id">Manual Entry</label>
                            <input type="text"
                                   id="student-id"
                                   name="qr"
                                   placeholder="Enter or paste your student QR code"
                                   autocomplete="off"
                                   required>
                            <div class="helper-text">Type or paste your QR code here, then press Enter or click Access Account.</div>
                        </div>
                        <button type="submit" class="btn-login">Access Account</button>
                    </form>
                    
                    <!-- Registration and Staff Login Links -->
                    <div style="margin-top: 30px; padding-top: 24px; border-top: 1px solid #E7EEF7; display: flex; flex-direction: column; gap: 12px;">
                        <a href="/LibraryBorrowingSystem/student/register.php" style="width: 100%; padding: 12px 24px; background: #141F52; color: white; text-decoration: none; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 4px 15px rgba(20, 31, 82, 0.3); text-align: center; text-transform: uppercase; letter-spacing: 0.5px;">Register for Student</a>
                        <a href="/LibraryBorrowingSystem/login.php" style="width: 100%; padding: 12px 24px; background: #E7EEF7; color: #202A44; text-decoration: none; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1); text-align: center; text-transform: uppercase; letter-spacing: 0.5px;">Login as Staff</a>
                    </div>
                </div>
                
                
            </div>
        </div>
    </div>
    
    <script>
        let html5QrcodeScanner = null;
        
        function startQrScanner() {
            const startBtn = document.getElementById('start-scan-btn');
            const stopBtn = document.getElementById('stop-scan-btn');
            const errorDiv = document.getElementById('qr-scan-error');
            
            if (html5QrcodeScanner) {
                html5QrcodeScanner.render(onScanSuccess, onScanError);
                if (startBtn) startBtn.style.display = 'none';
                stopBtn.style.display = 'inline-block';
                return;
            }
            
            html5QrcodeScanner = new Html5Qrcode("qr-reader", { fps: 30, qrbox: 250 });
            
            const config = { 
                fps: 30,
                qrbox: { width: 250, height: 250 },
                aspectRatio: 1.0
            };
            
            html5QrcodeScanner.start(
                { facingMode: "environment" },
                config,
                onScanSuccess,
                onScanError
            ).catch(err => {
                errorDiv.textContent = 'Unable to access camera. Please check permissions or use manual entry below.';
                errorDiv.classList.add('show');
            });
            
            if (startBtn) startBtn.style.display = 'none';
            stopBtn.style.display = 'inline-block';
        }
        
        function stopQrScanner() {
            const startBtn = document.getElementById('start-scan-btn');
            const stopBtn = document.getElementById('stop-scan-btn');
            
            if (html5QrcodeScanner) {
                html5QrcodeScanner.stop().then(() => {
                    if (startBtn) startBtn.style.display = 'inline-block';
                    stopBtn.style.display = 'none';
                }).catch(err => {
                    console.error('Error stopping scanner:', err);
                });
            }
        }
        
        function onScanSuccess(decodedText, decodedResult) {
            const resultDiv = document.getElementById('qr-result');
            const errorDiv = document.getElementById('qr-scan-error');
            const resultValue = document.getElementById('qr-result-value');
            
            errorDiv.classList.remove('show');
            resultDiv.classList.add('show');
            resultValue.value = decodedText;
            
            stopQrScanner();
            
            setTimeout(() => {
                document.getElementById('qr-submit-form').submit();
            }, 800);
        }
        
        function onScanError(error) {
            // Suppress error logs for continuous scanning attempts
        }
        
        function dismissError() {
            const banner = document.getElementById('error-banner');
            if (banner) {
                banner.style.animation = 'slideUp 0.3s ease';
                setTimeout(() => {
                    banner.style.display = 'none';
                }, 300);
            }
        }
        
        document.addEventListener("DOMContentLoaded", function() {
            setTimeout(startQrScanner, 400);
        });
    </script>
        <?php else: ?>
            <!-- Student Info -->
            <div class="header">
                <h1>Welcome, <?php echo htmlspecialchars($student['full_name']); ?></h1>
                <p>Here's your library account information</p>
            </div>
            
            <!-- Student Details -->
            <div class="card">
                <div class="card-header">
                    
                    <span>Your Information</span>
                </div>
                <div class="card-body">
                    <div class="student-info">
                        <div class="info-item">
                            <div class="info-label">Student ID</div>
                            <div class="info-value">STU-<?php echo str_pad($student['student_id'], 4, '0', STR_PAD_LEFT); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Full Name</div>
                            <div class="info-value"><?php echo htmlspecialchars($student['full_name']); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Contact</div>
                            <div class="info-value"><?php echo htmlspecialchars($student['contact_number'] ?? 'N/A'); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">Books Borrowed</div>
                            <div class="info-value"><?php echo count($borrowed_books); ?></div>
                        </div>
                    </div>
                    
                    <?php if ($total_penalty > 0): ?>
                        <div class="penalty-info">
                            <span style="font-size: 20px;"></span>
                            <div>
                                <strong>Outstanding Penalty:</strong><br>
                                <strong>$<?php echo number_format($total_penalty, 2); ?></strong>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- QR Code Display -->
                    <div class="qr-section">
                        <div class="qr-display">
                            <h4>Your Student QR Code</h4>
                            <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?php echo urlencode($student['qr_code']); ?>" 
                                 alt="Student QR Code">
                            <div class="qr-code-text"><?php echo htmlspecialchars($student['qr_code']); ?></div>
                        </div>
                    </div>
                    
                    <div class="action-buttons">
                        <a href="/LibraryBorrowingSystem/student/borrow.php">📤 Borrow a Book</a>
                    </div>
                </div>
            </div>
            
            <!-- Borrowed Books -->
            <div class="card">
                <div class="card-header">
                    
                    <span>Borrowed Books</span>
                </div>
                <div class="card-body">
                    <?php if (empty($borrowed_books)): ?>
                        <div class="empty-state">
                            
                            <p>You have no borrowed books right now!</p>
                            <a href="/LibraryBorrowingSystem/student/borrow.php" style="display: inline-block; margin-top: 20px;">📤 Start Borrowing</a>
                        </div>
                    <?php else: ?>
                        <div class="book-list">
                            <?php foreach ($borrowed_books as $book): ?>
                                <?php
                                // Determine status
                                $is_overdue = false;
                                $days_left = 0;
                                $penalty = 0;
                                
                                if (strtotime($book['due_date']) < time()) {
                                    $is_overdue = true;
                                    $days_late = floor((time() - strtotime($book['due_date'])) / 86400);
                                    $penalty = $days_late * 5;
                                } else {
                                    $days_left = floor((strtotime($book['due_date']) - time()) / 86400);
                                }
                                
                                $status_class = $is_overdue ? 'status-overdue' : 'status-borrowed';
                                $status_text = $is_overdue ? 'OVERDUE' : 'BORROWED';
                                ?>
                                <div class="book-item">
                                    <div class="book-item-header">
                                        <div class="book-info">
                                            <h3><?php echo htmlspecialchars($book['title']); ?></h3>
                                            <p class="author">by <?php echo htmlspecialchars($book['author']); ?></p>
                                        </div>
                                        <span class="status-badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                                    </div>
                                    
                                    <div class="book-dates">
                                        <div class="date-row">
                                            <span class="date-label">Borrowed:</span>
                                            <span class="date-value"><?php echo date('M d, Y', strtotime($book['date_borrowed'])); ?></span>
                                        </div>
                                        <div class="date-row">
                                            <span class="date-label">Due:</span>
                                            <span class="date-value"><?php echo date('M d, Y', strtotime($book['due_date'])); ?></span>
                                        </div>
                                    </div>
                                    
                                    <div style="padding-top: 12px; border-top: 1px solid #E7EEF7; margin-top: 12px; text-align: right;">
                                        <?php if ($is_overdue): ?>
                                            <div style="color: #BB5716; font-weight: 700; font-size: 16px;">â±<?php echo number_format($penalty, 2); ?> penalty</div>
                                            <div style="color: #52618D; font-size: 12px; margin-top: 4px;"><?php echo $days_late; ?> <?php echo $days_late === 1 ? 'day' : 'days'; ?> late</div>
                                        <?php else: ?>
                                            <div style="color: #27ae60; font-weight: 700; font-size: 16px;">⏰ <?php echo $days_left; ?> <?php echo $days_left === 1 ? 'day' : 'days'; ?> left</div>
                                            <div style="color: #52618D; font-size: 12px; margin-top: 4px;">Due on <?php echo date('M d', strtotime($book['due_date'])); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>

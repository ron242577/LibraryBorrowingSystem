<?php
/**
 * Add Student Page - Librarian Panel
 * Allows librarians to add new students and auto-generate QR codes
 */

require_once __DIR__ . '/../session_check.php';
require_once __DIR__ . '/../db.php';

// Check if user is librarian
if (!isLibrarian() && !isSuperAdmin()) {
    header('Location: /LibraryBorrowingSystem/login.php');
    exit();
}

$message = '';
$message_type = '';
$students = [];

// Create QR codes directory if it doesn't exist
$qr_dir = __DIR__ . '/../qr_codes';
if (!is_dir($qr_dir)) {
    mkdir($qr_dir, 0755, true);
}

/**
 * Generate a unique student QR code and save image
 * 
 * @param string $student_id The unique identifier for the student
 * @return string The path to the QR code image
 */
function generateQRCode($student_id) {
    global $qr_dir;
    
    $filename = $student_id . '.png';
    $filepath = $qr_dir . '/' . $filename;
    
    // Use QR server API to generate QR code
    $qr_api_url = 'https://api.qrserver.com/v1/create-qr-code/';
    $qr_data = urlencode($student_id);
    $qr_image_url = $qr_api_url . '?size=200x200&data=' . $qr_data;
    
    try {
        // Download QR code image from API
        $image_content = @file_get_contents($qr_image_url, false, stream_context_create([
            'ssl' => [ 
                'verify_peer' => false,
                'verify_peer_name' => false,
            ]
        ]));
        
        if ($image_content === false) {
            throw new Exception('Failed to download QR code from API');
        }
        
        // Save image to local directory
        if (file_put_contents($filepath, $image_content) === false) {
            throw new Exception('Failed to save QR code image');
        }
        
        return 'qr_codes/' . $filename;
    } catch (Exception $e) {
        logError('QR Code generation error: ' . $e->getMessage());
        throw $e;
    }
}

/**
 * Generate unique student QR code identifier
 * Format: STU-YYYYMMDD-XXXXX (where XXXXX is 5 random alphanumeric characters)
 * 
 * @return string Unique student identifier
 */
function generateUniqueStudentId() {
    $date = date('Ymd');
    $random = strtoupper(substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 5));
    return 'STU-' . $date . '-' . $random;
}

// Handle add student form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $full_name = trim($_POST['full_name'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    
    // Validate input
    if (empty($full_name)) {
        $message = 'Full name is required.';
        $message_type = 'error';
    } elseif (strlen($full_name) < 3) {
        $message = 'Full name must be at least 3 characters long.';
        $message_type = 'error';
    } elseif (!empty($contact_number) && !preg_match('/^[0-9\s\-\+\(\)]*$/', $contact_number)) {
        $message = 'Invalid contact number format.';
        $message_type = 'error';
    } else {
        try {
            // Generate unique student QR code identifier
            $student_qr_code = generateUniqueStudentId();
            
            // Check if QR code already exists (very unlikely but possible)
            $check_stmt = $conn->prepare("SELECT student_id FROM students WHERE qr_code = ?");
            $check_stmt->bind_param('s', $student_qr_code);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $message = 'Error generating unique QR code. Please try again.';
                $message_type = 'error';
            } else {
                // Generate QR code image
                $qr_path = generateQRCode($student_qr_code);
                
                // Insert student into database
                $insert_stmt = $conn->prepare("INSERT INTO students (full_name, contact_number, qr_code, status) VALUES (?, ?, ?, 'active')");
                $insert_stmt->bind_param('sss', $full_name, $contact_number, $student_qr_code);
                
                if ($insert_stmt->execute()) {
                    $student_id = $conn->insert_id;
                    $message = 'Student added successfully! QR Code: ' . htmlspecialchars($student_qr_code);
                    $message_type = 'success';
                    
                    // Clear form by reloading page after short delay
                    header("Refresh: 2; url=/LibraryBorrowingSystem/librarian/add_student.php");
                } else {
                    $message = 'Error adding student: ' . htmlspecialchars($conn->error);
                    $message_type = 'error';
                }
                $insert_stmt->close();
            }
            $check_stmt->close();
        } catch (Exception $e) {
            $message = 'Error: ' . htmlspecialchars($e->getMessage());
            $message_type = 'error';
        }
    }
}

// Fetch all students
try {
    $result = $conn->query("SELECT student_id, full_name, contact_number, qr_code, status, created_at FROM students ORDER BY created_at DESC LIMIT 20");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $students[] = $row;
        }
    }
} catch (Exception $e) {
    logError('Error fetching students: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Student - Library Borrowing System</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            color: #333;
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
            color: #333;
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
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
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
            color: #333;
            margin-bottom: 20px;
            font-size: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #8B0000;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
            font-size: 14px;
        }
        
        input[type="text"],
        input[type="tel"] {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        input[type="text"]:focus,
        input[type="tel"]:focus {
            outline: none;
            border-color: #8B0000;
            box-shadow: 0 0 5px rgba(139, 0, 0, 0.2);
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
        
        .button-group {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        button {
            padding: 12px 24px;
            background: #8B0000;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 14px;
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
        
        button[type="reset"] {
            background: #999;
        }
        
        button[type="reset"]:hover {
            box-shadow: 0 5px 15px rgba(150, 150, 150, 0.4);
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
            background: #f9f9f9;
        }
        
        th {
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: #333;
            border-bottom: 2px solid #ddd;
            font-size: 14px;
        }
        
        td {
            padding: 15px;
            border-bottom: 1px solid #eee;
            font-size: 14px;
        }
        
        tbody tr:hover {
            background: #f9f9f9;
        }
        
        .qr-code-cell {
            text-align: center;
        }
        
        .qr-code-image {
            width: 60px;
            height: 60px;
            border: 1px solid #ddd;
            border-radius: 4px;
            cursor: pointer;
            transition: transform 0.2s;
        }
        
        .qr-code-image:hover {
            transform: scale(1.1);
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
            background: #d4edda;
            color: #155724;
        }
        
        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }
        
        .qr-info {
            background: #f8f0f0;
            padding: 15px;
            border-radius: 5px;
            margin-top: 15px;
            border-left: 4px solid #8B0000;
            font-size: 13px;
            color: #333;
        }
        
        .qr-info strong {
            color: #155724;
        }
        
        .no-data {
            text-align: center;
            padding: 40px;
            color: #999;
        }
        
        /* Modal for QR code preview */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.6);
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
            text-align: center;
            max-width: 400px;
        }
        
        .modal-header h3 {
            margin-bottom: 15px;
            color: #333;
        }
        
        .modal-qr-image {
            max-width: 300px;
            margin: 20px auto;
            border: 2px solid #ddd;
            border-radius: 8px;
            padding: 10px;
            background: white;
        }
        
        .modal-close-btn {
            margin-top: 20px;
            padding: 10px 20px;
            background: #999;
            font-size: 14px;
            font-weight: 600;
        }
        
        .modal-close-btn:hover {
            background: #777;
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/../navbar.php'; ?>
    
    <div class="container">
        <div class="page-header">
            <h2>👨‍🎓 Add New Student</h2>
        </div>
        
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <span><?php echo $message_type === 'success' ? '✓' : '✕'; ?></span>
                <span><?php echo htmlspecialchars($message); ?></span>
            </div>
        <?php endif; ?>
        
        <!-- Add Student Form -->
        <div class="section">
            <h3>➕ Student Registration</h3>
            <form method="POST">
                <input type="hidden" name="action" value="add">
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="full_name">Full Name *</label>
                        <input type="text" id="full_name" name="full_name" required placeholder="e.g. John Doe">
                    </div>
                    
                    <div class="form-group">
                        <label for="contact_number">Contact Number</label>
                        <input type="tel" id="contact_number" name="contact_number" placeholder="e.g. +1-234-567-8900">
                    </div>
                </div>
                
                <div class="qr-info">
                    <strong>ℹ️ QR Code Generation:</strong><br>
                    A unique QR code will be automatically generated and saved when you add the student. The QR code can be used for tracking book borrowing and returns.
                </div>
                
                <div class="button-group">
                    <button type="submit">Add Student</button>
                    <button type="reset">Clear</button>
                </div>
            </form>
        </div>
        
        <!-- Recent Students List -->
        <div class="section">
            <h3>📋 Recent Students (Last 20)</h3>
            
            <?php if (count($students) > 0): ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>QR Code</th>
                                <th>Full Name</th>
                                <th>Contact Number</th>
                                <th>QR Code ID</th>
                                <th>Status</th>
                                <th>Added</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $student): ?>
                                <tr>
                                    <td class="qr-code-cell">
                                        <?php if (file_exists(__DIR__ . '/../qr_codes/' . $student['qr_code'] . '.png')): ?>
                                            <img src="/LibraryBorrowingSystem/qr_codes/<?php echo htmlspecialchars($student['qr_code']); ?>.png" 
                                                 alt="QR Code" 
                                                 class="qr-code-image"
                                                 onclick="openQRModal('<?php echo htmlspecialchars($student['qr_code']); ?>')">
                                        <?php else: ?>
                                            <span style="color: #999;">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($student['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($student['contact_number'] ?: '—'); ?></td>
                                    <td><code><?php echo htmlspecialchars($student['qr_code']); ?></code></td>
                                    <td>
                                        <span class="status-badge status-<?php echo $student['status']; ?>">
                                            <?php echo ucfirst($student['status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($student['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="no-data">
                    <p>No students added yet.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- QR Code Preview Modal -->
    <div id="qrModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>📱 QR Code Preview</h3>
                <p id="qrCodeId" style="color: #666; margin-top: 5px; font-size: 14px;"></p>
            </div>
            
            <img id="qrImage" src="" alt="QR Code" class="modal-qr-image">
            
            <button class="modal-close-btn" onclick="closeQRModal()">Close</button>
        </div>
    </div>
    
    <script>
        function openQRModal(qrCode) {
            document.getElementById('qrCodeId').textContent = 'ID: ' + qrCode;
            document.getElementById('qrImage').src = '/LibraryBorrowingSystem/qr_codes/' + qrCode + '.png';
            document.getElementById('qrModal').classList.add('show');
        }
        
        function closeQRModal() {
            document.getElementById('qrModal').classList.remove('show');
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('qrModal');
            if (event.target == modal) {
                modal.classList.remove('show');
            }
        }
    </script>
</body>
</html>

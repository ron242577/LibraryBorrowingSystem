<?php
/**
 * Add Book Page - Librarian Panel
 * Allows librarians to add new books and auto-generate QR codes
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
$books = [];

// Create QR codes directory if it doesn't exist
$qr_dir = __DIR__ . '/../qr_codes';
if (!is_dir($qr_dir)) {
    mkdir($qr_dir, 0755, true);
}

/**
 * Generate a unique book QR code and save image
 * 
 * @param string $book_qr_id The unique identifier for the book
 * @return string The path to the QR code image
 */
function generateBookQRCode($book_qr_id) {
    global $qr_dir;
    
    $filename = $book_qr_id . '.png';
    $filepath = $qr_dir . '/' . $filename;
    
    // Use QR server API to generate QR code
    $qr_api_url = 'https://api.qrserver.com/v1/create-qr-code/';
    $qr_data = urlencode($book_qr_id);
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
        logError('Book QR Code generation error: ' . $e->getMessage());
        throw $e;
    }
}

/**
 * Generate unique book QR code identifier
 * Format: BOOK-YYYYMMDD-XXXXX (where XXXXX is 5 random alphanumeric characters)
 * 
 * @return string Unique book identifier
 */
function generateUniqueBookQRId() {
    $date = date('Ymd');
    $random = strtoupper(substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 5));
    return 'BOOK-' . $date . '-' . $random;
}

// Handle add book form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $title = trim($_POST['title'] ?? '');
    $author = trim($_POST['author'] ?? '');
    
    // Validate input
    if (empty($title)) {
        $message = 'Book title is required.';
        $message_type = 'error';
    } elseif (strlen($title) < 3) {
        $message = 'Book title must be at least 3 characters long.';
        $message_type = 'error';
    } elseif (empty($author)) {
        $message = 'Author name is required.';
        $message_type = 'error';
    } elseif (strlen($author) < 2) {
        $message = 'Author name must be at least 2 characters long.';
        $message_type = 'error';
    } else {
        try {
            // Generate unique book QR code identifier
            $book_qr_code = generateUniqueBookQRId();
            
            // Check if QR code already exists (very unlikely but possible)
            $check_stmt = $conn->prepare("SELECT book_id FROM books WHERE qr_code = ?");
            $check_stmt->bind_param('s', $book_qr_code);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $message = 'Error generating unique QR code. Please try again.';
                $message_type = 'error';
            } else {
                // Generate QR code image
                $qr_path = generateBookQRCode($book_qr_code);
                
                // Insert book into database with inventory fields
                $status = 'available';
                $total_copies = 1;
                $available_copies = 1;
                $borrowed_copies = 0;
                $lost_copies = 0;
                
                $insert_stmt = $conn->prepare("INSERT INTO books (title, author, qr_code, book_status, total_copies, available_copies, borrowed_copies, lost_copies) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $insert_stmt->bind_param('ssssiiii', $title, $author, $book_qr_code, $status, $total_copies, $available_copies, $borrowed_copies, $lost_copies);
                
                if ($insert_stmt->execute()) {
                    $book_id = $conn->insert_id;
                    $message = 'Book added successfully! QR Code: ' . htmlspecialchars($book_qr_code);
                    $message_type = 'success';
                    
                    // Clear form by reloading page after short delay
                    header("Refresh: 2; url=/LibraryBorrowingSystem/librarian/add_book.php");
                } else {
                    $message = 'Error adding book: ' . htmlspecialchars($conn->error);
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

// Fetch all books
try {
    $result = $conn->query("SELECT book_id, title, author, qr_code, book_status, total_copies, available_copies, borrowed_copies, lost_copies, created_at FROM books ORDER BY created_at DESC LIMIT 20");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            // Only include books that have a QR code file
            if (file_exists($qr_dir . '/' . $row['qr_code'] . '.png')) {
                $books[] = $row;
            }
        }
    }
} catch (Exception $e) {
    logError('Error fetching books: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Book - Library Borrowing System</title>
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
        
        input[type="text"] {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        input[type="text"]:focus {
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
        
        .status-available {
            background: #d4edda;
            color: #155724;
        }
        
        .status-borrowed {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-damaged {
            background: #f8d7da;
            color: #721c24;
        }
        
        .status-lost {
            background: #e2e3e5;
            color: #383d41;
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
            <h2>📚 Add New Book</h2>
        </div>
        
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $message_type; ?>">
                <span><?php echo $message_type === 'success' ? '✓' : '✕'; ?></span>
                <span><?php echo htmlspecialchars($message); ?></span>
            </div>
        <?php endif; ?>
        
        <!-- Add Book Form -->
        <div class="section">
            <h3>➕ Book Registration</h3>
            <form method="POST">
                <input type="hidden" name="action" value="add">
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="title">Book Title *</label>
                        <input type="text" id="title" name="title" required placeholder="e.g. The Great Gatsby">
                    </div>
                    
                    <div class="form-group">
                        <label for="author">Author *</label>
                        <input type="text" id="author" name="author" required placeholder="e.g. F. Scott Fitzgerald">
                    </div>
                </div>
                
                <div class="qr-info">
                    <strong>ℹ️ QR Code & Status:</strong><br>
                    A unique QR code will be automatically generated and saved for each book copy. The book status will be set to "Available" by default.
                </div>
                
                <div class="button-group">
                    <button type="submit">Add Book</button>
                    <button type="reset">Clear</button>
                </div>
            </form>
        </div>
        
        <!-- Recent Books List -->
        <div class="section">
            <h3>📚 Recent Books (Last 20)</h3>
            
            <?php if (count($books) > 0): ?>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>QR Code</th>
                                <th>Title</th>
                                <th>Author</th>
                                <th>QR Code ID</th>
                                <th>Status</th>
                                <th>Added</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($books as $book): ?>
                                <tr>
                                    <td class="qr-code-cell">
                                        <img src="/LibraryBorrowingSystem/qr_codes/<?php echo htmlspecialchars($book['qr_code']); ?>.png" 
                                             alt="QR Code" 
                                             class="qr-code-image"
                                             onclick="openQRModal('<?php echo htmlspecialchars($book['qr_code']); ?>', '<?php echo htmlspecialchars($book['title']); ?>')">
                                    </td>
                                    <td><strong><?php echo htmlspecialchars($book['title']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($book['author']); ?></td>
                                    <td><code><?php echo htmlspecialchars($book['qr_code']); ?></code></td>
                                    <td>
                                        <span class="status-badge status-<?php echo $book['book_status']; ?>">
                                            <?php echo ucfirst($book['book_status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($book['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="no-data">
                    <p>No books added yet.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- QR Code Preview Modal -->
    <div id="qrModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>📱 QR Code Preview</h3>
                <p id="qrBookInfo" style="color: #666; margin-top: 5px; font-size: 13px;"></p>
            </div>
            
            <img id="qrImage" src="" alt="QR Code" class="modal-qr-image">
            
            <button class="modal-close-btn" onclick="closeQRModal()">Close</button>
        </div>
    </div>
    
    <script>
        function openQRModal(qrCode, bookTitle) {
            document.getElementById('qrBookInfo').textContent = 'ID: ' + qrCode + ' | Title: ' + bookTitle;
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

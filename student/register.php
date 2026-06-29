<?php
/**
 * Student Registration Page
 * Allows students to register with their information
 */

require_once __DIR__ . '/../db.php';

$errors = [];
$success = false;
$new_student_no = null;
$generated_qr = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate and sanitize input
    $full_name = trim($_POST['full_name'] ?? '');
    $student_no = trim($_POST['student_no'] ?? '');
    $student_group = trim($_POST['student_group'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $year_level = trim($_POST['year_level'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $email = trim($_POST['email'] ?? '');
    
    // Automatically set card validity to 1 year from today
    $card_valid_until = date('Y-m-d', strtotime('+1 year'));

    // Validation
    if (empty($full_name)) {
        $errors[] = 'Name is required.';
    } elseif (strlen($full_name) < 3) {
        $errors[] = 'Name must be at least 3 characters long.';
    }

    if (empty($student_no)) {
        $errors[] = 'Student Number is required.';
    } elseif (!preg_match('/^[A-Za-z0-9\-]+$/', $student_no)) {
        $errors[] = 'Student Number contains invalid characters.';
    }

    if (empty($department)) {
        $errors[] = 'Department is required.';
    }

    if (empty($contact_number)) {
        $errors[] = 'Contact Number is required.';
    } elseif (!preg_match('/^[0-9\+\-\s\(\)]+$/', $contact_number)) {
        $errors[] = 'Contact Number contains invalid characters.';
    }

    if (empty($email)) {
        $errors[] = 'Email is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please provide a valid email address.';
    }

    // Check for duplicate student number and email
    if (empty($errors)) {
        try {
            $check_stmt = $conn->prepare('SELECT student_no FROM students WHERE student_no = ? OR email = ? LIMIT 1');
            $check_stmt->bind_param('ss', $student_no, $email);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();

            if ($check_result->num_rows > 0) {
                $errors[] = 'Student Number or Email already registered.';
            }
            $check_stmt->close();
        } catch (Exception $e) {
            $errors[] = 'Database error during validation.';
            logError('Student registration validation error: ' . $e->getMessage());
        }
    }

    // If no errors, proceed with registration
    if (empty($errors)) {
        try {
            // Generate QR code
            $qr_code = 'STU-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 5));
            $generated_qr = $qr_code;

            $register_stmt = $conn->prepare(
                'INSERT INTO students (full_name, student_no, student_group, department, year_level, contact_number, card_valid_until, email, qr_code, status) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );

            $status = 'active';

            $register_stmt->bind_param(
                'ssssssssss',
                $full_name,
                $student_no,
                $student_group,
                $department,
                $year_level,
                $contact_number,
                $card_valid_until,
                $email,
                $qr_code,
                $status
            );

            if ($register_stmt->execute()) {
                $new_student_no = $conn->insert_id;
                $success = true;
            } else {
                $errors[] = 'Registration failed. Please try again.';
                logError('Student registration insert error: ' . $register_stmt->error);
            }

            $register_stmt->close();
        } catch (Exception $e) {
            $errors[] = 'An error occurred during registration.';
            logError('Student registration error: ' . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Registration - Library Borrowing System</title>
    <style>
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
            max-width: 900px;
            margin: 0 auto;
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

        /* Registration Card */
        .registration-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
            border: 1px solid #91B0E0;
            overflow: hidden;
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
            padding: 40px 30px;
        }

        /* Alert Messages */
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            animation: slideDown 0.3s ease;
        }

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

        .alert-error {
            background: #FBE8DC;
            border-left: 4px solid #BB5716;
            color: #7A3A0E;
        }

        .alert-success {
            background: #EDF5DD;
            border-left: 4px solid #567D1F;
            color: #344E15;
        }

        .alert ul {
            margin-left: 20px;
            line-height: 1.6;
        }

        .alert li {
            margin-bottom: 6px;
        }

        .alert-icon {
            font-size: 20px;
            flex-shrink: 0;
            margin-top: 2px;
        }

        /* Success Message Content */
        .success-content {
            text-align: center;
        }

        .success-content h3 {
            color: #344E15;
            margin-bottom: 16px;
            font-size: 20px;
        }

        .success-content p {
            margin-bottom: 12px;
            line-height: 1.6;
        }

        .success-qr {
            background: #F7F9FC;
            padding: 20px;
            border-radius: 12px;
            margin: 20px 0;
            text-align: center;
        }

        .success-qr h4 {
            color: #202A44;
            margin-bottom: 12px;
            font-size: 14px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .success-qr img {
            max-width: 180px;
            height: auto;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(20, 31, 82, 0.2);
            background: white;
            padding: 8px;
        }

        .success-qr-code {
            margin-top: 12px;
            font-size: 12px;
            color: #141F52;
            font-weight: 600;
            font-family: 'Courier New', monospace;
            letter-spacing: 0.5px;
        }

        .success-actions {
            display: flex;
            gap: 12px;
            margin-top: 24px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .success-actions a {
            padding: 12px 24px;
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

        .success-actions a:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(20, 31, 82, 0.4);
        }

        /* Form Grid */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-grid.full {
            grid-template-columns: 1fr;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: #52618D;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 10px;
        }

        .required {
            color: #BB5716;
            margin-left: 4px;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 14px 16px;
            border: 2px solid #E7EEF7;
            border-radius: 10px;
            font-size: 15px;
            font-family: inherit;
            color: #202A44;
            background: #F7F9FC;
            transition: all 0.3s ease;
        }

        .form-group input::placeholder,
        .form-group textarea::placeholder {
            color: #91B0E0;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #141F52;
            background: white;
            box-shadow: 0 0 0 3px rgba(244, 249, 22, 0.35);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }

        .helper-text {
            font-size: 12px;
            color: #52618D;
            margin-top: 8px;
            line-height: 1.5;
        }

        /* Submit Button */
        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 30px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn-submit {
            padding: 14px 40px;
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

        .btn-submit:hover {
            background: #52618D;
            transform: translateY(-2px);
            box-shadow: 0 6px 25px rgba(20, 31, 82, 0.4);
        }

        .btn-submit:active {
            transform: translateY(0);
        }

        .btn-back {
            padding: 14px 40px;
            background: #E7EEF7;
            color: #202A44;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            text-decoration: none;
            display: inline-block;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .btn-back:hover {
            transform: translateY(-2px);
            background: #D2E2F6;
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.15);
        }

        /* Footer Links */
        .form-footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 24px;
            border-top: 1px solid #E7EEF7;
            color: #52618D;
            font-size: 14px;
        }

        .form-footer a {
            color: #52618D;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s ease;
        }

        .form-footer a:hover {
            color: #141F52;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .header h1 {
                font-size: 24px;
            }

            .card-body {
                padding: 24px 20px;
            }

            .form-grid {
                grid-template-columns: 1fr;
                gap: 16px;
            }

            .form-actions {
                flex-direction: column;
            }

            .btn-submit,
            .btn-back {
                width: 100%;
            }
        }

        @media (max-width: 480px) {
            .header h1 {
                font-size: 20px;
            }

            .header p {
                font-size: 13px;
            }

            .card-body {
                padding: 20px 16px;
            }

            .form-group label {
                font-size: 11px;
            }

            .form-group input,
            .form-group select,
            .form-group textarea {
                padding: 12px 14px;
                font-size: 14px;
            }

            .alert {
                font-size: 13px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Student Registration</h1>
            <p>Register to access the library borrowing system</p>
        </div>

        <div class="registration-card">
            <div class="card-header">
                <span>📝</span>
                <span>Create Your Student Account</span>
            </div>

            <div class="card-body">
                <?php if ($success && $new_student_no): ?>
                    <!-- Success Message -->
                    <div class="alert alert-success">
                        <div class="alert-icon">✓</div>
                        <div class="success-content">
                            <h3>Registration Successful!</h3>
                            <p>Your student account has been created. You can now access the student portal.</p>
                            <p><strong>Student ID:</strong> <?php echo str_pad($new_student_no, 4, '0', STR_PAD_LEFT); ?></p>
                            
                            <div class="success-qr">
                                <h4>Your Student QR Code</h4>
                                <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?php echo urlencode($generated_qr); ?>" 
                                     alt="Student QR Code">
                                <div class="success-qr-code"><?php echo htmlspecialchars($generated_qr); ?></div>
                            </div>

                            <p style="color: #52618D; font-size: 13px; margin-top: 16px;">
                                Save your QR code or remember it. You'll use it to access your library account.
                            </p>

                            <div class="success-actions">
                                <a href="/LibraryBorrowingSystem/student/portal.php">Go to Student Portal</a>
                            </div>
                        </div>
                    </div>

                <?php else: ?>
                    <!-- Error Messages -->
                    <?php if (!empty($errors)): ?>
                        <div class="alert alert-error">
                            <div class="alert-icon">⚠</div>
                            <div>
                                <strong>Registration Failed</strong>
                                <ul>
                                    <?php foreach ($errors as $error): ?>
                                        <li><?php echo htmlspecialchars($error); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Registration Form -->
                    <form method="POST" novalidate>
                        <!-- Personal Information Section -->
                        <h3 style="color: #202A44; margin: 24px 0 16px; font-size: 16px; font-weight: 600; border-bottom: 2px solid #E7EEF7; padding-bottom: 12px;">
                            Personal Information
                        </h3>

                        <div class="form-grid">
                            <div class="form-group">
                                <label>Full Name <span class="required">*</span></label>
                                <input type="text" name="full_name" placeholder="Enter your full name" 
                                       value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>" required>
                                <div class="helper-text">Your complete legal name</div>
                            </div>

                            <div class="form-group">
                                <label>Student Number <span class="required">*</span></label>
                                <input type="text" name="student_no" placeholder="e.g., 23-01446" 
                                       value="<?php echo htmlspecialchars($_POST['student_no'] ?? ''); ?>" required>
                                <div class="helper-text">Your institutional student number</div>
                            </div>

                            <div class="form-group">
                                <label>Group</label>
                                <input type="text" name="student_group" placeholder="e.g., 3A, Group B" 
                                       value="<?php echo htmlspecialchars($_POST['student_group'] ?? ''); ?>">
                                <div class="helper-text">Your assigned group (optional)</div>
                            </div>

                            <div class="form-group">
                                <label>Department <span class="required">*</span></label>
                                <input type="text" name="department" placeholder="e.g., College of Computer Studies" 
                                       value="<?php echo htmlspecialchars($_POST['department'] ?? ''); ?>" required>
                                <div class="helper-text">Your department/college</div>
                            </div>

                            <div class="form-group">
                                <label>Year Level</label>
                                <select name="year_level">
                                    <option value="">Select Year Level</option>
                                    <option value="1st Year" <?php if (($_POST['year_level'] ?? '') === '1st Year') echo 'selected'; ?>>1st Year</option>
                                    <option value="2nd Year" <?php if (($_POST['year_level'] ?? '') === '2nd Year') echo 'selected'; ?>>2nd Year</option>
                                    <option value="3rd Year" <?php if (($_POST['year_level'] ?? '') === '3rd Year') echo 'selected'; ?>>3rd Year</option>
                                    <option value="4th Year" <?php if (($_POST['year_level'] ?? '') === '4th Year') echo 'selected'; ?>>4th Year</option>
                                    <option value="Masters" <?php if (($_POST['year_level'] ?? '') === 'Masters') echo 'selected'; ?>>Masters</option>
                                </select>
                                <div class="helper-text">Your academic year level (optional)</div>
                            </div>
                        </div>

                        <!-- Contact Information Section -->
                        <h3 style="color: #202A44; margin: 24px 0 16px; font-size: 16px; font-weight: 600; border-bottom: 2px solid #E7EEF7; padding-bottom: 12px;">
                            Contact Information
                        </h3>

                        <div class="form-grid">
                            <div class="form-group">
                                <label>Contact Number <span class="required">*</span></label>
                                <input type="tel" name="contact_number" placeholder="e.g., +63 945 735 2866" 
                                       value="<?php echo htmlspecialchars($_POST['contact_number'] ?? ''); ?>" required>
                                <div class="helper-text">Your mobile or phone number</div>
                            </div>

                            <div class="form-group">
                                <label>Email <span class="required">*</span></label>
                                <input type="email" name="email" placeholder="e.g., your.email@university.edu" 
                                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
                                <div class="helper-text">Your institutional email address</div>
                            </div>

                            <div class="form-group">
                                <label>Library Card Valid Until</label>
                                <input type="text" disabled placeholder="Automatically set to 1 year from today" 
                                       value="<?php echo date('F d, Y', strtotime('+1 year')); ?>">
                                <div class="helper-text">Your library card validity will be set to 1 year from today</div>
                            </div>
                        </div>

                        <!-- Form Actions -->
                        <div class="form-actions">
                            <button type="submit" class="btn-submit">Register Account</button>
                            <a href="/LibraryBorrowingSystem/student/portal.php" class="btn-back">Cancel</a>
                        </div>

                        <!-- Footer Links -->
                        <div class="form-footer">
                            Already have an account? <a href="/LibraryBorrowingSystem/student/portal.php">Go to Student Portal</a>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>

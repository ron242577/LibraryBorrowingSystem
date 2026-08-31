<?php
require_once __DIR__ . '/../session_check.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/library_rules.php';
require_once __DIR__ . '/../includes/notification_helper.php';
require_once __DIR__ . '/../includes/audit_logger.php';

if (!isAdmin()) {
    header('Location: /LibraryBorrowingSystem/login.php');
    exit();
}

function h($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

$message = '';
$message_type = '';
expireStaleReservations($conn);

$rules_message = '';
$rules_message_type = '';
$max_active_books = getLibraryRule($conn, 'max_active_books_per_student', 3);
$reservation_expiry_days = getLibraryRule($conn, 'reservation_expiry_days', 3);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_library_rules') {
    try {
        requireValidCsrf($_POST['csrf_token'] ?? '');
        $newMaxBooks = (int)($_POST['max_active_books_per_student'] ?? 3);
        $newExpiryDays = (int)($_POST['reservation_expiry_days'] ?? 3);

        if ($newMaxBooks < 1 || $newMaxBooks > 20) {
            throw new Exception('Maximum active books must be between 1 and 20.');
        }
        if ($newExpiryDays < 1 || $newExpiryDays > 30) {
            throw new Exception('Reservation expiry must be between 1 and 30 days.');
        }

        setLibraryRule($conn, 'max_active_books_per_student', $newMaxBooks);
        setLibraryRule($conn, 'reservation_expiry_days', $newExpiryDays);

        $max_active_books = $newMaxBooks;
        $reservation_expiry_days = $newExpiryDays;
        $rules_message = 'Borrowing and reservation rules updated successfully.';
        $rules_message_type = 'success';

        if (function_exists('auditRecordChange')) {
            auditRecordChange(
                $conn,
                'library_rules_updated',
                'reservations',
                'Updated borrowing and reservation rules.',
                'success',
                'library_settings',
                null,
                null,
                [
                    'max_active_books_per_student' => $newMaxBooks,
                    'reservation_expiry_days' => $newExpiryDays
                ]
            );
        }
    } catch (Throwable $e) {
        $rules_message = $e->getMessage();
        $rules_message_type = 'error';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        requireValidCsrf($_POST['csrf_token'] ?? '');
        $action = $_POST['action'] ?? '';
        $reservation_id = (int)($_POST['reservation_id'] ?? 0);

        if ($reservation_id <= 0) throw new Exception('Invalid reservation.');

        if ($action === 'mark_ready') {
            $lookup=$conn->prepare("
                SELECT r.student_id, r.book_id, r.status, r.reserved_at, b.title, b.available_copies
                FROM book_reservations r
                INNER JOIN books b ON r.book_id=b.book_id
                WHERE r.reservation_id=? LIMIT 1
            ");
            $lookup->bind_param('i',$reservation_id);
            $lookup->execute();
            $reservation=$lookup->get_result()->fetch_assoc();
            $lookup->close();

            if (!$reservation || $reservation['status'] !== 'pending') throw new Exception('Reservation is no longer pending.');
            if ((int)$reservation['available_copies'] <= 0) throw new Exception('The book has no available copy yet. It cannot be marked Ready.');

            $older=$conn->prepare("
                SELECT reservation_id FROM book_reservations
                WHERE book_id=? AND status='pending' AND reserved_at < ?
                LIMIT 1
            ");
            $older->bind_param('is',$reservation['book_id'],$reservation['reserved_at']);
            $older->execute();
            $hasOlder=$older->get_result()->num_rows>0;
            $older->close();

            if ($hasOlder) throw new Exception('Only the oldest pending reservation for a book can be marked Ready.');

            $stmt=$conn->prepare("UPDATE book_reservations SET status='ready', ready_at=NOW() WHERE reservation_id=? AND status='pending'");
            $stmt->bind_param('i',$reservation_id);
            $stmt->execute();
            if ($stmt->affected_rows===0) throw new Exception('Reservation could not be updated.');
            $stmt->close();

            createNotification($conn,'student',(int)$reservation['student_id'],'Reserved Book Available','A copy of "' . $reservation['title'] . '" is now available. Your reservation is ready.','/LibraryBorrowingSystem/student/profile.php#reservations');
            $message='Reservation marked as ready and the student was notified.';
            $message_type='success';
        } elseif ($action === 'cancel') {
            $stmt=$conn->prepare("UPDATE book_reservations SET status='cancelled', cancelled_at=NOW() WHERE reservation_id=? AND status IN ('pending','ready')");
            $stmt->bind_param('i',$reservation_id);
            $stmt->execute();
            if ($stmt->affected_rows===0) throw new Exception('Reservation is already closed.');
            $stmt->close();

            $lookup=$conn->prepare("SELECT student_id, book_id FROM book_reservations WHERE reservation_id=? LIMIT 1");
            $lookup->bind_param('i',$reservation_id); $lookup->execute(); $reservation=$lookup->get_result()->fetch_assoc(); $lookup->close();
            if ($reservation) {
                $bookStmt=$conn->prepare("SELECT title FROM books WHERE book_id=? LIMIT 1");
                $bookStmt->bind_param('i',$reservation['book_id']); $bookStmt->execute(); $book=$bookStmt->get_result()->fetch_assoc(); $bookStmt->close();
                if ($book) createNotification($conn,'student',(int)$reservation['student_id'],'Reservation Cancelled','Your reservation for "' . $book['title'] . '" was cancelled.','/LibraryBorrowingSystem/student/profile.php#reservations');
            }

            $message='Reservation cancelled and the student was notified.';
            $message_type='success';
        } elseif ($action === 'fulfill') {
            $stmt=$conn->prepare("UPDATE book_reservations SET status='fulfilled', fulfilled_at=NOW() WHERE reservation_id=? AND status='ready'");
            $stmt->bind_param('i',$reservation_id);
            $stmt->execute();
            if ($stmt->affected_rows===0) throw new Exception('Reservation must be ready before it can be fulfilled.');
            $stmt->close();
            $message='Reservation marked as fulfilled.';
            $message_type='success';
        } else {
            throw new Exception('Unknown reservation action.');
        }
    } catch (Throwable $e) {
        $message=$e->getMessage();
        $message_type='error';
    }
}

$reservations=[];
$sql="SELECT r.reservation_id, r.status, r.reserved_at, r.ready_at,
             s.student_no, s.full_name, s.email,
             b.title, b.book_number, b.available_copies
      FROM book_reservations r
      JOIN students s ON s.student_id=r.student_id
      JOIN books b ON b.book_id=r.book_id
      ORDER BY FIELD(r.status,'ready','pending','fulfilled','cancelled'), r.reserved_at ASC";
$result=$conn->query($sql);
if($result) while($row=$result->fetch_assoc()) $reservations[]=$row;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reservations - Library Borrowing System</title>
<style>
body{font-family:Segoe UI,Arial,sans-serif;background:#F3F7FC;color:#202A44;}
.container{max-width:1200px;margin:24px auto 30px;padding:0 20px}
.card{background:#fff;border-radius:12px;padding:24px;box-shadow:0 2px 10px rgba(0,0,0,.08)}
h1{margin:0 0 8px}.muted{color:#52618D;font-size:13px;margin-bottom:20px}
.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse}th,td{padding:12px;text-align:left;border-bottom:1px solid #E7EEF7;font-size:13px}th{font-size:12px;text-transform:uppercase;color:#52618D}
.badge{display:inline-block;padding:5px 9px;border-radius:999px;font-size:11px;font-weight:700}.pending{background:#FFF4D6;color:#8A5A00}.ready{background:#E6F5E8;color:#246B2B}.fulfilled{background:#E7EEF7;color:#52618D}.cancelled{background:#FBE7E7;color:#8A2B2B}
.actions{display:flex;gap:6px;flex-wrap:wrap}.actions button{border:0;border-radius:7px;padding:7px 10px;font-size:12px;font-weight:700;cursor:pointer}.ready-btn{background:#52618D;color:#fff}.cancel-btn{background:#B83232;color:#fff}.fulfill-btn{background:#2E7D32;color:#fff}
.alert{padding:12px 14px;border-radius:8px;margin-bottom:16px}.success{background:#EDF5DD;color:#344E15}.error{background:#F8D7DA;color:#721C24}
.rules-card{margin-bottom:18px}
.rules-card h2{margin:0 0 6px;font-size:19px;color:#202A44}
.rules-form{display:grid;grid-template-columns:1fr 1fr auto;gap:16px;align-items:end;margin-top:17px}
.rules-field label{display:block;font-size:12px;font-weight:700;color:#52618D;margin-bottom:6px}
.rules-field input{width:100%;padding:10px 11px;border:1px solid #D2E2F6;border-radius:7px;box-sizing:border-box;font-size:14px}
.rules-field small{display:block;color:#8793A7;font-size:10px;margin-top:5px}
.save-rules-btn{height:39px;padding:0 18px;border:0;border-radius:7px;background:#141F52;color:#fff;font-weight:700;cursor:pointer}
.save-rules-btn:hover{background:#52618D}
*{box-sizing:border-box} body{ margin:0;background:#F3F7FC;color:#202A44;font-family:'Segoe UI',Tahoma,sans-serif; overflow-x:hidden;}.container{max-width:1200px;margin:30px auto;padding:0 20px}.page-header,.backup-card,.history-card{background:#fff;border-radius:12px;box-shadow:0 2px 10px rgba(0,0,0,.08)}.page-header{padding:28px;margin-bottom:22px}.page-header h1{margin:0 0 7px;color:#141F52}.page-header p{margin:0;color:#52618D}.backup-card{padding:24px;margin-bottom:22px}.backup-card h2,.history-card h2{margin:0 0 12px;color:#141F52}.backup-card p{color:#52618D;line-height:1.6}.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}.btn{display:inline-flex;align-items:center;justify-content:center;padding:11px 18px;border:0;border-radius:8px;background:#141F52;color:#fff;font-weight:700;cursor:pointer;text-decoration:none}.btn:hover{background:#52618D}.btn-danger{background:#B42318}.notice{padding:13px 15px;border-radius:8px;margin-bottom:20px}.notice.success{background:#EDF5DD;color:#344E15;border:1px solid #B5D27A}.notice.error{background:#FBE8DC;color:#7A3A0E;border:1px solid #E8B08A}.warning{background:#FFF8D8;border:1px solid #E5CC55;color:#5C5F05;padding:14px;border-radius:8px;margin-top:15px}.history-card{padding:0;overflow:hidden}.history-head{padding:20px;border-bottom:1px solid #E7EEF7}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;min-width:800px}th{background:#141F52;color:#fff;text-align:left;padding:12px;font-size:12px;white-space:nowrap}td{padding:12px;border-bottom:1px solid #E7EEF7;font-size:13px;vertical-align:top}.status{display:inline-block;padding:4px 9px;border-radius:999px;font-size:11px;font-weight:700;text-transform:uppercase}.status-success{background:#EDF5DD;color:#344E15}.status-failed{background:#F8D7DA;color:#721C24}.status-started{background:#FBFDCB;color:#5C5F05}.inline-form{display:inline}.restore-file{max-width:260px}.danger-note{font-size:12px;color:#721c24;margin-top:10px}@media(max-width:768px){.container{margin:16px auto;padding:0 12px}.page-header,.backup-card{padding:18px}.actions{flex-direction:column}.btn{width:100%}.restore-file{max-width:none;width:100%}}

        .content-container,
        .container{margin-top:0 !important;}
@media(max-width:800px){.rules-form{grid-template-columns:1fr}.save-rules-btn{width:100%}}


</style>
</head>
<body>
<?php include __DIR__.'/../navbar.php'; ?>
<?php include __DIR__.'/../header.php'; ?>
<div class="container">
<div class="card rules-card">
<h2>Borrowing &amp; Reservation Rules</h2>
<div class="muted">Configure the limits applied to students system-wide.</div>
<?php if($rules_message): ?><div class="alert <?php echo $rules_message_type==='success'?'success':'error'; ?>"><?php echo h($rules_message); ?></div><?php endif; ?>
<form method="POST" class="rules-form">
<?php echo csrfField(); ?>
<input type="hidden" name="action" value="save_library_rules">
<div class="rules-field">
<label for="max_active_books_per_student">Maximum Active Books per Student</label>
<input type="number" id="max_active_books_per_student" name="max_active_books_per_student" min="1" max="20" value="<?php echo (int)$max_active_books; ?>" required>
<small>Applies to student borrowing and QR transactions.</small>
</div>
<div class="rules-field">
<label for="reservation_expiry_days">Reservation Expiry (Days)</label>
<input type="number" id="reservation_expiry_days" name="reservation_expiry_days" min="1" max="30" value="<?php echo (int)$reservation_expiry_days; ?>" required>
<small>Pending and Ready reservations expire automatically after this period.</small>
</div>
<div class="rules-actions"><button class="save-rules-btn" type="submit">Save Rules</button></div>
</form>
</div>

<div class="card">
<h1>Book Reservations</h1>
<div class="muted">Manage student reservations and notify students when a book becomes available.</div>
<?php if($message): ?><div class="alert <?php echo $message_type==='success'?'success':'error'; ?>"><?php echo h($message); ?></div><?php endif; ?>
<div class="table-wrap">
<table>
<thead><tr><th>Student</th><th>Book</th><th>Reserved</th><th>Status</th><th>Copies</th><th>Actions</th></tr></thead>
<tbody>
<?php foreach($reservations as $r): ?>
<tr>
<td><?php echo h($r['full_name']); ?><br><small><?php echo h($r['student_no']); ?></small></td>
<td><?php echo h($r['title']); ?><br><small><?php echo h($r['book_number']); ?></small></td>
<td><?php echo h(date('M d, Y h:i A',strtotime($r['reserved_at']))); ?></td>
<td><span class="badge <?php echo h($r['status']); ?>"><?php echo h(ucfirst($r['status'])); ?></span></td>
<td><?php echo (int)$r['available_copies']; ?></td>
<td><div class="actions">
<?php if($r['status']==='pending'): ?>
<form method="POST"><?php echo csrfField(); ?><input type="hidden" name="action" value="mark_ready"><input type="hidden" name="reservation_id" value="<?php echo (int)$r['reservation_id']; ?>"><button class="ready-btn" type="submit">Mark Ready</button></form>
<?php elseif($r['status']==='ready'): ?>
<form method="POST"><?php echo csrfField(); ?><input type="hidden" name="action" value="fulfill"><input type="hidden" name="reservation_id" value="<?php echo (int)$r['reservation_id']; ?>"><button class="fulfill-btn" type="submit">Fulfill</button></form>
<?php endif; ?>
<?php if(in_array($r['status'],['pending','ready'],true)): ?>
<form method="POST"><?php echo csrfField(); ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="reservation_id" value="<?php echo (int)$r['reservation_id']; ?>"><button class="cancel-btn" type="submit">Cancel</button></form>
<?php endif; ?>
</div></td>
</tr>
<?php endforeach; ?>
<?php if(!$reservations): ?><tr><td colspan="6" style="text-align:center;padding:30px;color:#777">No reservations yet.</td></tr><?php endif; ?>
</tbody>
</table>
</div>
</div></div>
</body></html>

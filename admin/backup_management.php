<?php
require_once __DIR__ . '/../session_check.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/backup_helper.php';

if (!isAdmin()) {
    header('Location: /LibraryBorrowingSystem/login.php');
    exit();
}

ensureBackupHistoryTable($conn);

$message = $_SESSION['backup_flash_message'] ?? '';
$message_type = $_SESSION['backup_flash_type'] ?? '';
unset($_SESSION['backup_flash_message'], $_SESSION['backup_flash_type']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        requireValidCsrf($_POST['csrf_token'] ?? '');
        $action = $_POST['action'] ?? '';

        if ($action === 'create_backup') {
            $backup = createDatabaseBackup($conn);
            $message = 'Database backup created successfully: ' . $backup['filename'];
            $message_type = 'success';
            if (function_exists('auditLogEvent')) {
                auditLogEvent($conn, 'database_backup', 'admin/backup_management', 'Created database backup.', 'success', 'backup', $backup['backup_id'], ['filename' => $backup['filename'], 'size' => $backup['size']]);
            }
        } elseif ($action === 'restore_backup') {
            $filename = (string)($_POST['filename'] ?? '');
            restoreDatabaseBackup($conn, $filename);
            $message = 'Database backup restored successfully. Please refresh the page after verifying the system state.';
            $message_type = 'success';
            if (function_exists('auditLogEvent')) {
                auditLogEvent($conn, 'database_restore', 'admin/backup_management', 'Restored database backup.', 'success', 'backup', null, ['filename' => basename($filename)]);
            }
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $message_type = 'error';
        if (function_exists('logError')) logError('Backup management error: ' . $e->getMessage());
        if (isset($action) && function_exists('auditLogEvent')) {
            auditLogEvent($conn, 'database_' . $action, 'admin/backup_management', 'Database backup operation failed.', 'failure', 'backup', null, ['filename' => $_POST['filename'] ?? null]);
        }
    }

    $_SESSION['backup_flash_message'] = $message;
    $_SESSION['backup_flash_type'] = $message_type ?: 'info';

    header('Location: /LibraryBorrowingSystem/admin/backup_management.php');
    exit();
}

$history = listBackupHistory($conn);

function h($value): string { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8'); }
function formatBytes($bytes): string {
    $bytes = (int)$bytes;
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1048576, 2) . ' MB';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Backup &amp; Restore - Chief Librarian - JASHS Library</title>
<style>
*{box-sizing:border-box} body{ margin:0;background:#F3F7FC;color:#202A44;font-family:'Segoe UI',Tahoma,sans-serif; overflow-x:hidden;}.container{max-width:1200px;margin:30px auto;padding:0 20px}.page-header,.backup-card,.history-card{background:#fff;border-radius:12px;box-shadow:0 2px 10px rgba(0,0,0,.08)}.page-header{padding:28px;margin-bottom:22px}.page-header h1{margin:0 0 7px;color:#141F52}.page-header p{margin:0;color:#52618D}.backup-card{padding:24px;margin-bottom:22px}.backup-card h2,.history-card h2{margin:0 0 12px;color:#141F52}.backup-card p{color:#52618D;line-height:1.6}.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}.btn{display:inline-flex;align-items:center;justify-content:center;padding:11px 18px;border:0;border-radius:8px;background:#141F52;color:#fff;font-weight:700;cursor:pointer;text-decoration:none}.btn:hover{background:#52618D}.btn-danger{background:#B42318}.notice{padding:13px 15px;border-radius:8px;margin-bottom:20px}.notice.success{background:#EDF5DD;color:#344E15;border:1px solid #B5D27A}.notice.error{background:#FBE8DC;color:#7A3A0E;border:1px solid #E8B08A}.warning{background:#FFF8D8;border:1px solid #E5CC55;color:#5C5F05;padding:14px;border-radius:8px;margin-top:15px}.history-card{padding:0;overflow:hidden}.history-head{padding:20px;border-bottom:1px solid #E7EEF7}.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;min-width:800px}th{background:#141F52;color:#fff;text-align:left;padding:12px;font-size:12px;white-space:nowrap}td{padding:12px;border-bottom:1px solid #E7EEF7;font-size:13px;vertical-align:top}.status{display:inline-block;padding:4px 9px;border-radius:999px;font-size:11px;font-weight:700;text-transform:uppercase}.status-success{background:#EDF5DD;color:#344E15}.status-failed{background:#F8D7DA;color:#721C24}.status-started{background:#FBFDCB;color:#5C5F05}.inline-form{display:inline}.restore-file{max-width:260px}.danger-note{font-size:12px;color:#721c24;margin-top:10px}@media(max-width:768px){.container{margin:16px auto;padding:0 12px}.page-header,.backup-card{padding:18px}.actions{flex-direction:column}.btn{width:100%}.restore-file{max-width:none;width:100%}}

        .content-container,
        .container{margin-top:0 !important;}

</style>
</head>
<body>
<?php include __DIR__ . '/../navbar.php'; ?>
<?php include __DIR__ . '/../header.php'; ?>
<main class="container">
<div class="page-header"><h1>Backup & Restore</h1><p>Protect the JASHS Library database with downloadable backups and controlled restoration.</p></div>
<?php if($message!==''): ?><div class="notice <?php echo h($message_type); ?>" id="backupNotice"><?php echo h($message); ?></div><?php endif; ?>
<section class="backup-card">
<h2>Create Database Backup</h2>
<p>Creates a complete SQL backup of the current library database and stores a protected copy on the server. You can download the backup from the history below.</p>
<form method="post" id="createBackupForm">
<?php echo csrfField(); ?><input type="hidden" name="action" value="create_backup">
<div class="actions"><button class="btn" id="createBackupBtn" type="submit">Create Backup Now</button></div>
</form>
</section>
<section class="backup-card">
<h2>Restore Database</h2>
<p>Restoring replaces database tables and data with the selected backup. Create a fresh backup before restoring.</p>
<div class="warning"><strong>Important:</strong> Restore is a destructive operation. Use a backup from a trusted source only and verify that nobody else is actively using the system.</div>
<form method="post" id="restoreForm" data-confirm-title="Restore Database" data-confirm-message="Restore this database backup? Current database data may be replaced by the selected backup." data-confirm-text="Restore Backup" data-confirm-danger="1" style="margin-top:16px;">
<?php echo csrfField(); ?><input type="hidden" name="action" value="restore_backup"><select class="restore-file" name="filename" required><option value="">Select a backup</option><?php foreach($history as $item): if($item['action']==='backup' && $item['status']==='success' && !empty($item['filename'])): ?><option value="<?php echo h($item['filename']); ?>"><?php echo h($item['filename']); ?> — <?php echo h(formatBytes($item['file_size'])); ?> — <?php echo h($item['created_at']); ?></option><?php endif; endforeach; ?></select><div class="actions"><button class="btn btn-danger" type="submit">Restore Selected Backup</button></div>
</form>
</section>
<section class="history-card"><div class="history-head"><h2>Backup History</h2><p style="margin:0;color:#52618D;font-size:13px;">Last 30 backup and restore operations.</p></div><div class="table-wrap"><table><thead><tr><th>Action</th><th>File</th><th>Size</th><th>Status</th><th>Created By</th><th>Date</th><th>Notes</th></tr></thead><tbody><?php if(empty($history)): ?><tr><td colspan="7" style="text-align:center;color:#777;padding:30px;">No backup history yet.</td></tr><?php else: foreach($history as $item): ?><tr><td><?php echo h(ucfirst($item['action'])); ?></td><td><?php echo h($item['filename'] ?? '—'); ?></td><td><?php echo $item['file_size']!==null ? h(formatBytes($item['file_size'])) : '—'; ?></td><td><span class="status status-<?php echo h($item['status']); ?>"><?php echo h($item['status']); ?></span></td><td><?php echo h($item['created_by_name'] ?? 'System'); ?></td><td><?php echo h($item['created_at']); ?></td><td><?php echo h($item['notes'] ?? ''); ?><?php if($item['action']==='backup' && $item['status']==='success' && !empty($item['filename'])): ?><br><a class="btn" style="margin-top:8px;padding:7px 10px;font-size:11px;" href="download_backup.php?file=<?php echo rawurlencode($item['filename']); ?>">Download</a><?php endif; ?></td></tr><?php endforeach; endif; ?></tbody></table></div></section>
</main>
<?php require_once __DIR__ . '/../includes/ui_feedback.php'; ?>
<script>
document.addEventListener('DOMContentLoaded',function(){
    const createForm=document.getElementById('createBackupForm');
    const restoreForm=document.getElementById('restoreForm');

    function lockForm(form, buttonText){
        if(!form || form.dataset.submitting==='1') return false;
        form.dataset.submitting='1';
        const button=form.querySelector('button[type="submit"]');
        if(button){
            button.disabled=true;
            button.textContent=buttonText;
            button.style.opacity='.65';
            button.style.cursor='wait';
        }
        return true;
    }

    if(createForm){
        createForm.addEventListener('submit',function(e){
            if(!lockForm(createForm,'Creating Backup...')) e.preventDefault();
        });
    }

    if(restoreForm){
        restoreForm.addEventListener('submit',function(e){
            if(!lockForm(restoreForm,'Restoring Backup...')) e.preventDefault();
        });
    }

    const n=document.getElementById('backupNotice');if(n&&typeof showToast==='function'){showToast(n.textContent.trim(),n.classList.contains('success')?'success':'error',4200);n.remove();}});</script>
</body>
</html>

<?php
/**
 * Recent activity timeline (borrows, returns, library visits) for the Student and Teacher profile pages.
 * Usage: require_once __DIR__ . '/../includes/portal_activity.php';
 *        renderRecentActivity($conn, 'student', $student);
 */
require_once __DIR__ . '/portal_dashboard.php'; // pdRows(), pdH(), pdAgo()

function renderRecentActivity(mysqli $conn, string $role, ?array $user): void
{
    if (!$user) return;
    $role = $role === 'teacher' ? 'teacher' : 'student';
    $col  = $role === 'teacher' ? 'teacher_id' : 'student_id';
    $uid  = (int)$user[$col];

    $activity = [];
    foreach (pdRows($conn, "SELECT b.title, t.date_borrowed AS at FROM transactions t INNER JOIN books b ON b.book_id = t.book_id WHERE t.$col = ? ORDER BY t.date_borrowed DESC LIMIT 5", 'i', [$uid]) as $r) {
        $activity[] = ['ts' => strtotime($r['at']), 'icon' => 'B', 'tone' => 'blue', 'text' => 'Borrowed <strong>' . pdH($r['title']) . '</strong>'];
    }
    foreach (pdRows($conn, "SELECT b.title, t.return_date AS at FROM transactions t INNER JOIN books b ON b.book_id = t.book_id WHERE t.$col = ? AND t.return_date IS NOT NULL ORDER BY t.return_date DESC LIMIT 5", 'i', [$uid]) as $r) {
        $activity[] = ['ts' => strtotime($r['at']), 'icon' => 'ꪜ', 'tone' => 'green', 'text' => 'Returned <strong>' . pdH($r['title']) . '</strong>'];
    }
    foreach (pdRows($conn, "SELECT time_in AS at, time_out FROM library_attendance WHERE $col = ? ORDER BY time_in DESC LIMIT 4", 'i', [$uid]) as $r) {
        $activity[] = ['ts' => strtotime($r['at']), 'icon' => '•', 'tone' => 'sky', 'text' => 'Visited the library' . ($r['time_out'] ? ' · stayed ' . max(1, (int)round((strtotime($r['time_out']) - strtotime($r['at'])) / 60)) . ' min' : ' · checked in')];
    }
    usort($activity, function ($a, $b) { return $b['ts'] <=> $a['ts']; });
    $activity = array_slice($activity, 0, 7);
    ?>
    <section class="card" id="activity">
        <div class="card-header">Recent Activity</div>
        <div class="card-body">
            <?php if (!$activity): ?>
                <div class="empty">No activity yet.</div>
            <?php else: ?>
            <ul class="ra-timeline">
                <?php foreach ($activity as $a): ?>
                <li class="ra-item">
                    <div class="ra-txt"><?php echo $a['text']; ?><span class="ra-when"><?php echo pdH(pdAgo((int)$a['ts'])); ?> · <?php echo pdH(date('M j, g:i A', (int)$a['ts'])); ?></span></div>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </section>
    <style>
    .ra-timeline{list-style:none;margin:0;padding:0;display:grid}
    .ra-item{display:flex;gap:12px;align-items:flex-start;padding:12px 0;border-bottom:1px solid var(--pu-line,#E2EAF5);min-width:0}
    .ra-item:last-child{border-bottom:0;padding-bottom:0}.ra-item:first-child{padding-top:0}
    .ra-dot{width:38px;height:38px;flex-shrink:0;border-radius:50%;display:grid;place-items:center;font-size:18px;background:var(--pu-mist,#EEF3FA)}
    .ra-txt{flex:1;min-width:0;font-size:14px;line-height:1.45;color:var(--pu-ink,#202A44);overflow-wrap:anywhere}
    .ra-when{display:block;margin-top:2px;font-size:12.5px;color:var(--pu-muted,#5B6890)}
    body.dark .ra-txt{color:#f4f7ff}body.dark .ra-when{color:#aebbdb}
    </style>
    <?php
}

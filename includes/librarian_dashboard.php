<?php
/**
 * Librarian dashboard: an operational "today" view for the Chief Librarian.
 * Usage (admin/dashboard.php, inside <body> after navbar + header):
 *     require_once __DIR__ . '/../includes/librarian_dashboard.php';
 *     renderLibrarianDashboard($conn, getUserFullName());
 * Every query is guarded, so a missing table or column never breaks the page.
 */

function ldH($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function ldRows(mysqli $conn, string $sql): array
{
    try {
        $res = $conn->query($sql);
        return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    } catch (Throwable $e) {
        if (function_exists('logError')) logError('Librarian dashboard query error: ' . $e->getMessage());
        return [];
    }
}

function ldOne(mysqli $conn, string $sql, string $key = 'c', int $default = 0): int
{
    $rows = ldRows($conn, $sql);
    return isset($rows[0][$key]) ? (int)$rows[0][$key] : $default;
}

function ldAgo(int $ts): string
{
    $d = max(0, time() - $ts);
    if ($d < 60) return 'Just now';
    if ($d < 3600) return floor($d / 60) . ' min ago';
    if ($d < 86400) return floor($d / 3600) . ' hr ago';
    $days = (int)floor($d / 86400);
    return $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
}

function renderLibrarianDashboard(mysqli $conn, string $fullName): void
{
    $now   = time();
    $today = date('Y-m-d');
    $b     = '/LibraryBorrowingSystem/admin';

    // ---------- Numbers ----------
    $students   = ldOne($conn, "SELECT COUNT(*) AS c FROM students WHERE status='active' AND COALESCE(is_archived,0)=0");
    $teachers   = ldOne($conn, "SELECT COUNT(*) AS c FROM teachers WHERE COALESCE(is_archived,0)=0");
    $titles     = ldOne($conn, "SELECT COUNT(*) AS c FROM books WHERE COALESCE(is_archived,0)=0");
    $copies     = ldOne($conn, "SELECT COALESCE(SUM(total_copies),0) AS c FROM books WHERE COALESCE(is_archived,0)=0");
    $borrowedNow = ldOne($conn, "SELECT COUNT(*) AS c FROM transactions WHERE status='borrowed'");
    $overdueN   = ldOne($conn, "SELECT COUNT(*) AS c FROM transactions WHERE status='borrowed' AND due_date < NOW()");
    $borrowedToday = ldOne($conn, "SELECT COUNT(*) AS c FROM transactions WHERE DATE(date_borrowed)=CURDATE()");
    $returnedToday = ldOne($conn, "SELECT COUNT(*) AS c FROM transactions WHERE return_date IS NOT NULL AND DATE(return_date)=CURDATE()");
    $visitsToday = ldOne($conn, "SELECT COUNT(*) AS c FROM library_attendance WHERE visit_date=CURDATE()");
    $insideNow  = ldOne($conn, "SELECT COUNT(*) AS c FROM library_attendance WHERE visit_date=CURDATE() AND time_out IS NULL");
    $lowStockN  = ldOne($conn, "SELECT COUNT(*) AS c FROM books WHERE COALESCE(is_archived,0)=0 AND available_copies <= 2");

    // ---------- Lists ----------
    $overdue = ldRows($conn, "
        SELECT t.transaction_id, t.book_id, t.due_date,
               COALESCE(NULLIF(TRIM(b.title), ''), CONCAT('Book #', t.book_id)) AS title,
               COALESCE(NULLIF(TRIM(s.full_name), ''), NULLIF(TRIM(te.full_name), ''), 'Unknown borrower') AS borrower,
               CASE WHEN t.teacher_id IS NOT NULL THEN 'Teacher' ELSE 'Student' END AS kind,
               COALESCE(s.student_group, '') AS grp
        FROM transactions t
        LEFT JOIN books b ON b.book_id = t.book_id
        LEFT JOIN students s ON s.student_id = t.student_id
        LEFT JOIN teachers te ON te.teacher_id = t.teacher_id
        WHERE t.status='borrowed' AND t.due_date IS NOT NULL AND t.due_date < NOW()
        ORDER BY t.due_date ASC LIMIT 6");

    $lowStock = ldRows($conn, "
        SELECT title, available_copies, total_copies, library_section, shelf_number
        FROM books
        WHERE COALESCE(is_archived,0)=0 AND available_copies <= 2
        ORDER BY available_copies ASC, title ASC LIMIT 6");

    $inside = ldRows($conn, "
        SELECT a.time_in, COALESCE(s.full_name, te.full_name, 'Visitor') AS name,
               CASE WHEN a.teacher_id IS NOT NULL THEN 'Teacher' ELSE 'Student' END AS kind
        FROM library_attendance a
        LEFT JOIN students s ON s.student_id = a.student_id
        LEFT JOIN teachers te ON te.teacher_id = a.teacher_id
        WHERE a.visit_date = CURDATE() AND a.time_out IS NULL
        ORDER BY a.time_in DESC LIMIT 6");

    // Activity feed (today first, then most recent)
    $feed = [];
    foreach (ldRows($conn, "
        SELECT b.title, t.date_borrowed AS at, COALESCE(s.full_name, te.full_name, 'Unknown') AS who
        FROM transactions t INNER JOIN books b ON b.book_id=t.book_id
        LEFT JOIN students s ON s.student_id=t.student_id LEFT JOIN teachers te ON te.teacher_id=t.teacher_id
        ORDER BY t.date_borrowed DESC LIMIT 6") as $r) {
        $feed[] = ['ts' => strtotime($r['at']), 'icon' => '🕮', 'html' => '<strong>' . ldH($r['who']) . '</strong> borrowed ' . ldH($r['title'])];
    }
    foreach (ldRows($conn, "
        SELECT b.title, t.return_date AS at, COALESCE(s.full_name, te.full_name, 'Unknown') AS who
        FROM transactions t INNER JOIN books b ON b.book_id=t.book_id
        LEFT JOIN students s ON s.student_id=t.student_id LEFT JOIN teachers te ON te.teacher_id=t.teacher_id
        WHERE t.return_date IS NOT NULL ORDER BY t.return_date DESC LIMIT 6") as $r) {
        $feed[] = ['ts' => strtotime($r['at']), 'icon' => 'ꪜ', 'html' => '<strong>' . ldH($r['who']) . '</strong> returned ' . ldH($r['title'])];
    }
    foreach (ldRows($conn, "
        SELECT a.time_in AS at, COALESCE(s.full_name, te.full_name, 'Visitor') AS who
        FROM library_attendance a LEFT JOIN students s ON s.student_id=a.student_id LEFT JOIN teachers te ON te.teacher_id=a.teacher_id
        ORDER BY a.time_in DESC LIMIT 6") as $r) {
        $feed[] = ['ts' => strtotime($r['at']), 'icon' => '🟢', 'html' => '<strong>' . ldH($r['who']) . '</strong> checked in to the library'];
    }
    usort($feed, function ($a, $c) { return $c['ts'] <=> $a['ts']; });
    $feed = array_slice($feed, 0, 8);

    // 7-day chart: borrowed / returned / visits
    $days = [];
    for ($i = 6; $i >= 0; $i--) {
        $k = date('Y-m-d', strtotime("-$i day"));
        $days[$k] = ['label' => date('D', strtotime($k)), 'borrow' => 0, 'ret' => 0, 'visit' => 0];
    }
    foreach (ldRows($conn, "SELECT DATE(date_borrowed) AS d, COUNT(*) AS c FROM transactions WHERE date_borrowed >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY d") as $r) {
        if (isset($days[$r['d']])) $days[$r['d']]['borrow'] = (int)$r['c'];
    }
    foreach (ldRows($conn, "SELECT DATE(return_date) AS d, COUNT(*) AS c FROM transactions WHERE return_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY d") as $r) {
        if (isset($days[$r['d']])) $days[$r['d']]['ret'] = (int)$r['c'];
    }
    foreach (ldRows($conn, "SELECT visit_date AS d, COUNT(*) AS c FROM library_attendance WHERE visit_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY d") as $r) {
        if (isset($days[$r['d']])) $days[$r['d']]['visit'] = (int)$r['c'];
    }
    $chartMax = 1;
    foreach ($days as $d) $chartMax = max($chartMax, $d['borrow'], $d['ret'], $d['visit']);
    $chartSum = 0;
    foreach ($days as $d) $chartSum += $d['borrow'] + $d['ret'] + $d['visit'];

    // Top books this month
    $top = ldRows($conn, "
        SELECT b.title, COUNT(*) AS c
        FROM transactions t INNER JOIN books b ON b.book_id = t.book_id
        WHERE t.date_borrowed >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
        GROUP BY b.book_id, b.title ORDER BY c DESC, b.title ASC LIMIT 5");
    $topMax = $top ? max(1, (int)$top[0]['c']) : 1;

    $hour = (int)date('G', $now);
    $greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
    $first = trim(explode(' ', trim($fullName))[0]);
    ?>
<style id="ld-css">
.ld{--ink:#1d2233;--mute:#6a7186;--line:#e3e6ec;--navy:#141F52;--red:#9B2335;--amber:#8a6a00;--green:#3f6b1a;
    max-width:1200px;margin:0 auto;padding:0 20px 24px;display:grid;gap:22px;font-family:'Inter','Segoe UI',system-ui,-apple-system,Roboto,sans-serif;color:var(--ink);line-height:1.5}
.ld *{box-sizing:border-box}
.ld a{color:inherit;text-decoration:none}
.ld-card{min-width:0}
.ld-card h2{margin:0;font-size:15px;font-weight:600;color:var(--ink)}
.ld-head{display:flex;align-items:baseline;justify-content:space-between;gap:12px;padding-bottom:10px;margin-bottom:4px;border-bottom:1px solid var(--ink)}
.ld-head a{font-size:13px;color:var(--mute);white-space:nowrap}
.ld-head a:hover{color:var(--navy);text-decoration:underline}
.ld-pill{margin-left:8px;font-size:13px;font-weight:400;color:var(--mute)}
.ld-pill.red{color:var(--red)}

.ld-hero{display:flex;align-items:flex-end;justify-content:space-between;gap:24px;flex-wrap:wrap;padding-top:20px}
.ld-date{font-size:13px;color:var(--mute)}
.ld-hero h1{margin:6px 0 8px;font-size:clamp(26px,4.4vw,38px);font-weight:600;letter-spacing:-.6px;line-height:1.1;color:var(--ink);overflow-wrap:anywhere}
.ld-hero p{margin:0;font-size:15px;color:var(--mute)}
.ld .ld-hero p strong{color:var(--red) !important;font-weight:600}
.ld-actions{display:flex;gap:10px;flex-wrap:wrap}
.ld-btn{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:0 18px;border-radius:6px;font-weight:600;font-size:14px;border:1px solid var(--line);color:var(--ink) !important;background:#fff;transition:border-color .15s}
.ld-btn:hover{border-color:var(--ink)}
.ld-btn.primary{background:var(--navy);border-color:var(--navy);color:#fff !important}
.ld-btn.primary:hover{background:#0d1538}

.ld-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));border-top:1px solid var(--line);border-left:1px solid var(--line)}
.ld .ld-stat{display:block;padding:20px 22px;border-right:1px solid var(--line);border-bottom:1px solid var(--line)}
.ld a.ld-stat:hover .v{color:var(--navy);text-decoration:underline}
.ld-stat .l{font-size:13px;color:var(--mute)}
.ld-stat .v{font-size:clamp(28px,4vw,36px);font-weight:600;letter-spacing:-.5px;line-height:1.1;margin-top:6px}
.ld-stat .v small{font-size:14px;font-weight:400;color:var(--mute)}
.ld-stat .s{margin-top:6px;font-size:12.5px;color:var(--mute)}
.ld-stat.bad .v{color:var(--red)}

.ld-grid{display:grid;grid-template-columns:minmax(0,1.45fr) minmax(0,1fr);gap:28px;align-items:start}
.ld-col{display:grid;gap:20px;min-width:0}
.ld-list{list-style:none;margin:0;padding:0}
.ld-row{display:flex;align-items:center;gap:12px;padding:13px 0;border-bottom:1px solid var(--line);min-width:0}
.ld-row .body{flex:1;min-width:0}
.ld-row .t{font-weight:600;font-size:14.5px;overflow-wrap:anywhere}
.ld-row .t span{font-weight:400 !important;color:var(--mute) !important}
.ld-row .m{margin-top:1px;font-size:13px;color:var(--mute);overflow-wrap:anywhere}
.ld-badge{font-size:13px;font-weight:600;white-space:nowrap;color:var(--navy)}
.ld-badge.bad{color:var(--red)}.ld-badge.warn{color:var(--amber)}.ld-badge.good{color:var(--green)}
.ld-empty{padding:18px 0;color:var(--mute);font-size:14px}

.ld-feed{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));column-gap:28px}
.ld-feed li{padding:9px 0;border-bottom:1px solid var(--line);min-width:0}
.ld-feed .dot{display:none}
.ld-feed .tx{font-size:14px;overflow-wrap:anywhere}
.ld-feed .w{display:block;margin-top:2px;font-size:12.5px;color:var(--mute)}

.ld-chart{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:14px;align-items:end;height:145px;padding-top:12px}
.ld-day{display:flex;flex-direction:column;justify-content:flex-end;align-items:center;height:100%;gap:8px;min-width:0}
.ld-bars{display:flex;align-items:flex-end;justify-content:center;gap:3px;width:100%;flex:1;min-height:0}
.ld-bars i{display:block;flex:1;max-width:12px;min-height:2px;border-radius:1px}
.ld-bars .b{background:#141F52}.ld-bars .r{background:#4a8a1c}.ld-bars .v{background:#91B0E0}
.ld-day span{font-size:12px;color:var(--mute)}
.ld-day.today span{color:var(--ink);font-weight:600}
.ld-legend{display:flex;flex-wrap:wrap;gap:6px 18px;margin-top:14px;font-size:12.5px;color:var(--mute)}
.ld-legend i{display:inline-block;width:8px;height:8px;margin-right:6px}

.ld-top{list-style:none;margin:0;padding:0}
.ld-top li{padding:11px 0;border-bottom:1px solid var(--line)}
.ld-top .n{display:flex;justify-content:space-between;gap:10px;font-size:14px}
.ld-top .n span:last-child{color:var(--mute);flex-shrink:0}
.ld-top .bar{height:2px;background:var(--line);margin-top:8px}
.ld-top .bar i{display:block;height:100%;background:var(--navy)}

.ld-title{font-size:15px;font-weight:600;padding-bottom:10px;border-bottom:1px solid var(--ink);margin-bottom:-24px}
.ld-title small{font-size:13px;font-weight:400;color:var(--mute);margin-left:10px}
.ld-mods{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,260px),1fr));column-gap:32px}
.ld-mod{display:block;padding:14px 0;border-bottom:1px solid var(--line)}
.ld-mod b{display:block;font-size:14.5px;font-weight:600}
.ld-mod span.d{display:block;margin-top:2px;font-size:13px;color:var(--mute)}
.ld-mod:hover b{color:var(--navy);text-decoration:underline}
.ld a:focus-visible{outline:2px solid var(--navy);outline-offset:3px}

@media (max-width:1000px){.ld-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.ld-grid{grid-template-columns:1fr;gap:20px}}
@media (max-width:600px){.ld{gap:32px}.ld-actions{width:100%}.ld-btn{flex:1 1 calc(50% - 5px)}.ld-btn.primary{flex-basis:100%}.ld .ld-stat{padding:16px}.ld-chart{height:140px;gap:8px}}
@media (prefers-reduced-motion:reduce){.ld *{transition:none !important}}

/* ── character layer: notebook paper, ledger numerals, stamped tags ── */
.ld{--rule:#D2E2F6;--hl:#F4F916;gap:22px}
.ld-card{background:#fff;border:1px solid var(--rule);border-radius:10px;padding:clamp(16px,2.2vw,24px)}
.ld-head{border-bottom:1.5px solid var(--navy);padding-bottom:10px;margin-bottom:2px}
.ld-card h2{font-size:18px;font-weight:700;color:var(--navy)}
.ld-pill{display:inline-block;margin-left:10px;padding:0 8px;border:1.5px solid currentColor;border-radius:4px;font-size:12px;font-weight:600;line-height:18px;font-family:inherit;color:var(--navy);vertical-align:2px}
.ld-pill.red{color:var(--red)}

.ld .ld-hero{--m:60px;align-items:center;padding:30px 28px 30px calc(var(--m) + 26px);border:1px solid var(--rule);border-radius:10px;}
.ld-date{color:#5B6890}
.ld-hero h1{font-weight:700;font-size:clamp(26px,4.4vw,38px);letter-spacing:-.5px;color:var(--navy)}
.ld-hero p{color:#3b466b}
.ld-btn{border:1.5px solid var(--navy);background:#fff;color:var(--navy) !important;border-radius:6px}
.ld-btn:hover{background:var(--hl)}
.ld-btn.primary{background:var(--hl);border-color:var(--navy);color:var(--navy) !important}
.ld-btn.primary:hover{background:var(--navy);color:#fff !important}

.ld-stats{border:0;gap:12px}
.ld .ld-stat{background:#fff;border:1px solid var(--rule);border-radius:10px;padding:16px 18px}
.ld a.ld-stat:hover{border-color:#000;box-shadow:none;background:#fff}
.ld a.ld-stat:hover .v{text-decoration:none;color:#000 !important}
.ld a.ld-stat:hover .l,.ld a.ld-stat:hover .s{color:#000 !important}
.ld-stat .l{color:#5B6890;font-size:13px}
.ld-stat .v{font-weight:700;color:var(--navy)}
.ld-stat.bad .v{color:var(--red)}

.ld-row,.ld-feed li,.ld-top li,.ld-mod{border-bottom-style:dashed;border-bottom-color:#b9cbe6}
.ld-badge{padding:1px 9px;border:1.5px solid currentColor;border-radius:4px;font-size:12px;line-height:18px}
.ld-top .bar{height:5px;border-radius:3px;background:#E7EEF7}
.ld-top .bar i{border-radius:3px}
.ld-bars i{border-radius:3px 3px 0 0}

.ld-title{margin:4px 0 0;padding:0;border:0;font-size:21px;font-weight:700;color:var(--navy)}
.ld-mods{gap:12px}
.ld-mod{padding:14px 16px;background:#fff;border:1px solid var(--rule);border-radius:10px}
.ld-mod:hover{border-color:var(--navy);box-shadow:none}
.ld-mod:hover b{text-decoration:none}
@media (max-width:600px){.ld .ld-hero{--m:40px;padding:24px 18px 24px calc(var(--m) + 16px)}.ld-chart{height:135px}.ld-col{gap:16px}.ld-feed{grid-template-columns:1fr}}
</style>

<main class="ld" aria-label="Librarian dashboard">

    <!-- Hero -->
    <div class="ld-card ld-hero">
        <div>
            <div class="ld-date"><?php echo ldH(date('l, F j, Y')); ?></div>
            <h1><?php echo ldH($greeting . ', ' . $first); ?>!</h1>
            <p><?php echo (int)$insideNow; ?> visitor<?php echo $insideNow === 1 ? '' : 's'; ?> inside right now ·
               <?php echo (int)$borrowedNow; ?> book<?php echo $borrowedNow === 1 ? '' : 's'; ?> out on loan<?php echo $overdueN > 0 ? ' · <strong style="color:#F4F916">' . (int)$overdueN . ' overdue</strong>' : ''; ?></p>
        </div>
        <div class="ld-actions">
            <a class="ld-btn primary" href="<?php echo $b; ?>/qr_transaction.php">Borrow / Return</a>
            <a class="ld-btn" href="<?php echo $b; ?>/attendance.php">Time In/Time Out</a>
            <a class="ld-btn" href="<?php echo $b; ?>/inventory.php">Add book</a>
        </div>
    </div>

    <!-- Numbers -->
    <div class="ld-stats">
        <a class="ld-card ld-stat <?php echo $overdueN > 0 ? 'bad' : 'good'; ?>" href="<?php echo $b; ?>/transactions.php">
            <div class="l">Overdue loans</div>
            <div class="v"><?php echo $overdueN; ?></div>
            <div class="s"><?php echo $overdueN > 0 ? 'Needs follow-up' : 'Nothing overdue'; ?></div>
        </a>
        <a class="ld-card ld-stat" href="<?php echo $b; ?>/transactions.php">
            <div class="l">On loan now</div>
            <div class="v"><?php echo $borrowedNow; ?></div>
            <div class="s"><?php echo $borrowedToday; ?> borrowed · <?php echo $returnedToday; ?> returned today</div>
        </a>
        <a class="ld-card ld-stat" href="<?php echo $b; ?>/attendance.php">
            <div class="l">Visits today</div>
            <div class="v"><?php echo $visitsToday; ?></div>
            <div class="s"><?php echo $insideNow; ?> still inside</div>
        </a>
        <a class="ld-card ld-stat <?php echo $lowStockN > 0 ? 'warn' : 'good'; ?>" href="<?php echo $b; ?>/inventory.php">
            <div class="l">Low stock titles</div>
            <div class="v"><?php echo $lowStockN; ?></div>
            <div class="s">2 or fewer copies available</div>
        </a>
        <a class="ld-card ld-stat" href="<?php echo $b; ?>/student_records.php">
            <div class="l">Students</div>
            <div class="v"><?php echo $students; ?></div>
            <div class="s">Active accounts</div>
        </a>
        <a class="ld-card ld-stat" href="<?php echo $b; ?>/student_records.php">
            <div class="l">Teachers</div>
            <div class="v"><?php echo $teachers; ?></div>
            <div class="s">Registered accounts</div>
        </a>
        <a class="ld-card ld-stat" href="<?php echo $b; ?>/inventory.php">
            <div class="l">Book titles</div>
            <div class="v"><?php echo $titles; ?></div>
            <div class="s"><?php echo $copies; ?> total copies</div>
        </a>
        <a class="ld-card ld-stat" href="<?php echo $b; ?>/reports.php">
            <div class="l">Reports</div>
            <div class="v" style="font-size:22px;margin-top:10px">View →</div>
            <div class="s">Trends, usage and exports</div>
        </a>
    </div>

    <!-- Main -->
    <div class="ld-grid">
        <div class="ld-col">
            <!-- Overdue -->
            <div class="ld-card">
                <div class="ld-head">
                    <h2>Overdue loans<?php if ($overdueN): ?><span class="ld-pill red"><?php echo $overdueN; ?></span><?php endif; ?></h2>
                    <a href="<?php echo $b; ?>/transactions.php">All transactions →</a>
                </div>
                <?php if (!$overdue): ?>
                    <div class="ld-empty">No overdue books. Everything is on schedule.</div>
                <?php else: ?>
                <ul class="ld-list">
                    <?php foreach ($overdue as $o):
                        $dueTs = strtotime($o['due_date']);
                        $late  = max(1, (int)ceil(($now - $dueTs) / 86400));
                    ?>
                    <li class="ld-row bad">
                        <div class="body">
                            <div class="t"><?php echo ldH($o['borrower']); ?> <span style="font-weight:600;color:#5B6890">· <?php echo ldH($o['kind'] . ($o['grp'] ? ' ' . $o['grp'] : '')); ?></span></div>
                            <div class="m"><?php echo ldH($o['title']); ?> · due <?php echo ldH(date('M j', $dueTs)); ?></div>
                        </div>
                        <span class="ld-badge bad"><?php echo $late; ?> day<?php echo $late === 1 ? '' : 's'; ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($overdueN > count($overdue)): ?><div class="ld-empty" style="padding-bottom:0">+ <?php echo $overdueN - count($overdue); ?> more in Transaction Records</div><?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Chart -->
            <div class="ld-card">
                <div class="ld-head"><h2>Last 7 days</h2><a href="<?php echo $b; ?>/reports.php">Full reports →</a></div>
                <?php if ($chartSum === 0): ?>
                    <div class="ld-empty">Activity will appear here once books are borrowed or visitors check in.</div>
                <?php else: ?>
                <div class="ld-chart" role="img" aria-label="Books borrowed, books returned and visits for each of the last seven days">
                    <?php foreach ($days as $k => $d): ?>
                    <div class="ld-day<?php echo $k === $today ? ' today' : ''; ?>">
                        <div class="ld-bars">
                            <i class="b" title="Borrowed: <?php echo (int)$d['borrow']; ?>" style="height:<?php echo $d['borrow'] ? max(5, round($d['borrow'] / $chartMax * 100)) : 2; ?>%"></i>
                            <i class="r" title="Returned: <?php echo (int)$d['ret']; ?>" style="height:<?php echo $d['ret'] ? max(5, round($d['ret'] / $chartMax * 100)) : 2; ?>%"></i>
                            <i class="v" title="Visits: <?php echo (int)$d['visit']; ?>" style="height:<?php echo $d['visit'] ? max(5, round($d['visit'] / $chartMax * 100)) : 2; ?>%"></i>
                        </div>
                        <span><?php echo ldH($k === $today ? 'Today' : $d['label']); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="ld-legend">
                    <span><i style="background:#141F52"></i>Borrowed (<?php echo array_sum(array_column($days, 'borrow')); ?>)</span>
                    <span><i style="background:#4a8a1c"></i>Returned (<?php echo array_sum(array_column($days, 'ret')); ?>)</span>
                    <span><i style="background:#91B0E0"></i>Visits (<?php echo array_sum(array_column($days, 'visit')); ?>)</span>
                </div>
                <?php endif; ?>
            </div>

        </div>

        <div class="ld-col">
            <!-- Inside now -->
            <div class="ld-card">
                <div class="ld-head">
                    <h2>In the library now<span class="ld-pill"><?php echo $insideNow; ?></span></h2>
                    <a href="<?php echo $b; ?>/attendance.php">Time In/Time Out →</a>
                </div>
                <?php if (!$inside): ?>
                    <div class="ld-empty">No one is checked in right now.</div>
                <?php else: ?>
                <ul class="ld-list">
                    <?php foreach ($inside as $v): ?>
                    <li class="ld-row">
                        <div class="body"><div class="t"><?php echo ldH($v['name']); ?></div><div class="m"><?php echo ldH($v['kind']); ?> · in at <?php echo ldH(date('g:i A', strtotime($v['time_in']))); ?></div></div>
                        <span class="ld-badge good">Inside</span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>

            <!-- Low stock -->
            <div class="ld-card">
                <div class="ld-head">
                    <h2>Low stock<?php if ($lowStockN): ?><span class="ld-pill"><?php echo $lowStockN; ?></span><?php endif; ?></h2>
                    <a href="<?php echo $b; ?>/inventory.php">Catalogue →</a>
                </div>
                <?php if (!$lowStock): ?>
                    <div class="ld-empty">All titles have enough copies.</div>
                <?php else: ?>
                <ul class="ld-list">
                    <?php foreach ($lowStock as $l): $out = (int)$l['available_copies'] <= 0; ?>
                    <li class="ld-row <?php echo $out ? 'bad' : 'warn'; ?>">
                        <div class="body">
                            <div class="t"><?php echo ldH($l['title']); ?></div>
                            <div class="m"><?php echo (int)$l['available_copies']; ?> of <?php echo (int)$l['total_copies']; ?> available<?php $loc = array_filter([trim((string)$l['library_section']), trim((string)$l['shelf_number']) !== '' ? 'Shelf ' . trim((string)$l['shelf_number']) : '']); echo $loc ? ' · ' . ldH(implode(' · ', $loc)) : ''; ?></div>
                        </div>
                        <span class="ld-badge <?php echo $out ? 'bad' : 'warn'; ?>"><?php echo $out ? 'Out' : 'Low'; ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>

            <!-- Top books -->
            <div class="ld-card">
                <div class="ld-head"><h2>Most borrowed this month</h2></div>
                <?php if (!$top): ?>
                    <div class="ld-empty">No borrowing recorded this month yet.</div>
                <?php else: ?>
                <ol class="ld-top">
                    <?php foreach ($top as $t): ?>
                    <li>
                        <div class="n"><span><?php echo ldH($t['title']); ?></span><span><?php echo (int)$t['c']; ?>×</span></div>
                        <div class="bar"><i style="width:<?php echo max(6, round($t['c'] / $topMax * 100)); ?>%"></i></div>
                    </li>
                    <?php endforeach; ?>
                </ol>
                <?php endif; ?>
            </div>
        </div>
    </div>

            <!-- Activity -->
            <div class="ld-card">
                <div class="ld-head"><h2>Latest activity</h2></div>
                <?php if (!$feed): ?>
                    <div class="ld-empty">No activity yet.</div>
                <?php else: ?>
                <ul class="ld-feed">
                    <?php foreach ($feed as $f): ?>
                    <li><div class="dot"><?php echo $f['icon']; ?></div><div class="tx"><?php echo $f['html']; ?><span class="w"><?php echo ldH(ldAgo((int)$f['ts'])); ?> · <?php echo ldH(date('M j, g:i A', (int)$f['ts'])); ?></span></div></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>


    <!-- Modules -->
    <div class="ld-title">Manage <small>Jump to any part of the system</small></div>
    <div class="ld-mods">
        <a class="ld-mod" href="<?php echo $b; ?>/qr_transaction.php"><span><b>Borrow / Return</b><span class="d">Scan QR codes to lend and receive books.</span></span></a>
        <a class="ld-mod" href="<?php echo $b; ?>/attendance.php"><span><b>Time In/Time Out</b><span class="d">Time in/out and visit reports.</span></span></a>
        <a class="ld-mod" href="<?php echo $b; ?>/inventory.php"><span><b>Catalogue</b><span class="d">Add books, QR codes and stock levels.</span></span></a>
        <a class="ld-mod" href="<?php echo $b; ?>/transactions.php"><span><b>Transaction Records</b><span class="d">Every borrow and return in detail.</span></span></a>
        <a class="ld-mod" href="<?php echo $b; ?>/student_records.php"><span><b>User Management</b><span class="d">Students, teachers and their history.</span></span></a>
        <a class="ld-mod" href="<?php echo $b; ?>/reports.php"><span><b>Reports &amp; Analytics</b><span class="d">Trends, usage and exports.</span></span></a>
        <a class="ld-mod" href="<?php echo $b; ?>/staff_management.php"><span><b>Staff Management</b><span class="d">Staff accounts and passwords.</span></span></a>
        <a class="ld-mod" href="<?php echo $b; ?>/backup_management.php"><span><b>Backup &amp; Restore</b><span class="d">Protected database backups.</span></span></a>
    </div>
</main>
<?php
}

<?php
/**
 * Shared "My Library" dashboard for the Student and Teacher portals.
 *
 * Usage (inside the page body, after the header):
 *   require_once __DIR__ . '/../includes/portal_dashboard.php';
 *   renderPortalDashboard($conn, 'student', $student, $max_active_books);
 *
 * Shows: greeting + library ID QR, alerts (overdue / due today),
 * key numbers, currently borrowed books,
 * 6-month borrowing chart and latest notifications.
 * Every query is wrapped so a missing table/column never breaks the page.
 */

function pdH($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function pdRows(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    try {
        $stmt = $conn->prepare($sql);
        if (!$stmt) return [];
        if ($types !== '') $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
        return $rows;
    } catch (Throwable $e) {
        if (function_exists('logError')) logError('Portal dashboard query error: ' . $e->getMessage());
        return [];
    }
}

function pdAgo(int $ts): string
{
    $diff = max(0, time() - $ts);
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hr ago';
    if ($diff < 86400 * 7) {
        $d = (int)floor($diff / 86400);
        return $d . ' day' . ($d === 1 ? '' : 's') . ' ago';
    }
    return date('M j, Y', $ts);
}

/** @return array{0:string,1:string,2:int} [css class, label, days overdue] */
function pdDueInfo(int $dueTs): array
{
    $now = time();
    $today = date('Y-m-d', $now);
    if ($now > $dueTs) {
        $days = max(1, (int)ceil(($now - $dueTs) / 86400));
        return ['danger', 'Overdue · ' . $days . ' day' . ($days === 1 ? '' : 's'), $days];
    }
    if (date('Y-m-d', $dueTs) === $today) return ['warn', 'Due today', 0];
    $days = (int)ceil(($dueTs - $now) / 86400);
    return ['ok', 'Due in ' . $days . ' day' . ($days === 1 ? '' : 's'), 0];
}

function pdListFromJson($value): string
{
    $value = trim((string)$value);
    if ($value === '') return '';
    $decoded = json_decode($value, true);
    if (is_array($decoded)) return implode(', ', array_map('strval', $decoded));
    return $value;
}

function renderPortalDashboard(mysqli $conn, string $role, ?array $user, int $maxActive): void
{
    if (!$user) return;

    $role   = $role === 'teacher' ? 'teacher' : 'student';
    $col    = $role === 'teacher' ? 'teacher_id' : 'student_id';
    $uid    = (int)$user[$col];
    $base   = '/LibraryBorrowingSystem/' . $role;
    $qrCode = trim((string)($user['qr_code'] ?? ''));
    $now    = time();

    // ---------- Profile line ----------
    $idNumber = $role === 'teacher' ? ($user['teacher_no'] ?? '') : ($user['student_no'] ?? '');
    $idLabel  = $role === 'teacher' ? 'Faculty ID' : 'Student ID';
    $chips    = [];
    $extra = pdRows($conn,
        $role === 'teacher'
            ? "SELECT teaching_grades, teaching_strands FROM teachers WHERE teacher_id = ? LIMIT 1"
            : "SELECT student_group, department, year_level FROM students WHERE student_id = ? LIMIT 1",
        'i', [$uid]);
    if (!empty($extra[0])) {
        $x = $extra[0];
        if ($role === 'teacher') {
            $g = pdListFromJson($x['teaching_grades'] ?? '');
            $s = pdListFromJson($x['teaching_strands'] ?? '');
            if ($g !== '') $chips[] = $g;
            if ($s !== '') $chips[] = $s;
        } else {
            if (!empty($x['year_level'])) $chips[] = $x['year_level'];
            if (!empty($x['student_group'])) $chips[] = $x['student_group'];
            if (!empty($x['department']) && in_array($x['year_level'] ?? '', ['Grade 11', 'Grade 12'], true)) $chips[] = $x['department'];
        }
    }

    // ---------- Data ----------
    $loans = pdRows($conn, "
        SELECT t.transaction_id, t.date_borrowed, t.due_date, b.title, b.author,
               COALESCE(c.book_number,b.book_number) AS book_number,
               COALESCE(c.qr_code,b.qr_code) AS qr_code,
               t.copy_id, b.library_section, b.shelf_number
        FROM transactions t
        INNER JOIN books b ON b.book_id = t.book_id
        LEFT JOIN book_copies c ON c.copy_id = t.copy_id
        WHERE t.$col = ? AND t.status = 'borrowed'
        ORDER BY t.due_date ASC", 'i', [$uid]);

    $totals = pdRows($conn, "
        SELECT COUNT(*) AS total,
               SUM(CASE WHEN status = 'returned' THEN 1 ELSE 0 END) AS returned,
               SUM(CASE WHEN status = 'returned' AND return_date IS NOT NULL AND return_date > due_date THEN 1 ELSE 0 END) AS late
        FROM transactions WHERE $col = ?", 'i', [$uid]);
    $totalBorrowed = (int)($totals[0]['total'] ?? 0);
    $totalReturned = (int)($totals[0]['returned'] ?? 0);
    $totalLate     = (int)($totals[0]['late'] ?? 0);
    $onTimePct     = $totalReturned > 0 ? (int)round((($totalReturned - $totalLate) / $totalReturned) * 100) : null;


    $visitRow   = pdRows($conn, "SELECT COUNT(*) AS c FROM library_attendance WHERE $col = ? AND visit_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')", 'i', [$uid]);
    $visitsMonth = (int)($visitRow[0]['c'] ?? 0);
    $lastVisit  = pdRows($conn, "SELECT time_in, time_out FROM library_attendance WHERE $col = ? ORDER BY time_in DESC LIMIT 1", 'i', [$uid]);
    $insideNow  = !empty($lastVisit[0]) && empty($lastVisit[0]['time_out']) && date('Y-m-d', strtotime($lastVisit[0]['time_in'])) === date('Y-m-d');

    // 6-month chart
    $monthly = [];
    for ($i = 5; $i >= 0; $i--) {
        $mTs = mktime(0, 0, 0, (int)date('n') - $i, 1, (int)date('Y'));
        $monthly[date('Y-m', $mTs)] = ['label' => date('M', $mTs), 'count' => 0];
    }
    $mRows = pdRows($conn, "
        SELECT DATE_FORMAT(date_borrowed, '%Y-%m') AS ym, COUNT(*) AS c
        FROM transactions
        WHERE $col = ? AND date_borrowed >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 5 MONTH)
        GROUP BY ym", 'i', [$uid]);
    foreach ($mRows as $r) {
        if (isset($monthly[$r['ym']])) $monthly[$r['ym']]['count'] = (int)$r['c'];
    }
    $chartMax = max(1, max(array_column($monthly, 'count')));
    $chartSum = array_sum(array_column($monthly, 'count'));

    // ---------- Derived ----------
    $overdue = 0; $dueToday = 0;
    foreach ($loans as &$loan) {
        $loan['_due'] = pdDueInfo(strtotime($loan['due_date']));
        if ($loan['_due'][0] === 'danger') $overdue++;
        elseif ($loan['_due'][0] === 'warn') $dueToday++;
    }
    unset($loan);

    $hour = (int)date('G', $now);
    $greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
    $firstName = trim(explode(' ', trim((string)$user['full_name']))[0]);
    $loanCount = count($loans);
    $slotPct = $maxActive > 0 ? min(100, (int)round(($loanCount / $maxActive) * 100)) : 0;
    $qrDownload = $role === 'teacher' ? '/LibraryBorrowingSystem/teacher/download_qr.php' : '/LibraryBorrowingSystem/student/download_qr.php';
    $qrImage = '/LibraryBorrowingSystem/qr_codes/' . rawurlencode($qrCode) . '.png';
    $cardLogo = '/LibraryBorrowingSystem/Img/jAbadSantos_Logo.jpg';
    $cardPrint = '/LibraryBorrowingSystem/print_library_card.php?type=' . rawurlencode($role);
    $borrowerWord = $role === 'teacher' ? 'faculty' : 'student';
    ?>
<style id="portal-dashboard-css">
.pd-wrap{display:grid;gap:36px;margin-bottom:32px;--pd-card:var(--pu-card,#fff);--pd-line:var(--pu-line,#e3e6ec);--pd-ink:var(--pu-ink,#1d2233);--pd-muted:var(--pu-muted,#6a7186);--pd-navy:var(--pu-navy,#141F52);--pd-red:var(--pu-red,#9B2335);font-family:'Inter','Segoe UI',system-ui,-apple-system,Roboto,sans-serif;line-height:1.5}
.pd-wrap *{box-sizing:border-box}
.pd-card{min-width:0;color:var(--pd-ink)}
.pd-card h2{margin:0;font-size:15px;font-weight:600;color:var(--pd-ink)}
.pd-card-head{display:flex;align-items:baseline;justify-content:space-between;gap:12px;padding-bottom:10px;margin-bottom:4px;border-bottom:1px solid var(--pd-ink)}
.pd-card-head a,.pd-link{font-size:13px;color:var(--pd-muted);text-decoration:none;white-space:nowrap}
.pd-card-head a:hover{color:var(--pd-navy);text-decoration:underline}
.pd-count{margin-left:8px;font-size:13px;font-weight:400;color:var(--pd-muted)}

.pd-hero{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:28px;align-items:center;padding-top:12px}
.pd-date{font-size:13px;color:var(--pd-muted)}
.pd-hero h1{margin:6px 0 10px;font-size:clamp(26px,4.6vw,38px);font-weight:600;letter-spacing:-.6px;line-height:1.1;color:var(--pd-ink) !important;overflow-wrap:anywhere}
.pd-chips{display:flex;flex-wrap:wrap;gap:2px 18px;margin-bottom:20px;font-size:14px;color:var(--pd-muted)}
.pd-chip{overflow-wrap:anywhere}
.pd-chip.id{color:var(--pd-muted);font-weight:600}
.pd-actions{display:flex;flex-wrap:wrap;gap:10px}
.pd-btn{display:inline-flex;align-items:center;justify-content:center;min-height:42px;padding:0 18px;border-radius:6px;border:1px solid var(--pd-line);cursor:pointer;font:600 14px/1 'Inter','Segoe UI',system-ui,-apple-system,Roboto,sans-serif;text-decoration:none;background:transparent;color:var(--pd-ink);transition:border-color .15s}
.pd-btn:hover{border-color:var(--pd-ink)}
.pd-btn.primary{background:var(--pd-navy);border-color:var(--pd-navy);color:#fff}
.pd-idcard{background:#fff;border:1px solid var(--pd-line);border-radius:8px;padding:12px;text-align:center;width:164px}
.pd-idcard .qr-zoomable{display:block;width:100%}
.pd-idcard img{width:100%;height:auto;aspect-ratio:1/1;background:#fff}
.pd-idcard .qr-tap-note{color:#6a7186;margin-top:8px;font-size:12px}
.pd-idcard .pd-idcode{margin-top:2px;font-size:11px;color:#1d2233;overflow-wrap:anywhere}

.pd-alerts{display:grid;gap:0}
.pd-alert{display:block;padding:12px 0;font-size:14.5px;border-top:1px solid var(--pd-line);border-bottom:1px solid var(--pd-line);color:var(--pd-ink)}
.pd-alert+.pd-alert{border-top:0}
.pd-alert .ico{display:none}
.pd-alert.danger{color:var(--pd-red)}
.pd-alert.warn{color:#8a6a00}
body.dark .pd-alert.warn{color:#ffe99a}
.pd-alert.ok,.pd-alert.info{color:var(--pd-muted)}
.pd-alert strong{font-weight:600}

.pd-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));border-top:1px solid var(--pd-line);border-left:1px solid var(--pd-line)}
.pd-wrap .pd-stat{display:block;padding:20px 22px;border-right:1px solid var(--pd-line);border-bottom:1px solid var(--pd-line);text-decoration:none}
.pd-stat .lbl{font-size:13px;color:var(--pd-muted)}
.pd-stat .val{font-size:clamp(28px,4vw,36px);font-weight:600;letter-spacing:-.5px;line-height:1.1;margin-top:6px;color:var(--pd-ink)}
.pd-stat .val small{font-size:14px;font-weight:400;color:var(--pd-muted)}
.pd-stat .sub{margin-top:6px;font-size:12.5px;color:var(--pd-muted)}
.pd-bar{height:2px;background:var(--pd-line);margin-top:10px}
.pd-bar>span{display:block;height:100%;background:var(--pd-navy)}
.pd-bar.full>span{background:var(--pd-red)}

.pd-main{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(0,1fr);gap:48px;align-items:start}
.pd-col{display:grid;gap:40px;min-width:0}
.pd-list{margin:0;padding:0;list-style:none}
.pd-item{display:flex;align-items:center;gap:14px;padding:14px 0;border-bottom:1px solid var(--pd-line);min-width:0}
.pd-item .ico{display:none}
.pd-item .body{flex:1;min-width:0}
.pd-item .ttl{font-weight:600;font-size:14.5px;overflow-wrap:anywhere;color:var(--pd-ink)}
.pd-item .meta{margin-top:2px;font-size:13px;color:var(--pd-muted);overflow-wrap:anywhere}
.pd-item .side{display:flex;flex-direction:column;align-items:flex-end;gap:8px;flex-shrink:0}
.pd-badge{font-size:13px;font-weight:600;white-space:nowrap}
.pd-badge.danger{color:var(--pd-red)}.pd-badge.warn{color:#8a6a00}.pd-badge.ok{color:#3f6b1a}.pd-badge.info{color:var(--pd-navy)}
body.dark .pd-badge.warn{color:#ffe99a}body.dark .pd-badge.ok{color:#a9d37a}body.dark .pd-badge.info{color:#f4f7ff}
.pd-mini{display:inline-flex;align-items:center;min-height:32px;padding:0 12px;border-radius:6px;border:1px solid var(--pd-line);background:transparent;color:var(--pd-ink);font:600 12.5px/1 'Inter','Segoe UI',system-ui,-apple-system,Roboto,sans-serif;cursor:pointer}
.pd-mini:hover{border-color:var(--pd-ink)}
.pd-empty{padding:18px 0;color:var(--pd-muted);font-size:14px}
.pd-empty .big{display:none}
.pd-note{margin:12px 0 0;font-size:13px;color:var(--pd-muted)}

.pd-chart{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:12px;align-items:end;height:150px;padding-top:12px}
.pd-bar-col{display:flex;flex-direction:column;align-items:center;justify-content:flex-end;height:100%;gap:6px;min-width:0}
.pd-bar-col .n{font-size:12.5px;color:var(--pd-ink)}
.pd-bar-col .b{width:100%;max-width:30px;background:var(--pd-navy);min-height:2px;border-radius:1px}
.pd-bar-col .b.zero{background:var(--pd-line)}
.pd-bar-col .m{font-size:12px;color:var(--pd-muted)}

.pd-section-title{display:flex;align-items:baseline;gap:10px;font-size:15px;font-weight:600;padding-bottom:10px;border-bottom:1px solid var(--pd-ink);margin-bottom:-20px;color:var(--pd-ink)}
.pd-section-title small{font-size:13px;font-weight:400;color:var(--pd-muted)}
.pd-wrap a:focus-visible,.pd-wrap button:focus-visible{outline:2px solid var(--pd-navy);outline-offset:3px}

@media (max-width:900px){.pd-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.pd-main{grid-template-columns:1fr;gap:40px}}
@media (max-width:600px){
    .pd-wrap{gap:30px}
    .pd-hero{grid-template-columns:1fr;gap:20px}
    .pd-idcard{width:100%;display:grid;grid-template-columns:96px 1fr;gap:12px;align-items:center;text-align:left;padding:10px}
    .pd-actions .pd-btn{flex:1 1 calc(50% - 5px)}
    .pd-wrap .pd-stat{padding:16px}
    .pd-item{flex-wrap:wrap}
    .pd-item .side{flex-direction:row;align-items:center;justify-content:space-between;width:100%;flex-basis:100%}
    .pd-chart{height:120px;gap:8px}
}

/* ── character layer: notebook paper, ledger numerals, stamped tags ── */
.pd-wrap{--pd-rule:var(--pu-line,#D2E2F6);--pd-hl:#F4F916;--pd-serif:'Inter','Segoe UI',system-ui,-apple-system,Roboto,sans-serif;gap:28px}
.pd-card{background:var(--pd-card);border:1px solid var(--pd-rule);border-radius:10px;padding:clamp(16px,2.2vw,24px)}
.pd-card-head{border-bottom:1.5px solid var(--pd-navy);padding-bottom:10px;margin-bottom:2px}
body.dark .pd-card-head{border-bottom-color:var(--pd-line)}
.pd-card h2{font-family:var(--pd-serif);font-size:18px;font-weight:700;color:var(--pd-navy)}
body.dark .pd-card h2{color:var(--pd-ink)}
.pd-count{display:inline-block;padding:0 8px;border:1.5px solid currentColor;border-radius:4px;font:600 12px/18px 'Inter','Segoe UI',system-ui,-apple-system,Roboto,sans-serif;vertical-align:2px}

.pd-wrap .pd-hero{--m:60px;padding:30px 28px 30px calc(var(--m) + 26px);color:#1d2233;border:1px solid #D2E2F6;}
.pd-hero .pd-date,.pd-hero .pd-chips{color:#5B6890}
.pd-hero h1{font-family:var(--pd-serif);font-weight:700;color:#fff}
.pd-hero .pd-chip.id{color:#5B6890}
.pd-hero .pd-btn{border:1.5px solid #141F52;color:#141F52;background:#fff}
.pd-hero .pd-btn:hover{background:var(--pd-hl)}

.pd-stats{border:0;gap:12px}
.pd-wrap .pd-stat{background:var(--pd-card);border:1px solid var(--pd-rule);border-radius:10px;padding:16px 18px}
.pd-stat .val{font-family:var(--pd-serif);font-weight:700;color:var(--pd-navy)}
body.dark .pd-stat .val{color:var(--pd-ink)}
.pd-bar{height:5px;border-radius:3px}.pd-bar>span{border-radius:3px}

.pd-alert{border:1px dashed currentColor;border-radius:8px;padding:12px 16px;margin-bottom:8px}
.pd-alert+.pd-alert{border-top:1px dashed currentColor}
.pd-item{border-bottom-style:dashed}
.pd-badge{padding:1px 9px;border:1.5px solid currentColor;border-radius:4px;font-size:12px;line-height:18px}
.pd-mini:hover{background:var(--pd-hl);color:#141F52;border-color:#141F52}
.pd-bar-col .b{border-radius:3px 3px 0 0}

.pd-section-title{margin:6px 0 0;padding:0;border:0;font-family:var(--pd-serif);font-size:22px;font-weight:700;color:var(--pd-navy)}
body.dark .pd-section-title{color:var(--pd-ink)}
.pd-section-title small{font-family:'Inter','Segoe UI',system-ui,-apple-system,Roboto,sans-serif}
@media (max-width:600px){.pd-wrap .pd-hero{--m:40px;padding:24px 18px 24px calc(var(--m) + 16px)}}
</style>

<section class="pd-wrap" aria-label="My library dashboard">

    <!-- Hero -->
    <div class="pd-card pd-hero">
        <div>
            <div class="pd-date"><?php echo pdH(date('l, F j, Y', $now)); ?></div>
            <h1><?php echo pdH($greeting . ', ' . $firstName); ?>!</h1>
            <div class="pd-chips">
                <?php if ($idNumber !== ''): ?><span class="pd-chip id"><?php echo pdH($idLabel . ': ' . $idNumber); ?></span><?php endif; ?>
                <?php foreach ($chips as $c): ?><span class="pd-chip"><?php echo pdH($c); ?></span><?php endforeach; ?>
                <?php if ($insideNow): ?><span class="pd-chip">Currently in the library</span><?php endif; ?>
            </div>
            <div class="pd-actions">
                <a class="pd-btn ghost" href="#catalogue">Browse books</a>
                <a class="pd-btn ghost" href="<?php echo pdH($base); ?>/profile.php">My profile</a>
            </div>
        </div>
        <?php if ($qrCode !== ''): ?>
        <div class="pd-idcard">
            <button type="button" class="qr-zoomable" data-qr-zoom
                    data-qr-code="<?php echo pdH($qrCode); ?>"
                    data-qr-title="My Library ID"
                    data-qr-sub="<?php echo pdH($user['full_name'] . ($idNumber !== '' ? ' · ' . $idNumber : '')); ?>"
                    data-qr-download="<?php echo pdH($base . '/download_qr.php?format=jpg'); ?>"
                    data-qr-print="<?php echo pdH($cardPrint); ?>"
                    data-qr-card="1"
                    data-qr-card-name="<?php echo pdH($user['full_name']); ?>"
                    data-qr-card-id="<?php echo pdH($idNumber); ?>"
                    data-qr-card-image="<?php echo pdH($qrImage); ?>"
                    data-qr-card-logo="<?php echo pdH($cardLogo); ?>"
                    aria-label="Enlarge my library ID QR code">
                <img src="https://api.qrserver.com/v1/create-qr-code/?size=300x300&margin=0&data=<?php echo urlencode($qrCode); ?>" alt="My library ID QR code" loading="lazy">
                <span class="qr-zoom-hint" aria-hidden="true">⤢</span>
            </button>
            <div class="meta">
                <span class="qr-tap-note">Tap to enlarge</span>
                <div class="pd-idcode"><?php echo pdH($qrCode); ?></div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Alerts -->
    <div class="pd-alerts">
        <?php if ($overdue > 0): ?>
            <div class="pd-alert danger"><span class="ico"></span><div><strong><?php echo $overdue; ?> overdue book<?php echo $overdue === 1 ? '' : 's'; ?>.</strong> Please return <?php echo $overdue === 1 ? 'it' : 'them'; ?> to the library desk as soon as possible.</div></div>
        <?php endif; ?>
        <?php if ($dueToday > 0): ?>
            <div class="pd-alert warn"><span class="ico"></span><div><strong><?php echo $dueToday; ?> book<?php echo $dueToday === 1 ? ' is' : 's are'; ?> due today.</strong> Library loans are same-day, so return before closing time.</div></div>
        <?php endif; ?>
        <?php if ($overdue === 0 && $dueToday === 0): ?>
            <div class="pd-alert info"><span class="ico"><?php echo $loanCount > 0; ?></span><div>
                <?php if ($loanCount > 0): ?><strong>You're all caught up.</strong> No books are overdue.<?php else: ?><strong>No books borrowed right now.</strong> Browse the catalogue below, then show your QR at the desk to borrow.<?php endif; ?>
            </div></div>
        <?php endif; ?>
    </div>

    <!-- Key numbers -->
    <div class="pd-stats">
        <div class="pd-card pd-stat">
            <div class="lbl">Borrowed now</div>
            <div class="val"><?php echo $loanCount; ?> <small>/ <?php echo (int)$maxActive; ?></small></div>
            <div class="pd-bar<?php echo $slotPct >= 100 ? ' full' : ''; ?>" role="progressbar" aria-valuemin="0" aria-valuemax="<?php echo (int)$maxActive; ?>" aria-valuenow="<?php echo $loanCount; ?>"><span style="width:<?php echo $slotPct; ?>%"></span></div>
            <div class="sub"><?php echo max(0, $maxActive - $loanCount); ?> slot<?php echo ($maxActive - $loanCount) === 1 ? '' : 's'; ?> left</div>
        </div>
        <div class="pd-card pd-stat">
            <div class="lbl">Books returned</div>
            <div class="val"><?php echo $totalReturned; ?></div>
            <div class="sub"><?php echo $totalLate > 0 ? $totalLate . ' returned late' : ($totalReturned > 0 ? 'All on time ' : 'None yet'); ?></div>
        </div>
        <div class="pd-card pd-stat">
            <div class="lbl">Visits this month</div>
            <div class="val"><?php echo $visitsMonth; ?></div>
            <div class="sub"><?php echo !empty($lastVisit[0]) ? 'Last: ' . pdH(date('M j, g:i A', strtotime($lastVisit[0]['time_in']))) : 'No visits yet'; ?></div>
        </div>
        <div class="pd-card pd-stat">
            <div class="lbl">Books borrowed</div>
            <div class="val"><?php echo $totalBorrowed; ?></div>
            <div class="sub"><?php echo $onTimePct === null ? 'All-time total' : $onTimePct . '% returned on time'; ?></div>
        </div>
    </div>

    <!-- Main -->
    <div class="pd-main">
        <div class="pd-col">
            <!-- Borrowed -->
            <div class="pd-card" id="borrowed">
                <div class="pd-card-head">
                    <h2>Currently borrowed<span class="pd-count"><?php echo $loanCount; ?></span></h2>
                    <a href="<?php echo pdH($base); ?>/profile.php#history">History →</a>
                </div>
                <?php if (!$loans): ?>
                    <div class="pd-empty"><span class="big"></span>Nothing borrowed yet.<br>Find a book below and bring its QR to the desk.</div>
                <?php else: ?>
                <ul class="pd-list">
                    <?php foreach ($loans as $loan): $due = $loan['_due']; ?>
                    <li class="pd-item <?php echo pdH($due[0]); ?>">
                        <div class="ico" aria-hidden="true"></div>
                        <div class="body">
                            <div class="ttl"><?php echo pdH($loan['title']); ?></div>
                            <div class="meta">
                                <?php echo pdH($loan['author']); ?><br>
                                Physical Copy <?php echo pdH($loan['book_number']); ?><?php if (!empty($loan['qr_code'])): ?> · QR ID <?php echo pdH($loan['qr_code']); ?><?php endif; ?><br>
                                Borrowed <?php echo pdH(date('M j, g:i A', strtotime($loan['date_borrowed']))); ?>
                                · Due <?php echo pdH(date('M j', strtotime($loan['due_date']))); ?>
                                <?php $loc = array_filter([trim((string)($loan['library_section'] ?? '')), trim((string)($loan['shelf_number'] ?? '')) !== '' ? 'Shelf ' . trim((string)$loan['shelf_number']) : '']); if ($loc): ?><br>Location: <?php echo pdH(implode(' · ', $loc)); ?><?php endif; ?>
                            </div>
                        </div>
                        <div class="side">
                            <span class="pd-badge <?php echo pdH($due[0]); ?>"><?php echo pdH($due[1]); ?></span>
                            <?php if (!empty($loan['qr_code'])): ?>
                            <button type="button" class="pd-mini" data-qr-zoom
                                    data-qr-code="<?php echo pdH($loan['qr_code']); ?>"
                                    data-qr-title="<?php echo pdH($loan['title']); ?>"
                                    data-qr-sub="Book QR · show this when returning">▦ Book QR</button>
                            <?php endif; ?>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <p class="pd-note">To return a book, hand it to the library desk and show the book QR above.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="pd-col">
            <!-- Chart -->
            <div class="pd-card">
                <div class="pd-card-head"><h2>Borrowing, last 6 months</h2></div>
                <?php if ($chartSum === 0): ?>
                    <div class="pd-empty">Your reading activity will show up here once you borrow a book.</div>
                <?php else: ?>
                <div class="pd-chart" role="img" aria-label="Books borrowed per month for the last six months">
                    <?php foreach ($monthly as $m): $h = $m['count'] > 0 ? max(8, (int)round(($m['count'] / $chartMax) * 90)) : 0; ?>
                    <div class="pd-bar-col">
                        <span class="n"><?php echo (int)$m['count']; ?></span>
                        <div class="b<?php echo $m['count'] === 0 ? ' zero' : ''; ?>" style="height:<?php echo $m['count'] === 0 ? 4 : $h; ?>%"></div>
                        <span class="m"><?php echo pdH($m['label']); ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <div class="pd-section-title" id="catalogue">Book catalogue <small>Search, then tap a book for details and its QR</small></div>
</section>
<?php
}

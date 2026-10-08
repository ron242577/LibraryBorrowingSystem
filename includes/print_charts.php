<?php
/**
 * Print Charts - Jose Abad Santos High School Library Borrowing System
 * Server-rendered inline SVG charts for printable librarian reports.
 * No external scripts, so graphs always appear when printing or saving as PDF.
 */

if (!function_exists('printChartEscape')) {
    function printChartEscape($value) {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('printChartStyles')) {
    /** CSS shared by all printable report graphs. */
    function printChartStyles() {
        return '.print-chart{margin:18px 0 22px;page-break-inside:avoid;break-inside:avoid}'
            . '.print-chart h2{color:#141F52;font-size:18px;margin:0 0 8px}'
            . '.print-chart svg{width:100%;height:auto;display:block;border:1px solid #D2E2F6;border-radius:6px;background:#fff}'
            . '.print-chart .chart-empty{border:1px dashed #D2E2F6;border-radius:6px;padding:22px;text-align:center;color:#52618D;font-size:12px}'
            . '.cal{width:100%;table-layout:fixed;border-collapse:collapse}'
            . '.cal th{background:#E7EEF7;color:#141F52;font-size:11px;padding:6px 4px;border:1px solid #D2E2F6;text-align:center}'
            . '.cal td{height:58px;padding:4px 6px;border:1px solid #D2E2F6;vertical-align:top;text-align:left;font-size:11px;color:#52618D}'
            . '.cal td b{display:block;margin-top:4px;font-size:20px;text-align:center;font-weight:700}'
            . '.cal td.cal-off{background:#FAFCFF}'
            . '.cal td.cal-out{color:#B5BED2;background:#F6F8FC}'
            . '.cal td.cal-zero b{color:#B5BED2;font-weight:400}'
            . '.month-picker{display:flex;flex-wrap:wrap;align-items:center;gap:8px 14px;margin:0 0 18px;padding:12px 14px;border:1px solid #D2E2F6;border-radius:8px;background:#F7F9FC;font-size:13px}'
            . '.month-picker strong{color:#141F52}'
            . '.month-picker label{display:inline-flex;align-items:center;gap:6px;cursor:pointer}'
            . '.month-picker input{width:16px;height:16px;cursor:pointer}'
            . '.month-picker button{border:1px solid #D2E2F6;background:#fff;color:#141F52;border-radius:6px;padding:6px 10px;font-size:12px;font-weight:700;cursor:pointer}'
            . '.cal-note{margin:6px 0 0;font-size:11px;color:#52618D}'
            . '.chart-toggle{display:inline-flex;align-items:center;gap:7px;margin-right:12px;font-size:13px;color:#202A44;cursor:pointer;user-select:none}'
            . '.chart-toggle input{width:16px;height:16px;cursor:pointer}'
            . 'body.hide-charts .print-chart{display:none !important}'
            . '.print-frame{width:100%;border-collapse:collapse;border:0;font-size:inherit}'
            . '.print-frame>thead>tr>td,.print-frame>tbody>tr>td,.print-frame>tfoot>tr>td{border:0;padding:0;background:none;text-align:left;vertical-align:top;font-size:inherit}'
            . '.frame-space{height:0}'
            . '@page{margin:0}'
            . '@media print{.print-chart svg{-webkit-print-color-adjust:exact;print-color-adjust:exact}'
            . '.chart-toggle,.month-picker{display:none !important}'
            . 'html,body{margin:0 !important;padding:0 !important;background:#fff !important}'
            . '*{-webkit-print-color-adjust:exact;print-color-adjust:exact}'
            . '.report{max-width:none !important;margin:0 !important}'
            . '.frame-space{height:12mm}'
            . '.print-frame>tbody>tr>td{padding:0 14mm !important}'
            . '.sheet{padding:0 !important}}';
    }
}

if (!function_exists('printChartEmpty')) {
    /** Shown instead of a graph when there is nothing to plot, so reports never silently drop a chart. */
    function printChartEmpty($title) {
        return '<section class="print-chart"><h2>' . printChartEscape($title) . '</h2><div class="chart-empty">No data for this period.</div></section>';
    }
}

if (!function_exists('printChartNiceMax')) {
    function printChartNiceMax($max) {
        if ($max <= 5) return 5;
        $pow = pow(10, floor(log10($max)));
        foreach ([1, 2, 5, 10] as $step) {
            if ($max <= $step * $pow) return $step * $pow;
        }
        return 10 * $pow;
    }
}

if (!function_exists('printChartTrim')) {
    function printChartTrim($label, $limit = 14) {
        $label = (string)$label;
        return mb_strlen($label) > $limit ? mb_substr($label, 0, $limit - 1) . '...' : $label;
    }
}

if (!function_exists('renderBarChart')) {
    /**
     * Vertical grouped bar chart.
     *
     * @param string $title    Heading shown above the graph
     * @param array  $labels   X-axis labels
     * @param array  $series   [['name' => 'Borrows', 'color' => '#141F52', 'values' => [..]], ...]
     */
    function renderBarChart($title, array $labels, array $series) {
        $out = '<section class="print-chart"><h2>' . printChartEscape($title) . '</h2>';
        $hasData = false;
        foreach ($series as $s) {
            foreach ($s['values'] as $v) { if ((float)$v > 0) { $hasData = true; break 2; } }
        }
        if (!$labels || !$hasData) {
            return printChartEmpty($title);
        }

        $w = 760; $h = 300;
        $ml = 46; $mr = 16; $mt = 18; $mb = 58;
        $plotW = $w - $ml - $mr; $plotH = $h - $mt - $mb;
        $maxVal = 0;
        foreach ($series as $s) foreach ($s['values'] as $v) $maxVal = max($maxVal, (float)$v);
        $top = printChartNiceMax($maxVal);
        $n = count($labels); $m = count($series);
        $group = $plotW / $n;
        $barW = max(4, min(64, ($group * 0.72) / max(1, $m)));

        $svg = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="' . printChartEscape($title) . '">';
        for ($i = 0; $i <= 5; $i++) {
            $val = $top * $i / 5;
            $y = $mt + $plotH - ($plotH * $i / 5);
            $svg .= '<line x1="' . $ml . '" y1="' . round($y, 1) . '" x2="' . ($w - $mr) . '" y2="' . round($y, 1) . '" stroke="#E7EEF7" stroke-width="1"/>';
            $svg .= '<text x="' . ($ml - 6) . '" y="' . round($y + 4, 1) . '" font-size="10" fill="#52618D" text-anchor="end">' . (floor($val) == $val ? (int)$val : round($val, 1)) . '</text>';
        }
        $svg .= '<line x1="' . $ml . '" y1="' . ($mt + $plotH) . '" x2="' . ($w - $mr) . '" y2="' . ($mt + $plotH) . '" stroke="#52618D" stroke-width="1"/>';

        foreach ($labels as $i => $label) {
            $gx = $ml + $group * $i + ($group - $barW * $m) / 2;
            foreach ($series as $k => $s) {
                $v = (float)($s['values'][$i] ?? 0);
                $bh = $top > 0 ? ($v / $top) * $plotH : 0;
                $x = $gx + $barW * $k;
                $y = $mt + $plotH - $bh;
                $svg .= '<rect x="' . round($x, 1) . '" y="' . round($y, 1) . '" width="' . round($barW - 2, 1) . '" height="' . round($bh, 1) . '" fill="' . printChartEscape($s['color']) . '"/>';
                if ($v > 0) {
                    $svg .= '<text x="' . round($x + ($barW - 2) / 2, 1) . '" y="' . round($y - 4, 1) . '" font-size="10" fill="#202A44" text-anchor="middle">' . (floor($v) == $v ? (int)$v : round($v, 1)) . '</text>';
                }
            }
            $cx = $ml + $group * $i + $group / 2;
            $svg .= '<text x="' . round($cx, 1) . '" y="' . ($mt + $plotH + 16) . '" font-size="10" fill="#202A44" text-anchor="middle" transform="rotate(' . ($n > 7 ? -25 : 0) . ' ' . round($cx, 1) . ' ' . ($mt + $plotH + 16) . ')">' . printChartEscape(printChartTrim($label)) . '</text>';
        }

        if ($m > 1 || !empty($series[0]['name'])) {
            $lx = $ml;
            foreach ($series as $s) {
                $svg .= '<rect x="' . $lx . '" y="' . ($h - 16) . '" width="10" height="10" fill="' . printChartEscape($s['color']) . '"/>';
                $svg .= '<text x="' . ($lx + 15) . '" y="' . ($h - 7) . '" font-size="11" fill="#202A44">' . printChartEscape($s['name']) . '</text>';
                $lx += 30 + mb_strlen($s['name']) * 6.5;
            }
        }
        $svg .= '</svg>';
        return $out . $svg . '</section>';
    }
}

if (!function_exists('renderHorizontalBarChart')) {
    /** Horizontal bar chart, good for ranked lists with long labels. */
    function renderHorizontalBarChart($title, array $labels, array $values, $color = '#141F52') {
        $out = '<section class="print-chart"><h2>' . printChartEscape($title) . '</h2>';
        if (!$labels || max(array_map('floatval', $values ?: [0])) <= 0) {
            return printChartEmpty($title);
        }
        $rowH = 26; $w = 760;
        $ml = 200; $mr = 40; $mt = 12; $mb = 12;
        $h = $mt + $mb + $rowH * count($labels);
        $plotW = $w - $ml - $mr;
        $top = printChartNiceMax(max(array_map('floatval', $values)));

        $svg = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="' . printChartEscape($title) . '">';
        foreach ($labels as $i => $label) {
            $v = (float)($values[$i] ?? 0);
            $y = $mt + $rowH * $i;
            $bw = $top > 0 ? ($v / $top) * $plotW : 0;
            $svg .= '<text x="' . ($ml - 8) . '" y="' . ($y + 16) . '" font-size="11" fill="#202A44" text-anchor="end">' . printChartEscape(printChartTrim($label, 30)) . '</text>';
            $svg .= '<rect x="' . $ml . '" y="' . ($y + 4) . '" width="' . round($bw, 1) . '" height="16" fill="' . printChartEscape($color) . '"/>';
            $svg .= '<text x="' . round($ml + $bw + 6, 1) . '" y="' . ($y + 16) . '" font-size="11" fill="#202A44">' . (floor($v) == $v ? (int)$v : round($v, 1)) . '</text>';
        }
        $svg .= '</svg>';
        return $out . $svg . '</section>';
    }
}

if (!function_exists('renderLineChart')) {
    /** Single-series line chart with point markers. */
    function renderLineChart($title, array $labels, array $values, $color = '#141F52', $seriesName = 'Count') {
        $out = '<section class="print-chart"><h2>' . printChartEscape($title) . '</h2>';
        if (!$labels || max(array_map('floatval', $values ?: [0])) <= 0) {
            return printChartEmpty($title);
        }
        // A line needs two points. With one, a bar shows the value instead of a lone dot.
        if (count($labels) < 2) {
            return renderBarChart($title, $labels, [['name' => $seriesName, 'color' => $color, 'values' => $values]]);
        }
        $w = 760; $h = 280;
        $ml = 46; $mr = 20; $mt = 18; $mb = 54;
        $plotW = $w - $ml - $mr; $plotH = $h - $mt - $mb;
        $top = printChartNiceMax(max(array_map('floatval', $values)));
        $n = count($labels);
        $step = $n > 1 ? $plotW / ($n - 1) : 0;

        $svg = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="' . printChartEscape($title) . '">';
        for ($i = 0; $i <= 5; $i++) {
            $val = $top * $i / 5;
            $y = $mt + $plotH - ($plotH * $i / 5);
            $svg .= '<line x1="' . $ml . '" y1="' . round($y, 1) . '" x2="' . ($w - $mr) . '" y2="' . round($y, 1) . '" stroke="#E7EEF7" stroke-width="1"/>';
            $svg .= '<text x="' . ($ml - 6) . '" y="' . round($y + 4, 1) . '" font-size="10" fill="#52618D" text-anchor="end">' . (floor($val) == $val ? (int)$val : round($val, 1)) . '</text>';
        }
        $svg .= '<line x1="' . $ml . '" y1="' . ($mt + $plotH) . '" x2="' . ($w - $mr) . '" y2="' . ($mt + $plotH) . '" stroke="#52618D" stroke-width="1"/>';

        $points = [];
        foreach ($labels as $i => $label) {
            $x = $n > 1 ? $ml + $step * $i : $ml + $plotW / 2;
            $v = (float)($values[$i] ?? 0);
            $y = $mt + $plotH - ($top > 0 ? ($v / $top) * $plotH : 0);
            $points[] = [round($x, 1), round($y, 1), $v];
            $svg .= '<text x="' . round($x, 1) . '" y="' . ($mt + $plotH + 16) . '" font-size="10" fill="#202A44" text-anchor="middle" transform="rotate(' . ($n > 8 ? -25 : 0) . ' ' . round($x, 1) . ' ' . ($mt + $plotH + 16) . ')">' . printChartEscape(printChartTrim($label)) . '</text>';
        }
        if ($n > 1) {
            $area = $ml . ',' . ($mt + $plotH) . ' ';
            $line = '';
            foreach ($points as $p) { $line .= $p[0] . ',' . $p[1] . ' '; }
            $svg .= '<polygon points="' . $area . $line . ($ml + $plotW) . ',' . ($mt + $plotH) . '" fill="' . printChartEscape($color) . '" fill-opacity="0.12"/>';
            $svg .= '<polyline points="' . trim($line) . '" fill="none" stroke="' . printChartEscape($color) . '" stroke-width="2.5"/>';
        }
        foreach ($points as $p) {
            $svg .= '<circle cx="' . $p[0] . '" cy="' . $p[1] . '" r="4" fill="' . printChartEscape($color) . '"/>';
            $svg .= '<text x="' . $p[0] . '" y="' . ($p[1] - 9) . '" font-size="10" fill="#202A44" text-anchor="middle">' . (floor($p[2]) == $p[2] ? (int)$p[2] : round($p[2], 1)) . '</text>';
        }
        $svg .= '<rect x="' . $ml . '" y="' . ($h - 16) . '" width="10" height="10" fill="' . printChartEscape($color) . '"/>';
        $svg .= '<text x="' . ($ml + 15) . '" y="' . ($h - 7) . '" font-size="11" fill="#202A44">' . printChartEscape($seriesName) . '</text>';
        $svg .= '</svg>';
        return $out . $svg . '</section>';
    }
}

if (!function_exists('renderVisitCalendar')) {
    /**
     * Month calendar(s) with the number of visits written in each day.
     * $countsByDate = ['2026-10-06' => 12, ...]. Days outside start..end are greyed out.
     */
    function renderVisitCalendar(array $countsByDate, $start, $end, $months = null) {
        $title = 'Visits Calendar';
        $startTs = strtotime($start); $endTs = strtotime($end);
        if (!$startTs || !$endTs) return '';
        $max = $countsByDate ? max($countsByDate) : 0;
        if ($max <= 0) return printChartEmpty($title);

        $out = '';
        $cursor = strtotime(date('Y-m-01', $startTs));
        $lastMonth = strtotime(date('Y-m-01', $endTs));
        $shown = 0;
        while ($cursor <= $lastMonth && $shown < 60) {
            if ($months !== null && !in_array(date('Y-m', $cursor), $months, true)) {
                $cursor = strtotime('+1 month', $cursor);
                $shown++;
                continue;
            }
            $first = (int)date('w', $cursor);
            $days = (int)date('t', $cursor);
            $monthKey = date('Y-m-', $cursor);
            $total = 0; $cell = 0;
            $body = '<tr>';
            for ($i = 0; $i < $first; $i++) { $body .= '<td class="cal-off"></td>'; $cell++; }
            for ($d = 1; $d <= $days; $d++) {
                if ($cell > 0 && $cell % 7 === 0) $body .= '</tr><tr>';
                $key = $monthKey . sprintf('%02d', $d);
                if ($key < $start || $key > $end) {
                    $body .= '<td class="cal-out">' . $d . '</td>';
                } else {
                    $n = (int)($countsByDate[$key] ?? 0);
                    $total += $n;
                    if ($n > 0) {
                        $alpha = round(0.12 + 0.78 * ($n / $max), 2);
                        $ink = $alpha > 0.5 ? '#fff' : '#141F52';
                        $body .= '<td style="background:rgba(20,31,82,' . $alpha . ');color:' . $ink . '">' . $d . '<b>' . $n . '</b></td>';
                    } else {
                        $body .= '<td class="cal-zero">' . $d . '<b>0</b></td>';
                    }
                }
                $cell++;
            }
            while ($cell % 7 !== 0) { $body .= '<td class="cal-off"></td>'; $cell++; }
            $body .= '</tr>';

            $out .= '<section class="print-chart"><h2>' . $title . ': ' . printChartEscape(date('F Y', $cursor)) . ' (' . $total . ' visit' . ($total === 1 ? '' : 's') . ')</h2>'
                . '<table class="cal"><thead><tr><th>Sun</th><th>Mon</th><th>Tue</th><th>Wed</th><th>Thu</th><th>Fri</th><th>Sat</th></tr></thead><tbody>' . $body . '</tbody></table>'
                . '<p class="cal-note">The large number in each box is the number of visits on that day. Darker boxes are busier days.</p></section>';
            $cursor = strtotime('+1 month', $cursor);
            $shown++;
        }
        return $out;
    }
}

if (!function_exists('renderVisitCharts')) {
    /**
     * Calendar of visits per day, weekly bars (range longer than a week) and monthly bars.
     * $months = list of 'Y-m' to include, or null for every month in the range.
     */
    function renderVisitCharts(array $countsByDate, $start, $end, $months = null) {
        $out = renderVisitCalendar($countsByDate, $start, $end, $months);
        $startTs = strtotime($start); $endTs = strtotime($end);
        if (!$startTs || !$endTs || !$countsByDate) return $out;
        $picked = function ($ts) use ($months) { return $months === null || in_array(date('Y-m', $ts), $months, true); };

        if (($endTs - $startTs) / 86400 >= 7) {
            $labels = []; $vals = [];
            $w = strtotime('-' . date('w', $startTs) . ' days', $startTs);
            for ($i = 0; $w <= $endTs && $i < 120; $i++, $w = strtotime('+7 days', $w)) {
                $wEnd = strtotime('+6 days', $w);
                $sum = 0; $has = false;
                for ($d = $w; $d <= $wEnd; $d = strtotime('+1 day', $d)) {
                    $k = date('Y-m-d', $d);
                    if ($k >= $start && $k <= $end && $picked($d)) { $sum += (int)($countsByDate[$k] ?? 0); $has = true; }
                }
                if (!$has) continue;
                $labels[] = date('M j', $w) . '-' . (date('n', $w) === date('n', $wEnd) ? date('j', $wEnd) : date('M j', $wEnd));
                $vals[] = $sum;
            }
            if ($labels) $out .= renderBarChart('Visits per Week', $labels, [['name' => 'Visits', 'color' => '#52618D', 'values' => $vals]]);
        }

        $labels = []; $vals = [];
        $m = strtotime(date('Y-m-01', $startTs));
        for ($i = 0; $m <= $endTs && $i < 60; $i++, $m = strtotime('+1 month', $m)) {
            if (!$picked($m)) continue;
            $prefix = date('Y-m', $m);
            $sum = 0;
            foreach ($countsByDate as $k => $n) {
                if (strpos($k, $prefix) === 0 && $k >= $start && $k <= $end) $sum += (int)$n;
            }
            $labels[] = date('M Y', $m);
            $vals[] = $sum;
        }
        if ($labels) $out .= renderBarChart('Visits per Month', $labels, [['name' => 'Visits', 'color' => '#141F52', 'values' => $vals]]);
        return $out;
    }
}

if (!function_exists('printMonthOptions')) {
    /** ['2026-09' => 'Sep 2026', ...] for every month between start and end. */
    function printMonthOptions($start, $end) {
        $out = [];
        $m = strtotime(date('Y-m-01', strtotime($start)));
        $last = strtotime(date('Y-m-01', strtotime($end)));
        for ($i = 0; $m <= $last && $i < 60; $i++, $m = strtotime('+1 month', $m)) $out[date('Y-m', $m)] = date('M Y', $m);
        return $out;
    }
}

if (!function_exists('printSelectedMonths')) {
    /** Months ticked in the picker. Everything is selected until the picker is used. */
    function printSelectedMonths(array $options) {
        if (empty($_GET['months_set'])) return array_keys($options);
        $picked = array_map('strval', (array)($_GET['months'] ?? []));
        return array_values(array_intersect(array_keys($options), $picked));
    }
}

if (!function_exists('printMonthPicker')) {
    /** Screen-only checkboxes to choose which months go into the printout. Hidden when there is only one month. */
    function printMonthPicker(array $options, array $selected, $start, $end) {
        if (count($options) < 2) return '';
        $h = '<form method="GET" class="month-picker" id="monthPicker">'
            . '<input type="hidden" name="report" value="1">'
            . '<input type="hidden" name="start_date" value="' . printChartEscape($start) . '">'
            . '<input type="hidden" name="end_date" value="' . printChartEscape($end) . '">'
            . '<input type="hidden" name="months_set" value="1">'
            . '<strong>Months to print</strong>';
        foreach ($options as $key => $label) {
            $h .= '<label><input type="checkbox" name="months[]" value="' . printChartEscape($key) . '"' . (in_array($key, $selected, true) ? ' checked' : '') . '> ' . printChartEscape($label) . '</label>';
        }
        $h .= '<button type="button" data-all="1">Select all</button><button type="button" data-all="0">Clear</button></form>'
            . '<script>(function(){var f=document.getElementById("monthPicker");'
            . 'f.addEventListener("change",function(){f.submit();});'
            . 'f.querySelectorAll("button[data-all]").forEach(function(b){b.addEventListener("click",function(){'
            . 'var on=b.getAttribute("data-all")==="1";f.querySelectorAll("input[type=checkbox]").forEach(function(c){c.checked=on;});f.submit();});});})();</script>';
        return $h;
    }
}

if (!function_exists('printChartToggle')) {
    /**
     * "Include graphs" checkbox for the print toolbar. Unticking it hides every
     * .print-chart on screen and in print. The choice is remembered, and the
     * checkbox hides itself when the report has no graphs.
     */
    function printChartToggle() {
        return '<label class="chart-toggle" id="chartToggle"><input type="checkbox" id="includeCharts" checked> Include graphs when printing</label>'
            . '<script>(function(){'
            . 'var key="libraryPrintGraphs",box=document.getElementById("includeCharts"),wrap=document.getElementById("chartToggle");'
            . 'function apply(){document.body.classList.toggle("hide-charts",!box.checked);}'
            . 'document.addEventListener("DOMContentLoaded",function(){'
            . 'if(!document.querySelector(".print-chart")){wrap.style.display="none";return;}'
            . 'try{if(localStorage.getItem(key)==="0")box.checked=false;}catch(e){}'
            . 'apply();'
            . 'box.addEventListener("change",function(){try{localStorage.setItem(key,box.checked?"1":"0");}catch(e){}apply();});'
            . '});})();</script>';
    }
}

if (!function_exists('printFrameOpen')) {
    /**
     * Wraps the report in a table whose empty header/footer rows repeat on every
     * printed page. That gives every page the same top/bottom margin while the
     * browser's own header/footer stays off (@page margin is 0).
     */
    function printFrameOpen() {
        return '<table class="print-frame"><thead><tr><td><div class="frame-space"></div></td></tr></thead><tbody><tr><td>';
    }
    function printFrameClose() {
        return '</td></tr></tbody><tfoot><tr><td><div class="frame-space"></div></td></tr></tfoot></table>';
    }
}

if (!function_exists('printReportStyles')) {
    /** One stylesheet shared by every printable report so they all look identical. */
    function printReportStyles() {
        return '*{box-sizing:border-box}'
            . 'body{margin:0;padding:28px;background:#fff;color:#202A44;font-family:Arial,sans-serif}'
            . '.report{max-width:1050px;margin:0 auto}'
            . '.print-actions{display:flex;justify-content:flex-end;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:18px}'
            . '.print-actions button,.print-actions a{border:0;border-radius:6px;padding:10px 16px;background:#141F52;color:#fff;text-decoration:none;cursor:pointer;font-weight:700;font-size:13px;font-family:inherit}'
            . '.print-actions a{background:#52618D}'
            . '.report-header{text-align:center;border-bottom:3px solid #141F52;padding-bottom:16px;margin-bottom:22px}'
            . '.report-header img{width:58px;height:58px;object-fit:contain;border-radius:50%;vertical-align:middle;margin-bottom:8px}'
            . '.report-header h1{margin:0;color:#141F52;font-size:25px}'
            . '.report-header p{margin:5px 0;color:#52618D;font-size:13px}'
            . '.summary{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:22px}'
            . '.summary-card{border:1px solid #D2E2F6;border-left:4px solid #141F52;padding:14px;border-radius:6px}'
            . '.summary-card strong{display:block;font-size:24px;color:#141F52}'
            . '.summary-card span{font-size:12px;color:#52618D}'
            . '.report h2{color:#141F52;font-size:18px;margin:22px 0 8px}'
            . 'table{width:100%;border-collapse:collapse;font-size:12px}'
            . 'th,td{padding:9px;border:1px solid #D2E2F6;text-align:left}'
            . 'th{background:#141F52;color:#fff}'
            . '@media(max-width:600px){.summary{grid-template-columns:1fr}}'
            . printChartStyles()
            . '@media print{.print-actions{display:none !important}}';
    }
}

if (!function_exists('printReportToolbar')) {
    /** Top-right toolbar: graph toggle, Print button and optional Back link. */
    function printReportToolbar($backUrl = null, $backLabel = 'Back') {
        $html = '<div class="print-actions">' . printChartToggle()
            . '<button type="button" onclick="window.print()">Print / Save PDF</button>';
        if ($backUrl) {
            $html .= '<a href="' . printChartEscape($backUrl) . '">' . printChartEscape($backLabel) . '</a>';
        }
        return $html . '</div>';
    }
}

if (!function_exists('printReportHeader')) {
    /** Logo, school name, report title and period - identical on every report. */
    function printReportHeader($reportTitle, $period) {
        return '<header class="report-header">'
            . '<img src="/LibraryBorrowingSystem/Img/jAbadSantos_Logo.jpg" alt="Jose Abad Santos High School Logo">'
            . '<h1>Jose Abad Santos High School</h1>'
            . '<p>' . printChartEscape($reportTitle) . '</p>'
            . '<p>' . printChartEscape($period) . '</p>'
            . '</header>';
    }
}

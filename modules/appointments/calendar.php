<?php
require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';

$page_title = 'Calendar';

$year  = intval($_GET['year']  ?? date('Y'));
$month = intval($_GET['month'] ?? date('n'));

if ($month < 1)  { $month = 12; $year--; }
if ($month > 12) { $month = 1;  $year++; }

$month_start = sprintf('%04d-%02d-01', $year, $month);
$month_end   = date('Y-m-t', strtotime($month_start));
$month_label = date('F Y', strtotime($month_start));
$today       = date('Y-m-d');

$prev_month = $month - 1; $prev_year = $year;
if ($prev_month < 1)  { $prev_month = 12; $prev_year--; }
$next_month = $month + 1; $next_year = $year;
if ($next_month > 12) { $next_month = 1;  $next_year++; }

// Fetch appointments (now includes doctor)
$appts_raw = $conn->query("
    SELECT a.id, a.appointment_date, a.appointment_time,
           a.status, a.patient_id,
           CONCAT(p.first_name,' ',p.last_name) as patient_name,
           s.service_name, s.id as service_id,
           d.full_name as doctor_name, d.id as doctor_id
    FROM appointments a
    LEFT JOIN patients p ON a.patient_id = p.id
    LEFT JOIN services s ON a.service_id = s.id
    LEFT JOIN doctors  d ON a.doctor_id  = d.id
    WHERE a.appointment_date BETWEEN '$month_start' AND '$month_end'
    ORDER BY a.appointment_time ASC
")->fetch_all(MYSQLI_ASSOC);

// Fetch all doctors for the legend + filter
$all_doctors_raw = $conn->query("SELECT id, full_name FROM doctors WHERE is_active = 1 ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);

// Doctor color palette (up to 8 doctors — repeats gracefully after that)
$doctor_palette = [
    1 => ['bg'=>'#e8f0fe','border'=>'#4285f4','text'=>'#1a56db'],  // Google Blue
    2 => ['bg'=>'#fce8f3','border'=>'#e040fb','text'=>'#8e24aa'],  // Purple
    3 => ['bg'=>'#e6f4ea','border'=>'#34a853','text'=>'#137333'],  // Green
    4 => ['bg'=>'#fef7e0','border'=>'#fbbc04','text'=>'#b06000'],  // Amber
    5 => ['bg'=>'#fce8e6','border'=>'#ea4335','text'=>'#c5221f'],  // Red
    6 => ['bg'=>'#e4f7fb','border'=>'#00acc1','text'=>'#006064'],  // Cyan
    7 => ['bg'=>'#fff3e0','border'=>'#ff6d00','text'=>'#bf360c'],  // Orange
    8 => ['bg'=>'#f3e5f5','border'=>'#9c27b0','text'=>'#6a1b9a'],  // Violet
];

// Map doctor id → color (cycle through palette)
$doctor_color_map = [];
$palette_index = 1;
foreach ($all_doctors_raw as $dr) {
    $doctor_color_map[$dr['id']] = $doctor_palette[($palette_index - 1) % 8 + 1];
    $palette_index++;
}
$default_color = ['bg'=>'#f0f3f4','border'=>'#5d6d7e','text'=>'#2c3e50'];

// Fetch blocked dates
$blocked_raw = $conn->query("
    SELECT blocked_date, reason FROM blocked_dates
    WHERE blocked_date BETWEEN '$month_start' AND '$month_end'
")->fetch_all(MYSQLI_ASSOC);

$appts_by_date = [];
foreach ($appts_raw as $a) {
    $appts_by_date[$a['appointment_date']][] = $a;
}
$blocked_by_date = [];
foreach ($blocked_raw as $b) {
    $blocked_by_date[$b['blocked_date']] = $b['reason'];
}

$first_day_of_week = intval(date('w', strtotime($month_start)));
$days_in_month     = intval(date('t', strtotime($month_start)));

$status_dot = [
    'pending'   => '#f39c12',
    'confirmed' => '#2980b9',
    'completed' => '#27ae60',
    'cancelled' => '#e74c3c',
    'no-show'   => '#95a5a6',
];
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include '../../includes/head.php'; ?>
<style>
.calendar-wrap { border-radius: 12px; overflow: hidden; border: 1px solid var(--gray-200); }
.cal-header-row { display: grid; grid-template-columns: repeat(7,1fr); }
.cal-dow { padding: 10px 0; text-align: center; font-size: 0.75rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: 0.06em; color: var(--gray-500);
    background: var(--gray-50); border-bottom: 1px solid var(--gray-200); }
.cal-grid { display: grid; grid-template-columns: repeat(7,1fr); }
.cal-cell { min-height: 110px; padding: 6px 7px; border-right: 1px solid var(--gray-100);
    border-bottom: 1px solid var(--gray-100); background: var(--white);
    vertical-align: top; transition: background 0.15s; }
.cal-cell:nth-child(7n) { border-right: none; }
.cal-cell.empty { background: var(--gray-50); opacity: 0.5; }
.cal-cell.today { background: var(--blue-50) !important; }
.cal-cell.blocked { background: var(--danger-bg); }
.cal-cell.has-appts { cursor: pointer; }
.cal-cell.has-appts:hover { background: var(--gray-50); }
.day-num { font-size: 0.78rem; font-weight: 700; color: var(--gray-600); margin-bottom: 4px;
    display: flex; align-items: center; gap: 4px; }
.day-num.is-today { color: #2563eb; }
.today-dot { width: 20px; height: 20px; background: #2563eb; border-radius: 50%;
    color: #fff; font-size: 0.65rem; display: flex; align-items: center; justify-content: center; }
.appt-chip { display: flex; align-items: center; gap: 4px; margin-bottom: 3px;
    border-radius: 5px; padding: 2px 6px; font-size: 0.7rem; font-weight: 600;
    cursor: pointer; border-left: 3px solid; transition: opacity 0.15s; white-space: nowrap;
    overflow: hidden; text-overflow: ellipsis; max-width: 100%; }
.appt-chip:hover { opacity: 0.82; }
.appt-chip .status-dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
.appt-chip .chip-time { font-size: 0.62rem; opacity: 0.75; flex-shrink: 0; }
.more-pill { font-size: 0.68rem; color: var(--blue-500); font-weight: 600; cursor: pointer;
    margin-top: 2px; display: inline-block; }
.more-pill:hover { text-decoration: underline; }
.blocked-tag { font-size: 0.68rem; color: #c0392b; background: #ffe0e0;
    border-radius: 4px; padding: 1px 5px; display: inline-block; }

/* service legend pills */
.svc-legend { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 14px; }
.svc-pill { font-size: 0.7rem; padding: 3px 9px; border-radius: 20px;
    font-weight: 600; border-left: 3px solid; }

/* modal */
.day-modal-appt { display: flex; align-items: center; gap: 10px;
    padding: 10px 14px; border-radius: 8px; margin-bottom: 8px; border-left: 4px solid; }
.day-modal-appt .appt-time { font-size: 0.8rem; font-weight: 700; min-width: 60px; }
.day-modal-appt .appt-info { flex: 1; }
.day-modal-appt .appt-name { font-weight: 600; font-size: 0.875rem; }
.day-modal-appt .appt-svc  { font-size: 0.75rem; color: var(--gray-500); }
</style>
</head>
<body>
<?php include '../../includes/sidebar.php'; ?>
<div class="main-content">
    <?php include '../../includes/header.php'; ?>
    <div class="page-content">

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5>Appointment Calendar</h5>
            <div style="display:flex;gap:8px;">
                <a href="list.php?walkin=1" class="btn btn-success btn-sm"><i class="bi bi-person-walking"></i> Walk-in</a>
                <a href="add.php" class="btn btn-primary btn-sm"><i class="bi bi-plus"></i> Book Appointment</a>
            </div>
        </div>

        <!-- Month nav -->
        <div class="d-flex align-items-center gap-3 mb-3">
            <a href="calendar.php?month=<?php echo $prev_month; ?>&year=<?php echo $prev_year; ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-left"></i></a>
            <h6 class="mb-0" style="min-width:120px;text-align:center;"><?php echo $month_label; ?></h6>
            <a href="calendar.php?month=<?php echo $next_month; ?>&year=<?php echo $next_year; ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-right"></i></a>
            <a href="calendar.php" class="btn btn-sm btn-outline-primary">Today</a>
            <a href="list.php" class="btn btn-sm btn-outline-secondary ms-auto"><i class="bi bi-list-ul"></i> List View</a>
        </div>

        <!-- Doctor color legend -->
        <?php if (!empty($all_doctors_raw)): ?>
        <div class="svc-legend">
            <?php foreach ($all_doctors_raw as $dr):
                $c = $doctor_color_map[$dr['id']] ?? $default_color;
            ?>
            <span class="svc-pill" style="background:<?php echo $c['bg']; ?>;border-color:<?php echo $c['border']; ?>;color:<?php echo $c['text']; ?>;">
                <?php echo htmlspecialchars($dr['full_name']); ?>
            </span>
            <?php endforeach; ?>
            <span class="svc-pill" style="background:#f0f3f4;border-color:#95a5a6;color:#5d6d7e;">Unassigned</span>
            <span class="svc-pill" style="background:#fff5f5;border-color:#e74c3c;color:#c0392b;">Blocked</span>
        </div>
        <?php endif; ?>

        <!-- Status legend -->
        <div class="d-flex gap-3 mb-3 flex-wrap" style="font-size:0.75rem;">
            <?php foreach ($status_dot as $s => $color): ?>
            <span style="display:flex;align-items:center;gap:5px;">
                <span style="width:8px;height:8px;border-radius:50%;background:<?php echo $color; ?>;display:inline-block;"></span>
                <?php echo ucfirst($s); ?>
            </span>
            <?php endforeach; ?>
        </div>

        <!-- Calendar grid -->
        <div class="calendar-wrap">
            <div class="cal-header-row">
                <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $dow): ?>
                <div class="cal-dow"><?php echo $dow; ?></div>
                <?php endforeach; ?>
            </div>
            <div class="cal-grid">
            <?php
            $day = 1;
            $total_cells = ceil(($first_day_of_week + $days_in_month) / 7) * 7;
            for ($i = 0; $i < $total_cells; $i++):
                if ($i < $first_day_of_week || $day > $days_in_month):
            ?>
                <div class="cal-cell empty"></div>
            <?php else:
                $date_str   = sprintf('%04d-%02d-%02d', $year, $month, $day);
                $is_today   = ($date_str === $today);
                $is_blocked = isset($blocked_by_date[$date_str]);
                $day_appts  = $appts_by_date[$date_str] ?? [];
                $has_appts  = !empty($day_appts) || $is_blocked;
                $cell_cls   = implode(' ', array_filter(['cal-cell', $is_today?'today':'', $is_blocked?'blocked':'', $has_appts?'has-appts':'']));
            ?>
                <div class="<?php echo $cell_cls; ?>" <?php echo $has_appts ? "onclick=\"viewDay('$date_str')\"" : ''; ?>>
                    <div class="day-num <?php echo $is_today ? 'is-today' : ''; ?>">
                        <?php if ($is_today): ?>
                            <span class="today-dot"><?php echo $day; ?></span>
                        <?php else: ?>
                            <?php echo $day; ?>
                        <?php endif; ?>
                    </div>

                    <?php if ($is_blocked): ?>
                        <span class="blocked-tag">🚫 Closed</span>
                    <?php endif; ?>

                    <?php
                    $shown = 0;
                    foreach ($day_appts as $a):
                        if ($shown >= 3) break;
                        // Color pill by doctor; fall back to default if unassigned
                        $c    = isset($a['doctor_id']) && $a['doctor_id'] ? ($doctor_color_map[$a['doctor_id']] ?? $default_color) : $default_color;
                        $dot  = $status_dot[$a['status']] ?? '#95a5a6';
                        $time = date('h:i A', strtotime($a['appointment_time']));
                        $name = ucwords(strtolower($a['patient_name']));
                        $first = explode(' ', $name)[0];
                        $doc_label = !empty($a['doctor_name']) ? ' | ' . $a['doctor_name'] : '';
                    ?>
                    <div class="appt-chip"
                         style="background:<?php echo $c['bg']; ?>;border-color:<?php echo $c['border']; ?>;color:<?php echo $c['text']; ?>;"
                         title="<?php echo htmlspecialchars($time.' — '.$name.' | '.($a['service_name']??'').$doc_label.' | '.ucfirst($a['status'])); ?>">
                        <span class="status-dot" style="background:<?php echo $dot; ?>;"></span>
                        <span class="chip-time"><?php echo $time; ?></span>
                        <span style="overflow:hidden;text-overflow:ellipsis;"><?php echo htmlspecialchars($first); ?></span>
                    </div>
                    <?php $shown++; endforeach; ?>

                    <?php $remaining = count($day_appts) - $shown;
                    if ($remaining > 0): ?>
                        <span class="more-pill">+<?php echo $remaining; ?> more</span>
                    <?php endif; ?>
                </div>
            <?php $day++; endif; endfor; ?>
            </div>
        </div>

    </div>
</div>

<!-- Day Detail Modal -->
<div class="modal fade" id="dayModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="border-radius:14px;border:none;box-shadow:0 20px 60px rgba(0,0,0,0.15);">
            <div class="modal-header" style="border-bottom:1px solid var(--gray-200);padding:18px 22px;">
                <div>
                    <h6 class="modal-title mb-0" id="dayModalTitle">Appointments</h6>
                    <div id="dayModalSub" style="font-size:0.78rem;color:var(--gray-400);margin-top:2px;"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="dayModalBody" style="padding:18px 22px;"></div>
            <div class="modal-footer" style="border-top:1px solid var(--gray-200);padding:12px 22px;">
                <a id="dayListLink" href="#" class="btn btn-sm btn-outline-primary"><i class="bi bi-list-ul"></i> Full List for This Day</a>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
<script>
var dayModal     = new bootstrap.Modal(document.getElementById('dayModal'));
var allAppts     = <?php echo json_encode($appts_by_date); ?>;
var doctorColors = <?php echo json_encode($doctor_color_map); ?>;
var defaultC     = <?php echo json_encode($default_color); ?>;
var statusDot    = <?php echo json_encode($status_dot); ?>;

function ucwords(str) {
    return str.toLowerCase().replace(/(^|\s)\S/g, l => l.toUpperCase());
}

function viewDay(date) {
    var appts = allAppts[date] || [];
    var d = new Date(date + 'T12:00:00');
    var label = d.toLocaleDateString('en-PH', { weekday:'long', year:'numeric', month:'long', day:'numeric' });

    document.getElementById('dayModalTitle').textContent = label;
    document.getElementById('dayModalSub').textContent = appts.length + ' appointment' + (appts.length !== 1 ? 's' : '');
    document.getElementById('dayListLink').href = 'list.php?date=' + date;

    var body = '';
    if (appts.length === 0) {
        body = '<p style="color:var(--gray-400);text-align:center;padding:24px 0;"><i class="bi bi-calendar-x" style="font-size:2rem;display:block;margin-bottom:8px;"></i>No appointments on this day.</p>';
    } else {
        appts.sort((a,b) => a.appointment_time.localeCompare(b.appointment_time));
        appts.forEach(function(a) {
            var c    = (a.doctor_id && doctorColors[a.doctor_id]) ? doctorColors[a.doctor_id] : defaultC;
            var dot  = statusDot[a.status] || '#95a5a6';
            var t    = new Date('1970-01-01T' + a.appointment_time);
            var time = t.toLocaleTimeString('en-PH', {hour:'2-digit', minute:'2-digit'});
            var name = ucwords(a.patient_name || '');
            body += '<div class="day-modal-appt" style="background:'+c.bg+';border-color:'+c.border+';">';
            body += '<div class="appt-time" style="color:'+c.text+'">'+time+'</div>';
            body += '<div class="appt-info">';
            body += '<div class="appt-name">'+name+'</div>';
            body += '<div class="appt-svc">'+(a.service_name||'No service specified')+'</div>';
            if (a.doctor_name) body += '<div style="font-size:0.72rem;color:'+c.text+';font-weight:600;margin-top:2px;"><i class="bi bi-person-badge"></i> '+a.doctor_name+'</div>';
            body += '</div>';
            body += '<span style="display:flex;align-items:center;gap:4px;font-size:0.72rem;font-weight:600;color:'+dot+';">';
            body += '<span style="width:7px;height:7px;border-radius:50%;background:'+dot+';display:inline-block;"></span>';
            body += a.status.charAt(0).toUpperCase()+a.status.slice(1);
            body += '</span>';
            body += '<a href="../../modules/treatments/add.php?patient_id='+a.patient_id+'&appointment_id='+a.id+'" class="btn btn-sm btn-success" style="flex-shrink:0;" title="Check-in"><i class="bi bi-person-check"></i></a>';
            body += '</div>';
        });
    }
    document.getElementById('dayModalBody').innerHTML = body;
    dayModal.show();
}
</script>
</body>
</html>

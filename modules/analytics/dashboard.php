<?php
// Admin-only analytics: patient growth, appointment trends, revenue charts.

require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_admin();

$page_title = 'Analytics';

// --- KPI Queries ---
$new_patients = (int)$conn->query("
    SELECT COUNT(*) as c FROM patients
    WHERE created_at >= DATE_FORMAT(NOW(),'%Y-%m-01')
")->fetch_assoc()['c'];

$returning = (int)$conn->query("
    SELECT COUNT(DISTINCT patient_id) as c
    FROM appointments
    WHERE appointment_date >= DATE_FORMAT(NOW(),'%Y-%m-01')
    AND patient_id NOT IN (
        SELECT id FROM patients WHERE created_at >= DATE_FORMAT(NOW(),'%Y-%m-01')
    )
")->fetch_assoc()['c'];

$revenue = (float)$conn->query("
    SELECT COALESCE(SUM(amount_paid), 0) as total
    FROM bills
    WHERE DATE(created_at) >= DATE_FORMAT(NOW(),'%Y-%m-01')
")->fetch_assoc()['total'];

$status_breakdown = $conn->query("
    SELECT status, COUNT(*) as total
    FROM appointments
    WHERE appointment_date >= DATE_FORMAT(NOW(),'%Y-%m-01')
    GROUP BY status
")->fetch_all(MYSQLI_ASSOC);

$status_map = [];
foreach ($status_breakdown as $row) { $status_map[$row['status']] = (int)$row['total']; }
$total_this_month = array_sum($status_map);
$completed_count  = $status_map['completed'] ?? 0;
$rate = $total_this_month > 0 ? round(($completed_count / $total_this_month) * 100, 1) : 0;

// --- Previous Month KPIs (for trend arrows) ---
$prev_new_patients = (int)$conn->query("
    SELECT COUNT(*) as c FROM patients
    WHERE created_at >= DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 1 MONTH),'%Y-%m-01')
      AND created_at <  DATE_FORMAT(NOW(),'%Y-%m-01')
")->fetch_assoc()['c'];

$prev_returning = (int)$conn->query("
    SELECT COUNT(DISTINCT patient_id) as c
    FROM appointments
    WHERE appointment_date >= DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 1 MONTH),'%Y-%m-01')
      AND appointment_date <  DATE_FORMAT(NOW(),'%Y-%m-01')
      AND patient_id NOT IN (
          SELECT id FROM patients
          WHERE created_at >= DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 1 MONTH),'%Y-%m-01')
            AND created_at <  DATE_FORMAT(NOW(),'%Y-%m-01')
      )
")->fetch_assoc()['c'];

$prev_revenue = (float)$conn->query("
    SELECT COALESCE(SUM(amount_paid), 0) as total FROM bills
    WHERE DATE(created_at) >= DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 1 MONTH),'%Y-%m-01')
      AND DATE(created_at) <  DATE_FORMAT(NOW(),'%Y-%m-01')
")->fetch_assoc()['total'];

$prev_status = $conn->query("
    SELECT status, COUNT(*) as total FROM appointments
    WHERE appointment_date >= DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 1 MONTH),'%Y-%m-01')
      AND appointment_date <  DATE_FORMAT(NOW(),'%Y-%m-01')
    GROUP BY status
")->fetch_all(MYSQLI_ASSOC);
$prev_map = [];
foreach ($prev_status as $r) { $prev_map[$r['status']] = (int)$r['total']; }
$prev_total = array_sum($prev_map);
$prev_rate  = $prev_total > 0 ? round((($prev_map['completed'] ?? 0) / $prev_total) * 100, 1) : 0;

// Helper: returns trend badge HTML
function trend_badge(float $now, float $prev): string {
    if ($prev == 0) {
        if ($now == 0) return '<span style="color:var(--gray-400);font-size:0.75rem;">No data last month</span>';
        return '<span style="color:#16a34a;font-size:0.75rem;font-weight:600;"><i class="bi bi-arrow-up-short"></i>New this month</span>';
    }
    $pct = round((($now - $prev) / $prev) * 100, 1);
    if ($pct > 0)     return '<span style="color:#16a34a;font-size:0.75rem;font-weight:600;"><i class="bi bi-arrow-up-short"></i>'.abs($pct).'% vs last month</span>';
    elseif ($pct < 0) return '<span style="color:#dc2626;font-size:0.75rem;font-weight:600;"><i class="bi bi-arrow-down-short"></i>'.abs($pct).'% vs last month</span>';
    else              return '<span style="color:var(--gray-400);font-size:0.75rem;">Same as last month</span>';
}

// --- Quick-Stat Queries (today & pending) ---
$today_appts = (int)$conn->query("
    SELECT COUNT(*) as c FROM appointments
    WHERE appointment_date = CURDATE()
    AND status NOT IN ('cancelled','no-show')
")->fetch_assoc()['c'];

$pending_bills = (float)$conn->query("
    SELECT COALESCE(SUM(amount_due - amount_paid), 0) as total
    FROM bills WHERE status != 'paid'
")->fetch_assoc()['total'];

$new_this_week = (int)$conn->query("
    SELECT COUNT(*) as c FROM patients
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
")->fetch_assoc()['c'];

// --- Chart Queries (granularity-aware) ---
$granularity = $_GET['granularity'] ?? 'month';
$allowed_gran = ['day', 'week', 'month'];
if (!in_array($granularity, $allowed_gran)) $granularity = 'month';

if ($granularity === 'day') {
    $appts_per_month = $conn->query("
        SELECT DATE_FORMAT(appointment_date, '%b %d') as month,
               DATE_FORMAT(appointment_date, '%Y-%m-%d') as sort_key,
               COUNT(*) as total
        FROM appointments
        WHERE appointment_date >= DATE_SUB(NOW(), INTERVAL 14 DAY)
        GROUP BY sort_key, month ORDER BY sort_key ASC
    ")->fetch_all(MYSQLI_ASSOC);
} elseif ($granularity === 'week') {
    $appts_per_month = $conn->query("
        SELECT CONCAT('Wk ', WEEK(appointment_date), ' ', YEAR(appointment_date)) as month,
               CONCAT(YEAR(appointment_date), '-', LPAD(WEEK(appointment_date),2,'0')) as sort_key,
               COUNT(*) as total
        FROM appointments
        WHERE appointment_date >= DATE_SUB(NOW(), INTERVAL 8 WEEK)
        GROUP BY sort_key, month ORDER BY sort_key ASC
    ")->fetch_all(MYSQLI_ASSOC);
} else {
    $appts_per_month = $conn->query("
        SELECT DATE_FORMAT(appointment_date, '%b %Y') as month,
               DATE_FORMAT(appointment_date, '%Y-%m') as sort_key,
               COUNT(*) as total
        FROM appointments
        WHERE appointment_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY sort_key, month ORDER BY sort_key ASC
    ")->fetch_all(MYSQLI_ASSOC);
}

$revenue_per_month = $conn->query("
    SELECT DATE_FORMAT(created_at, '%b %Y') as month,
           DATE_FORMAT(created_at, '%Y-%m') as sort_key,
           COALESCE(SUM(amount_paid), 0) as total
    FROM bills
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY sort_key, month ORDER BY sort_key ASC
")->fetch_all(MYSQLI_ASSOC);

// --- No-Show Rate & Lost Revenue ---
$noshow_count     = $status_map['no-show']   ?? 0;
$cancelled_count  = $status_map['cancelled'] ?? 0;
$noshow_rate      = $total_this_month > 0 ? round(($noshow_count / $total_this_month) * 100, 1) : 0;
$avg_rev_per_appt = ($completed_count > 0 && $revenue > 0) ? ($revenue / $completed_count) : 0;
$lost_revenue     = round(($noshow_count + $cancelled_count) * $avg_rev_per_appt);

// --- Patient Retention ---
$total_patients_this_month = $new_patients + $returning;
$retention_pct = $total_patients_this_month > 0 ? round(($returning    / $total_patients_this_month) * 100) : 0;
$new_pct       = $total_patients_this_month > 0 ? round(($new_patients / $total_patients_this_month) * 100) : 0;

// --- Doctor Performance ---
$doctor_performance = $conn->query("
    SELECT
        s.full_name as doctor_name,
        COUNT(a.id) as total_appointments,
        COALESCE(SUM(b.amount_paid), 0) as total_revenue
    FROM appointments a
    JOIN doctors s ON a.doctor_id = s.id
    LEFT JOIN bills b ON b.appointment_id = a.id
    WHERE a.appointment_date >= DATE_FORMAT(NOW(),'%Y-%m-01')
      AND a.status = 'completed'
    GROUP BY s.id, doctor_name
    ORDER BY total_revenue DESC
    LIMIT 8
");
$doctor_performance = $doctor_performance ? $doctor_performance->fetch_all(MYSQLI_ASSOC) : [];

// --- Revenue Forecast (linear trend on last 3 months) ---
$rev_totals = array_column($revenue_per_month, 'total');
$last3      = array_slice($rev_totals, -3);
if (count($last3) >= 2) {
    $slope    = ($last3[count($last3)-1] - $last3[0]) / max(count($last3) - 1, 1);
    $forecast = max(0, round(end($last3) + $slope));
} elseif (count($last3) === 1) {
    $forecast = (int)$last3[0];
} else {
    $forecast = 0;
}
$next_month_label = date('M Y', strtotime('first day of next month'));

// --- Busiest Hours Heatmap ---
$heatmap_raw = $conn->query("
    SELECT DAYOFWEEK(appointment_date) as day_num,
           DAYNAME(appointment_date)   as day_name,
           HOUR(appointment_time)      as hour_num,
           COUNT(*) as total
    FROM appointments
    WHERE appointment_time IS NOT NULL
    GROUP BY day_num, day_name, hour_num
    ORDER BY day_num, hour_num
");
$heatmap_raw = $heatmap_raw ? $heatmap_raw->fetch_all(MYSQLI_ASSOC) : [];
$heatmap = []; $heatmap_max = 1;
foreach ($heatmap_raw as $row) {
    $heatmap[$row['day_num']][$row['hour_num']] = (int)$row['total'];
    if ((int)$row['total'] > $heatmap_max) $heatmap_max = (int)$row['total'];
}

// --- New vs Returning per Month (stacked bar chart) ---
$patient_breakdown = $conn->query("
    SELECT
        DATE_FORMAT(a.appointment_date, '%b %Y') as month,
        DATE_FORMAT(a.appointment_date, '%Y-%m') as sort_key,
        SUM(CASE WHEN DATE_FORMAT(p.created_at,'%Y-%m') = DATE_FORMAT(a.appointment_date,'%Y-%m') THEN 1 ELSE 0 END) as new_count,
        SUM(CASE WHEN DATE_FORMAT(p.created_at,'%Y-%m') != DATE_FORMAT(a.appointment_date,'%Y-%m') THEN 1 ELSE 0 END) as returning_count
    FROM appointments a
    JOIN patients p ON a.patient_id = p.id
    WHERE a.appointment_date >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY sort_key, month
    ORDER BY sort_key ASC
");
$patient_breakdown = $patient_breakdown ? $patient_breakdown->fetch_all(MYSQLI_ASSOC) : [];

// Encode for JS
$am_labels     = json_encode(array_column($appts_per_month,     'month'));
$am_data       = json_encode(array_column($appts_per_month,     'total'));
$rev_labels    = json_encode(array_column($revenue_per_month,   'month'));
$rev_data      = json_encode(array_column($revenue_per_month,   'total'));
$status_labels = json_encode(array_keys($status_map));
$status_data   = json_encode(array_values($status_map));
$doc_labels    = json_encode(array_column($doctor_performance,  'doctor_name'));
$doc_appts     = json_encode(array_column($doctor_performance,  'total_appointments'));
$doc_rev       = json_encode(array_column($doctor_performance,  'total_revenue'));
$pb_labels     = json_encode(array_column($patient_breakdown,   'month'));
$pb_new        = json_encode(array_column($patient_breakdown,   'new_count'));
$pb_returning  = json_encode(array_column($patient_breakdown,   'returning_count'));
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include '../../includes/head.php'; ?></head>
<body>
<?php include '../../includes/sidebar.php'; ?>
<div class="main-content">
    <?php include '../../includes/header.php'; ?>
    <div class="page-content">

        <div class="page-header" style="margin-bottom:24px;">
            <div>
                <h5>Analytics Dashboard</h5>
                <p style="color:var(--gray-500);font-size:0.85rem;margin:0;">
                    <i class="bi bi-calendar3"></i>
                    <?php echo date('F Y'); ?> — current month snapshot
                </p>
            </div>
        </div>

        <!-- Time granularity filter -->
        <div style="display:flex; gap:8px; margin-bottom:24px; align-items:center;">
            <span style="font-size:0.82rem; color:var(--gray-500); margin-right:4px;">View by:</span>
            <?php foreach (['day' => 'Per Day (last 14 days)', 'week' => 'Per Week (last 8 weeks)', 'month' => 'Per Month (last 6 months)'] as $val => $label): ?>
            <a href="?granularity=<?php echo $val; ?>"
               class="btn btn-sm <?php echo $granularity === $val ? 'btn-primary' : 'btn-outline-secondary'; ?>">
                <?php echo $label; ?>
            </a>
            <?php endforeach; ?>
        </div>

        <!-- ROW 0: Quick Stats (Today) -->
        <div class="row g-3 mb-3">
            <div class="col-md-4 col-sm-6">
                <div style="background:#2563eb;border-radius:14px;padding:18px 22px;display:flex;align-items:center;gap:16px;color:#fff;">
                    <div style="width:48px;height:48px;background:rgba(255,255,255,0.15);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0;">
                        <i class="bi bi-calendar-check-fill"></i>
                    </div>
                    <div>
                        <div style="font-size:0.75rem;opacity:0.8;margin-bottom:2px;">Today's Appointments</div>
                        <div style="font-size:2rem;font-weight:800;line-height:1;"><?php echo $today_appts; ?></div>
                        <div style="font-size:0.72rem;opacity:0.7;margin-top:2px;"><?php echo date('l, M d'); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 col-sm-6">
                <div style="background:#dc2626;border-radius:14px;padding:18px 22px;display:flex;align-items:center;gap:16px;color:#fff;">
                    <div style="width:48px;height:48px;background:rgba(255,255,255,0.15);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0;">
                        <i class="bi bi-receipt-cutoff"></i>
                    </div>
                    <div>
                        <div style="font-size:0.75rem;opacity:0.8;margin-bottom:2px;">Pending / Unpaid Bills</div>
                        <div style="font-size:1.6rem;font-weight:800;line-height:1;">₱<?php echo number_format($pending_bills, 0); ?></div>
                        <div style="font-size:0.72rem;opacity:0.7;margin-top:2px;">Uncollected balance</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 col-sm-12">
                <div style="background:#16a34a;border-radius:14px;padding:18px 22px;display:flex;align-items:center;gap:16px;color:#fff;">
                    <div style="width:48px;height:48px;background:rgba(255,255,255,0.15);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0;">
                        <i class="bi bi-person-plus-fill"></i>
                    </div>
                    <div>
                        <div style="font-size:0.75rem;opacity:0.8;margin-bottom:2px;">New Patients This Week</div>
                        <div style="font-size:2rem;font-weight:800;line-height:1;"><?php echo $new_this_week; ?></div>
                        <div style="font-size:0.72rem;opacity:0.7;margin-top:2px;">Last 7 days</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ROW 1: KPI Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-3 col-sm-6">
                <div class="stat-card">
                    <div class="stat-icon blue"><i class="bi bi-person-plus-fill"></i></div>
                    <div class="stat-info">
                        <div class="stat-label">New Patients</div>
                        <div class="stat-value"><?php echo $new_patients; ?></div>
                        <div class="stat-sub"><?php echo trend_badge($new_patients, $prev_new_patients); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-card">
                    <div class="stat-icon cyan"><i class="bi bi-arrow-repeat"></i></div>
                    <div class="stat-info">
                        <div class="stat-label">Returning Patients</div>
                        <div class="stat-value"><?php echo $returning; ?></div>
                        <div class="stat-sub"><?php echo trend_badge($returning, $prev_returning); ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-card">
                    <div class="stat-icon green"><i class="bi bi-cash-coin"></i></div>
                    <div class="stat-info">
                        <div class="stat-label">Revenue</div>
                        <div class="stat-value" style="font-size:1.3rem;">₱<?php echo number_format($revenue, 0); ?></div>
                        <div class="stat-sub">
                            <?php echo trend_badge($revenue, $prev_revenue); ?>
                            <?php if ($revenue == 0): ?>
                                <a href="<?php echo BASE_URL; ?>modules/billing/create.php"
                                   style="display:inline-flex;align-items:center;gap:4px;margin-top:5px;font-size:0.75rem;font-weight:600;color:#fff;background:#16a34a;border-radius:5px;padding:3px 9px;text-decoration:none;">
                                    <i class="bi bi-plus-circle-fill"></i> Record Payment
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-card">
                    <div class="stat-icon yellow"><i class="bi bi-graph-up-arrow"></i></div>
                    <div class="stat-info">
                        <div class="stat-label">Completion Rate</div>
                        <div class="stat-value"><?php echo $rate; ?>%</div>
                        <div class="stat-sub"><?php echo trend_badge($rate, $prev_rate); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ROW 2: Appointments (8col) + Status Breakdown (4col) -->
        <div class="row g-3 mb-4">
            <div class="col-lg-8">
                <div class="card h-100">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <span><i class="bi bi-calendar-check" style="color:#2563eb;margin-right:6px;"></i>Appointments</span>
                        <span style="font-size:0.78rem;color:var(--gray-400);">
                            <?php echo $granularity === 'day' ? 'Last 14 days' : ($granularity === 'week' ? 'Last 8 weeks' : 'Last 6 months'); ?>
                        </span>
                    </div>
                    <div class="card-body" style="padding:20px 24px;">
                        <div style="position:relative;height:220px;">
                            <canvas id="apptsChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card h-100">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <span><i class="bi bi-pie-chart-fill" style="color:#2563eb;margin-right:6px;"></i>Status Breakdown</span>
                        <span style="font-size:0.78rem;color:var(--gray-400);">This month</span>
                    </div>
                    <div class="card-body" style="display:flex;flex-direction:column;align-items:center;justify-content:center;padding:20px 24px;">
                        <div style="position:relative;width:200px;height:200px;">
                            <canvas id="statusChart"></canvas>
                        </div>
                        <p style="font-size:0.72rem;color:var(--gray-400);margin:8px 0 0;text-align:center;">
                            <i class="bi bi-cursor-fill"></i> Click a slice to filter appointments
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- ROW 2b: New vs Returning Patients Stacked Bar -->
        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <span><i class="bi bi-bar-chart-fill" style="color:#2563eb;margin-right:6px;"></i>New vs Returning Patients</span>
                        <span style="font-size:0.78rem;color:var(--gray-400);">Last 6 months — stacked by patient type</span>
                    </div>
                    <div class="card-body" style="padding:20px 24px;">
                        <div style="position:relative;height:220px;">
                            <canvas id="stackedPatientChart"></canvas>
                        </div>
                        <div style="display:flex;gap:20px;justify-content:center;margin-top:12px;font-size:0.78rem;color:var(--gray-500);">
                            <span><span style="display:inline-block;width:12px;height:12px;border-radius:3px;background:#2563eb;margin-right:5px;vertical-align:middle;"></span>New Patients</span>
                            <span><span style="display:inline-block;width:12px;height:12px;border-radius:3px;background:#16a34a;margin-right:5px;vertical-align:middle;"></span>Returning Patients</span>
                            <span style="color:var(--gray-400);font-style:italic;">Total bar height = total appointments that month</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ROW 3: Revenue+Forecast inline (6col) + No-Show Gauge (3col) + Retention (3col) -->
        <div class="row g-3 mb-4">
            <div class="col-lg-6">
                <div class="card h-100">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <span><i class="bi bi-cash-stack" style="color:#16a34a;margin-right:6px;"></i>Revenue per Month</span>
                        <span style="font-size:0.78rem;color:var(--gray-400);">Last 6 months</span>
                    </div>
                    <div class="card-body" style="padding:20px 24px;">
                        <div style="position:relative;height:180px;">
                            <canvas id="revenueChart"></canvas>
                        </div>
                        <?php if ($forecast > 0): ?>
                        <div style="margin-top:12px;padding:10px 14px;background:rgba(22,163,74,0.07);border:1px solid rgba(22,163,74,0.15);border-radius:8px;display:flex;justify-content:space-between;align-items:center;">
                            <span style="font-size:0.78rem;color:var(--gray-500);"><i class="bi bi-graph-up" style="margin-right:4px;"></i>Projected next month</span>
                            <span style="font-size:1rem;font-weight:700;color:#16a34a;">₱<?php echo number_format($forecast); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-3">
                <div class="card h-100">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <span><i class="bi bi-exclamation-triangle-fill" style="color:#f59e0b;margin-right:6px;"></i>No-Show Rate</span>
                        <span style="font-size:0.78rem;color:var(--gray-400);">This month</span>
                    </div>
                    <div class="card-body" style="display:flex;flex-direction:column;align-items:center;justify-content:center;padding:20px 24px;gap:10px;">
                        <div style="position:relative;width:160px;height:88px;">
                            <canvas id="gaugeChart"></canvas>
                            <div style="position:absolute;bottom:0;left:50%;transform:translateX(-50%);text-align:center;line-height:1;">
                                <div style="font-size:1.6rem;font-weight:700;color:<?php echo $noshow_rate <= 10 ? '#16a34a' : ($noshow_rate <= 20 ? '#f59e0b' : '#dc2626'); ?>;">
                                    <?php echo $noshow_rate; ?>%
                                </div>
                                <div style="font-size:0.7rem;color:var(--gray-400);">no-show rate</div>
                            </div>
                        </div>
                        <div style="font-size:0.75rem;text-align:center;">
                            <?php if ($noshow_rate <= 10): ?>
                                <span style="color:#16a34a;font-weight:600;"><i class="bi bi-check-circle-fill"></i> Healthy</span>
                            <?php elseif ($noshow_rate <= 20): ?>
                                <span style="color:#f59e0b;font-weight:600;"><i class="bi bi-exclamation-circle-fill"></i> Warning</span>
                            <?php else: ?>
                                <span style="color:#dc2626;font-weight:600;"><i class="bi bi-x-circle-fill"></i> Critical</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($lost_revenue > 0): ?>
                        <div style="background:rgba(220,38,38,0.07);border:1px solid rgba(220,38,38,0.15);border-radius:8px;padding:7px 10px;width:100%;text-align:center;">
                            <div style="font-size:0.68rem;color:var(--gray-500);">Est. lost revenue</div>
                            <div style="font-size:1rem;font-weight:700;color:#dc2626;">₱<?php echo number_format($lost_revenue); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-3">
                <div class="card h-100">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <span><i class="bi bi-person-check-fill" style="color:#2563eb;margin-right:6px;"></i>Retention</span>
                        <span style="font-size:0.78rem;color:var(--gray-400);">This month</span>
                    </div>
                    <div class="card-body" style="padding:20px 24px;">
                        <?php if ($total_patients_this_month > 0): ?>
                        <div style="margin-bottom:16px;">
                            <div style="display:flex;justify-content:space-between;margin-bottom:5px;">
                                <span style="font-size:0.78rem;color:var(--gray-600);">New</span>
                                <span style="font-size:0.78rem;font-weight:600;"><?php echo $new_patients; ?> (<?php echo $new_pct; ?>%)</span>
                            </div>
                            <div style="height:10px;background:var(--gray-100);border-radius:6px;overflow:hidden;">
                                <div style="height:100%;width:<?php echo $new_pct; ?>%;background:linear-gradient(90deg,#2563eb,#60a5fa);border-radius:6px;"></div>
                            </div>
                        </div>
                        <div style="margin-bottom:20px;">
                            <div style="display:flex;justify-content:space-between;margin-bottom:5px;">
                                <span style="font-size:0.78rem;color:var(--gray-600);">Returning</span>
                                <span style="font-size:0.78rem;font-weight:600;"><?php echo $returning; ?> (<?php echo $retention_pct; ?>%)</span>
                            </div>
                            <div style="height:10px;background:var(--gray-100);border-radius:6px;overflow:hidden;">
                                <div style="height:100%;width:<?php echo $retention_pct; ?>%;background:linear-gradient(90deg,#16a34a,#4ade80);border-radius:6px;"></div>
                            </div>
                        </div>
                        <div style="text-align:center;background:rgba(22,163,74,0.07);border-radius:10px;padding:12px;">
                            <div style="font-size:2rem;font-weight:800;color:#16a34a;"><?php echo $retention_pct; ?>%</div>
                            <div style="font-size:0.72rem;color:var(--gray-500);">Retention rate</div>
                        </div>
                        <?php else: ?>
                        <div style="height:100%;display:flex;align-items:center;justify-content:center;color:var(--gray-400);font-size:0.85rem;padding:40px 0;text-align:center;">
                            No patient data this month
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ROW 4: Doctor Performance (full width) -->
        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <span><i class="bi bi-person-badge-fill" style="color:#2563eb;margin-right:6px;"></i>Doctor Performance</span>
                        <span style="font-size:0.78rem;color:var(--gray-400);">Completed appointments &amp; revenue — this month</span>
                    </div>
                    <div class="card-body" style="padding:20px 24px;">
                        <div style="position:relative;height:240px;">
                            <canvas id="doctorChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ROW 5: Busiest Hours Heatmap (full width) -->
        <div class="row g-3 mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <span><i class="bi bi-fire" style="color:#f97316;margin-right:6px;"></i>Busiest Hours Heatmap</span>
                        <span style="font-size:0.78rem;color:var(--gray-400);">Appointments by day &amp; time slot — all time</span>
                    </div>
                    <div class="card-body" style="padding:20px 24px;overflow-x:auto;">
                        <?php
                        $days_map   = [2=>'Mon',3=>'Tue',4=>'Wed',5=>'Thu',6=>'Fri',7=>'Sat',1=>'Sun'];
                        $hours_show = [8,9,10,11,12,13,14,15,16,17];
                        $hour_label = function($h) { return $h < 12 ? $h.'AM' : ($h == 12 ? '12PM' : ($h-12).'PM'); };
                        ?>
                        <?php if (!empty($heatmap_raw)): ?>
                        <table style="border-collapse:collapse;width:100%;min-width:600px;">
                            <thead>
                                <tr>
                                    <th style="padding:6px 10px;font-size:0.75rem;color:var(--gray-400);text-align:left;font-weight:500;">Day</th>
                                    <?php foreach ($hours_show as $h): ?>
                                    <th style="padding:6px 8px;font-size:0.73rem;color:var(--gray-400);text-align:center;font-weight:500;"><?php echo $hour_label($h); ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($days_map as $dnum => $dname): ?>
                                <tr>
                                    <td style="padding:5px 10px;font-size:0.8rem;font-weight:600;color:var(--gray-600);white-space:nowrap;"><?php echo $dname; ?></td>
                                    <?php foreach ($hours_show as $h): ?>
                                    <?php
                                        $val       = $heatmap[$dnum][$h] ?? 0;
                                        $intensity = $heatmap_max > 0 ? $val / $heatmap_max : 0;
                                        $alpha     = round($intensity * 0.85 + ($val > 0 ? 0.12 : 0), 2);
                                        $bg        = $val > 0 ? "rgba(249,115,22,{$alpha})" : 'rgba(100,116,139,0.08)';
                                    ?>
                                    <td style="padding:4px;text-align:center;">
                                        <div style="width:100%;min-width:36px;height:32px;background:<?php echo $bg; ?>;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:0.75rem;font-weight:<?php echo $val > 0 ? '600' : '400'; ?>;color:<?php echo $val > 0 ? ($intensity > 0.5 ? '#fff' : '#ea580c') : 'var(--gray-400)'; ?>;">
                                            <?php echo $val > 0 ? $val : '·'; ?>
                                        </div>
                                    </td>
                                    <?php endforeach; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div style="display:flex;align-items:center;gap:8px;margin-top:12px;font-size:0.72rem;color:var(--gray-400);">
                            <span>Low</span>
                            <?php for ($i = 1; $i <= 6; $i++): $a = round(0.12 + ($i/6)*0.73, 2); ?>
                            <div style="width:22px;height:14px;background:rgba(249,115,22,<?php echo $a; ?>);border-radius:3px;"></div>
                            <?php endfor; ?>
                            <span>High</span>
                        </div>
                        <?php else: ?>
                        <div style="text-align:center;padding:40px 0;color:var(--gray-400);font-size:0.85rem;">
                            No appointment time data available yet
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<?php include '../../includes/footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
var isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark'
          || document.documentElement.getAttribute('data-theme') === 'dark';
var gridColor  = isDark ? 'rgba(255,255,255,0.07)' : 'rgba(0,0,0,0.06)';
var tickColor  = isDark ? '#8a9bb0' : '#64748b';
var legendColor= isDark ? '#b0bec5' : '#475569';

function noDataPlugin(message) {
    return {
        id: 'noData',
        afterDraw(chart) {
            if (!chart.data.datasets[0].data.length ||
                chart.data.datasets[0].data.every(v => v == 0)) {
                var ctx = chart.ctx, w = chart.width, h = chart.height;
                chart.clear();
                ctx.save();
                ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
                ctx.fillStyle = tickColor;
                ctx.font = '13px DM Sans, sans-serif';
                ctx.fillText(message || 'No data yet', w / 2, h / 2);
                ctx.restore();
            }
        }
    };
}

var scaleDefaults = {
    grid: { color: gridColor },
    ticks: { color: tickColor, font: { size: 12 } }
};

// 1. Appointments — area line (blue)
new Chart(document.getElementById('apptsChart'), {
    type: 'line',
    plugins: [noDataPlugin('No appointments yet')],
    data: {
        labels: <?php echo $am_labels ?: '[]'; ?>,
        datasets: [{
            label: 'Appointments',
            data: <?php echo $am_data ?: '[]'; ?>,
            borderColor: '#2563eb',
            backgroundColor: 'rgba(37,99,235,0.08)',
            tension: 0.4, fill: true, pointRadius: 4,
            pointBackgroundColor: '#2563eb', pointBorderColor: '#fff', pointBorderWidth: 2
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { ...scaleDefaults, grid: { display: false } },
            y: { ...scaleDefaults, beginAtZero: true, ticks: { ...scaleDefaults.ticks, stepSize: 1 } }
        }
    }
});

// 2. Status doughnut — clickable slices
var statusColors = {
    pending:   '#f59e0b',
    confirmed: '#2563eb',
    completed: '#16a34a',
    cancelled: '#dc2626',
    'no-show': '#64748b'
};
var sLabels = <?php echo $status_labels ?: '[]'; ?>;
var sData   = <?php echo $status_data   ?: '[]'; ?>;
var sBg     = sLabels.map(l => statusColors[l] || '#94a3b8');
var apptListUrl = '<?php echo BASE_URL; ?>modules/appointments/list.php';

new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    plugins: [noDataPlugin('No appointments this month')],
    data: {
        labels: sLabels,
        datasets: [{ data: sData, backgroundColor: sBg, borderWidth: 3,
                     borderColor: isDark ? '#1e2535' : '#ffffff', hoverOffset: 8 }]
    },
    options: {
        responsive: true, maintainAspectRatio: false, cutout: '65%',
        plugins: {
            legend: { display: true, position: 'bottom',
                labels: { color: legendColor, font: { size: 11 }, padding: 10, boxWidth: 12 } },
            tooltip: { callbacks: {
                label: ctx => ' ' + ctx.label + ': ' + ctx.parsed + ' appointment' + (ctx.parsed !== 1 ? 's' : '')
            }}
        },
        onClick: function(evt, elements) {
            if (!elements.length) return;
            window.location.href = apptListUrl + '?status=' + encodeURIComponent(sLabels[elements[0].index]);
        },
        onHover: function(evt, elements) {
            evt.native.target.style.cursor = elements.length ? 'pointer' : 'default';
        }
    }
});

// 3. Revenue — bar (green)
new Chart(document.getElementById('revenueChart'), {
    type: 'bar',
    plugins: [noDataPlugin('No revenue recorded yet')],
    data: {
        labels: <?php echo $rev_labels ?: '[]'; ?>,
        datasets: [{
            label: 'Revenue (₱)',
            data: <?php echo $rev_data ?: '[]'; ?>,
            backgroundColor: 'rgba(22,163,74,0.75)',
            borderRadius: 6, borderSkipped: false
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false },
            tooltip: { callbacks: {
                label: ctx => ' ₱' + Number(ctx.parsed.y).toLocaleString('en-PH', {minimumFractionDigits: 0})
            }}
        },
        scales: {
            x: { ...scaleDefaults, grid: { display: false } },
            y: { ...scaleDefaults, beginAtZero: true, ticks: { ...scaleDefaults.ticks,
                callback: v => { if (v >= 1000000) return '₱'+(v/1000000).toFixed(1).replace(/.0$/,'')+'M';
                                 if (v >= 1000)    return '₱'+(v/1000).toFixed(1).replace(/.0$/,'')+'K';
                                 return '₱'+v; } }}
        }
    }
});

// 4. No-Show Gauge — semicircle doughnut
(function() {
    var rate      = <?php echo $noshow_rate; ?>;
    var remaining = Math.max(0, 100 - rate);
    var gaugeColor = rate <= 10 ? '#16a34a' : (rate <= 20 ? '#f59e0b' : '#dc2626');
    var trackColor = isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.07)';
    new Chart(document.getElementById('gaugeChart'), {
        type: 'doughnut',
        data: { datasets: [{
            data: [rate, remaining],
            backgroundColor: [gaugeColor, trackColor],
            borderWidth: 0, circumference: 180, rotation: -90
        }]},
        options: {
            responsive: true, maintainAspectRatio: false, cutout: '75%',
            plugins: { legend: { display: false }, tooltip: { enabled: false } },
            animation: { duration: 1000 }
        }
    });
})();

// 5. Doctor Performance — grouped bar (blue + green)
(function() {
    var dLabels = <?php echo $doc_labels ?: '[]'; ?>;
    var dAppts  = <?php echo $doc_appts  ?: '[]'; ?>;
    var dRev    = <?php echo $doc_rev    ?: '[]'; ?>;
    new Chart(document.getElementById('doctorChart'), {
        type: 'bar',
        plugins: [noDataPlugin('No completed appointments this month')],
        data: {
            labels: dLabels,
            datasets: [
                {
                    label: 'Appointments',
                    data: dAppts,
                    backgroundColor: 'rgba(37,99,235,0.75)',
                    borderRadius: 5, borderSkipped: false,
                    yAxisID: 'yAppts'
                },
                {
                    label: 'Revenue (₱)',
                    data: dRev,
                    backgroundColor: 'rgba(22,163,74,0.75)',
                    borderRadius: 5, borderSkipped: false,
                    yAxisID: 'yRev'
                }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { display: true, position: 'top',
                    labels: { color: legendColor, font: { size: 11 }, padding: 10, boxWidth: 12 } },
                tooltip: { callbacks: {
                    label: ctx => ctx.dataset.label === 'Revenue (₱)'
                        ? ' ₱' + Number(ctx.parsed.y).toLocaleString('en-PH')
                        : ' ' + ctx.parsed.y + ' appts'
                }}
            },
            scales: {
                x: { ...scaleDefaults, grid: { display: false },
                     ticks: { ...scaleDefaults.ticks, maxRotation: 20, font: { size: 11 } } },
                yAppts: { ...scaleDefaults, beginAtZero: true, position: 'left',
                          ticks: { ...scaleDefaults.ticks, stepSize: 1 },
                          title: { display: true, text: 'Appts', color: tickColor, font: { size: 10 } } },
                yRev:   { ...scaleDefaults, beginAtZero: true, position: 'right',
                          grid: { display: false },
                          ticks: { ...scaleDefaults.ticks,
                              callback: v => v >= 1000 ? '₱'+(v/1000).toFixed(0)+'K' : '₱'+v },
                          title: { display: true, text: 'Revenue', color: tickColor, font: { size: 10 } } }
            }
        }
    });
})();

// 6. New vs Returning Patients — stacked bar
(function() {
    var pbLabels     = <?php echo $pb_labels    ?: '[]'; ?>;
    var pbNew        = <?php echo $pb_new       ?: '[]'; ?>;
    var pbReturning  = <?php echo $pb_returning ?: '[]'; ?>;
    new Chart(document.getElementById('stackedPatientChart'), {
        type: 'bar',
        plugins: [noDataPlugin('No appointment data yet')],
        data: {
            labels: pbLabels,
            datasets: [
                {
                    label: 'New Patients',
                    data: pbNew,
                    backgroundColor: 'rgba(37,99,235,0.80)',
                    borderRadius: 0,
                    borderSkipped: false,
                    stack: 'patients'
                },
                {
                    label: 'Returning Patients',
                    data: pbReturning,
                    backgroundColor: 'rgba(22,163,74,0.80)',
                    borderRadius: 6,
                    borderSkipped: 'bottom',
                    stack: 'patients'
                }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        afterBody: function(items) {
                            var total = (pbNew[items[0].dataIndex] || 0) + (pbReturning[items[0].dataIndex] || 0);
                            return 'Total: ' + total + ' appointments';
                        }
                    }
                }
            },
            scales: {
                x: { ...scaleDefaults, grid: { display: false }, stacked: true },
                y: { ...scaleDefaults, stacked: true, beginAtZero: true,
                     ticks: { ...scaleDefaults.ticks, stepSize: 1 } }
            }
        }
    });
})();
</script>
</body>
</html>
<?php
// Main dashboard: stat cards, visit flow guide, recent appointments, quick actions.

require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/auth.php';

$page_title = 'Dashboard';

$today       = date('Y-m-d');
$month_start = date('Y-m-01');

$total_patients       = (int)$conn->query("SELECT COUNT(*) as c FROM patients WHERE is_active = 1")->fetch_assoc()['c'];
$todays_appointments  = (int)$conn->query("SELECT COUNT(*) as c FROM appointments WHERE appointment_date = '$today'")->fetch_assoc()['c'];
$pending_appointments = (int)$conn->query("SELECT COUNT(*) as c FROM appointments WHERE status = 'pending'")->fetch_assoc()['c'];
$completed_month      = (int)$conn->query("SELECT COUNT(*) as c FROM appointments WHERE status = 'completed' AND appointment_date >= '$month_start'")->fetch_assoc()['c'];
$revenue_month        = (float)$conn->query("SELECT COALESCE(SUM(amount_paid),0) as c FROM bills WHERE DATE(created_at) >= '$month_start'")->fetch_assoc()['c'];

$notif_stmt = $conn->prepare("SELECT * FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND is_read = 0 ORDER BY created_at DESC LIMIT 5");
$notif_stmt->bind_param('i', $current_user_id);
$notif_stmt->execute();
$notifications = $notif_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$notif_stmt->close();

$recent_appts_result = $conn->query("
    SELECT a.*, CONCAT(p.first_name,' ',p.last_name) as patient_name,
           s.service_name, d.full_name as doctor_name
    FROM appointments a
    LEFT JOIN patients p ON a.patient_id = p.id
    LEFT JOIN services s ON a.service_id = s.id
    LEFT JOIN doctors  d ON a.doctor_id  = d.id
    ORDER BY a.created_at DESC LIMIT 8
");
$recent_appts = $recent_appts_result ? $recent_appts_result->fetch_all(MYSQLI_ASSOC) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include 'includes/head.php'; ?></head>
<body>

<?php include 'includes/sidebar.php'; ?>

<div class="main-content">
    <?php include 'includes/header.php'; ?>

    <div class="page-content">

        <!-- Stat Cards — all clickable -->
        <div class="row g-3 mb-4">
            <div class="col-md-3 col-sm-6">
                <a href="modules/patients/list.php" style="text-decoration:none;">
                    <div class="stat-card" style="cursor:pointer;">
                        <div class="stat-icon blue"><i class="bi bi-people-fill"></i></div>
                        <div class="stat-info">
                            <div class="stat-label">Total Patients</div>
                            <div class="stat-value"><?php echo number_format($total_patients); ?></div>
                            <div class="stat-sub">All registered patients <i class="bi bi-arrow-right" style="font-size:0.7rem;"></i></div>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-md-3 col-sm-6">
                <a href="modules/appointments/list.php?date=<?php echo $today; ?>" style="text-decoration:none;">
                    <div class="stat-card" style="cursor:pointer;">
                        <div class="stat-icon cyan"><i class="bi bi-calendar-day"></i></div>
                        <div class="stat-info">
                            <div class="stat-label">Today's Appointments</div>
                            <div class="stat-value"><?php echo $todays_appointments; ?></div>
                            <div class="stat-sub"><?php echo date('F d, Y'); ?> <i class="bi bi-arrow-right" style="font-size:0.7rem;"></i></div>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-md-3 col-sm-6">
                <a href="modules/appointments/list.php?status=pending" style="text-decoration:none;">
                    <div class="stat-card" style="cursor:pointer;">
                        <div class="stat-icon yellow"><i class="bi bi-hourglass-split"></i></div>
                        <div class="stat-info">
                            <div class="stat-label">Pending</div>
                            <div class="stat-value"><?php echo $pending_appointments; ?></div>
                            <div class="stat-sub">Awaiting confirmation <i class="bi bi-arrow-right" style="font-size:0.7rem;"></i></div>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-md-3 col-sm-6">
                <a href="modules/appointments/list.php?status=completed" style="text-decoration:none;">
                    <div class="stat-card" style="cursor:pointer;">
                        <div class="stat-icon green"><i class="bi bi-check-circle-fill"></i></div>
                        <div class="stat-info">
                            <div class="stat-label">Completed This Month</div>
                            <div class="stat-value"><?php echo $completed_month; ?></div>
                            <div class="stat-sub">Revenue: ₱<?php echo number_format($revenue_month, 2); ?> <i class="bi bi-arrow-right" style="font-size:0.7rem;"></i></div>
                        </div>
                    </div>
                </a>
            </div>
        </div>

        <!-- Notifications -->
        <?php if (!empty($notifications)): ?>
        <div class="mb-4">
            <?php foreach ($notifications as $n): ?>
            <div class="alert alert-info alert-dismissible">
                <i class="bi bi-bell-fill"></i>
                <div>
                    <strong><?php echo htmlspecialchars($n['title']); ?></strong> —
                    <?php echo htmlspecialchars($n['message']); ?>
                    <span style="font-size:0.75rem;color:var(--gray-400);margin-left:8px;">
                        <?php echo date('M d, h:i A', strtotime($n['created_at'])); ?>
                    </span>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"
                        onclick="markRead(<?php echo $n['id']; ?>)"></button>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Patient Visit Flow Guide -->
        <div class="card mb-4" style="background:linear-gradient(135deg,var(--blue-900),var(--blue-700));border:none;">
            <div class="card-body" style="padding:18px 22px;">
                <div style="font-family:'Outfit',sans-serif;font-weight:700;font-size:0.82rem;color:rgba(255,255,255,0.65);text-transform:uppercase;letter-spacing:0.06em;margin-bottom:12px;">
                    <i class="bi bi-signpost-2"></i> Patient Visit Flow — click any step to go there
                </div>
                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                    <?php
                    $flow_steps = [
                        ['bi-calendar-check-fill', 'Appointment',       'modules/appointments/list.php'],
                        ['bi-person-check-fill',   'Check-in',          'modules/appointments/list.php'],
                        ['bi-journal-medical',     'Record Treatment',  'modules/treatments/add.php'],
                        ['bi-receipt',             'Create Bill',       'modules/billing/create.php'],
                        ['bi-check-circle-fill',   'Done ✓',            'modules/appointments/list.php'],
                    ];
                    foreach ($flow_steps as $i => [$icon, $label, $url]):
                    ?>
                    <a href="<?php echo BASE_URL . $url; ?>"
                       style="display:flex;align-items:center;gap:7px;padding:8px 14px;background:rgba(255,255,255,0.12);border-radius:8px;color:white;text-decoration:none;font-size:0.8rem;font-weight:600;white-space:nowrap;"
                       onmouseover="this.style.background='rgba(255,255,255,0.22)'"
                       onmouseout="this.style.background='rgba(255,255,255,0.12)'">
                        <i class="bi <?php echo $icon; ?>"></i> <?php echo $label; ?>
                    </a>
                    <?php if ($i < count($flow_steps) - 1): ?>
                    <i class="bi bi-arrow-right" style="color:rgba(255,255,255,0.35);"></i>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <div style="font-size:0.72rem;color:rgba(255,255,255,0.4);margin-top:8px;">
                    For walk-in patients, start at <a href="<?php echo BASE_URL; ?>modules/appointments/list.php?walkin=1" style="color:rgba(255,255,255,0.65);">Walk-in Registration</a> instead of Appointment.
                </div>
            </div>
        </div>

        <!-- Quick Actions + Recent Appointments -->
        <div class="row g-3">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        <i class="bi bi-clock-history" style="color:var(--blue-500);"></i>
                        Recent Appointments
                        <a href="modules/appointments/list.php" class="btn btn-sm btn-outline-primary ms-auto">View All</a>
                    </div>
                    <div class="card-body p-0">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Code</th>
                                    <th>Patient</th>
                                    <th>Service</th>
                                    <th>Date & Time</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recent_appts)): ?>
                                    <tr><td colspan="5" style="text-align:center;padding:32px;color:var(--gray-400);">
                                        <i class="bi bi-calendar-x" style="font-size:2rem;display:block;margin-bottom:8px;"></i>
                                        No appointments yet
                                    </td></tr>
                                <?php else: ?>
                                    <?php foreach ($recent_appts as $a): ?>
                                    <tr>
                                        <td style="font-weight:600;color:var(--blue-500);font-size:0.8rem;">
                                            <?php echo htmlspecialchars($a['appointment_code']); ?>
                                        </td>
                                        <td style="font-weight:500;"><?php echo htmlspecialchars(ucwords(strtolower($a['patient_name']))); ?></td>
                                        <td style="color:var(--gray-500);font-size:0.82rem;">
                                            <?php echo htmlspecialchars($a['service_name'] ?? '—'); ?>
                                            <?php if (!empty($a['doctor_name'])): ?>
                                                <br><span style="font-size:0.72rem;color:var(--primary);font-weight:600;">
                                                    <i class="bi bi-person-badge"></i> <?php echo htmlspecialchars($a['doctor_name']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-size:0.82rem;">
                                            <?php echo date('M d, Y', strtotime($a['appointment_date'])); ?>
                                            <span style="color:var(--gray-400);">
                                                <?php echo date('h:i A', strtotime($a['appointment_time'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php
                                                echo match($a['status']) {
                                                    'pending'   => 'warning',
                                                    'confirmed' => 'primary',
                                                    'completed' => 'success',
                                                    'cancelled' => 'danger',
                                                    'no-show'   => 'secondary',
                                                    default     => 'secondary'
                                                };
                                            ?>">
                                                <?php echo ucfirst($a['status']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header">
                        <i class="bi bi-lightning-fill" style="color:var(--blue-500);"></i>
                        Quick Actions
                    </div>
                    <div class="card-body" style="display:flex;flex-direction:column;gap:10px;">
                        <a href="modules/patients/add.php" class="btn btn-primary">
                            <i class="bi bi-person-plus"></i> Add New Patient
                        </a>
                        <a href="modules/appointments/add.php" class="btn btn-outline-primary">
                            <i class="bi bi-calendar-plus"></i> Book Appointment
                        </a>
                        <a href="modules/appointments/list.php?walkin=1" class="btn btn-success">
                            <i class="bi bi-person-walking"></i> Walk-in
                        </a>
                        <a href="modules/appointments/calendar.php" class="btn btn-outline-secondary">
                            <i class="bi bi-calendar3"></i> View Calendar
                        </a>
                        <?php if (is_admin()): ?>
                        <a href="modules/analytics/dashboard.php" class="btn btn-outline-secondary">
                            <i class="bi bi-bar-chart-line"></i> Analytics
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /page-content -->
</div><!-- /main-content -->

<?php include 'includes/footer.php'; ?>
<script>
function markRead(id) {
    fetch('<?php echo BASE_URL; ?>api/notifications.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'mark_read', id: id })
    });
}
</script>
</body>
</html>

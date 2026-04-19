<?php
// sidebar.php — Left navigation sidebar shown on every admin page.
$initials = strtoupper(substr($current_user_name ?? 'U', 0, 1));

// Returns 'active' CSS class if the current URL contains the given path segment
function nav_active($path) {
    return strpos($_SERVER['PHP_SELF'], $path) !== false ? 'active' : '';
}
?>
<div id="sidebar">

    <div class="sidebar-brand">
        <div class="sidebar-brand-icon">🦷</div>
        <div class="sidebar-brand-text">
            <span class="brand-name">DentalCare</span>
            <span class="brand-sub">Clinic System</span>
        </div>
    </div>

    <ul class="sidebar-nav">

        <li><span class="nav-section-label">Main</span></li>
        <li>
            <a href="<?php echo BASE_URL; ?>dashboard.php" class="<?php echo nav_active('dashboard'); ?>">
                <i class="bi bi-house-door-fill"></i><span class="nav-label">Dashboard</span>
            </a>
        </li>

        <li><span class="nav-section-label">Patients</span></li>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/patients/list.php" class="<?php echo nav_active('/patients/list'); ?>">
                <i class="bi bi-people-fill"></i><span class="nav-label">Patient Records</span>
            </a>
        </li>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/treatments/list.php" class="<?php echo nav_active('/treatments/'); ?>">
                <i class="bi bi-journal-medical"></i><span class="nav-label">Dental Records</span>
            </a>
        </li>

        <li><span class="nav-section-label">Appointments</span></li>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/appointments/list.php" class="<?php echo nav_active('/appointments/list'); ?>">
                <i class="bi bi-calendar-check-fill"></i><span class="nav-label">Appointments</span>
            </a>
        </li>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/appointments/calendar.php" class="<?php echo nav_active('/appointments/calendar'); ?>">
                <i class="bi bi-calendar3"></i><span class="nav-label">Calendar</span>
            </a>
        </li>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/schedule/manage.php" class="<?php echo nav_active('/schedule/'); ?>">
                <i class="bi bi-clock-history"></i><span class="nav-label">Schedule</span>
            </a>
        </li>

        <li><span class="nav-section-label">Billing</span></li>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/billing/list.php" class="<?php echo nav_active('/billing/'); ?>">
                <i class="bi bi-receipt"></i><span class="nav-label">Billing</span>
            </a>
        </li>

        <?php if (is_admin()): ?>
        <li><span class="nav-section-label">Admin</span></li>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/analytics/dashboard.php" class="<?php echo nav_active('/analytics/'); ?>">
                <i class="bi bi-bar-chart-fill"></i><span class="nav-label">Analytics</span>
            </a>
        </li>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/reports/index.php" class="<?php echo nav_active('/reports/'); ?>">
                <i class="bi bi-file-earmark-bar-graph-fill"></i><span class="nav-label">Reports</span>
            </a>
        </li>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/users/list.php" class="<?php echo nav_active('/users/'); ?>">
                <i class="bi bi-person-gear"></i><span class="nav-label">Users</span>
            </a>
        </li>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/services/list.php" class="<?php echo nav_active('/services/'); ?>">
                <i class="bi bi-grid-fill"></i><span class="nav-label">Services</span>
            </a>
        </li>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/doctors/list.php" class="<?php echo nav_active('/doctors/'); ?>">
                <i class="bi bi-person-badge-fill"></i><span class="nav-label">Doctors</span>
            </a>
        </li>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/logs/activity.php" class="<?php echo nav_active('/logs/'); ?>">
                <i class="bi bi-shield-fill-check"></i><span class="nav-label">Audit Logs</span>
            </a>
        </li>
        <?php endif; ?>

    </ul>

    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="user-avatar"><?php echo $initials; ?></div>
            <div class="user-info">
                <div class="user-name"><?php echo e($current_user_name); ?></div>
                <div class="user-role"><?php echo ucfirst($current_user_role); ?></div>
            </div>
        </div>
    </div>

</div>

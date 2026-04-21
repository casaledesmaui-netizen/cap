<?php
// Audit Logs — shows all system activity. Admin-only.

require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_admin();

$page_title = 'Audit Logs';

// ── Filters ──────────────────────────────────────────────────────────
$filter_user   = trim($_GET['user']   ?? '');
$filter_module = trim($_GET['module'] ?? '');
$filter_action = trim($_GET['action'] ?? '');
$filter_date   = trim($_GET['date']   ?? '');

$per_page = 25;
$page     = max(1, intval($_GET['page'] ?? 1));

$where  = ['1=1'];
$params = [];
$types  = '';

if ($filter_user) {
    $where[]  = '(al.user_name LIKE ? OR al.user_id = ?)';
    $like      = '%' . $filter_user . '%';
    $params[]  = $like;
    $params[]  = (int)$filter_user;
    $types    .= 'si';
}
if ($filter_module) {
    $where[]  = 'al.module = ?';
    $params[]  = $filter_module;
    $types    .= 's';
}
if ($filter_action) {
    $where[]  = 'al.action LIKE ?';
    $params[]  = '%' . $filter_action . '%';
    $types    .= 's';
}
if ($filter_date) {
    $where[]  = 'DATE(al.created_at) = ?';
    $params[]  = $filter_date;
    $types    .= 's';
}

$where_sql = implode(' AND ', $where);

// Total count
$count_sql  = "SELECT COUNT(*) as c FROM audit_logs al WHERE $where_sql";
$count_stmt = $conn->prepare($count_sql);
if ($types && $count_stmt) {
    $count_stmt->bind_param($types, ...$params);
}
if ($count_stmt) {
    $count_stmt->execute();
    $total_count = (int)$count_stmt->get_result()->fetch_assoc()['c'];
    $count_stmt->close();
} else {
    $total_count = 0;
}

$total_pages = max(1, ceil($total_count / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

// Fetch logs
$data_sql  = "SELECT al.*, u.full_name as linked_name
              FROM audit_logs al
              LEFT JOIN users u ON u.id = al.user_id
              WHERE $where_sql
              ORDER BY al.created_at DESC
              LIMIT $per_page OFFSET $offset";
$data_stmt = $conn->prepare($data_sql);
if ($types && $data_stmt) {
    $data_stmt->bind_param($types, ...$params);
}
if ($data_stmt) {
    $data_stmt->execute();
    $logs = $data_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $data_stmt->close();
} else {
    $logs = [];
}

// Distinct modules for filter dropdown
$modules = $conn->query("SELECT DISTINCT module FROM audit_logs WHERE module IS NOT NULL AND module != '' ORDER BY module ASC")->fetch_all(MYSQLI_ASSOC);

// Build filter query string for pagination links
$filter_qs = http_build_query(array_filter([
    'user'   => $filter_user,
    'module' => $filter_module,
    'action' => $filter_action,
    'date'   => $filter_date,
]));
if ($filter_qs) $filter_qs .= '&';

// Action colour helper
function action_badge($action) {
    $a = strtolower($action);
    if (str_contains($a, 'delet') || str_contains($a, 'remov'))  return 'danger';
    if (str_contains($a, 'add')   || str_contains($a, 'creat') || str_contains($a, 'register')) return 'success';
    if (str_contains($a, 'edit')  || str_contains($a, 'updat') || str_contains($a, 'chang'))    return 'warning';
    if (str_contains($a, 'login') || str_contains($a, 'logout')) return 'info';
    return 'secondary';
}
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include '../../includes/head.php'; ?></head>
<body>
<?php include '../../includes/sidebar.php'; ?>
<div class="main-content">
    <?php include '../../includes/header.php'; ?>
    <div class="page-content">

        <div class="page-header">
            <div>
                <h5>Audit Logs</h5>
                <p style="color:var(--gray-500);font-size:0.82rem;margin:0;">
                    Complete record of every action performed in the system.
                </p>
            </div>
            <div class="page-header-actions">
                <span style="font-size:0.8rem;color:var(--gray-400);">
                    <i class="bi bi-shield-fill-check" style="color:var(--blue-400);"></i>
                    <?php echo number_format($total_count); ?> total entries
                </span>
            </div>
        </div>

        <!-- Filters -->
        <form method="GET" class="mb-3">
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
                <div>
                    <label class="form-label form-label-sm mb-1" style="font-size:0.75rem;color:var(--gray-500);">User</label>
                    <input type="text" name="user" class="form-control form-control-sm" placeholder="Name or ID…"
                           value="<?php echo htmlspecialchars($filter_user); ?>" style="width:160px;">
                </div>
                <div>
                    <label class="form-label form-label-sm mb-1" style="font-size:0.75rem;color:var(--gray-500);">Module</label>
                    <select name="module" class="form-select form-select-sm" style="width:150px;">
                        <option value="">All Modules</option>
                        <?php foreach ($modules as $m): ?>
                        <option value="<?php echo e($m['module']); ?>" <?php echo $filter_module === $m['module'] ? 'selected' : ''; ?>>
                            <?php echo e(ucfirst($m['module'])); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label form-label-sm mb-1" style="font-size:0.75rem;color:var(--gray-500);">Action</label>
                    <input type="text" name="action" class="form-control form-control-sm" placeholder="e.g. Deleted…"
                           value="<?php echo htmlspecialchars($filter_action); ?>" style="width:160px;">
                </div>
                <div>
                    <label class="form-label form-label-sm mb-1" style="font-size:0.75rem;color:var(--gray-500);">Date</label>
                    <input type="date" name="date" class="form-control form-control-sm"
                           value="<?php echo htmlspecialchars($filter_date); ?>" style="width:150px;">
                </div>
                <div style="display:flex;gap:6px;padding-bottom:1px;">
                    <button class="btn btn-sm btn-primary" type="submit">
                        <i class="bi bi-funnel-fill"></i> Filter
                    </button>
                    <?php if ($filter_user || $filter_module || $filter_action || $filter_date): ?>
                    <a href="activity.php" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-x-circle"></i> Clear
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </form>

        <div class="card">
            <div class="card-header">
                <span><i class="bi bi-shield-fill-check" style="color:var(--blue-400);margin-right:6px;"></i>Activity Log</span>
            </div>
            <div class="card-body p-0">
                <table class="table mb-0">
                    <thead>
                        <tr>
                            <th style="width:160px;">When</th>
                            <th style="width:160px;">User</th>
                            <th style="width:90px;">Module</th>
                            <th>Action</th>
                            <th>Details</th>
                            <th style="width:100px;">IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                        <tr><td colspan="6" class="text-center py-5" style="color:var(--gray-400);">
                            <i class="bi bi-shield-slash" style="font-size:2rem;display:block;margin-bottom:8px;opacity:0.4;"></i>
                            No log entries found<?php echo ($filter_user || $filter_module || $filter_action || $filter_date) ? ' for the selected filters' : ''; ?>.
                        </td></tr>
                        <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                        <tr>
                            <td style="font-size:0.78rem;color:var(--gray-500);white-space:nowrap;">
                                <div><?php echo date('M d, Y', strtotime($log['created_at'])); ?></div>
                                <div style="color:var(--gray-400);"><?php echo date('h:i:s A', strtotime($log['created_at'])); ?></div>
                            </td>
                            <td>
                                <div style="font-size:0.85rem;font-weight:500;"><?php echo e($log['user_name'] ?? '—'); ?></div>
                                <?php if ($log['user_id']): ?>
                                <div style="font-size:0.72rem;color:var(--gray-400);">ID #<?php echo $log['user_id']; ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($log['module']): ?>
                                <span class="badge bg-secondary" style="font-size:0.72rem;">
                                    <?php echo e(ucfirst($log['module'])); ?>
                                </span>
                                <?php else: ?>
                                <span style="color:var(--gray-300);">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-<?php echo action_badge($log['action']); ?>" style="font-size:0.75rem;">
                                    <?php echo e($log['action']); ?>
                                </span>
                                <?php if ($log['record_id']): ?>
                                <span style="font-size:0.72rem;color:var(--gray-400);margin-left:4px;">
                                    #<?php echo $log['record_id']; ?>
                                </span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:0.8rem;color:var(--gray-600);max-width:260px;">
                                <?php echo e($log['details'] ?: '—'); ?>
                            </td>
                            <td style="font-size:0.75rem;color:var(--gray-400);font-family:monospace;">
                                <?php echo e($log['ip_address'] ?? '—'); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($total_pages > 1): ?>
        <div class="pagination-bar">
            <div class="pagination-info">
                Showing <?php echo number_format($offset + 1); ?>–<?php echo number_format(min($offset + $per_page, $total_count)); ?> of <?php echo number_format($total_count); ?> entries
            </div>
            <div class="pagination-links">
                <?php if ($page > 1): ?>
                <a href="activity.php?<?php echo $filter_qs; ?>page=<?php echo $page - 1; ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-chevron-left"></i> Prev
                </a>
                <?php endif; ?>
                <?php for ($pg = max(1, $page - 2); $pg <= min($total_pages, $page + 2); $pg++): ?>
                <a href="activity.php?<?php echo $filter_qs; ?>page=<?php echo $pg; ?>"
                   class="btn btn-sm <?php echo $pg === $page ? 'btn-primary' : 'btn-outline-secondary'; ?>">
                    <?php echo $pg; ?>
                </a>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                <a href="activity.php?<?php echo $filter_qs; ?>page=<?php echo $page + 1; ?>" class="btn btn-sm btn-outline-secondary">
                    Next <i class="bi bi-chevron-right"></i>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /.page-content -->
</div><!-- /.main-content -->

<?php include '../../includes/footer.php'; ?>
</body>
</html>

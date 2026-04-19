<?php
// List all bills with filters and summary totals.

require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';

$page_title = 'Billing';

// Filters
$status_filter = $_GET['status'] ?? '';
$search        = trim($_GET['search'] ?? '');
$date_from     = $_GET['date_from'] ?? '';
$date_to       = $_GET['date_to'] ?? '';

// Whitelist status values
$allowed_statuses = ['unpaid', 'partial', 'paid'];
if (!in_array($status_filter, $allowed_statuses)) $status_filter = '';
// Validate date formats
if ($date_from && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = '';
if ($date_to   && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = '';

$where = "WHERE 1=1";
if ($status_filter) $where .= " AND b.status = '" . $conn->real_escape_string($status_filter) . "'";
if ($search) {
    $s = $conn->real_escape_string($search);
    $where .= " AND (p.first_name LIKE '%$s%' OR p.last_name LIKE '%$s%' OR b.bill_code LIKE '%$s%' OR p.patient_code LIKE '%$s%')";
}
if ($date_from) $where .= " AND DATE(b.created_at) >= '" . $conn->real_escape_string($date_from) . "'";
if ($date_to)   $where .= " AND DATE(b.created_at) <= '" . $conn->real_escape_string($date_to) . "'";

$per_page    = 20;
$page        = max(1, intval($_GET['page'] ?? 1));
$total_count = (int)$conn->query("
    SELECT COUNT(*) as c FROM bills b
    LEFT JOIN patients p ON b.patient_id = p.id
    $where
")->fetch_assoc()['c'];
$total_pages = max(1, ceil($total_count / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

$filter_parts = [];
if ($status_filter) $filter_parts[] = 'status='    . urlencode($status_filter);
if ($search)        $filter_parts[] = 'search='    . urlencode($search);
if ($date_from)     $filter_parts[] = 'date_from=' . urlencode($date_from);
if ($date_to)       $filter_parts[] = 'date_to='   . urlencode($date_to);
$filter_qs = $filter_parts ? implode('&', $filter_parts) . '&' : '';

$bills = $conn->query("
    SELECT b.*,
           CONCAT(p.first_name,' ',p.last_name) as patient_name,
           p.patient_code, p.phone,
           s.service_name,
           a.appointment_code
    FROM bills b
    LEFT JOIN patients p ON b.patient_id = p.id
    LEFT JOIN services s ON b.service_id = s.id
    LEFT JOIN appointments a ON b.appointment_id = a.id
    $where
    ORDER BY b.created_at DESC
    LIMIT $per_page OFFSET $offset
")->fetch_all(MYSQLI_ASSOC);

// Summary totals
$totals = $conn->query("
    SELECT
        COUNT(*) as total_bills,
        COALESCE(SUM(amount_due),0) as total_due,
        COALESCE(SUM(amount_paid),0) as total_paid,
        COUNT(CASE WHEN status='unpaid' THEN 1 END) as unpaid_count,
        COUNT(CASE WHEN status='partial' THEN 1 END) as partial_count,
        COUNT(CASE WHEN status='paid' THEN 1 END) as paid_count
    FROM bills
")->fetch_assoc();
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
                <h5>Billing</h5>
                <p>Manage patient payments — Cash, GCash, Bank Transfer</p>
            </div>
            <a href="create.php" class="btn btn-primary btn-sm">
                <i class="bi bi-plus"></i> Create Bill
            </a>
        </div>

        <!-- Summary Cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-3 col-sm-6">
                <div class="stat-card">
                    <div class="stat-icon blue"><i class="bi bi-receipt"></i></div>
                    <div class="stat-info">
                        <div class="stat-label">Total Bills</div>
                        <div class="stat-value"><?php echo $totals['total_bills']; ?></div>
                        <div class="stat-sub">All time</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-card">
                    <div class="stat-icon green"><i class="bi bi-cash-coin"></i></div>
                    <div class="stat-info">
                        <div class="stat-label">Total Collected</div>
                        <div class="stat-value" style="font-size:1.3rem;">₱<?php echo number_format($totals['total_paid'], 0); ?></div>
                        <div class="stat-sub">of ₱<?php echo number_format($totals['total_due'], 0); ?> billed</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-card">
                    <div class="stat-icon red"><i class="bi bi-exclamation-circle"></i></div>
                    <div class="stat-info">
                        <div class="stat-label">Unpaid</div>
                        <div class="stat-value"><?php echo $totals['unpaid_count']; ?></div>
                        <div class="stat-sub"><?php echo $totals['partial_count']; ?> partial</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-card">
                    <div class="stat-icon yellow"><i class="bi bi-check-circle"></i></div>
                    <div class="stat-info">
                        <div class="stat-label">Fully Paid</div>
                        <div class="stat-value"><?php echo $totals['paid_count']; ?></div>
                        <div class="stat-sub">Bills settled</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <form method="GET" class="row g-2 mb-3">
            <div class="col-md-3">
                <input type="text" name="search" class="form-control form-control-sm"
                    placeholder="Search patient or bill code..."
                    value="<?php echo e($search); ?>">
            </div>
            <div class="col-md-2">
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Status</option>
                    <option value="unpaid"  <?php echo $status_filter === 'unpaid'  ? 'selected' : ''; ?>>Unpaid</option>
                    <option value="partial" <?php echo $status_filter === 'partial' ? 'selected' : ''; ?>>Partial</option>
                    <option value="paid"    <?php echo $status_filter === 'paid'    ? 'selected' : ''; ?>>Paid</option>
                </select>
            </div>
            <div class="col-md-2">
                <input type="date" name="date_from" class="form-control form-control-sm"
                    value="<?php echo e($date_from); ?>">
            </div>
            <div class="col-md-2">
                <input type="date" name="date_to" class="form-control form-control-sm"
                    value="<?php echo e($date_to); ?>">
            </div>
            <div class="col-md-1">
                <button type="submit" class="btn btn-sm btn-outline-secondary w-100">Filter</button>
            </div>
            <div class="col-md-1">
                <a href="list.php" class="btn btn-sm btn-outline-danger w-100">Clear</a>
            </div>
        </form>

        <!-- Bills Table -->
        <div class="card">
            <div class="card-body p-0">
                <table class="table mb-0">
                    <thead>
                        <tr>
                            <th>Bill Code</th>
                            <th>Patient</th>
                            <th>Service</th>
                            <th>Amount Due</th>
                            <th>Amount Paid</th>
                            <th>Balance</th>
                            <th>Method</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($bills)): ?>
                        <tr>
                            <td colspan="10" style="text-align:center;padding:40px;color:var(--gray-400);">
                                <i class="bi bi-receipt" style="font-size:2rem;display:block;margin-bottom:8px;"></i>
                                No bills found.
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($bills as $b):
                            $balance = $b['amount_due'] - $b['amount_paid'];
                        ?>
                        <tr>
                            <td style="font-weight:600;color:var(--blue-500);font-size:0.8rem;">
                                <?php echo e($b['bill_code']); ?>
                            </td>
                            <td>
                                <div style="font-weight:500;"><?php echo e(ucwords(strtolower($b['patient_name']))); ?></div>
                                <div style="font-size:0.75rem;color:var(--gray-400);"><?php echo e($b['patient_code']); ?></div>
                            </td>
                            <td style="font-size:0.82rem;"><?php echo e($b['service_name'] ?? '—'); ?></td>
                            <td>₱<?php echo number_format($b['amount_due'], 2); ?></td>
                            <td style="color:var(--success);font-weight:600;">
                                ₱<?php echo number_format($b['amount_paid'], 2); ?>
                            </td>
                            <td style="color:<?php echo $balance > 0 ? 'var(--danger)' : 'var(--success)'; ?>;font-weight:600;">
                                <?php echo $balance > 0 ? '₱'.number_format($balance,2) : 'PAID'; ?>
                            </td>
                            <td>
                                <?php
                                $method_icons = [
                                    'cash'  => '💵 Cash',
                                    'gcash' => '📱 GCash',
                                    'bank'  => '🏦 Bank',
                                    'other' => '💳 Other',
                                ];
                                echo $method_icons[$b['payment_method']] ?? e($b['payment_method']);
                                ?>
                            </td>
                            <td>
                                <span class="badge bg-<?php
                                    echo match($b['status']) {
                                        'paid'    => 'success',
                                        'partial' => 'warning',
                                        'unpaid'  => 'danger',
                                        default   => 'secondary'
                                    };
                                ?>"><?php echo ucfirst($b['status']); ?></span>
                            </td>
                            <td style="font-size:0.78rem;color:var(--gray-500);">
                                <?php echo date('M d, Y', strtotime($b['created_at'])); ?>
                            </td>
                            <td>
                                <div style="display:flex;gap:5px;">
                                    <a href="view.php?id=<?php echo $b['id']; ?>"
                                       class="btn btn-sm btn-outline-info" title="View">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <?php if ($b['status'] !== 'paid'): ?>
                                    <a href="pay.php?id=<?php echo $b['id']; ?>"
                                       class="btn btn-sm btn-outline-success" title="Record Payment">
                                        <i class="bi bi-cash"></i>
                                    </a>
                                    <?php endif; ?>
                                    <a href="<?php echo BASE_URL; ?>modules/print/payment_receipt.php?bill_id=<?php echo $b['id']; ?>"
                                       target="_blank" class="btn btn-sm btn-outline-secondary" title="Print Receipt">
                                        <i class="bi bi-printer"></i>
                                    </a>
                                </div>
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
                Showing <?php echo number_format($offset + 1); ?>–<?php echo number_format(min($offset + $per_page, $total_count)); ?> of <?php echo number_format($total_count); ?> bills
            </div>
            <div class="pagination-links">
                <?php if ($page > 1): ?>
                <a href="list.php?<?php echo $filter_qs; ?>page=<?php echo $page - 1; ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-left"></i> Prev</a>
                <?php endif; ?>
                <?php for ($pg = max(1, $page - 2); $pg <= min($total_pages, $page + 2); $pg++): ?>
                <a href="list.php?<?php echo $filter_qs; ?>page=<?php echo $pg; ?>"
                   class="btn btn-sm <?php echo $pg === $page ? 'btn-primary' : 'btn-outline-secondary'; ?>"><?php echo $pg; ?></a>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                <a href="list.php?<?php echo $filter_qs; ?>page=<?php echo $page + 1; ?>" class="btn btn-sm btn-outline-secondary">Next <i class="bi bi-chevron-right"></i></a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>
<?php include '../../includes/footer.php'; ?>
</body>
</html>
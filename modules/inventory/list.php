<?php
// Inventory Management — Admin only.
// Track dental supplies, materials, and clinic stocks.
// Provides add, edit, soft-delete, and low-stock alert features.

require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_admin();

$page_title = 'Inventory';
$success = '';
$error   = '';

// ─── TOGGLE ACTIVE STATUS ────────────────────────────────────────────────────
if (isset($_GET['toggle']) && isset($_GET['iid'])) {
    $iid = secure_int($_GET['iid'] ?? 0);
    if ($iid > 0) {
        $stmt = $conn->prepare("SELECT is_active, item_name FROM inventory WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $iid);
        $stmt->execute();
        $item = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($item) {
            $new_status = $item['is_active'] ? 0 : 1;
            $stmt2 = $conn->prepare("UPDATE inventory SET is_active = ? WHERE id = ?");
            $stmt2->bind_param('ii', $new_status, $iid);
            $stmt2->execute();
            $stmt2->close();
            $label = $new_status ? 'Activated Item' : 'Deactivated Item';
            log_action($conn, $current_user_id, $current_user_name, $label, 'inventory', $iid, "Item: " . $item['item_name']);
        }
    }
    header('Location: list.php');
    exit();
}

// ─── ADD ITEM ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $item_name     = trim($_POST['item_name']     ?? '');
    $category      = trim($_POST['category']      ?? 'General');
    $quantity      = intval($_POST['quantity']     ?? 0);
    $unit          = trim($_POST['unit']           ?? 'pcs');
    $reorder_level = intval($_POST['reorder_level'] ?? 5);
    $price_per_unit = floatval($_POST['price_per_unit'] ?? 0);
    $supplier      = trim($_POST['supplier']      ?? '');
    $notes         = trim($_POST['notes']         ?? '');

    if ($item_name === '') {
        $error = 'Item name is required.';
    } elseif ($quantity < 0) {
        $error = 'Quantity cannot be negative.';
    } elseif ($reorder_level < 0) {
        $error = 'Reorder level cannot be negative.';
    } else {
        $chk = $conn->prepare("SELECT id FROM inventory WHERE item_name = ? LIMIT 1");
        $chk->bind_param('s', $item_name);
        $chk->execute();
        $dup = $chk->get_result()->num_rows > 0;
        $chk->close();

        if ($dup) {
            $error = 'An item named "' . e($item_name) . '" already exists.';
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO inventory (item_name, category, quantity, unit, reorder_level, price_per_unit, supplier, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('ssissdss', $item_name, $category, $quantity, $unit, $reorder_level, $price_per_unit, $supplier, $notes);
            $stmt->execute();
            $new_id = $stmt->insert_id;
            $stmt->close();
            log_action($conn, $current_user_id, $current_user_name, 'Added Inventory Item', 'inventory', $new_id, "Item: $item_name, Qty: $quantity $unit");
            $success = 'Item "' . e($item_name) . '" added successfully.';
        }
    }
}

// ─── EDIT ITEM ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    $iid           = secure_int($_POST['iid']         ?? 0);
    $item_name     = trim($_POST['item_name']          ?? '');
    $category      = trim($_POST['category']           ?? 'General');
    $quantity      = intval($_POST['quantity']          ?? 0);
    $unit          = trim($_POST['unit']               ?? 'pcs');
    $reorder_level = intval($_POST['reorder_level']    ?? 5);
    $price_per_unit = floatval($_POST['price_per_unit'] ?? 0);
    $supplier      = trim($_POST['supplier']           ?? '');
    $notes         = trim($_POST['notes']              ?? '');

    if ($iid <= 0 || $item_name === '') {
        $error = 'Invalid item or name.';
    } else {
        $stmt = $conn->prepare(
            "UPDATE inventory SET item_name=?, category=?, quantity=?, unit=?, reorder_level=?,
             price_per_unit=?, supplier=?, notes=? WHERE id=?"
        );
        $stmt->bind_param('ssissdssi', $item_name, $category, $quantity, $unit, $reorder_level, $price_per_unit, $supplier, $notes, $iid);
        $stmt->execute();
        $stmt->close();
        log_action($conn, $current_user_id, $current_user_name, 'Edited Inventory Item', 'inventory', $iid, "Updated: $item_name");
        $success = 'Item updated successfully.';
    }
}

// ─── RESTOCK (quick quantity update) ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restock') {
    $iid    = secure_int($_POST['iid']    ?? 0);
    $add_qty = intval($_POST['add_qty']   ?? 0);

    if ($iid > 0 && $add_qty > 0) {
        $stmt = $conn->prepare("UPDATE inventory SET quantity = quantity + ? WHERE id = ?");
        $stmt->bind_param('ii', $add_qty, $iid);
        $stmt->execute();
        $stmt->close();

        $nr = $conn->prepare("SELECT item_name, quantity, reorder_level, unit FROM inventory WHERE id = ? LIMIT 1");
        $nr->bind_param('i', $iid); $nr->execute();
        $item = $nr->get_result()->fetch_assoc(); $nr->close();
        log_action($conn, $current_user_id, $current_user_name, 'Restocked Item', 'inventory', $iid, "Added $add_qty to " . ($item['item_name'] ?? '?') . ". New qty: " . ($item['quantity'] ?? '?'));

        // ── Low stock notification check ──────────────────────────
        if ($item && $item['quantity'] <= $item['reorder_level']) {
            notify($conn, 'system', 'Low Stock Alert',
                "⚠️ " . $item['item_name'] . " is low: only " . $item['quantity'] . " " . $item['unit'] . " left (reorder level: " . $item['reorder_level'] . ").",
                'modules/inventory/list.php?filter=low');
        }
        // ─────────────────────────────────────────────────────────

        $success = 'Stock updated. New quantity: ' . ($item['quantity'] ?? '?');
    }
}

// ─── FETCH DATA ──────────────────────────────────────────────────────────────
$filter   = trim($_GET['filter'] ?? 'all');   // all | low | inactive
$search   = trim($_GET['search'] ?? '');
$per_page = 20;
$page     = max(1, intval($_GET['page'] ?? 1));

$where = "WHERE 1=1";
if ($filter === 'low')      $where .= " AND quantity <= reorder_level AND is_active = 1";
elseif ($filter === 'inactive') $where .= " AND is_active = 0";
else                             $where .= " AND is_active = 1";

if ($search !== '') {
    $s = $conn->real_escape_string($search);
    $where .= " AND (item_name LIKE '%$s%' OR category LIKE '%$s%' OR supplier LIKE '%$s%')";
}

$total_count = $conn->query("SELECT COUNT(*) as c FROM inventory $where")->fetch_assoc()['c'];
$total_pages = max(1, ceil($total_count / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

$items = $conn->query("SELECT * FROM inventory $where ORDER BY category ASC, item_name ASC LIMIT $per_page OFFSET $offset")->fetch_all(MYSQLI_ASSOC);

// Summary stats
$stats = $conn->query("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN is_active=1 THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN quantity <= reorder_level AND is_active=1 THEN 1 ELSE 0 END) as low_stock,
        COALESCE(SUM(quantity * price_per_unit), 0) as total_value
    FROM inventory
")->fetch_assoc();

// For edit modal — fetch item if requested
$edit_item = null;
if (isset($_GET['edit'])) {
    $eid = secure_int($_GET['edit']);
    $es = $conn->prepare("SELECT * FROM inventory WHERE id = ? LIMIT 1");
    $es->bind_param('i', $eid); $es->execute();
    $edit_item = $es->get_result()->fetch_assoc();
    $es->close();
}

$categories = ['General', 'Consumables', 'Instruments', 'Medications', 'PPE', 'Sterilization', 'Lab Supplies', 'Office Supplies'];
$units      = ['pcs', 'boxes', 'bottles', 'packs', 'tubes', 'pairs', 'rolls', 'liters', 'ml', 'grams', 'kg'];
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
            <h5><i class="bi bi-box-seam-fill" style="color:var(--blue-500);"></i> Inventory</h5>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="bi bi-plus-lg"></i> Add Item
            </button>
        </div>

        <?php if ($success): ?><div class="alert alert-success alert-dismissible"><i class="bi bi-check-circle-fill"></i> <?php echo $success; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
        <?php if ($error):   ?><div class="alert alert-danger  alert-dismissible"><i class="bi bi-exclamation-triangle-fill"></i> <?php echo $error; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

        <!-- Stat cards -->
        <div class="row g-3 mb-4">
            <div class="col-md-3 col-sm-6">
                <div class="stat-card">
                    <div class="stat-icon blue"><i class="bi bi-box-seam"></i></div>
                    <div class="stat-info">
                        <div class="stat-label">Total Items</div>
                        <div class="stat-value"><?php echo number_format($stats['active']); ?></div>
                        <div class="stat-sub">Active items in stock</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-card" style="cursor:pointer;" onclick="window.location='list.php?filter=low'">
                    <div class="stat-icon <?php echo $stats['low_stock'] > 0 ? 'yellow' : 'green'; ?>">
                        <i class="bi bi-exclamation-triangle<?php echo $stats['low_stock'] > 0 ? '-fill' : ''; ?>"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-label">Low Stock Alerts</div>
                        <div class="stat-value"><?php echo $stats['low_stock']; ?></div>
                        <div class="stat-sub">Items at or below reorder level</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-card">
                    <div class="stat-icon green"><i class="bi bi-currency-dollar"></i></div>
                    <div class="stat-info">
                        <div class="stat-label">Inventory Value</div>
                        <div class="stat-value">₱<?php echo number_format($stats['total_value'], 2); ?></div>
                        <div class="stat-sub">Estimated total value</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-sm-6">
                <div class="stat-card" style="cursor:pointer;" onclick="window.location='list.php?filter=inactive'">
                    <div class="stat-icon" style="background:var(--gray-100);"><i class="bi bi-archive" style="color:var(--gray-500);"></i></div>
                    <div class="stat-info">
                        <div class="stat-label">Archived</div>
                        <div class="stat-value"><?php echo number_format($stats['total'] - $stats['active']); ?></div>
                        <div class="stat-sub">Deactivated items</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters + Search -->
        <form method="GET" class="mb-3" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
            <div class="input-group" style="max-width:340px;">
                <input type="text" name="search" class="form-control" placeholder="Search item, category, supplier..." value="<?php echo e($search); ?>">
                <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
                <?php if ($search): ?><a href="list.php?filter=<?php echo e($filter); ?>" class="btn btn-outline-danger">Clear</a><?php endif; ?>
            </div>
            <div style="display:flex;gap:6px;">
                <a href="list.php?filter=all<?php echo $search ? '&search='.urlencode($search) : ''; ?>" class="btn btn-sm <?php echo $filter==='all' ? 'btn-primary' : 'btn-outline-secondary'; ?>">All Active</a>
                <a href="list.php?filter=low<?php echo $search ? '&search='.urlencode($search) : ''; ?>" class="btn btn-sm <?php echo $filter==='low' ? 'btn-warning' : 'btn-outline-warning'; ?>">
                    <i class="bi bi-exclamation-triangle"></i> Low Stock <?php if ($stats['low_stock'] > 0): ?><span class="badge bg-danger ms-1"><?php echo $stats['low_stock']; ?></span><?php endif; ?>
                </a>
                <a href="list.php?filter=inactive<?php echo $search ? '&search='.urlencode($search) : ''; ?>" class="btn btn-sm <?php echo $filter==='inactive' ? 'btn-secondary' : 'btn-outline-secondary'; ?>">Archived</a>
            </div>
        </form>

        <!-- Table -->
        <div class="card">
            <div class="card-body p-0">
                <table class="table mb-0">
                    <thead>
                        <tr>
                            <th>Item Name</th>
                            <th>Category</th>
                            <th>Quantity</th>
                            <th>Reorder At</th>
                            <th>Unit Price</th>
                            <th>Supplier</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($items)): ?>
                            <tr><td colspan="8" style="text-align:center;padding:40px;color:var(--gray-400);">
                                <i class="bi bi-box-seam" style="font-size:2rem;display:block;margin-bottom:8px;"></i>
                                <?php echo $filter === 'low' ? 'No low-stock items — you\'re well stocked!' : ($search ? 'No results found.' : 'No items yet. Add your first inventory item.'); ?>
                            </td></tr>
                        <?php else: ?>
                            <?php foreach ($items as $item): ?>
                            <?php
                                $is_low = $item['is_active'] && $item['quantity'] <= $item['reorder_level'];
                            ?>
                            <tr <?php if ($is_low) echo 'style="background:rgba(255,193,7,0.06);"'; ?>>
                                <td style="font-weight:600;">
                                    <?php echo e($item['item_name']); ?>
                                    <?php if ($is_low): ?>
                                        <span class="badge bg-warning text-dark ms-1" style="font-size:0.65rem;"><i class="bi bi-exclamation-triangle-fill"></i> Low</span>
                                    <?php endif; ?>
                                    <?php if ($item['notes']): ?>
                                        <br><small style="color:var(--gray-400);font-weight:400;"><?php echo e($item['notes']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge bg-light text-dark" style="font-size:0.75rem;"><?php echo e($item['category']); ?></span></td>
                                <td>
                                    <span style="font-size:1.05rem;font-weight:700;color:<?php echo $is_low ? 'var(--bs-warning)' : 'var(--blue-600)'; ?>;">
                                        <?php echo number_format($item['quantity']); ?>
                                    </span>
                                    <span style="color:var(--gray-400);font-size:0.78rem;"> <?php echo e($item['unit']); ?></span>
                                </td>
                                <td style="color:var(--gray-500);font-size:0.85rem;"><?php echo $item['reorder_level']; ?> <?php echo e($item['unit']); ?></td>
                                <td style="font-size:0.85rem;"><?php echo $item['price_per_unit'] > 0 ? '₱'.number_format($item['price_per_unit'],2) : '—'; ?></td>
                                <td style="font-size:0.82rem;color:var(--gray-500);"><?php echo $item['supplier'] ? e($item['supplier']) : '—'; ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $item['is_active'] ? 'success' : 'secondary'; ?>">
                                        <?php echo $item['is_active'] ? 'Active' : 'Archived'; ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="display:flex;gap:5px;flex-wrap:wrap;">
                                        <!-- Restock -->
                                        <button class="btn btn-sm btn-outline-success" title="Restock"
                                                onclick="openRestock(<?php echo $item['id']; ?>, '<?php echo e($item['item_name']); ?>', <?php echo $item['quantity']; ?>, '<?php echo e($item['unit']); ?>')">
                                            <i class="bi bi-plus-circle"></i>
                                        </button>
                                        <!-- Edit -->
                                        <button class="btn btn-sm btn-outline-secondary" title="Edit"
                                                onclick="openEdit(<?php echo htmlspecialchars(json_encode($item)); ?>)">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <!-- Toggle active -->
                                        <a href="list.php?toggle=1&iid=<?php echo $item['id']; ?>&filter=<?php echo e($filter); ?>"
                                           class="btn btn-sm <?php echo $item['is_active'] ? 'btn-outline-danger' : 'btn-outline-primary'; ?>"
                                           title="<?php echo $item['is_active'] ? 'Archive' : 'Restore'; ?>"
                                           onclick="return confirm('<?php echo $item['is_active'] ? 'Archive this item?' : 'Restore this item?'; ?>')">
                                            <i class="bi <?php echo $item['is_active'] ? 'bi-archive' : 'bi-arrow-counterclockwise'; ?>"></i>
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

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <nav class="mt-3">
            <ul class="pagination pagination-sm justify-content-center">
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                    <a class="page-link" href="list.php?filter=<?php echo e($filter); ?>&search=<?php echo urlencode($search); ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
        <?php endif; ?>

    </div>
</div>
<?php include '../../includes/footer.php'; ?>

<!-- ═══════════════ ADD MODAL ═══════════════ -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Add Inventory Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label">Item Name <span style="color:red;">*</span></label>
                            <input type="text" name="item_name" class="form-control" placeholder="e.g. Latex Gloves Medium" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Category</label>
                            <select name="category" class="form-select">
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat; ?>"><?php echo $cat; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Quantity <span style="color:red;">*</span></label>
                            <input type="number" name="quantity" class="form-control" min="0" value="0" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Unit</label>
                            <select name="unit" class="form-select">
                                <?php foreach ($units as $u): ?>
                                    <option value="<?php echo $u; ?>"><?php echo $u; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Reorder Level</label>
                            <input type="number" name="reorder_level" class="form-control" min="0" value="5">
                            <small class="text-muted">Alert when stock falls to this amount</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Price per Unit (₱)</label>
                            <input type="number" name="price_per_unit" class="form-control" min="0" step="0.01" value="0">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Supplier</label>
                            <input type="text" name="supplier" class="form-control" placeholder="Supplier name">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Optional notes..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Add Item</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══════════════ EDIT MODAL ═══════════════ -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="iid" id="edit_iid">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil"></i> Edit Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label">Item Name <span style="color:red;">*</span></label>
                            <input type="text" name="item_name" id="edit_item_name" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Category</label>
                            <select name="category" id="edit_category" class="form-select">
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat; ?>"><?php echo $cat; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Quantity <span style="color:red;">*</span></label>
                            <input type="number" name="quantity" id="edit_quantity" class="form-control" min="0" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Unit</label>
                            <select name="unit" id="edit_unit" class="form-select">
                                <?php foreach ($units as $u): ?>
                                    <option value="<?php echo $u; ?>"><?php echo $u; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Reorder Level</label>
                            <input type="number" name="reorder_level" id="edit_reorder" class="form-control" min="0">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Price per Unit (₱)</label>
                            <input type="number" name="price_per_unit" id="edit_price" class="form-control" min="0" step="0.01">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Supplier</label>
                            <input type="text" name="supplier" id="edit_supplier" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" id="edit_notes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══════════════ RESTOCK MODAL ═══════════════ -->
<div class="modal fade" id="restockModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="restock">
                <input type="hidden" name="iid" id="restock_iid">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Restock</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p style="font-size:0.9rem;" id="restock_info"></p>
                    <label class="form-label">Quantity to Add <span style="color:red;">*</span></label>
                    <input type="number" name="add_qty" id="restock_qty" class="form-control" min="1" value="10" required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success"><i class="bi bi-plus-lg"></i> Add Stock</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openEdit(item) {
    document.getElementById('edit_iid').value       = item.id;
    document.getElementById('edit_item_name').value = item.item_name;
    document.getElementById('edit_quantity').value  = item.quantity;
    document.getElementById('edit_reorder').value   = item.reorder_level;
    document.getElementById('edit_price').value     = item.price_per_unit;
    document.getElementById('edit_supplier').value  = item.supplier || '';
    document.getElementById('edit_notes').value     = item.notes || '';
    // Category
    const catSel = document.getElementById('edit_category');
    for (let o of catSel.options) if (o.value === item.category) { o.selected = true; break; }
    // Unit
    const unitSel = document.getElementById('edit_unit');
    for (let o of unitSel.options) if (o.value === item.unit) { o.selected = true; break; }
    new bootstrap.Modal(document.getElementById('editModal')).show();
}

function openRestock(id, name, qty, unit) {
    document.getElementById('restock_iid').value = id;
    document.getElementById('restock_info').textContent = name + ' — Current stock: ' + qty + ' ' + unit;
    document.getElementById('restock_qty').value = 10;
    new bootstrap.Modal(document.getElementById('restockModal')).show();
}

<?php if ($error && str_contains($error, 'already exists') === false && $_POST['action'] ?? '' === 'add'): ?>
// Reopen add modal if there was a validation error
document.addEventListener('DOMContentLoaded', () => new bootstrap.Modal(document.getElementById('addModal')).show());
<?php endif; ?>
</script>
</body>
</html>

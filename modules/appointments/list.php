<?php
// List appointments with filters. Confirm, check-in, update status, print, or delete.

require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';

$page_title = 'Appointments';

// Pre-load services for the walk-in drawer
$walkin_services = $conn->query("SELECT id, service_name, price FROM services WHERE is_active = 1 ORDER BY service_name")->fetch_all(MYSQLI_ASSOC);

// Auto-open walk-in drawer if ?walkin=1 is in the URL
$auto_open_walkin = isset($_GET['walkin']) && $_GET['walkin'] === '1';

$status_filter = $_GET['status'] ?? '';
$date_filter   = $_GET['date'] ?? '';
$doctor_filter = intval($_GET['doctor_id'] ?? 0);
$search        = trim($_GET['search'] ?? '');

// Pre-load doctors for filter dropdown
$all_doctors = $conn->query("SELECT id, full_name FROM doctors WHERE is_active = 1 ORDER BY full_name ASC")->fetch_all(MYSQLI_ASSOC);

// Whitelist status values — never interpolate raw user input into SQL
$allowed_statuses = ['pending', 'confirmed', 'completed', 'cancelled', 'no-show'];
if (!in_array($status_filter, $allowed_statuses)) $status_filter = '';
// Validate date format (YYYY-MM-DD) — reject anything that doesn't match
if ($date_filter && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_filter)) $date_filter = '';

$where = "WHERE 1=1";
if ($status_filter) $where .= " AND a.status = '" . $conn->real_escape_string($status_filter) . "'";
if ($date_filter)   $where .= " AND a.appointment_date = '" . $conn->real_escape_string($date_filter) . "'";
if ($doctor_filter) $where .= " AND a.doctor_id = $doctor_filter";
if ($search) {
    $s = $conn->real_escape_string($search);
    $where .= " AND (p.first_name LIKE '%$s%' OR p.last_name LIKE '%$s%' OR a.appointment_code LIKE '%$s%')";
}

$per_page    = 20;
$page        = max(1, intval($_GET['page'] ?? 1));
$total_count = (int)$conn->query("
    SELECT COUNT(*) as c FROM appointments a
    LEFT JOIN patients p ON a.patient_id = p.id
    $where
")->fetch_assoc()['c'];
$total_pages = max(1, ceil($total_count / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

$filter_parts = [];
if ($status_filter) $filter_parts[] = 'status=' . urlencode($status_filter);
if ($date_filter)   $filter_parts[] = 'date='   . urlencode($date_filter);
if ($doctor_filter) $filter_parts[] = 'doctor_id=' . $doctor_filter;
if ($search)        $filter_parts[] = 'search=' . urlencode($search);
$filter_qs = $filter_parts ? implode('&', $filter_parts) . '&' : '';

$appointments = $conn->query("
    SELECT a.*, CONCAT(p.first_name,' ',p.last_name) as patient_name,
           s.service_name, d.full_name as doctor_name, d.id as doctor_id
    FROM appointments a
    LEFT JOIN patients p ON a.patient_id = p.id
    LEFT JOIN services s ON a.service_id = s.id
    LEFT JOIN doctors  d ON a.doctor_id  = d.id
    $where
    ORDER BY a.appointment_date DESC, a.appointment_time ASC
    LIMIT $per_page OFFSET $offset
")->fetch_all(MYSQLI_ASSOC);

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
                <h5>Appointments</h5>
            </div>
            <div class="page-header-actions">
                <button onclick="openWalkinDrawer()" class="btn btn-success btn-sm">
                    <i class="bi bi-person-walking"></i> Walk-in
                </button>
                <a href="add.php" class="btn btn-primary btn-sm">
                    <i class="bi bi-plus"></i> Book Appointment
                </a>
            </div>
        </div>

        <!-- Filters -->
        <form method="GET" class="row g-2 mb-3">
            <div class="col-md-3">
                <input type="text" name="search" class="form-control form-control-sm" placeholder="Search patient or code..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="col-md-2">
                <input type="date" name="date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($date_filter); ?>">
            </div>
            <div class="col-md-2">
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Status</option>
                    <option value="pending"   <?php echo $status_filter === 'pending'   ? 'selected' : ''; ?>>Pending</option>
                    <option value="confirmed" <?php echo $status_filter === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                    <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                    <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    <option value="no-show"   <?php echo $status_filter === 'no-show'   ? 'selected' : ''; ?>>No-show</option>
                </select>
            </div>
            <?php if (!empty($all_doctors)): ?>
            <div class="col-md-2">
                <select name="doctor_id" class="form-select form-select-sm">
                    <option value="">All Doctors</option>
                    <?php foreach ($all_doctors as $d): ?>
                        <option value="<?php echo $d['id']; ?>" <?php echo $doctor_filter == $d['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($d['full_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-md-2">
                <button class="btn btn-sm btn-outline-secondary w-100" type="submit">Filter</button>
            </div>
            <div class="col-md-1">
                <a href="list.php" class="btn btn-sm btn-outline-danger w-100">Clear</a>
            </div>
        </form>

        <div class="card">
            <div class="card-body p-0">
                <table class="table table-hover mb-0" id="appointmentsTable">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Patient</th>
                            <th>Service</th>
                            <th>Doctor</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($appointments)): ?>
                            <tr><td colspan="8" class="text-center text-muted py-3">No appointments found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($appointments as $a): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($a['appointment_code']); ?></td>
                                <td><?php echo htmlspecialchars(ucwords(strtolower($a['patient_name']))); ?></td>
                                <td><?php echo htmlspecialchars($a['service_name'] ?? 'N/A'); ?></td>
                                <td>
                                    <?php if (!empty($a['doctor_name'])): ?>
                                        <span class="badge rounded-pill" style="background:var(--primary);opacity:0.85;font-size:0.72rem;">
                                            <?php echo htmlspecialchars($a['doctor_name']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted" style="font-size:0.8rem;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($a['appointment_date'])); ?></td>
                                <td><?php echo date('h:i A', strtotime($a['appointment_time'])); ?></td>
                                <td>
                                    <span class="badge bg-<?php
                                        echo match($a['status']) {
                                            'pending'   => 'warning',
                                            'confirmed' => 'primary',
                                            'completed' => 'success',
                                            'cancelled' => 'danger',
                                            'no-show'   => 'secondary',
                                            default     => 'light'
                                        };
                                    ?>"><?php echo ucfirst($a['status']); ?></span>
                                </td>
                                <td>
                                    <div style="display:flex;gap:5px;flex-wrap:wrap;">
                                        <?php if ($a['status'] === 'confirmed'): ?>
                                        <!-- CHECK-IN: leads to dental record then billing -->
                                        <a href="<?php echo BASE_URL; ?>modules/treatments/add.php?patient_id=<?php echo $a['patient_id']; ?>&appointment_id=<?php echo $a['id']; ?>"
                                           class="btn btn-sm btn-success" title="Check-in: Record Treatment">
                                            <i class="bi bi-person-check"></i> Check-in
                                        </a>
                                        <?php elseif ($a['status'] === 'completed'): ?>
                                        <!-- VIEW RECORD for completed appointments -->
                                        <a href="<?php echo BASE_URL; ?>modules/patients/view.php?id=<?php echo $a['patient_id']; ?>"
                                           class="btn btn-sm btn-outline-success" title="View Patient Record">
                                            <i class="bi bi-clipboard2-check"></i> Record
                                        </a>
                                        <!-- CREATE BILL if not yet billed -->
                                        <a href="<?php echo BASE_URL; ?>modules/billing/create.php?patient_id=<?php echo $a['patient_id']; ?>&appointment_id=<?php echo $a['id']; ?>"
                                           class="btn btn-sm btn-outline-primary" title="Create Bill">
                                            <i class="bi bi-receipt"></i> Bill
                                        </a>
                                        <?php elseif ($a['status'] === 'pending'): ?>
                                        <!-- CONFIRM pending appointments -->
                                        <button class="btn btn-sm btn-outline-primary" onclick="openConfirmModal(<?php echo $a['id']; ?>, '<?php echo htmlspecialchars($a['appointment_code'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($a['patient_name'], ENT_QUOTES); ?>')" title="Confirm Appointment">
                                            <i class="bi bi-check-lg"></i> Confirm
                                        </button>
                                        <?php endif; ?>
                                        <a href="<?php echo BASE_URL; ?>modules/print/appointment_slip.php?id=<?php echo $a['id']; ?>"
                                           target="_blank" class="btn btn-sm btn-outline-secondary" title="Print Slip">
                                            <i class="bi bi-printer"></i>
                                        </a>
                                        <button class="btn btn-sm btn-outline-secondary" onclick="updateStatus(<?php echo $a['id']; ?>, this)" title="Update Status">
                                            <i class="bi bi-pencil-square"></i>
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger" onclick="confirmDeleteAppt(<?php echo $a['id']; ?>, '<?php echo htmlspecialchars($a['appointment_code'], ENT_QUOTES); ?>')" title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
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
                Showing <?php echo number_format($offset + 1); ?>–<?php echo number_format(min($offset + $per_page, $total_count)); ?> of <?php echo number_format($total_count); ?> appointments
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

<!-- Status Update Modal -->
<div class="modal fade" id="statusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title">Update Appointment Status</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="appt_id">
                <label class="form-label">New Status</label>
                <select id="new_status" class="form-select">
                    <option value="pending">Pending</option>
                    <option value="confirmed">Confirmed</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                    <option value="no-show">No-show</option>
                </select>
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary btn-sm" onclick="saveStatus()">Save</button>
            </div>
        </div>
    </div>
</div>

<!-- Confirm Appointment Modal -->
<div class="modal fade" id="confirmApptModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title" style="color:var(--blue-600);"><i class="bi bi-check-circle-fill"></i> Confirm Appointment</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="font-size:0.875rem;">
                Confirm appointment <strong id="confirmApptCode"></strong> for <strong id="confirmApptPatient"></strong>?
                <div style="margin-top:10px;padding:10px;background:var(--blue-50);border-radius:6px;font-size:0.78rem;color:var(--blue-600);">
                    <i class="bi bi-info-circle-fill"></i> This will mark the appointment as <strong>Confirmed</strong> and notify the patient.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-primary" onclick="doConfirmAppt()">
                    <i class="bi bi-check-lg"></i> Yes, Confirm
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Appointment Modal -->
<div class="modal fade" id="deleteApptModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title" style="color:var(--danger);"><i class="bi bi-exclamation-triangle-fill"></i> Delete Appointment</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="font-size:0.875rem;">
                Permanently delete appointment <strong id="deleteApptCode"></strong>?
                <div style="margin-top:10px;padding:10px;background:var(--danger-bg);border-radius:6px;font-size:0.78rem;color:var(--danger);">
                    <i class="bi bi-exclamation-triangle-fill"></i> <strong>Warning:</strong> This will permanently delete the appointment and its payment records. This cannot be undone.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">No, Keep It</button>
                <button type="button" class="btn btn-sm btn-danger" onclick="doDeleteAppt()">
                    <i class="bi bi-trash"></i> Yes, Delete It
                </button>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
<script>
var statusModal = new bootstrap.Modal(document.getElementById('statusModal'));
var deleteApptModal = new bootstrap.Modal(document.getElementById('deleteApptModal'));
var deleteApptId = null;

function updateStatus(id, btn) {
    document.getElementById('appt_id').value = id;
    statusModal.show();
}

function saveStatus() {
    var id     = document.getElementById('appt_id').value;
    var status = document.getElementById('new_status').value;
    fetch('<?php echo BASE_URL; ?>api/appointments.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'update_status', id: id, status: status })
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') { statusModal.hide(); location.reload(); }
        else alert('Error: ' + data.message);
    });
}

function confirmDeleteAppt(id, code) {
    deleteApptId = id;
    document.getElementById('deleteApptCode').textContent = code;
    deleteApptModal.show();
}

function doDeleteAppt() {
    fetch('<?php echo BASE_URL; ?>api/appointments.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete_appointment', id: deleteApptId })
    })
    .then(res => res.json())
    .then(data => {
        deleteApptModal.hide();
        if (data.status === 'success') location.reload();
        else alert('Error: ' + data.message);
    });
}

// Confirm appointment modal
var confirmApptModal = new bootstrap.Modal(document.getElementById('confirmApptModal'));
var pendingConfirmId = null;
function openConfirmModal(id, code, patient) {
    pendingConfirmId = id;
    document.getElementById('confirmApptCode').textContent  = code;
    document.getElementById('confirmApptPatient').textContent = patient;
    confirmApptModal.show();
}
function doConfirmAppt() {
    if (!pendingConfirmId) return;
    confirmApptModal.hide();
    fetch('<?php echo BASE_URL; ?>api/appointments.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'update_status', id: pendingConfirmId, status: 'confirmed' })
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') location.reload();
        else alert('Error: ' + data.message);
    });
}
</script>


<div class="drawer-overlay" id="drawerOverlay" onclick="closeWalkinDrawer()"></div>

<div class="drawer-panel" id="walkinDrawer">
    <div class="drawer-head">
        <div>
            <h6><i class="bi bi-person-walking"></i> Walk-in Registration</h6>
            <p>Creates a new patient + today's appointment automatically</p>
        </div>
        <button class="drawer-close" onclick="closeWalkinDrawer()">✕</button>
    </div>
    <div class="drawer-slot-bar" id="drawerSlotBar">
        <span style="color:var(--gray-400);">Loading slot info...</span>
    </div>
    <div id="drawerAlert" style="display:none;margin:14px 22px 0;"></div>
    <div class="drawer-body">
        <form id="walkinDrawerForm" autocomplete="off">
            <input type="hidden" name="_ajax" value="1">
            <div class="row g-3">
                <div class="col-6">
                    <label class="form-label">First Name <span style="color:var(--danger)">*</span></label>
                    <input type="text" name="first_name" class="form-control" placeholder="e.g. Juan" required>
                </div>
                <div class="col-6">
                    <label class="form-label">Last Name <span style="color:var(--danger)">*</span></label>
                    <input type="text" name="last_name" class="form-control" placeholder="e.g. dela Cruz" required>
                </div>
                <div class="col-12">
                    <label class="form-label">Phone <span style="font-size:0.72rem;color:var(--gray-400);font-weight:400;">(optional)</span></label>
                    <input type="text" name="phone" class="form-control" placeholder="09XXXXXXXXX" maxlength="13">
                </div>
                <div class="col-12">
                    <label class="form-label">Service</label>
                    <select name="service_id" class="form-select">
                        <option value="">— No service selected —</option>
                        <?php foreach ($walkin_services as $sv): ?>
                        <option value="<?php echo $sv['id']; ?>">
                            <?php echo e($sv['service_name']); ?> — ₱<?php echo number_format($sv['price'],2); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php
                $walkin_doctors_list = $conn->query("SELECT id, full_name, specialization FROM doctors WHERE is_active = 1 ORDER BY full_name ASC")->fetch_all(MYSQLI_ASSOC);
                if (!empty($walkin_doctors_list)):
                ?>
                <div class="col-12">
                    <label class="form-label">Doctor <span style="font-size:0.72rem;color:var(--gray-400);font-weight:400;">(optional)</span></label>
                    <select name="doctor_id" class="form-select">
                        <option value="">Any Available Doctor</option>
                        <?php foreach ($walkin_doctors_list as $d): ?>
                        <option value="<?php echo $d['id']; ?>">
                            <?php echo e($d['full_name']); ?><?php if ($d['specialization']): ?> — <?php echo e($d['specialization']); ?><?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-12">
                    <label class="form-label">Manual Time <span style="font-size:0.72rem;color:var(--gray-400);font-weight:400;">(leave blank to auto-assign)</span></label>
                    <input type="time" name="manual_time" class="form-control">
                </div>
                <div class="col-12">
                    <label class="form-label">Notes <span style="font-size:0.72rem;color:var(--gray-400);font-weight:400;">(optional)</span></label>
                    <textarea name="notes" class="form-control" rows="2" placeholder="Chief complaint or remarks..." maxlength="500"></textarea>
                </div>
            </div>
        </form>
    </div>
    <div class="drawer-foot">
        <button type="button" class="btn btn-success" id="walkinSubmitBtn" onclick="submitWalkin()">
            <i class="bi bi-person-check-fill"></i> Register Walk-in
        </button>
        <button type="button" class="btn btn-outline-secondary" onclick="closeWalkinDrawer()">Cancel</button>
    </div>
</div>

<div id="walkinToast" style="display:none;position:fixed;bottom:28px;right:28px;z-index:2000;background:#fff;border:1.5px solid #bbf7d0;border-radius:12px;padding:14px 20px;box-shadow:0 8px 24px rgba(0,0,0,0.12);max-width:360px;animation:slideToast 0.3s ease;">
    <div style="display:flex;align-items:flex-start;gap:12px;">
        <span style="font-size:1.4rem;">✅</span>
        <div>
            <div style="font-weight:700;color:#166534;margin-bottom:3px;">Walk-in Registered!</div>
            <div id="walkinToastMsg" style="font-size:0.82rem;color:#374151;"></div>
        </div>
        <button onclick="document.getElementById('walkinToast').style.display='none'" style="background:none;border:none;color:#9ca3af;cursor:pointer;margin-left:auto;font-size:1rem;">✕</button>
    </div>
</div>

<script>
function openWalkinDrawer() {
    document.getElementById('walkinDrawer').classList.add('open');
    document.getElementById('drawerOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
    loadSlotInfo();
    document.getElementById('walkinDrawerForm').reset();
    hideDrawerAlert();
}
function closeWalkinDrawer() {
    document.getElementById('walkinDrawer').classList.remove('open');
    document.getElementById('drawerOverlay').classList.remove('open');
    document.body.style.overflow = '';
}
function loadSlotInfo() {
    var bar = document.getElementById('drawerSlotBar');
    bar.innerHTML = '<span style="color:var(--gray-400);">Checking today\'s slots...</span>';
    fetch('<?php echo BASE_URL; ?>api/walkin.php')
    .then(r => r.json())
    .then(data => {
        if (data.is_closed || data.is_full) {
            bar.innerHTML = '<i class="bi bi-exclamation-triangle-fill" style="color:#f59e0b;"></i> <strong>' + (data.reason || 'No slots available') + '</strong> Use manual time below.';
        } else {
            bar.innerHTML = '<i class="bi bi-clock-fill" style="color:#2563eb;"></i> Next available slot: <strong style="color:#2563eb;">' + data.label + '</strong> — will be auto-assigned.';
        }
    })
    .catch(() => {
        bar.innerHTML = '<span style="color:var(--gray-400);">Could not load slot info. You can still register.</span>';
    });
}
function showDrawerAlert(type, msg) {
    var el = document.getElementById('drawerAlert');
    el.style.display = 'block';
    el.innerHTML = '<div class="alert alert-' + type + '" style="margin:0;font-size:0.85rem;"><i class="bi bi-' + (type==='danger'?'x-circle-fill':'check-circle-fill') + '"></i> ' + msg + '</div>';
}
function hideDrawerAlert() { document.getElementById('drawerAlert').style.display = 'none'; }
function submitWalkin() {
    var form = document.getElementById('walkinDrawerForm');
    var btn  = document.getElementById('walkinSubmitBtn');
    var first = form.querySelector('[name=first_name]').value.trim();
    var last  = form.querySelector('[name=last_name]').value.trim();
    if (!first || !last) { showDrawerAlert('danger', 'First name and last name are required.'); return; }
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Registering...';
    hideDrawerAlert();
    fetch('<?php echo BASE_URL; ?>modules/walkin/add.php', { method: 'POST', body: new FormData(form) })
    .then(r => r.json())
    .then(res => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-person-check-fill"></i> Register Walk-in';
        if (res.status === 'success') {
            closeWalkinDrawer();
            var appt = res.appt || {};
            var timeLabel = appt.time ? new Date('1970-01-01T' + appt.time).toLocaleTimeString('en-PH',{hour:'2-digit',minute:'2-digit',hour12:true}) : '';

            // ── Inject new row at top of appointments table instantly ──
            var tbody = document.querySelector('#appointmentsTable tbody');
            if (tbody) {
                // Remove empty-state placeholder row if present
                var placeholder = tbody.querySelector('td[colspan]');
                if (placeholder) placeholder.closest('tr').remove();

                var checkinUrl = '<?php echo BASE_URL; ?>modules/treatments/add.php?patient_id=' + (appt.patient_id||'') + '&appointment_id=' + (appt.appt_id||'');
                var todayLabel = new Date().toLocaleDateString('en-PH',{month:'short',day:'2-digit',year:'numeric'});

                var tr = document.createElement('tr');
                tr.style.cssText = 'background:#f0fdf4;transition:background 3s ease;';
                tr.innerHTML =
                    '<td style="font-weight:600;color:var(--blue-500);font-size:0.8rem;">' + (appt.appt_code||'—') + '</td>' +
                    '<td style="font-weight:500;">' + (appt.patient_name||'—') + '<br><small style="color:var(--gray-400);">' + (appt.patient_code||'') + '</small></td>' +
                    '<td style="color:var(--gray-500);font-size:0.82rem;">' + (appt.service_name||'—') + '</td>' +
                    '<td style="font-size:0.82rem;">' + todayLabel + '</td>' +
                    '<td style="font-size:0.82rem;">' + timeLabel + '</td>' +
                    '<td><span class="badge bg-primary">Confirmed</span></td>' +
                    '<td><div style="display:flex;gap:5px;">' +
                        '<a href="' + checkinUrl + '" class="btn btn-sm btn-success"><i class="bi bi-person-check"></i> Check-in</a>' +
                    '</div></td>';
                tbody.insertBefore(tr, tbody.firstChild);
                // Fade highlight after 3s
                setTimeout(() => { tr.style.background = ''; }, 3000);
            }

            // Show toast
            document.getElementById('walkinToastMsg').innerHTML =
                '<strong>' + (appt.patient_name||'') + '</strong> (' + (appt.patient_code||'') + ')<br>' +
                'Appointment: <strong>' + (appt.appt_code||'') + '</strong> at <strong>' + timeLabel + '</strong>';
            var toast = document.getElementById('walkinToast');
            toast.style.display = 'block';
            setTimeout(() => { toast.style.display='none'; }, 5000);
            form.reset();
        } else {
            showDrawerAlert('danger', res.message || 'Something went wrong. Please try again.');
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-person-check-fill"></i> Register Walk-in';
        showDrawerAlert('danger', 'Network error. Please try again.');
    });
}

// Auto-open drawer if ?walkin=1 was in the URL (e.g. from dashboard button)
<?php if ($auto_open_walkin): ?>
document.addEventListener('DOMContentLoaded', function() { openWalkinDrawer(); });
<?php endif; ?>
</script>
</body>
</html>
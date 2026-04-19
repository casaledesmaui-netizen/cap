<?php
// Register a walk-in patient and auto-assign the next available time slot for today.

require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';

$page_title = 'Walk-in Registration';
$error      = '';
$success    = '';
$new_appt   = null;

$services = $conn->query(
    "SELECT id, service_name, price, duration_minutes FROM services WHERE is_active = 1 ORDER BY service_name"
)->fetch_all(MYSQLI_ASSOC);

$walkin_doctors = $conn->query(
    "SELECT id, full_name, specialization FROM doctors WHERE is_active = 1 ORDER BY full_name ASC"
)->fetch_all(MYSQLI_ASSOC);

// WALK-IN NEXT SLOT LOGIC
//
// Correct approach (as described in system documentation):
//
//   1. Get clinic schedule for today (open_time, close_time, duration)
//   2. Get ALL appointments already booked today
//   3. Generate every possible slot from open_time → close_time
//      using the configured duration interval (e.g. every 30 mins)
//   4. For each slot, check if it is already taken by an appointment
//   5. Return the FIRST slot that is free AND is not in the past
//
// Example:
//   Schedule: 8:00 AM – 5:00 PM, 30-min slots
//   Booked:   9:00, 9:30, 10:00
//   Current time: 9:50 AM
//   → Skips 8:00,8:30 (past), 9:00,9:30,10:00 (taken)
//   → Returns 10:30 AM as the next available slot
//
// If NO open schedule exists today → staff can enter manual time
// If schedule is full → show message but still allow manual time
function find_next_available_slot($conn) {
    $today = date('Y-m-d');
    $now   = time();
    $day   = strtolower(date('l'));

    // Check if today is blocked
    $blocked = $conn->query(
        "SELECT id FROM blocked_dates WHERE blocked_date = '$today'"
    )->num_rows;

    if ($blocked > 0) {
        return [
            'slot'      => null,
            'label'     => null,
            'is_closed' => true,
            'reason'    => 'Today is a blocked date (holiday or clinic closed).',
            'all_slots' => [],
        ];
    }

    // Get today's schedule
    $sched = $conn->query(
        "SELECT * FROM schedules WHERE day_of_week = '$day' AND is_open = 1 LIMIT 1"
    )->fetch_assoc();

    if (!$sched) {
        return [
            'slot'      => null,
            'label'     => null,
            'is_closed' => true,
            'reason'    => 'No schedule configured for ' . ucfirst($day) . '. Use the manual time field below.',
            'all_slots' => [],
        ];
    }

    $open_ts  = strtotime($today . ' ' . $sched['open_time']);
    $close_ts = strtotime($today . ' ' . $sched['close_time']);
    $step     = intval($sched['slot_duration_minutes'] ?? 30) * 60;

    // Get all booked appointments today WITH durations (not cancelled/no-show).
    // We need duration_minutes so we block slots that fall *inside* an existing
    // appointment's window, not just slots that share the exact same start time.
    // e.g. A 90-min root canal at 13:00 must also block the 13:30 slot.
    $booked_res = $conn->query("
        SELECT a.appointment_time,
               COALESCE(s.duration_minutes, " . intval($sched['slot_duration_minutes']) . ") AS duration_minutes
        FROM   appointments a
        LEFT JOIN services s ON s.id = a.service_id
        WHERE  a.appointment_date = '$today'
        AND    a.status NOT IN ('cancelled','no-show')
        ORDER BY a.appointment_time ASC
    ");
    $booked_windows = [];
    $booked_count   = 0;
    while ($row = $booked_res->fetch_assoc()) {
        $appt_start = strtotime($today . ' ' . $row['appointment_time']);
        $booked_windows[] = [
            'start' => $appt_start,
            'end'   => $appt_start + (intval($row['duration_minutes']) * 60),
        ];
        $booked_count++;
    }

    // Build list of all slots and find the first free one after current time.
    $all_slots  = [];
    $next_slot  = null;
    $next_label = null;

    for ($t = $open_ts; $t < $close_ts; $t += $step) {
        $slot_time  = date('H:i', $t);
        $slot_label = date('h:i A', $t);
        $is_past    = $t < $now;

        // A slot is taken if it falls inside any existing appointment's time window.
        // Condition: appt_start <= slot_time < appt_end
        $is_taken = false;
        foreach ($booked_windows as $win) {
            if ($t >= $win['start'] && $t < $win['end']) {
                $is_taken = true;
                break;
            }
        }

        $all_slots[] = [
            'time'  => $slot_time,
            'label' => $slot_label,
            'taken' => $is_taken,
            'past'  => $is_past,
        ];

        // First slot that is free AND not in the past
        if (!$next_slot && !$is_taken && !$is_past) {
            $next_slot  = $slot_time . ':00';
            $next_label = $slot_label;
        }
    }

    $is_full = ($next_slot === null);

    return [
        'slot'        => $next_slot,
        'label'       => $next_label,
        'is_closed'   => false,
        'is_full'     => $is_full,
        'reason'      => $is_full
            ? 'Schedule is full for today (no more slots before ' . date('h:i A', $close_ts) . ').'
            : null,
        'all_slots'   => $all_slots,
        'open_label'  => date('h:i A', $open_ts),
        'close_label' => date('h:i A', $close_ts),
        'booked_count'=> $booked_count,
        'total_slots' => count($all_slots),
    ];
}

$slot_data  = find_next_available_slot($conn);
$next_slot  = $slot_data['slot'];
$next_label = $slot_data['label'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $first_name  = ucwords(strtolower(trim($_POST['first_name']  ?? '')));
    $last_name   = ucwords(strtolower(trim($_POST['last_name']   ?? '')));
    $phone       = trim($_POST['phone']       ?? '');
    $service_id  = intval($_POST['service_id'] ?? 0);
    $doctor_id   = intval($_POST['doctor_id']  ?? 0) ?: null;
    $notes       = trim($_POST['notes']       ?? '');
    $manual_time = trim($_POST['manual_time'] ?? '');
    $today       = date('Y-m-d');

    if (empty($first_name) || empty($last_name)) {
        $error = 'First name and last name are required.';
    } elseif (strlen($first_name) < 2 || strlen($last_name) < 2) {
        $error = 'First and last name must each be at least 2 characters.';
    } elseif (!empty($phone) && !valid_phone($phone)) {
        $error = 'Please enter a valid phone number using the country code selector.';
    } elseif (strlen($notes) > 500) {
        $error = 'Notes must be 500 characters or fewer.';    } else {
        // Determine assigned time
        if (!empty($manual_time)) {
            // Staff entered a custom time — validate format AND check conflicts
            if (!preg_match('/^\d{2}:\d{2}$/', $manual_time)) {
                $error = 'Please enter time in HH:MM format (e.g. 14:30).';
            } else {
                $manual_ts = strtotime($today . ' ' . $manual_time);
                // Check if manually entered time conflicts with any existing appointment
                $conf_stmt = $conn->prepare("
                    SELECT a.appointment_time,
                           COALESCE(s.duration_minutes, 30) AS duration_minutes
                    FROM appointments a
                    LEFT JOIN services s ON s.id = a.service_id
                    WHERE a.appointment_date = ?
                    AND a.status NOT IN ('cancelled','no-show')
                ");
                $conf_stmt->bind_param('s', $today);
                $conf_stmt->execute();
                $conf_res = $conf_stmt->get_result();
                $conf_stmt->close();
                $has_conflict = false;
                $conflict_label = '';
                while ($crow = $conf_res->fetch_assoc()) {
                    $ex_start = strtotime($today . ' ' . $crow['appointment_time']);
                    $ex_end   = $ex_start + (intval($crow['duration_minutes']) * 60);
                    if ($manual_ts >= $ex_start && $manual_ts < $ex_end) {
                        $has_conflict  = true;
                        $conflict_label = date('h:i A', $ex_start) . ' – ' . date('h:i A', $ex_end);
                        break;
                    }
                }
                if ($has_conflict) {
                    $error = "That time ($manual_time) conflicts with an existing appointment ($conflict_label). Please choose a different time.";
                } else {
                    $assigned_time = $manual_time . ':00';
                }
            }
        } else {
            // Re-run slot scan fresh at submission time
            $fresh = find_next_available_slot($conn);
            if (!$fresh['slot']) {
                $error = $fresh['reason'] ?? 'No available slot found. Please enter a manual time.';
            } else {
                $assigned_time = $fresh['slot'];
            }
        }

        if (empty($error)) {
            // Create patient
            $patient_code = generate_code($conn, 'patients', 'PAT');
            $stmt = $conn->prepare(
                "INSERT INTO patients (patient_code, first_name, last_name, phone, registered_by)
                 VALUES (?,?,?,?,?)"
            );
            $stmt->bind_param('ssssi',
                $patient_code, $first_name, $last_name, $phone, $current_user_id
            );
            $stmt->execute();
            $patient_id = $conn->insert_id;
            $stmt->close();

            // Create appointment
            $appt_code = generate_code($conn, 'appointments', 'APT');
            $type      = 'walk-in';
            $status    = 'confirmed';

            $stmt2 = $conn->prepare("
                INSERT INTO appointments
                (appointment_code, patient_id, service_id, doctor_id, appointment_date,
                 appointment_time, type, status, notes, handled_by)
                VALUES (?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt2->bind_param('siiisssssi',
                $appt_code, $patient_id, $service_id, $doctor_id,
                $today, $assigned_time, $type, $status, $notes, $current_user_id
            );
            $stmt2->execute();
            $new_appt_id = $conn->insert_id; // capture immediately before other queries
            $stmt2->close();

            // Get service info for the slip
            $svc_name  = '—';
            $svc_price = 0;
            if ($service_id) {
                $svc_stmt = $conn->prepare("SELECT service_name, price FROM services WHERE id = ? LIMIT 1");
                $svc_stmt->bind_param('i', $service_id);
                $svc_stmt->execute();
                $svc_row   = $svc_stmt->get_result()->fetch_assoc();
                $svc_stmt->close();
                $svc_name  = $svc_row['service_name'] ?? '—';
                $svc_price = $svc_row['price'] ?? 0;
            }

            log_action($conn, $current_user_id, $current_user_name,
                'Walk-in Registration', 'walkin', $patient_id,
                "Patient: $first_name $last_name | Appt: $appt_code | Slot: $assigned_time"
            );

            $new_appt = [
                'appt_code'    => $appt_code,
                'appt_id'      => $new_appt_id,
                'patient_id'   => $patient_id,
                'patient_code' => $patient_code,
                'patient_name' => "$first_name $last_name",
                'phone'        => $phone,
                'service_name' => $svc_name,
                'service'      => $svc_name,
                'price'        => $svc_price,
                'date'         => $today,
                'time'         => $assigned_time,
                'notes'        => $notes,
                'staff'        => $current_user_name,
            ];

            $success = 'Walk-in registered! Assigned time slot: ' . date('h:i A', strtotime($assigned_time));

            // If called from drawer (AJAX), return JSON and exit
            if (!empty($_POST['_ajax'])) {
                header('Content-Type: application/json');
                echo json_encode([
                    'status'  => 'success',
                    'message' => $success,
                    'appt'    => $new_appt,
                ]);
                exit();
            }

            // Refresh slot data
            $slot_data  = find_next_available_slot($conn);
            $next_slot  = $slot_data['slot'];
            $next_label = $slot_data['label'];
        }
    }
}

// If AJAX request with an error, return JSON and stop
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['_ajax']) && $error) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => $error]);
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include '../../includes/head.php'; ?>
</head>
<body>
<?php include '../../includes/sidebar.php'; ?>
<div class="main-content">
    <?php include '../../includes/header.php'; ?>
    <div class="page-content">

        <div class="page-header">
            <div>
                <h5>Walk-in Registration</h5>
                <p>
                    The system scans today's schedule and assigns the first available slot.
                    <?php if (!empty($slot_data['open_label'])): ?>
                    <span style="color:var(--gray-500);">
                        Clinic hours today: <strong><?php echo $slot_data['open_label']; ?></strong>
                        to <strong><?php echo $slot_data['close_label']; ?></strong>
                        (<?php echo $slot_data['total_slots'] ?? 0; ?> slots,
                        <?php echo $slot_data['booked_count'] ?? 0; ?> booked)
                    </span>
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <!-- Status Banner -->
        <?php if ($slot_data['is_closed']): ?>
        <div class="alert alert-warning" style="display:flex;align-items:flex-start;gap:12px;margin-bottom:20px;">
            <i class="bi bi-calendar-x" style="font-size:1.2rem;flex-shrink:0;margin-top:2px;"></i>
            <div>
                <strong>Clinic closed today</strong> — <?php echo e($slot_data['reason']); ?><br>
                <span style="font-size:0.82rem;">You can still register using the manual time field below.</span>
            </div>
        </div>
        <?php elseif (!empty($slot_data['is_full'])): ?>
        <div class="alert alert-warning" style="display:flex;align-items:flex-start;gap:12px;margin-bottom:20px;">
            <i class="bi bi-calendar-check" style="font-size:1.2rem;flex-shrink:0;margin-top:2px;"></i>
            <div>
                <strong>Schedule is full</strong> — <?php echo e($slot_data['reason']); ?><br>
                <span style="font-size:0.82rem;">You can still register using the manual time field below.</span>
            </div>
        </div>
        <?php else: ?>
        <div class="alert alert-info" style="display:flex;align-items:center;gap:12px;margin-bottom:20px;">
            <i class="bi bi-clock-fill" style="font-size:1.2rem;flex-shrink:0;"></i>
            <div>
                <strong>Next available slot:</strong>
                <span style="color:var(--blue-600);font-weight:700;font-size:1.05rem;margin:0 8px;">
                    <?php echo $next_label; ?>
                </span>
                <span style="font-size:0.82rem;color:var(--gray-500);">
                    — This will be assigned to the walk-in patient automatically.
                </span>
            </div>
        </div>
        <?php endif; ?>

        <!-- Error -->
        <?php if ($error): ?>
        <div class="alert alert-danger"><i class="bi bi-x-circle-fill"></i> <?php echo e($error); ?></div>
        <?php endif; ?>

        <!-- TWO-COLUMN LAYOUT: Form left, Schedule right -->
        <div style="display:grid;grid-template-columns:1fr 380px;gap:22px;align-items:start;">

            <!-- LEFT: Form or Slip -->
            <div>
            <?php if ($success && $new_appt): ?>
            <div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> <?php echo e($success); ?></div>
            <div id="printSlip" style="margin-bottom:20px;">
                <div class="slip-box">
                    <div class="slip-header">
                        <div style="font-size:2rem;">🦷</div>
                        <div style="font-family:'Outfit',sans-serif;font-weight:700;font-size:1rem;margin:4px 0 2px;">DentalCare Clinic</div>
                        <div style="font-size:0.75rem;color:var(--gray-500);">Walk-in Appointment Slip</div>
                    </div>
                    <div class="slip-time">
                        <div class="tl">Assigned Time Slot</div>
                        <div class="tv"><?php echo date('h:i A', strtotime($new_appt['time'])); ?></div>
                        <div style="font-size:0.82rem;color:var(--gray-500);margin-top:4px;"><?php echo date('F d, Y', strtotime($new_appt['date'])); ?></div>
                    </div>
                    <div class="slip-row"><span class="sl">Appointment Code</span><span class="sv"><?php echo e($new_appt['appt_code']); ?></span></div>
                    <div class="slip-row"><span class="sl">Patient Code</span><span class="sv"><?php echo e($new_appt['patient_code']); ?></span></div>
                    <div class="slip-row"><span class="sl">Patient Name</span><span class="sv"><?php echo e($new_appt['patient_name']); ?></span></div>
                    <div class="slip-row"><span class="sl">Phone</span><span class="sv"><?php echo e($new_appt['phone'] ?: '—'); ?></span></div>
                    <div class="slip-row"><span class="sl">Service</span><span class="sv"><?php echo e($new_appt['service']); ?></span></div>
                    <?php if ($new_appt['price'] > 0): ?>
                    <div class="slip-row"><span class="sl">Estimated Fee</span><span class="sv">₱<?php echo number_format($new_appt['price'], 2); ?></span></div>
                    <?php endif; ?>
                    <div class="slip-row"><span class="sl">Served by</span><span class="sv"><?php echo e($new_appt['staff']); ?></span></div>
                    <div style="text-align:center;margin-top:14px;font-size:0.72rem;color:var(--gray-400);">
                        Please present this slip at the reception.<br>Generated: <?php echo date('M d, Y h:i A'); ?>
                    </div>
                </div>
            </div>
            <div style="display:flex;gap:10px;">
                <button onclick="window.print()" class="btn btn-primary"><i class="bi bi-printer"></i> Print Slip</button>
                <a href="add.php" class="btn btn-outline-secondary"><i class="bi bi-plus"></i> Register Another</a>
            </div>

            <?php else: ?>
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-person-walking" style="color:var(--blue-500)"></i> Patient Details
                </div>
                <div class="card-body">
                    <form method="POST">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">First Name <span style="color:var(--danger)">*</span></label>
                                <input type="text" name="first_name" class="form-control" required autofocus>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Last Name <span style="color:var(--danger)">*</span></label>
                                <input type="text" name="last_name" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <?php
                                    $phone_field_name     = 'phone';
                                    $phone_field_value    = '';
                                    $phone_field_label    = 'Phone (optional)';
                                    $phone_field_required = false;
                                    include '../../includes/phone_input.php';
                                ?>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Service <span style="font-size:0.72rem;color:var(--gray-400)">(optional)</span></label>
                                <select name="service_id" class="form-select">
                                    <option value="">Select Service</option>
                                    <?php foreach ($services as $s): ?>
                                    <option value="<?php echo $s['id']; ?>"><?php echo e($s['service_name']); ?> — ₱<?php echo number_format($s['price'], 2); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php if (!empty($walkin_doctors)): ?>
                            <div class="col-md-6">
                                <label class="form-label">Doctor <span style="font-size:0.72rem;color:var(--gray-400)">(optional)</span></label>
                                <select name="doctor_id" class="form-select">
                                    <option value="">Any Available Doctor</option>
                                    <?php foreach ($walkin_doctors as $d): ?>
                                    <option value="<?php echo $d['id']; ?>">
                                        <?php echo e($d['full_name']); ?>
                                        <?php if ($d['specialization']): ?> — <?php echo e($d['specialization']); ?><?php endif; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            <div class="col-12">
                                <label class="form-label">Notes / Chief Complaint <span style="font-size:0.72rem;color:var(--gray-400)">(optional)</span></label>
                                <textarea name="notes" class="form-control" rows="2" placeholder="Describe the patient's concern..."></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label">
                                    Override Time
                                    <span style="font-size:0.72rem;color:var(--gray-400);">
                                        — Leave blank to auto-assign<?php if ($next_label): ?> <strong style="color:var(--blue-500);">(<?php echo $next_label; ?>)</strong><?php endif; ?>
                                    </span>
                                </label>
                                <input type="text" name="manual_time" class="form-control" style="max-width:200px;"
                                    placeholder="HH:MM e.g. 14:30 (24-hr)" pattern="\d{2}:\d{2}">
                                <div style="font-size:0.72rem;color:var(--gray-400);margin-top:4px;">24-hour format only. Leave blank to use the next available slot.</div>
                            </div>
                        </div>
                        <div class="mt-4" style="display:flex;gap:10px;align-items:center;">
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-person-check-fill"></i> Register Walk-in
                            </button>
                            <?php if ($next_label): ?>
                            <span style="font-size:0.82rem;color:var(--gray-500);">
                                <i class="bi bi-clock"></i> Auto-slot: <strong style="color:var(--blue-600);"><?php echo $next_label; ?></strong>
                            </span>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>
            </div><!-- /left col -->

            <!-- RIGHT: Today's slot timeline — always visible -->
            <div>
                <div class="card" style="position:sticky;top:82px;">
                    <div class="card-header" style="font-size:0.82rem;">
                        <i class="bi bi-calendar-day" style="color:var(--blue-500)"></i>
                        Today — <?php echo date('D, M d'); ?>
                    </div>
                    <div class="card-body" style="padding:14px 16px;">
                        <?php if (empty($slot_data['all_slots'])): ?>
                        <div style="text-align:center;padding:24px;color:var(--gray-400);font-size:0.82rem;">
                            <i class="bi bi-calendar-x" style="font-size:2rem;display:block;margin-bottom:8px;"></i>
                            <?php echo e($slot_data['reason'] ?? 'No schedule today.'); ?>
                        </div>
                        <?php else: ?>

                        <!-- Stats row -->
                        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:14px;text-align:center;">
                            <div style="background:var(--gray-50);border-radius:8px;padding:8px 4px;">
                                <div style="font-family:'Outfit',sans-serif;font-weight:700;font-size:1.3rem;color:var(--blue-500);"><?php echo $slot_data['total_slots']; ?></div>
                                <div style="font-size:0.62rem;color:var(--gray-400);text-transform:uppercase;letter-spacing:0.05em;">Total</div>
                            </div>
                            <div style="background:var(--danger-bg);border-radius:8px;padding:8px 4px;">
                                <div style="font-family:'Outfit',sans-serif;font-weight:700;font-size:1.3rem;color:var(--danger);"><?php echo $slot_data['booked_count']; ?></div>
                                <div style="font-size:0.62rem;color:var(--danger);text-transform:uppercase;letter-spacing:0.05em;">Booked</div>
                            </div>
                            <div style="background:var(--success-bg);border-radius:8px;padding:8px 4px;">
                                <div style="font-family:'Outfit',sans-serif;font-weight:700;font-size:1.3rem;color:var(--success);"><?php echo max(0, $slot_data['total_slots'] - $slot_data['booked_count']); ?></div>
                                <div style="font-size:0.62rem;color:var(--success);text-transform:uppercase;letter-spacing:0.05em;">Free</div>
                            </div>
                        </div>

                        <!-- Slot pills -->
                        <div class="slot-timeline">
                        <?php foreach ($slot_data['all_slots'] as $s):
                            if ($s['past'] && $s['taken']) $cls = 'taken past';
                            elseif ($s['past'])              $cls = 'past';
                            elseif ($s['taken'])             $cls = 'taken';
                            elseif ($s['time'].':00' === $next_slot) $cls = 'next';
                            else                             $cls = 'free';
                        ?>
                        <span class="slot-pill <?php echo $cls; ?>" title="<?php
                            if ($s['taken']) echo 'Booked';
                            elseif ($s['past']) echo 'Past';
                            elseif ($cls === 'next') echo 'Next → will be auto-assigned';
                            else echo 'Available';
                        ?>">
                            <?php echo $s['label']; ?>
                            <?php if ($s['taken']): ?> ✗<?php elseif ($cls === 'next'): ?> ←<?php endif; ?>
                        </span>
                        <?php endforeach; ?>
                        </div>

                        <!-- Legend -->
                        <div style="margin-top:12px;font-size:0.68rem;color:var(--gray-400);display:flex;gap:10px;flex-wrap:wrap;">
                            <span><span style="display:inline-block;width:8px;height:8px;background:var(--blue-500);border-radius:2px;margin-right:3px;"></span>Next</span>
                            <span><span style="display:inline-block;width:8px;height:8px;background:var(--blue-100);border:1px solid var(--blue-300);border-radius:2px;margin-right:3px;"></span>Free</span>
                            <span><span style="display:inline-block;width:8px;height:8px;background:var(--gray-200);border-radius:2px;margin-right:3px;"></span>Booked</span>
                            <span><span style="display:inline-block;width:8px;height:8px;background:var(--gray-100);border-radius:2px;margin-right:3px;"></span>Past</span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div><!-- /right col -->

        </div><!-- /grid -->

    </div>
</div>
<?php include '../../includes/footer.php'; ?>
</body>
</html>

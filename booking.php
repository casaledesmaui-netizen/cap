<?php
require_once 'includes/config.php';
require_once 'includes/db.php';

$success = false;
$error   = '';

// =============================================
// CLINIC INFO — edit everything here
// =============================================
$clinic_name      = 'DentalCare';
$clinic_tagline   = 'Trusted Dental Care in Cebu';
$clinic_hero_h1   = 'Your Healthy Smile Starts Here';
$clinic_hero_sub  = 'We provide compassionate, modern dental care for the whole family. From routine checkups to advanced treatments — all in a comfortable, welcoming clinic.';
$clinic_about_h2  = 'A Clinic That Truly Cares';
$clinic_about_sub = 'DentalCare has been serving Cebu families for over a decade with professional, gentle, and affordable dental services. We combine modern technology with a warm personal touch.';
$clinic_phone     = '(032) 123-4567';
$clinic_mobile    = '09XX-XXX-XXXX';
$clinic_email     = 'hello@dentalcare.ph';
$clinic_address   = '123 Dental Street, Cebu City, Central Visayas 6000';
$clinic_location  = 'Cebu City, Central Visayas';
$clinic_hours     = 'Mon – Sat · 8:00 AM – 5:00 PM';
$clinic_hours_sat = '8:00 AM – 12:00 PM';
$clinic_parking   = 'Free parking on-site. Accessible via jeepney routes passing Fuente Osmeña or SM Cebu.';
$stat_patients    = '5,000+';
$stat_years       = '10+';
$stat_rating      = '4.9 ★';
// =============================================

$services = $conn->query("SELECT id, service_name, description, price FROM services WHERE is_active = 1 ORDER BY service_name")->fetch_all(MYSQLI_ASSOC);
$doctors  = $conn->query("SELECT id, full_name FROM doctors WHERE is_active = 1 ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);

// Today's real schedule from database
$today = date('Y-m-d');
$today_schedule = $conn->query("
    SELECT a.appointment_time, s.service_name, a.status
    FROM appointments a
    LEFT JOIN services s ON a.service_id = s.id
    WHERE a.appointment_date = '$today'
    AND a.status IN ('confirmed', 'pending', 'completed')
    ORDER BY a.appointment_time ASC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first  = trim($_POST['first_name'] ?? '');
    $last   = trim($_POST['last_name']  ?? '');
    $phone  = trim($_POST['phone']      ?? '');
    $svc_id = intval($_POST['service_id']  ?? 0);
    $doc_id = intval($_POST['doctor_id']   ?? 0);
    $date   = $_POST['appt_date'] ?? '';
    $time   = $_POST['appt_time'] ?? '';

    if (!$first || !$last || !$phone || !$svc_id || !$doc_id || !$date || !$time) {
        $error = 'Please fill in all fields.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $error = 'Invalid date format.';
    } else {
        $slot_check = $conn->prepare("
            SELECT id FROM appointments
            WHERE doctor_id = ?
            AND appointment_date = ?
            AND appointment_time = ?
            AND status NOT IN ('cancelled', 'no-show')
            LIMIT 1
        ");
        $slot_check->bind_param("iss", $doc_id, $date, $time);
        $slot_check->execute();
        $slot_taken = $slot_check->get_result()->fetch_assoc();
        $slot_check->close();

        if ($slot_taken) {
            $error = 'Sorry, that doctor is not available at that date and time. Please choose a different slot.';
        } else {
            $stmt = $conn->prepare("SELECT id FROM patients WHERE phone = ? LIMIT 1");
            $stmt->bind_param("s", $phone);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($existing) {
                $patient_id = $existing['id'];
            } else {
                $count = (int)$conn->query("SELECT COUNT(*) as c FROM patients")->fetch_assoc()['c'];
                $patient_code = 'PAT-' . str_pad($count + 1, 4, '0', STR_PAD_LEFT);
                $ins = $conn->prepare("INSERT INTO patients (patient_code, first_name, last_name, phone, created_at) VALUES (?, ?, ?, ?, NOW())");
                $ins->bind_param("ssss", $patient_code, $first, $last, $phone);
                $ins->execute();
                $patient_id = $conn->insert_id;
                $ins->close();
            }

            $count2 = (int)$conn->query("SELECT COUNT(*) as c FROM appointments")->fetch_assoc()['c'];
            $appt_code = 'APT-' . str_pad($count2 + 1, 4, '0', STR_PAD_LEFT);

            $a = $conn->prepare("INSERT INTO appointments (appointment_code, patient_id, service_id, doctor_id, appointment_date, appointment_time, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())");
            $a->bind_param("siiiss", $appt_code, $patient_id, $svc_id, $doc_id, $date, $time);

            if ($a->execute()) {
                $success = true;
            } else {
                $error = 'Something went wrong. Please try again.';
            }
            $a->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo $clinic_name; ?> Clinic — Book an Appointment</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
  <style>
    :root {
      --blue: #2563eb;
      --blue-dark: #1d4ed8;
      --blue-light: #eff6ff;
      --text: #0f172a;
      --muted: #64748b;
      --border: #e2e8f0;
      --surface: #f8fafc;
    }
    * { box-sizing: border-box; }
    html { scroll-behavior: smooth; }
    body { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; color: var(--text); margin: 0; padding: 0; background: #fff; }

    .nav-bar { position: sticky; top: 0; z-index: 100; background: rgba(255,255,255,0.95); backdrop-filter: blur(8px); border-bottom: 1px solid var(--border); padding: 0 5%; display: flex; align-items: center; justify-content: space-between; height: 64px; }
    .nav-logo { display: flex; align-items: center; gap: 10px; text-decoration: none; }
    .nav-logo-icon { width: 36px; height: 36px; background: var(--blue); border-radius: 9px; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 1rem; }
    .nav-logo-text { font-size: 1rem; font-weight: 700; color: var(--text); }
    .nav-links { display: flex; gap: 28px; align-items: center; }
    .nav-links a { font-size: 0.85rem; font-weight: 500; color: var(--muted); text-decoration: none; transition: color .15s; }
    .nav-links a:hover { color: var(--blue); }
    .nav-cta { background: var(--blue); color: #fff !important; padding: 8px 18px; border-radius: 8px; font-size: 0.85rem !important; font-weight: 600 !important; }
    .nav-cta:hover { background: var(--blue-dark) !important; color: #fff !important; }

    .hero { background: linear-gradient(135deg, #f0f7ff 0%, #e8f4fd 60%, #fff 100%); padding: 90px 5% 80px; display: flex; align-items: center; gap: 60px; min-height: 88vh; }
    .hero-content { flex: 1; max-width: 560px; }
    .hero-badge { display: inline-flex; align-items: center; gap: 6px; background: var(--blue-light); color: var(--blue); font-size: 0.78rem; font-weight: 600; padding: 6px 14px; border-radius: 20px; margin-bottom: 20px; letter-spacing: .3px; }
    .hero h1 { font-size: clamp(2rem, 4vw, 3.2rem); font-weight: 800; line-height: 1.15; color: var(--text); margin-bottom: 18px; }
    .hero h1 span { color: var(--blue); }
    .hero p { font-size: 1.05rem; color: var(--muted); line-height: 1.7; margin-bottom: 32px; max-width: 440px; }
    .hero-btns { display: flex; gap: 14px; flex-wrap: wrap; }
    .btn-primary-custom { background: var(--blue); color: #fff; border: none; border-radius: 10px; padding: 14px 28px; font-size: 0.95rem; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: background .15s, transform .1s; }
    .btn-primary-custom:hover { background: var(--blue-dark); color: #fff; transform: translateY(-1px); }
    .btn-ghost { background: transparent; color: var(--text); border: 1.5px solid var(--border); border-radius: 10px; padding: 14px 28px; font-size: 0.95rem; font-weight: 500; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: border-color .15s, color .15s; }
    .btn-ghost:hover { border-color: var(--blue); color: var(--blue); }
    .hero-stats { display: flex; gap: 32px; margin-top: 40px; padding-top: 32px; border-top: 1px solid var(--border); }
    .hero-stat-num { font-size: 1.6rem; font-weight: 800; color: var(--text); }
    .hero-stat-label { font-size: 0.75rem; color: var(--muted); margin-top: 2px; }
    .hero-visual { flex: 1; max-width: 480px; display: flex; flex-direction: column; gap: 16px; }
    .hero-card { background: #fff; border-radius: 16px; border: 1px solid var(--border); padding: 20px 24px; box-shadow: 0 2px 12px rgba(37,99,235,0.06); }
    .hero-card-header { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; }
    .hc-icon { width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 0.9rem; }
    .hc-icon.blue { background: var(--blue-light); color: var(--blue); }
    .hc-title { font-size: 0.85rem; font-weight: 700; color: var(--text); }
    .hc-sub { font-size: 0.75rem; color: var(--muted); }
    .schedule-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid #f1f5f9; font-size: 0.8rem; }
    .schedule-row:last-child { border-bottom: none; }
    .schedule-time { color: var(--muted); }
    .schedule-name { font-weight: 500; color: var(--text); }
    .status-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
    .dot-green { background: #22c55e; }
    .dot-blue { background: #3b82f6; }
    .dot-orange { background: #f97316; }
    .hero-cards-row { display: flex; gap: 16px; }
    .mini-card { flex: 1; background: #fff; border: 1px solid var(--border); border-radius: 14px; padding: 16px; box-shadow: 0 2px 12px rgba(37,99,235,0.06); text-align: center; }
    .mini-card-num { font-size: 1.4rem; font-weight: 800; color: var(--text); }
    .mini-card-label { font-size: 0.72rem; color: var(--muted); margin-top: 2px; }

    .section { padding: 80px 5%; }
    .section-alt { background: var(--surface); }
    .section-label { font-size: 0.75rem; font-weight: 700; color: var(--blue); letter-spacing: 1px; text-transform: uppercase; margin-bottom: 10px; }
    .section-title { font-size: clamp(1.5rem, 3vw, 2.2rem); font-weight: 800; color: var(--text); margin-bottom: 14px; }
    .section-sub { font-size: 1rem; color: var(--muted); max-width: 520px; line-height: 1.7; margin-bottom: 48px; }

    .about-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 48px; align-items: center; }
    .about-features { display: flex; flex-direction: column; gap: 20px; }
    .about-feature { display: flex; gap: 16px; align-items: flex-start; }
    .about-feature-icon { width: 44px; height: 44px; background: var(--blue-light); border-radius: 10px; display: flex; align-items: center; justify-content: center; color: var(--blue); font-size: 1rem; flex-shrink: 0; }
    .about-feature h4 { font-size: 0.95rem; font-weight: 700; margin-bottom: 4px; }
    .about-feature p { font-size: 0.85rem; color: var(--muted); margin: 0; line-height: 1.6; }
    .about-visual { background: var(--blue-light); border-radius: 20px; padding: 36px; display: flex; flex-direction: column; gap: 16px; }
    .about-info-card { background: #fff; border-radius: 12px; padding: 18px 20px; display: flex; align-items: center; gap: 14px; }
    .aic-icon { width: 40px; height: 40px; border-radius: 10px; background: var(--blue-light); color: var(--blue); display: flex; align-items: center; justify-content: center; font-size: 1rem; flex-shrink: 0; }
    .aic-label { font-size: 0.72rem; color: var(--muted); margin-bottom: 2px; }
    .aic-value { font-size: 0.9rem; font-weight: 700; color: var(--text); }

    .services-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 20px; }
    .service-card { background: #fff; border: 1px solid var(--border); border-radius: 16px; padding: 24px 20px; transition: border-color .2s, box-shadow .2s, transform .15s; }
    .service-card:hover { border-color: var(--blue); box-shadow: 0 4px 20px rgba(37,99,235,0.1); transform: translateY(-2px); }
    .sc-icon { width: 44px; height: 44px; background: var(--blue-light); border-radius: 10px; display: flex; align-items: center; justify-content: center; color: var(--blue); font-size: 1.1rem; margin-bottom: 14px; }
    .sc-name { font-size: 0.95rem; font-weight: 700; color: var(--text); margin-bottom: 6px; }
    .sc-desc { font-size: 0.8rem; color: var(--muted); margin-bottom: 14px; line-height: 1.5; }
    .sc-price { font-size: 1rem; font-weight: 800; color: var(--blue); }

    .location-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 48px; align-items: start; }
    .location-map { background: var(--surface); border: 1px solid var(--border); border-radius: 20px; overflow: hidden; height: 360px; }
    .location-map iframe { width: 100%; height: 100%; border: none; }
    .location-details { display: flex; flex-direction: column; gap: 20px; }
    .loc-item { display: flex; gap: 16px; align-items: flex-start; padding: 20px; background: #fff; border-radius: 14px; border: 1px solid var(--border); }
    .loc-item-icon { width: 40px; height: 40px; background: var(--blue-light); border-radius: 10px; color: var(--blue); display: flex; align-items: center; justify-content: center; font-size: 1rem; flex-shrink: 0; }
    .loc-item h4 { font-size: 0.85rem; font-weight: 700; margin-bottom: 4px; color: var(--text); }
    .loc-item p { font-size: 0.82rem; color: var(--muted); margin: 0; line-height: 1.6; }

    #booking { background: linear-gradient(135deg, #2563eb 0%, #1e40af 100%); padding: 80px 5%; }
    .booking-wrapper { max-width: 660px; margin: 0 auto; }
    .booking-header { text-align: center; margin-bottom: 40px; }
    .booking-header .section-label { color: rgba(255,255,255,0.7); }
    .booking-header .section-title { color: #fff; }
    .booking-header .section-sub { color: rgba(255,255,255,0.75); margin: 0 auto; }
    .booking-card { background: #fff; border-radius: 20px; padding: 40px 36px; box-shadow: 0 20px 60px rgba(0,0,0,0.2); }
    .form-label-custom { font-size: 0.78rem; font-weight: 700; color: #374151; margin-bottom: 5px; display: block; }
    .form-control-custom, .form-select-custom { border-radius: 10px; border: 1.5px solid var(--border); font-size: 0.9rem; padding: 11px 14px; width: 100%; background: #fff; color: var(--text); transition: border-color .15s, box-shadow .15s; appearance: none; -webkit-appearance: none; }
    .form-control-custom:focus, .form-select-custom:focus { outline: none; border-color: var(--blue); box-shadow: 0 0 0 3px rgba(37,99,235,0.1); }
    .form-select-custom { background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%2364748b' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14L2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 14px center; padding-right: 40px; }
    .form-group { margin-bottom: 18px; }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    .section-divider { display: flex; align-items: center; gap: 12px; margin: 24px 0 20px; }
    .section-divider hr { flex: 1; margin: 0; border-color: var(--border); }
    .section-divider span { font-size: 0.72rem; font-weight: 700; color: var(--muted); white-space: nowrap; letter-spacing: .5px; text-transform: uppercase; }
    .btn-submit { background: var(--blue); color: #fff; border: none; border-radius: 12px; padding: 14px; font-size: 0.95rem; font-weight: 700; width: 100%; margin-top: 8px; cursor: pointer; transition: background .15s, transform .1s; display: flex; align-items: center; justify-content: center; gap: 8px; }
    .btn-submit:hover { background: var(--blue-dark); transform: translateY(-1px); }
    .success-box { text-align: center; padding: 20px 0; }
    .success-icon { width: 70px; height: 70px; background: #dcfce7; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 2rem; color: #16a34a; }
    .alert-custom { background: #fef2f2; border: 1px solid #fecaca; border-radius: 10px; padding: 12px 16px; font-size: 0.85rem; color: #991b1b; margin-bottom: 20px; display: flex; align-items: center; gap: 8px; }

    .footer { background: #0f172a; color: rgba(255,255,255,0.6); padding: 40px 5%; text-align: center; font-size: 0.82rem; }
    .footer a { color: rgba(255,255,255,0.6); text-decoration: none; }
    .footer a:hover { color: #fff; }

    @media (max-width: 768px) {
      .hero { flex-direction: column; padding: 50px 5%; min-height: auto; gap: 40px; }
      .hero-visual { max-width: 100%; }
      .about-grid, .location-grid { grid-template-columns: 1fr; }
      .location-map { height: 260px; }
      .hero-cards-row { flex-direction: column; }
      .booking-card { padding: 28px 20px; }
      .form-row { grid-template-columns: 1fr; }
      .nav-links { display: none; }
      .hero-stats { gap: 20px; }
    }
  </style>
</head>
<body>

<nav class="nav-bar">
  <a href="#" class="nav-logo">
    <div class="nav-logo-icon"><i class="bi bi-heart-pulse-fill"></i></div>
    <div class="nav-logo-text"><?php echo $clinic_name; ?></div>
  </a>
  <div class="nav-links">
    <a href="#about">About</a>
    <a href="#services">Services</a>
    <a href="#location">Location</a>
    <a href="#booking" class="nav-cta"><i class="bi bi-calendar-check-fill"></i> Book Now</a>
  </div>
</nav>

<section class="hero">
  <div class="hero-content">
    <div class="hero-badge">
      <i class="bi bi-stars" style="font-size:14px;"></i>
      <?php echo $clinic_tagline; ?>
    </div>
    <h1><?php
      // Split on first space to wrap second word in <span>
      // For "Your Healthy Smile Starts Here" → "Your <span>Healthy Smile</span> Starts Here"
      // We hardcode the span logic based on the variable for flexibility
      $parts = explode(' ', $clinic_hero_h1, 2);
      echo $parts[0] . ' <span>' . ($parts[1] ?? '') . '</span>';
    ?></h1>
    <p><?php echo $clinic_hero_sub; ?></p>
    <div class="hero-btns">
      <a href="#booking" class="btn-primary-custom">
        <i class="bi bi-calendar-check-fill"></i> Book Appointment
      </a>
      <a href="#services" class="btn-ghost">
        <i class="bi bi-grid-3x3-gap"></i> Our Services
      </a>
    </div>
    <div class="hero-stats">
      <div>
        <div class="hero-stat-num"><?php echo $stat_patients; ?></div>
        <div class="hero-stat-label">Happy Patients</div>
      </div>
      <div>
        <div class="hero-stat-num"><?php echo $stat_years; ?></div>
        <div class="hero-stat-label">Years of Service</div>
      </div>
      <div>
        <div class="hero-stat-num"><?php echo $stat_rating; ?></div>
        <div class="hero-stat-label">Patient Rating</div>
      </div>
    </div>
  </div>

  <div class="hero-visual">
    <div class="hero-card">
      <div class="hero-card-header">
        <div class="hc-icon blue"><i class="bi bi-calendar2-week-fill"></i></div>
        <div>
          <div class="hc-title">Today's Schedule</div>
          <div class="hc-sub"><?php echo date('l — M d, Y'); ?></div>
        </div>
      </div>
      <?php if (empty($today_schedule)): ?>
        <div style="padding:16px 0;text-align:center;color:#94a3b8;font-size:0.82rem;">
          No appointments scheduled today
        </div>
      <?php else: ?>
        <?php foreach ($today_schedule as $appt):
          $dot = match($appt['status']) {
            'completed' => '#16a34a',
            'confirmed' => '#2563eb',
            default     => '#f59e0b'
          };
        ?>
        <div class="schedule-row">
          <span class="schedule-time">
            <?php echo date('g:i A', strtotime($appt['appointment_time'])); ?>
          </span>
          <span class="schedule-name">
            <?php echo htmlspecialchars($appt['service_name'] ?? 'Appointment'); ?>
          </span>
          <span style="width:8px;height:8px;border-radius:50%;background:<?php echo $dot; ?>;display:inline-block;flex-shrink:0;"></span>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <div class="hero-cards-row">
      <div class="mini-card">
        <div class="mini-card-num" style="color:#16a34a;">Open</div>
        <div class="mini-card-label"><?php echo $clinic_hours; ?></div>
      </div>
      <div class="mini-card">
        <div class="mini-card-num">15 min</div>
        <div class="mini-card-label">Avg. booking time</div>
      </div>
    </div>
  </div>
</section>

<section class="section section-alt" id="about">
  <div style="max-width:1100px; margin: 0 auto;">
    <div class="about-grid">
      <div>
        <div class="section-label">About Us</div>
        <h2 class="section-title"><?php echo $clinic_about_h2; ?></h2>
        <p class="section-sub"><?php echo $clinic_about_sub; ?></p>
        <div class="about-features">
          <div class="about-feature">
            <div class="about-feature-icon"><i class="bi bi-shield-check-fill"></i></div>
            <div>
              <h4>Fully Licensed & Certified</h4>
              <p>All our dentists are PRC-licensed professionals with years of clinical experience.</p>
            </div>
          </div>
          <div class="about-feature">
            <div class="about-feature-icon"><i class="bi bi-hospital-fill"></i></div>
            <div>
              <h4>Modern Equipment</h4>
              <p>We use up-to-date dental technology for accurate diagnosis and comfortable treatment.</p>
            </div>
          </div>
          <div class="about-feature">
            <div class="about-feature-icon"><i class="bi bi-people-fill"></i></div>
            <div>
              <h4>Family-Friendly Environment</h4>
              <p>From kids to seniors, our clinic is designed to be welcoming and stress-free for all ages.</p>
            </div>
          </div>
          <div class="about-feature">
            <div class="about-feature-icon"><i class="bi bi-cash-coin"></i></div>
            <div>
              <h4>Transparent, Affordable Pricing</h4>
              <p>No hidden fees. We show you clear pricing upfront so you always know what to expect.</p>
            </div>
          </div>
        </div>
      </div>
      <div class="about-visual">
        <div class="about-info-card">
          <div class="aic-icon"><i class="bi bi-clock-fill"></i></div>
          <div>
            <div class="aic-label">Operating Hours</div>
            <div class="aic-value"><?php echo $clinic_hours; ?></div>
          </div>
        </div>
        <div class="about-info-card">
          <div class="aic-icon"><i class="bi bi-telephone-fill"></i></div>
          <div>
            <div class="aic-label">Call Us</div>
            <div class="aic-value"><?php echo $clinic_phone; ?> · <?php echo $clinic_mobile; ?></div>
          </div>
        </div>
        <div class="about-info-card">
          <div class="aic-icon"><i class="bi bi-geo-alt-fill"></i></div>
          <div>
            <div class="aic-label">Branch Location</div>
            <div class="aic-value"><?php echo $clinic_location; ?></div>
          </div>
        </div>
        <div class="about-info-card">
          <div class="aic-icon" style="background:#f0fdf4; color:#16a34a;"><i class="bi bi-emoji-smile-fill"></i></div>
          <div>
            <div class="aic-label">Patients Served</div>
            <div class="aic-value" style="color:#16a34a;"><?php echo $stat_patients; ?> Happy Smiles</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="section" id="services">
  <div style="max-width:1100px; margin: 0 auto;">
    <div class="section-label">What We Offer</div>
    <h2 class="section-title">Our Dental Services</h2>
    <p class="section-sub">From preventive care to cosmetic treatments, we offer a full range of dental services to keep your smile healthy and beautiful.</p>
    <?php
    $icons = [
      'checkup'        => 'bi-search-heart',
      'extraction'     => 'bi-bandaid',
      'cleaning'       => 'bi-droplet-fill',
      'filling'        => 'bi-patch-plus-fill',
      'root canal'     => 'bi-activity',
      'orthodontic'    => 'bi-chat-square-heart',
      'whitening'      => 'bi-stars',
      'denture'        => 'bi-person-hearts',
      'x-ray'          => 'bi-camera-fill',
      'fluoride'       => 'bi-shield-plus',
    ];
    ?>
    <div class="services-grid">
      <?php foreach ($services as $s):
        $key  = strtolower($s['service_name']);
        $icon = 'bi-heart-pulse';
        foreach ($icons as $k => $v) {
          if (str_contains($key, $k)) { $icon = $v; break; }
        }
      ?>
      <div class="service-card">
        <div class="sc-icon"><i class="bi <?php echo $icon; ?>"></i></div>
        <div class="sc-name"><?php echo htmlspecialchars($s['service_name']); ?></div>
        <div class="sc-desc"><?php echo htmlspecialchars($s['description'] ?? ''); ?></div>
        <div class="sc-price">₱<?php echo number_format($s['price'], 0); ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section section-alt" id="location">
  <div style="max-width:1100px; margin: 0 auto;">
    <div class="section-label">Find Us</div>
    <h2 class="section-title">Visit Our Clinic</h2>
    <p class="section-sub">Conveniently located in <?php echo $clinic_location; ?>. Easily accessible by jeepney, taxi, or private vehicle.</p>
    <div class="location-grid">
      <div class="location-map">
        <iframe
          src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d125375.43390869668!2d123.82866!3d10.31672!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x33a999258b6c9027%3A0x9c6c1e5fce07e0c8!2sCebu%20City%2C%20Cebu!5e0!3m2!1sen!2sph!4v1680000000000!5m2!1sen!2sph"
          allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade">
        </iframe>
      </div>
      <div class="location-details">
        <div class="loc-item">
          <div class="loc-item-icon"><i class="bi bi-geo-alt-fill"></i></div>
          <div>
            <h4>Address</h4>
            <p><?php echo $clinic_address; ?></p>
          </div>
        </div>
        <div class="loc-item">
          <div class="loc-item-icon"><i class="bi bi-clock-fill"></i></div>
          <div>
            <h4>Operating Hours</h4>
            <p>Monday – Friday: 8:00 AM – 5:00 PM<br>Saturday: <?php echo $clinic_hours_sat; ?><br>Sunday: Closed</p>
          </div>
        </div>
        <div class="loc-item">
          <div class="loc-item-icon"><i class="bi bi-telephone-fill"></i></div>
          <div>
            <h4>Contact</h4>
            <p>Landline: <?php echo $clinic_phone; ?><br>Mobile: <?php echo $clinic_mobile; ?><br>Email: <?php echo $clinic_email; ?></p>
          </div>
        </div>
        <div class="loc-item">
          <div class="loc-item-icon"><i class="bi bi-car-front-fill"></i></div>
          <div>
            <h4>Getting Here</h4>
            <p><?php echo $clinic_parking; ?></p>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<section id="booking">
  <div class="booking-wrapper">
    <div class="booking-header">
      <div class="section-label">Appointments</div>
      <h2 class="section-title">Request an Appointment</h2>
      <p class="section-sub">Fill in the form below and our staff will confirm your schedule shortly.</p>
    </div>
    <div class="booking-card">
      <?php if ($success): ?>
        <div class="success-box">
          <div class="success-icon"><i class="bi bi-check-lg"></i></div>
          <h3 style="font-weight:800; margin-bottom:8px;">Booking Received!</h3>
          <p style="color:var(--muted); font-size:0.9rem; margin-bottom:24px;">
            Your appointment request has been submitted.<br>The clinic will confirm your schedule shortly.
          </p>
          <a href="booking.php" class="btn-primary-custom" style="display:inline-flex;">
            <i class="bi bi-plus-circle"></i> Book Another Appointment
          </a>
        </div>
      <?php else: ?>
        <?php if ($error): ?>
          <div class="alert-custom">
            <i class="bi bi-exclamation-circle-fill" style="font-size:16px;"></i>
            <?php echo htmlspecialchars($error); ?>
          </div>
        <?php endif; ?>
        <form method="POST">
          <div class="section-divider"><hr><span>Your details</span><hr></div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label-custom">First name</label>
              <input type="text" name="first_name" class="form-control-custom" placeholder="Maria" required value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>">
            </div>
            <div class="form-group">
              <label class="form-label-custom">Last name</label>
              <input type="text" name="last_name" class="form-control-custom" placeholder="Reyes" required value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>">
            </div>
          </div>
          <div class="form-group">
            <label class="form-label-custom">Phone number</label>
            <input type="text" name="phone" class="form-control-custom" placeholder="09XXXXXXXXX" required value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
          </div>
          <div class="section-divider"><hr><span>Appointment details</span><hr></div>
          <div class="form-group">
            <label class="form-label-custom">Service</label>
            <select name="service_id" class="form-select-custom" required>
              <option value="">— Select a service —</option>
              <?php foreach ($services as $s): ?>
                <option value="<?php echo $s['id']; ?>" <?php echo (($_POST['service_id'] ?? '') == $s['id']) ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($s['service_name']); ?> — ₱<?php echo number_format($s['price'], 0); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label-custom">Doctor</label>
            <select name="doctor_id" class="form-select-custom" required>
              <option value="">— Select a doctor —</option>
              <?php foreach ($doctors as $d): ?>
                <option value="<?php echo $d['id']; ?>" <?php echo (($_POST['doctor_id'] ?? '') == $d['id']) ? 'selected' : ''; ?>>
                  <?php echo htmlspecialchars($d['full_name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label-custom">Preferred date</label>
              <input type="date" name="appt_date" class="form-control-custom" required min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" value="<?php echo htmlspecialchars($_POST['appt_date'] ?? ''); ?>">
            </div>
            <div class="form-group">
              <label class="form-label-custom">Preferred time</label>
              <select name="appt_time" class="form-select-custom" required>
                <option value="">— Pick a time —</option>
                <?php
                $slots = ['08:00','08:30','09:00','09:30','10:00','10:30','11:00','11:30','13:00','13:30','14:00','14:30','15:00','15:30','16:00','16:30'];
                foreach ($slots as $slot):
                  $label = date('h:i A', strtotime($slot));
                ?>
                  <option value="<?php echo $slot; ?>" <?php echo (($_POST['appt_time'] ?? '') == $slot) ? 'selected' : ''; ?>><?php echo $label; ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <button type="submit" class="btn-submit">
            <i class="bi bi-calendar-check-fill"></i> Request Appointment
          </button>
        </form>
        <p style="text-align:center; font-size:0.75rem; color:var(--muted); margin-top:20px; margin-bottom:0;">
          Are you staff? <a href="<?php echo BASE_URL; ?>index.php" style="color:var(--blue); font-weight:600;">Admin panel →</a>
        </p>
      <?php endif; ?>
    </div>
  </div>
</section>

<footer class="footer">
  <p style="margin:0 0 8px;"><strong style="color:#fff;"><?php echo $clinic_name; ?> Clinic</strong> · <?php echo $clinic_location; ?></p>
  <p style="margin:0;">© <?php echo date('Y'); ?> <?php echo $clinic_name; ?>. All rights reserved. &nbsp;·&nbsp; <a href="<?php echo BASE_URL; ?>index.php">Staff Login</a></p>
</footer>

</body>
</html>

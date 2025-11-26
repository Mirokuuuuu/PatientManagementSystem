<?php
session_start();

// Handle sign-out request
if (isset($_GET['logout']) && $_GET['logout'] == 'true') {
    session_destroy();
    header("Location: login.php");
    exit;
}

// Database configuration
$host = 'localhost';
$dbname = 'patient_management';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Check if user is logged in, if not redirect to login page
if (!isset($_SESSION['nurse_id'])) {
    header("Location: login.php");
    exit;
}

// Get logged-in user information
$nurse_id = $_SESSION['nurse_id'];

// Fetch nurse details from database
$stmt = $pdo->prepare("SELECT first_name, last_name, role FROM nurse WHERE nurse_id = ?");
$stmt->execute([$nurse_id]);
$nurse = $stmt->fetch(PDO::FETCH_ASSOC);

if ($nurse) {
    $user_name = $nurse['first_name'] . ' ' . $nurse['last_name'];
    $user_role = ucfirst($nurse['role']);
    
    // Store user info in session for persistence
    $_SESSION['user_name'] = $user_name;
    $_SESSION['user_role'] = $user_role;
} else {
    // If nurse not found in database, log them out
    session_destroy();
    header("Location: login.php");
    exit;
}

// Use session data for display (this persists across page loads)
$display_name = $_SESSION['user_name'] ?? 'Guest User';
$display_role = $_SESSION['user_role'] ?? 'Nurse';

// Get current date and time
$current_date = date('Y-m-d');
$current_time = date('H:i:s');

// Calculate statistics for summary cards
try {
    // Total Patients
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM patients");
    $total_patients = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Total patients from last month for comparison
    $last_month_start = date('Y-m-01', strtotime('-1 month'));
    $last_month_end = date('Y-m-t', strtotime('-1 month'));
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM patients WHERE DATE(created_at) BETWEEN ? AND ?");
    $stmt->execute([$last_month_start, $last_month_end]);
    $last_month_patients = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Calculate percentage change
    $patients_change = 0;
    if ($last_month_patients > 0) {
        $patients_change = round((($total_patients - $last_month_patients) / $last_month_patients) * 100);
    }
    
    // Today's Appointments
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM appointments WHERE DATE(appointment_date) = ?");
    $stmt->execute([$current_date]);
    $today_appointments = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Yesterday's appointments for comparison
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM appointments WHERE DATE(appointment_date) = ?");
    $stmt->execute([$yesterday]);
    $yesterday_appointments = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $appointments_change = $today_appointments - $yesterday_appointments;
    
    // Past Appointments (appointments with appointment_date before today OR status 'completed' or 'cancelled')
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM appointments WHERE DATE(appointment_date) < CURDATE() OR status IN ('completed', 'cancelled')");
    $past_appointments = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Past appointments from last month for comparison (all past appointments up to last month)
    $last_month_end = date('Y-m-t', strtotime('-1 month'));
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM appointments WHERE DATE(appointment_date) < ? OR status IN ('completed', 'cancelled')");
    $stmt->execute([$last_month_end]);
    $last_month_past = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $past_change = $past_appointments - $last_month_past;
    
    // This Month's Appointments
    $month_start = date('Y-m-01');
    $month_end = date('Y-m-t');
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM appointments WHERE DATE(appointment_date) BETWEEN ? AND ?");
    $stmt->execute([$month_start, $month_end]);
    $month_appointments = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Last month's appointments for comparison
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM appointments WHERE DATE(appointment_date) BETWEEN ? AND ?");
    $stmt->execute([$last_month_start, $last_month_end]);
    $last_month_appointments_count = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $month_change = 0;
    if ($last_month_appointments_count > 0) {
        $month_change = round((($month_appointments - $last_month_appointments_count) / $last_month_appointments_count) * 100);
    }
    
} catch(PDOException $e) {
    // Set default values if queries fail
    $total_patients = 0;
    $patients_change = 0;
    $today_appointments = 0;
    $appointments_change = 0;
    $past_appointments = 0;
    $past_change = 0;
    $month_appointments = 0;
    $month_change = 0;
}

// Get view type from URL parameter or default to 'day'
$view_type = isset($_GET['view']) ? $_GET['view'] : 'day';

// Fetch appointments from database based on view type (excluding past appointments)
try {
    if ($view_type == 'day') {
        // Today's appointments (only upcoming/current appointments, excluding past ones)
        $stmt = $pdo->prepare("
            SELECT * FROM appointments 
            WHERE DATE(appointment_date) = ? 
            AND (appointment_time > ? OR (appointment_time <= ? AND appointment_time >= DATE_SUB(?, INTERVAL 30 MINUTE)))
            AND status NOT IN ('completed', 'cancelled')
            ORDER BY appointment_time ASC
        ");
        $current_time_formatted = date('H:i:s');
        $stmt->execute([$current_date, $current_time_formatted, $current_time_formatted, $current_time_formatted]);
        $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } elseif ($view_type == 'week') {
        // This week's appointments (only upcoming appointments)
        $start_of_week = date('Y-m-d', strtotime('monday this week'));
        $end_of_week = date('Y-m-d', strtotime('sunday this week'));
        
        $stmt = $pdo->prepare("
            SELECT * FROM appointments 
            WHERE (DATE(appointment_date) BETWEEN ? AND ?)
            AND (appointment_date > ? OR (appointment_date = ? AND appointment_time > ?))
            AND status NOT IN ('completed', 'cancelled')
            ORDER BY appointment_date ASC, appointment_time ASC
        ");
        $stmt->execute([$start_of_week, $end_of_week, $current_date, $current_date, $current_time]);
        $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } elseif ($view_type == 'month') {
        // This month's appointments (only upcoming appointments)
        $start_of_month = date('Y-m-01');
        $end_of_month = date('Y-m-t');
        
        $stmt = $pdo->prepare("
            SELECT * FROM appointments 
            WHERE (DATE(appointment_date) BETWEEN ? AND ?)
            AND (appointment_date > ? OR (appointment_date = ? AND appointment_time > ?))
            AND status NOT IN ('completed', 'cancelled')
            ORDER BY appointment_date ASC, appointment_time ASC
        ");
        $stmt->execute([$start_of_month, $end_of_month, $current_date, $current_date, $current_time]);
        $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch(PDOException $e) {
    die("Error fetching appointments: " . $e->getMessage());
}

// Function to check if appointment is happening today
function isToday($appointment_date) {
    return $appointment_date == date('Y-m-d');
}

// Function to check if appointment is current (within 30 minutes before to 15 minutes after)
function isCurrentAppointment($appointment_time, $appointment_date) {
    if ($appointment_date != date('Y-m-d')) {
        return false;
    }
    
    $current_time = time();
    $appointment_timestamp = strtotime($appointment_date . ' ' . $appointment_time);
    
    // Use 30-minute window before and 15-minute window after appointment
    $thirty_min_before = $appointment_timestamp - 1800; // 30 minutes before appointment
    $fifteen_min_after = $appointment_timestamp + 900;  // 15 minutes after appointment
    
    return ($current_time >= $thirty_min_before && $current_time <= $fifteen_min_after);
}

// Function to check if appointment is in the past (for display purposes)
function isPastAppointment($appointment_time, $appointment_date, $status) {
    $appointment_timestamp = strtotime($appointment_date . ' ' . $appointment_time);
    $current_time = time();
    
    // If appointment date is before today, it's definitely past
    if ($appointment_date < date('Y-m-d')) {
        return true;
    }
    
    // If appointment date is today but time has passed and it's not current
    if ($appointment_date == date('Y-m-d') && $appointment_timestamp < $current_time) {
        // Check if it's within the current window
        $thirty_min_before = $appointment_timestamp - 1800;
        $fifteen_min_after = $appointment_timestamp + 900;
        
        if (!($current_time >= $thirty_min_before && $current_time <= $fifteen_min_after)) {
            return true;
        }
    }
    
    // If status is completed or cancelled, consider it past
    if (in_array(strtolower($status), ['completed', 'cancelled'])) {
        return true;
    }
    
    return false;
}

// Function to get status class based on appointment status
function getStatusClass($status) {
    $status_map = [
        'confirmed' => 'status-confirmed',
        'scheduled' => 'status-confirmed',
        'waiting' => 'status-waiting',
        'pending' => 'status-waiting',
        'urgent' => 'status-urgent',
        'emergency' => 'status-urgent',
        'completed' => 'status-completed',
        'cancelled' => 'status-cancelled'
    ];
    
    return $status_map[strtolower($status)] ?? 'status-waiting';
}

// Function to get status icon based on appointment status
function getStatusIcon($status) {
    $status = strtolower($status);
    if (in_array($status, ['confirmed', 'scheduled', 'completed'])) {
        return 'bi-check-circle appt-check';
    } elseif (in_array($status, ['waiting', 'pending'])) {
        return 'bi-exclamation-circle appt-warn';
    } elseif (in_array($status, ['urgent', 'emergency'])) {
        return 'bi-exclamation-triangle appt-err';
    } else {
        return 'bi-question-circle appt-warn';
    }
}

// Function to get doctor name based on appointment type or notes
function getDoctorName($appointment_type, $notes) {
    // Extract doctor name from notes or use a default based on appointment type
    if (strpos($notes, 'Dr.') !== false) {
        // Extract doctor name from notes
        preg_match('/Dr\.\s+[A-Za-z\s]+/', $notes, $matches);
        if (!empty($matches)) {
            return $matches[0];
        }
    }
    
    // Default doctors based on appointment type
    $doctor_map = [
        'Regular Checkup' => 'Dr. Maine',
        'Follow-up Checkup' => 'Dr. Maine',
        'Consultation' => 'Dr. Brown',
        'Emergency' => 'Dr. Garcia',
        'Other' => 'Dr. Smith'
    ];
    
    return $doctor_map[$appointment_type] ?? 'Dr. Unknown';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Nurse Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="nurse_dash.css" />

</head>
<body>
<div class="dashboard-root">
    <aside class="sidebar">
        <div>
            <div class="brand">
                <img src="logo.jpg" alt="MediSync Logo" style="height: 50px; margin: auto; display: block;" />
            </div>
            <br>
            
        </div>
        <ul class="nav-list">
            <li class="active"><i class="bi bi-grid"></i> Dashboard</li>
            <li><a href="patients.php" class="nav-link"><i class="bi bi-people"></i> Patients</a></li>
            <li><a href="appointments.php" class="nav-link"><i class="bi bi-calendar-event"></i> Appointments</a></li>
        </ul>
        
    </aside>
    
    <main class="main-content">
        <header class="topbar">
            <div style="display:flex;align-items:center;gap:12px">
            </div>
            <div style="display:flex;align-items:center;gap:14px">
                <div class="small-muted"><?php echo htmlspecialchars($display_name); ?><br><small class="small-muted">Role: <?php echo htmlspecialchars($display_role); ?></small></div>
                <button class="btn btn-outline-dark btn-sm" onclick="signOut()">Sign Out</button>
            </div>
        </header><br>
        <div class="main-header">
            <h2>Dashboard</h2>
        </div>
        <div class="summary-cards">
            <div class="summary">
                <div class="label">Total Patients</div>
                <div class="value">
                    <?php echo number_format($total_patients); ?> 
                    <span class="trend <?php echo $patients_change >= 0 ? 'positive' : 'negative'; ?>">
                        <?php echo $patients_change >= 0 ? '+' : ''; ?><?php echo $patients_change; ?>%
                    </span> 
                    <i class="bi bi-people icon"></i>
                </div>
                <div class="small-muted">vs last month</div>
            </div>
            <div class="summary">
                <div class="label">Today's Appointments</div>
                <div class="value">
                    <?php echo $today_appointments; ?> 
                    <span class="trend <?php echo $appointments_change >= 0 ? 'positive' : 'negative'; ?>">
                        <?php echo $appointments_change >= 0 ? '+' : ''; ?><?php echo $appointments_change; ?>
                    </span> 
                    <i class="bi bi-calendar-event icon icon-calendar"></i>
                </div>
                <div class="small-muted">vs yesterday</div>
            </div>
            <div class="summary past-appointments-card" onclick="viewPastAppointments()">
                <div class="label">Past Appointments</div>
                <div class="value">
                    <?php echo $past_appointments; ?> 
                    <span class="trend <?php echo $past_change >= 0 ? 'positive' : 'negative'; ?>">
                        <?php echo $past_change >= 0 ? '+' : ''; ?><?php echo $past_change; ?>
                    </span> 
                    <i class="bi bi-clock-history icon icon-warning"></i>
                </div>
                <div class="small-muted">vs last month</div>
            </div>
            <div class="summary">
                <div class="label">This Month</div>
                <div class="value">
                    <?php echo $month_appointments; ?> 
                    <span class="trend <?php echo $month_change >= 0 ? 'positive' : 'negative'; ?>">
                        <?php echo $month_change >= 0 ? '+' : ''; ?><?php echo $month_change; ?>%
                    </span> 
                    <i class="bi bi-bar-chart icon icon-stats"></i>
                </div>
                <div class="small-muted">vs last month</div>
            </div>
        </div>
        <div class="row g-3">
            <div class="col-lg-8">
                <section class="appt-section">
                    <div class="appt-header d-flex align-items-center justify-content-between">
                        <h6 style="margin:0">
                            <?php 
                            if ($view_type == 'day') {
                                echo "Today's Appointments";
                            } elseif ($view_type == 'week') {
                                echo "This Week's Appointments";
                            } else {
                                echo "This Month's Appointments";
                            }
                            ?>
                        </h6>
                        <div class="d-flex align-items-center gap-2">
                            <button class="btn btn-light btn-sm" onclick="window.location.href='appointments.php'">View All</button>
                            <div class="view-filter">
                                <a href="?view=day" class="btn btn-sm <?php echo $view_type == 'day' ? 'active' : 'btn-outline-dark'; ?>">Day</a>
                                <a href="?view=week" class="btn btn-sm <?php echo $view_type == 'week' ? 'active' : 'btn-outline-dark'; ?>">Week</a>
                                <a href="?view=month" class="btn btn-sm <?php echo $view_type == 'month' ? 'active' : 'btn-outline-dark'; ?>">Month</a>
                            </div>
                        </div>
                    </div>
                    <div class="appt-list">
                        <?php if (empty($appointments)): ?>
                            <div class="text-center py-4 text-muted">
                                <i class="bi bi-calendar-x" style="font-size: 2rem;"></i>
                                <p class="mt-2">No appointments found for this period.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($appointments as $appointment): ?>
                                <?php 
                                $is_today = isToday($appointment['appointment_date']);
                                $is_current = isCurrentAppointment($appointment['appointment_time'], $appointment['appointment_date']);
                                $is_past = isPastAppointment($appointment['appointment_time'], $appointment['appointment_date'], $appointment['status']);
                                
                                $card_class = '';
                                $badge_text = '';
                                $badge_class = '';
                                
                                if ($is_current) {
                                    $card_class = 'current';
                                    $badge_text = 'Current';
                                    $badge_class = 'bg-success';
                                } elseif ($is_today && !$is_past) {
                                    $card_class = 'today';
                                    $badge_text = 'Today';
                                    $badge_class = 'bg-primary';
                                }
                                
                                $patient_name = htmlspecialchars($appointment['patientname']);
                                $appointment_time = date('g:i A', strtotime($appointment['appointment_time']));
                                $appointment_date_display = date('M j', strtotime($appointment['appointment_date']));
                                $appointment_type = htmlspecialchars($appointment['appointment_type'] ?? 'Consultation');
                                $doctor_name = getDoctorName($appointment_type, $appointment['notes'] ?? '');
                                $status = htmlspecialchars($appointment['status'] ?? 'scheduled');
                                ?>
                                <div class="appt-card <?php echo $card_class; ?>" style="cursor: pointer;" onclick="window.location.href='appointments.php?id=<?php echo $appointment['id']; ?>'">
                                    <div class="appt-info">
                                        <span class="appt-name">
                                            <?php echo $patient_name; ?> 
                                            <span class="appt-status <?php echo getStatusClass($status); ?>">
                                                <?php echo ucfirst($status); ?>
                                            </span>
                                            <?php if ($badge_text): ?>
                                                <span class="badge <?php echo $badge_class; ?> ms-1"><?php echo $badge_text; ?></span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="appt-meta">
                                            <?php if (!$is_today): ?>
                                                <?php echo $appointment_date_display; ?> • 
                                            <?php endif; ?>
                                            <?php echo $appointment_time; ?> • 
                                            <?php echo $appointment_type; ?> • 
                                            <?php echo $doctor_name; ?>
                                        </span>
                                    </div>
                                    <i class="bi <?php echo getStatusIcon($status); ?>"></i>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>
            </div>
            <div class="col-lg-4">
                <section class="alerts-section">
                    <div class="alerts-list">
                        <div class="alert-card alert-orange d-flex align-items-center">
                            <i class="bi bi-exclamation-triangle-fill" style="color:#f59e42;font-size:22px"></i>
                            <span class="alert-title">12 patients have overdue appointments</span>
                            <span class="alert-link ms-auto"><button class="btn btn-outline-warning btn-sm">View Details</button></span>
                        </div>
                        <div class="alert-card alert-blue d-flex align-items-center">
                            <i class="bi bi-info-circle-fill" style="color:#2563eb;font-size:22px"></i>
                            <span style="font-weight:600;color:#2563eb">New patient registration system update available</span>
                            <span class="alert-link ms-auto"><button class="btn btn-outline-primary btn-sm">Learn More</button></span>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </main>
</div>

<script>
function viewPastAppointments() {
    // Redirect to appointments page with a filter for past appointments
    window.location.href = 'appointments.php?filter=past';
}

// Add click event listener to the past appointments card
document.addEventListener('DOMContentLoaded', function() {
    const pastAppointmentsCard = document.querySelector('.past-appointments-card');
    if (pastAppointmentsCard) {
        pastAppointmentsCard.addEventListener('click', viewPastAppointments);
    }
});

function signOut() {
    // Redirect to logout handler which destroys session
    window.location.href = 'nurse_dash.php?logout=true';
}
</script>
</body>
</html>
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

// Get logged-in user information
$doctor_id = isset($_SESSION['doctor_id']) ? $_SESSION['doctor_id'] : null;

// Fetch doctor details from database if logged in
if ($doctor_id) {
    $stmt = $pdo->prepare("SELECT first_name, last_name, email, department FROM doctor WHERE doctor_id = ?");
    $stmt->execute([$doctor_id]);
    $doctor = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($doctor) {
        $user_name = 'Dr. ' . $doctor['first_name'] . ' ' . $doctor['last_name'];
        $user_role = $doctor['department'] ?? 'General Medicine';
    } else {
        $user_name = 'Guest Doctor';
        $user_role = 'General Medicine';
    }
} else {
    $user_name = 'Guest Doctor';
    $user_role = 'General Medicine';
}

// Handle appointment actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle reschedule appointment
    if (isset($_POST['reschedule_appointment'])) {
        try {
            $stmt = $pdo->prepare("UPDATE appointments SET appointment_date = ?, appointment_time = ?, notes = CONCAT(IFNULL(notes, ''), ' Rescheduled: ', NOW()) WHERE id = ?");
            $stmt->execute([
                $_POST['new_date'],
                $_POST['new_time'],
                $_POST['appointment_id']
            ]);
            $success_message = "Appointment rescheduled successfully!";
        } catch(PDOException $e) {
            $error_message = "Error rescheduling appointment: " . $e->getMessage();
        }
    }

    // Handle cancel appointment
    if (isset($_POST['cancel_appointment'])) {
        try {
            $stmt = $pdo->prepare("UPDATE appointments SET status = 'Cancelled', notes = CONCAT(IFNULL(notes, ''), ' Cancelled: ', NOW()) WHERE id = ?");
            $stmt->execute([$_POST['appointment_id']]);
            $success_message = "Appointment cancelled successfully!";
        } catch(PDOException $e) {
            $error_message = "Error cancelling appointment: " . $e->getMessage();
        }
    }

    // Handle complete appointment
    if (isset($_POST['complete_appointment'])) {
        try {
            $stmt = $pdo->prepare("UPDATE appointments SET status = 'Completed', notes = CONCAT(IFNULL(notes, ''), ' Completed: ', NOW()) WHERE id = ?");
            $stmt->execute([$_POST['appointment_id']]);
            $success_message = "Appointment marked as completed!";
        } catch(PDOException $e) {
            $error_message = "Error completing appointment: " . $e->getMessage();
        }
    }
}

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
    
    // Past Appointments - Count appointments that are completed, cancelled, or have date before today
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total FROM appointments 
        WHERE status IN ('completed', 'cancelled') 
        OR DATE(appointment_date) < CURDATE()
    ");
    $stmt->execute();
    $past_appointments = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Past appointments from last month for comparison
    $last_month_end = date('Y-m-t', strtotime('-1 month'));
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total FROM appointments 
        WHERE status IN ('completed', 'cancelled') 
        OR DATE(appointment_date) < ?
    ");
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
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doctor Dashboard</title>
    <link rel="stylesheet" href="doctor_dash.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/css/all.min.css">
    
</head>
<body>
    <div class="app">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="logo">
                <img src="logo.jpg" alt="MediSync Logo" style="height: 50px; margin: auto; display: block;" />
            </div>
            

            <nav>
                <a href="doctor_dash.php" class="active"><i class="fas fa-th-large"></i> Dashboard</a>
                <a href="patients_appointments.php"><i class="fas fa-calendar-check"></i> Patient Appointments</a>
                <a href="medical_history.php"><i class="fas fa-file-medical"></i> Medical History</a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="main">
            <header class="topbar">
                <div style="display:flex;align-items:center;gap:12px">
                </div>
                <div style="display:flex;align-items:center;gap:14px">
                    <div class="small-muted"><?php echo htmlspecialchars($user_name); ?><br><small class="small-muted">Department: <?php echo htmlspecialchars($user_role); ?></small></div>
                    <button class="btn btn-outline-dark btn-sm" onclick="signOut()">Sign Out</button>
                </div>
            </header><br>

            <?php if (isset($success_message)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo $success_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (isset($error_message)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo $error_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="welcome">
                <h1>Dashboard</h1>
                <p>Welcome back! Here's what's happening today.</p>
            </div>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value">
                        <?php echo number_format($total_patients); ?> 
                        <span class="trend <?php echo $patients_change >= 0 ? 'up' : 'down'; ?>">
                            <?php echo $patients_change >= 0 ? '+' : ''; ?><?php echo $patients_change; ?>%
                        </span>
                    </div>
                    <div class="stat-label">Total Patients</div>
                    <div class="stat-period">vs last month</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value">
                        <?php echo $today_appointments; ?> 
                        <span class="trend <?php echo $appointments_change >= 0 ? 'up' : 'down'; ?>">
                            <?php echo $appointments_change >= 0 ? '+' : ''; ?><?php echo $appointments_change; ?>
                        </span>
                    </div>
                    <div class="stat-label">Today's Appointments</div>
                    <div class="stat-period">vs yesterday</div>
                </div>
                <div class="stat-card past-appointments-card" onclick="viewPastAppointments()">
                    <div class="stat-value">
                        <?php echo $past_appointments; ?> 
                        <span class="trend <?php echo $past_change >= 0 ? 'up' : 'down'; ?>">
                            <?php echo $past_change >= 0 ? '+' : ''; ?><?php echo $past_change; ?>
                        </span>
                    </div>
                    <div class="stat-label">Past Appointments</div>
                    <div class="stat-period">vs last month</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value">
                        <?php echo $month_appointments; ?> 
                        <span class="trend <?php echo $month_change >= 0 ? 'up' : 'down'; ?>">
                            <?php echo $month_change >= 0 ? '+' : ''; ?><?php echo $month_change; ?>%
                        </span>
                    </div>
                    <div class="stat-label">This Month</div>
                    <div class="stat-period">vs last month</div>
                </div>
            </div>

            <div class="content-grid">
                <section class="appointments">
                    <div class="section-header">
                        <h2>
                            <?php 
                            if ($view_type == 'day') {
                                echo "Today's Appointments";
                            } elseif ($view_type == 'week') {
                                echo "This Week's Appointments";
                            } else {
                                echo "This Month's Appointments";
                            }
                            ?>
                        </h2>
                        <div class="d-flex align-items-center gap-2">
                            <a href="patients_appointments.php" class="view-all">View All</a>
                            <div class="view-filter">
                                <a href="?view=day" class="btn btn-sm <?php echo $view_type == 'day' ? 'active' : 'btn-outline-dark'; ?>">Day</a>
                                <a href="?view=week" class="btn btn-sm <?php echo $view_type == 'week' ? 'active' : 'btn-outline-dark'; ?>">Week</a>
                                <a href="?view=month" class="btn btn-sm <?php echo $view_type == 'month' ? 'active' : 'btn-outline-dark'; ?>">Month</a>
                            </div>
                        </div>
                    </div>

                    <div class="appointment-list">
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
                                
                                $card_class = '';
                                $badge_text = '';
                                $badge_class = '';
                                
                                if ($is_current) {
                                    $card_class = 'current';
                                    $badge_text = 'Current';
                                    $badge_class = 'bg-success';
                                } elseif ($is_today) {
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
                                <div class="appointment-card <?php echo $card_class; ?>">
                                    <div class="patient-info">
                                        <div class="patient-name">
                                            <?php echo $patient_name; ?>
                                            <span class="status <?php echo getStatusClass($status); ?>">
                                                <?php echo ucfirst($status); ?>
                                            </span>
                                            <?php if ($badge_text): ?>
                                                <span class="badge <?php echo $badge_class; ?> ms-1"><?php echo $badge_text; ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="appointment-details">
                                        <div class="time">
                                            <?php if (!$is_today): ?>
                                                <?php echo $appointment_date_display; ?> • 
                                            <?php endif; ?>
                                            <?php echo $appointment_time; ?> • 
                                            <?php echo $appointment_type; ?> • 
                                            <?php echo $doctor_name; ?>
                                        </div>
                                    </div>
                                    <div class="appointment-actions">
                                        <?php if ($appointment['status'] != 'Cancelled' && $appointment['status'] != 'Completed'): ?>
                                            <button class="btn btn-outline-primary btn-sm" 
                                                    onclick="showRescheduleModal(<?php echo $appointment['id']; ?>, '<?php echo htmlspecialchars($appointment['appointment_type']); ?>', '<?php echo $appointment['appointment_date']; ?>', '<?php echo $appointment['appointment_time']; ?>')">
                                                Reschedule
                                            </button>
                                            <button class="btn btn-outline-success btn-sm" 
                                                    onclick="showCompleteModal(<?php echo $appointment['id']; ?>, '<?php echo htmlspecialchars($appointment['appointment_type']); ?>')">
                                                Complete
                                            </button>
                                            <button class="btn btn-outline-danger btn-sm" 
                                                    onclick="showCancelModal(<?php echo $appointment['id']; ?>, '<?php echo htmlspecialchars($appointment['appointment_type']); ?>')">
                                                Cancel
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                    <i class="bi <?php echo getStatusIcon($status); ?>"></i>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="notifications">
                    <div class="section-header">
                        <h2>Alerts & Notifications</h2>
                        <a href="#" class="view-all">View All</a>
                    </div>

                    <div class="alert warning">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                            <path d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" stroke="currentColor" stroke-width="2"/>
                        </svg>
                        <span>12 patients have overdue appointments</span>
                        <a href="#" class="view-details">View Details</a>
                    </div>

                    
                    <div class="alert info">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                            <path d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" stroke="currentColor" stroke-width="2"/>
                        </svg>
                        <span>New patient registration system update available</span>
                        <a href="#" class="learn-more">Learn More</a>
                    </div>
                </section>
            </div>
        </main>
    </div>

    <!-- Reschedule Modal -->
    <div id="rescheduleModal" class="modal-overlay" aria-hidden="true">
        <div class="modal-card">
            <div class="modal-header">
                <h5 class="modal-title">Reschedule Appointment</h5>
                <button type="button" class="btn-close" onclick="closeRescheduleModal()"></button>
            </div>
            <div class="modal-body">
                <p id="rescheduleAppointmentInfo" class="mb-3"></p>
                <form id="rescheduleForm" method="POST">
                    <input type="hidden" name="reschedule_appointment" value="1">
                    <input type="hidden" id="rescheduleAppointmentId" name="appointment_id">
                    <div class="mb-3">
                        <label for="newDate" class="form-label">New Date</label>
                        <input type="date" class="form-control" id="newDate" name="new_date" required>
                    </div>
                    <div class="mb-3">
                        <label for="newTime" class="form-label">New Time</label>
                        <input type="time" class="form-control" id="newTime" name="new_time" required>
                    </div>
                    <div class="mb-3">
                        <label for="rescheduleReason" class="form-label">Reason for Rescheduling (Optional)</label>
                        <textarea class="form-control" id="rescheduleReason" name="reason" rows="3"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeRescheduleModal()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="submitReschedule()">Confirm Reschedule</button>
            </div>
        </div>
    </div>

    <!-- Cancel Appointment Modal -->
    <div id="cancelModal" class="modal-overlay" aria-hidden="true">
        <div class="modal-card">
            <div class="modal-header">
                <h5 class="modal-title">Cancel Appointment</h5>
                <button type="button" class="btn-close" onclick="closeCancelModal()"></button>
            </div>
            <div class="modal-body">
                <p id="cancelAppointmentInfo" class="mb-3"></p>
                <p>Are you sure you want to cancel this appointment?</p>
                <form id="cancelForm" method="POST">
                    <input type="hidden" name="cancel_appointment" value="1">
                    <input type="hidden" id="cancelAppointmentId" name="appointment_id">
                    <div class="mb-3">
                        <label for="cancelReason" class="form-label">Reason for Cancellation (Optional)</label>
                        <textarea class="form-control" id="cancelReason" name="reason" rows="3"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeCancelModal()">No, Keep It</button>
                <button type="button" class="btn btn-danger" onclick="submitCancel()">Yes, Cancel Appointment</button>
            </div>
        </div>
    </div>

    <!-- Complete Appointment Modal -->
    <div id="completeModal" class="modal-overlay" aria-hidden="true">
        <div class="modal-card">
            <div class="modal-header">
                <h5 class="modal-title">Complete Appointment</h5>
                <button type="button" class="btn-close" onclick="closeCompleteModal()"></button>
            </div>
            <div class="modal-body">
                <p id="completeAppointmentInfo" class="mb-3"></p>
                <p>Mark this appointment as completed?</p>
                <form id="completeForm" method="POST">
                    <input type="hidden" name="complete_appointment" value="1">
                    <input type="hidden" id="completeAppointmentId" name="appointment_id">
                    <div class="mb-3">
                        <label for="completeNotes" class="form-label">Additional Notes (Optional)</label>
                        <textarea class="form-control" id="completeNotes" name="notes" rows="3"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeCompleteModal()">Cancel</button>
                <button type="button" class="btn btn-success" onclick="submitComplete()">Yes, Complete Appointment</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function viewPastAppointments() {
        // Redirect to appointments page with a filter for past appointments
        window.location.href = 'patients_appointments.php?filter=past';
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
        window.location.href = 'doctor_dash.php?logout=true';
    }

    // Show reschedule modal
    function showRescheduleModal(appointmentId, appointmentType, currentDate, currentTime) {
        document.getElementById('rescheduleAppointmentId').value = appointmentId;
        document.getElementById('rescheduleAppointmentInfo').textContent = 
            'Rescheduling: ' + appointmentType;
        
        // Set minimum date to today
        const today = new Date().toISOString().split('T')[0];
        document.getElementById('newDate').setAttribute('min', today);
        document.getElementById('newDate').value = currentDate;
        document.getElementById('newTime').value = currentTime;
        
        document.getElementById('rescheduleModal').classList.add('open');
        document.getElementById('rescheduleModal').setAttribute('aria-hidden','false');
    }

    // Close reschedule modal
    function closeRescheduleModal() {
        document.getElementById('rescheduleModal').classList.remove('open');
        document.getElementById('rescheduleModal').setAttribute('aria-hidden','true');
    }

    // Submit reschedule
    function submitReschedule() {
        document.getElementById('rescheduleForm').submit();
    }

    // Show cancel modal
    function showCancelModal(appointmentId, appointmentType) {
        document.getElementById('cancelAppointmentId').value = appointmentId;
        document.getElementById('cancelAppointmentInfo').textContent = 
            'You are about to cancel: ' + appointmentType;
        
        document.getElementById('cancelModal').classList.add('open');
        document.getElementById('cancelModal').setAttribute('aria-hidden','false');
    }

    // Close cancel modal
    function closeCancelModal() {
        document.getElementById('cancelModal').classList.remove('open');
        document.getElementById('cancelModal').setAttribute('aria-hidden','true');
    }

    // Submit cancel
    function submitCancel() {
        document.getElementById('cancelForm').submit();
    }

    // Show complete modal
    function showCompleteModal(appointmentId, appointmentType) {
        document.getElementById('completeAppointmentId').value = appointmentId;
        document.getElementById('completeAppointmentInfo').textContent = 
            'Completing: ' + appointmentType;
        
        document.getElementById('completeModal').classList.add('open');
        document.getElementById('completeModal').setAttribute('aria-hidden','false');
    }

    // Close complete modal
    function closeCompleteModal() {
        document.getElementById('completeModal').classList.remove('open');
        document.getElementById('completeModal').setAttribute('aria-hidden','true');
    }

    // Submit complete
    function submitComplete() {
        document.getElementById('completeForm').submit();
    }

    // Close modals when clicking outside
    document.addEventListener('click', function(e) {
        const rescheduleModal = document.getElementById('rescheduleModal');
        const cancelModal = document.getElementById('cancelModal');
        const completeModal = document.getElementById('completeModal');
        
        if (rescheduleModal && rescheduleModal.classList.contains('open') && e.target === rescheduleModal) {
            closeRescheduleModal();
        }
        
        if (cancelModal && cancelModal.classList.contains('open') && e.target === cancelModal) {
            closeCancelModal();
        }
        
        if (completeModal && completeModal.classList.contains('open') && e.target === completeModal) {
            closeCompleteModal();
        }
    });
    </script>
</body>
</html>
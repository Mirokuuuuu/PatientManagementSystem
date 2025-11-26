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
    $stmt = $pdo->prepare("SELECT first_name, last_name, department FROM doctor WHERE doctor_id = ?");
    $stmt->execute([$doctor_id]);
    $doctor = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($doctor) {
        $user_name = 'Dr. ' . $doctor['first_name'] . ' ' . $doctor['last_name'];
        $user_role = $doctor['department'] ?? 'Doctor';
    } else {
        $user_name = 'Guest Doctor';
        $user_role = 'Doctor';
    }
} else {
    $user_name = 'Guest Doctor';
    $user_role = 'Doctor';
}

// Get current date and time
$current_date = date('Y-m-d');
$current_time = date('H:i:s');

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

// Calculate statistics
$today_patients = 0;
$completed_count = 0;
$past_appointments = 0;
$urgent_count = 0;

// Calculate past appointments (appointments that are completed, cancelled, or have date before today)
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total FROM appointments 
        WHERE status IN ('completed', 'cancelled') 
        OR DATE(appointment_date) < CURDATE()
    ");
    $stmt->execute();
    $past_appointments = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
} catch(PDOException $e) {
    $past_appointments = 0;
}

foreach ($appointments as $appointment) {
    $today_patients++;
    $status = strtolower($appointment['status'] ?? 'scheduled');
    
    if ($status == 'completed') {
        $completed_count++;
    } elseif (in_array($status, ['urgent', 'emergency'])) {
        $urgent_count++;
    }
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
        'in progress' => 'status-waiting',
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
    } elseif (in_array($status, ['waiting', 'pending', 'in progress'])) {
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

// Function to get patient initials for avatar
function getPatientInitials($patient_name) {
    $names = explode(' ', $patient_name);
    $initials = '';
    
    if (count($names) >= 2) {
        $initials = strtoupper(substr($names[0], 0, 1) . substr($names[count($names)-1], 0, 1));
    } else {
        $initials = strtoupper(substr($patient_name, 0, 2));
    }
    
    return $initials;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Appointments - Pamantasan ng Lungsod ng Pasig</title>
    <link rel="stylesheet" href="patients_appointments.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
</head>
<body>
    <div class="sidebar">
        <div class="logo">
            <img src="logo.jpg" alt="MediSync Logo" style="height: 50px; margin: auto; display: block;" />
        </div>

        <nav>
            <a href="doctor_dash.php"><i class="fas fa-th-large"></i> Dashboard</a>
            <a href="patients_appointments.php" class="active"><i class="fas fa-calendar-check"></i> Patient Appointments</a>
            <a href="medical_history.php"><i class="fas fa-file-medical"></i> Medical History</a>
        </nav>
    </div>

    <main class="content">
        <header class="topbar">
            <div style="display:flex;align-items:center;gap:12px">
            </div>
            <div style="display:flex;align-items:center;gap:14px">
                <div class="small-muted"><?php echo htmlspecialchars($user_name); ?><br><small class="small-muted">Department: <?php echo htmlspecialchars($user_role); ?></small></div>
                <button class="btn btn-outline-dark btn-sm" onclick="signOut()">Sign Out</button>
            </div>
        </header><br>

        <div class="page-header">
            <h1>View patient appointments</h1>
        </div>

        <div class="stats-container">
            <div class="stat-card">
                <h3>Today's Patients</h3>
                <div class="stat-value">
                    <span class="number"><?php echo $today_patients; ?></span>
                    <i class="fas fa-users"></i>
                </div>
            </div>
            <div class="stat-card">
                <h3>Past Appointments</h3>
                <div class="stat-value">
                    <span class="number"><?php echo $past_appointments; ?></span>
                    <i class="fas fa-history"></i>
                </div>
            </div>
        </div>

        <div class="appointments-section">
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
                <div class="d-flex align-items-center">
                    <div class="search-appointments">
                        <i class="fas fa-search"></i>
                        <input type="text" placeholder="Search patients..." id="searchInput" onkeyup="searchPatients()">
                    </div>
                    <div class="view-filter">
                        <a href="?view=day" class="btn btn-sm <?php echo $view_type == 'day' ? 'active' : 'btn-outline-dark'; ?>">Day</a>
                        <a href="?view=week" class="btn btn-sm <?php echo $view_type == 'week' ? 'active' : 'btn-outline-dark'; ?>">Week</a>
                        <a href="?view=month" class="btn btn-sm <?php echo $view_type == 'month' ? 'active' : 'btn-outline-dark'; ?>">Month</a>
                    </div>
                </div>
            </div>

            <div class="appointments-list" id="appointmentsList">
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
                        $patient_initials = getPatientInitials($patient_name);
                        ?>
                        <div class="appointment-card <?php echo $card_class; ?>" data-patient-name="<?php echo strtolower($patient_name); ?>" data-appointment-type="<?php echo strtolower($appointment_type); ?>" data-status="<?php echo strtolower($status); ?>">
                            <div class="patient-info">
                                <div class="avatar">
                                    <?php echo $patient_initials; ?>
                                </div>
                                <div class="details">
                                    <h3><?php echo $patient_name; ?></h3>
                                    <p>P-00<?php echo $appointment['appointment_id'] ?? '1'; ?> • <?php echo $appointment['patient_age'] ?? 'N/A'; ?>y • <?php echo $appointment['patient_gender'] ?? 'N/A'; ?></p>
                                    <div class="appointment-time">
                                        <i class="far fa-clock"></i> <?php echo $appointment_time; ?>
                                        <i class="fas fa-stethoscope"></i> <?php echo $appointment_type; ?>
                                        <i class="fas fa-clock"></i> <?php echo $appointment['duration'] ?? '30'; ?> min
                                    </div>
                                    <p class="chief-complaint">Chief Complaint: <?php echo htmlspecialchars($appointment['notes'] ?? 'No complaint specified'); ?></p>
                                </div>
                            </div>
                            <div class="status">
                                <span class="<?php echo getStatusClass($status); ?>">
                                    <?php echo ucfirst($status); ?>
                                </span>
                                <?php if ($badge_text): ?>
                                    <span class="badge <?php echo $badge_class; ?>"><?php echo $badge_text; ?></span>
                                <?php endif; ?>
                                <i class="bi <?php echo getStatusIcon($status); ?>"></i>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script>
        function searchPatients() {
            const input = document.getElementById('searchInput');
            const filter = input.value.toLowerCase();
            const appointments = document.getElementById('appointmentsList');
            const cards = appointments.getElementsByClassName('appointment-card');
            let hasVisibleResults = false;
            
            for (let i = 0; i < cards.length; i++) {
                const card = cards[i];
                const patientName = card.getAttribute('data-patient-name');
                const appointmentType = card.getAttribute('data-appointment-type');
                const status = card.getAttribute('data-status');
                
                // Search in patient name, appointment type, and status
                if (patientName.includes(filter) || 
                    appointmentType.includes(filter) || 
                    status.includes(filter)) {
                    card.classList.remove('hidden');
                    hasVisibleResults = true;
                } else {
                    card.classList.add('hidden');
                }
            }
            
            // Show no results message if no cards are visible
            const noResultsMessage = appointments.querySelector('.no-results');
            if (!hasVisibleResults && cards.length > 0) {
                if (!noResultsMessage) {
                    const noResultsDiv = document.createElement('div');
                    noResultsDiv.className = 'no-results';
                    noResultsDiv.innerHTML = `
                        <i class="bi bi-search"></i>
                        <h4>No patients found</h4>
                        <p>Try adjusting your search terms</p>
                    `;
                    appointments.appendChild(noResultsDiv);
                }
            } else if (noResultsMessage) {
                noResultsMessage.remove();
            }
        }

        function signOut() {
            // Redirect to logout handler which destroys session
            window.location.href = 'patients_appointments.php?logout=true';
        }
        
        // Clear search when changing view
        document.addEventListener('DOMContentLoaded', function() {
            const viewLinks = document.querySelectorAll('.view-filter a');
            viewLinks.forEach(link => {
                link.addEventListener('click', function() {
                    const searchInput = document.getElementById('searchInput');
                    if (searchInput) {
                        searchInput.value = '';
                        searchPatients(); // Reset the search to show all appointments
                    }
                });
            });
        });
    </script>
</body>
</html>
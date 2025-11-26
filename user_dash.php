<?php
session_start();

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "patient_management";

$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Handle sign-out request
if (isset($_GET['signout']) && $_GET['signout'] == 'true') {
    session_destroy();
    header("Location: login.php");
    exit;
}

// Redirect to login if not a patient
if (!isset($_SESSION['userRole']) || $_SESSION['userRole'] !== 'patient') {
    header("Location: login.php");
    exit;
}

// Get current patient data
function getCurrentPatientData($conn) {
    if (!isset($_SESSION['patient_id'])) {
        return null;
    }
    
    $sql = "SELECT id, first_name, last_name, email, phone_number, address FROM patients WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $_SESSION['patient_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows === 1) {
        return $result->fetch_assoc();
    }
    
    return null;
}

// Fetch all doctors from the database
function getAllDoctors($conn) {
    $sql = "SELECT doctor_id, first_name, last_name, department FROM doctor ORDER BY first_name, last_name";
    $result = $conn->query($sql);
    
    $doctors = [];
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $doctors[] = $row;
        }
    }
    return $doctors;
}

// Fetch upcoming appointments for the logged-in patient
function getUpcomingAppointments($conn) {
    if (!isset($_SESSION['patient_id'])) {
        return [];
    }
    
    $current_date = date('Y-m-d');
    
    $patient_data = getCurrentPatientData($conn);
    if (!$patient_data) {
        return [];
    }
    
    $patient_name = $patient_data['first_name'] . ' ' . $patient_data['last_name'];
    
    $sql = "SELECT 
                a.id,
                a.patientname,
                a.appointment_date,
                a.appointment_time,
                a.appointment_type,
                a.notes,
                a.doctor_name,
                a.created_at,
                a.status
            FROM appointments a
            WHERE a.patientname = ?
            AND a.appointment_date >= ?
            AND a.status != 'Cancelled'
            ORDER BY a.appointment_date ASC, a.appointment_time ASC";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("SQL Prepare failed: " . $conn->error);
        return [];
    }
    
    $stmt->bind_param("ss", $patient_name, $current_date);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $appointments = [];
    while ($row = $result->fetch_assoc()) {
        $appointments[] = $row;
    }
    
    $stmt->close();
    return $appointments;
}

// Fetch patient's medical documents
function getPatientDocuments($conn) {
    if (!isset($_SESSION['patient_id'])) {
        return [];
    }
    
    $patient_id = $_SESSION['patient_id'];
    
    $sql = "SELECT 
                id,
                document_type,
                document_title,
                file_name,
                file_path,
                file_size,
                upload_date,
                notes
            FROM medical_documents
            WHERE patient_id = ?
            ORDER BY upload_date DESC";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $documents = [];
    while ($row = $result->fetch_assoc()) {
        $documents[] = $row;
    }
    
    $stmt->close();
    return $documents;
}

// Fetch patient's prescriptions with optional month filter
function getPatientPrescriptions($conn, $month_filter = null) {
    if (!isset($_SESSION['patient_id'])) {
        return [];
    }
    
    $patient_id = $_SESSION['patient_id'];
    
    // Simple query without doctor join
    $sql = "SELECT * FROM prescriptions WHERE patient_id = ?";
    
    // Add month filter if provided
    if ($month_filter && $month_filter !== 'all') {
        $month_number = date('m', strtotime($month_filter . " 1"));
        $sql .= " AND MONTH(created_at) = ?";
    }
    
    $sql .= " ORDER BY created_at DESC";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    
    if ($month_filter && $month_filter !== 'all') {
        $stmt->bind_param("is", $patient_id, $month_number);
    } else {
        $stmt->bind_param("i", $patient_id);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $prescriptions = [];
    while ($row = $result->fetch_assoc()) {
        $prescriptions[] = $row;
    }
    
    $stmt->close();
    return $prescriptions;
}

// Fetch patient's lab test requests
function getPatientLabRequests($conn) {
    if (!isset($_SESSION['patient_id'])) {
        return [];
    }
    
    $patient_id = $_SESSION['patient_id'];
    
    $sql = "SELECT * FROM lab_test_requests WHERE patient_id = ? ORDER BY request_date DESC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $requests = [];
    while ($row = $result->fetch_assoc()) {
        $requests[] = $row;
    }
    
    $stmt->close();
    return $requests;
}

function getTestInfo($conn, $test_name) {
    $sql = "SELECT category FROM available_lab_tests WHERE test_name = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $test_name);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        return $result->fetch_assoc();
    }
    return ['category' => 'basic'];
}

// Check if appointment slot is available
function isAppointmentSlotAvailable($conn, $appointment_date, $appointment_time, $doctor_name) {
    $sql = "SELECT COUNT(*) as count FROM appointments 
            WHERE appointment_date = ? 
            AND appointment_time = ? 
            AND doctor_name = ?
            AND status != 'Cancelled'";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }
    
    $stmt->bind_param("sss", $appointment_date, $appointment_time, $doctor_name);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return $row['count'] == 0;
}

// Check if appointment time is within business hours (8 AM to 5 PM)
function isValidAppointmentTime($appointment_time) {
    $time_parts = explode(':', $appointment_time);
    if (count($time_parts) < 2) {
        return false;
    }
    
    $hours = (int)$time_parts[0];
    $minutes = (int)$time_parts[1];
    
    // Convert to total minutes for easier comparison
    $total_minutes = ($hours * 60) + $minutes;
    
    // Business hours: 8:00 AM (480 minutes) to 5:00 PM (1020 minutes)
    $start_minutes = 8 * 60;   // 8:00 AM = 480 minutes
    $end_minutes = 17 * 60;    // 5:00 PM = 1020 minutes
    
    return ($total_minutes >= $start_minutes && $total_minutes <= $end_minutes);
}

// Handle form submission for new appointment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['schedule_appointment'])) {
    $patient_data = getCurrentPatientData($conn);
    if ($patient_data) {
        $patient_name = $patient_data['first_name'] . ' ' . $patient_data['last_name'];
        $appointment_date = $_POST['appointment_date'];
        $appointment_time = $_POST['appointment_time'];
        $appointment_type = $_POST['appointment_type'];
        $doctor_name = $_POST['doctor_name'];
        $notes = $_POST['notes'];
        
        // If appointment type is "Other", use the custom input
        if ($appointment_type === 'Other' && isset($_POST['other_appointment_type'])) {
            $appointment_type = $_POST['other_appointment_type'];
        }
        
        $appointment_datetime = $appointment_date . ' ' . $appointment_time;
        $current_datetime = date('Y-m-d H:i:s');
        
        if (strtotime($appointment_datetime) < strtotime($current_datetime)) {
            $error_message = "Cannot schedule appointments in the past. Please select a future date and time.";
        } else if (!isValidAppointmentTime($appointment_time)) {
            $error_message = "Appointments can only be scheduled between 8:00 AM and 5:00 PM. Please select a valid time.";
        } else if (empty($doctor_name)) {
            $error_message = "Please select a doctor for your appointment.";
        } else {
            // Check if appointment slot is available for the selected doctor
            if (!isAppointmentSlotAvailable($conn, $appointment_date, $appointment_time, $doctor_name)) {
                $error_message = "This appointment slot is already booked for the selected doctor. Please choose a different date, time, or doctor.";
            } else {
                $sql = "INSERT INTO appointments (patientname, appointment_date, appointment_time, appointment_type, doctor_name, notes, status, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, 'Scheduled', NOW())";
                
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param("ssssss", $patient_name, $appointment_date, $appointment_time, $appointment_type, $doctor_name, $notes);
                    
                    if ($stmt->execute()) {
                        $success_message = "Appointment scheduled successfully with Dr. " . htmlspecialchars($doctor_name) . "!";
                    } else {
                        $error_message = "Failed to schedule appointment. Please try again.";
                    }
                    $stmt->close();
                } else {
                    $error_message = "Database error. Please try again.";
                }
            }
        }
    } else {
        $error_message = "Patient data not found. Please log in again.";
    }
}

// Handle lab test request submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_lab_test'])) {
    $patient_data = getCurrentPatientData($conn);
    if ($patient_data) {
        $patient_id = $patient_data['id'];
        $patient_name = $patient_data['first_name'] . ' ' . $patient_data['last_name'];
        
        // Handle test type (with "Other" option)
        $test_type = $_POST['test_type'];
        if ($test_type === 'Other' && isset($_POST['other_test_type'])) {
            $test_type = $_POST['other_test_type'];
        }
        
        // Handle test category (with "other" option)
        $test_category = $_POST['test_category'];
        if ($test_category === 'other') {
            $test_category = isset($_POST['other_category_description']) ? $_POST['other_category_description'] : 'Other';
        } else {
            // Ensure it's either 'basic' or 'advanced' for the enum
            $test_category = ($test_category === 'basic' || $test_category === 'advanced') ? $test_category : 'basic';
        }
        
        $priority = $_POST['priority'];
        $reason = $_POST['reason'];
        $symptoms = $_POST['symptoms'] ?? '';
        
        // All tests require approval now (no auto-approval)
        $status = 'pending';
        
        $sql = "INSERT INTO lab_test_requests 
                (patient_id, patient_name, test_type, test_category, priority, reason, symptoms, status, request_date) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("isssssss", $patient_id, $patient_name, $test_type, $test_category, $priority, $reason, $symptoms, $status);
            
            if ($stmt->execute()) {
                $success_message = "Lab test request submitted successfully! Your request is pending doctor approval.";
            } else {
                $error_message = "Failed to submit lab test request. Error: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $error_message = "Database error. Please try again. Error: " . $conn->error;
        }
    } else {
        $error_message = "Patient data not found. Please log in again.";
    }
}

// Get patient data
$patient_data = getCurrentPatientData($conn);
if (!$patient_data) {
    header("Location: login.php");
    exit;
}

$patient_name = $patient_data['first_name'] . ' ' . $patient_data['last_name'];
$patient_id = '24-' . str_pad($patient_data['id'], 5, '0', STR_PAD_LEFT);
$upcoming_appointments = getUpcomingAppointments($conn);
$patient_documents = getPatientDocuments($conn);

// Handle prescription month filter
$prescription_month_filter = isset($_GET['prescription_month']) ? $_GET['prescription_month'] : 'all';
$patient_prescriptions = getPatientPrescriptions($conn, $prescription_month_filter);

$lab_requests = getPatientLabRequests($conn);
$doctors = getAllDoctors($conn);

// Determine active tab
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'appointments';

// Format date for display
function formatAppointmentDateTime($date, $time) {
    $datetime = DateTime::createFromFormat('Y-m-d H:i:s', $date . ' ' . $time);
    return $datetime->format('l, F j, Y \a\t g:i A');
}

// Format file size
function formatFileSize($bytes) {
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}

// Calculate days left for prescriptions
function calculateDaysLeft($duration_days, $created_at) {
    $startDate = new DateTime($created_at);
    $endDate = new DateTime($created_at);
    $endDate->modify("+$duration_days days");
    $today = new DateTime();
    
    $interval = $today->diff($endDate);
    $daysLeft = $interval->days;
    
    if ($today > $endDate) {
        return 0;
    }
    
    return $daysLeft;
}

function getDaysLeftBadge($daysLeft) {
    if ($daysLeft <= 0) return 'bg-secondary';
    if ($daysLeft <= 3) return 'bg-danger';
    if ($daysLeft <= 7) return 'bg-warning';
    return 'bg-success';
}

$current_time = date('H:i');
$current_date = date('Y-m-d');
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title>User Dashboard</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
	<link rel="stylesheet" href="user_dash.css" />
</head>
<body>

	<header class="topbar">
		<div style="display:flex;align-items:center;gap:12px">
           <img src="logo.jpg" alt="MediSync Logo" style="height: 50px; margin: auto; display: block;" />
		</div>
		<div style="display:flex;align-items:center;gap:14px">
			<div class="small-muted"><?php echo htmlspecialchars($patient_name); ?><br><small class="small-muted">Patient ID: <?php echo $patient_id; ?></small></div>
			<button class="btn btn-outline-dark btn-sm" onclick="showSignOutModal()">Sign Out</button>
		</div>
	</header>

	<main class="container-main">
		<h4 style="margin-bottom:6px">Welcome back, <?php echo htmlspecialchars($patient_name); ?>!</h4>
		<p class="small-muted" style="margin-top:0;margin-bottom:18px">Stay connected with your healthcare team and manage your health information.</p>

		<!-- Success/Error Messages -->
		<?php if (isset($success_message)): ?>
			<div class="alert alert-success alert-dismissible fade show" role="alert">
				<?php echo $success_message; ?>
				<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
			</div>
		<?php endif; ?>
		
		<?php if (isset($error_message)): ?>
			<div class="alert alert-danger alert-dismissible fade show" role="alert">
				<?php echo $error_message; ?>
				<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
			</div>
		<?php endif; ?>

		<section class="summary-cards">
			<div class="summary">
				<div class="small-muted">Next Appointment</div>
				<div style="font-weight:700;margin-top:6px">
					<?php if (!empty($upcoming_appointments)): ?>
						<?php echo date('M j', strtotime($upcoming_appointments[0]['appointment_date'])); ?><br>
						<small class="small-muted"><?php echo date('g:i A', strtotime($upcoming_appointments[0]['appointment_time'])); ?></small>
					<?php else: ?>
						None<br><small class="small-muted">Scheduled</small>
					<?php endif; ?>
				</div>
			</div>
		</section>

		<div style="display:flex;gap:12px;align-items:center;margin-bottom:14px">
			<div style="flex:1;background:#fff;border-radius:8px;padding:8px;border:1px solid #eef2f6;display:flex;gap:8px">
				<button class="tab-btn <?php echo $active_tab === 'appointments' ? 'active' : ''; ?>" onclick="showTab('appointments')">My Appointments</button>
				<button class="tab-btn <?php echo $active_tab === 'lab-result' ? 'active' : ''; ?>" onclick="showTab('lab-result')">Lab Results</button>
				<button class="tab-btn <?php echo $active_tab === 'prescriptions' ? 'active' : ''; ?>" onclick="showTab('prescriptions')">Prescriptions</button>
				<button class="tab-btn <?php echo $active_tab === 'lab-requests' ? 'active' : ''; ?>" onclick="showTab('lab-requests')">Lab Requests</button>
			</div>
		</div>

		<section id="appointments-section" class="appointment-list" style="display: <?php echo $active_tab === 'appointments' ? 'block' : 'none'; ?>;">
			<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
				<h6 style="margin-top:0;margin-bottom:0">Upcoming Appointments</h6>
				<button class="btn btn-dark" data-bs-toggle="modal" data-bs-target="#scheduleAppointmentModal">+ Schedule Appointment</button>
			</div>

			<?php
			if (!empty($upcoming_appointments)) {
				foreach ($upcoming_appointments as $appointment) {
					$badge_class = 'badge badge-primary';
					$status_text = 'Scheduled';
					
					if ($appointment['status'] == 'Pending') {
						$badge_class = 'badge badge-warning text-dark';
						$status_text = 'Pending';
					} elseif ($appointment['status'] == 'Completed') {
						$badge_class = 'badge badge-success';
						$status_text = 'Completed';
					} elseif ($appointment['status'] == 'Cancelled') {
						$badge_class = 'badge badge-secondary';
						$status_text = 'Cancelled';
					}
					
					$formatted_datetime = formatAppointmentDateTime(
						$appointment['appointment_date'], 
						$appointment['appointment_time']
					);
			?>
			
			<div class="appt-card d-flex justify-content-between align-items-start">
				<div style="flex:1">
					<div style="font-weight:700"><?php echo htmlspecialchars($appointment['appointment_type']); ?></div>
					<div class="appt-meta"><?php echo $formatted_datetime; ?></div>
					<?php if (!empty($appointment['doctor_name'])): ?>
					<div class="appt-meta">With: Dr. <?php echo htmlspecialchars($appointment['doctor_name']); ?></div>
					<?php endif; ?>
					
					<?php if (!empty($appointment['notes'])): ?>
					<div class="instr">
						Notes: <?php echo htmlspecialchars($appointment['notes']); ?>
					</div>
					<?php endif; ?>
				</div>
				
				<div style="display:flex;flex-direction:column;align-items:flex-end;gap:8px">
					<div class="<?php echo $badge_class; ?>"><?php echo $status_text; ?></div>
				</div>
			</div>
			
			<?php
				}
			} else {
				echo '<div class="alert alert-info" style="border-radius:10px">
						<i class="bi bi-info-circle"></i> You have no upcoming appointments scheduled.
					  </div>';
			}
			?>
		</section>

		<section id="lab-result-section" class="appointment-list" style="display: <?php echo $active_tab === 'lab-result' ? 'block' : 'none'; ?>;">
			<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
				<h5 style="margin:0">Your Lab Result</h5>
				<button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadDocumentModal">
					<i class="bi bi-upload"></i> Upload Document
				</button>
			</div>
			
			<div class="medical-record">
				<h6 class="mb-3">Uploaded Documents</h6>
				
				<?php if (!empty($patient_documents)): ?>
					<?php foreach ($patient_documents as $doc): ?>
					<div class="record-card">
						<div class="d-flex justify-content-between align-items-start mb-2">
							<div style="flex:1">
								<div class="d-flex align-items-center gap-2 mb-2">
									<i class="bi bi-file-earmark-text" style="font-size:24px;color:#0d6efd"></i>
									<div>
										<div class="record-title"><?php echo htmlspecialchars($doc['document_title']); ?></div>
										<div class="record-date">
											<?php echo date('F j, Y g:i A', strtotime($doc['upload_date'])); ?> • 
											<?php echo htmlspecialchars($doc['document_type']); ?> • 
											<?php echo formatFileSize($doc['file_size']); ?>
										</div>
									</div>
								</div>
								
								<?php if (!empty($doc['notes'])): ?>
								<div class="record-description"><?php echo htmlspecialchars($doc['notes']); ?></div>
								<?php endif; ?>
							</div>
							<div>
								<a href="<?php echo htmlspecialchars($doc['file_path']); ?>" 
								   target="_blank" 
								   class="btn btn-outline-primary btn-sm">
									<i class="bi bi-download"></i> Download
								</a>
							</div>
						</div>
					</div>
					<?php endforeach; ?>
				<?php else: ?>
					<div class="alert alert-info">
						<i class="bi bi-info-circle"></i> No documents uploaded yet. Click "Upload Document" to add your medical records.
					</div>
				<?php endif; ?>
			</div>
		</section>

		<!-- Your Prescription Section -->
		<section id="prescriptions-section" class="appointment-list" style="display: <?php echo $active_tab === 'prescriptions' ? 'block' : 'none'; ?>;">
			<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
				<h5 style="margin:0">Your Prescriptions</h5>
			</div>
			
			<!-- Month Filter for Prescriptions -->
			<div class="mb-3">
				<label class="form-label"><strong>Filter by Month:</strong></label>
				<div class="d-flex flex-wrap gap-2">
					<select class="form-select" style="width: auto;" id="prescriptionMonthFilter" onchange="filterPrescriptionsByMonth()">
						<option value="all" <?php echo $prescription_month_filter === 'all' ? 'selected' : ''; ?>>All Months</option>
						<option value="January" <?php echo $prescription_month_filter === 'January' ? 'selected' : ''; ?>>January</option>
						<option value="February" <?php echo $prescription_month_filter === 'February' ? 'selected' : ''; ?>>February</option>
						<option value="March" <?php echo $prescription_month_filter === 'March' ? 'selected' : ''; ?>>March</option>
						<option value="April" <?php echo $prescription_month_filter === 'April' ? 'selected' : ''; ?>>April</option>
						<option value="May" <?php echo $prescription_month_filter === 'May' ? 'selected' : ''; ?>>May</option>
						<option value="June" <?php echo $prescription_month_filter === 'June' ? 'selected' : ''; ?>>June</option>
						<option value="July" <?php echo $prescription_month_filter === 'July' ? 'selected' : ''; ?>>July</option>
						<option value="August" <?php echo $prescription_month_filter === 'August' ? 'selected' : ''; ?>>August</option>
						<option value="September" <?php echo $prescription_month_filter === 'September' ? 'selected' : ''; ?>>September</option>
						<option value="October" <?php echo $prescription_month_filter === 'October' ? 'selected' : ''; ?>>October</option>
						<option value="November" <?php echo $prescription_month_filter === 'November' ? 'selected' : ''; ?>>November</option>
						<option value="December" <?php echo $prescription_month_filter === 'December' ? 'selected' : ''; ?>>December</option>
					</select>
					<?php if ($prescription_month_filter !== 'all'): ?>
						<button class="btn btn-outline-secondary btn-sm" onclick="clearPrescriptionFilter()">
							<i class="bi bi-x-circle"></i> Clear Filter
						</button>
					<?php endif; ?>
				</div>
			</div>
			
			<div class="medical-record">
				<h6 class="mb-3">Current and Past Prescriptions</h6>
				
				<?php if (!empty($patient_prescriptions)): ?>
					<?php foreach ($patient_prescriptions as $prescription): ?>
						<?php
						$prescribedDate = date('F j, Y', strtotime($prescription['created_at']));
						$endDate = date('F j, Y', strtotime($prescription['created_at'] . " + {$prescription['duration_days']} days"));
						$daysLeft = calculateDaysLeft($prescription['duration_days'], $prescription['created_at']);
						$badgeClass = getDaysLeftBadge($daysLeft);
						?>
						<div class="prescription-card" style="background: #f8f9fa; border-radius: 8px; padding: 16px; margin-bottom: 12px; border-left: 4px solid #0d6efd;">
							<div class="d-flex justify-content-between align-items-start mb-2">
								<h5 style="margin: 0; color: #333;"><?php echo htmlspecialchars($prescription['medicine_name']); ?></h5>
								<span class="badge bg-success">Active</span>
							</div>
							<div class="prescription-details">
								<div class="row">
									<div class="col-md-6">
										<strong>Dosage:</strong> <?php echo htmlspecialchars($prescription['dosage']); ?>
									</div>
									<div class="col-md-6">
										<strong>Duration:</strong> <?php echo htmlspecialchars($prescription['duration_days']); ?> days
									</div>
								</div>
								<div class="row mt-2">
									<div class="col-md-6">
										<strong>Frequency:</strong> <?php echo htmlspecialchars($prescription['intake_frequency']); ?>
									</div>
									<div class="col-md-6">
										<strong>Prescribed:</strong> <?php echo $prescribedDate; ?>
									</div>
								</div>
								<div class="row mt-2">
									<div class="col-md-6">
										<strong>Treatment Until:</strong> <?php echo $endDate; ?>
									</div>
									<div class="col-md-6">
										<strong>Days Left:</strong> 
										<span class="badge <?php echo $badgeClass; ?>">
											<?php echo $daysLeft > 0 ? $daysLeft . ' days' : 'Completed'; ?>
										</span>
									</div>
								</div>
								<?php if (!empty($prescription['notes'])): ?>
								<div class="row mt-2">
									<div class="col-12">
										<strong>Notes:</strong> <?php echo htmlspecialchars($prescription['notes']); ?>
									</div>
								</div>
								<?php endif; ?>
								<?php if (!empty($prescription['first_name'])): ?>
								<div class="row mt-2">
									<div class="col-12">
										<small class="text-muted">Prescribed by: Dr. <?php echo htmlspecialchars($prescription['first_name'] . ' ' . $prescription['last_name']); ?></small>
									</div>
								</div>
								<?php endif; ?>
							</div>
						</div>
					<?php endforeach; ?>
				<?php else: ?>
					<div class="alert alert-info">
						<i class="bi bi-capsule"></i> 
						<?php if ($prescription_month_filter !== 'all'): ?>
							No prescriptions found for <?php echo $prescription_month_filter; ?>. 
						<?php else: ?>
							No prescriptions found. Your doctor will add prescriptions here after your appointments.
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>
		</section>

		<!-- Lab Requests Section -->
		<section id="lab-requests-section" class="appointment-list" style="display: <?php echo $active_tab === 'lab-requests' ? 'block' : 'none'; ?>;">
			<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
				<h5 style="margin:0">Your Lab Test Requests</h5>
				<button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#requestLabTestModal">
					<i class="bi bi-plus-circle"></i> Request Lab Test
				</button>
			</div>
			
			<div class="medical-record">
				<h6 class="mb-3">Lab Test Requests History</h6>
				
				<?php if (!empty($lab_requests)): ?>
					<?php foreach ($lab_requests as $request): ?>
					<div class="record-card">
						<div class="d-flex justify-content-between align-items-start mb-2">
							<div style="flex:1">
								<div class="d-flex align-items-center gap-2 mb-2">
									<i class="bi bi-flask" style="font-size:24px;color:#0d6efd"></i>
									<div>
										<div class="record-title"><?php echo htmlspecialchars($request['test_type']); ?></div>
										<div class="record-date">
											Requested: <?php echo date('F j, Y g:i A', strtotime($request['request_date'])); ?> • 
											Category: <?php echo htmlspecialchars($request['test_category']); ?> • 
											Priority: <?php echo htmlspecialchars($request['priority']); ?>
										</div>
									</div>
								</div>
								
								<?php if (!empty($request['reason'])): ?>
								<div class="record-description">
									<strong>Reason:</strong> <?php echo htmlspecialchars($request['reason']); ?>
								</div>
								<?php endif; ?>
								
								<?php if (!empty($request['symptoms'])): ?>
								<div class="record-description">
									<strong>Symptoms:</strong> <?php echo htmlspecialchars($request['symptoms']); ?>
								</div>
								<?php endif; ?>
								
								<?php if (!empty($request['doctor_notes'])): ?>
								<div class="record-description">
									<strong>Doctor's Notes:</strong> <?php echo htmlspecialchars($request['doctor_notes']); ?>
								</div>
								<?php endif; ?>
								
								<?php if (!empty($request['denial_reason'])): ?>
								<div class="record-description text-danger">
									<strong>Denial Reason:</strong> <?php echo htmlspecialchars($request['denial_reason']); ?>
								</div>
								<?php endif; ?>
								
								<div class="mt-2">
									<?php
									$status_badge = '';
									switch($request['status']) {
										case 'approved':
											$status_badge = '<span class="badge bg-success">Approved</span>';
											break;
										case 'denied':
											$status_badge = '<span class="badge bg-danger">Denied</span>';
											break;
										default:
											$status_badge = '<span class="badge bg-warning">Pending Review</span>';
									}
									echo $status_badge;
									
									if ($request['status'] == 'approved' && !empty($request['permit_file_path'])) {
										echo ' <a href="' . htmlspecialchars($request['permit_file_path']) . '" target="_blank" class="btn btn-success btn-sm ms-2"><i class="bi bi-download"></i> Download Permit</a>';
									}
									?>
								</div>
							</div>
						</div>
					</div>
					<?php endforeach; ?>
				<?php else: ?>
					<div class="alert alert-info">
						<i class="bi bi-info-circle"></i> No lab test requests yet. Click "Request Lab Test" to submit your first request.
					</div>
					<?php endif; ?>
			</div>
		</section>
	</main>

	<!-- Upload Document Modal -->
	<div class="modal fade" id="uploadDocumentModal" tabindex="-1" aria-labelledby="uploadDocumentModalLabel" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered">
			<div class="modal-content">
				<form method="POST" action="upload_document.php" enctype="multipart/form-data" id="uploadForm">
					<div class="modal-header">
						<h5 class="modal-title" id="uploadDocumentModalLabel">Upload Medical Document</h5>
						<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
					</div>
					<div class="modal-body">
						<!-- Document Type -->
						<div class="mb-3">
							<label class="form-label">Document Type</label>
							<select class="form-select" name="document_type" required>
								<option value="" selected disabled>Select document type</option>
								<option value="Lab Results">Lab Results</option>
								<option value="X-Ray">X-Ray</option>
								<option value="Prescription">Prescription</option>
								<option value="Ultrasound">Ultrasound</option>
								<option value="CT Scan">CT Scan</option>
								<option value="MRI">MRI</option>
								<option value="Blood Test">Blood Test</option>
							</select>
						</div>
						
						<!-- Document Title -->
						<div class="mb-3">
							<label class="form-label">Document Title</label>
							<input type="text" class="form-control" name="document_title" 
								   placeholder="e.g., Blood Test Results - May 2024" required>
						</div>
						
						<!-- File Upload -->
						<div class="mb-3">
							<label class="form-label">Select File</label>
							<input type="file" class="form-control" name="document_file" 
								   accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" required>
							<div class="form-text">Accepted formats: PDF, JPG, PNG, DOC, DOCX (Max: 5MB)</div>
						</div>
						
						<!-- Notes -->
						<div class="mb-3">
							<label class="form-label">Notes (Optional)</label>
							<textarea class="form-control" name="notes" rows="3" 
									  placeholder="Add any additional notes about this document"></textarea>
						</div>
					</div>
					<div class="modal-footer">
						<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
						<button type="submit" name="upload_document" class="btn btn-primary">
							<i class="bi bi-upload"></i> Upload Document
						</button>
					</div>
				</form>
			</div>
		</div>
	</div>

	<!-- Schedule Appointment Modal -->
	<div class="modal fade" id="scheduleAppointmentModal" tabindex="-1" aria-labelledby="scheduleAppointmentModalLabel" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered">
			<div class="modal-content">
				<form method="POST" action="" id="appointmentForm">
					<div class="modal-header">
						<h5 class="modal-title" id="scheduleAppointmentModalLabel">Schedule Appointment</h5>
						<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
					</div>
					<div class="modal-body">
						<div class="text-center mb-3 small text-muted">
							Current Time: <strong id="currentTimeDisplay"><?php echo date('g:i A'); ?></strong>
						</div>
						
						<h6>Appointment Details</h6>
						
						<div class="mb-3">
							<label class="form-label">Patient Name</label>
							<input type="text" class="form-control" value="<?php echo htmlspecialchars($patient_name); ?>" readonly>
							<input type="hidden" name="patient_name" value="<?php echo htmlspecialchars($patient_name); ?>">
						</div>
						
						<div class="mb-3">
							<label class="form-label">Select Doctor</label>
							<select class="form-select" name="doctor_name" id="doctor_name" required>
								<option value="" selected disabled>Select a doctor</option>
								<?php if (!empty($doctors)): ?>
									<?php foreach ($doctors as $doctor): ?>
										<option value="<?php echo htmlspecialchars($doctor['first_name'] . ' ' . $doctor['last_name']); ?>">
											Dr. <?php echo htmlspecialchars($doctor['first_name'] . ' ' . $doctor['last_name']); ?> - <?php echo htmlspecialchars($doctor['department']); ?>
										</option>
									<?php endforeach; ?>
								<?php else: ?>
									<option value="" disabled>No doctors available</option>
								<?php endif; ?>
							</select>
							<div class="form-text">Choose the doctor you want to schedule an appointment with</div>
						</div>
						
						<div class="mb-3">
							<label class="form-label">Appointment Date</label>
							<input type="date" class="form-control" name="appointment_date" id="appointment_date" value="<?php echo date('Y-m-d'); ?>" required onchange="updateTimeRestrictions()">
							<div class="form-text">Select a future date for your appointment</div>
						</div>
						
						<div class="mb-3">
							<label class="form-label">Appointment Time</label>
							<input type="time" class="form-control" name="appointment_time" id="appointment_time" min="08:00" max="17:00" step="1800" required>
							<div class="time-help" id="timeHelpText">
								Select a time during clinic hours (8:00 AM - 5:00 PM)
							</div>
						</div>
						
						<div class="mb-3">
							<label class="form-label">Appointment Type</label>
							<select class="form-select" name="appointment_type" id="appointment_type" required onchange="toggleOtherAppointmentType()">
								<option value="" selected disabled>Select type</option>
								<option value="Consultation">Consultation</option>
								<option value="Follow-up Checkup">Follow-up Checkup</option>
								<option value="Other">Other</option>
							</select>
						</div>
						
						<!-- Other Appointment Type Input (Hidden by default) -->
						<div class="mb-3" id="otherAppointmentTypeContainer" style="display: none;">
							<label class="form-label">Specify Appointment Type</label>
							<input type="text" class="form-control" name="other_appointment_type" id="other_appointment_type" placeholder="Enter your appointment type">
							<div class="form-text">Please specify the type of appointment you need</div>
						</div>
						
						<div class="mb-3">
							<label class="form-label">Notes</label>
							<textarea class="form-control" name="notes" rows="3" placeholder="Add any special notes or instructions"></textarea>
						</div>
					</div>
					<div class="modal-footer">
						<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
						<button type="submit" name="schedule_appointment" class="btn btn-primary" id="scheduleButton">Schedule Appointment</button>
					</div>
				</form>
			</div>
		</div>
	</div>

	<!-- Request Lab Test Modal -->
<div class="modal fade" id="requestLabTestModal" tabindex="-1" aria-labelledby="requestLabTestModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="" id="labRequestForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="requestLabTestModalLabel">Request Lab Test</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <h6>Lab Test Details</h6>
                    
                    <div class="mb-3">
                        <label class="form-label">Patient Name</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($patient_name); ?>" readonly>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Test Type</label>
                        <select class="form-select" name="test_type" id="test_type" required onchange="updateTestCategory()">
                            <option value="" selected disabled>Select test type</option>
                            <option value="CBC" data-category="basic">CBC (Complete Blood Count)</option>
                            <option value="Urinalysis" data-category="basic">Urinalysis</option>
                            <option value="Fecalysis" data-category="basic">Fecalysis</option>
                            <option value="Blood Sugar" data-category="basic">Blood Sugar</option>
                            <option value="Lipid Profile" data-category="advanced">Lipid Profile</option>
                            <option value="Pregnancy Test" data-category="basic">Pregnancy Test</option>
                            <option value="HIV Test" data-category="advanced">HIV Test</option>
                            <option value="Other">Other (Specify below)</option>
                        </select>
                    </div>
                    
                    <!-- Other Test Type Input (Hidden by default) -->
                    <div class="mb-3" id="otherTestTypeContainer" style="display: none;">
                        <label class="form-label">Specify Test Type</label>
                        <input type="text" class="form-control" name="other_test_type" id="other_test_type" placeholder="Enter the specific test name">
                        <div class="form-text">Please specify the exact test you need</div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Test Category</label>
                        <select class="form-select" name="test_category" id="test_category" required onchange="updateCategoryHelpText()">
                            <option value="" selected disabled>Select category</option>
                            <option value="basic">Basic Test</option>
                            <option value="advanced">Advanced Test</option>
                            <option value="other">Other Category</option>
                        </select>
                        <div class="form-text" id="categoryHelpText">
                            Select whether this is a basic or advanced test
                        </div>
                    </div>
                    
                    <!-- Other Category Description Input (Hidden by default) -->
                    <div class="mb-3" id="otherCategoryContainer" style="display: none;">
                        <label class="form-label">Describe Test Category</label>
                        <input type="text" class="form-control" name="other_category_description" id="other_category_description" placeholder="Describe the test category">
                        <div class="form-text">Provide details about this test category</div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Priority</label>
                        <select class="form-select" name="priority" required>
                            <option value="routine">Routine</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Reason for Request</label>
                        <textarea class="form-control" name="reason" rows="3" placeholder="Explain why you need this test..." required></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Symptoms (Optional)</label>
                        <textarea class="form-control" name="symptoms" rows="2" placeholder="Describe any symptoms you're experiencing..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="request_lab_test" class="btn btn-primary">Submit Request</button>
                </div>
            </form>
        </div>
    </div>
</div>

	<!-- Sign Out Confirmation Modal -->
	<div class="modal fade" id="signOutModal" tabindex="-1" aria-labelledby="signOutModalLabel" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered">
			<div class="modal-content">
				<div class="modal-header">
					<h5 class="modal-title" id="signOutModalLabel">Confirm Sign Out</h5>
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
				</div>
				<div class="modal-body">Are you sure you want to sign out?</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">No</button>
					<button type="button" class="btn btn-danger" id="confirmSignOutBtn">Yes, sign out</button>
				</div>
			</div>
		</div>
	</div>

	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
	<script>
		// Set active tab on page load based on PHP variable
		let activeTab = '<?php echo $active_tab; ?>';
		
		function showTab(tabName) {
			document.getElementById('appointments-section').style.display = 'none';
			document.getElementById('lab-result-section').style.display = 'none';
			document.getElementById('prescriptions-section').style.display = 'none';
			document.getElementById('lab-requests-section').style.display = 'none';
			
			document.getElementById(tabName + '-section').style.display = 'block';
			
			const tabs = document.querySelectorAll('.tab-btn');
			tabs.forEach(tab => {
				if (tab.textContent.toLowerCase().includes(tabName.replace('-', ' '))) {
					tab.classList.add('active');
				} else {
					tab.classList.remove('active');
				}
			});
			
			// Update the active tab variable
			activeTab = tabName;
		}

		// Initialize the correct tab on page load
		document.addEventListener('DOMContentLoaded', function() {
			showTab(activeTab);
		});

		function signOut() {
    		window.location.href = 'user_dash.php?signout=true';
		}

		function showSignOutModal() {
			try {
				const modalEl = document.getElementById('signOutModal');
				if (!modalEl) {
					if (confirm('Are you sure you want to sign out?')) signOut();
					return;
				}
				if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
					const bsModal = new bootstrap.Modal(modalEl);
					bsModal.show();
					document.getElementById('confirmSignOutBtn').onclick = function() {
						bsModal.hide();
						signOut();
					};
				} else {
					if (confirm('Are you sure you want to sign out?')) signOut();
				}
			} catch (err) {
				console.error('Sign out modal error', err);
				if (confirm('Are you sure you want to sign out?')) signOut();
			}
		}

		function toggleOtherAppointmentType() {
			const appointmentType = document.getElementById('appointment_type');
			const otherContainer = document.getElementById('otherAppointmentTypeContainer');
			const otherInput = document.getElementById('other_appointment_type');
			
			if (appointmentType.value === 'Other') {
				otherContainer.style.display = 'block';
				otherInput.required = true;
			} else {
				otherContainer.style.display = 'none';
				otherInput.required = false;
				otherInput.value = '';
			}
		}

		// Function to validate time selection
		function validateAppointmentTime() {
			const timeInput = document.getElementById('appointment_time');
			const timeHelp = document.getElementById('timeHelpText');
			const scheduleButton = document.getElementById('scheduleButton');
			
			if (timeInput.value) {
				const selectedTime = timeInput.value;
				const hours = parseInt(selectedTime.split(':')[0]);
				const minutes = parseInt(selectedTime.split(':')[1]);
				
				// Convert to total minutes for easier comparison
				const totalMinutes = hours * 60 + minutes;
				const startMinutes = 8 * 60; // 8:00 AM
				const endMinutes = 17 * 60; // 5:00 PM
				
				if (totalMinutes < startMinutes || totalMinutes > endMinutes) {
					timeHelp.textContent = '❌ Appointments can only be scheduled between 8:00 AM and 5:00 PM';
					timeHelp.className = 'time-help warning';
					scheduleButton.disabled = true;
					return false;
				} else {
					timeHelp.textContent = '✅ Valid time slot (8:00 AM - 5:00 PM)';
					timeHelp.className = 'time-help';
					scheduleButton.disabled = false;
					return true;
				}
			}
			return true;
		}

		// Update time restrictions based on selected date
		function updateTimeRestrictions() {
			const dateInput = document.getElementById('appointment_date');
			const timeInput = document.getElementById('appointment_time');
			const today = new Date().toISOString().split('T')[0];
			const now = new Date();
			const currentHour = now.getHours();
			const currentMinute = now.getMinutes();
			
			if (dateInput.value === today) {
				// If today is selected, set minimum time to current time + 1 hour
				const minHour = currentHour + 1;
				const minTime = `${minHour.toString().padStart(2, '0')}:${currentMinute.toString().padStart(2, '0')}`;
				timeInput.min = minTime;
			} else {
				// For future dates, allow full business hours
				timeInput.min = '08:00';
			}
			
			// Always set max to 5:00 PM
			timeInput.max = '17:00';
			
			validateAppointmentTime();
		}

		// Add event listeners for time validation
		document.addEventListener('DOMContentLoaded', function() {
			const today = new Date().toISOString().split('T')[0];
			const dateInput = document.getElementById('appointment_date');
			const timeInput = document.getElementById('appointment_time');
			
			if (dateInput) {
				dateInput.min = today;
			}
			
			if (timeInput) {
				timeInput.addEventListener('change', validateAppointmentTime);
				timeInput.addEventListener('input', validateAppointmentTime);
			}
			
			const scheduleModal = document.getElementById('scheduleAppointmentModal');
			if (scheduleModal) {
				scheduleModal.addEventListener('show.bs.modal', function() {
					updateTimeRestrictions();
					
					const now = new Date();
					document.getElementById('currentTimeDisplay').textContent = 
						now.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
				});
			}
			
			setInterval(function() {
				const currentTimeDisplay = document.getElementById('currentTimeDisplay');
				if (currentTimeDisplay) {
					const now = new Date();
					currentTimeDisplay.textContent = 
						now.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
				}
			}, 60000);
		});

		// Add form validation for appointment time
		document.getElementById('appointmentForm').addEventListener('submit', function(e) {
			if (!validateAppointmentTime()) {
				e.preventDefault();
				alert('Please select a valid appointment time between 8:00 AM and 5:00 PM.');
				document.getElementById('appointment_time').focus();
				return false;
			}
		});

		// Prescription Filter Functions
		function filterPrescriptionsByMonth() {
			const monthFilter = document.getElementById('prescriptionMonthFilter').value;
			// Reload the page with the month filter parameter and active tab
			window.location.href = 'user_dash.php?prescription_month=' + monthFilter + '&tab=prescriptions';
		}

		function clearPrescriptionFilter() {
			window.location.href = 'user_dash.php?tab=prescriptions';
		}

		// Other functions remain the same
		function updateTestCategory() {
			const testType = document.getElementById('test_type');
			const testCategory = document.getElementById('test_category');
			const otherTestTypeContainer = document.getElementById('otherTestTypeContainer');
			const otherTestTypeInput = document.getElementById('other_test_type');
			
			// Handle "Other" test type
			if (testType.value === 'Other') {
				otherTestTypeContainer.style.display = 'block';
				otherTestTypeInput.required = true;
				// Reset category selection when choosing "Other"
				testCategory.value = '';
				updateCategoryHelpText();
			} else {
				otherTestTypeContainer.style.display = 'none';
				otherTestTypeInput.required = false;
				otherTestTypeInput.value = '';
				
				// Auto-set category based on selected test
				if (testType.selectedIndex > 0) {
					const selectedOption = testType.options[testType.selectedIndex];
					const category = selectedOption.getAttribute('data-category');
					if (category) {
						testCategory.value = category;
						updateCategoryHelpText();
					}
				}
			}
		}

		function updateCategoryHelpText() {
			const testCategory = document.getElementById('test_category');
			const otherCategoryContainer = document.getElementById('otherCategoryContainer');
			const otherCategoryInput = document.getElementById('other_category_description');
			const helpText = document.getElementById('categoryHelpText');
			
			// Handle "other" category
			if (testCategory.value === 'other') {
				otherCategoryContainer.style.display = 'block';
				otherCategoryInput.required = true;
				helpText.textContent = 'Please describe this test category for the doctor';
				helpText.className = 'form-text text-info';
			} else {
				otherCategoryContainer.style.display = 'none';
				otherCategoryInput.required = false;
				otherCategoryInput.value = '';
				
				// Update help text based on category
				if (testCategory.value === 'basic') {
					helpText.textContent = 'Basic tests like CBC, Urinalysis, etc. - typically routine screening tests';
					helpText.className = 'form-text text-info';
				} else if (testCategory.value === 'advanced') {
					helpText.textContent = 'Advanced tests like CT Scan, MRI, etc. - typically require detailed medical review';
					helpText.className = 'form-text text-warning';
				} else {
					helpText.textContent = 'Select whether this is a basic or advanced test';
					helpText.className = 'form-text';
				}
			}
		}

		// Add form validation for the lab request form
		document.getElementById('labRequestForm').addEventListener('submit', function(e) {
			const testType = document.getElementById('test_type');
			const otherTestType = document.getElementById('other_test_type');
			const testCategory = document.getElementById('test_category');
			const otherCategory = document.getElementById('other_category_description');
			
			// Validate "Other" test type
			if (testType.value === 'Other' && (!otherTestType.value || otherTestType.value.trim() === '')) {
				e.preventDefault();
				alert('Please specify the test type when selecting "Other".');
				otherTestType.focus();
				return false;
			}
			
			// Validate "other" category
			if (testCategory.value === 'other' && (!otherCategory.value || otherCategory.value.trim() === '')) {
				e.preventDefault();
				alert('Please describe the test category when selecting "Other Category".');
				otherCategory.focus();
				return false;
			}
			
			// Validate that a test type is selected
			if (!testType.value) {
				e.preventDefault();
				alert('Please select or specify a test type.');
				testType.focus();
				return false;
			}
			
			// Validate that a category is selected
			if (!testCategory.value) {
				e.preventDefault();
				alert('Please select a test category.');
				testCategory.focus();
				return false;
			}
		});
	</script>
</body>
</html>
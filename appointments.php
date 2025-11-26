<?php
session_start();

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
$user_name = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Guest User';
$user_role = isset($_SESSION['user_role']) ? $_SESSION['user_role'] : 'Nurse';
$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 1;

// Handle appointment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['schedule_appointment'])) {
    try {
        $stmt = $pdo->prepare("INSERT INTO appointments (patientname, appointment_date, appointment_time, appointment_type, notes, status, created_at) VALUES (?, ?, ?, ?, ?, 'Scheduled', NOW())");
        
        $stmt->execute([
            $_POST['patient_name'],
            $_POST['appointment_date'],
            $_POST['appointment_time'],
            $_POST['appointment_type'],
            $_POST['notes']
        ]);
        
        $success_message = "Appointment scheduled successfully!";
        
    } catch(PDOException $e) {
        $error_message = "Error: " . $e->getMessage();
    }
}

// Handle reschedule appointment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reschedule_appointment'])) {
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_appointment'])) {
    try {
        $stmt = $pdo->prepare("UPDATE appointments SET status = 'Cancelled', notes = CONCAT(IFNULL(notes, ''), ' Cancelled: ', NOW()) WHERE id = ?");
        $stmt->execute([$_POST['appointment_id']]);
        $success_message = "Appointment cancelled successfully!";
    } catch(PDOException $e) {
        $error_message = "Error cancelling appointment: " . $e->getMessage();
    }
}

// Get current date or selected date
$current_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// Fetch appointments for the selected date
$stmt = $pdo->prepare("SELECT * FROM appointments WHERE appointment_date = ? ORDER BY appointment_time ASC");
$stmt->execute([$current_date]);
$appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Create time slots array
$time_slots = [];
for ($hour = 8; $hour <= 17; $hour++) {
    $time_slots[] = sprintf('%02d:00:00', $hour); // Use full time format with seconds
    if ($hour < 17) {
        $time_slots[] = sprintf('%02d:30:00', $hour);
    }
}

// Also create display versions without seconds
$time_slots_display = [];
foreach ($time_slots as $slot) {
    $time_slots_display[$slot] = date('h:i A', strtotime($slot));
}
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title>Appointments - Scheduling</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="appointments.css"/>
</head>
<body>
<div class="dashboard-root">
	<aside class="sidebar">
			<div>
				<div class="brand">
					<img src="logo.jpg" alt="MediSync Logo" style="height: 50px; margin: auto; display: block;" />
				</div><br>
				
			</div>
			<ul class="nav-list">
				<li><a href="nurse_dash.php"><i class="bi bi-grid"></i> Dashboard</a></li>
				<li><a href="patients.php"><i class="bi bi-people"></i> Patients</span></a></li>
				<li class="active"><a href="appointments.php"><i class="bi bi-calendar-event"></i> Appointments</span></a></li>
			</ul>
			
		</aside>

		<!-- Main content area -->
		<main class="main">
			<header class="topbar">
				<div style="display:flex;align-items:center;gap:12px">
				</div>
				<div style="display:flex;align-items:center;gap:14px">
					<div class="small-muted"><?php echo htmlspecialchars($user_name); ?><br><small class="small-muted">Role: <?php echo htmlspecialchars($user_role); ?></small></div>
					<button class="btn btn-outline-dark btn-sm" onclick="window.location.href='login.php'">Sign Out</button>
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

			<header class="main-header">
				<div style="display: flex; justify-content: space-between; align-items: flex-start;">
					<div>
						<h1>Appointment Scheduling</h1>
						<p class="subtitle">Schedule and manage appointments</p>
					</div>
				</div>
				<div class="controls">
					<div class="date-nav">
						<button class="chev" onclick="changeDate(-1)">&lt;</button>
						<span class="date" id="current-date"><?php echo date('l, F d, Y', strtotime($current_date)); ?></span>
						<button class="chev" onclick="changeDate(1)">&gt;</button>
					</div>
					
				</div>
			</header>

			<section class="timeline">
				<!-- hours column and rows -->
				<div class="time-column">
					<?php foreach ($time_slots as $time): ?>
						<div class="hour"><?php echo date('h:i A', strtotime($time)); ?></div>
					<?php endforeach; ?>
				</div>

				<div class="slots-column">
					<?php 
					foreach ($time_slots as $time):
						$has_appointment = false;
						$appointment_data = null;
						
						// Check if there's an appointment at this time - use TIME() function to compare times properly
						foreach ($appointments as $apt) {
							// Compare times by converting both to the same format
							$apt_time = date('H:i:s', strtotime($apt['appointment_time']));
							$slot_time = date('H:i:s', strtotime($time));
							
							if ($apt_time === $slot_time) {
								$has_appointment = true;
								$appointment_data = $apt;
								break;
							}
						}
						
						if ($has_appointment):
					?>
						<div class="slot appt" data-time="<?php echo $time; ?>">
							<div class="appt-card d-flex justify-content-between align-items-start">
								<div style="flex: 1;">
									<div class="appt-title"><?php echo htmlspecialchars($appointment_data['patientname']); ?></div>
									<div class="appt-desc">
										<strong>Type:</strong> <?php echo htmlspecialchars($appointment_data['appointment_type']); ?>
                                        
									</div>
									<?php if (!empty($appointment_data['notes'])): ?>
										<div class="appt-notes"><?php echo htmlspecialchars($appointment_data['notes']); ?></div>
									<?php endif; ?>
								</div>
								<div style="display: flex; flex-direction: column; align-items: flex-end; gap: 8px;">
									<div class="appt-status"><?php echo htmlspecialchars($appointment_data['status']); ?></div>
									<!-- Added Reschedule and Cancel buttons -->
									<?php if ($appointment_data['status'] != 'Cancelled' && $appointment_data['status'] != 'Completed'): ?>
									<div class="appt-actions d-flex gap-2">
										<button class="btn btn-outline-primary btn-sm" 
												onclick="showRescheduleModal(<?php echo $appointment_data['id']; ?>, '<?php echo htmlspecialchars($appointment_data['appointment_type']); ?>', '<?php echo $appointment_data['appointment_date']; ?>', '<?php echo $appointment_data['appointment_time']; ?>')">
											Reschedule
										</button>
										<button class="btn btn-outline-danger btn-sm" 
												onclick="showCancelModal(<?php echo $appointment_data['id']; ?>, '<?php echo htmlspecialchars($appointment_data['appointment_type']); ?>')">
											Cancel
										</button>
									</div>
									<?php endif; ?>
								</div>
							</div>
						</div>
					<?php else: ?>
						<div class="slot empty" data-time="<?php echo $time; ?>">Available</div>
					<?php endif; ?>
					<?php endforeach; ?>
				</div>
			</section>
		</main>

	</div>

<!-- Appointment Scheduling Modal -->
<div id="schedule-modal" class="modal-overlay" aria-hidden="true">
    <div class="modal-card">
        <div class="modal-header d-flex align-items-center justify-content-between">
            <div>
                <h5 style="margin:0">Schedule Appointment</h5>
                <div class="small-muted">Appointment Details</div>
            </div>
            <button id="close-schedule" class="btn btn-light btn-sm"><i class="bi bi-x-lg"></i></button>
        </div>
        <form method="POST" action="appointments.php" class="modal-form">
            <input type="hidden" name="schedule_appointment" value="1">
            <div class="form-grid">
                <div class="form-group">
                    <label>Patient Name</label>
                    <input type="text" name="patient_name" placeholder="Enter patient name" required />
                </div>
                <div class="form-group">
                    <label>Appointment Date</label>
                    <input type="date" name="appointment_date" value="<?php echo $current_date; ?>" required />
                </div>
                <div class="form-group">
                    <label>Appointment Time</label>
                    <select name="appointment_time" class="form-select" required>
                        <option value="">Select time</option>
                        <?php foreach ($time_slots as $time): ?>
                            <option value="<?php echo $time; ?>"><?php echo date('h:i A', strtotime($time)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group full">
                    <label>Appointment Type</label>
                    <select class="form-select" name="appointment_type" required>
                        <option value="" selected disabled>Select type</option>
                        <option value="Regular Checkup">Regular Checkup</option>
                        <option value="Consultation">Consultation</option>
                        <option value="Follow-up Checkup">Follow-up Checkup</option>
                        <option value="Emergency">Emergency</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="form-group full">
                    <label>Notes</label>
                    <textarea name="notes" placeholder="Add any special notes or instructions"></textarea>
                </div>
            </div>
            <div class="mt-3 d-flex justify-content-end gap-2">
                <button type="button" id="cancel-schedule" class="btn btn-outline-secondary">Cancel</button>
                <button type="submit" class="btn btn-dark">Schedule Appointment</button>
            </div>
        </form>
    </div>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const currentDate = new Date('<?php echo $current_date; ?>');

function changeDate(days) {
    currentDate.setDate(currentDate.getDate() + days);
    const year = currentDate.getFullYear();
    const month = String(currentDate.getMonth() + 1).padStart(2, '0');
    const day = String(currentDate.getDate()).padStart(2, '0');
    const dateStr = `${year}-${month}-${day}`;
    window.location.href = `appointments.php?date=${dateStr}`;
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

document.addEventListener('DOMContentLoaded', function(){
    const overlay = document.getElementById('schedule-modal');
    const closeBtn = document.getElementById('close-schedule');
    const cancelBtn = document.getElementById('cancel-schedule');

    function openModal() {
        overlay.classList.add('open');
        overlay.setAttribute('aria-hidden','false');
    }

    function closeModal() {
        overlay.classList.remove('open');
        overlay.setAttribute('aria-hidden','true');
    }

    if (closeBtn) {
        closeBtn.addEventListener('click', closeModal);
    }

    if (cancelBtn) {
        cancelBtn.addEventListener('click', closeModal);
    }

    if (overlay) {
        overlay.addEventListener('click', function(e){
            if(e.target === overlay){
                closeModal();
            }
        });
    }

    // Close modals when clicking outside
    document.addEventListener('click', function(e) {
        const rescheduleModal = document.getElementById('rescheduleModal');
        const cancelModal = document.getElementById('cancelModal');
        
        if (rescheduleModal && rescheduleModal.classList.contains('open') && e.target === rescheduleModal) {
            closeRescheduleModal();
        }
        
        if (cancelModal && cancelModal.classList.contains('open') && e.target === cancelModal) {
            closeCancelModal();
        }
    });
});
</script>

</body>
</html>
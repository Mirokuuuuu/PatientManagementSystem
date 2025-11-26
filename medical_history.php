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
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Get logged-in user information - FIXED: Check session more thoroughly
$doctor_id = null;
$user_name = 'Guest Doctor';
$user_role = 'General Medicine';

// Check if doctor is logged in by verifying session variables
if (isset($_SESSION['doctor_id']) && !empty($_SESSION['doctor_id'])) {
    $doctor_id = $_SESSION['doctor_id'];
    
    // Fetch doctor details from database if logged in
    $stmt = $conn->prepare("SELECT first_name, last_name, email, department FROM doctor WHERE doctor_id = ?");
    $stmt->execute([$doctor_id]);
    $doctor = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($doctor) {
        $user_name = 'Dr. ' . $doctor['first_name'] . ' ' . $doctor['last_name'];
        $user_role = $doctor['department'] ?? 'General Medicine';
    } else {
        // If doctor not found in database, clear session
        unset($_SESSION['doctor_id']);
        $doctor_id = null;
    }
}

// If no valid doctor session, redirect to login
if (!$doctor_id && basename($_SERVER['PHP_SELF']) != 'login.php') {
    header("Location: login.php");
    exit;
}

// Fetch all patients with medical documents
function getPatientsWithDocuments($conn) {
    $sql = "SELECT DISTINCT 
                p.id,
                p.first_name,
                p.last_name,
                p.age,
                p.gender,
                p.email,
                p.phone_number,
                p.photo_path
            FROM patients p
            INNER JOIN medical_documents md ON p.id = md.patient_id
            ORDER BY p.first_name, p.last_name";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return $patients;
}

// Search patients with documents
function searchPatientsWithDocuments($conn, $search_term) {
    $sql = "SELECT DISTINCT 
                p.id,
                p.first_name,
                p.last_name,
                p.age,
                p.gender,
                p.email,
                p.phone_number,
                p.photo_path
            FROM patients p
            INNER JOIN medical_documents md ON p.id = md.patient_id
            WHERE CONCAT(p.first_name, ' ', p.last_name) LIKE ? 
               OR p.first_name LIKE ?
               OR p.last_name LIKE ?
            ORDER BY p.first_name, p.last_name";
    
    $stmt = $conn->prepare($sql);
    $search_like = "%$search_term%";
    $stmt->execute([$search_like, $search_like, $search_like]);
    $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return $patients;
}

// Get documents for a specific patient
function getPatientDocuments($conn, $patient_id) {
    $sql = "SELECT 
                md.id,
                md.patient_id,
                md.patient_name,
                md.document_type,
                md.document_title,
                md.file_name,
                md.file_path,
                md.file_size,
                md.upload_date,
                md.notes
            FROM medical_documents md
            WHERE md.patient_id = ?
            ORDER BY md.upload_date DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$patient_id]);
    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return $documents;
}

// Get patient details by ID
function getPatientDetails($conn, $patient_id) {
    $sql = "SELECT 
                p.id,
                p.first_name,
                p.last_name,
                p.age,
                p.gender,
                p.email,
                p.phone_number,
                p.address,
                p.emergency_contact,
                p.blood_type,
                p.allergies,
                p.photo_path
            FROM patients p
            WHERE p.id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$patient_id]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $patient;
}

// Get patient's lab test requests
function getPatientLabRequests($conn, $patient_id) {
    $sql = "SELECT * FROM lab_test_requests WHERE patient_id = ? ORDER BY request_date DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$patient_id]);
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return $requests;
}

// Fetch patient's all appointments
function getPatientAppointments($conn, $patient_id) {
    $sql = "SELECT 
                a.id,
                a.patientname,
                a.appointment_date,
                a.appointment_time,
                a.appointment_type,
                a.notes,
                a.status,
                a.created_at
            FROM appointments a
            WHERE a.patientname LIKE ?
            ORDER BY a.appointment_date DESC, a.appointment_time DESC";
    
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }
    
    // Get patient name for search
    $patient_sql = "SELECT CONCAT(first_name, ' ', last_name) as full_name FROM patients WHERE id = ?";
    $patient_stmt = $conn->prepare($patient_sql);
    $patient_stmt->execute([$patient_id]);
    $patient = $patient_stmt->fetch(PDO::FETCH_ASSOC);
    
    $appointments = [];
    if ($patient) {
        $patient_name = $patient['full_name'];
        $search_pattern = "%" . $patient_name . "%";
        
        $stmt->execute([$search_pattern]);
        $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    return $appointments;
}

// Get patient's prescriptions (updated for your schema)
function getPatientPrescriptions($conn, $patient_id) {
    $sql = "SELECT * FROM prescriptions WHERE patient_id = ? ORDER BY created_at DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$patient_id]);
    $prescriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return $prescriptions;
}

// Add new prescription (UPDATED to include patient_name)
function addPrescription($conn, $patient_id, $patient_name, $medicine_name, $dosage, $duration_days, $intake_frequency) {
    // Validate inputs
    if (empty($patient_id) || empty($patient_name) || empty($medicine_name) || empty($dosage) || empty($duration_days) || empty($intake_frequency)) {
        return false;
    }
    
    try {
        $sql = "INSERT INTO prescriptions (patient_id, patient_name, medicine_name, dosage, duration_days, intake_frequency, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())";
        
        $stmt = $conn->prepare($sql);
        $result = $stmt->execute([$patient_id, $patient_name, $medicine_name, $dosage, $duration_days, $intake_frequency]);
        
        return $result;
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        return false;
    }
}

// Add medical history
function addMedicalHistory($conn, $patient_id, $appointment_date, $findings, $diagnoses = '') {
    try {
        $sql = "INSERT INTO medical_history (patient_id, appointment_date, findings, diagnoses, created_at) 
                VALUES (?, ?, ?, ?, NOW())";
        
        $stmt = $conn->prepare($sql);
        $result = $stmt->execute([$patient_id, $appointment_date, $findings, $diagnoses]);
        
        return $result;
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        return false;
    }
}

// Get patient medical history
function getPatientMedicalHistory($conn, $patient_id) {
    $sql = "SELECT * FROM medical_history WHERE patient_id = ? ORDER BY appointment_date DESC, created_at DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$patient_id]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return $history;
}

// Handle lab request approval/denial
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_lab_request'])) {
    $request_id = $_POST['request_id'];
    $action = $_POST['action'];
    $doctor_notes = $_POST['doctor_notes'] ?? '';
    $denial_reason = $_POST['denial_reason'] ?? '';
    
    if ($action === 'approve') {
        // Handle file upload for permit
        $permit_file_path = '';
        if (isset($_FILES['permit_file']) && $_FILES['permit_file']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/permits/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file_extension = pathinfo($_FILES['permit_file']['name'], PATHINFO_EXTENSION);
            $file_name = 'permit_' . $request_id . '_' . time() . '.' . $file_extension;
            $permit_file_path = $upload_dir . $file_name;
            
            if (move_uploaded_file($_FILES['permit_file']['tmp_name'], $permit_file_path)) {
                // File uploaded successfully
            } else {
                $response = ['success' => false, 'message' => 'Error uploading permit file'];
                if (isset($_POST['ajax'])) {
                    header('Content-Type: application/json');
                    echo json_encode($response);
                    exit;
                }
            }
        } else {
            $response = ['success' => false, 'message' => 'Permit file is required for approval'];
            if (isset($_POST['ajax'])) {
                header('Content-Type: application/json');
                echo json_encode($response);
                exit;
            }
        }
        
        $sql = "UPDATE lab_test_requests SET status = 'approved', doctor_notes = ?, permit_file_path = ?, review_date = NOW() WHERE request_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$doctor_notes, $permit_file_path, $request_id]);
    } else {
        $sql = "UPDATE lab_test_requests SET status = 'denied', denial_reason = ?, review_date = NOW() WHERE request_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$denial_reason, $request_id]);
    }
    
    // Success - PDO execute() returns true on success
    $response = ['success' => true, 'message' => 'Lab request updated successfully'];
    
    if (isset($_POST['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
}

// Handle prescription form submission (UPDATED to include patient_name)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_prescription'])) {
    $patient_id = $_POST['patient_id'];
    $patient_name = $_POST['patient_name']; // NEW: Get patient_name from form
    $medicine_name = $_POST['medicine_name'];
    $dosage = $_POST['dosage'];
    $duration_days = $_POST['duration_days'];
    $intake_frequency = $_POST['intake_frequency'];
    
    // Validate required fields
    if (empty($medicine_name) || empty($dosage) || empty($duration_days) || empty($intake_frequency)) {
        $response = ['success' => false, 'message' => 'Please fill in all required fields'];
    } else {
        // UPDATED: Include patient_name in the function call
        if (addPrescription($conn, $patient_id, $patient_name, $medicine_name, $dosage, $duration_days, $intake_frequency)) {
            $response = ['success' => true, 'message' => 'Prescription added successfully'];
        } else {
            $response = ['success' => false, 'message' => 'Error adding prescription'];
        }
    }
    
    if (isset($_POST['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
}

// Handle medical history form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_medical_history'])) {
    $patient_id = $_POST['patient_id'];
    $appointment_date = $_POST['appointment_date'];
    $findings = $_POST['findings'];
    $diagnoses = $_POST['diagnoses'] ?? '';
    
    // Validate required fields
    if (empty($patient_id) || empty($appointment_date) || empty($findings)) {
        $response = ['success' => false, 'message' => 'Please fill in all required fields'];
    } else {
        if (addMedicalHistory($conn, $patient_id, $appointment_date, $findings, $diagnoses)) {
            $response = ['success' => true, 'message' => 'Medical history added successfully'];
        } else {
            $response = ['success' => false, 'message' => 'Error adding medical history'];
        }
    }
    
    if (isset($_POST['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
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

// Handle search
$patients = [];
$search_term = '';

if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search_term = trim($_GET['search']);
    $patients = searchPatientsWithDocuments($conn, $search_term);
} else {
    $patients = getPatientsWithDocuments($conn);
}

// For AJAX requests to get patient documents
if (isset($_GET['get_patient_documents']) && isset($_GET['patient_id'])) {
    $patient_id = $_GET['patient_id'];
    $patient_docs = getPatientDocuments($conn, $patient_id);
    
    header('Content-Type: application/json');
    echo json_encode($patient_docs);
    exit;
}

// For AJAX requests to get patient details
if (isset($_GET['get_patient_details']) && isset($_GET['patient_id'])) {
    $patient_id = $_GET['patient_id'];
    $patient_details = getPatientDetails($conn, $patient_id);
    
    header('Content-Type: application/json');
    echo json_encode($patient_details);
    exit;
}

// For AJAX requests to get patient lab requests
if (isset($_GET['get_patient_lab_requests']) && isset($_GET['patient_id'])) {
    $patient_id = $_GET['patient_id'];
    $lab_requests = getPatientLabRequests($conn, $patient_id);
    
    header('Content-Type: application/json');
    echo json_encode($lab_requests);
    exit;
}

// For AJAX requests to get patient's appointments
if (isset($_GET['get_patient_appointments']) && isset($_GET['patient_id'])) {
    $patient_id = $_GET['patient_id'];
    $appointments = getPatientAppointments($conn, $patient_id);
    
    header('Content-Type: application/json');
    echo json_encode($appointments ?: ['error' => 'No appointments found']);
    exit;
}

// For AJAX requests to get patient prescriptions
if (isset($_GET['get_patient_prescriptions']) && isset($_GET['patient_id'])) {
    $patient_id = $_GET['patient_id'];
    $prescriptions = getPatientPrescriptions($conn, $patient_id);
    
    header('Content-Type: application/json');
    echo json_encode($prescriptions);
    exit;
}

// For AJAX requests to get patient medical history
if (isset($_GET['get_patient_history']) && isset($_GET['patient_id'])) {
    $patient_id = $_GET['patient_id'];
    $medical_history = getPatientMedicalHistory($conn, $patient_id);
    
    header('Content-Type: application/json');
    echo json_encode($medical_history);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medical History - Pamantasan ng Lungsod ng Pasig</title>
    <link rel="stylesheet" href="medical_history.css">
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
            <a href="patients_appointments.php"><i class="fas fa-calendar-check"></i> Patient Appointments</a>
            <a href="medical_history.php" class="active"><i class="fas fa-file-medical"></i> Medical History</a>
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
            <h1>View patient medical histories</h1>
        </div>

        <div class="appointments-section">
            <div class="section-header">
                <h2>Medical Records</h2>
                <div class="d-flex align-items-center">
                    <div class="search-appointments">
                        <i class="fas fa-search"></i>
                        <input type="text" placeholder="Search patients..." id="searchInput" onkeyup="searchPatients()" value="<?php echo htmlspecialchars($search_term); ?>">
                    </div>
                    <?php if (!empty($search_term)): ?>
                        <a href="medical_history.php" class="btn btn-secondary">Clear</a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="appointments-list" id="appointmentsList">
                <!-- Patient Details Modal -->
                <div id="patientDetailsModal" class="modal">
                    <div class="modal-content">
                        <span class="close-modal">&times;</span>
                        <div class="patient-card">
                            <div class="patient-card-header">
                                <div class="patient-avatar" id="modalPatientAvatar">
                                    <i class="fas fa-user" style="font-size: 24px; color: #666; margin: 12px;"></i>
                                </div>
                                <div class="patient-basic-info">
                                    <h2 id="modalPatientName">Patient Name</h2>
                                    <p id="modalPatientInfo">Select a patient to view details</p>
                                </div>
                                <p class="record-description">Complete medical records and appointment details</p>
                            </div>

                            <div class="patient-tabs">
                                <button class="tab-btn active" data-tab="overview">Overview</button>
                                <button class="tab-btn" data-tab="history">History</button>
                                <button class="tab-btn" data-tab="medications">Medications</button>
                                <button class="tab-btn" data-tab="lab-results">Lab Results</button>
                            </div>

                            <div class="patient-details">
                                <!-- Overview Section -->
                                <div class="tab-content overview-tab" style="display: block;">
                                    <div class="appointment-section">
                                        <h3><i class="fas fa-calendar-check"></i> Appointment History</h3>
                                        <div class="appointment-info" id="latestAppointment">
                                            <p>Select a patient to view appointment history.</p>
                                        </div>
                                    </div>
                                </div>

                                <!-- History Section -->
                                <div class="tab-content history-tab" style="display: none;">
                                    <div class="history-section">
                                        <div class="visit-history" id="visitHistory">
                                            <div class="empty-state">
                                                <i class="bi bi-clock-history"></i>
                                                <p>No medical history recorded yet.</p>
                                            </div>
                                        </div>
                                        <button class="history-btn">Add History</button>
                                    </div>
                                </div>

                                <!-- Medications Section -->
                                <div class="tab-content medications-tab" style="display: none;">
                                    <div class="medications-section">
                                        <div class="current-medications">
                                            <h3><i class="fas fa-pills"></i> Current Medications</h3>
                                            <div id="currentMedications">
                                                <p class="no-medications">No current medications recorded.</p>
                                            </div>
                                            <button class="prescription-btn">Add Prescription</button>
                                        </div>
                                    </div>
                                </div>

                                <!-- Lab Results Section -->
                                <div class="tab-content lab-results-tab" style="display: none;">
                                    <div class="lab-results-section">
                                        <h3><i class="fas fa-flask"></i> Laboratory Results & Medical Documents</h3>
                                        <div class="lab-results-list" id="patientDocumentsList">
                                            <div class="empty-state">
                                                <i class="bi bi-file-earmark-medical"></i>
                                                <p>Select a patient to view documents</p>
                                            </div>
                                        </div>
                                        
                                        <!-- Lab Test Requests Section -->
                                        <div class="lab-requests-section" style="margin-top: 30px;">
                                            <h3><i class="fas fa-tasks"></i> Lab Test Requests</h3>
                                            <div class="lab-requests-list" id="patientLabRequestsList">
                                                <div class="empty-state">
                                                    <i class="bi bi-hourglass-split"></i>
                                                    <p>Select a patient to view lab requests</p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (empty($patients)): ?>
                    <div class="no-patients">
                        <i class="bi bi-file-earmark-x"></i>
                        <h4>No patients with medical documents found</h4>
                        <p>There are no patients in the system who have uploaded medical documents.</p>
                        <?php if (!empty($search_term)): ?>
                            <p>Try a different search term or <a href="medical_history.php">view all patients</a>.</p>
                        <?php else: ?>
                            <p>Patients will appear here once they upload medical documents.</p>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <?php foreach ($patients as $patient): ?>
                        <?php
                        $full_name = $patient['first_name'] . ' ' . $patient['last_name'];
                        $age = $patient['age'];
                        $gender = $patient['gender'];
                        $photo_path = $patient['photo_path'] ?? '';
                        ?>
                        <div class="appointment-card" data-patient-id="<?php echo $patient['id']; ?>" data-patient-name="<?php echo htmlspecialchars(strtolower($full_name)); ?>" data-patient-age="<?php echo htmlspecialchars($age); ?>" data-patient-gender="<?php echo htmlspecialchars($gender); ?>" data-photo-path="<?php echo htmlspecialchars($photo_path); ?>">
                            <div class="patient-info">
                                <div class="avatar">
                                    <?php if (!empty($photo_path)): ?>
                                        <img src="<?php echo htmlspecialchars($photo_path); ?>" alt="<?php echo htmlspecialchars($full_name); ?>" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">
                                    <?php else: ?>
                                        <i class="fas fa-user" style="color: #666; font-size: 24px; margin: 13px;"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="details">
                                    <h3><?php echo htmlspecialchars($full_name); ?></h3>
                                    <p>ID: <?php echo $patient['id']; ?> • <?php echo htmlspecialchars($age); ?>y • <?php echo htmlspecialchars($gender); ?></p>
                                    <div class="appointment-time">
                                        <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($patient['email']); ?>
                                        <i class="fas fa-phone"></i> <?php echo htmlspecialchars($patient['phone_number']); ?>
                                    </div>
                                    <p class="chief-complaint">Patient has uploaded medical documents</p>
                                </div>
                            </div>
                            <div class="status completed">
                                <span>has documents</span>
                                <button class="view-details">View Details</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Add Prescription Modal -->
    <div id="addPrescriptionModal" class="modal">
        <div class="modal-content add-prescription-modal">
            <span class="close-modal" id="closePrescriptionModal">&times;</span>
            <h2>Add Prescription</h2>
            <p>Prescription Details</p>
            
            <form id="prescriptionForm">
                <input type="hidden" id="prescriptionPatientId" name="patient_id">
                <input type="hidden" id="prescriptionPatientName" name="patient_name"> <!-- NEW: Hidden field for patient_name -->
                
                <div class="form-group">
                    <label for="medicineName">Medicine Name</label>
                    <input type="text" id="medicineName" name="medicine_name" placeholder="Enter medicine name" required>
                </div>

                <div class="form-group">
                    <label for="dosage">Dosage</label>
                    <input type="text" id="dosage" name="dosage" placeholder="e.g., 500mg" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="duration_days">Duration (Days)</label>
                        <input type="number" id="duration_days" name="duration_days" placeholder="e.g., 7" min="1" required>
                    </div>
                    <div class="form-group">
                        <label for="intake_frequency">Intake Frequency</label>
                        <select id="intake_frequency" name="intake_frequency" required>
                            <option value="">Select intake frequency</option>
                            <option value="Once daily">Once daily</option>
                            <option value="Twice daily">Twice daily</option>
                            <option value="Three times daily">Three times daily</option>
                            <option value="Every 4 hours">Every 4 hours</option>
                            <option value="Every 6 hours">Every 6 hours</option>
                            <option value="Every 8 hours">Every 8 hours</option>
                            <option value="As needed">As needed</option>
                            <option value="Before meals">Before meals</option>
                            <option value="After meals">After meals</option>
                            <option value="At bedtime">At bedtime</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="prescriptionNotes">Additional Notes</label>
                    <textarea id="prescriptionNotes" name="notes" placeholder="Add any special instructions or notes..." rows="3"></textarea>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn-cancel" id="cancelPrescriptionBtn">Cancel</button>
                    <button type="submit" class="btn-add-prescription">Add Prescription</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add History Modal -->
    <div id="addHistoryModal" class="modal">
        <div class="modal-content add-history-modal">
            <span class="close-modal" id="closeHistoryModal">&times;</span>
            <h2>Add History</h2>
            <p>Patient Details</p>
            
            <form id="medicalHistoryForm">
                <input type="hidden" id="historyPatientId" name="patient_id">
                
                <div class="form-group">
                    <label for="historyPatientName">Patient Name</label>
                    <input type="text" id="historyPatientName" placeholder="Patient name will appear here" readonly>
                </div>

                <div class="form-group">
                    <label for="historyDate">Appointment Date</label>
                    <input type="date" id="historyDate" name="appointment_date" required>
                </div>

                <div class="form-group">
                    <label for="historyFindings">Findings</label>
                    <textarea id="historyFindings" name="findings" placeholder="Enter medical findings, observations, and examination results..." rows="4" required></textarea>
                </div>

                <div class="form-group">
                    <label for="historyDiagnoses">Diagnoses</label>
                    <textarea id="historyDiagnoses" name="diagnoses" placeholder="Add diagnosis and any special notes or instructions..." rows="3"></textarea>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn-cancel" id="cancelHistoryBtn">Cancel</button>
                    <button type="submit" class="btn-add-history">Add History</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        let currentPatientId = '';
        let currentPatientName = '';

        // Format file size
        function formatFileSize(bytes) {
            if (bytes >= 1048576) {
                return (bytes / 1048576).toFixed(2) + ' MB';
            } else if (bytes >= 1024) {
                return (bytes / 1024).toFixed(2) + ' KB';
            } else {
                return bytes + ' bytes';
            }
        }

        // Format date
        function formatDate(dateString) {
            const date = new Date(dateString);
            return date.toLocaleDateString('en-US', { 
                year: 'numeric', 
                month: 'long', 
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        }

        // NEW FUNCTION: Reset all tab content to loading/empty state
        function resetAllTabContent() {
            // Reset Overview Tab
            document.getElementById('latestAppointment').innerHTML = `
                <div class="empty-state">
                    <i class="bi bi-hourglass-split"></i>
                    <p>Loading appointments...</p>
                </div>
            `;
            
            // Reset History Tab
            document.getElementById('visitHistory').innerHTML = `
                <div class="empty-state">
                    <i class="bi bi-hourglass-split"></i>
                    <p>Loading medical history...</p>
                </div>
            `;
            
            // Reset Medications Tab
            document.getElementById('currentMedications').innerHTML = `
                <div class="empty-state">
                    <i class="bi bi-hourglass-split"></i>
                    <p>Loading prescriptions...</p>
                </div>
            `;
            
            // Reset Lab Results Tab
            document.getElementById('patientDocumentsList').innerHTML = `
                <div class="empty-state">
                    <i class="bi bi-hourglass-split"></i>
                    <p>Loading documents...</p>
                </div>
            `;
            
            // Reset Lab Requests
            document.getElementById('patientLabRequestsList').innerHTML = `
                <div class="empty-state">
                    <i class="bi bi-hourglass-split"></i>
                    <p>Loading lab requests...</p>
                </div>
            `;
        }

        function signOut() {
    // Redirect to logout handler which destroys session
    window.location.href = 'medical_history.php?logout=true';
}

        // Search function for real-time filtering
        function searchPatients() {
            const input = document.getElementById('searchInput');
            const filter = input.value.toLowerCase();
            const appointments = document.getElementById('appointmentsList');
            const cards = appointments.getElementsByClassName('appointment-card');
            let hasVisibleResults = false;
            
            for (let i = 0; i < cards.length; i++) {
                const card = cards[i];
                const patientName = card.getAttribute('data-patient-name');
                
                // Search in patient name
                if (patientName.includes(filter)) {
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

        // Function to open the modal
        function openPatientDetails(patientId, patientName, patientAge, patientGender, photoPath) {
            currentPatientId = patientId;
            currentPatientName = patientName;
            
            // Immediately update the modal with all the basic patient information
            document.getElementById('modalPatientName').textContent = patientName;
            document.getElementById('modalPatientInfo').textContent = `ID: ${patientId} • ${patientAge} years • ${patientGender}`;
            
            // Update avatar in modal if photo exists
            const avatar = document.getElementById('modalPatientAvatar');
            if (photoPath) {
                avatar.innerHTML = `<img src="${photoPath}" alt="${patientName}" style="width: 100%; height: 100%; border-radius: 50%; object-fit: cover;">`;
            } else {
                avatar.innerHTML = '<i class="fas fa-user" style="font-size: 24px; color: #666; margin: 12px;"></i>';
            }
            
            // RESET ALL TAB CONTENT TO LOADING STATE
            resetAllTabContent();
            
            // Reset to overview tab and activate it
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            document.querySelector('[data-tab="overview"]').classList.add('active');
            
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.style.display = 'none';
            });
            document.querySelector('.overview-tab').style.display = 'block';
            
            document.getElementById('patientDetailsModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
            
            // Load dynamic data for the new patient
            loadPatientAppointments(patientId);
        }

        // Function to close patient modal and clear content
        function closePatientModal() {
            const modal = document.getElementById('patientDetailsModal');
            modal.style.display = 'none';
            document.body.style.overflow = 'auto';
            
            // Clear current patient data
            currentPatientId = '';
            currentPatientName = '';
            
            // Reset all tab content
            resetAllTabContent();
            
            // Reset to default state
            document.getElementById('modalPatientName').textContent = 'Patient Name';
            document.getElementById('modalPatientInfo').textContent = 'Select a patient to view details';
            document.getElementById('modalPatientAvatar').innerHTML = '<i class="fas fa-user" style="font-size: 24px; color: #666; margin: 12px;"></i>';
        }

        // Load patient's appointments
        function loadPatientAppointments(patientId) {
            const appointmentContainer = document.getElementById('latestAppointment');
            
            appointmentContainer.innerHTML = `
                <div class="empty-state">
                    <i class="bi bi-hourglass-split"></i>
                    <p>Loading appointments...</p>
                </div>
            `;
            
            fetch(`medical_history.php?get_patient_appointments=1&patient_id=${patientId}`)
                .then(response => response.json())
                .then(appointments => {
                    // Check if we're still viewing the same patient
                    if (currentPatientId !== patientId) return;
                    
                    if (appointments.error || !appointments || appointments.length === 0) {
                        appointmentContainer.innerHTML = `
                            <div class="empty-state">
                                <i class="bi bi-calendar-x"></i>
                                <p>No appointments found.</p>
                            </div>
                        `;
                        return;
                    }
                    
                    let html = '';
                    
                    // Add section header
                    html += `
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 style="margin: 0; color: #333;">Appointment History</h5>
                            <span class="badge bg-primary">${appointments.length} appointment(s)</span>
                        </div>
                    `;
                    
                    appointments.forEach((appointment, index) => {
                        const appointmentDate = new Date(appointment.appointment_date + ' ' + appointment.appointment_time);
                        const formattedDate = appointmentDate.toLocaleDateString('en-US', {
                            weekday: 'long',
                            year: 'numeric',
                            month: 'long',
                            day: 'numeric'
                        });
                        const formattedTime = appointmentDate.toLocaleTimeString('en-US', {
                            hour: '2-digit',
                            minute: '2-digit'
                        });
                        
                        let statusBadge = '';
                        switch(appointment.status) {
                            case 'Scheduled':
                                statusBadge = '<span class="badge bg-primary">Scheduled</span>';
                                break;
                            case 'Completed':
                                statusBadge = '<span class="badge bg-success">Completed</span>';
                                break;
                            case 'Pending':
                                statusBadge = '<span class="badge bg-warning">Pending</span>';
                                break;
                            case 'Cancelled':
                                statusBadge = '<span class="badge bg-danger">Cancelled</span>';
                                break;
                            default:
                                statusBadge = '<span class="badge bg-secondary">' + appointment.status + '</span>';
                        }
                        
                        // Add separator between appointments (except for the first one)
                        if (index > 0) {
                            html += `<hr style="margin: 20px 0;">`;
                        }
                        
                        html += `
                            <div class="appointment-detail">
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <div>
                                        <h6 style="margin: 0; color: #333; font-weight: 600;">${appointment.appointment_type}</h6>
                                        ${statusBadge}
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="appointment-detail-label">Date</div>
                                        <div class="appointment-detail-value">
                                            <i class="bi bi-calendar-event"></i> ${formattedDate}
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="appointment-detail-label">Time</div>
                                        <div class="appointment-detail-value">
                                            <i class="bi bi-clock"></i> ${formattedTime}
                                        </div>
                                    </div>
                                </div>
                                
                                ${appointment.notes ? `
                                <div class="mt-3">
                                    <div class="appointment-detail-label">Notes</div>
                                    <div class="appointment-detail-value" style="font-style: italic;">
                                        "${appointment.notes}"
                                    </div>
                                </div>
                                ` : ''}
                                
                                <div class="mt-3">
                                    <small class="text-muted">
                                        Created: ${formatDate(appointment.created_at)}
                                    </small>
                                </div>
                            </div>
                        `;
                    });
                    
                    appointmentContainer.innerHTML = html;
                })
                .catch(error => {
                    // Check if we're still viewing the same patient
                    if (currentPatientId !== patientId) return;
                    
                    console.error('Error loading appointments:', error);
                    appointmentContainer.innerHTML = `
                        <div class="empty-state">
                            <i class="bi bi-exclamation-triangle"></i>
                            <p>Error loading appointment details.</p>
                        </div>
                    `;
                });
        }

        // Load patient documents
        function loadPatientDocuments(patientId) {
            const documentsList = document.getElementById('patientDocumentsList');
            
            documentsList.innerHTML = `
                <div class="empty-state">
                    <i class="bi bi-hourglass-split"></i>
                    <p>Loading documents...</p>
                </div>
            `;
            
            fetch(`medical_history.php?get_patient_documents=1&patient_id=${patientId}`)
                .then(response => response.json())
                .then(documents => {
                    // Check if we're still viewing the same patient
                    if (currentPatientId !== patientId) return;
                    
                    if (documents.length === 0) {
                        documentsList.innerHTML = `
                            <div class="empty-state">
                                <i class="bi bi-file-earmark-x"></i>
                                <p>No documents uploaded yet for this patient.</p>
                            </div>
                        `;
                        return;
                    }
                    
                    let html = '';
                    documents.forEach(doc => {
                        html += `
                            <div class="document-card">
                                <div class="document-header">
                                    <i class="bi bi-file-earmark-text document-icon"></i>
                                    <div class="document-info">
                                        <div class="document-title">${doc.document_title}</div>
                                        <div class="document-meta">
                                            ${formatDate(doc.upload_date)} • 
                                            ${doc.document_type} • 
                                            ${formatFileSize(doc.file_size)}
                                        </div>
                                        ${doc.notes ? `<div class="document-meta" style="margin-top:4px">${doc.notes}</div>` : ''}
                                    </div>
                                    <div class="document-actions">
                                        <a href="${doc.file_path}" target="_blank" class="btn btn-sm btn-primary">
                                            <i class="bi bi-download"></i> View
                                        </a>
                                    </div>
                                </div>
                            </div>
                        `;
                    });
                    
                    documentsList.innerHTML = html;
                })
                .catch(error => {
                    // Check if we're still viewing the same patient
                    if (currentPatientId !== patientId) return;
                    
                    console.error('Error loading documents:', error);
                    documentsList.innerHTML = `
                            <div class="empty-state">
                                <i class="bi bi-exclamation-triangle"></i>
                                <p>Error loading documents. Please try again.</p>
                            </div>
                        `;
                });
        }

        // Load patient lab requests
        function loadPatientLabRequests(patientId) {
            const requestsList = document.getElementById('patientLabRequestsList');
            
            requestsList.innerHTML = `
                <div class="empty-state">
                    <i class="bi bi-hourglass-split"></i>
                    <p>Loading lab requests...</p>
                </div>
            `;
            
            fetch(`medical_history.php?get_patient_lab_requests=1&patient_id=${patientId}`)
                .then(response => response.json())
                .then(requests => {
                    // Check if we're still viewing the same patient
                    if (currentPatientId !== patientId) return;
                    
                    if (requests.length === 0) {
                        requestsList.innerHTML = `
                            <div class="empty-state">
                                <i class="bi bi-check-circle"></i>
                                <p>No lab test requests found for this patient.</p>
                            </div>
                        `;
                        return;
                    }
                    
                    let html = '';
                    requests.forEach(request => {
                        const statusBadge = request.status === 'approved' ? 
                            '<span class="badge bg-success">Approved</span>' :
                            request.status === 'denied' ? 
                            '<span class="badge bg-danger">Denied</span>' :
                            '<span class="badge bg-warning">Pending Review</span>';
                        
                        const categoryBadge = request.test_category === 'basic' ? 
                            '<span class="badge bg-info">Basic Test</span>' :
                            '<span class="badge bg-primary">Advanced Test</span>';
                        
                        const priorityBadge = request.priority === 'urgent' ? 
                            '<span class="badge bg-danger">Urgent</span>' :
                            '<span class="badge bg-secondary">Routine</span>';
                        
                        html += `
                            <div class="lab-request-card">
                                <div class="lab-request-header">
                                    <i class="bi bi-flask lab-request-icon"></i>
                                    <div class="lab-request-info">
                                        <div class="lab-request-title">${request.test_type}</div>
                                        <div class="lab-request-meta">
                                            Requested: ${formatDate(request.request_date)} • 
                                            ${categoryBadge} • 
                                            ${priorityBadge}
                                        </div>
                                        ${request.reason ? `<div class="lab-request-meta" style="margin-top:4px"><strong>Reason:</strong> ${request.reason}</div>` : ''}
                                        ${request.symptoms ? `<div class="lab-request-meta"><strong>Symptoms:</strong> ${request.symptoms}</div>` : ''}
                                        ${request.doctor_notes ? `<div class="lab-request-meta text-success"><strong>Doctor Notes:</strong> ${request.doctor_notes}</div>` : ''}
                                        ${request.denial_reason ? `<div class="lab-request-meta text-danger"><strong>Denial Reason:</strong> ${request.denial_reason}</div>` : ''}
                                        <div style="margin-top:8px">
                                            ${statusBadge}
                                        </div>
                                    </div>
                                    ${request.status === 'pending' ? `
                                    <div class="lab-request-actions">
                                        <button class="btn btn-success btn-sm" onclick="approveLabRequest(${request.request_id})">Approve</button>
                                        <button class="btn btn-danger btn-sm" onclick="denyLabRequest(${request.request_id})">Deny</button>
                                    </div>
                                    ` : ''}
                                </div>
                            </div>
                        `;
                    });
                    
                    requestsList.innerHTML = html;
                })
                .catch(error => {
                    // Check if we're still viewing the same patient
                    if (currentPatientId !== patientId) return;
                    
                    console.error('Error loading lab requests:', error);
                    requestsList.innerHTML = `
                        <div class="empty-state">
                            <i class="bi bi-exclamation-triangle"></i>
                            <p>Error loading lab requests.</p>
                        </div>
                    `;
                });
        }

        // Load patient prescriptions
        function loadPatientPrescriptions(patientId) {
            const prescriptionsContainer = document.getElementById('currentMedications');
            
            prescriptionsContainer.innerHTML = `
                <div class="empty-state">
                    <i class="bi bi-hourglass-split"></i>
                    <p>Loading prescriptions...</p>
                </div>
            `;
            
            fetch(`medical_history.php?get_patient_prescriptions=1&patient_id=${patientId}`)
                .then(response => response.json())
                .then(prescriptions => {
                    // Check if we're still viewing the same patient
                    if (currentPatientId !== patientId) return;
                    
                    console.log('Loaded prescriptions for patient', patientId, ':', prescriptions);
                    
                    if (!prescriptions || prescriptions.length === 0) {
                        prescriptionsContainer.innerHTML = `
                            <p class="no-medications">No current medications recorded for this patient.</p>
                        `;
                        return;
                    }
                    
                    let html = '';
                    prescriptions.forEach(prescription => {
                        // Verify this prescription belongs to the current patient
                        if (prescription.patient_id != patientId) {
                            console.warn('Prescription patient_id mismatch:', prescription.patient_id, 'expected:', patientId);
                            return; // Skip this prescription
                        }
                        
                        const prescribedDate = new Date(prescription.created_at);
                        const formattedDate = prescribedDate.toLocaleDateString('en-US', {
                            year: 'numeric',
                            month: 'long',
                            day: 'numeric'
                        });
                        
                        // Calculate end date based on duration_days
                        const endDate = new Date(prescribedDate);
                        endDate.setDate(endDate.getDate() + parseInt(prescription.duration_days));
                        const formattedEndDate = endDate.toLocaleDateString('en-US', {
                            year: 'numeric',
                            month: 'long',
                            day: 'numeric'
                        });
                        
                        html += `
                            <div class="prescription-card" style="background: #f8f9fa; border-radius: 8px; padding: 16px; margin-bottom: 12px; border-left: 4px solid #0d6efd;">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <h5 style="margin: 0; color: #333;">${prescription.medicine_name}</h5>
                                    <span class="badge bg-success">Active</span>
                                </div>
                                <div class="prescription-details">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <strong>Dosage:</strong> ${prescription.dosage}
                                        </div>
                                        <div class="col-md-6">
                                            <strong>Duration:</strong> ${prescription.duration_days} days
                                        </div>
                                    </div>
                                    <div class="row mt-2">
                                        <div class="col-md-6">
                                            <strong>Frequency:</strong> ${prescription.intake_frequency}
                                        </div>
                                        <div class="col-md-6">
                                            <strong>Prescribed:</strong> ${formattedDate}
                                        </div>
                                    </div>
                                    <div class="row mt-2">
                                        <div class="col-md-6">
                                            <strong>Treatment Until:</strong> ${formattedEndDate}
                                        </div>
                                        <div class="col-md-6">
                                            <strong>Days Left:</strong> 
                                            <span class="badge ${getDaysLeftBadge(prescription.duration_days, prescription.created_at)}">
                                                ${calculateDaysLeft(prescription.duration_days, prescription.created_at)}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;
                    });
                    
                    prescriptionsContainer.innerHTML = html;
                })
                .catch(error => {
                    // Check if we're still viewing the same patient
                    if (currentPatientId !== patientId) return;
                    
                    console.error('Error loading prescriptions:', error);
                    prescriptionsContainer.innerHTML = `
                        <div class="empty-state">
                            <i class="bi bi-exclamation-triangle"></i>
                            <p>Error loading prescriptions.</p>
                        </div>
                    `;
                });
        }

        // Load patient medical history
        function loadPatientHistory(patientId) {
            const historyContainer = document.getElementById('visitHistory');
            
            historyContainer.innerHTML = `
                <div class="empty-state">
                    <i class="bi bi-hourglass-split"></i>
                    <p>Loading medical history...</p>
                </div>
            `;
            
            fetch(`medical_history.php?get_patient_history=1&patient_id=${patientId}`)
                .then(response => response.json())
                .then(history => {
                    // Check if we're still viewing the same patient
                    if (currentPatientId !== patientId) return;
                    
                    if (!history || history.length === 0) {
                        historyContainer.innerHTML = `
                            <div class="empty-state">
                                <i class="bi bi-clock-history"></i>
                                <p>No medical history recorded yet.</p>
                            </div>
                        `;
                        return;
                    }
                    
                    let html = '';
                    history.forEach(record => {
                        const visitDate = new Date(record.appointment_date);
                        const formattedDate = visitDate.toLocaleDateString('en-US', {
                            year: 'numeric',
                            month: 'long',
                            day: 'numeric'
                        });
                        
                        html += `
                            <div class="history-record" style="background: #f8f9fa; border-radius: 8px; padding: 16px; margin-bottom: 12px; border-left: 4px solid #6c757d;">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <h5 style="margin: 0; color: #333;">Visit on ${formattedDate}</h5>
                                </div>
                                <div class="history-details">
                                    <div class="row">
                                        <div class="col-12">
                                            <strong>Findings:</strong> ${record.findings || 'No findings recorded'}
                                        </div>
                                    </div>
                                    <div class="row mt-2">
                                        <div class="col-12">
                                            <strong>Diagnosis:</strong> ${record.diagnoses || 'No diagnosis recorded'}
                                        </div>
                                    </div>
                                    <div class="row mt-2">
                                        <div class="col-12">
                                            <small class="text-muted">
                                                Recorded: ${formatDate(record.created_at)}
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;
                    });
                    
                    historyContainer.innerHTML = html;
                })
                .catch(error => {
                    // Check if we're still viewing the same patient
                    if (currentPatientId !== patientId) return;
                    
                    console.error('Error loading medical history:', error);
                    historyContainer.innerHTML = `
                        <div class="empty-state">
                            <i class="bi bi-exclamation-triangle"></i>
                            <p>Error loading medical history.</p>
                        </div>
                    `;
                });
        }

        // Calculate days left in treatment
        function calculateDaysLeft(durationDays, createdDate) {
            const startDate = new Date(createdDate);
            const endDate = new Date(startDate);
            endDate.setDate(endDate.getDate() + parseInt(durationDays));
            const today = new Date();
            
            const timeDiff = endDate - today;
            const daysLeft = Math.ceil(timeDiff / (1000 * 60 * 60 * 24));
            
            return daysLeft > 0 ? daysLeft + ' days' : 'Completed';
        }

        // Get badge color based on days left
        function getDaysLeftBadge(durationDays, createdDate) {
            const startDate = new Date(createdDate);
            const endDate = new Date(startDate);
            endDate.setDate(endDate.getDate() + parseInt(durationDays));
            const today = new Date();
            
            const timeDiff = endDate - today;
            const daysLeft = Math.ceil(timeDiff / (1000 * 60 * 60 * 24));
            
            if (daysLeft <= 0) return 'bg-secondary';
            if (daysLeft <= 3) return 'bg-danger';
            if (daysLeft <= 7) return 'bg-warning';
            return 'bg-success';
        }

        // Switch tabs
        function switchTab(tabName) {
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.style.display = 'none';
            });
            
            document.querySelector(`.${tabName}-tab`).style.display = 'block';
            
            // Only load data if we have a current patient
            if (currentPatientId) {
                if (tabName === 'overview') {
                    loadPatientAppointments(currentPatientId);
                } else if (tabName === 'history') {
                    loadPatientHistory(currentPatientId);
                } else if (tabName === 'medications') {
                    loadPatientPrescriptions(currentPatientId);
                } else if (tabName === 'lab-results') {
                    loadPatientDocuments(currentPatientId);
                    loadPatientLabRequests(currentPatientId);
                }
            } else {
                // If no patient is selected, show appropriate empty state
                const emptyStates = {
                    'overview': 'Select a patient to view appointment history.',
                    'history': 'Select a patient to view medical history.',
                    'medications': 'Select a patient to view prescriptions.',
                    'lab-results': 'Select a patient to view lab results and documents.'
                };
                
                const tabContent = document.querySelector(`.${tabName}-tab`);
                const mainContainer = tabContent.querySelector('.empty-state, #latestAppointment, #visitHistory, #currentMedications, #patientDocumentsList, #patientLabRequestsList');
                
                if (mainContainer) {
                    mainContainer.innerHTML = `
                        <div class="empty-state">
                            <i class="bi bi-person-x"></i>
                            <p>${emptyStates[tabName]}</p>
                        </div>
                    `;
                }
            }
        }

        // Handle prescription form submission
        function handlePrescriptionSubmit(event) {
            event.preventDefault();
            
            // Validate form
            const form = event.target;
            const medicineName = form.medicine_name.value.trim();
            const dosage = form.dosage.value.trim();
            const durationDays = form.duration_days.value.trim();
            const intakeFrequency = form.intake_frequency.value;
            const patientId = form.patient_id.value;
            const patientName = form.patient_name.value; // NEW: Get patient_name from form
            
            console.log('Adding prescription for patient:', patientId, 'Name:', patientName);
            
            if (!patientId || !patientName) {
                alert('Error: No patient selected');
                return;
            }
            
            if (!medicineName || !dosage || !durationDays || !intakeFrequency) {
                alert('Please fill in all required fields');
                return;
            }
            
            const formData = new FormData(form);
            formData.append('add_prescription', 'true');
            formData.append('ajax', 'true');
            
            // Show loading state
            const submitBtn = form.querySelector('.btn-add-prescription');
            const originalText = submitBtn.textContent;
            submitBtn.textContent = 'Adding...';
            submitBtn.disabled = true;
            
            fetch('medical_history.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(result => {
                console.log('Response:', result);
                if (result.success) {
                    alert('Prescription added successfully!');
                    document.getElementById('addPrescriptionModal').style.display = 'none';
                    document.body.style.overflow = 'auto';
                    
                    // Reload prescriptions for the current patient
                    if (currentPatientId) {
                        loadPatientPrescriptions(currentPatientId);
                    }
                    
                    // Reset form
                    form.reset();
                } else {
                    alert('Error: ' + result.message);
                }
            })
            .catch(error => {
                console.error('Error adding prescription:', error);
                alert('Error adding prescription. Please try again.');
            })
            .finally(() => {
                // Restore button state
                submitBtn.textContent = originalText;
                submitBtn.disabled = false;
            });
        }

        // Handle medical history form submission
        function handleMedicalHistorySubmit(event) {
            event.preventDefault();
            
            const form = event.target;
            const patientId = form.patient_id.value;
            const appointmentDate = form.appointment_date.value;
            const findings = form.findings.value.trim();
            
            if (!patientId || !appointmentDate || !findings) {
                alert('Please fill in all required fields');
                return;
            }
            
            const formData = new FormData(form);
            formData.append('add_medical_history', 'true');
            formData.append('ajax', 'true');
            
            // Show loading state
            const submitBtn = form.querySelector('.btn-add-history');
            const originalText = submitBtn.textContent;
            submitBtn.textContent = 'Adding...';
            submitBtn.disabled = true;
            
            fetch('medical_history.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    alert('Medical history added successfully!');
                    document.getElementById('addHistoryModal').style.display = 'none';
                    document.body.style.overflow = 'auto';
                    
                    // Reload medical history for the current patient
                    if (currentPatientId) {
                        loadPatientHistory(currentPatientId);
                    }
                    
                    // Reset form
                    form.reset();
                } else {
                    alert('Error: ' + result.message);
                }
            })
            .catch(error => {
                console.error('Error adding medical history:', error);
                alert('Error adding medical history. Please try again.');
            })
            .finally(() => {
                // Restore button state
                submitBtn.textContent = originalText;
                submitBtn.disabled = false;
            });
        }

        // Lab request approval functions
        function approveLabRequest(requestId) {
            const modalHtml = `
                <div id="approveModal" class="modal" style="display: block;">
                    <div class="modal-content" style="max-width: 500px;">
                        <span class="close-modal" onclick="closeApproveModal()">&times;</span>
                        <h3>Approve Lab Request</h3>
                        <p>Please upload the permit file and add optional notes:</p>
                        
                        <form id="approveForm" enctype="multipart/form-data">
                            <div class="form-group">
                                <label for="permitFile" class="required">Permit File</label>
                                <div class="file-input-wrapper">
                                    <input type="file" id="permitFile" name="permit_file" class="form-control-file" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" required>
                                    <div class="file-input-custom">
                                        <span class="file-input-text" id="fileInputText">Choose permit file...</span>
                                        <span class="file-input-button">Browse</span>
                                    </div>
                                </div>
                                <div class="file-name" id="fileName" style="display: none;"></div>
                                <div class="form-text">Accepted formats: PDF, JPG, PNG, DOC, DOCX (Max: 5MB)</div>
                            </div>
                            
                            <div class="form-group">
                                <label for="approveNotes">Approval Notes</label>
                                <textarea id="approveNotes" name="doctor_notes" class="form-control" rows="4" placeholder="Add any approval notes or instructions for the patient..."></textarea>
                                <div class="form-text">Optional notes that will be visible to the patient</div>
                            </div>
                            
                            <div class="form-actions">
                                <button type="button" class="btn btn-secondary" onclick="closeApproveModal()">
                                    <i class="bi bi-x-circle"></i> Cancel
                                </button>
                                <button type="button" class="btn btn-success" onclick="submitApproveRequest(${requestId})">
                                    <i class="bi bi-check-circle"></i> Approve Request
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            `;
            
            const modalContainer = document.createElement('div');
            modalContainer.innerHTML = modalHtml;
            document.body.appendChild(modalContainer);
            document.body.style.overflow = 'hidden';
            
            // Add file input change handler
            const fileInput = document.getElementById('permitFile');
            const fileInputText = document.getElementById('fileInputText');
            const fileName = document.getElementById('fileName');
            
            fileInput.addEventListener('change', function(e) {
                if (this.files && this.files[0]) {
                    const file = this.files[0];
                    fileInputText.textContent = file.name;
                    fileName.textContent = `Selected: ${file.name} (${formatFileSize(file.size)})`;
                    fileName.style.display = 'block';
                    
                    // Validate file size (5MB limit)
                    if (file.size > 5 * 1024 * 1024) {
                        alert('File size exceeds 5MB limit. Please choose a smaller file.');
                        this.value = '';
                        fileInputText.textContent = 'Choose permit file...';
                        fileName.style.display = 'none';
                    }
                }
            });
        }

        function closeApproveModal() {
            const modal = document.getElementById('approveModal');
            if (modal) {
                modal.remove();
            }
            document.body.style.overflow = 'auto';
        }

        function submitApproveRequest(requestId) {
            const permitFile = document.getElementById('permitFile').files[0];
            const notes = document.getElementById('approveNotes').value;
            
            if (!permitFile) {
                alert('Please select a permit file to upload.');
                return;
            }
            
            const formData = new FormData();
            formData.append('request_id', requestId);
            formData.append('action_lab_request', 'true');
            formData.append('action', 'approve');
            formData.append('doctor_notes', notes);
            formData.append('permit_file', permitFile);
            formData.append('ajax', 'true');
            
            fetch('medical_history.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    closeApproveModal();
                    // Reload the lab requests
                    if (currentPatientId) {
                        loadPatientLabRequests(currentPatientId);
                    }
                    alert('Lab request approved successfully!');
                } else {
                    alert('Error: ' + result.message);
                }
            })
            .catch(error => {
                console.error('Error updating lab request:', error);
                alert('Error processing request. Please try again.');
            });
        }

        function denyLabRequest(requestId) {
            const reason = prompt('Enter denial reason:');
            if (reason !== null && reason.trim() !== '') {
                const formData = new FormData();
                formData.append('request_id', requestId);
                formData.append('action_lab_request', 'true');
                formData.append('action', 'deny');
                formData.append('denial_reason', reason);
                formData.append('ajax', 'true');
                
                fetch('medical_history.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        if (currentPatientId) {
                            loadPatientLabRequests(currentPatientId);
                        }
                    } else {
                        alert('Error: ' + result.message);
                    }
                })
                .catch(error => {
                    console.error('Error updating lab request:', error);
                    alert('Error processing request. Please try again.');
                });
            }
        }

        $(document).ready(function() {
            const modal = document.getElementById('patientDetailsModal');
            const addHistoryModal = document.getElementById('addHistoryModal');
            const addPrescriptionModal = document.getElementById('addPrescriptionModal');
            
            const closeBtn = document.getElementsByClassName('close-modal')[0];
            const closeHistoryBtn = document.getElementById('closeHistoryModal');
            const closePrescriptionBtn = document.getElementById('closePrescriptionModal');
            
            closeBtn.onclick = closePatientModal;

            closeHistoryBtn.onclick = function() {
                addHistoryModal.style.display = 'none';
                document.body.style.overflow = 'auto';
            }

            closePrescriptionBtn.onclick = function() {
                addPrescriptionModal.style.display = 'none';
                document.body.style.overflow = 'auto';
            }
            
            window.onclick = function(event) {
                if (event.target == modal) {
                    closePatientModal();
                }
                if (event.target == addHistoryModal) {
                    addHistoryModal.style.display = 'none';
                    document.body.style.overflow = 'auto';
                }
                if (event.target == addPrescriptionModal) {
                    addPrescriptionModal.style.display = 'none';
                    document.body.style.overflow = 'auto';
                }
            }

            // Handle tab clicks
            $('.tab-btn').click(function() {
                $('.tab-btn').removeClass('active');
                $(this).addClass('active');
                
                const tabName = $(this).data('tab');
                switchTab(tabName);
            });

            // Add click handler to all view details buttons
            $('.view-details').click(function() {
                const patientCard = $(this).closest('.appointment-card');
                const patientId = patientCard.data('patient-id');
                const patientName = patientCard.data('patient-name');
                const patientAge = patientCard.data('patient-age');
                const patientGender = patientCard.data('patient-gender');
                const photoPath = patientCard.data('photo-path');
                openPatientDetails(patientId, patientName, patientAge, patientGender, photoPath);
            });

            $('.history-btn').click(function() {
                if (!currentPatientId || !currentPatientName) {
                    alert('Error: No patient selected');
                    return;
                }
                
                // Populate the patient name and ID in the history modal
                document.getElementById('historyPatientName').value = currentPatientName;
                document.getElementById('historyPatientId').value = currentPatientId;
                
                // Set today's date as default
                const today = new Date().toISOString().split('T')[0];
                document.getElementById('historyDate').value = today;
                
                // Clear previous inputs
                document.getElementById('historyFindings').value = '';
                document.getElementById('historyDiagnoses').value = '';
                
                addHistoryModal.style.display = 'block';
                document.body.style.overflow = 'hidden';
            });

            // UPDATED: Set both patient ID and name when opening prescription modal
            $('.prescription-btn').click(function() {
                if (!currentPatientId || !currentPatientName) {
                    alert('Error: No patient selected');
                    return;
                }
                
                // Set both patient ID and name
                document.getElementById('prescriptionPatientId').value = currentPatientId;
                document.getElementById('prescriptionPatientName').value = currentPatientName;
                
                document.getElementById('addPrescriptionModal').style.display = 'block';
                document.body.style.overflow = 'hidden';
            });

            $('#cancelHistoryBtn').click(function() {
                addHistoryModal.style.display = 'none';
                document.body.style.overflow = 'auto';
            });

            $('#cancelPrescriptionBtn').click(function() {
                addPrescriptionModal.style.display = 'none';
                document.body.style.overflow = 'auto';
            });

            // Add form submit handlers
            document.getElementById('prescriptionForm').addEventListener('submit', handlePrescriptionSubmit);
            document.getElementById('medicalHistoryForm').addEventListener('submit', handleMedicalHistorySubmit);
        });
    </script>
</body>
</html>
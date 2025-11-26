<?php
session_start();

// Check if user is logged in, if not redirect to login page
if (!isset($_SESSION['nurse_id'])) {
    header("Location: login.php");
    exit;
}

// Database connection
$conn = mysqli_connect("localhost", "root", "", "patient_management");

// Check connection
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Get logged-in user information
$nurse_id = $_SESSION['nurse_id'];

// Fetch nurse details from database
$stmt = mysqli_prepare($conn, "SELECT first_name, last_name, role FROM nurse WHERE nurse_id = ?");
mysqli_stmt_bind_param($stmt, "i", $nurse_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$nurse = mysqli_fetch_assoc($result);

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

// Handle AJAX request for appointment history
if (isset($_GET['action']) && $_GET['action'] == 'get_appointment_history' && isset($_GET['patient_id'])) {
    $patient_id = mysqli_real_escape_string($conn, $_GET['patient_id']);
    
    // Get patient name
    $patient_sql = "SELECT first_name, last_name FROM patients WHERE id = ?";
    $patient_stmt = mysqli_prepare($conn, $patient_sql);
    mysqli_stmt_bind_param($patient_stmt, "i", $patient_id);
    mysqli_stmt_execute($patient_stmt);
    $patient_result = mysqli_stmt_get_result($patient_stmt);
    $patient = mysqli_fetch_assoc($patient_result);
    $patient_name = $patient ? $patient['first_name'] . ' ' . $patient['last_name'] : 'Unknown Patient';
    
    // Get appointment history
    $sql = "SELECT appointment_date, appointment_time,appointment_type 
            FROM appointments 
            WHERE patientname = ? 
            ORDER BY appointment_date DESC, appointment_time DESC";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "s", $patient_name);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if (mysqli_num_rows($result) > 0) {
        echo '<div class="table-responsive">';
        echo '<table class="table table-striped table-bordered appointment-table">';
        echo '<thead class="table-dark">';
        echo '<tr>';
        echo '<th>Appointment Date</th>';
        echo '<th>Appointment Time</th>';
        echo '<th>Appointment Type</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        while ($row = mysqli_fetch_assoc($result)) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($row['appointment_date']) . '</td>';
            echo '<td>' . htmlspecialchars($row['appointment_time']) . '</td>';
            echo '<td>' . htmlspecialchars($row['appointment_type']) . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    } else {
        echo '<div class="alert alert-info text-center">';
        echo '<i class="bi bi-calendar-x" style="font-size: 2rem;"></i>';
        echo '<h5 class="mt-2">No Appointments Found</h5>';
        echo '<p class="mb-0">This patient has no appointment history.</p>';
        echo '</div>';
    }
    
    mysqli_stmt_close($stmt);
    exit;
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $first_name = mysqli_real_escape_string($conn, $_POST['first_name']);
    $last_name = mysqli_real_escape_string($conn, $_POST['last_name']);
    $age = mysqli_real_escape_string($conn, $_POST['age']);
    $gender = mysqli_real_escape_string($conn, $_POST['gender']);
    $phone_number = mysqli_real_escape_string($conn, $_POST['phone']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $address = mysqli_real_escape_string($conn, $_POST['address']);
    $password_hash = password_hash($_POST['password'], PASSWORD_DEFAULT);

    // Handle file upload: store image in database as a base64 data URI (no filesystem folder)
    $photo_path = "";
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] == 0) {
        // Read uploaded file contents
        $tmpPath = $_FILES['photo']['tmp_name'];
        $fileContents = file_get_contents($tmpPath);
        if ($fileContents !== false) {
            // Detect MIME type
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $tmpPath);
            finfo_close($finfo);
            // Encode as base64 data URI so it can be stored in a text column and displayed directly
            $base64 = base64_encode($fileContents);
            $photo_path = 'data:' . $mime . ';base64,' . $base64;
        }
    }

    $sql = "INSERT INTO patients (first_name, last_name, age, gender, phone_number, email, address, 
              password_hash, photo_path) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "sssssssss", 
        $first_name, $last_name, $age, $gender, $phone_number, $email, $address, 
        $password_hash, $photo_path);
    
    if (mysqli_stmt_execute($stmt)) {
        echo "<script>alert('Patient registered successfully!');</script>";
    } else {
        echo "<script>alert('Error: " . mysqli_error($conn) . "');</script>";
    }
    
    mysqli_stmt_close($stmt);
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Patient Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="patients.css" />

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
            <li class="active"><a href="patients.php"><i class="bi bi-people"></i> Patients</span></a></li>
            <li><a href="appointments.php"><i class="bi bi-calendar-event"></i> Appointments</span></a></li>
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
        <div class="page-header d-flex align-items-center justify-content-between">
            <div>
                <h2>Patient Management</h2>
                <div class="small-muted">Manage patient records and registrations across all clinics</div>
            </div>
    </div><br>

        <div class="controls d-flex align-items-center justify-content-between my-3">
            <div class="search-wrap">
                <i class="bi bi-search"></i>
                <input type="text" id="search-input" placeholder="Search by name, phone, email, or address..." />
            </div>
            <div class="d-flex gap-2">
                <button id="add-patient-btn" class="btn btn-dark">+ Add Patient</button>
            </div>
        </div>

        <div class="patient-list">
            <?php
            // Fetch patients from DB and display them (show uploaded photo if present)
            $result = mysqli_query($conn, "SELECT * FROM patients ORDER BY id DESC LIMIT 50");
            if ($result && mysqli_num_rows($result) > 0) {
                while ($row = mysqli_fetch_assoc($result)) {
                    $fullName = htmlspecialchars($row['first_name'] . ' ' . $row['last_name']);
                    $statusBadge = 'active';
                    $clinicChip = isset($row['clinic']) ? htmlspecialchars($row['clinic']) : '';
                    $phone = isset($row['phone_number']) ? htmlspecialchars($row['phone_number']) : '';
                    $email = isset($row['email']) ? htmlspecialchars($row['email']) : '';
                    $photoSrc = '';
                    if (!empty($row['photo_path'])) {
                        $photoSrc = $row['photo_path']; // data URI or path
                    }
                    echo '<div class="patient-card">';
                    echo '  <div class="patient-top d-flex justify-content-between">';
                    echo '    <div style="display:flex;gap:12px;align-items:center">';
                    if ($photoSrc) {
                        echo '<div style="width:72px;height:72px;overflow:hidden;border-radius:8px;flex-shrink:0"><img src="' . $photoSrc . '" alt="' . $fullName . '" style="width:100%;height:100%;object-fit:cover;"/></div>';
                    }
                    echo '      <div>';
                    echo '        <div class="patient-name">' . $fullName . ' <span class="badge bg-dark">' . $statusBadge . '</span>' . (!empty($clinicChip) ? ' <span class="chip">' . $clinicChip . '</span>' : '') . '</div>';
                    echo '        <div class="muted"><i class="bi bi-telephone"></i> ' . $phone . ' &nbsp; <i class="bi bi-envelope"></i> ' . $email . ' &nbsp; <i class="bi bi-calendar"></i> Last visit: --</div>';
                    echo '        <div class="muted"><i class="bi bi-geo-alt"></i> ' . htmlspecialchars($row['address']) . '</div>';
                    echo '      </div>';
                    echo '    </div>';
                    echo '    <div class="card-actions d-flex flex-column align-items-end gap-2">';
                    echo '      <button class="btn btn-outline-secondary btn-sm schedule-btn" data-patient-id="' . $row['id'] . '" data-patient-name="' . $fullName . '"><i class="bi bi-calendar-plus"></i> Schedule</button>';
                    echo '    </div>';
                    echo '  </div>';
                    echo '</div>';
                }
            } else {
                echo '<div class="alert alert-light">No patients found.</div>';
            }
            ?>
        </div>
    </main>
</div>

<!-- Registration Modal -->
<div id="register-modal" class="modal-overlay" aria-hidden="true">
    <div class="modal-card" style="max-height: 80vh; overflow-y: auto;">
        <div class="modal-header d-flex align-items-center justify-content-between">
            <div>
                <h5 style="margin:0">Patient Registration</h5>
                <div class="small-muted">Personal Information</div>
            </div>
            <button id="close-register" class="btn btn-light btn-sm"><i class="bi bi-x-lg"></i></button>
        </div>
        <form id="register-form" class="modal-form" method="POST" enctype="multipart/form-data">
            <div class="form-grid">
                <!-- Centered Photo Upload -->
                <div class="form-group full d-flex flex-column align-items-center mb-4">
                    <label for="photo" class="mb-2 fw-bold fs-5">Upload Photo</label>
                        <div id="photo-preview" style="width:120px;height:120px;background:#f3f3f3;overflow:hidden;display:flex;align-items:center;justify-content:center;margin-bottom:10px;border:1px solid #ccc;">
                            <img id="preview-img" src="https://via.placeholder.com/120?text=Photo" alt="Preview" style="width:100%;height:100%;object-fit:cover;display:block;" />
                    </div>
                    <input type="file" id="photo" name="photo" accept="image/*" class="form-control mb-2" style="max-width: 250px;" />
                    <div id="photo-error" class="invalid-feedback text-center"></div>
                </div>
                <!-- First/Last Name Row -->
                <div class="row mb-3">
                    <div class="col-md-6 form-group">
                        <label class="fw-bold">First Name</label>
                        <input type="text" name="first_name" placeholder="Enter first name" class="form-control" required />
                        <div id="first-name-error" class="invalid-feedback">Please enter letters only for first name</div>
                    </div>
                    <div class="col-md-6 form-group">
                        <label class="fw-bold">Last Name</label>
                        <input type="text" name="last_name" placeholder="Enter last name" class="form-control" required />
                        <div id="last-name-error" class="invalid-feedback">Please enter letters only for last name</div>
                    </div>
                </div>
                <!-- Age/Gender Row -->
                <div class="row mb-3">
                    <div class="col-md-6 form-group">
                        <label class="fw-bold">Age</label>
                        <input type="number" name="age" placeholder="Enter age" class="form-control" required />
                        <div id="age-error" class="invalid-feedback">Please enter a valid age (1-120)</div>
                    </div>
                    <div class="col-md-6 form-group">
                        <label class="fw-bold">Gender</label>
                        <select name="gender" class="form-control" required>
                            <option value="">Select Gender</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                            <option value="Other">Other</option>
                        </select>
                        <div id="gender-error" class="invalid-feedback">Please select a gender</div>
                    </div>
                </div>
                <!-- Phone/Email Row -->
                <div class="row mb-3">
                    <div class="col-md-6 form-group">
                        <label class="fw-bold">Phone Number</label>
                        <input type="text" name="phone" placeholder="(+63) 9451648923" class="form-control" required />
                        <div id="phone-error" class="invalid-feedback">Please enter a valid 11-digit phone number</div>
                    </div>
                    <div class="col-md-6 form-group">
                        <label class="fw-bold">Email Address</label>
                        <input type="email" name="email" placeholder="patient@email.com" class="form-control" required />
                        <div id="email-error" class="invalid-feedback">Please enter a valid Gmail address</div>
                    </div>
                </div>
                <!-- Address -->
                <div class="form-group full mb-3">
                    <label class="fw-bold">Address</label>
                    <textarea name="address" placeholder="Enter full address" class="form-control" required></textarea>
                    <div id="address-error" class="invalid-feedback">Please enter an address</div>
                </div>

                <!-- Password -->
                <div class="form-group full mb-3">
                    <label class="fw-bold">Password</label>
                    <input type="password" name="password" placeholder="Enter password" class="form-control" required />
                    <div id="password-error" class="invalid-feedback">Password must be at least 8 characters long</div>
                </div>
            </div>
            
            <!-- Error Alert Box -->
            <div id="form-errors" class="alert alert-danger d-none" role="alert">
                <strong>Please fix the following errors:</strong>
                <ul id="error-list" class="mb-0"></ul>
            </div>
            
            <div class="mt-3 d-flex justify-content-end gap-2">
                <button type="button" id="cancel-register" class="btn btn-outline-secondary">Cancel</button>
                <button type="submit" class="btn btn-dark">Register Patient</button>
            </div>
        </form>
    </div>
</div>

<!-- Schedule Modal -->
<div id="schedule-modal" class="modal-overlay" aria-hidden="true">
    <div class="modal-card" style="max-width: 900px; max-height: 80vh;">
        <div class="modal-header d-flex align-items-center justify-content-between">
            <div>
                <h5 style="margin:0" id="schedule-modal-title">Appointment History</h5>
                <div class="small-muted" id="schedule-modal-subtitle"></div>
            </div>
            <button id="close-schedule" class="btn btn-light btn-sm"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="modal-body">
            <div id="appointment-history">
                <!-- Appointment table will be loaded here via AJAX -->
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2">Loading appointment history...</p>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" id="close-schedule-footer" class="btn btn-outline-secondary">Close</button>
        </div>
    </div>
</div>

<script>
// Sign out function
function signOut() {
    // Redirect to logout handler which destroys session
    window.location.href = 'nurse_dash.php?logout=true';
}

document.addEventListener('DOMContentLoaded', function(){
    // Real-time search functionality
    const searchInput = document.getElementById('search-input');
    const patientCards = document.querySelectorAll('.patient-card');

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase().trim();
            let visibleCount = 0;
            
            patientCards.forEach(card => {
                const cardText = card.textContent.toLowerCase();
                
                if (searchTerm === '' || cardText.includes(searchTerm)) {
                    card.style.display = 'block';
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            });

            // Show message if no results found
            const patientList = document.querySelector('.patient-list');
            let noResultsMessage = document.querySelector('.no-results-message');
            
            if (visibleCount === 0 && searchTerm !== '') {
                if (!noResultsMessage) {
                    noResultsMessage = document.createElement('div');
                    noResultsMessage.className = 'alert alert-light no-results-message';
                    noResultsMessage.textContent = `No patients found matching "${searchTerm}"`;
                    patientList.appendChild(noResultsMessage);
                } else {
                    noResultsMessage.textContent = `No patients found matching "${searchTerm}"`;
                    noResultsMessage.style.display = 'block';
                }
            } else if (noResultsMessage) {
                noResultsMessage.style.display = 'none';
            }
        });

        // Clear search when page loads
        searchInput.value = '';
    }

    // Validation functions
    const validationRules = {
        firstName: {
            validate: (value) => /^[A-Za-z\s]+$/.test(value.trim()),
            message: 'First name should contain letters only'
        },
        lastName: {
            validate: (value) => /^[A-Za-z\s]+$/.test(value.trim()),
            message: 'Last name should contain letters only'
        },
        age: {
            validate: (value) => {
                const age = parseInt(value);
                return !isNaN(age) && age >= 1 && age <= 120;
            },
            message: 'Age must be a number between 1 and 120'
        },
        phone: {
            validate: (value) => {
                // Remove non-digit characters and check if it's 11 digits
                const digits = value.replace(/\D/g, '');
                return digits.length === 11 && /^\d+$/.test(digits);
            },
            message: 'Phone number must be 11 digits'
        },
        email: {
            validate: (value) => {
                const emailRegex = /^[a-zA-Z0-9._%+-]+@gmail\.com$/;
                return emailRegex.test(value.trim());
            },
            message: 'Email must be a valid Gmail address (ending with @gmail.com)'
        },
        password: {
            validate: (value) => value.length >= 8,
            message: 'Password must be at least 8 characters long'
        },
        gender: {
            validate: (value) => value !== '',
            message: 'Please select a gender'
        },
        address: {
            validate: (value) => value.trim().length > 0,
            message: 'Please enter an address'
        }
    };

    // Form elements
    const form = document.getElementById('register-form');
    const errorAlert = document.getElementById('form-errors');
    const errorList = document.getElementById('error-list');

    // Setup real-time validation for all fields
    const fields = {
        firstName: document.querySelector('input[name="first_name"]'),
        lastName: document.querySelector('input[name="last_name"]'),
        age: document.querySelector('input[name="age"]'),
        phone: document.querySelector('input[name="phone"]'),
        email: document.querySelector('input[name="email"]'),
        password: document.querySelector('input[name="password"]'),
        gender: document.querySelector('select[name="gender"]'),
        address: document.querySelector('textarea[name="address"]')
    };

    function hideErrorAlert() {
        errorAlert.classList.add('d-none');
        errorList.innerHTML = '';
    }

    function showErrorAlert(errors) {
        errorList.innerHTML = '';
        errors.forEach(error => {
            const li = document.createElement('li');
            li.textContent = error;
            errorList.appendChild(li);
        });
        errorAlert.classList.remove('d-none');
        
        // Scroll to error alert
        errorAlert.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    // Form submission handler
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const errors = [];
            
            // First, check for empty required fields
            Object.keys(fields).forEach(fieldName => {
                if (fields[fieldName]) {
                    const value = fields[fieldName].value.trim();
                    const isEmpty = value === '';
                    
                    if (isEmpty) {
                        // Show empty field error with different message
                        let emptyMessage = '';
                        switch(fieldName) {
                            case 'firstName':
                                emptyMessage = 'Please enter first name';
                                break;
                            case 'lastName':
                                emptyMessage = 'Please enter last name';
                                break;
                            case 'age':
                                emptyMessage = 'Please enter age';
                                break;
                            case 'phone':
                                emptyMessage = 'Please enter phone number';
                                break;
                            case 'email':
                                emptyMessage = 'Please enter email address';
                                break;
                            case 'password':
                                emptyMessage = 'Please enter password';
                                break;
                            case 'gender':
                                emptyMessage = 'Please select a gender';
                                break;
                            case 'address':
                                emptyMessage = 'Please enter address';
                                break;
                            default:
                                emptyMessage = 'This field is required';
                        }
                        errors.push(emptyMessage);
                        fields[fieldName].classList.add('is-invalid');
                    } else {
                        // Field is not empty, validate its content
                        const isValid = validationRules[fieldName].validate(value);
                        if (!isValid) {
                            errors.push(validationRules[fieldName].message);
                            fields[fieldName].classList.add('is-invalid');
                        } else {
                            fields[fieldName].classList.remove('is-invalid');
                            fields[fieldName].classList.add('is-valid');
                        }
                    }
                }
            });
            
            // Validate photo (optional field)
            const photoInput = document.getElementById('photo');
            if (photoInput && photoInput.files.length > 0) {
                const file = photoInput.files[0];
                if (!file.type.startsWith('image/')) {
                    errors.push('Please upload a valid image file');
                    document.getElementById('photo-error').textContent = 'Please upload a valid image file';
                    document.getElementById('photo-error').style.display = 'block';
                } else {
                    document.getElementById('photo-error').style.display = 'none';
                }
            }
            
            if (errors.length === 0) {
                // All validations passed, submit the form
                this.submit();
            } else {
                // Show error alert
                showErrorAlert(errors);
                
                // Focus on first invalid field
                const firstInvalidField = document.querySelector('.is-invalid');
                if (firstInvalidField) {
                    firstInvalidField.focus();
                }
            }
        });
    }

    // Add real-time validation for fields
    Object.keys(fields).forEach(fieldName => {
        if (fields[fieldName]) {
            fields[fieldName].addEventListener('input', function() {
                const value = this.value.trim();
                if (value !== '') {
                    const isValid = validationRules[fieldName].validate(value);
                    if (isValid) {
                        this.classList.remove('is-invalid');
                        this.classList.add('is-valid');
                    } else {
                        this.classList.remove('is-valid');
                        this.classList.add('is-invalid');
                    }
                } else {
                    this.classList.remove('is-valid', 'is-invalid');
                }
                hideErrorAlert();
            });
        }
    });

    // Special handling for gender select
    if (fields.gender) {
        fields.gender.addEventListener('change', function() {
            if (this.value !== '') {
                this.classList.remove('is-invalid');
                this.classList.add('is-valid');
            } else {
                this.classList.remove('is-valid');
                this.classList.add('is-invalid');
            }
            hideErrorAlert();
        });
    }

    // Reset form validation when modal is closed/opened
    function resetFormValidation() {
        Object.keys(fields).forEach(fieldName => {
            if (fields[fieldName]) {
                fields[fieldName].classList.remove('is-valid', 'is-invalid');
            }
        });
        hideErrorAlert();
        
        // Reset photo error
        const photoError = document.getElementById('photo-error');
        if (photoError) {
            photoError.style.display = 'none';
        }
    }

    // Registration modal functionality
    const openBtn = document.getElementById('open-register');
    const addPatientBtn = document.getElementById('add-patient-btn');
    const overlay = document.getElementById('register-modal');
    const closeBtn = document.getElementById('close-register');
    const cancelBtn = document.getElementById('cancel-register');
    
    function openModal() {
        resetFormValidation(); // Reset form when opening modal
        overlay.classList.add('open');
        overlay.setAttribute('aria-hidden','false');
    }
    
    if(openBtn && overlay){
        openBtn.addEventListener('click', openModal);
    }
    if(addPatientBtn && overlay){
        addPatientBtn.addEventListener('click', openModal);
    }
    if(closeBtn){ 
        closeBtn.addEventListener('click', function(){ 
            overlay.classList.remove('open'); 
            overlay.setAttribute('aria-hidden','true'); 
            resetFormValidation();
        }); 
    }
    if(cancelBtn){ 
        cancelBtn.addEventListener('click', function(){ 
            overlay.classList.remove('open'); 
            overlay.setAttribute('aria-hidden','true'); 
            resetFormValidation();
        }); 
    }
    if(overlay){ 
        overlay.addEventListener('click', function(e){ 
            if(e.target === overlay){ 
                overlay.classList.remove('open'); 
                overlay.setAttribute('aria-hidden','true'); 
                resetFormValidation();
            } 
        }); 
    }

    // Photo preview logic
    const photoInput = document.getElementById('photo');
    const previewImg = document.getElementById('preview-img');
    if(photoInput && previewImg){
        photoInput.addEventListener('change', function(e){
            const file = e.target.files[0];
            if(file && file.type.startsWith('image/')){
                const reader = new FileReader();
                reader.onload = function(ev){
                    previewImg.src = ev.target.result;
                };
                reader.readAsDataURL(file);
            } else {
                previewImg.src = 'https://via.placeholder.com/120?text=Photo';
            }
        });
    }

    // Schedule modal functionality
    const scheduleModal = document.getElementById('schedule-modal');
    const closeScheduleBtn = document.getElementById('close-schedule');
    const closeScheduleFooterBtn = document.getElementById('close-schedule-footer');
    const scheduleButtons = document.querySelectorAll('.schedule-btn');

    function openScheduleModal(patientId, patientName) {
        // Update modal title
        document.getElementById('schedule-modal-title').textContent = 'Appointment History - ' + patientName;
        document.getElementById('schedule-modal-subtitle').textContent = 'View all scheduled appointments for this patient';
        
        // Show loading state
        document.getElementById('appointment-history').innerHTML = `
            <div class="text-center py-4">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <p class="mt-2">Loading appointment history...</p>
            </div>
        `;
        
        // Open modal
        scheduleModal.classList.add('open');
        scheduleModal.setAttribute('aria-hidden','false');
        
        // Load appointment history via AJAX
        fetch('patients.php?action=get_appointment_history&patient_id=' + patientId)
            .then(response => response.text())
            .then(data => {
                document.getElementById('appointment-history').innerHTML = data;
            })
            .catch(error => {
                document.getElementById('appointment-history').innerHTML = `
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle"></i>
                        <h5>Error Loading Appointment History</h5>
                        <p>Unable to load appointment data. Please try again.</p>
                        <small>Error: ${error}</small>
                    </div>
                `;
            });
    }

    // Add event listeners to all schedule buttons
    scheduleButtons.forEach(button => {
        button.addEventListener('click', function() {
            const patientId = this.getAttribute('data-patient-id');
            const patientName = this.getAttribute('data-patient-name');
            openScheduleModal(patientId, patientName);
        });
    });

    // Close schedule modal handlers
    if(closeScheduleBtn) {
        closeScheduleBtn.addEventListener('click', function() {
            scheduleModal.classList.remove('open');
            scheduleModal.setAttribute('aria-hidden','true');
        });
    }

    if(closeScheduleFooterBtn) {
        closeScheduleFooterBtn.addEventListener('click', function() {
            scheduleModal.classList.remove('open');
            scheduleModal.setAttribute('aria-hidden','true');
        });
    }
    
    if(scheduleModal) {
        scheduleModal.addEventListener('click', function(e) {
            if(e.target === scheduleModal) {
                scheduleModal.classList.remove('open');
                scheduleModal.setAttribute('aria-hidden','true');
            }
        });
    }

    // Add event listener for dynamically created schedule buttons (if any)
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('schedule-btn') || e.target.closest('.schedule-btn')) {
            const button = e.target.classList.contains('schedule-btn') ? e.target : e.target.closest('.schedule-btn');
            const patientId = button.getAttribute('data-patient-id');
            const patientName = button.getAttribute('data-patient-name');
            openScheduleModal(patientId, patientName);
        }
    });
});
</script>

</body>
</html>
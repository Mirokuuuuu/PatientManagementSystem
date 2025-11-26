<?php
session_start();

// Handle sign-out request
if (isset($_GET['logout']) && $_GET['logout'] == 'true') {
    session_destroy();
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
$doctor_id = isset($_SESSION['doctor_id']) ? $_SESSION['doctor_id'] : null;

// Initialize variables
$success_message = '';
$error_message = '';

// Fetch doctor details from database if logged in
if ($doctor_id) {
    $stmt = mysqli_prepare($conn, "SELECT first_name, last_name, email, phone, department FROM doctor WHERE doctor_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $doctor_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $doctor = mysqli_fetch_assoc($result);
    
    if ($doctor) {
        $user_name = 'Dr. ' . $doctor['first_name'] . ' ' . $doctor['last_name'];
        $user_role = $doctor['department'] ?? 'Doctor';
        $user_email = $doctor['email'];
        $user_phone = isset($doctor['phone']) ? $doctor['phone'] : '';
        $user_first_name = $doctor['first_name'];
        $user_last_name = $doctor['last_name'];
    } else {
        $user_name = 'Guest Doctor';
        $user_role = 'Doctor';
        $user_email = '';
        $user_phone = '';
        $user_first_name = '';
        $user_last_name = '';
    }
    mysqli_stmt_close($stmt);
} else {
    $user_name = 'Guest Doctor';
    $user_role = 'Doctor';
    $user_email = '';
    $user_phone = '';
    $user_first_name = '';
    $user_last_name = '';
}

// Handle profile update
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_profile'])) {
    $first_name = mysqli_real_escape_string($conn, $_POST['first_name']);
    $last_name = mysqli_real_escape_string($conn, $_POST['last_name']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $phone = mysqli_real_escape_string($conn, $_POST['phone']);
    
    // Check if email already exists for another user
    $check_stmt = mysqli_prepare($conn, "SELECT doctor_id FROM doctor WHERE email = ? AND doctor_id != ?");
    mysqli_stmt_bind_param($check_stmt, "si", $email, $doctor_id);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);
    
    if (mysqli_num_rows($check_result) > 0) {
        $error_message = "Email already exists for another user.";
    } else {
        // Update profile
        $update_stmt = mysqli_prepare($conn, "UPDATE doctor SET first_name = ?, last_name = ?, email = ?, phone = ? WHERE doctor_id = ?");
        mysqli_stmt_bind_param($update_stmt, "ssssi", $first_name, $last_name, $email, $phone, $doctor_id);
        
        if (mysqli_stmt_execute($update_stmt)) {
            $success_message = "Profile updated successfully!";
            // Update session variables if needed
            $_SESSION['doctor_name'] = $first_name . ' ' . $last_name;
            
            // Refresh user data
            $user_first_name = $first_name;
            $user_last_name = $last_name;
            $user_email = $email;
            $user_phone = $phone;
            $user_name = 'Dr. ' . $first_name . ' ' . $last_name;
        } else {
            $error_message = "Error updating profile: " . mysqli_error($conn);
        }
        mysqli_stmt_close($update_stmt);
    }
    mysqli_stmt_close($check_stmt);
}

// Handle password change
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Fetch current password hash
    $password_stmt = mysqli_prepare($conn, "SELECT password_hash FROM doctor WHERE doctor_id = ?");
    mysqli_stmt_bind_param($password_stmt, "i", $doctor_id);
    mysqli_stmt_execute($password_stmt);
    $password_result = mysqli_stmt_get_result($password_stmt);
    $password_data = mysqli_fetch_assoc($password_result);
    
    if ($password_data && password_verify($current_password, $password_data['password_hash'])) {
        if ($new_password === $confirm_password) {
            if (strlen($new_password) >= 6) {
                $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $update_password_stmt = mysqli_prepare($conn, "UPDATE doctor SET password_hash = ? WHERE doctor_id = ?");
                mysqli_stmt_bind_param($update_password_stmt, "si", $new_password_hash, $doctor_id);
                
                if (mysqli_stmt_execute($update_password_stmt)) {
                    $success_message = "Password changed successfully!";
                } else {
                    $error_message = "Error changing password: " . mysqli_error($conn);
                }
                mysqli_stmt_close($update_password_stmt);
            } else {
                $error_message = "New password must be at least 6 characters long.";
            }
        } else {
            $error_message = "New passwords do not match.";
        }
    } else {
        $error_message = "Current password is incorrect.";
    }
    mysqli_stmt_close($password_stmt);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doctor Settings - Pamantasan ng Lungsod ng Pasig</title>
    <link rel="stylesheet" href="doctor_settings.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
</head>
<body>
    <div class="app">
        <!-- Sidebar -->
        <aside class="sidebar">
            <div class="logo">
                <h2>Pamantasan ng Lungsod ng Pasig</h2>
                <p>Patient Management</p>
            </div>
            
            <div class="search-bar">
                <i class="fas fa-search"></i>
                <input type="text" placeholder="Search patients, appointments...">
            </div>

            <nav>
                <a href="doctor_dash.php"><i class="fas fa-th-large"></i> Dashboard</a>
                <a href="patients_appointments.php"><i class="fas fa-calendar-check"></i> Patient Appointments</a>
                <a href="medical_history.php"><i class="fas fa-file-medical"></i> Medical History</a>
                <a href="doctor_settings.php" class="active"><i class="fas fa-cog"></i> Settings</a>
            </nav>
        </aside>
    </div>

    <div class="content-wrapper">
        <header class="topbar">
            <div style="display:flex;align-items:center;gap:12px">
            </div>
            <div style="display:flex;align-items:center;gap:14px">
                <div class="small-muted"><?php echo htmlspecialchars($user_name); ?><br><small class="small-muted">Department: <?php echo htmlspecialchars($user_role); ?></small></div>
                <button class="btn btn-outline-dark btn-sm" onclick="signOut()">Sign Out</button>
            </div>
        </header>

        <div class="settings-header">
            <h1>Settings</h1>
            <p class="subtitle">Manage your account and preferences</p>
        </div>

        <!-- Success and Error Messages -->
        <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo $success_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo $error_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="settings-tabs">
            <button class="settings-tab active" onclick="showTab('profile')">Profile</button>
            <button class="settings-tab" onclick="showTab('password')">Password</button>
        </div>

        <!-- Profile Tab -->
        <div id="profile-tab" class="tab-content active">
            <div class="settings-card">
                <form method="POST" class="profile-form">
                    <input type="hidden" name="update_profile" value="1">
                    
                    <!-- Profile Picture Section -->
                    <div class="profile-picture-section">
                        <div class="profile-avatar">
                            <?php echo strtoupper(substr($user_first_name, 0, 1) . substr($user_last_name, 0, 1)); ?>
                        </div>
                        <div class="profile-actions">
                            <button type="button" class="btn">Upload New</button>
                            <button type="button" class="btn btn-outline">Remove</button>
                        </div>
                    </div>

                    <!-- Name Fields -->
                    <div class="form-row">
                        <div class="form-group">
                            <label for="first_name" class="form-label">First Name</label>
                            <input type="text" id="first_name" name="first_name" class="form-input" value="<?php echo htmlspecialchars($user_first_name); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="last_name" class="form-label">Last Name</label>
                            <input type="text" id="last_name" name="last_name" class="form-input" value="<?php echo htmlspecialchars($user_last_name); ?>" required>
                        </div>
                    </div>

                    <!-- Email Field -->
                    <div class="form-group">
                        <label for="email" class="form-label">Email Address</label>
                        <input type="email" id="email" name="email" class="form-input" value="<?php echo htmlspecialchars($user_email); ?>" required>
                    </div>

                    <!-- Phone Field -->
                    <div class="form-group">
                        <label for="phone" class="form-label">Phone Number</label>
                        <input type="tel" id="phone" name="phone" class="form-input" value="<?php echo htmlspecialchars($user_phone); ?>">
                    </div>

                    <!-- Save Button -->
                    <button type="submit" class="save-btn">Save Profile Changes</button>
                </form>
            </div>
        </div>

        <!-- Password Tab -->
        <div id="password-tab" class="tab-content">
            <div class="settings-card">
                <form method="POST" class="profile-form">
                    <input type="hidden" name="change_password" value="1">
                    
                    <div class="form-group">
                        <label for="current_password" class="form-label">Current Password</label>
                        <input type="password" id="current_password" name="current_password" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="new_password" class="form-label">New Password</label>
                        <input type="password" id="new_password" name="new_password" class="form-input" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="confirm_password" class="form-label">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-input" required>
                    </div>
                    
                    <button type="submit" class="save-btn">Change Password</button>
                </form>
            </div>
        </div>
    </div>

    <script>
        function showTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('.settings-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tabName + '-tab').classList.add('active');
            
            // Add active class to clicked tab
            event.target.classList.add('active');
        }

        // Add some basic interactivity for profile picture buttons
        document.querySelector('.btn-outline').addEventListener('click', function() {
            if (confirm('Are you sure you want to remove your profile picture?')) {
                // Add remove profile picture logic here
                console.log('Profile picture removed');
            }
        });

        document.querySelector('.btn').addEventListener('click', function() {
            // Add upload profile picture logic here
            console.log('Upload profile picture');
        });

        function signOut() {
            // Redirect to logout handler which destroys session
            window.location.href = 'doctor_settings.php?logout=true';
        }
    </script>
</body>
</html>
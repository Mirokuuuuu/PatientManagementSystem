<?php
session_start();

// Database connection
$conn = mysqli_connect("localhost", "root", "", "patient_management");

// Check connection
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Get logged-in user information
$nurse_id = isset($_SESSION['nurse_id']) ? $_SESSION['nurse_id'] : null;

// Initialize variables
$success_message = '';
$error_message = '';

// Fetch nurse details from database if logged in
if ($nurse_id) {
    $stmt = mysqli_prepare($conn, "SELECT first_name, last_name, role, email, phone FROM nurse WHERE nurse_id = ?");
    mysqli_stmt_bind_param($stmt, "i", $nurse_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $nurse = mysqli_fetch_assoc($result);
    
    if ($nurse) {
        $user_name = $nurse['first_name'] . ' ' . $nurse['last_name'];
        $user_role = ucfirst($nurse['role']);
        $user_email = $nurse['email'];
        $user_phone = isset($nurse['phone']) ? $nurse['phone'] : '';
        $user_first_name = $nurse['first_name'];
        $user_last_name = $nurse['last_name'];
    } else {
        $user_name = 'Guest User';
        $user_role = 'Nurse';
        $user_email = '';
        $user_phone = '';
        $user_first_name = '';
        $user_last_name = '';
    }
    mysqli_stmt_close($stmt);
} else {
    $user_name = 'Guest User';
    $user_role = 'Nurse';
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
    $check_stmt = mysqli_prepare($conn, "SELECT nurse_id FROM nurse WHERE email = ? AND nurse_id != ?");
    mysqli_stmt_bind_param($check_stmt, "si", $email, $nurse_id);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);
    
    if (mysqli_num_rows($check_result) > 0) {
        $error_message = "Email already exists for another user.";
    } else {
        // Update profile
        $update_stmt = mysqli_prepare($conn, "UPDATE nurse SET first_name = ?, last_name = ?, email = ?, phone = ? WHERE nurse_id = ?");
        mysqli_stmt_bind_param($update_stmt, "ssssi", $first_name, $last_name, $email, $phone, $nurse_id);
        
        if (mysqli_stmt_execute($update_stmt)) {
            $success_message = "Profile updated successfully!";
            // Update session variables if needed
            $_SESSION['user_name'] = $first_name . ' ' . $last_name;
            
            // Refresh user data
            $user_first_name = $first_name;
            $user_last_name = $last_name;
            $user_email = $email;
            $user_phone = $phone;
            $user_name = $first_name . ' ' . $last_name;
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
    $password_stmt = mysqli_prepare($conn, "SELECT password_hash FROM nurse WHERE nurse_id = ?");
    mysqli_stmt_bind_param($password_stmt, "i", $nurse_id);
    mysqli_stmt_execute($password_stmt);
    $password_result = mysqli_stmt_get_result($password_stmt);
    $password_data = mysqli_fetch_assoc($password_result);
    
    if ($password_data && password_verify($current_password, $password_data['password_hash'])) {
        if ($new_password === $confirm_password) {
            if (strlen($new_password) >= 6) {
                $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $update_password_stmt = mysqli_prepare($conn, "UPDATE nurse SET password_hash = ? WHERE nurse_id = ?");
                mysqli_stmt_bind_param($update_password_stmt, "si", $new_password_hash, $nurse_id);
                
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
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Settings Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="settings.css" />
<body>
<div class="dashboard-root">
    <aside class="sidebar">
        <div>
            <div class="brand">Pamantasan ng Lungsod<br><span class="small-muted">Patient Management</span></div>
            <input type="text" placeholder="Search patients, appointments" />
        </div>
        <ul class="nav-list">
            <li><a href="nurse_dash.php"><i class="bi bi-grid"></i> Dashboard</a></li>
            <li><a href="patients.php"><i class="bi bi-people"></i> Patients</a></li>
            <li><a href="appointments.php"><i class="bi bi-calendar-event"></i> Appointments</span></a></li>
            <li><a href="analytics.php"><i class="bi bi-bar-chart"></i> Analytics</a></li>
            <li class="active"><a href="settings.php"><i class="bi bi-gear"></i> Settings</a></li>
        </ul>
        
    </aside>
    <div class="settings-container">

        <header class="topbar">
                <div style="display:flex;align-items:center;gap:12px">
                </div>
                <div style="display:flex;align-items:center;gap:14px">
                    <div class="small-muted"><?php echo htmlspecialchars($user_name); ?><br><small class="small-muted">Role: <?php echo htmlspecialchars($user_role); ?></small></div>
                    <button class="btn btn-outline-dark btn-sm" onclick="window.location.href='sign_in.php'">Sign Out</button>
                </div>
            </header><br>

        <div class="settings-section">
            <div class="page-header d-flex align-items-center justify-content-between">
                <div>
                    <h2>Settings</h2>
                    <div class="small-muted">Manage your account and preferences</div>
                </div>
            </div><br>

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
    <div>
        <!-- Profile Information -->
        <form method="POST" class="profile-form" style="max-width:480px;">
            <input type="hidden" name="update_profile" value="1">
            <div class="profile-actions">
                <div class="profile-avatar"><?php echo strtoupper(substr($user_first_name, 0, 1) . substr($user_last_name, 0, 1)); ?></div>
                <button type="button" class="btn">Upload New</button>
                <button type="button" class="btn btn-outline">Remove</button>
            </div>
            <div class="profile-row">
                <div style="flex:1;">
                    <div class="profile-label">First Name</div>
                    <input class="profile-input" type="text" name="first_name" value="<?php echo htmlspecialchars($user_first_name); ?>" required />
                </div>
                <div style="flex:1;">
                    <div class="profile-label">Last Name</div>
                    <input class="profile-input" type="text" name="last_name" value="<?php echo htmlspecialchars($user_last_name); ?>" required />
                </div>
            </div>
            <div>
                <div class="profile-label">Email Address</div>
                <input class="profile-input" type="email" name="email" value="<?php echo htmlspecialchars($user_email); ?>" required />
            </div>
            <div>
                <div class="profile-label">Phone Number</div>
                <input class="profile-input" type="text" name="phone" value="<?php echo htmlspecialchars($user_phone); ?>" />
            </div>
            <button type="submit" class="save-btn">Save Profile Changes</button>
        </form>
    </div>
</div>

            <!-- Password Tab -->
            <div id="password-tab" class="tab-content">
             <div>
        <form method="POST" class="profile-form">
            <input type="hidden" name="change_password" value="1">
            <div>
                <div class="profile-label">Current Password</div>
                <input class="profile-input" type="password" name="current_password" required />
            </div>
            <div>
                <div class="profile-label">New Password</div>
                <input class="profile-input" type="password" name="new_password" required />
            </div>
            <div>
                <div class="profile-label">Confirm New Password</div>
                <input class="profile-input" type="password" name="confirm_password" required />
            </div>
            <button type="submit" class="save-btn">Change Password</button>
        </form>
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
</script>

<style>
.tab-content {
    display: none;
}
.tab-content.active {
    display: block;
}
.settings-tab {
    background: none;
    border: none;
    padding: 10px 20px;
    cursor: pointer;
    border-bottom: 2px solid transparent;
}
.settings-tab.active {
    border-bottom: 2px solid #007bff;
    color: #007bff;
}
</style>

</body>
</html>
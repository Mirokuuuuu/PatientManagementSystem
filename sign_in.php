<?php
// Database connection
$conn = new mysqli('localhost', 'root', '', 'patient_management');

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Check if form is submitted via POST
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Always respond with JSON for AJAX login requests
    header('Content-Type: application/json; charset=utf-8');

    $email = $_POST['email'];
    $password = $_POST['password'];

    // Check if it's the hardcoded admin account
    if ($email === 'admin@gmail.com' && $password === 'admin123') {
        session_start();
        $_SESSION['userRole'] = 'admin';
        $_SESSION['email'] = $email;
        echo json_encode(['success' => true, 'role' => 'admin']);
        exit;
    }

    // Check nurse credentials in database
    $sql = "SELECT nurse_id, email, password_hash, first_name, last_name, role FROM nurse WHERE email = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('Prepare failed: ' . $conn->error);
        echo json_encode(['success' => false, 'message' => 'Internal error']);
        exit;
    }
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows === 1) {
        $user = $result->fetch_assoc();
        // Verify password against stored hash
        if (isset($user['password_hash']) && password_verify($password, $user['password_hash'])) {
            // Start session securely
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
            session_regenerate_id(true);
            $_SESSION['nurse_id'] = $user['nurse_id'];
            $_SESSION['nurse_email'] = $user['email'];
            $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
            $_SESSION['user_role'] = $user['role'];

            echo json_encode(['success' => true, 'role' => 'nurse']);
            exit;
        }
    }

    // Check doctor credentials in database
    $sql = "SELECT doctor_id, email, password_hash, first_name, last_name FROM doctor WHERE email = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('Prepare failed: ' . $conn->error);
        echo json_encode(['success' => false, 'message' => 'Internal error']);
        exit;
    }
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows === 1) {
        $user = $result->fetch_assoc();
        // Verify password against stored hash
        if (isset($user['password_hash']) && password_verify($password, $user['password_hash'])) {
            // Start session securely
            session_start();
            session_regenerate_id(true);
            $_SESSION['userRole'] = 'doctor';
            $_SESSION['doctor_email'] = $user['email'];
            $_SESSION['doctor_id'] = $user['doctor_id'];
            $_SESSION['doctor_name'] = $user['first_name'] . ' ' . $user['last_name'];

            echo json_encode(['success' => true, 'role' => 'doctor']);
            exit;
        }
    }

    // Check patient credentials in database
    $sql = "SELECT id, first_name, email, password_hash FROM patients WHERE email = ? LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log('Prepare failed: ' . $conn->error);
        echo json_encode(['success' => false, 'message' => 'Internal error']);
        exit;
    }
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows === 1) {
        $user = $result->fetch_assoc();
        // Verify password against stored hash
        if (isset($user['password_hash']) && password_verify($password, $user['password_hash'])) {
            // Start session securely
            session_start();
            session_regenerate_id(true);
            $_SESSION['userRole'] = 'patient';
            $_SESSION['patient_email'] = $user['email'];
            $_SESSION['patient_name'] = isset($user['first_name']) ? $user['first_name'] : '';
            $_SESSION['patient_id'] = $user['id']; // Store the patient ID

            // Rehash if needed (keeps hashes up-to-date). Update by email.
            if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                $u = $conn->prepare("UPDATE patients SET password_hash = ? WHERE email = ?");
                if ($u) {
                    $u->bind_param('ss', $newHash, $user['email']);
                    $u->execute();
                    $u->close();
                }
            }

            echo json_encode(['success' => true, 'role' => 'patient']);
            exit;
        }
    }
    
    echo json_encode(['success' => false, 'message' => 'Invalid credentials']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MediSync - Log In</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="sign_in_css.css" />
</head>
<body>
    <div class="main-container">
        <!-- Left Section - Blue Background -->
        <div class="left-section">
            <div class="logo-content">
                <div class="logo-circle">
                    <img src="logo_2.jpg" alt="MediSync Logo" class="logo-img" />
                </div>
                <h1 class="company-name">MediSync</h1>
                <p class="company-tagline">Health Medical Center</p>
            </div>
        </div>

        <!-- Right Section - Login Form -->
        <div class="right-section">
            <div class="login-form-container">
                <h2 class="login-title">Log In</h2>
                
                <form id="signin-form" class="login-form">
                    <div class="form-group">
                        <label for="email" class="input-label">Email</label>
                        <input 
                            type="email" 
                            id="email" 
                            name="email" 
                            class="form-input"
                            value="flavieriaurence01@gmail.com"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="password" class="input-label">Password</label>
                        <div class="password-container">
                            <input 
                                type="password" 
                                id="password" 
                                name="password" 
                                class="form-input password-input"
                                placeholder="Enter your password"
                                required
                            >
                        </div>
                    </div>

                    <div class="checkbox-container">
                        <input type="checkbox" id="showPassword" class="checkbox">
                        <label for="showPassword" class="checkbox-label">Show Password</label>
                    </div>

                    <button type="submit" class="login-button" id="btn-signin">
                        LOG IN
                    </button>

                    <div class="forgot-password">
                        <a href="#" class="forgot-link">Forgot Password?</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('signin-form');
            const showPasswordCheckbox = document.getElementById('showPassword');
            const passwordInput = document.getElementById('password');

            // Toggle password visibility
            showPasswordCheckbox.addEventListener('change', function() {
                passwordInput.type = this.checked ? 'text' : 'password';
            });

            // Form submission
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                
                const email = document.getElementById('email').value.trim();
                const password = passwordInput.value.trim();

                if (!email) {
                    alert('Please enter your email.');
                    return;
                }

                if (!password) {
                    alert('Please enter your password.');
                    return;
                }

                // Basic email validation
                const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailPattern.test(email)) {
                    alert('Please enter a valid email address.');
                    return;
                }

                // Show loading state
                const submitBtn = document.getElementById('btn-signin');
                const originalText = submitBtn.textContent;
                submitBtn.textContent = 'LOGGING IN...';
                submitBtn.disabled = true;

                // Send login request
                fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `email=${encodeURIComponent(email)}&password=${encodeURIComponent(password)}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        if (data.role === 'admin') {
                            window.location.href = 'admin_nurse.php';
                        } else if (data.role === 'nurse') {
                            window.location.href = 'nurse_dash.php';
                        } else if (data.role === 'doctor') {
                            window.location.href = 'doctor_dash.php';
                        } else if (data.role === 'patient') {
                            window.location.href = 'user_dash.php';
                        }
                    } else {
                        alert(data.message || 'Invalid credentials. Please try again.');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred during sign in. Please try again.');
                })
                .finally(() => {
                    submitBtn.textContent = originalText;
                    submitBtn.disabled = false;
                });
            });
        });
    </script>
</body>
</html>
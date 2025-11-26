<?php
session_start();

// Clear any existing session data for fresh login
if (isset($_GET['logout']) && $_GET['logout'] == 'true') {
    // Destroy the session completely
    $_SESSION = array();
    session_destroy();
    session_start(); // Start new session after destroy
}

// Clear the redirect flag when visiting login.php via GET request (opening in new tab)
if ($_SERVER["REQUEST_METHOD"] == "GET" && !isset($_GET['logout'])) {
    unset($_SESSION['login_redirect']);
}

// If user is already logged in and login was just completed (has redirect flag), redirect to dashboard
if (isset($_SESSION['userRole']) && isset($_SESSION['login_redirect']) && $_SESSION['login_redirect'] === true) {
    // Clear the redirect flag so it doesn't redirect on subsequent page loads
    unset($_SESSION['login_redirect']);
    
    switch ($_SESSION['userRole']) {
        case 'admin':
            header('Location: admin_nurse.php');
            exit;
        case 'nurse':
            header('Location: nurse_dash.php');
            exit;
        case 'doctor':
            header('Location: doctor_dash.php');
            exit;
        case 'patient':
            header('Location: user_dash.php');
            exit;
    }
}

// Database connection
$conn = new mysqli('localhost', 'root', '', 'patient_management');

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Check if form is submitted via POST
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Always respond with JSON for AJAX login requests
    header('Content-Type: application/json; charset=utf-8');

    // Clear any existing session data before new login
    $_SESSION = array();

    $email = $_POST['email'];
    $password = $_POST['password'];

    // Check if it's the hardcoded admin account
    if ($email === 'admin@gmail.com' && $password === 'admin123') {
        $_SESSION['userRole'] = 'admin';
        $_SESSION['email'] = $email;
        $_SESSION['login_redirect'] = true;
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
            $_SESSION['nurse_id'] = $user['nurse_id'];
            $_SESSION['nurse_email'] = $user['email'];
            $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['userRole'] = 'nurse';
            $_SESSION['login_redirect'] = true;

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
            $_SESSION['userRole'] = 'doctor';
            $_SESSION['doctor_email'] = $user['email'];
            $_SESSION['doctor_id'] = $user['doctor_id'];
            $_SESSION['doctor_name'] = $user['first_name'] . ' ' . $user['last_name'];
            $_SESSION['login_redirect'] = true;

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
            $_SESSION['userRole'] = 'patient';
            $_SESSION['patient_email'] = $user['email'];
            $_SESSION['patient_name'] = isset($user['first_name']) ? $user['first_name'] : '';
            $_SESSION['patient_id'] = $user['id']; // Store the patient ID
            $_SESSION['login_redirect'] = true;

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
  <title>MediSync Login</title>
  <link rel="stylesheet" href="login.css">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    /* Prevent back button cache */
    .login-wrapper {
      animation: fadeIn 0.5s;
    }
    
    @keyframes fadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
    }
    
    .error-message {
      color: #dc3545;
      background: #f8d7da;
      border: 1px solid #f5c6cb;
      padding: 10px;
      border-radius: 5px;
      margin-bottom: 15px;
      display: none;
    }
  </style>
</head>
<body>

<div class="login-wrapper">

  <!-- Left Branding Section -->
  <div class="login-left">
    <img class="cross" src="logo+.svg" alt="Logo Cross" />
    <img class="line" src="logo-.svg" alt="Logo Line" />
    <div class="login-brand">
        <h1>Medi<span class="span1" style="color: var(--primary-color2);">Sync</span></h1>
        <p class="info">Health Medical Center</p>
      <p class="copyright">© 2025 BSIT-3E. All rights reserved.</p>
    </div>
  </div>

  <!-- Right Login Form Section -->
  <div class="login-right">
    <div class="login-box">
      <h2>Log In</h2>
      
      <!-- Error message container -->
      <div id="errorMessage" class="error-message"></div>
      
      <form id="loginForm" method="POST" autocomplete="off">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" placeholder="Enter your email" required autocomplete="off">

        <label for="password">Password</label>
        <input type="password" id="password" name="password" placeholder="Enter your password" required autocomplete="new-password">

        <div class="show-pass">
          <input type="checkbox" id="showPassword">
          <label for="showPassword">Show Password</label>
        </div>

        <button type="submit" id="loginButton">LOG IN</button>

        <div class="forgot">
          <a href="/forgot-password">Forgot Password?</a>
        </div>
      </form>
    </div>
  </div>

</div>

<script>
  // Clear form fields when page loads
  document.addEventListener('DOMContentLoaded', function() {
    // Clear any stored form data
    document.getElementById('loginForm').reset();
    
    // Clear browser autofill
    setTimeout(() => {
      document.getElementById('email').value = '';
      document.getElementById('password').value = '';
    }, 100);

    // Check if coming from logout
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('logout') === 'true') {
      // Clear any cached credentials
      document.getElementById('email').value = '';
      document.getElementById('password').value = '';
      
      // Show logout message
      Swal.fire({
        title: "Logged Out",
        text: "You have been successfully logged out.",
        icon: "info",
        timer: 2000,
        showConfirmButton: false
      });
    }
  });

  const loginForm = document.getElementById('loginForm');
  const showPassword = document.getElementById('showPassword');
  const passwordInput = document.getElementById('password');
  const loginButton = document.getElementById('loginButton');
  const errorMessage = document.getElementById('errorMessage');

  // Toggle show/hide password
  showPassword.addEventListener('change', () => {
    passwordInput.type = showPassword.checked ? 'text' : 'password';
  });

  // Form submission
  loginForm.addEventListener('submit', async (e) => {
    e.preventDefault();

    const email = document.getElementById('email').value.trim();
    const password = passwordInput.value;

    // Hide any previous error messages
    errorMessage.style.display = 'none';

    // Basic validation
    if (!email || !password) {
      showError('Please enter both email and password');
      return;
    }

    // Email validation
    const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailPattern.test(email)) {
      showError('Please enter a valid email address');
      return;
    }

    // Show loading state
    const originalText = loginButton.textContent;
    loginButton.textContent = 'LOGGING IN...';
    loginButton.disabled = true;

    try {
      const response = await fetch('', {
        method: "POST",
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `email=${encodeURIComponent(email)}&password=${encodeURIComponent(password)}`
      });

      if (!response.ok) {
        throw new Error('Network response was not ok');
      }

      const data = await response.json();
      console.log("Login response:", data);

      if (data.success) {
        Swal.fire({
          title: "Login Successful!",
          text: "Welcome! Redirecting...",
          icon: "success",
          timer: 1500,
          showConfirmButton: false
        }).then(() => {
          // Redirect based on role
          if (data.role === 'admin') {
            window.location.href = 'admin_nurse.php';
          } else if (data.role === 'nurse') {
            window.location.href = 'nurse_dash.php';
          } else if (data.role === 'doctor') {
            window.location.href = 'doctor_dash.php';
          } else if (data.role === 'patient') {
            window.location.href = 'user_dash.php';
          } else {
            window.location.href = '/dashboard';
          }
        });
      } else {
        showError(data.message || "Invalid email or password");
        // Clear password field on failed login
        passwordInput.value = '';
      }
    } catch (error) {
      console.error("Login error:", error);
      showError("Unable to process login. Please check your connection and try again.");
      // Clear form on error
      document.getElementById('loginForm').reset();
    } finally {
      // Restore button state
      loginButton.textContent = originalText;
      loginButton.disabled = false;
    }
  });

  function showError(message) {
    errorMessage.textContent = message;
    errorMessage.style.display = 'block';
    
    // Scroll to error message
    errorMessage.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  // Prevent browser back button from showing cached form data
  window.addEventListener('pageshow', function(event) {
    if (event.persisted) {
      document.getElementById('loginForm').reset();
    }
  });

  // Clear form when page is about to be unloaded (when navigating away)
  window.addEventListener('beforeunload', function() {
    document.getElementById('loginForm').reset();
  });
</script>

</body>
</html>
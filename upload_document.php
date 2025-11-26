<?php
session_start();

// Database connection
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "patient_management";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Check if user is logged in as patient
if (!isset($_SESSION['userRole']) || $_SESSION['userRole'] !== 'patient' || !isset($_SESSION['patient_id'])) {
    header("Location: sign_in.php");
    exit;
}

// Get patient data
function getCurrentPatientData($conn, $patient_id) {
    $sql = "SELECT id, first_name, last_name FROM patients WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $patient_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $result->num_rows === 1) {
        return $result->fetch_assoc();
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_document'])) {
    $patient_id = $_SESSION['patient_id'];
    $patient_data = getCurrentPatientData($conn, $patient_id);
    
    if (!$patient_data) {
        $_SESSION['error_message'] = "Patient data not found.";
        header("Location: user_dash.php");
        exit;
    }
    
    $patient_name = $patient_data['first_name'] . ' ' . $patient_data['last_name'];
    $document_type = $_POST['document_type'];
    $document_title = $_POST['document_title'];
    $notes = isset($_POST['notes']) ? $_POST['notes'] : '';
    
    // File upload handling
    if (isset($_FILES['document_file']) && $_FILES['document_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['document_file'];
        $file_name = $file['name'];
        $file_tmp = $file['tmp_name'];
        $file_size = $file['size'];
        $file_error = $file['error'];
        
        // Get file extension
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        
        // Allowed file types
        $allowed_extensions = array('pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx');
        
        // Validate file extension
        if (!in_array($file_ext, $allowed_extensions)) {
            $_SESSION['error_message'] = "Invalid file type. Only PDF, JPG, PNG, DOC, and DOCX files are allowed.";
            header("Location: user_dash.php");
            exit;
        }
        
        // Validate file size (5MB max)
        if ($file_size > 5242880) {
            $_SESSION['error_message'] = "File size exceeds 5MB limit.";
            header("Location: user_dash.php");
            exit;
        }
        
        // Create uploads directory if it doesn't exist
        $upload_dir = 'uploads/medical_documents/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        // Generate unique file name
        $unique_filename = $patient_id . '_' . time() . '_' . uniqid() . '.' . $file_ext;
        $file_path = $upload_dir . $unique_filename;
        
        // Move uploaded file
        if (move_uploaded_file($file_tmp, $file_path)) {
            // Insert into database - REMOVED STATUS FIELD
            $sql = "INSERT INTO medical_documents 
                    (patient_id, patient_name, document_type, document_title, file_name, file_path, file_size, notes, uploaded_by, upload_date) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Patient', NOW())";
            
            $stmt = $conn->prepare($sql);
            
            if ($stmt) {
                $stmt->bind_param("isssssds", 
                    $patient_id, 
                    $patient_name, 
                    $document_type, 
                    $document_title, 
                    $file_name, 
                    $file_path, 
                    $file_size, 
                    $notes
                );
                
                if ($stmt->execute()) {
                    $_SESSION['success_message'] = "Document uploaded successfully!";
                } else {
                    // If database insert fails, delete the uploaded file
                    unlink($file_path);
                    $_SESSION['error_message'] = "Failed to save document information. Please try again.";
                }
                
                $stmt->close();
            } else {
                // If prepare fails, delete the uploaded file
                unlink($file_path);
                $_SESSION['error_message'] = "Database error. Please try again.";
            }
        } else {
            $_SESSION['error_message'] = "Failed to upload file. Please try again.";
        }
    } else {
        // Handle file upload errors
        $error_messages = array(
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize directive in php.ini',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive in HTML form',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
        );
        
        $error_code = $_FILES['document_file']['error'];
        $error_message = isset($error_messages[$error_code]) ? $error_messages[$error_code] : 'Unknown upload error';
        
        $_SESSION['error_message'] = "Upload error: " . $error_message;
    }
}

$conn->close();
header("Location: user_dash.php");
exit;
?>
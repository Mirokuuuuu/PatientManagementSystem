<?php
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

// Handle AJAX request to get doctor data
if (isset($_GET['get_doctor_id'])) {
    $doctor_id = intval($_GET['get_doctor_id']);
    $sql = "SELECT doctor_id, first_name, last_name, email, phone, department, profile_image FROM doctor WHERE doctor_id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $doctor_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($result && mysqli_num_rows($result) > 0) {
        $doctor = mysqli_fetch_assoc($result);
        echo json_encode(['success' => true, 'doctor' => $doctor]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Doctor not found']);
    }
    mysqli_stmt_close($stmt);
    exit;
}

// Handle delete request
if (isset($_GET['delete_id'])) {
    $delete_id = intval($_GET['delete_id']);
    $sql = "DELETE FROM doctor WHERE doctor_id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $delete_id);
    
    if (mysqli_stmt_execute($stmt)) {
        echo "<script>alert('Doctor deleted successfully!'); window.location.href='admin_doctor.php';</script>";
    } else {
        echo "<script>alert('Error deleting doctor: " . mysqli_error($conn) . "');</script>";
    }
    mysqli_stmt_close($stmt);
}

// Handle edit form submission
if (isset($_POST['edit_doctor_id'])) {
    $doctor_id = intval($_POST['edit_doctor_id']);
    $first_name = mysqli_real_escape_string($conn, $_POST['first_name']);
    $last_name = mysqli_real_escape_string($conn, $_POST['last_name']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $phone = mysqli_real_escape_string($conn, $_POST['phone']);
    $department = mysqli_real_escape_string($conn, $_POST['department']);
    
    // Handle file upload: store image in database as a base64 data URI
    $profile_image = "";
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] == 0) {
        $tmpPath = $_FILES['profile_image']['tmp_name'];
        $fileContents = file_get_contents($tmpPath);
        if ($fileContents !== false) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $tmpPath);
            finfo_close($finfo);
            $base64 = base64_encode($fileContents);
            $profile_image = 'data:' . $mime . ';base64,' . $base64;
        }
    }
    
    // Update with password if provided
    if (!empty($_POST['password'])) {
        $password_hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
        if ($profile_image) {
            $sql = "UPDATE doctor SET first_name=?, last_name=?, email=?, phone=?, department=?, password_hash=?, profile_image=? WHERE doctor_id=?";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "sssssssi", $first_name, $last_name, $email, $phone, $department, $password_hash, $profile_image, $doctor_id);
        } else {
            $sql = "UPDATE doctor SET first_name=?, last_name=?, email=?, phone=?, department=?, password_hash=? WHERE doctor_id=?";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "ssssssi", $first_name, $last_name, $email, $phone, $department, $password_hash, $doctor_id);
        }
    } else {
        // Update without password
        if ($profile_image) {
            $sql = "UPDATE doctor SET first_name=?, last_name=?, email=?, phone=?, department=?, profile_image=? WHERE doctor_id=?";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "sssssssi", $first_name, $last_name, $email, $phone, $department, $profile_image, $doctor_id);
        } else {
            $sql = "UPDATE doctor SET first_name=?, last_name=?, email=?, phone=?, department=? WHERE doctor_id=?";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "sssssi", $first_name, $last_name, $email, $phone, $department, $doctor_id);
        }
    }
    
    if (mysqli_stmt_execute($stmt)) {
        echo "<script>alert('Doctor updated successfully!'); window.location.href='admin_doctor.php';</script>";
    } else {
        echo "<script>alert('Error: " . mysqli_error($conn) . "');</script>";
    }
    
    mysqli_stmt_close($stmt);
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && !isset($_POST['edit_doctor_id'])) {
    $first_name = mysqli_real_escape_string($conn, $_POST['first_name']);
    $last_name = mysqli_real_escape_string($conn, $_POST['last_name']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $phone = mysqli_real_escape_string($conn, $_POST['phone']);
    $department = mysqli_real_escape_string($conn, $_POST['department']);
    $password_hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
    
    // Handle file upload: store image in database as a base64 data URI
    $profile_image = "";
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] == 0) {
        $tmpPath = $_FILES['profile_image']['tmp_name'];
        $fileContents = file_get_contents($tmpPath);
        if ($fileContents !== false) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $tmpPath);
            finfo_close($finfo);
            $base64 = base64_encode($fileContents);
            $profile_image = 'data:' . $mime . ';base64,' . $base64;
        }
    }

    $sql = "INSERT INTO doctor (first_name, last_name, email, password_hash, phone, department, role, profile_image) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = mysqli_prepare($conn, $sql);
    $role = 'doctor';
    mysqli_stmt_bind_param($stmt, "ssssssss", 
        $first_name, $last_name, $email, $password_hash, $phone, $department, $role, $profile_image);
    
    if (mysqli_stmt_execute($stmt)) {
        echo "<script>alert('Doctor registered successfully!'); window.location.href='admin_doctor.php';</script>";
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
	<title>Manage Doctors</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
	<link rel="stylesheet" href="admin_doctor.css" />
</head>
<body>
<div class="dashboard-root">
	<aside class="sidebar">
		<div>
			<img src="logo.jpg" alt="MediSync Logo" style="height: 50px; margin: auto; display: block;" />
		</div>
		<ul class="nav-list">
			<li><a href="admin_nurse.php" class="nav-link"><i class="bi bi-person-badge"></i> Nurses</a></li>
			<li class="active"><i class="bi bi-person-check"></i> Doctors</li>
		</ul>
		
	</aside>
	
	<main class="main-content">
		<header class="topbar">
			<div style="display:flex;align-items:center;gap:12px">
			</div>
			<div style="display:flex;align-items:center;gap:14px">
				<div class="small-muted">Admin Name<br><small class="small-muted">Admin ID: 00-00001</small></div>
				<button class="btn btn-outline-dark btn-sm" onclick="signOut()">Sign Out</button>
			</div>
		</header><br>
		<div class="main-header">
			<h2>Doctors</h2>
		</div>
		
		<!-- Doctors Section -->
		<div class="section-container">
			<div class="section-header">
				<h5>Registered Doctors</h5>
				<button id="open-add-doctor" class="btn btn-dark btn-sm"><i class="bi bi-plus-circle"></i> Add Doctor</button>
			</div>
			<div class="section-table">
				<table class="table table-striped">
					<thead class="table-light">
						<tr>
							<th>Photo</th>
							<th>Name</th>
							<th>Email</th>
							<th>Phone</th>
							<th>Department</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody>
						<?php
						// Fetch doctors from DB and display them
						$result = mysqli_query($conn, "SELECT * FROM doctor ORDER BY doctor_id DESC LIMIT 50");
						if ($result && mysqli_num_rows($result) > 0) {
							while ($row = mysqli_fetch_assoc($result)) {
								$doctor_id = htmlspecialchars($row['doctor_id']);
								$fullName = htmlspecialchars($row['first_name'] . ' ' . $row['last_name']);
								$email = htmlspecialchars($row['email']);
								$phone = htmlspecialchars($row['phone']);
								$department = htmlspecialchars($row['department']);
								$photoSrc = !empty($row['profile_image']) ? $row['profile_image'] : '';
								
								echo '<tr>';
								if ($photoSrc) {
									echo '<td><img src="' . $photoSrc . '" alt="' . $fullName . '" style="width:40px;height:40px;border-radius:8px;object-fit:cover;"/></td>';
								} else {
									echo '<td><div style="width:40px;height:40px;border-radius:8px;background:#e5e7eb;"></div></td>';
								}
								echo '<td>' . $fullName . '</td>';
								echo '<td>' . $email . '</td>';
								echo '<td>' . $phone . '</td>';
								echo '<td>' . $department . '</td>';
								echo '<td>';
								echo '<button class="btn btn-sm btn-outline-primary" onclick="openEditModal(' . $doctor_id . ')"><i class="bi bi-pencil"></i> Edit</button> ';
								echo '<button class="btn btn-sm btn-outline-danger" onclick="deleteDoctor(' . $doctor_id . ')"><i class="bi bi-trash"></i> Delete</button>';
								echo '</td>';
								echo '</tr>';
							}
						} else {
							echo '<tr><td colspan="6" class="text-center text-muted">No doctors found.</td></tr>';
						}
						?>
					</tbody>
				</table>
			</div>
		</div>

	</main>
</div>

<!-- Add Doctor Modal -->
<div id="add-doctor-modal" class="modal-overlay" aria-hidden="true">
	<div class="modal-card" style="max-height: 80vh; overflow-y: auto;">
		<div class="modal-header d-flex align-items-center justify-content-between">
			<div>
				<h5 style="margin:0">Doctor Registration</h5>
				<div class="small-muted">Professional Information</div>
			</div>
			<button id="close-add-doctor" class="btn btn-light btn-sm"><i class="bi bi-x-lg"></i></button>
		</div>
		<form id="add-doctor-form" class="modal-form" method="POST" enctype="multipart/form-data">
			<div class="form-grid">
				<!-- Centered Photo Upload -->
				<div class="form-group full d-flex flex-column align-items-center mb-4">
					<label for="profile_image" class="mb-2 fw-bold fs-5">Upload Photo</label>
					<div id="photo-preview" style="width:120px;height:120px;background:#f3f3f3;overflow:hidden;display:flex;align-items:center;justify-content:center;margin-bottom:10px;border:1px solid #ccc;">
						<img id="preview-img" src="https://via.placeholder.com/120?text=Photo" alt="Preview" style="width:100%;height:100%;object-fit:cover;display:block;" />
					</div>
					<input type="file" id="profile_image" name="profile_image" accept="image/*" class="form-control mb-2" style="max-width: 250px;" />
				</div>
				<!-- First/Last Name Row -->
				<div class="row mb-3">
					<div class="col-md-6 form-group">
						<label class="fw-bold">First Name</label>
						<input type="text" name="first_name" placeholder="Enter first name" class="form-control" required />
					</div>
					<div class="col-md-6 form-group">
						<label class="fw-bold">Last Name</label>
						<input type="text" name="last_name" placeholder="Enter last name" class="form-control" required />
					</div>
				</div>
				<!-- Phone/Email Row -->
				<div class="row mb-3">
					<div class="col-md-6 form-group">
						<label class="fw-bold">Phone Number</label>
						<input type="text" name="phone" placeholder="(+63) 9451648923" class="form-control" required />
					</div>
					<div class="col-md-6 form-group">
						<label class="fw-bold">Email Address</label>
						<input type="email" name="email" placeholder="doctor@email.com" class="form-control" required />
					</div>
				</div>
				<!-- Department -->
				<div class="form-group full mb-3">
					<label class="fw-bold">Department</label>
					<select name="department" class="form-control" required>
						<option value="">Select Department</option>
						<option value="Cardiology">Cardiology</option>
						<option value="Radiology">Radiology</option>
						<option value="Pediatrics">Pediatrics</option>
						<option value="Emergency">Emergency</option>
						<option value="Surgery">Surgery</option>
						<option value="Internal_medicine">Internal Medicine</option>
						<option value="Obstetrics_gynecology">Obstetrics & Gynecology</option>
						<option value="Orthopedics">Orthopedics</option>
						<option value="Neurology">Neurology</option>
						<option value="Psychiatry">Psychiatry</option>
						<option value="Anesthesiology">Anesthesiology</option>
						<option value="Pathology">Pathology</option>
						<option value="Dermatology">Dermatology</option>
						<option value="Ophthalmology">Ophthalmology</option>
						<option value="ENT">ENT</option>
						<option value="Urology">Urology</option>
						<option value="Oncology">Oncology</option>
						<option value="Other">Other</option>
					</select>
				</div>
				<!-- Password -->
				<div class="form-group full mb-3">
					<label class="fw-bold">Password</label>
					<input type="password" name="password" placeholder="Enter password" class="form-control" required />
				</div>
			</div>
			<div class="mt-3 d-flex justify-content-end gap-2">
				<button type="button" id="cancel-add-doctor" class="btn btn-outline-secondary">Cancel</button>
				<button type="submit" class="btn btn-dark">Register Doctor</button>
			</div>
		</form>
	</div>
</div>

<!-- Edit Doctor Modal -->
<div id="edit-doctor-modal" class="modal-overlay" aria-hidden="true">
	<div class="modal-card" style="max-height: 80vh; overflow-y: auto;">
		<div class="modal-header d-flex align-items-center justify-content-between">
			<div>
				<h5 style="margin:0">Edit Doctor Information</h5>
				<div class="small-muted">Update Professional Information</div>
			</div>
			<button id="close-edit-doctor" class="btn btn-light btn-sm"><i class="bi bi-x-lg"></i></button>
		</div>
		<form id="edit-doctor-form" class="modal-form" method="POST" enctype="multipart/form-data">
			<input type="hidden" name="edit_doctor_id" id="edit_doctor_id" />
			<div class="form-grid">
				<!-- Centered Photo Upload -->
				<div class="form-group full d-flex flex-column align-items-center mb-4">
					<label for="edit_profile_image" class="mb-2 fw-bold fs-5">Update Photo</label>
					<div id="edit-photo-preview" style="width:120px;height:120px;background:#f3f3f3;overflow:hidden;display:flex;align-items:center;justify-content:center;margin-bottom:10px;border:1px solid #ccc;">
						<img id="edit-preview-img" src="https://via.placeholder.com/120?text=Photo" alt="Preview" style="width:100%;height:100%;object-fit:cover;display:block;" />
					</div>
					<input type="file" id="edit_profile_image" name="profile_image" accept="image/*" class="form-control mb-2" style="max-width: 250px;" />
				</div>
				<!-- First/Last Name Row -->
				<div class="row mb-3">
					<div class="col-md-6 form-group">
						<label class="fw-bold">First Name</label>
						<input type="text" name="first_name" id="edit_first_name" placeholder="Enter first name" class="form-control" required />
					</div>
					<div class="col-md-6 form-group">
						<label class="fw-bold">Last Name</label>
						<input type="text" name="last_name" id="edit_last_name" placeholder="Enter last name" class="form-control" required />
					</div>
				</div>
				<!-- Phone/Email Row -->
				<div class="row mb-3">
					<div class="col-md-6 form-group">
						<label class="fw-bold">Phone Number</label>
						<input type="text" name="phone" id="edit_phone" placeholder="(+63) 9451648923" class="form-control" required />
					</div>
					<div class="col-md-6 form-group">
						<label class="fw-bold">Email Address</label>
						<input type="email" name="email" id="edit_email" placeholder="doctor@email.com" class="form-control" required />
					</div>
				</div>
				<!-- Department -->
				<div class="form-group full mb-3">
					<label class="fw-bold">Department</label>
					<select name="department" id="edit_department" class="form-control" required>
						<option value="">Select Department</option>
						<option value="cardiology">Cardiology</option>
						<option value="radiology">Radiology</option>
						<option value="pediatrics">Pediatrics</option>
						<option value="emergency">Emergency</option>
						<option value="surgery">Surgery</option>
						<option value="internal_medicine">Internal Medicine</option>
						<option value="obstetrics_gynecology">Obstetrics & Gynecology</option>
						<option value="orthopedics">Orthopedics</option>
						<option value="neurology">Neurology</option>
						<option value="psychiatry">Psychiatry</option>
						<option value="anesthesiology">Anesthesiology</option>
						<option value="pathology">Pathology</option>
						<option value="dermatology">Dermatology</option>
						<option value="ophthalmology">Ophthalmology</option>
						<option value="ent">ENT</option>
						<option value="urology">Urology</option>
						<option value="oncology">Oncology</option>
						<option value="other">Other</option>
					</select>
				</div>
				<!-- Password -->
				<div class="form-group full mb-3">
					<label class="fw-bold">Password <span class="text-muted">(Leave blank to keep current password)</span></label>
					<input type="password" name="password" id="edit_password" placeholder="Enter new password (optional)" class="form-control" />
				</div>
			</div>
			<div class="mt-3 d-flex justify-content-end gap-2">
				<button type="button" id="cancel-edit-doctor" class="btn btn-outline-secondary">Cancel</button>
				<button type="submit" class="btn btn-dark">Update Doctor</button>
			</div>
		</form>
	</div>
</div>

<script>
function deleteDoctor(doctorId) {
	if (confirm('Are you sure you want to delete this doctor?')) {
		window.location.href = 'admin_doctor.php?delete_id=' + doctorId;
	}
}

function openEditModal(doctorId) {
	// Fetch doctor data via AJAX
	fetch('admin_doctor.php?get_doctor_id=' + doctorId)
		.then(response => response.json())
		.then(data => {
			if (data.success) {
				const doctor = data.doctor;
				document.getElementById('edit_doctor_id').value = doctor.doctor_id;
				document.getElementById('edit_first_name').value = doctor.first_name;
				document.getElementById('edit_last_name').value = doctor.last_name;
				document.getElementById('edit_email').value = doctor.email;
				document.getElementById('edit_phone').value = doctor.phone;
				document.getElementById('edit_department').value = doctor.department;
				
				// Set photo preview
				if (doctor.profile_image) {
					document.getElementById('edit-preview-img').src = doctor.profile_image;
				} else {
					document.getElementById('edit-preview-img').src = 'https://via.placeholder.com/120?text=Photo';
				}
				
				// Open modal
				const editModal = document.getElementById('edit-doctor-modal');
				editModal.classList.add('open');
				editModal.setAttribute('aria-hidden', 'false');
			}
		})
		.catch(error => {
			alert('Error loading doctor data');
			console.error('Error:', error);
		});
}

document.addEventListener('DOMContentLoaded', function(){
	const openBtn = document.getElementById('open-add-doctor');
	const overlay = document.getElementById('add-doctor-modal');
	const closeBtn = document.getElementById('close-add-doctor');
	const cancelBtn = document.getElementById('cancel-add-doctor');
	
	const editOverlay = document.getElementById('edit-doctor-modal');
	const editCloseBtn = document.getElementById('close-edit-doctor');
	const editCancelBtn = document.getElementById('cancel-edit-doctor');
	
	function openModal() {
		overlay.classList.add('open');
		overlay.setAttribute('aria-hidden','false');
	}
	
	if(openBtn && overlay){
		openBtn.addEventListener('click', openModal);
	}
	
	if(closeBtn){ 
		closeBtn.addEventListener('click', function(){ 
			overlay.classList.remove('open'); 
			overlay.setAttribute('aria-hidden','true'); 
		}); 
	}
	
	if(cancelBtn){ 
		cancelBtn.addEventListener('click', function(){ 
			overlay.classList.remove('open'); 
			overlay.setAttribute('aria-hidden','true'); 
		}); 
	}
	
	if(overlay){ 
		overlay.addEventListener('click', function(e){ 
			if(e.target === overlay){ 
				overlay.classList.remove('open'); 
				overlay.setAttribute('aria-hidden','true'); 
			} 
		}); 
	}
	
	// Edit modal event listeners
	if(editCloseBtn) {
		editCloseBtn.addEventListener('click', function() {
			editOverlay.classList.remove('open');
			editOverlay.setAttribute('aria-hidden', 'true');
		});
	}
	
	if(editCancelBtn) {
		editCancelBtn.addEventListener('click', function() {
			editOverlay.classList.remove('open');
			editOverlay.setAttribute('aria-hidden', 'true');
		});
	}
	
	if(editOverlay) {
		editOverlay.addEventListener('click', function(e) {
			if(e.target === editOverlay) {
				editOverlay.classList.remove('open');
				editOverlay.setAttribute('aria-hidden', 'true');
			}
		});
	}
	
	// Photo preview logic
	const photoInput = document.getElementById('profile_image');
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
	
	// Edit photo preview logic
	const editPhotoInput = document.getElementById('edit_profile_image');
	const editPreviewImg = document.getElementById('edit-preview-img');
	if(editPhotoInput && editPreviewImg){
		editPhotoInput.addEventListener('change', function(e){
			const file = e.target.files[0];
			if(file && file.type.startsWith('image/')){
				const reader = new FileReader();
				reader.onload = function(ev){
					editPreviewImg.src = ev.target.result;
				};
				reader.readAsDataURL(file);
			}
		});
	}
});

function signOut() {
    // Redirect to logout handler which destroys session
    window.location.href = 'admin_doctor.php?logout=true';
}
</script>

</body>
</html>

<?php
session_start();

// Handle sign-out request
if (isset($_GET['logout']) && $_GET['logout'] == 'true') {
    session_destroy();
    header("Location: login.php");
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title>Analytics Dashboard</title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
	<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
	<link rel="stylesheet" href="analytics.css" />
</head>
<body>
<div class="dashboard-root">
	<aside class="sidebar">
		<div>
			<div class="brand">Pamantasan ng Lungsod<br><span class="small-muted">Patient Management</span>
		</div>
			<input type="text" placeholder="Search patients, appointments" />
		</div>
		<ul class="nav-list">
			<li><a href="nurse_dash.php"><i class="bi bi-grid"></i> Dashboard</a></li>
			<li><a href="patients.php"><i class="bi bi-people"></i> Patients</a></li>
			<li><a href="appointments.php"><i class="bi bi-calendar-event"></i> Appointments</span></a></li>
			<li class="active"><a href="analytics.php"><i class="bi bi-bar-chart"></i> Analytics</a></li>
			<li><a href="settings.php"><i class="bi bi-gear"></i> Settings</a></li>
		</ul>
		
	</aside>

	<main class="main-content">
		<header class="topbar">
			<div style="display:flex;align-items:center;gap:12px">
			</div>
			<div style="display:flex;align-items:center;gap:14px">
				<div class="small-muted">Amira Singin<br><small class="small-muted">Patient ID: 24-00006</small></div>
				<button class="btn btn-outline-dark btn-sm" onclick="signOut()">Sign Out</button>
			</div>
		</header><br>
		<div class="page-header d-flex align-items-center justify-content-between">
			<div>
				<h2>Analytics Dashboard</h2>
				<div class="small-muted">Comprehensive insights and performance metrics</div>
			</div>
		</div><br>
		<div style="display:flex;align-items:center;gap:12px">
			<button id="export-report" class="btn btn-outline-secondary"> <i class="bi bi-bar-chart-line"></i> Export Report</button>
		</div><br>
            <div class="d-flex align-items-center gap-2">
				<div class="segment-tabs">
					<button class="tab active">Overview</button>
					
				</div>
		</div>

		<div class="metrics-grid">
			<div class="metric-card">
				<div class="metric-label">Total Patients</div>
				<div class="metric-value">1,247 <span class="metric-trend up">+8.2%</span> <i class="bi bi-people metric-icon"></i></div>
				<div class="small-muted">vs last month</div>
			</div>
			<div class="metric-card">
				<div class="metric-label">Appointments Today</div>
				<div class="metric-value">23 <span class="metric-trend up">+12%</span> <i class="bi bi-calendar-event metric-icon"></i></div>
				<div class="small-muted">vs yesterday</div>
			</div>
			<div class="metric-card">
				<div class="metric-label">Patient Satisfaction</div>
				<div class="metric-value">4.7/5 <span class="metric-trend up">+0.3</span> <i class="bi bi-star metric-icon"></i></div>
				<div class="small-muted">average rating</div>
			</div>
			<div class="metric-card">
				<div class="metric-label">Average Wait Time</div>
				<div class="metric-value">18 min <span class="metric-trend down">-5 min</span> <i class="bi bi-clock metric-icon"></i></div>
				<div class="small-muted">vs last week</div>
			</div>
			<div class="metric-card">
				<div class="metric-label">Revenue (Monthly)</div>
				<div class="metric-value">₱2000K <span class="metric-trend up">+15.8%</span> <i class="bi bi-currency-dollar metric-icon"></i></div>
				<div class="small-muted">vs last month</div>
			</div>
			<div class="metric-card">
				<div class="metric-label">Critical Alerts</div>
				<div class="metric-value">3 <span class="metric-trend up">+2</span> <i class="bi bi-exclamation-triangle metric-icon"></i></div>
				<div class="small-muted">active alerts</div>
			</div>
		</div>

		

	</main>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
	// Simple tab behavior (visual only)
	document.querySelectorAll('.segment-tabs .tab').forEach(function(btn){
		btn.addEventListener('click', function(){
			document.querySelectorAll('.segment-tabs .tab').forEach(t=>t.classList.remove('active'));
			btn.classList.add('active');
		});
	});

	const exportBtn = document.getElementById('export-report');
	if(exportBtn){ exportBtn.addEventListener('click', function(){ alert('Exporting report (demo).'); }); }
});

function signOut() {
    // Redirect to logout handler which destroys session
    window.location.href = 'analytics.php?logout=true';
}
</script>

</body>
</html>


<?php
require_once '../includes/functions.php';
requireLogin();

$userId = $_SESSION['user_id'];
$feedback = '';

// Handle profile photo upload
if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($_FILES['profile_photo']['tmp_name']);
    
    if (in_array($mime, $allowed)) {
        $ext = pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION);
        $filename = 'profile_' . $userId . '_' . time() . '.' . $ext;
        $targetDir = __DIR__ . '/../assets/uploads/profiles/';
        if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
        
        if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $targetDir . $filename)) {
            // Update DB
            $stmt = $pdo->prepare("UPDATE users SET profile_photo = ? WHERE id = ?");
            $stmt->execute(['assets/uploads/profiles/' . $filename, $userId]);
            $feedback = 'Profile photo updated successfully.';
        } else {
            $feedback = 'Error: Failed to move uploaded file.';
        }
    } else {
        $feedback = 'Error: Invalid file type. Allowed: JPG, PNG, GIF, WEBP.';
    }
}

// Handle signature save (canvas or upload)
// Handle signature save (canvas or upload)
if (function_exists('ensureUserSignatureColumn')) {
    ensureUserSignatureColumn();
}
if (function_exists('ensureProfilePhotoColumn')) {
    ensureProfilePhotoColumn();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_FILES['profile_photo'])) {
	if (isset($_POST['delete_signature'])) {
		try {
			deleteUserSignature($userId);
			$feedback = 'Signature deleted successfully.';
		} catch (Exception $e) {
			$feedback = 'Error: ' . $e->getMessage();
		}
	} // Handle WhatsApp Number Update
    elseif (isset($_POST['whatsapp_number'])) {
        $wa_number = trim($_POST['whatsapp_number']);
        // Basic validation: ensure it mimics a phone number (e.g., starts with +)
        if (!empty($wa_number)) {
            $stmt = $pdo->prepare("UPDATE users SET whatsapp_number = ? WHERE id = ?");
            $stmt->execute([$wa_number, $userId]);
            $feedback = 'WhatsApp number updated successfully.';
        }
    }
    // Handle Username Update
    elseif (isset($_POST['new_username'])) {
        $newUsername = trim($_POST['new_username']);
        if (strlen($newUsername) >= 3) {
            // Check uniqueness
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $stmt->execute([$newUsername, $userId]);
            
        if ($stmt->fetch()) {
            $feedback = 'Error: Username already taken.';
        } else {
            $oldUsername = $_SESSION['username'];
            // Update both username and full_name to be the same, as requested
            $stmt = $pdo->prepare("UPDATE users SET username = ?, full_name = ? WHERE id = ?");
            if ($stmt->execute([$newUsername, $newUsername, $userId])) {
                $_SESSION['username'] = $newUsername; 
                $_SESSION['full_name'] = $newUsername; // Sync session full_name too
                
                // Propagate name change to payment_vouchers (legacy text fields)
                $voucherFields = ['applicant', 'prepared_by', 'checked_by', 'department_manager', 'general_manager'];
                foreach ($voucherFields as $field) {
                    try {
                        // Check if column exists
                        $colCheck = $pdo->query("SHOW COLUMNS FROM payment_vouchers LIKE '$field'");
                        if ($colCheck->fetch()) {
                            $upd = $pdo->prepare("UPDATE payment_vouchers SET $field = ? WHERE $field = ?");
                            // Update matching old username OR old full name (best effort)
                            $upd->execute([$newUsername, $oldUsername]);
                        }
                    } catch (Exception $e) { }
                }
                
                $feedback = 'Name updated successfully.';
            } else {
                $feedback = 'Error: Failed to update name.';
            }
        }
        } else {
            $feedback = 'Error: Username must be at least 3 characters.';
        }
    } else {
		$result = handleUserSignatureUpload($userId);
		$feedback = $result['ok'] ? 'Signature saved successfully.' : ('Error: ' . $result['error']);
	}
}
$initial = strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1));

// Fetch current user details
$stmt = $pdo->prepare("SELECT id, username, full_name, email, role, department, is_active, created_at, updated_at, profile_photo, whatsapp_number FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
	header('Location: dashboard.php');
	exit();
}

$currentSig = getUserSignaturePathById($userId);
$dashboardUrl = isAdmin() ? '../admin/dashboard.php' : 'dashboard.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>My Account - <?= htmlspecialchars($user['full_name']) ?></title>
	<link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
	<style>
        /* Profile Photo Styles */
        .profile-section {
            background: #fff;
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            gap: 30px;
        }
        .profile-preview {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: #f3f4f6;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            color: #6b7280;
            overflow: hidden;
            border: 4px solid #fff;
            box-shadow: 0 0 0 2px #e5e7eb;
        }
        .profile-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .profile-upload-btn {
            display: inline-block;
            padding: 8px 16px;
            background: #f3f4f6;
            color: #374151;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
        }
        .profile-upload-btn:hover {
            background: #e5e7eb;
        }

		.signature-section {
			background: #fff;
			border-radius: 20px;
			padding: 0;
			margin-top: 30px;
			box-shadow: none;
		}
		
		.signature-card {
			background: rgba(255, 255, 255, 0.95);
			backdrop-filter: blur(10px);
			border-radius: 16px;
			padding: 30px;
			box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
		}
		
		.signature-header {
			display: flex;
			justify-content: space-between;
			align-items: center;
			margin-bottom: 25px;
		}
		
		.signature-header h2 {
			margin: 0;
			font-size: 24px;
			font-weight: 700;
			font-weight: 700;
			color: #111827;
			background: none;
			-webkit-text-fill-color: initial;
		}
		
		.canvas-container {
			position: relative;
			background: #ffffff;
			border: 3px solid #e5e7eb;
			border-radius: 12px;
			overflow: hidden;
			margin-bottom: 20px;
			box-shadow: inset 0 2px 8px rgba(0, 0, 0, 0.05);
		}
		
		#sigCanvas {
			display: block;
			width: 100%;
			height: 250px;
			cursor: crosshair;
			touch-action: none;
		}
		
		.canvas-placeholder {
			position: absolute;
			top: 50%;
			left: 50%;
			transform: translate(-50%, -50%);
			color: #9ca3af;
			font-size: 16px;
			pointer-events: none;
			opacity: 0.5;
			transition: opacity 0.3s;
		}
		
		.canvas-container.has-content .canvas-placeholder {
			opacity: 0;
		}
		
		.tools-panel {
			display: flex;
			gap: 15px;
			flex-wrap: wrap;
			align-items: center;
			margin-bottom: 20px;
			padding: 15px;
			background: #f9fafb;
			border-radius: 10px;
		}
		
		.tool-group {
			display: flex;
			gap: 8px;
			align-items: center;
		}
		
		.tool-label {
			font-size: 13px;
			font-weight: 600;
			color: #6b7280;
			margin-right: 5px;
		}
		
		.pen-size-btn, .color-btn {
			width: 40px;
			height: 40px;
			border: 2px solid #e5e7eb;
			border-radius: 8px;
			background: white;
			cursor: pointer;
			transition: all 0.2s;
			display: flex;
			align-items: center;
			justify-content: center;
		}
		
		.pen-size-btn:hover, .color-btn:hover {
			border-color: #667eea;
			transform: translateY(-2px);
			box-shadow: 0 4px 12px rgba(102, 126, 234, 0.2);
		}
		
		.pen-size-btn.active, .color-btn.active {
			border-color: #667eea;
			background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
			color: white;
		}
		
		.pen-size-btn .dot {
			background: currentColor;
			border-radius: 50%;
		}
		
		.pen-size-btn[data-size="2"] .dot { width: 4px; height: 4px; }
		.pen-size-btn[data-size="4"] .dot { width: 8px; height: 8px; }
		.pen-size-btn[data-size="6"] .dot { width: 12px; height: 12px; }
		
		.color-btn {
			position: relative;
		}
		
		.color-btn .color-circle {
			width: 24px;
			height: 24px;
			border-radius: 50%;
			border: 2px solid white;
			box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
		}
		
		.action-buttons {
			display: flex;
			gap: 10px;
			flex-wrap: wrap;
		}
		
		.btn-modern {
			padding: 12px 24px;
			border: none;
			border-radius: 10px;
			font-weight: 600;
			font-size: 14px;
			cursor: pointer;
			transition: all 0.3s;
			display: inline-flex;
			align-items: center;
			gap: 8px;
		}
		
		.btn-primary {
			background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
			color: white;
			box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
		}
		
		.btn-primary:hover {
			transform: translateY(-2px);
			box-shadow: 0 6px 20px rgba(102, 126, 234, 0.5);
		}
		
		.btn-secondary {
			background: white;
			color: #667eea;
			border: 2px solid #667eea;
		}
		
		.btn-secondary:hover {
			background: #667eea;
			color: white;
		}
		
		.btn-danger {
			background: #ef4444;
			color: white;
		}
		
		.btn-danger:hover {
			background: #dc2626;
			color: white;
		}
		
		.btn-modern:disabled {
			opacity: 0.5;
			cursor: not-allowed;
			transform: none !important;
		}
		
		.current-signature {
			margin-top: 25px;
			padding: 20px;
			background: #f9fafb;
			border-radius: 12px;
		}
		
		.current-signature img {
			max-width: 100%;
			height: auto;
			border: 2px dashed #d1d5db;
			border-radius: 8px;
			padding: 10px;
			background: white;
		}
		
		.toast {
			position: fixed;
			top: 20px;
			right: 20px;
			padding: 16px 24px;
			border-radius: 10px;
			color: white;
			font-weight: 600;
			box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
			transform: translateX(400px);
			transition: transform 0.3s;
			z-index: 1000;
		}
		
		.toast.show {
			transform: translateX(0);
		}
		
		.toast.success {
			background: linear-gradient(135deg, #10b981 0%, #059669 100%);
		}
		
		.toast.error {
			background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
		}
		
		@media (max-width: 640px) {
			.signature-section {
				padding: 20px;
			}
			
			.signature-card {
				padding: 20px;
			}
			
			.tools-panel {
				justify-content: center;
			}
			
			.action-buttons {
				flex-direction: column;
			}
			
			.btn-modern {
				width: 100%;
				justify-content: center;
			}
            .profile-section {
                flex-direction: column;
                text-align: center;
            }
		}
	</style>
</head>
<body class="dashboard">
<?php require_once __DIR__ . '/../includes/header_employee.php'; ?>

<main class="main-content">
	<div style="margin-bottom: 12px;">
		<a class="icon-link icon-neutral" href="<?= htmlspecialchars($dashboardUrl) ?>" title="Back" aria-label="Back">
			<svg class="icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
				<path d="M15 18l-6-6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
			</svg>
		</a>
	</div>
	
	<div class="form-container">
		<h2>My Account</h2>
		<div class="account-actions" style="margin:10px 0 16px;">
			<a class="btn btn-danger" href="../logout.php" title="Logout">Logout</a>
		</div>

        <!-- Profile Photo Section -->
        <div class="profile-section">
            <div class="profile-preview">
                <?php if (!empty($user['profile_photo'])): ?>
                    <img src="../<?= htmlspecialchars($user['profile_photo']) ?>" alt="Profile Photo">
                <?php else: ?>
                    <?= $initial ?>
                <?php endif; ?>
            </div>
            <div style="flex:1;">
                <h3 style="margin:0 0 8px; font-size:18px;">Profile Photo</h3>
                <p style="margin:0 0 16px; color:#6b7280; font-size:14px;">Upload a new photo to use as your profile picture.</p>
                <form method="post" enctype="multipart/form-data" style="margin:0;">
                    <label class="profile-upload-btn">
                        Choose Image
                        <input type="file" name="profile_photo" accept="image/*" style="display:none;" onchange="this.form.submit()">
                    </label>
                </form>
            </div>
        </div>
		
        <!-- Username Update Section -->
        <div class="profile-section" style="margin-top: 20px;">
            <div style="width: 100%;">
                <h3 style="margin:0 0 8px; font-size:18px;">Change Username</h3>
                <p style="margin:0 0 16px; color:#6b7280; font-size:14px;">Update your system login username. Must be unique.</p>
                <form method="post" style="margin:0; display: flex; gap: 10px; max-width: 400px;">
                    <input type="text" name="new_username" 
                           value="<?= htmlspecialchars($user['username'] ?? '') ?>" 
                           required minlength="3"
                           style="padding: 10px; border: 1px solid #e5e7eb; border-radius: 6px; flex: 1; border-radius: 0;">
                    <button type="submit" class="btn btn-primary" style="border-radius: 0;">Update</button>
                </form>
            </div>
        </div>

        <!-- WhatsApp Notification Section -->
        <div class="profile-section" style="margin-top: 20px;">
            <div style="width: 100%;">
                <h3 style="margin:0 0 8px; font-size:18px;">WhatsApp Notification</h3>
                <p style="margin:0 0 16px; color:#6b7280; font-size:14px;">Enter your phone number (with country code, e.g., +255...) to enable one-click WhatsApp notifications.</p>
                <form method="post" style="margin:0; display: flex; gap: 10px; max-width: 400px;">
                    <input type="text" name="whatsapp_number" 
                           value="<?= htmlspecialchars($user['whatsapp_number'] ?? '') ?>" 
                           placeholder="+255 712 345 678"
                           style="padding: 10px; border: 1px solid #e5e7eb; border-radius: 6px; flex: 1; border-radius: 0;">
                    <button type="submit" class="btn btn-primary" style="border-radius: 0;">Save Number</button>
                </form>
            </div>
        </div>
		

	</div>

	<div class="signature-section">
		<div class="signature-card">
			<div class="signature-header">
				<h2>âœï¸ Digital Signature</h2>
			</div>
			
			<p style="color: #6b7280; margin-bottom: 20px;">Draw your signature below. It will appear on vouchers where your name is used.</p>
			
			<div class="tools-panel">
				<div class="tool-group">
					<span class="tool-label">Pen Size:</span>
					<button class="pen-size-btn active" data-size="2" onclick="setPenSize(2)" title="Thin">
						<div class="dot"></div>
					</button>
					<button class="pen-size-btn" data-size="4" onclick="setPenSize(4)" title="Medium">
						<div class="dot"></div>
					</button>
					<button class="pen-size-btn" data-size="6" onclick="setPenSize(6)" title="Thick">
						<div class="dot"></div>
					</button>
				</div>
				
				<div class="tool-group">
					<span class="tool-label">Color:</span>
					<button class="color-btn active" data-color="#000000" onclick="setPenColor('#000000')" title="Black">
						<div class="color-circle" style="background: #000000;"></div>
					</button>
					<button class="color-btn" data-color="#1e40af" onclick="setPenColor('#1e40af')" title="Blue">
						<div class="color-circle" style="background: #1e40af;"></div>
					</button>
					<button class="color-btn" data-color="#7c3aed" onclick="setPenColor('#7c3aed')" title="Purple">
						<div class="color-circle" style="background: #7c3aed;"></div>
					</button>
				</div>
			</div>
			
			<div class="canvas-container" id="canvasContainer">
				<canvas id="sigCanvas" width="800" height="250"></canvas>
				<div class="canvas-placeholder">âœï¸ Sign here</div>
			</div>
			
			<div class="action-buttons">
				<button class="btn-modern btn-secondary" onclick="undoStroke()" id="undoBtn" disabled>
					<svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/>
					</svg>
					Undo
				</button>
				<button class="btn-modern btn-secondary" onclick="redoStroke()" id="redoBtn" disabled>
					<svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 10h-10a8 8 0 00-8 8v2m18-10l-6 6m6-6l-6-6"/>
					</svg>
					Redo
				</button>
				<button class="btn-modern btn-secondary" onclick="clearCanvas()">
					<svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
					</svg>
					Clear
				</button>
				<button class="btn-modern btn-primary" onclick="saveSignature()" id="saveBtn">
					<svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
					</svg>
					Save Signature
				</button>
			</div>
			
			<?php if ($currentSig): ?>
			<div class="current-signature">
				<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
					<strong style="color: #374151;">Current Signature:</strong>
					<form method="post" onsubmit="return confirm('Delete your signature? This cannot be undone.');" style="margin: 0;">
						<button type="submit" name="delete_signature" value="1" class="btn-modern btn-danger" style="padding: 8px 16px; font-size: 13px;">
							<svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
							</svg>
							Delete
						</button>
					</form>
				</div>
				<img src="../<?= htmlspecialchars($currentSig) ?>" alt="Current signature" />
			</div>
			<?php endif; ?>
		</div>
	</div>
</main>

<script>
const canvas = document.getElementById('sigCanvas');
const ctx = canvas.getContext('2d');
const container = document.getElementById('canvasContainer');
const undoBtn = document.getElementById('undoBtn');
const redoBtn = document.getElementById('redoBtn');
const saveBtn = document.getElementById('saveBtn');

let drawing = false;
let currentStroke = [];
let strokes = [];
let redoStack = [];
let penSize = 2;
let penColor = '#000000';

// Set canvas size to match display size
function resizeCanvas() {
	const rect = canvas.getBoundingClientRect();
	canvas.width = rect.width;
	canvas.height = rect.height;
	redrawCanvas();
}

// Initialize
resizeCanvas();
window.addEventListener('resize', resizeCanvas);

// Drawing functions
function getPos(e) {
	const rect = canvas.getBoundingClientRect();
	const x = (e.touches ? e.touches[0].clientX : e.clientX) - rect.left;
	const y = (e.touches ? e.touches[0].clientY : e.clientY) - rect.top;
	return { x, y };
}

function startDrawing(e) {
	drawing = true;
	currentStroke = [{
		...getPos(e),
		size: penSize,
		color: penColor
	}];
	e.preventDefault();
}

function draw(e) {
	if (!drawing) return;
	
	const pos = getPos(e);
	const lastPos = currentStroke[currentStroke.length - 1];
	
	ctx.strokeStyle = penColor;
	ctx.lineWidth = penSize;
	ctx.lineCap = 'round';
	ctx.lineJoin = 'round';
	
	ctx.beginPath();
	ctx.moveTo(lastPos.x, lastPos.y);
	ctx.lineTo(pos.x, pos.y);
	ctx.stroke();
	
	currentStroke.push({ ...pos, size: penSize, color: penColor });
	updatePlaceholder();
	e.preventDefault();
}

function stopDrawing() {
	if (drawing && currentStroke.length > 1) {
		strokes.push(currentStroke);
		redoStack = [];
		updateButtons();
	}
	drawing = false;
	currentStroke = [];
}

// Event listeners
canvas.addEventListener('mousedown', startDrawing);
canvas.addEventListener('mousemove', draw);
canvas.addEventListener('mouseup', stopDrawing);
canvas.addEventListener('mouseleave', stopDrawing);
canvas.addEventListener('touchstart', startDrawing, { passive: false });
canvas.addEventListener('touchmove', draw, { passive: false });
canvas.addEventListener('touchend', stopDrawing);

// Tool functions
function setPenSize(size) {
	penSize = size;
	document.querySelectorAll('.pen-size-btn').forEach(btn => {
		btn.classList.toggle('active', btn.dataset.size == size);
	});
}

function setPenColor(color) {
	penColor = color;
	document.querySelectorAll('.color-btn').forEach(btn => {
		btn.classList.toggle('active', btn.dataset.color === color);
	});
}

function clearCanvas() {
	if (strokes.length === 0) return;
	if (!confirm('Clear your signature?')) return;
	
	ctx.clearRect(0, 0, canvas.width, canvas.height);
	strokes = [];
	redoStack = [];
	updateButtons();
	updatePlaceholder();
}

function undoStroke() {
	if (strokes.length === 0) return;
	redoStack.push(strokes.pop());
	redrawCanvas();
	updateButtons();
}

function redoStroke() {
	if (redoStack.length === 0) return;
	strokes.push(redoStack.pop());
	redrawCanvas();
	updateButtons();
}

function redrawCanvas() {
	ctx.clearRect(0, 0, canvas.width, canvas.height);
	
	strokes.forEach(stroke => {
		if (stroke.length < 2) return;
		
		ctx.strokeStyle = stroke[0].color;
		ctx.lineWidth = stroke[0].size;
		ctx.lineCap = 'round';
		ctx.lineJoin = 'round';
		
		ctx.beginPath();
		ctx.moveTo(stroke[0].x, stroke[0].y);
		
		for (let i = 1; i < stroke.length; i++) {
			ctx.lineTo(stroke[i].x, stroke[i].y);
		}
		ctx.stroke();
	});
	
	updatePlaceholder();
}

function updateButtons() {
	undoBtn.disabled = strokes.length === 0;
	redoBtn.disabled = redoStack.length === 0;
	saveBtn.disabled = strokes.length === 0;
}

function updatePlaceholder() {
	container.classList.toggle('has-content', strokes.length > 0);
}

function saveSignature() {
	if (strokes.length === 0) {
		showToast('Please draw your signature first', 'error');
		return;
	}
	
	const data = canvas.toDataURL('image/png');
	const form = document.createElement('form');
	form.method = 'POST';
	const input = document.createElement('input');
	input.type = 'hidden';
	input.name = 'signatureData';
	input.value = data;
	form.appendChild(input);
	document.body.appendChild(form);
	
	showToast('Saving signature...', 'success');
	form.submit();
}

function showToast(message, type = 'success') {
	const toast = document.createElement('div');
	toast.className = `toast ${type}`;
	toast.textContent = message;
	document.body.appendChild(toast);
	
	setTimeout(() => toast.classList.add('show'), 100);
	setTimeout(() => {
		toast.classList.remove('show');
		setTimeout(() => toast.remove(), 300);
	}, 3000);
}

// Keyboard shortcuts
document.addEventListener('keydown', (e) => {
	if (e.ctrlKey || e.metaKey) {
		if (e.key === 'z') {
			e.preventDefault();
			undoStroke();
		} else if (e.key === 'y') {
			e.preventDefault();
			redoStroke();
		}
	}
});

// Show feedback if exists
<?php if ($feedback): ?>
showToast(<?= json_encode($feedback) ?>, <?= json_encode(strpos($feedback, 'Error:') === 0 ? 'error' : 'success') ?>);
<?php endif; ?>
</script>
<?php require_once __DIR__ . '/../includes/mobile_footer.php'; ?>
</body>
</html>


<?php
require_once '../includes/functions.php';
requireLogin();

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle array of tasks
    $tasks = $_POST['tasks'] ?? [];
    
    // Filter out empty lines
    $tasks = array_filter($tasks, function($t) { return trim($t) !== ''; });
    
    if (empty($tasks)) {
        $error = 'Please enter at least one task for your overtime.';
    } else {
        // Auto-number the tasks
        $formattedDescription = "";
        $i = 1;
        foreach ($tasks as $task) {
            $formattedDescription .= $i . ". " . trim($task) . "\n";
            $i++;
        }
        $formattedDescription = trim($formattedDescription);

        try {
            ensureTasksSchema();
            $stmt = $pdo->prepare("INSERT INTO tasks (user_id, type, description) VALUES (?, 'overtime', ?)");
            $stmt->execute([$user_id, $formattedDescription]);
            header('Location: dashboard.php?overtime_submitted=1');
            exit;
        } catch (Exception $e) {
            $error = 'Failed to submit overtime tasks. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Overtime Report - Ultimate General Trading</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Override body styles from inline definition to match system layout */
        body {
            font-family: var(--font-primary, 'Inter', sans-serif); /* Use system font var */
            background-color: #f3f4f6;
            display: block; /* Was flex */
            margin: 0;
            padding: 0;
            min-height: 100vh;
        }

        /* Center container in the main content area */
        .task-container {
            max-width: 600px;
            margin: 40px auto;
            padding: 0 20px;
        }

        .task-card {
            background: white;
            padding: 40px;
            border-radius: 8px; /* Standardize */
            box-shadow: 0 1px 3px rgba(0,0,0,0.1); /* Subtler shadow */
            width: 100%;
            animation: slideUp 0.4s ease-out;
        }
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .header-icon {
            width: 60px;
            height: 60px;
            background: #dbeafe;
            color: #2563eb;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
        }
        h2 {
            text-align: center;
            color: #111827;
            margin: 0 0 8px;
            font-weight: 700;
        }
        p.subtitle {
            text-align: center;
            color: #6b7280;
            margin-bottom: 30px;
            font-size: 0.95rem;
        }
        textarea, input[type="text"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-family: inherit;
            font-size: 0.95rem;
            margin-bottom: 0; /* Handled by group */
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        input:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.1);
        }
        .btn-submit {
            width: 100%;
            padding: 12px;
            background: #2563eb;
            color: white;
            border: none;
            border-radius: 6px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s;
            font-size: 1rem;
        }
        .btn-submit:hover {
            background: #1d4ed8;
        }
        .error-msg {
            background: #fee2e2;
            color: #991b1b;
            padding: 10px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 0.9rem;
            text-align: center;
        }
    </style>
</head>
<body>
    <?php require_once '../includes/header_employee.php'; ?>

    <main class="main-content">
        <div class="task-container">
            <div class="task-card">
                <div class="header-icon" style="background: #e0e7ff; color: #4338ca;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <polyline points="12 6 12 12 16 14"></polyline>
                    </svg>
                </div>
                <h2>Overtime Report</h2>
                <p class="subtitle">Please update the tasks you completed during your overtime.</p>
                
                <?php if ($error): ?>
                    <div class="error-msg"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="POST" id="taskForm">
                    <div id="taskList">
                        <div class="task-input-group" style="display:flex; gap:10px; margin-bottom:10px;">
                            <span class="task-number" style="padding:10px; background:#f3f4f6; border:1px solid #e5e7eb; border-radius:6px; display:flex; align-items:center; font-weight:600; color:#4b5563; min-width: 40px; justify-content: center;">1</span>
                            <input type="text" name="tasks[]" class="task-input" placeholder="Enter task..." required style="flex:1;">
                        </div>
                    </div>
                    
                    <button type="button" onclick="addTask()" style="background:transparent; color:#4338ca; border:1px dashed #4338ca; padding:8px 16px; border-radius:6px; cursor:pointer; font-weight:500; margin-bottom:20px; font-size:0.9rem; width: 100%;">
                        + Add Another Task
                    </button>

                    <button type="submit" class="btn-submit" style="background: #4338ca;">Submit Overtime Report</button>
                </form>
            </div>
        </div>
    </main>

    <script>
        function addTask() {
            const list = document.getElementById('taskList');
            const count = list.children.length + 1;
            
            const div = document.createElement('div');
            div.className = 'task-input-group';
            div.style.cssText = 'display:flex; gap:10px; margin-bottom:10px; animation: fadeIn 0.3s ease-out;';
            div.innerHTML = `
                <span class="task-number" style="padding:10px; background:#f3f4f6; border:1px solid #e5e7eb; border-radius:6px; display:flex; align-items:center; font-weight:600; color:#4b5563; min-width: 40px; justify-content: center;">${count}</span>
                <input type="text" name="tasks[]" class="task-input" placeholder="Enter task..." required style="flex:1;">
                <button type="button" onclick="removeTask(this)" style="background:#fee2e2; color:#ef4444; border:none; padding:0 12px; border-radius:6px; cursor:pointer; font-size:1.2rem;">&times;</button>
            `;
            list.appendChild(div);
            
            // Auto focus new input
            div.querySelector('input').focus();
        }

        function removeTask(btn) {
            const list = document.getElementById('taskList');
            if (list.children.length > 1) {
                btn.parentElement.remove();
                renumberTasks();
            }
        }

        function renumberTasks() {
            const list = document.getElementById('taskList');
            Array.from(list.children).forEach((div, index) => {
                div.querySelector('.task-number').innerText = index + 1;
            });
        }
    </script>
    <style>
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(5px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</body>
</html>

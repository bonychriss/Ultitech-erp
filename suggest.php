<?php
require_once 'includes/functions.php';
requireLogin();

// Ensure table exists (Auto-migration)
try {
    $pdo->query("SELECT 1 FROM developer_suggestions LIMIT 1");
} catch (PDOException $e) {
    if ($e->getCode() == '42S02' || strpos($e->getMessage(), 'doesn\'t exist') !== false) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS developer_suggestions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            suggestion TEXT NOT NULL,
            status ENUM('pending', 'accomplished', 'impossible') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    }
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['suggestion'])) {
    $suggestion = trim($_POST['suggestion']);
    if (!empty($suggestion)) {
        try {
            global $pdo;
            $stmt = $pdo->prepare("INSERT INTO developer_suggestions (user_id, suggestion) VALUES (?, ?)");
            $stmt->execute([$_SESSION['user_id'], $suggestion]);
            $success = "Feature request submitted successfully! The developer will review it.";
        } catch (Exception $e) {
            $error = "Error: " . $e->getMessage();
        }
    } else {
        $error = "Please enter a valid suggestion.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Suggest a Feature - Coverage</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Poppins', sans-serif; background: #f8fafc; color: #1e293b; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .box { background: #ffffff; padding: 30px; border-radius: 0; max-width: 500px; width: 90%; text-align: center; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }
        textarea { width: 100%; padding: 8px 10px; background: #f8fafc; color: #334155; border: 1px solid #cbd5e1; border-radius: 0; margin: 15px 0; font-family: inherit; resize: vertical; box-sizing: border-box; outline: none; min-height: 36px; }
        textarea:focus { border-color: #3b82f6; ring: 2px solid #3b82f6; }
        .btn { background: #3b82f6; color: white; border: none; padding: 10px 20px; border-radius: 0; font-weight: 600; cursor: pointer; transition: 0.2s; box-shadow: none; }
        .btn:hover { background: #2563eb; transform: translateY(-1px); }
        .btn-secondary { background: #e2e8f0 !important; color: #475569 !important; border-radius: 0 !important; }
        .back-link { display: block; margin-top: 20px; color: #64748b; text-decoration: none; font-size: 13px; font-weight: 500; }
        .back-link:hover { color: #3b82f6; }
        .msg { padding: 8px; border-radius: 0; margin-bottom: 20px; font-size: 13px; }
        .success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
    </style>
</head>
<body>
    <div class="box">
        <h2 style="color:#1e293b; margin-top:0;">💡 Suggest a Feature</h2>
        <p style="color:#64748b; font-size:14px;">Help us improve the system. Submissions are reviewed directly by the Developer.</p>
        
        <?php if($success): ?><div class="msg success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
        <?php if($error): ?><div class="msg error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <form method="POST">
            <textarea name="suggestion" id="suggestionBox" rows="1" placeholder="1. Suggestion one..." required></textarea>
            <div style="display:flex; justify-content:space-between; margin-bottom:15px;">
                <button type="button" id="addLineBtn" class="btn-secondary" style="background:#e2e8f0; color:#475569; border:none; padding:8px 16px; border-radius:6px; font-size:13px; font-weight:500; cursor:pointer;">+ Add another line</button>
                <div style="flex:1;"></div>
            </div>
            <button type="submit" class="btn" style="width:100%;">Submit to Developer</button>
        </form>

        <a href="select-module.php" class="back-link">← Return to Dashboard</a>

        <!-- Suggestions Feed -->
        <div style="margin-top: 40px; text-align: left; border-top: 1px solid #e2e8f0; paddingTop: 20px;">
            <h3 style="font-size: 16px; color: #1e293b; margin-bottom: 15px;">Recent Suggestions</h3>
            <?php
            // Fetch recent suggestions
            try {
                global $pdo;
                $stmt = $pdo->query("SELECT s.*, u.full_name FROM developer_suggestions s JOIN users u ON s.user_id = u.id ORDER BY s.created_at DESC LIMIT 20");
                $suggestions = $stmt->fetchAll();
            } catch (Exception $e) { $suggestions = []; }
            ?>
            
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
                    <thead>
                        <tr style="background: #f1f5f9; text-align: left;">
                            <th style="padding: 10px; border: 1px solid #e2e8f0;">Suggestion</th>
                            <th style="padding: 10px; border: 1px solid #e2e8f0;">By</th>
                            <th style="padding: 10px; border: 1px solid #e2e8f0;">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($suggestions as $s): ?>
                        <tr>
                            <td style="padding: 10px; border: 1px solid #e2e8f0; color: #334155;"><?= nl2br(htmlspecialchars($s['suggestion'])) ?></td>
                            <td style="padding: 10px; border: 1px solid #e2e8f0; color: #64748b; white-space: nowrap;"><?= htmlspecialchars($s['full_name']) ?></td>
                            <td style="padding: 10px; border: 1px solid #e2e8f0; text-align: center;">
                                <?php if($s['status'] == 'pending'): ?>
                                    <span style="background: #fef9c3; color: #854d0e; padding: 2px 6px; font-size: 11px; font-weight: 600; text-transform: uppercase;">Pending</span>
                                <?php elseif($s['status'] == 'accomplished'): ?>
                                    <span style="background: #dcfce7; color: #166534; padding: 2px 6px; font-size: 11px; font-weight: 600; text-transform: uppercase;">Done</span>
                                <?php else: ?>
                                    <span style="background: #fee2e2; color: #991b1b; padding: 2px 6px; font-size: 11px; font-weight: 600; text-transform: uppercase;">Impossible</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($suggestions)): ?>
                        <tr><td colspan="3" style="padding:15px; text-align:center; color:#94a3b8;">No suggestions yet. Be the first!</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        const textarea = document.getElementById('suggestionBox');
        const addLineBtn = document.getElementById('addLineBtn');

        // Auto-start with "1. " on focus if empty
        textarea.addEventListener('focus', function() {
            if (this.value.trim() === '') {
                this.value = '1. ';
            }
        });

        // Manual Add Line Button Logic
        addLineBtn.addEventListener('click', function() {
            const currentVal = textarea.value;
            // Find last number used
            const matches = currentVal.match(/^(\d+)\.\s/gm);
            let nextNum = 1;
            
            if (matches && matches.length > 0) {
                // Get the number from the last match
                const lastMatch = matches[matches.length - 1];
                nextNum = parseInt(lastMatch.match(/\d+/)[0]) + 1;
            }
            
            const insert = (currentVal.trim() === '' ? '' : '\n') + nextNum + '. ';
            textarea.value += insert;
            textarea.focus();
            textarea.scrollTop = textarea.scrollHeight;
        });
    </script>
</body>
</html>

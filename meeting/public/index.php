<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Online Meeting System</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= time() ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="container">
        <!-- Left Side: Branding -->
        <div class="branding-section">
            <h1>Meeting System</h1>
            <p>Secure, high-quality video meetings for everyone. Connect with your team, friends, or family instantly.</p>
        </div>

        <!-- Right Side: Action -->
        <div class="action-section">
            <div class="card">
                <h2>Welcome</h2>
                <div class="tabs">
                    <button class="tab-btn active" onclick="showTab('join-meeting')">Join Meeting</button>
                    <button class="tab-btn" onclick="showTab('create-meeting')">New Meeting</button>
                </div>

                <div id="join-meeting" class="tab-content active">
                    <form action="meeting.php" method="GET">
                        <div class="form-group">
                            <label>Meeting Code</label>
                            <input type="text" name="room" placeholder="e.g. abc-def-ghi" required>
                        </div>
                        <div class="form-group">
                            <label>Your Name</label>
                            <input type="text" name="user" placeholder="Enter your name" required>
                        </div>
                        <button type="submit" class="btn-primary">Join Now</button>
                    </form>
                </div>

                <div id="create-meeting" class="tab-content">
                    <p style="color: var(--text-secondary); margin-bottom: 1.5rem;">Start a new meeting instantly and invite others.</p>
                    <div class="form-group">
                        <label>Your Name</label>
                        <input type="text" id="host-name" placeholder="Enter your name" required>
                    </div>
                    <button id="createBtn" class="btn-primary">Create New Meeting</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        function showTab(tabId) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
            
            // Find the button that triggered this
            const buttons = document.querySelectorAll('.tab-btn');
            buttons.forEach(btn => {
                if(btn.getAttribute('onclick').includes(tabId)) {
                    btn.classList.add('active');
                }
            });

            document.getElementById(tabId).classList.add('active');
        }

        document.getElementById('createBtn').addEventListener('click', async () => {
            const hostName = document.getElementById('host-name').value;
            if (!hostName) {
                alert('Please enter your name');
                return;
            }

            const formData = new FormData();
            formData.append('action', 'create_meeting');
            const res = await fetch('api.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.status === 'success') {
                window.location.href = `meeting.php?room=${data.meeting_code}&user=${encodeURIComponent(hostName)}&role=host`;
            } else {
                alert(data.message);
            }
        });
    </script>
</body>
</html>


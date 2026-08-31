/**
 * ERP Global Reminder Service
 * Synchronizes with the To-Do module using localStorage
 */

const ReminderService = {
    // Save a new task/reminder to localStorage
    saveTask: function(title, desc, date, time, sound) {
        let tasks = JSON.parse(localStorage.getItem('tasks') || '[]');
        const newTask = {
            id: Date.now(),
            title: title,
            desc: desc,
            completed: false,
            date: date, // YYYY-MM-DD
            reminderTime: time, // h:i K (e.g. 09:00 AM)
            reminderSound: sound || 'bell notification.wav',
            reminded: false,
            createdAt: new Date().toISOString()
        };
        tasks.unshift(newTask);
        localStorage.setItem('tasks', JSON.stringify(tasks));
        return newTask;
    },

    // Check for active reminders
    checkReminders: function() {
        let tasks = JSON.parse(localStorage.getItem('tasks') || '[]');
        const now = new Date();
        const currentDate = now.toISOString().split('T')[0];
        
        // Format now to match Flatpickr "h:i K"
        const hours = now.getHours();
        const suffix = hours >= 12 ? 'PM' : 'AM';
        const h12 = hours % 12 || 12;
        const mins = now.getMinutes().toString().padStart(2, '0');
        const currentTimeStr = `${h12.toString().padStart(2, '0')}:${mins} ${suffix}`;
        
        let updateNeeded = false;

        tasks = tasks.map(task => {
            if(!task.completed && task.reminderTime && !task.reminded) {
                if(task.date === currentDate && task.reminderTime === currentTimeStr) {
                    this.triggerReminder(task);
                    updateNeeded = true;
                    return {...task, reminded: true};
                }
            }
            return task;
        });

        if(updateNeeded) {
            localStorage.setItem('tasks', JSON.stringify(tasks));
            // Trigger a custom event so the To-Do page can refresh if open
            window.dispatchEvent(new Event('storage'));
        }
    },

    // Trigger the actual notification
    triggerReminder: function(task) {
        // 1. Browser Notification
        if ('Notification' in window && Notification.permission === 'granted') {
            new Notification('ERP Reminder', { body: task.title });
        }

        // 2. Visual Bell Glow (Replaces SweetAlert Toast)
        const bellIcon = document.querySelector('#notif-dd .bi-bell');
        if (bellIcon) {
            // Inject animation styles if not already present
            if (!document.getElementById('bell-glow-style')) {
                const style = document.createElement('style');
                style.id = 'bell-glow-style';
                style.innerHTML = `
                    @keyframes bellGlowPulse {
                        0% { filter: drop-shadow(0 0 2px #ef4444); color: #ef4444 !important; transform: scale(1); }
                        25% { transform: scale(1.15) rotate(12deg); }
                        50% { filter: drop-shadow(0 0 14px #ef4444); color: #ef4444 !important; transform: scale(1.15) rotate(-12deg); }
                        75% { transform: scale(1.15) rotate(12deg); }
                        100% { filter: drop-shadow(0 0 2px #ef4444); color: #ef4444 !important; transform: scale(1); }
                    }
                    .bell-glow-active {
                        animation: bellGlowPulse 1.2s infinite ease-in-out;
                        transition: all 0.3s;
                    }
                `;
                document.head.appendChild(style);
            }
            
            // Apply glow class
            bellIcon.classList.add('bell-glow-active');
            
            // Inject the reminder into the actual notification dropdown so the user sees it when they click the glowing bell
            const notifList = document.getElementById('notif-list');
            const notifCount = document.getElementById('notif-count');
            if (notifList && notifCount) {
                // Increment red badge count
                let currentCount = parseInt(notifCount.textContent) || 0;
                notifCount.textContent = currentCount + 1;
                notifCount.style.display = 'inline-flex';
                
                // Clear 'loading' or 'no reminders' text if present
                if(notifList.innerHTML.includes('No reminders') || notifList.innerHTML.includes('Loading')) {
                    notifList.innerHTML = '';
                }
                
                // Construct a custom notification card for the dropdown
                const safeTitle = String(task.title).replace(/[&<>"']/g, function(m){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]); });
                const safeDesc = String(task.desc).replace(/[&<>"']/g, function(m){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]); });
                
                const newItem = `
                    <a href="${window.APP_BASE_URL}/todo/index.php" style="display:block; padding:16px 20px; text-decoration:none; color:#1e293b; border-bottom:1px solid #f8fafc; background: #fff1f2; transition: background 0.2s;">
                        <div style="display:flex; justify-content:space-between; gap:12px; align-items:flex-start;">
                            <div style="font-weight:700; font-size:14px; color: #e11d48;"><i class="fas fa-clock" style="margin-right:6px;"></i> Local Reminder</div>
                            <div style="flex-shrink:0;"><span style="font-size:10px; font-weight:700; text-transform:uppercase; background:#ef4444; color:#fff; padding:2px 6px; border-radius:4px;">DUE NOW</span></div>
                        </div>
                        <div style="margin-top:6px; font-weight:600; color:#111827; font-size:14px; line-height:1.4;">${safeTitle}</div>
                        <div style="margin-top:4px; color:#64748b; font-size:13px; line-height:1.5;">${safeDesc}</div>
                    </a>
                `;
                notifList.insertAdjacentHTML('afterbegin', newItem);
            }
            
            // Remove glow when user opens the notification menu
            const notifBtn = document.querySelector('#notif-dd button');
            if (notifBtn) {
                notifBtn.addEventListener('click', function removeGlow() {
                    bellIcon.classList.remove('bell-glow-active');
                    notifBtn.removeEventListener('click', removeGlow);
                });
            }
        }

        // 3. Audio (Try-catch for autoplay policies)
        try {
            if (task.reminderSound && task.reminderSound !== 'none') {
                const audio = new Audio(window.APP_BASE_URL + '/todo/notification sound/' + task.reminderSound);
                audio.play().catch(e => console.log('Autoplay blocked'));
            }
        } catch(e) {}
    },

    // UI Helper: Open a SweetAlert to set a quick reminder
    promptReminder: function(title, desc) {
        if (typeof Swal === 'undefined') {
            console.error('SweetAlert2 is required for ReminderService');
            return;
        }

        const today = new Date().toISOString().split('T')[0];
        
        Swal.fire({
            title: 'Set Reminder',
            html: `
                <style>
                    .rmd-input {
                        width: 100%; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; color: #1e293b; background: #f8fafc; transition: all 0.2s; box-sizing: border-box; font-family: inherit;
                    }
                    .rmd-input:focus {
                        border-color: #3b82f6; outline: none; box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15); background: #ffffff;
                    }
                    .rmd-label {
                        display: block; font-size: 11px; font-weight: 700; color: #64748b; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px;
                    }
                    .rmd-icon {
                        color: #94a3b8; margin-right: 6px; font-size: 12px;
                    }
                    .rmd-input::-webkit-calendar-picker-indicator {
                        cursor: pointer;
                        opacity: 0.5;
                        transition: 0.2s;
                        padding: 5px;
                    }
                    .rmd-input::-webkit-calendar-picker-indicator:hover {
                        opacity: 0.9;
                    }
                </style>
                <div style="text-align: left; padding-top: 5px;">
                    <div style="margin-bottom: 18px;">
                        <label class="rmd-label"><i class="fas fa-tasks rmd-icon"></i> Reminder Title</label>
                        <input id="swal-reminder-title" class="rmd-input" value="${title}" placeholder="What do you need to remember?">
                    </div>
                    
                    <div style="display:flex; gap:16px; margin-bottom: 18px; background: #f1f5f9; padding: 16px; border-radius: 10px; border: 1px solid #e2e8f0;">
                        <div style="flex:1">
                            <label class="rmd-label" style="color: #334155;"><i class="fas fa-calendar-alt rmd-icon"></i> Reminder Date</label>
                            <input id="swal-reminder-date" type="date" class="rmd-input" style="cursor: pointer; background: #ffffff;" value="${today}">
                        </div>
                        <div style="flex:1">
                            <label class="rmd-label" style="color: #334155;"><i class="fas fa-clock rmd-icon"></i> Reminder Time</label>
                            <input id="swal-reminder-time" type="time" class="rmd-input" style="accent-color: #ec4899; cursor: pointer; background: #ffffff;">
                        </div>
                    </div>
                    
                    <div style="margin-bottom: 4px;">
                        <label class="rmd-label"><i class="fas fa-volume-up rmd-icon"></i> Notification Sound</label>
                        <select id="swal-reminder-sound" class="rmd-input" style="cursor: pointer;">
                            <option value="bell notification.wav">Bell Notification</option>
                            <option value="alarm reminder.wav">Alarm Reminder</option>
                            <option value="baby alarm reminder.wav">Baby Alarm Reminder</option>
                            <option value="chicken alarm.wav">Chicken Alarm</option>
                            <option value="none">None (Silent)</option>
                        </select>
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Save Reminder',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#111827',
            cancelButtonColor: '#ef4444',
            customClass: {
                popup: 'rounded-4 shadow-lg border-0',
                title: 'fs-5 fw-bold text-dark',
                confirmButton: 'btn btn-dark px-4 py-2 rounded-3 fw-semibold',
                cancelButton: 'btn btn-danger px-4 py-2 rounded-3 fw-semibold ms-2'
            },
            buttonsStyling: false,
            preConfirm: () => {
                const rTitle = document.getElementById('swal-reminder-title').value;
                const rDate = document.getElementById('swal-reminder-date').value;
                let rTime = document.getElementById('swal-reminder-time').value; // Returns 24h format HH:mm:ss
                const rSound = document.getElementById('swal-reminder-sound').value;
                
                if (!rTitle || !rDate || !rTime) {
                    Swal.showValidationMessage('Please fill in all fields');
                    return false;
                }
                
                // Convert 24h native time (HH:mm:ss or HH:mm) to 12h "h:mm AM/PM" format expected by the service
                try {
                    let [hours, minutes] = rTime.split(':');
                    hours = parseInt(hours, 10);
                    const ampm = hours >= 12 ? 'PM' : 'AM';
                    hours = hours % 12 || 12; // Convert '0' to '12'
                    rTime = `${hours.toString().padStart(2, '0')}:${minutes} ${ampm}`;
                } catch(e) {
                    Swal.showValidationMessage('Invalid time format');
                    return false;
                }
                
                return { title: rTitle, date: rDate, time: rTime, sound: rSound };
            }
        }).then((result) => {
            if (result.isConfirmed) {
                this.saveTask(result.value.title, desc, result.value.date, result.value.time, result.value.sound);
                
                // Immediately refresh the notification dropdown to show the new local reminder
                if (typeof refreshNotif === 'function') {
                    refreshNotif();
                }
                
                Swal.fire({
                    icon: 'success',
                    title: 'Reminder Set!',
                    text: `We will notify you at ${result.value.time} on ${result.value.date}`,
                    timer: 2000,
                    showConfirmButton: false
                });
            }
        });
    }
};

// Start the background checker if we're not already in the To-Do module
// (The To-Do module has its own checker)
if (!window.location.pathname.includes('/todo/')) {
    setInterval(() => ReminderService.checkReminders(), 10000);
}

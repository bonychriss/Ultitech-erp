/**
 * Weekly Mission dashboard UI
 */
(function () {
    'use strict';

    const cfg = window.WM_CONFIG || {};
    const API = cfg.apiUrl || 'api/weekly_missions.php';
    const MAX = cfg.maxMissions || 7;
    const IS_ADMIN = !!cfg.isAdmin;
    const USER_ID = cfg.userId || 0;
    const STORAGE_KEY = 'weeklyMission';

    let weekStart = null;
    let missions = [];
    let summary = {};
    let followUp = null;
    let monthPerf = {};
    let leaderboard = [];
    let teamProgress = {};
    let teamChart = null;
    let adminUsers = [];
    let adminDepartments = [];
    let allMissions = [];
    let adminViewMode = 'grid';
    let selectedUserId = 0;
    let adminFilters = { department: '' };

    function api(action, opts = {}) {
        const method = opts.method || 'GET';
        const params = new URLSearchParams({ action, ...(opts.params || {}) });
        if (weekStart) params.set('week_start', weekStart);
        const url = API + '?' + params.toString();
        const fetchOpts = { method, credentials: 'same-origin', headers: {} };
        if (opts.body) {
            fetchOpts.headers['Content-Type'] = 'application/json';
            fetchOpts.body = JSON.stringify(opts.body);
        }
        return fetch(url, fetchOpts).then(async (response) => {
            const text = await response.text();
            let data;
            try {
                data = text ? JSON.parse(text) : {};
            } catch (e) {
                console.error('Weekly Mission API returned non-JSON:', text.slice(0, 400));
                return {
                    success: false,
                    error: 'Server returned an invalid response. You may be logged out or the API path is wrong.',
                };
            }
            if (!response.ok && !data.error) {
                data.error = data.error || ('Request failed (' + response.status + ')');
                data.success = false;
            }
            return data;
        });
    }

    function escapeHtml(t) {
        const d = document.createElement('div');
        d.textContent = t == null ? '' : String(t);
        return d.innerHTML;
    }

    function formatDate(iso) {
        if (!iso) return '\u2014';
        const d = new Date(iso);
        if (isNaN(d.getTime())) return '\u2014';
        return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function formatDateTime(iso) {
        if (!iso) return '\u2014';
        const d = new Date(iso);
        if (isNaN(d.getTime())) return '\u2014';
        const date = d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
        const time = d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
        return date + ' at ' + time;
    }

    function initials(name) {
        const parts = String(name || '').trim().split(/\s+/);
        if (parts.length >= 2) {
            return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
        }
        return (parts[0] && parts[0][0] ? parts[0][0] : '?').toUpperCase();
    }

    function statusBadge(displayStatus) {
        const map = {
            'Completed': 'wm-status-completed',
            'In Progress': 'wm-status-progress',
            'Pending Review': 'wm-status-review',
            'Not Started': 'wm-status-notstarted',
        };
        const cls = map[displayStatus] || 'wm-status-notstarted';
        return `<span class="wm-status ${cls}">${escapeHtml(displayStatus)}</span>`;
    }

    async function tryImportLocalStorage() {
        try {
            const stored = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');
            if (!stored?.missions?.length) return;
            const res = await api('list');
            if (!res.success || stored.weekStart !== res.week.week_start) return;
            const imp = await api('import_local', {
                method: 'POST',
                body: { action: 'import_local', missions: stored.missions },
            });
            if (imp.success && imp.imported > 0) localStorage.removeItem(STORAGE_KEY);
        } catch (e) { /* ignore */ }
    }

    async function loadDashboard() {
        if (IS_ADMIN) {
            await loadAdminOverview();
            return;
        }
        const app = document.getElementById('wmApp');
        app?.classList.add('wm-loading');
        let res;
        try {
            res = await api('dashboard');
        } catch (err) {
            console.error('Dashboard load failed:', err);
            Swal.fire({ icon: 'error', title: 'Error', text: 'Could not reach the server. Check that you are logged in.' });
            return;
        } finally {
            app?.classList.remove('wm-loading');
        }

        if (!res.success) {
            Swal.fire({ icon: 'error', title: 'Error', text: res.error || 'Failed to load' });
            return;
        }

        weekStart = res.week.week_start;
        missions = res.missions || [];
        summary = res.summary || {};
        followUp = res.follow_up || null;
        monthPerf = res.month_performance || {};
        leaderboard = res.leaderboard || [];
        teamProgress = res.team_progress || {};

        const start = new Date(res.week.week_start + 'T12:00:00');
        const end = new Date(res.week.week_end + 'T12:00:00');
        const opts = { month: 'short', day: 'numeric' };
        const label = document.getElementById('wmWeekLabel');
        if (label) {
            label.textContent = start.toLocaleDateString(undefined, opts) + ' \u2013 ' +
                end.toLocaleDateString(undefined, { ...opts, year: 'numeric' });
        }

        renderAll();
    }

    async function loadAdminOverview() {
        const app = document.getElementById('wmApp');
        app?.classList.add('wm-loading');
        let res;
        try {
            res = await api('admin_overview', {
                params: {
                    department: adminFilters.department,
                },
            });
        } catch (err) {
            console.error('Admin overview load failed:', err);
            Swal.fire({ icon: 'error', title: 'Error', text: 'Could not reach the server. Check that you are logged in.' });
            return;
        } finally {
            app?.classList.remove('wm-loading');
        }

        if (!res.success) {
            Swal.fire({ icon: 'error', title: 'Error', text: res.error || 'Failed to load team missions' });
            return;
        }

        weekStart = res.week.week_start;
        allMissions = res.missions || [];
        missions = allMissions;
        summary = res.summary || {};
        adminUsers = res.users || [];
        adminDepartments = res.departments || [];
        leaderboard = res.leaderboard || [];
        teamProgress = res.team_progress || {};

        if (selectedUserId && !adminUsers.some(u => Number(u.id) === selectedUserId)) {
            selectedUserId = 0;
            adminViewMode = 'grid';
        }

        const start = new Date(res.week.week_start + 'T12:00:00');
        const end = new Date(res.week.week_end + 'T12:00:00');
        const opts = { month: 'short', day: 'numeric' };
        const label = document.getElementById('wmWeekLabel');
        if (label) {
            label.textContent = start.toLocaleDateString(undefined, opts) + ' \u2013 ' +
                end.toLocaleDateString(undefined, { ...opts, year: 'numeric' });
        }

        populateAdminFilters();
        renderAll();
    }

    function populateAdminFilters() {
        const deptSel = document.getElementById('wmFilterDept');
        if (!deptSel) return;

        const prevDept = adminFilters.department;
        deptSel.innerHTML = '<option value="">All departments</option>' +
            adminDepartments.map(d => `<option value="${escapeHtml(d)}"${d === prevDept ? ' selected' : ''}>${escapeHtml(d)}</option>`).join('');
    }

    function getUserMissionStats(userId) {
        const userMissions = allMissions.filter(m => Number(m.user_id) === Number(userId));
        const completed = userMissions.filter(m => m.display_status === 'Completed').length;
        const pendingReview = userMissions.filter(m => m.display_status === 'Pending Review').length;
        const total = userMissions.length;
        const rate = total ? Math.round((completed / total) * 100) : 0;
        return { total, completed, pendingReview, rate, missions: userMissions };
    }

    function renderAdminUserGrid() {
        const grid = document.getElementById('wmAdminUserGrid');
        const gridPanel = document.getElementById('wmAdminGridPanel');
        const detailPanel = document.getElementById('wmAdminDetailPanel');
        if (!grid) return;

        gridPanel?.removeAttribute('hidden');
        detailPanel?.setAttribute('hidden', '');

        if (!adminUsers.length) {
            grid.innerHTML = '<p class="wm-empty">No employees found for this department.</p>';
            setText('wmTeamMeta', '0 employees');
            return;
        }

        const withMissions = adminUsers.filter(u => getUserMissionStats(u.id).total > 0).length;
        setText('wmTeamMeta', `${adminUsers.length} employee${adminUsers.length === 1 ? '' : 's'} · ${withMissions} with missions`);

        grid.innerHTML = adminUsers.map(u => {
            const stats = getUserMissionStats(u.id);
            const hasMissions = stats.total > 0;
            const statusLabel = hasMissions
                ? `${stats.completed}/${stats.total} completed`
                : 'No missions yet';
            const progressWidth = hasMissions ? stats.rate : 0;
            const pendingBadge = stats.pendingReview > 0
                ? `<span class="wm-user-card-badge">${stats.pendingReview} to review</span>`
                : '';

            return `
                <article class="wm-user-card${hasMissions ? '' : ' is-empty'}">
                    <div class="wm-user-card-head">
                        <span class="wm-top-avatar rank-1">${escapeHtml(initials(u.full_name))}</span>
                        <div class="wm-user-card-meta">
                            <strong class="wm-user-card-name">${escapeHtml(u.full_name)}</strong>
                            <span class="wm-user-card-dept">${escapeHtml(u.department || 'No department')}</span>
                        </div>
                        ${pendingBadge}
                    </div>
                    <div class="wm-user-card-stats">
                        <span>${escapeHtml(statusLabel)}</span>
                        <strong>${progressWidth}%</strong>
                    </div>
                    <div class="wm-user-card-progress" aria-hidden="true">
                        <div class="wm-user-card-progress-fill" style="width:${progressWidth}%"></div>
                    </div>
                    <button type="button" class="wm-btn-primary wm-btn-sm wm-user-card-btn" data-user-id="${u.id}">
                        ${hasMissions ? 'Review missions' : 'View employee'}
                    </button>
                </article>
            `;
        }).join('');

        grid.querySelectorAll('[data-user-id]').forEach(btn => {
            btn.addEventListener('click', () => openUserReview(+btn.dataset.userId));
        });
    }

    function openUserReview(userId) {
        selectedUserId = userId;
        adminViewMode = 'detail';
        renderAdminUserDetail();
    }

    function backToAdminGrid() {
        selectedUserId = 0;
        adminViewMode = 'grid';
        renderAdminUserGrid();
    }

    function renderAdminUserDetail() {
        const gridPanel = document.getElementById('wmAdminGridPanel');
        const detailPanel = document.getElementById('wmAdminDetailPanel');
        const tbody = document.getElementById('wmAdminDetailBody');
        const userBox = document.getElementById('wmAdminDetailUser');
        if (!detailPanel || !tbody || !userBox) return;

        gridPanel?.setAttribute('hidden', '');
        detailPanel.removeAttribute('hidden');

        const user = adminUsers.find(u => Number(u.id) === selectedUserId);
        const stats = getUserMissionStats(selectedUserId);
        if (!user) {
            backToAdminGrid();
            return;
        }

        userBox.innerHTML = `
            <span class="wm-top-avatar rank-1">${escapeHtml(initials(user.full_name))}</span>
            <div>
                <strong class="wm-user-card-name">${escapeHtml(user.full_name)}</strong>
                <span class="wm-user-card-dept">${escapeHtml(user.department || 'No department')} · ${stats.total} mission${stats.total === 1 ? '' : 's'} · ${stats.rate}% complete</span>
            </div>
        `;

        tbody.innerHTML = '';
        if (!stats.missions.length) {
            tbody.innerHTML = '<tr class="wm-table-empty"><td colspan="5">This employee has not assigned any missions for this week.</td></tr>';
            setText('wmAdminDetailMeta', '0 missions');
            document.getElementById('wmAdminSaveRemarks')?.setAttribute('hidden', '');
            return;
        }

        document.getElementById('wmAdminSaveRemarks')?.removeAttribute('hidden');

        stats.missions.forEach(m => {
            const tr = document.createElement('tr');

            const titleTd = document.createElement('td');
            titleTd.innerHTML = `<span class="wm-mission-title ${m.display_status === 'Completed' ? 'done' : ''}">${escapeHtml(m.title)}</span>`;

            const statusTd = document.createElement('td');
            statusTd.className = 'col-status';
            statusTd.innerHTML = statusBadge(m.display_status);

            const remarksTd = document.createElement('td');
            remarksTd.className = 'col-remarks';
            const remarkInput = document.createElement('textarea');
            remarkInput.className = 'wm-admin-remark-input';
            remarkInput.dataset.missionId = String(m.id);
            remarkInput.rows = 2;
            remarkInput.placeholder = 'Add feedback or remarks for this mission...';
            remarkInput.value = m.admin_comment || '';
            remarksTd.appendChild(remarkInput);
            if (m.employee_reply) {
                const reply = document.createElement('p');
                reply.className = 'wm-admin-remark-reply';
                reply.innerHTML = `<strong>Employee reply:</strong> ${escapeHtml(m.employee_reply)}`;
                remarksTd.appendChild(reply);
            }

            const updatedTd = document.createElement('td');
            updatedTd.className = 'col-updated';
            updatedTd.textContent = formatDate(m.updated_at);

            const actionsTd = document.createElement('td');
            actionsTd.className = 'col-actions';
            actionsTd.innerHTML = `
                <div class="dropdown">
                    <button class="wm-menu-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="fas fa-ellipsis-v"></i></button>
                    <ul class="dropdown-menu dropdown-menu-end wm-dropdown-menu">
                        <li><a class="dropdown-item" href="#" data-action="complete" data-id="${m.id}"><i class="fas fa-check me-2"></i>Mark Completed</a></li>
                    </ul>
                </div>
            `;

            tr.appendChild(titleTd);
            tr.appendChild(statusTd);
            tr.appendChild(remarksTd);
            tr.appendChild(updatedTd);
            tr.appendChild(actionsTd);
            tbody.appendChild(tr);
        });

        setText('wmAdminDetailMeta', `Showing ${stats.missions.length} mission${stats.missions.length === 1 ? '' : 's'}`);

        tbody.querySelectorAll('[data-action]').forEach(el => {
            el.addEventListener('click', e => {
                e.preventDefault();
                const id = +el.dataset.id;
                const action = el.dataset.action;
                if (action === 'complete') toggleComplete(id, true);
            });
        });
    }

    async function saveAdminRemarks() {
        const inputs = document.querySelectorAll('#wmAdminDetailBody .wm-admin-remark-input');
        if (!inputs.length) return;

        const btn = document.getElementById('wmAdminSaveRemarks');
        btn?.setAttribute('disabled', 'disabled');

        let saved = 0;
        let failed = false;
        for (const input of inputs) {
            const id = +input.dataset.missionId;
            const mission = allMissions.find(m => m.id === id);
            const comment = input.value.trim();
            const previous = (mission?.admin_comment || '').trim();
            if (comment === previous) continue;

            const res = await api('admin_comment', {
                method: 'POST',
                body: { action: 'admin_comment', id, admin_comment: comment },
            });
            if (!res.success) {
                failed = true;
                Swal.fire({ icon: 'error', text: res.error || 'Could not save remarks' });
                break;
            }
            saved++;
            if (mission) mission.admin_comment = comment;
        }

        btn?.removeAttribute('disabled');

        if (failed) return;
        if (saved > 0) {
            Swal.fire({ icon: 'success', title: 'Saved', text: 'Admin remarks updated.', timer: 1800, showConfirmButton: false });
            await loadAdminOverview();
        } else {
            Swal.fire({ icon: 'info', title: 'No changes', text: 'Remarks are already up to date.', timer: 1600, showConfirmButton: false });
        }
    }

    function renderKpis() {
        const s = summary;
        const total = s.total || 0;
        const done = s.completed || 0;
        const pctDone = total ? ((done / total) * 100).toFixed(1) : '0';

        setText('wmStatTotal', total);
        setText('wmStatCompleted', done);
        setText('wmStatReview', s.pending_review ?? 0);
        setText('wmStatScore', (s.performance_score ?? 0) + '%');

        setText('wmHintTotal', IS_ADMIN ? 'Across all employees' : 'Assigned for the week');
        setText('wmHintCompleted', pctDone + '% completed');
        const score = s.performance_score ?? 0;
        setText('wmHintScore', IS_ADMIN
            ? 'Team completion rate'
            : (score >= 80 ? 'Great job! Keep it up.' : 'Complete missions to improve your score.'));
    }

    function setText(id, val) {
        const el = document.getElementById(id);
        if (el) el.textContent = val;
    }

    function renderTable() {
        if (IS_ADMIN) return;
        const tbody = document.getElementById('wmMissionBody');
        if (!tbody) return;

        tbody.innerHTML = '';
        const show = missions;
        const colSpan = 5;

        if (!show.length) {
            tbody.innerHTML = `<tr class="wm-table-empty"><td colspan="${colSpan}">No missions yet. Add up to 7 goals for this week.</td></tr>`;
            setText('wmTableMeta', 'Showing 0 of 0 missions');
            const viewAll = document.getElementById('wmViewAll');
            if (viewAll) viewAll.hidden = true;
            return;
        }

        show.forEach(m => {
            const done = m.display_status === 'Completed';
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td><div class="wm-check ${done ? 'done' : ''}" data-id="${m.id}" role="button" tabindex="0">${done ? '<i class="fas fa-check" style="font-size:10px"></i>' : ''}</div></td>
                <td><span class="wm-mission-title ${done ? 'done' : ''}">${escapeHtml(m.title)}</span></td>
                <td class="col-status">${statusBadge(m.display_status)}</td>
                <td class="col-updated">${formatDate(m.updated_at)}</td>
                <td class="col-actions">
                    <div class="dropdown">
                        <button class="wm-menu-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="fas fa-ellipsis-v"></i></button>
                        <ul class="dropdown-menu dropdown-menu-end wm-dropdown-menu">
                            <li><a class="dropdown-item" href="#" data-action="edit" data-id="${m.id}"><i class="fas fa-pen me-2"></i>Edit</a></li>
                            <li><a class="dropdown-item" href="#" data-action="complete" data-id="${m.id}"><i class="fas fa-check me-2"></i>Mark Completed</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="#" data-action="delete" data-id="${m.id}"><i class="fas fa-trash me-2"></i>Delete</a></li>
                        </ul>
                    </div>
                </td>
            `;
            tbody.appendChild(tr);
        });

        setText('wmTableMeta', show.length
            ? `Showing 1 to ${show.length} of ${missions.length} missions`
            : 'Showing 0 of 0 missions');
        const viewAll = document.getElementById('wmViewAll');
        if (viewAll) viewAll.hidden = true;

        if (!IS_ADMIN) {
            tbody.querySelectorAll('.wm-check').forEach(el => {
                el.addEventListener('click', () => toggleComplete(+el.dataset.id));
            });
        }
        tbody.querySelectorAll('[data-action]').forEach(el => {
            el.addEventListener('click', e => {
                e.preventDefault();
                const id = +el.dataset.id;
                const action = el.dataset.action;
                if (action === 'edit') editMission(id);
                else if (action === 'delete') deleteMission(id);
                else if (action === 'complete') toggleComplete(id, true);
                else if (action === 'review') adminReview(id);
            });
        });
    }

    function renderFollowUp() {
        const box = document.getElementById('wmFollowUp');
        const replyPanel = document.getElementById('wmFollowUpPanel');
        if (!box) return;

        if (!followUp) {
            box.innerHTML = '<p class="wm-muted">No admin comments for this week yet.</p>';
            if (replyPanel) replyPanel.querySelector('.wm-reply-box')?.classList.toggle('d-none', false);
            return;
        }

        box.innerHTML = `
            <div class="wm-followup-item d-flex gap-3 align-items-start mb-3" style="padding-top: 4px;">
                <div class="wm-comment-avatar d-flex align-items-center justify-content-center flex-shrink-0" 
                     style="width: 36px; height: 36px; border-radius: 50%; background: #7c3aed; color: #fff; font-weight: 700; font-size: 14px;">
                    A
                </div>
                <div class="flex-grow-1">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-1">
                        <div class="d-flex align-items-center gap-2">
                            <span style="font-weight: 700; font-size: 0.875rem; color: #1e293b;">${escapeHtml(followUp.admin_name || 'Admin')}</span>
                            <span class="wm-admin-tag" style="background: #ede9fe; color: #6d28d9; font-size: 9px; font-weight: 800; padding: 2px 6px; border-radius: 4px; letter-spacing: 0.05em;">ADMIN</span>
                        </div>
                        <span class="wm-followup-time" style="font-size: 11px; color: #94a3b8;">${formatDateTime(followUp.updated_at)}</span>
                    </div>
                    <p class="wm-followup-text" style="font-size: 0.875rem; color: #475569; margin: 0; line-height: 1.5;">${escapeHtml(followUp.admin_comment)}</p>
                    ${followUp.employee_reply ? `
                    <div class="wm-employee-reply mt-2 p-2 rounded" style="background: #f8fafc; border-left: 3px solid #7c3aed; font-size: 0.8125rem; color: #475569;">
                        <strong>You:</strong> ${escapeHtml(followUp.employee_reply)}
                    </div>` : ''}
                </div>
            </div>
        `;
        const ta = document.getElementById('wmReplyText');
        if (ta && followUp.employee_reply) ta.value = followUp.employee_reply;
    }

    function renderAwards() {
        setText('wmAwardLabel', monthPerf.award_label || 'Keep Going');
        const awardDateEl = document.getElementById('wmAwardDate');
        if (awardDateEl) {
            awardDateEl.textContent = monthPerf.award_earned_at
                ? `Award earned on ${formatDate(monthPerf.award_earned_at)}`
                : 'This month';
        }
        setText('wmMonthScore', monthPerf.score ?? 0);
        const delta = monthPerf.delta ?? 0;
        const el = document.getElementById('wmMonthDelta');
        if (el) {
            const sign = delta >= 0 ? '\u25B2 ' : '\u25BC ';
            el.textContent = `${sign}${Math.abs(delta)}% vs last month`;
            el.className = delta >= 0 ? 'wm-delta-up' : 'wm-delta-down';
        }
    }

    function renderLeaderboard() {
        const list = document.getElementById('wmLeaderboard');
        if (!list) return;

        if (!leaderboard.length) {
            list.innerHTML = '<li class="wm-top-item wm-top-empty"><span class="wm-muted">No rankings yet this week.</span></li>';
            return;
        }

        list.innerHTML = leaderboard.slice(0, 5).map(item => leaderboardRowHtml(item, false)).join('');

        const viewAllLb = document.getElementById('wmViewAllLeaderboard');
        if (viewAllLb) {
            viewAllLb.hidden = leaderboard.length === 0;
        }
    }

    function leaderboardRowHtml(item, showDetail) {
        const isYou = item.user_id === USER_ID;
        const firstName = escapeHtml((item.full_name || '').split(' ')[0] || 'User');
        const name = isYou ? `You (${firstName})` : escapeHtml(item.full_name);
        const rankClass = 'rank-' + Math.min(item.rank, 5);
        let nameCell = `<span class="wm-top-name">${name}</span>`;
        if (showDetail) {
            const missionsHint =
                item.total_missions > 0
                    ? `${item.completed_missions || 0}/${item.total_missions} missions`
                    : 'No missions this week';
            nameCell = `<span class="wm-top-name" style="flex:1;min-width:0;">${name}<br><small style="color:#94a3b8;font-weight:500;">${escapeHtml(missionsHint)}</small></span>`;
        }
        return `
            <li class="wm-top-item ${isYou ? 'is-you' : ''}"${showDetail ? ' style="margin-bottom:8px;"' : ''}>
                <span class="wm-top-rank">${item.rank}</span>
                <span class="wm-top-avatar ${rankClass}">${escapeHtml(initials(item.full_name))}</span>
                ${nameCell}
                <span class="wm-top-pct">${Math.round(item.completion_rate)}%</span>
            </li>
        `;
    }

    function showLeaderboardModal() {
        if (!leaderboard.length) {
            Swal.fire({ icon: 'info', title: 'Top Performers', text: 'No rankings yet this week.' });
            return;
        }
        const html =
            '<ul class="wm-top-list" style="text-align:left;max-height:60vh;overflow-y:auto;padding:0;margin:0;">' +
            leaderboard.map(item => leaderboardRowHtml(item, true)).join('') +
            '</ul>';
        Swal.fire({
            title: 'Top Performers',
            html,
            width: 'min(440px, 92vw)',
            confirmButtonText: 'Close',
            confirmButtonColor: '#7c3aed',
        });
    }

    function renderTeamChart() {
        if (typeof Chart === 'undefined') return;
        const ctx = document.getElementById('wmChartTeam');
        if (!ctx) return;

        if (teamChart) teamChart.destroy();

        const labels = teamProgress.labels?.length ? teamProgress.labels : ['W1', 'W2', 'W3', 'W4', 'W5'];
        const yourData = teamProgress.your_progress || [0, 0, 0, 0, 0];
        const teamData = teamProgress.team_average || [0, 0, 0, 0, 0];

        const endpointLabelPlugin = {
            id: 'wmEndpointLabels',
            afterDatasetsDraw(chart) {
                const { ctx } = chart;
                chart.data.datasets.forEach((dataset, i) => {
                    const meta = chart.getDatasetMeta(i);
                    const last = meta.data[meta.data.length - 1];
                    if (!last) return;
                    const value = Math.round(dataset.data[dataset.data.length - 1]) + '%';
                    
                    ctx.save();
                    ctx.font = '600 10px "Inter", sans-serif';
                    
                    // Measure text for badge size
                    const textWidth = ctx.measureText(value).width;
                    const paddingX = 6;
                    const paddingY = 3;
                    const badgeWidth = textWidth + paddingX * 2;
                    const badgeHeight = 14 + paddingY * 2;
                    
                    const x = last.x;
                    const y = last.y - 12 - paddingY;
                    
                    // Draw filled capsule background
                    ctx.fillStyle = dataset.borderColor;
                    ctx.beginPath();
                    const r = 6; // border radius
                    ctx.moveTo(x - badgeWidth/2 + r, y - badgeHeight/2);
                    ctx.lineTo(x + badgeWidth/2 - r, y - badgeHeight/2);
                    ctx.quadraticCurveTo(x + badgeWidth/2, y - badgeHeight/2, x + badgeWidth/2, y - badgeHeight/2 + r);
                    ctx.lineTo(x + badgeWidth/2, y + badgeHeight/2 - r);
                    ctx.quadraticCurveTo(x + badgeWidth/2, y + badgeHeight/2, x + badgeWidth/2 - r, y + badgeHeight/2);
                    ctx.lineTo(x - badgeWidth/2 + r, y + badgeHeight/2);
                    ctx.quadraticCurveTo(x - badgeWidth/2, y + badgeHeight/2, x - badgeWidth/2, y + badgeHeight/2 - r);
                    ctx.lineTo(x - badgeWidth/2, y - badgeHeight/2 + r);
                    ctx.quadraticCurveTo(x - badgeWidth/2, y - badgeHeight/2, x - badgeWidth/2 + r, y - badgeHeight/2);
                    ctx.closePath();
                    ctx.fill();
                    
                    // Draw centered white text
                    ctx.fillStyle = '#ffffff';
                    ctx.textAlign = 'center';
                    ctx.textBaseline = 'middle';
                    ctx.fillText(value, x, y);
                    
                    ctx.restore();
                });
            },
        };

        teamChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels,
                datasets: [
                    {
                        label: 'Your Progress',
                        data: yourData,
                        borderColor: '#4338ca',
                        backgroundColor: 'rgba(67, 56, 202, 0.06)',
                        fill: true,
                        tension: 0.35,
                        pointRadius: 3,
                        pointBackgroundColor: '#4338ca',
                        borderWidth: 2,
                    },
                    {
                        label: 'Team Average',
                        data: teamData,
                        borderColor: '#93c5fd',
                        backgroundColor: 'transparent',
                        borderDash: [5, 5],
                        tension: 0.35,
                        pointRadius: 3,
                        pointBackgroundColor: '#93c5fd',
                        borderWidth: 2,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        align: 'start',
                        labels: {
                            boxWidth: 12,
                            boxHeight: 2,
                            font: { size: 11, family: 'Inter, sans-serif' },
                            padding: 16,
                            usePointStyle: false,
                        },
                    },
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 10 }, color: '#94a3b8' },
                    },
                    y: {
                        beginAtZero: true,
                        max: 100,
                        grid: { color: '#f1f5f9' },
                        ticks: {
                            callback: v => v + '%',
                            font: { size: 10 },
                            color: '#94a3b8',
                            stepSize: 25,
                        },
                    },
                },
            },
            plugins: [endpointLabelPlugin],
        });
    }

    function renderAll() {
        renderKpis();
        if (IS_ADMIN) {
            populateAdminFilters();
            if (adminViewMode === 'detail' && selectedUserId) {
                renderAdminUserDetail();
            } else {
                renderAdminUserGrid();
            }
        } else {
            renderTable();
            renderFollowUp();
            renderAwards();
        }
        renderLeaderboard();
        renderTeamChart();
    }

    async function addMission() {
        const title = document.getElementById('wmTitle')?.value.trim();
        if (!title) {
            Swal.fire({ icon: 'warning', title: 'Enter a mission title' });
            return;
        }
        if (missions.length >= MAX) {
            Swal.fire({ icon: 'info', title: 'Limit reached', text: `Maximum ${MAX} missions per week.` });
            return;
        }
        const res = await api('create', {
            method: 'POST',
            body: { action: 'create', title },
        });
        if (!res.success) {
            Swal.fire({ icon: 'error', text: res.error });
            return;
        }
        document.getElementById('wmTitle').value = '';
        await loadDashboard();
    }

    async function toggleComplete(id, forceComplete) {
        const m = missions.find(x => x.id === id);
        const markComplete = forceComplete || !(m && m.status === 'Completed');
        const res = await api('toggle_complete', {
            method: 'POST',
            body: { action: 'toggle_complete', id, completed: markComplete },
        });
        if (!res.success) {
            Swal.fire({ icon: 'error', text: res.error });
            return;
        }
        if (res.completed && typeof confetti === 'function') {
            confetti({ particleCount: 40, spread: 50, origin: { y: 0.75 } });
        }
        await loadDashboard();
    }

    async function deleteMission(id) {
        const m = missions.find(x => x.id === id);
        const r = await Swal.fire({
            title: 'Remove mission?',
            text: m?.title || '',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            confirmButtonText: 'Remove',
        });
        if (!r.isConfirmed) return;
        const res = await api('delete', { method: 'POST', body: { action: 'delete', id } });
        if (!res.success) Swal.fire({ icon: 'error', text: res.error });
        else await loadDashboard();
    }

    async function editMission(id) {
        const m = missions.find(x => x.id === id);
        if (!m) return;
        const { value: title } = await Swal.fire({
            title: 'Edit mission',
            input: 'text',
            inputValue: m.title,
            showCancelButton: true,
            inputValidator: v => (!v?.trim() ? 'Title is required' : undefined),
        });
        if (!title?.trim()) return;
        const res = await api('update', {
            method: 'POST',
            body: { action: 'update', id, title: title.trim() },
        });
        if (!res.success) Swal.fire({ icon: 'error', text: res.error });
        else await loadDashboard();
    }

    async function adminReview(id) {
        const m = allMissions.find(x => x.id === id) || missions.find(x => x.id === id) || {};
        const { value } = await Swal.fire({
            title: 'Admin review',
            input: 'textarea',
            inputLabel: 'Comment for employee',
            inputValue: m.admin_comment || '',
            showCancelButton: true,
        });
        if (value === undefined) return;
        await api('admin_comment', {
            method: 'POST',
            body: { action: 'admin_comment', id, admin_comment: value },
        });
        await loadDashboard();
    }

    async function sendReply() {
        if (!followUp?.mission_id) {
            Swal.fire({ icon: 'info', text: 'No admin comment to reply to yet.' });
            return;
        }
        const reply = document.getElementById('wmReplyText')?.value.trim() || '';
        const res = await api('employee_reply', {
            method: 'POST',
            body: { action: 'employee_reply', mission_id: followUp.mission_id, reply },
        });
        if (!res.success) Swal.fire({ icon: 'error', text: res.error });
        else await loadDashboard();
    }

    function shiftWeek(delta) {
        const base = weekStart || new Date().toISOString().split('T')[0];
        const d = new Date(base + 'T12:00:00');
        d.setDate(d.getDate() + delta * 7);
        weekStart = d.toISOString().split('T')[0];
        if (IS_ADMIN) {
            selectedUserId = 0;
            adminViewMode = 'grid';
        }
        loadDashboard();
    }

    function goThisWeek() {
        weekStart = null;
        if (IS_ADMIN) {
            selectedUserId = 0;
            adminViewMode = 'grid';
        }
        loadDashboard();
    }

    function bindEvents() {
        document.getElementById('wmAddBtn')?.addEventListener('click', addMission);
        document.getElementById('wmTitle')?.addEventListener('keydown', e => {
            if (e.key === 'Enter') addMission();
        });
        document.getElementById('wmPrevWeek')?.addEventListener('click', () => shiftWeek(-1));
        document.getElementById('wmNextWeek')?.addEventListener('click', () => shiftWeek(1));
        document.getElementById('wmThisWeek')?.addEventListener('click', goThisWeek);
        document.getElementById('wmReplyBtn')?.addEventListener('click', sendReply);

        document.getElementById('wmViewAllLeaderboard')?.addEventListener('click', e => {
            e.preventDefault();
            showLeaderboardModal();
        });

        document.getElementById('wmViewAll')?.addEventListener('click', e => {
            e.preventDefault();
            const panel = document.querySelector('.wm-missions-panel');
            panel?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });

        document.getElementById('wmGlobalSearch')?.addEventListener('keydown', e => {
            if (e.key === 'Enter') {
                const q = e.target.value.trim().toLowerCase();
                if (!q) return;
                const match = missions.find(m =>
                    m.title.toLowerCase().includes(q) ||
                    (IS_ADMIN && String(m.full_name || '').toLowerCase().includes(q))
                );
                if (match) {
                    document.querySelector(`[data-action][data-id="${match.id}"]`)?.closest('tr')
                        ?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }
        });

        if (IS_ADMIN) {
            document.getElementById('wmFilterDept')?.addEventListener('change', e => {
                adminFilters.department = e.target.value;
                selectedUserId = 0;
                adminViewMode = 'grid';
                loadAdminOverview();
            });
            document.getElementById('wmAdminBack')?.addEventListener('click', backToAdminGrid);
            document.getElementById('wmAdminSaveRemarks')?.addEventListener('click', saveAdminRemarks);
        }
    }

    async function init() {
        bindEvents();
        await tryImportLocalStorage();
        await loadDashboard();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.WM = { loadDashboard, addMission, toggleComplete, deleteMission };
})();

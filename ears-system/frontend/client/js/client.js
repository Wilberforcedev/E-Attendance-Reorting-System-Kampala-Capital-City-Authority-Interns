/**
 * EARS Client Portal - Kampala Capital City Authority (KCCA)
 * Core interactive client logic for Interns & Staff
 */

// Server-backed portal state. No fabricated attendance or report history is shown.
let clientAttendanceLogs = [];
let clientReports = [];
let clientNotifications = [];
let activeClockSession = null;
let sessionTimerInterval = null;
let chartsInitialized = false;

// DOM Initialization
document.addEventListener('DOMContentLoaded', async () => {
    const user = await window.EARSAuth?.requireRole('intern');
    if (!user) return;
    await Promise.all([loadAttendanceData(), loadReportsData()]);
    initTheme();
    initClock();
    checkActiveSession();
    renderAttendanceTable();
    renderDashboardAttendance();
    renderReportsTable();
    renderNotifications();
    updateSummaryStats();
    updateDailyGoalProgress();
    setupEventListeners();
    setupReportDatePicker();
});

// Theme Management
function initTheme() {
    const savedTheme = localStorage.getItem('ears_theme') || 'light';
    applyTheme(savedTheme);

    const toggleBtn = document.getElementById('client-theme-toggle-btn');
    if (toggleBtn) {
        toggleBtn.addEventListener('click', toggleTheme);
    }
}

function toggleTheme() {
    const currentTheme = document.documentElement.getAttribute('data-bs-theme') || 'light';
    const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
    applyTheme(newTheme);
    localStorage.setItem('ears_theme', newTheme);
}

function applyTheme(theme) {
    document.documentElement.setAttribute('data-bs-theme', theme);
    const body = document.body;
    if (theme === 'dark') {
        body.classList.add('dark-mode');
    } else {
        body.classList.remove('dark-mode');
    }

    const icon = document.getElementById('theme-toggle-icon');
    const text = document.getElementById('theme-toggle-text');
    if (icon && text) {
        if (theme === 'dark') {
            icon.className = 'fas fa-sun text-warning';
            text.textContent = 'Light Mode';
        } else {
            icon.className = 'fas fa-moon';
            text.textContent = 'Dark Mode';
        }
    }
}

// Live Digital Clock (Kampala / EAT)
function initClock() {
    const clockEl = document.getElementById('live-digital-clock');
    const dateEl = document.getElementById('live-digital-date');
    const punchClockEl = document.getElementById('punch-terminal-clock');
    const punchDateEl = document.getElementById('punch-terminal-date');

    function updateTime() {
        const now = new Date();
        if (clockEl) {
            clockEl.textContent = now.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true, timeZone: 'Africa/Kampala' });
        }
        if (dateEl) {
            dateEl.textContent = now.toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'short', year: 'numeric', timeZone: 'Africa/Kampala' });
        }
        if (punchClockEl) {
            punchClockEl.textContent = now.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true, timeZone: 'Africa/Kampala' });
        }
        if (punchDateEl) {
            punchDateEl.textContent = now.toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'Africa/Kampala' });
        }
    }

    updateTime();
    setInterval(updateTime, 1000);
}

// Clock-In & Out Session Management
function checkActiveSession() {
    const punchBtn = document.getElementById('btn-punch-action');
    const statusBadge = document.getElementById('clock-status-badge');
    const timerContainer = document.getElementById('active-session-timer-container');
    const quickPunchText = document.getElementById('quick-punch-text');
    const quickPunchBtn = document.getElementById('btn-quick-punch-toggle');

    if (activeClockSession && activeClockSession.status === 'in_progress') {
        if (punchBtn) {
            punchBtn.textContent = 'Clock Out Now';
            punchBtn.className = 'btn btn-clock-out btn-lg w-100 py-3 shadow-sm d-flex align-items-center justify-content-center gap-2';
            punchBtn.innerHTML = '<i class="fas fa-sign-out-alt"></i> <span>Clock Out (End Shift)</span>';
        }
        if (quickPunchText) quickPunchText.textContent = 'Clock Out';
        if (quickPunchBtn) {
            quickPunchBtn.className = 'btn btn-sm btn-danger py-2 px-3 d-flex align-items-center gap-1';
        }
        if (statusBadge) {
            statusBadge.className = 'badge bg-success-subtle text-success border border-success px-3 py-2 fs-6';
            statusBadge.innerHTML = '<i class="fas fa-circle text-success me-1 fa-beat-fade"></i> Active Shift in Progress';
        }
        if (timerContainer) {
            timerContainer.classList.remove('d-none');
        }
        startSessionTimer(new Date(activeClockSession.clock_in_utc));
    } else {
        if (punchBtn) {
            punchBtn.className = 'btn btn-clock-in btn-lg w-100 py-3 shadow-sm d-flex align-items-center justify-content-center gap-2';
            punchBtn.innerHTML = '<i class="fas fa-fingerprint fs-4"></i> <span>Clock In (Start Shift)</span>';
        }
        if (quickPunchText) quickPunchText.textContent = 'Clock In';
        if (quickPunchBtn) {
            quickPunchBtn.className = 'btn btn-sm btn-success py-2 px-3 d-flex align-items-center gap-1';
        }
        if (statusBadge) {
            statusBadge.className = 'badge bg-secondary-subtle text-secondary border px-3 py-2 fs-6';
            statusBadge.innerHTML = '<i class="fas fa-circle text-muted me-1"></i> Not Clocked In';
        }
        if (timerContainer) {
            timerContainer.classList.add('d-none');
        }
        if (sessionTimerInterval) clearInterval(sessionTimerInterval);
    }
    updateDailyGoalProgress();
}

async function loadAttendanceData() {
    try {
        const response = await window.EARSAuth.request('/backend/api/attendance.php');
        const payload = await response.json();
        if (!response.ok || !payload.success) throw new Error(payload.message || 'Could not load attendance.');
        clientAttendanceLogs = (payload.sessions || []).map(session => {
            const start = new Date(session.clock_in_utc);
            const end = session.clock_out_utc ? new Date(session.clock_out_utc) : null;
            const day = new Intl.DateTimeFormat('en-GB', { weekday: 'long', timeZone: 'Africa/Kampala' }).format(start);
            return {
                id: session.id,
                date: session.work_date,
                day,
                timeIn: formatKampalaTime(start),
                timeOut: end ? formatKampalaTime(end) : 'In progress',
                hours: `${(session.duration_seconds / 3600).toFixed(1)} hrs`,
                status: session.status === 'completed' ? 'Completed' : 'In progress',
                location: 'Not collected'
            };
        });
        activeClockSession = payload.active_session || null;
    } catch (error) {
        showToast('Attendance unavailable', error.message, 'danger');
    }
}

async function loadReportsData() {
    try {
        const response = await window.EARSAuth.request('/backend/api/reports.php');
        const payload = await response.json();
        if (!response.ok || payload.status !== 'success') throw new Error(payload.message || 'Could not load reports.');
        clientReports = payload.reports || [];
    } catch (error) {
        showToast('Reports unavailable', error.message, 'danger');
    }
}

function formatKampalaTime(date) {
    return new Intl.DateTimeFormat('en-GB', {
        hour: '2-digit', minute: '2-digit', hour12: true, timeZone: 'Africa/Kampala'
    }).format(date);
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, character => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[character]);
}

async function handlePunchAction() {
    const buttons = [document.getElementById('btn-punch-action'), document.getElementById('btn-quick-punch-toggle')].filter(Boolean);
    buttons.forEach(button => button.disabled = true);
    try {
        const action = activeClockSession ? 'clock_out' : 'clock_in';
        const response = await window.EARSAuth.request('/backend/api/attendance.php', {
            method: 'POST',
            body: JSON.stringify({ action })
        });
        const payload = await response.json();
        if (!response.ok || !payload.success) throw new Error(payload.message || 'Attendance could not be recorded.');
        await loadAttendanceData();
        checkActiveSession();
        renderAttendanceTable();
        renderDashboardAttendance();
        updateSummaryStats();
        updateDailyGoalProgress();
            showToast(action === 'clock_in' ? 'Clocked in' : 'Clocked out', 'The attendance record was saved using the server time.', 'success');
    } catch (error) {
        showToast('Attendance not recorded', error.message, 'danger');
    } finally {
        buttons.forEach(button => button.disabled = false);
    }
}

function startSessionTimer(startTime) {
    if (sessionTimerInterval) clearInterval(sessionTimerInterval);
    const timerDisplay = document.getElementById('session-stopwatch');

    function update() {
        if (!timerDisplay) return;
        const now = new Date();
        const diff = Math.max(0, Math.floor((now - startTime) / 1000));
        const hrs = String(Math.floor(diff / 3600)).padStart(2, '0');
        const mins = String(Math.floor((diff % 3600) / 60)).padStart(2, '0');
        const secs = String(diff % 60).padStart(2, '0');
        timerDisplay.textContent = `${hrs}:${mins}:${secs}`;
        updateDailyGoalProgress();
    }

    update();
    sessionTimerInterval = setInterval(update, 1000);
}

// Display observed attendance duration without assuming an approved target.
function getTodayHoursData() {
    const todayStr = new Intl.DateTimeFormat('en-CA', { timeZone: 'Africa/Kampala' }).format(new Date());
    let completedHours = 0;
    if (Array.isArray(clientAttendanceLogs)) {
        clientAttendanceLogs.forEach(log => {
            if (log.date === todayStr && log.status === 'Completed') {
                const parsed = parseFloat(log.hours);
                if (!isNaN(parsed)) completedHours += parsed;
            }
        });
    }

    // Add active ongoing clock session hours
    let activeSessionHours = 0;
    if (activeClockSession && activeClockSession.clock_in_utc) {
        const start = new Date(activeClockSession.clock_in_utc).getTime();
        const diffMs = Math.max(0, Date.now() - start);
        activeSessionHours = diffMs / (1000 * 60 * 60);
    }

    const totalHours = completedHours + activeSessionHours;
    return {
        completedHours,
        activeSessionHours,
        totalHours,
    };
}

function updateDailyGoalProgress() {
    const currentHours = getTodayHoursData().totalHours;
    const circle = document.getElementById('daily-goal-radial-circle');
    const percent = document.getElementById('daily-goal-percent-display');
    const hours = document.getElementById('daily-goal-hours-display');
    const status = document.getElementById('daily-goal-status-badge');
    const remaining = document.getElementById('daily-goal-remaining-display');
    const logged = document.getElementById('daily-goal-logged-subtext');
    const badge = document.getElementById('daily-goal-top-badge');
    if (circle) circle.style.strokeDashoffset = '414.69';
    if (percent) percent.textContent = '—';
    if (hours) hours.textContent = `${currentHours.toFixed(1)} hrs`;
    if (logged) logged.textContent = `${currentHours.toFixed(1)} hrs`;
    if (status) {
        status.className = 'radial-status-badge bg-secondary-subtle text-secondary border';
        status.innerHTML = activeClockSession ? '<i class="fas fa-play"></i> In progress' : '<i class="fas fa-clock"></i> Recorded';
    }
    if (remaining) remaining.textContent = 'No daily target configured';
    if (badge) badge.textContent = 'Recorded time only';
}
window.updateDailyGoalProgress = updateDailyGoalProgress;

// Attendance Table Renderer
function renderAttendanceTable() {
    const tbody = document.getElementById('attendance-log-tbody');
    if (!tbody) return;

    if (clientAttendanceLogs.length === 0) {
        tbody.innerHTML = `<tr><td colspan="7" class="text-center py-4 text-muted">No attendance punch logs recorded yet.</td></tr>`;
        return;
    }

    tbody.innerHTML = clientAttendanceLogs.slice(0, 10).map((log, index) => {
        return `
            <tr>
                <td class="fw-semibold text-muted small">${index + 1}</td>
                <td>
                    <div class="fw-bold">${escapeHtml(log.date)}</div>
                    <small class="text-muted">${escapeHtml(log.day)}</small>
                </td>
                <td><span class="badge bg-success-subtle text-success border border-success-subtle"><i class="fas fa-sign-in-alt me-1"></i>${escapeHtml(log.timeIn)}</span></td>
                <td><span class="badge bg-danger-subtle text-danger border border-danger-subtle"><i class="fas fa-sign-out-alt me-1"></i>${escapeHtml(log.timeOut)}</span></td>
                <td class="fw-bold text-success">${escapeHtml(log.hours)}</td>
                <td><small>${escapeHtml(log.location)}</small></td>
                <td><span class="badge ${log.status === 'Completed' ? 'bg-secondary' : 'bg-primary'}">${escapeHtml(log.status)}</span></td>
            </tr>
        `;
    }).join('');
}

function renderDashboardAttendance() {
    const tbody = document.getElementById('dashboard-attendance-tbody');
    if (!tbody) return;
    if (!clientAttendanceLogs.length) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">No attendance records yet.</td></tr>';
        return;
    }
    tbody.innerHTML = clientAttendanceLogs.slice(0, 3).map(log => `
        <tr>
            <td class="fw-bold">${escapeHtml(log.date)}</td>
            <td><span class="badge bg-success-subtle text-success">${escapeHtml(log.timeIn)}</span></td>
            <td><span class="badge bg-danger-subtle text-danger">${escapeHtml(log.timeOut)}</span></td>
            <td class="fw-bold">${escapeHtml(log.hours)}</td>
            <td><span class="badge ${log.status === 'Completed' ? 'bg-secondary' : 'bg-primary'}">${escapeHtml(log.status)}</span></td>
        </tr>
    `).join('');
}

// Export Attendance Punch Logs to CSV
function downloadAttendanceCSV() {
    if (!clientAttendanceLogs || clientAttendanceLogs.length === 0) {
        showToast('Export Notice', 'No attendance punch records available to export.', 'warning');
        return;
    }

    // CSV Headers
    const headers = ['Record ID', 'Date', 'Day of Week', 'Time In', 'Time Out', 'Duration (Hours)', 'Location (not verified)', 'Status'];

    // Map existing attendance punch logs
    const rows = clientAttendanceLogs.map(log => {
        return [
            log.id || '',
            log.date || '',
            log.day || '',
            log.timeIn || '',
            log.timeOut || '',
            log.hours || '',
            log.location || '',
            log.status || ''
        ].map(val => `"${String(val).replace(/"/g, '""')}"`).join(',');
    });

    // Build CSV content with UTF-8 BOM for Excel/Sheets compatibility
    const csvContent = [headers.map(h => `"${h}"`).join(','), ...rows].join('\r\n');
    const blob = new Blob(['\uFEFF' + csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const downloadLink = document.createElement('a');
    const now = new Date();
    const dateStr = new Intl.DateTimeFormat('en-CA', { timeZone: 'Africa/Kampala' }).format(now);
    downloadLink.href = url;
    downloadLink.setAttribute('download', `EARS_Attendance_Punch_Logs_${dateStr}.csv`);
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
    URL.revokeObjectURL(url);

    showToast('Download Ready', `Successfully exported ${rows.length} attendance punch log records to CSV.`, 'success');
}
window.downloadAttendanceCSV = downloadAttendanceCSV;

// Weekly Reports Table Renderer
function renderReportsTable() {
    const tbody = document.getElementById('client-reports-tbody');
    if (!tbody) return;

    if (clientReports.length === 0) {
        tbody.innerHTML = `<tr><td colspan="7" class="text-center py-4 text-muted">No weekly reports created yet.</td></tr>`;
        return;
    }

    tbody.innerHTML = clientReports.map(rep => {
        let badgeClass = 'status-badge-approved';
        let icon = 'fa-check-circle';
        if (rep.status === 'Pending') {
            badgeClass = 'status-badge-pending';
            icon = 'fa-clock';
        } else if (rep.status === 'Revision') {
            badgeClass = 'status-badge-revision';
            icon = 'fa-exclamation-triangle';
        }

        return `
            <tr>
                <td class="fw-bold text-primary">Week #${rep.weekNumber}</td>
                <td>${escapeHtml(rep.weekRange)}</td>
                <td>${escapeHtml(rep.submittedDate)}</td>
                <td class="fw-bold text-success">${Number(rep.hoursLogged || 0).toFixed(1)} hrs</td>
                <td><span class="badge ${badgeClass}"><i class="fas ${icon} me-1"></i>${escapeHtml(rep.status)}</span></td>
                <td><small class="text-muted">${escapeHtml(rep.supervisor)}</small></td>
                <td>
                    <button class="btn btn-sm btn-outline-primary py-1 px-2" onclick="viewReportDetails('${rep.id}')" title="View Report">
                        <i class="fas fa-eye me-1"></i>View
                    </button>
                    <button class="btn btn-sm btn-outline-secondary py-1 px-2 ms-1" onclick="printReport('${rep.id}')" title="Print/Export">
                        <i class="fas fa-print"></i>
                    </button>
                </td>
            </tr>
        `;
    }).join('');
}

// View Detailed Report Modal
function viewReportDetails(reportId) {
    const report = clientReports.find(r => String(r.id) === String(reportId));
    if (!report) return;

    const modalTitle = document.getElementById('reportDetailModalTitle');
    const modalBody = document.getElementById('reportDetailModalBody');
    if (!modalTitle || !modalBody) return;

    modalTitle.textContent = `Weekly Attendance Report - Week #${report.weekNumber}`;

    let tasksHtml = (report.tasks || []).map(t => `
        <div class="mb-3 p-3 rounded border" style="background-color: var(--theme-card-bg);">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="fw-bold text-success">${escapeHtml(t.day)}</span>
                <span class="badge bg-secondary-subtle text-secondary">${Number(t.hours || 0).toFixed(1)} hrs</span>
            </div>
            <p class="mb-0 small text-muted">${escapeHtml(t.task)}</p>
        </div>
    `).join('');

    modalBody.innerHTML = `
        <div class="mb-3 d-flex justify-content-between align-items-center pb-2 border-bottom">
            <div>
                <h6 class="mb-0 fw-bold">Period: ${escapeHtml(report.weekRange)}</h6>
                <small class="text-muted">Submitted on ${escapeHtml(report.submittedDate)} &bull; Total Hours: <strong>${Number(report.hoursLogged || 0).toFixed(1)} hrs</strong></small>
            </div>
            <span class="badge status-badge-approved fs-6 px-3 py-2">${escapeHtml(report.status)}</span>
        </div>

        <h6 class="fw-bold text-uppercase small text-muted mb-2">Daily Tasks & Activities</h6>
        ${tasksHtml}

        <div class="mt-4 p-3 rounded border bg-light-subtle">
            <h6 class="fw-bold text-success mb-1"><i class="fas fa-comment-dots me-1"></i>Supervisor Feedback</h6>
            <p class="mb-0 small text-muted"><strong>${escapeHtml(report.supervisor)}:</strong> "${escapeHtml(report.feedback || 'Pending review')}"</p>
        </div>
    `;

    const modal = new bootstrap.Modal(document.getElementById('reportDetailModal'));
    modal.show();
}

function printReport(reportId) {
    const report = clientReports.find(r => String(r.id) === String(reportId));
    if (!report) return;
    window.print();
}

// Submit Weekly Report Handler
async function submitWeeklyReport(event) {
    event.preventDefault();
    const form = document.getElementById('weekly-report-form');
    const weekStart = document.getElementById('report-week-start').value;
    const dayIds = ['mon', 'tue', 'wed', 'thu', 'fri'];
    const dayNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
    const days = {};
    dayNames.forEach((day, index) => {
        days[day] = { activities: document.getElementById(`task-${dayIds[index]}`).value.trim() };
    });
    const button = form.querySelector('[type="submit"]');
    button.disabled = true;
    try {
        const response = await window.EARSAuth.request('/backend/api/reports.php', {
            method: 'POST',
            body: JSON.stringify({ week_start_date: weekStart, days })
        });
        const payload = await response.json();
        if (!response.ok || payload.status !== 'success') throw new Error(payload.message || 'Report could not be submitted.');
        await loadReportsData();
        form.reset();
        setupReportDatePicker();
        renderReportsTable();
        updateSummaryStats();
            showToast('Report submitted', 'Your weekly report was saved.', 'success');
        showClientSection('reports-section');
    } catch (error) {
        showToast('Report not submitted', error.message, 'danger');
    } finally {
        button.disabled = false;
    }
}

function updateFormTotalHours() {
    const badge = document.getElementById('report-form-total-badge');
    if (badge) badge.textContent = 'Attendance hours are calculated from saved clock-in and clock-out times.';
}

function setupReportDatePicker() {
    const input = document.getElementById('report-week-start');
    if (!input) return;
    const today = new Date();
    const weekday = new Intl.DateTimeFormat('en-US', { weekday: 'short', timeZone: 'Africa/Kampala' }).format(today);
    const index = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].indexOf(weekday);
    const kampalaToday = new Date(new Intl.DateTimeFormat('en-CA', { timeZone: 'Africa/Kampala' }).format(today) + 'T12:00:00');
    kampalaToday.setDate(kampalaToday.getDate() - ((index + 6) % 7));
    if (!input.value) input.value = kampalaToday.toISOString().slice(0, 10);
    const updateRange = () => {
        const date = new Date(`${input.value}T12:00:00`);
        const end = new Date(date);
        end.setDate(end.getDate() + 4);
        const range = document.getElementById('report-range-input');
        if (range && input.value) range.value = `${date.toLocaleDateString('en-GB')} - ${end.toLocaleDateString('en-GB')}`;
    };
    input.onchange = updateRange;
    updateRange();
}

// Notifications Renderer
function renderNotifications() {
    const list = document.getElementById('client-notif-list');
    const badge = document.getElementById('client-notif-badge');
    if (!list) return;

    const unreadCount = clientNotifications.filter(n => n.unread).length;
    if (badge) {
        badge.textContent = unreadCount;
        badge.style.display = unreadCount > 0 ? 'inline-block' : 'none';
    }

    if (clientNotifications.length === 0) {
        list.innerHTML = `<li class="p-3 text-center text-muted small">No notifications at this time</li>`;
        return;
    }

    list.innerHTML = clientNotifications.slice(0, 6).map(n => `
        <li class="p-3 border-bottom d-flex align-items-start gap-2 ${n.unread ? 'bg-light-subtle' : ''}">
            <i class="fas fa-bell text-${n.type || 'primary'} mt-1"></i>
            <div class="flex-grow-1">
                <div class="d-flex justify-content-between">
                    <strong class="small">${n.title}</strong>
                    <small class="text-muted" style="font-size:0.7rem;">${n.time}</small>
                </div>
                <p class="mb-0 text-muted" style="font-size:0.75rem;">${n.message}</p>
            </div>
        </li>
    `).join('');
}

function markAllNotificationsRead() {
    clientNotifications.forEach(n => n.unread = false);
    renderNotifications();
}

// Summary Statistics Update
function updateSummaryStats() {
    const totalReportsEl = document.getElementById('stat-total-reports');
    const approvedReportsEl = document.getElementById('stat-approved-reports');
    const totalHoursEl = document.getElementById('stat-total-hours');
    const punctualityEl = document.getElementById('stat-punctuality-rate');

    if (totalReportsEl) totalReportsEl.textContent = clientReports.length;
    const approved = clientReports.filter(r => r.status === 'Approved').length;
    if (approvedReportsEl) approvedReportsEl.textContent = approved;

    let totalHrs = 0;
    clientReports.forEach(r => totalHrs += (r.hoursLogged || 0));
    if (totalHoursEl) totalHoursEl.textContent = `${totalHrs.toFixed(1)} hrs`;

    if (punctualityEl) punctualityEl.textContent = 'Not available';
}

// Navigation between views
function showClientSection(sectionId) {
    const sections = ['dashboard-section', 'punch-section', 'submit-report-section', 'reports-section', 'analytics-section', 'profile-section'];
    sections.forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.classList.toggle('d-none', id !== sectionId);
        }
    });

    // Update active nav link
    document.querySelectorAll('.client-sidebar .nav-link').forEach(link => {
        link.classList.remove('active');
        if (link.getAttribute('onclick') && link.getAttribute('onclick').includes(sectionId)) {
            link.classList.add('active');
        }
    });

}

// Toast helper
function showToast(title, message, type = 'info') {
    const container = document.getElementById('toast-container');
    if (!container) return;

    const toastId = 'toast-' + Date.now();
    const bgClass = type === 'success' ? 'bg-success text-white' : type === 'danger' ? 'bg-danger text-white' : 'bg-primary text-white';

    const toastHtml = `
        <div id="${toastId}" class="toast align-items-center ${bgClass} border-0 shadow" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body">
                    <strong>${title}:</strong> ${message}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        </div>
    `;

    container.insertAdjacentHTML('beforeend', toastHtml);
    const toastEl = document.getElementById(toastId);
    if (toastEl && window.bootstrap) {
        const bsToast = new bootstrap.Toast(toastEl, { delay: 4000 });
        bsToast.show();
        toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
    }
}

// Metrics such as punctuality and target compliance require approved rules and are not fabricated.

// Event Listeners
function setupEventListeners() {
    const punchBtn = document.getElementById('btn-punch-action');
    if (punchBtn) {
        punchBtn.addEventListener('click', handlePunchAction);
    }

    const reportForm = document.getElementById('weekly-report-form');
    if (reportForm) {
        reportForm.addEventListener('submit', submitWeeklyReport);
    }

    // Bind hour input changes to total calculation
    const markReadBtn = document.getElementById('btn-mark-all-read');
    if (markReadBtn) {
        markReadBtn.addEventListener('click', markAllNotificationsRead);
    }

    const exportCsvBtn = document.getElementById('btn-export-punch-csv');
    if (exportCsvBtn) {
        exportCsvBtn.addEventListener('click', downloadAttendanceCSV);
    }
}

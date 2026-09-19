/**
 * EARS Client Portal - Kampala Capital City Authority (KCCA)
 * Core interactive client logic for Interns & Staff
 */

// Initial Default State
const DEFAULT_ATTENDANCE_LOGS = [
    { id: 'att-1', date: '2026-09-19', day: 'Friday', timeIn: '08:02 AM', timeOut: '05:04 PM', hours: '8.0 hrs', status: 'Completed', location: 'City Hall - ICT Lab 2' },
    { id: 'att-2', date: '2026-09-18', day: 'Thursday', timeIn: '08:14 AM', timeOut: '05:00 PM', hours: '7.8 hrs', status: 'Completed', location: 'City Hall - Software Wing' },
    { id: 'att-3', date: '2026-09-17', day: 'Wednesday', timeIn: '07:55 AM', timeOut: '05:15 PM', hours: '8.3 hrs', status: 'Completed', location: 'Nakawa Division Field Office' },
    { id: 'att-4', date: '2026-09-16', day: 'Tuesday', timeIn: '08:00 AM', timeOut: '05:05 PM', hours: '8.1 hrs', status: 'Completed', location: 'City Hall - Main Boardroom' },
    { id: 'att-5', date: '2026-09-15', day: 'Monday', timeIn: '08:05 AM', timeOut: '05:00 PM', hours: '8.0 hrs', status: 'Completed', location: 'City Hall - ICT Lab 2' }
];

const DEFAULT_REPORTS = [
    {
        id: 'rep-4',
        weekNumber: 4,
        weekRange: '15 Sep 2026 - 19 Sep 2026',
        submittedDate: '19 Sep 2026',
        hoursLogged: 40.2,
        status: 'Approved',
        supervisor: 'Sarah Smith (Senior IT Systems Analyst)',
        feedback: 'Excellent work on the biometric integration module and UI responsiveness testing. Keep up the high standards!',
        tasks: [
            { day: 'Monday', hours: 8, task: 'Configured local database schemas and verified migration script integrity for the new attendance records.' },
            { day: 'Tuesday', hours: 8.5, task: 'Developed responsive client-side UI layouts and tested cross-browser compatibility on mobile viewports.' },
            { day: 'Wednesday', hours: 8, task: 'Integrated KCCA division directory endpoints and conducted validation on staff login tokens.' },
            { day: 'Thursday', hours: 7.8, task: 'Tested supervisor report approval workflows and fixed real-time form validation errors.' },
            { day: 'Friday', hours: 8, task: 'Compiled weekly deliverables, prepared attendance audit logs, and documented API endpoints.' }
        ]
    },
    {
        id: 'rep-3',
        weekNumber: 3,
        weekRange: '08 Sep 2026 - 12 Sep 2026',
        submittedDate: '12 Sep 2026',
        hoursLogged: 39.5,
        status: 'Approved',
        supervisor: 'Sarah Smith (Senior IT Systems Analyst)',
        feedback: 'Weekly progress met all requirements. Well-documented challenges and solutions.',
        tasks: [
            { day: 'Monday', hours: 8, task: 'Attended KCCA ICT orientation and setup development environments on Linux workstations.' },
            { day: 'Tuesday', hours: 8, task: 'Reviewed EARS architecture and analyzed supervisor pending approvals module.' },
            { day: 'Wednesday', hours: 8, task: 'Assisted in resolving user role permission mismatches for HR officers.' },
            { day: 'Thursday', hours: 7.5, task: 'Shadowed senior engineers during server maintenance and network switch upgrades.' },
            { day: 'Friday', hours: 8, task: 'Conducted unit tests on attendance punch endpoints and submitted week 3 summary.' }
        ]
    },
    {
        id: 'rep-2',
        weekNumber: 2,
        weekRange: '01 Sep 2026 - 05 Sep 2026',
        submittedDate: '05 Sep 2026',
        hoursLogged: 40.0,
        status: 'Approved',
        supervisor: 'Michael Brown (Infrastructure Lead)',
        feedback: 'Satisfactory completion of network configuration and hardware logging tasks.',
        tasks: [
            { day: 'Monday', hours: 8, task: 'Hardware inventory inspection at City Hall 2nd Floor offices.' },
            { day: 'Tuesday', hours: 8, task: 'Diagnosed biometric terminal connectivity glitches in Nakawa Division.' },
            { day: 'Wednesday', hours: 8, task: 'Configured static IP assignments for attendance logging terminals.' },
            { day: 'Thursday', hours: 8, task: 'Participated in cybersecurity awareness training conducted for interns.' },
            { day: 'Friday', hours: 8, task: 'Drafted report on equipment status and presented to supervisor.' }
        ]
    }
];

const DEFAULT_NOTIFICATIONS = [
    { id: 'notif-1', title: 'Weekly Report #4 Approved', message: 'Supervisor Sarah Smith approved your Week 4 submission with commendation.', time: '2 hours ago', unread: true, type: 'success' },
    { id: 'notif-2', title: 'System Notice: Punctuality Commendation', message: 'You have maintained 100% on-time clock-in this week.', time: '5 hours ago', unread: true, type: 'info' },
    { id: 'notif-3', title: 'Reminder: Friday Report Submission', message: 'Please ensure your weekly summary is submitted before 5:00 PM.', time: '1 day ago', unread: false, type: 'warning' },
    { id: 'notif-4', title: 'KCCA ICT Directorate Meeting', message: 'All intern staff are invited to the bi-weekly tech talk on Tuesday.', time: '3 days ago', unread: false, type: 'primary' }
];

// App State Management
let clientAttendanceLogs = JSON.parse(localStorage.getItem('ears_client_attendance')) || DEFAULT_ATTENDANCE_LOGS;
let clientReports = JSON.parse(localStorage.getItem('ears_client_reports')) || DEFAULT_REPORTS;
let clientNotifications = JSON.parse(localStorage.getItem('ears_client_notifications')) || DEFAULT_NOTIFICATIONS;

// Active Session Timer state
let activeClockSession = JSON.parse(localStorage.getItem('ears_active_clock_session')) || null;
let sessionTimerInterval = null;
let chartsInitialized = false;

// DOM Initialization
document.addEventListener('DOMContentLoaded', () => {
    initTheme();
    initClock();
    checkActiveSession();
    renderAttendanceTable();
    renderReportsTable();
    renderNotifications();
    updateSummaryStats();
    setupEventListeners();
    setTimeout(renderClientCharts, 250);
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
    renderClientCharts();
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

    function updateTime() {
        const now = new Date();
        if (clockEl) {
            clockEl.textContent = now.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true });
        }
        if (dateEl) {
            dateEl.textContent = now.toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'short', year: 'numeric' });
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

    if (activeClockSession && activeClockSession.inProgress) {
        if (punchBtn) {
            punchBtn.textContent = 'Clock Out Now';
            punchBtn.className = 'btn btn-clock-out btn-lg w-100 py-3 shadow-sm d-flex align-items-center justify-content-center gap-2';
            punchBtn.innerHTML = '<i class="fas fa-sign-out-alt"></i> <span>Clock Out (End Shift)</span>';
        }
        if (statusBadge) {
            statusBadge.className = 'badge bg-success-subtle text-success border border-success px-3 py-2 fs-6';
            statusBadge.innerHTML = '<i class="fas fa-circle text-success me-1 fa-beat-fade"></i> Active Shift in Progress';
        }
        if (timerContainer) {
            timerContainer.classList.remove('d-none');
        }
        startSessionTimer(new Date(activeClockSession.startTime));
    } else {
        if (punchBtn) {
            punchBtn.className = 'btn btn-clock-in btn-lg w-100 py-3 shadow-sm d-flex align-items-center justify-content-center gap-2';
            punchBtn.innerHTML = '<i class="fas fa-fingerprint fs-4"></i> <span>Clock In (Start Shift)</span>';
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
}

function handlePunchAction() {
    const locationInput = document.getElementById('punch-location-select');
    const notesInput = document.getElementById('punch-notes-input');
    const location = locationInput ? locationInput.value : 'City Hall - ICT Lab 2';
    const notes = notesInput ? notesInput.value.trim() : '';

    if (!activeClockSession || !activeClockSession.inProgress) {
        // Clock In
        const startTime = new Date();
        activeClockSession = {
            inProgress: true,
            startTime: startTime.toISOString(),
            location: location,
            notes: notes
        };
        localStorage.setItem('ears_active_clock_session', JSON.stringify(activeClockSession));
        showToast('Shift Started', `Successfully clocked in at ${startTime.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: true })}. Have a productive day!`, 'success');
        checkActiveSession();
    } else {
        // Clock Out
        const startTime = new Date(activeClockSession.startTime);
        const endTime = new Date();
        const diffMs = endTime - startTime;
        const hours = (diffMs / (1000 * 60 * 60)).toFixed(1);

        const newLog = {
            id: 'att-' + Date.now(),
            date: endTime.toISOString().split('T')[0],
            day: endTime.toLocaleDateString('en-GB', { weekday: 'long' }),
            timeIn: startTime.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: true }),
            timeOut: endTime.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: true }),
            hours: `${Math.max(0.1, hours)} hrs`,
            status: 'Completed',
            location: activeClockSession.location
        };

        clientAttendanceLogs.unshift(newLog);
        localStorage.setItem('ears_client_attendance', JSON.stringify(clientAttendanceLogs));

        activeClockSession = null;
        localStorage.removeItem('ears_active_clock_session');

        showToast('Shift Concluded', `Successfully clocked out at ${endTime.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: true })}. Total session: ${newLog.hours}.`, 'info');
        checkActiveSession();
        renderAttendanceTable();
        updateSummaryStats();
        renderClientCharts();
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
    }

    update();
    sessionTimerInterval = setInterval(update, 1000);
}

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
                    <div class="fw-bold">${log.date}</div>
                    <small class="text-muted">${log.day}</small>
                </td>
                <td><span class="badge bg-success-subtle text-success border border-success-subtle"><i class="fas fa-sign-in-alt me-1"></i>${log.timeIn}</span></td>
                <td><span class="badge bg-danger-subtle text-danger border border-danger-subtle"><i class="fas fa-sign-out-alt me-1"></i>${log.timeOut}</span></td>
                <td class="fw-bold text-success">${log.hours}</td>
                <td><small><i class="fas fa-map-marker-alt text-danger me-1"></i>${log.location}</small></td>
                <td><span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>${log.status}</span></td>
            </tr>
        `;
    }).join('');
}

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
                <td>${rep.weekRange}</td>
                <td>${rep.submittedDate}</td>
                <td class="fw-bold text-success">${rep.hoursLogged} hrs</td>
                <td><span class="badge ${badgeClass}"><i class="fas ${icon} me-1"></i>${rep.status}</span></td>
                <td><small class="text-muted">${rep.supervisor}</small></td>
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
    const report = clientReports.find(r => r.id === reportId);
    if (!report) return;

    const modalTitle = document.getElementById('reportDetailModalTitle');
    const modalBody = document.getElementById('reportDetailModalBody');
    if (!modalTitle || !modalBody) return;

    modalTitle.textContent = `Weekly Attendance Report - Week #${report.weekNumber}`;

    let tasksHtml = (report.tasks || []).map(t => `
        <div class="mb-3 p-3 rounded border" style="background-color: var(--theme-card-bg);">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="fw-bold text-success">${t.day}</span>
                <span class="badge bg-secondary-subtle text-secondary">${t.hours} hrs</span>
            </div>
            <p class="mb-0 small text-muted">${t.task}</p>
        </div>
    `).join('');

    modalBody.innerHTML = `
        <div class="mb-3 d-flex justify-content-between align-items-center pb-2 border-bottom">
            <div>
                <h6 class="mb-0 fw-bold">Period: ${report.weekRange}</h6>
                <small class="text-muted">Submitted on ${report.submittedDate} &bull; Total Hours: <strong>${report.hoursLogged} hrs</strong></small>
            </div>
            <span class="badge status-badge-approved fs-6 px-3 py-2">${report.status}</span>
        </div>

        <h6 class="fw-bold text-uppercase small text-muted mb-2">Daily Tasks & Activities</h6>
        ${tasksHtml}

        <div class="mt-4 p-3 rounded border bg-light-subtle">
            <h6 class="fw-bold text-success mb-1"><i class="fas fa-comment-dots me-1"></i>Supervisor Feedback</h6>
            <p class="mb-0 small text-muted"><strong>${report.supervisor}:</strong> "${report.feedback || 'Pending review'}"</p>
        </div>
    `;

    const modal = new bootstrap.Modal(document.getElementById('reportDetailModal'));
    modal.show();
}

function printReport(reportId) {
    const report = clientReports.find(r => r.id === reportId);
    if (!report) return;
    window.print();
}

// Submit Weekly Report Handler
function submitWeeklyReport(event) {
    event.preventDefault();

    const weekNum = document.getElementById('report-week-select').value;
    const weekRange = document.getElementById('report-range-input').value;
    const mTask = document.getElementById('task-mon').value.trim();
    const tTask = document.getElementById('task-tue').value.trim();
    const wTask = document.getElementById('task-wed').value.trim();
    const thTask = document.getElementById('task-thu').value.trim();
    const fTask = document.getElementById('task-fri').value.trim();

    const mHours = parseFloat(document.getElementById('hours-mon').value) || 0;
    const tHours = parseFloat(document.getElementById('hours-tue').value) || 0;
    const wHours = parseFloat(document.getElementById('hours-wed').value) || 0;
    const thHours = parseFloat(document.getElementById('hours-thu').value) || 0;
    const fHours = parseFloat(document.getElementById('hours-fri').value) || 0;

    const totalHours = mHours + tHours + wHours + thHours + fHours;

    if (!mTask || !tTask || !wTask || !thTask || !fTask) {
        alert('Please provide task descriptions for all working days (Monday - Friday).');
        return;
    }

    const newReport = {
        id: 'rep-' + Date.now(),
        weekNumber: parseInt(weekNum, 10),
        weekRange: weekRange || 'Current Week',
        submittedDate: new Date().toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }),
        hoursLogged: parseFloat(totalHours.toFixed(1)),
        status: 'Pending',
        supervisor: 'Sarah Smith (Senior IT Systems Analyst)',
        feedback: 'Awaiting supervisor review.',
        tasks: [
            { day: 'Monday', hours: mHours, task: mTask },
            { day: 'Tuesday', hours: tHours, task: tTask },
            { day: 'Wednesday', hours: wHours, task: wTask },
            { day: 'Thursday', hours: thHours, task: thTask },
            { day: 'Friday', hours: fHours, task: fTask }
        ]
    };

    clientReports.unshift(newReport);
    localStorage.setItem('ears_client_reports', JSON.stringify(clientReports));

    // Also push a notification
    const notif = {
        id: 'notif-' + Date.now(),
        title: `Report Week #${weekNum} Submitted`,
        message: 'Your weekly report has been forwarded to Supervisor Sarah Smith for review.',
        time: 'Just now',
        unread: true,
        type: 'primary'
    };
    clientNotifications.unshift(notif);
    localStorage.setItem('ears_client_notifications', JSON.stringify(clientNotifications));

    // Reset form
    document.getElementById('weekly-report-form').reset();
    updateFormTotalHours();
    renderReportsTable();
    renderNotifications();
    updateSummaryStats();
    renderClientCharts();

    showToast('Report Submitted Successfully', `Week #${weekNum} report sent to your supervisor for review.`, 'success');

    // Switch tab to Reports
    showClientSection('reports-section');
}

function updateFormTotalHours() {
    const mHours = parseFloat(document.getElementById('hours-mon')?.value) || 0;
    const tHours = parseFloat(document.getElementById('hours-tue')?.value) || 0;
    const wHours = parseFloat(document.getElementById('hours-wed')?.value) || 0;
    const thHours = parseFloat(document.getElementById('hours-thu')?.value) || 0;
    const fHours = parseFloat(document.getElementById('hours-fri')?.value) || 0;

    const total = (mHours + tHours + wHours + thHours + fHours).toFixed(1);
    const badge = document.getElementById('report-form-total-badge');
    if (badge) {
        badge.textContent = `${total} hrs / 40.0 hrs`;
        if (total >= 40) {
            badge.className = 'badge bg-success fs-6';
        } else if (total >= 35) {
            badge.className = 'badge bg-primary fs-6';
        } else {
            badge.className = 'badge bg-warning text-dark fs-6';
        }
    }
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
    localStorage.setItem('ears_client_notifications', JSON.stringify(clientNotifications));
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

    if (punctualityEl) punctualityEl.textContent = '98.5%';
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

    if (sectionId === 'analytics-section' || sectionId === 'dashboard-section') {
        setTimeout(renderClientCharts, 100);
    }
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

// Recharts Visualization
let clientHoursChartRoot = null;
let clientPunctualityChartRoot = null;

function renderClientCharts() {
    if (!window.React || !window.ReactDOM || !window.Recharts) {
        setTimeout(renderClientCharts, 200);
        return;
    }

    const e = window.React.createElement;
    const { ResponsiveContainer, BarChart, Bar, LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, Legend } = window.Recharts;
    const isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
    const gridColor = isDark ? '#334155' : '#e2e8f0';
    const textColor = isDark ? '#94a3b8' : '#64748b';

    // 1. Weekly Hours Chart
    const hoursContainer = document.getElementById('client-weekly-hours-chart');
    if (hoursContainer) {
        const weeklyData = [
            { day: 'Mon', logged: 8.0, target: 8.0 },
            { day: 'Tue', logged: 8.2, target: 8.0 },
            { day: 'Wed', logged: 8.5, target: 8.0 },
            { day: 'Thu', logged: 7.8, target: 8.0 },
            { day: 'Fri', logged: 8.0, target: 8.0 }
        ];

        const chart = e(ResponsiveContainer, { width: '100%', height: 260 },
            e(BarChart, { data: weeklyData, margin: { top: 10, right: 20, left: -10, bottom: 0 } },
                e(CartesianGrid, { key: 'g', strokeDasharray: '3 3', stroke: gridColor }),
                e(XAxis, { key: 'x', dataKey: 'day', stroke: textColor, tick: { fill: textColor, fontSize: 12 } }),
                e(YAxis, { key: 'y', stroke: textColor, tick: { fill: textColor, fontSize: 12 }, domain: [0, 10] }),
                e(Tooltip, { key: 't' }),
                e(Legend, { key: 'l' }),
                e(Bar, { key: 'b-logged', dataKey: 'logged', name: 'Logged Hours', fill: '#008540', radius: [4, 4, 0, 0] }),
                e(Bar, { key: 'b-target', dataKey: 'target', name: 'Standard Target (8h)', fill: '#FFD100', radius: [4, 4, 0, 0] })
            )
        );

        if (!clientHoursChartRoot) clientHoursChartRoot = window.ReactDOM.createRoot(hoursContainer);
        clientHoursChartRoot.render(chart);
    }

    // 2. Attendance Trend Chart
    const trendContainer = document.getElementById('client-attendance-trend-chart');
    if (trendContainer) {
        const trendData = [
            { week: 'Week 1', rate: 96, hours: 38 },
            { week: 'Week 2', rate: 98, hours: 40 },
            { week: 'Week 3', rate: 100, hours: 39.5 },
            { week: 'Week 4', rate: 99, hours: 40.2 }
        ];

        const trendChart = e(ResponsiveContainer, { width: '100%', height: 260 },
            e(LineChart, { data: trendData, margin: { top: 10, right: 20, left: -10, bottom: 0 } },
                e(CartesianGrid, { key: 'cg', strokeDasharray: '3 3', stroke: gridColor }),
                e(XAxis, { key: 'cx', dataKey: 'week', stroke: textColor, tick: { fill: textColor, fontSize: 12 } }),
                e(YAxis, { key: 'cy', stroke: textColor, tick: { fill: textColor, fontSize: 12 } }),
                e(Tooltip, { key: 'ct' }),
                e(Legend, { key: 'cl' }),
                e(Line, { key: 'l-rate', type: 'monotone', dataKey: 'rate', name: 'Punctuality (%)', stroke: '#008540', strokeWidth: 3, dot: { r: 4, fill: '#008540' } }),
                e(Line, { key: 'l-hrs', type: 'monotone', dataKey: 'hours', name: 'Total Hours', stroke: '#E30613', strokeWidth: 2, strokeDasharray: '4 4', dot: { r: 3.5, fill: '#E30613' } })
            )
        );

        if (!clientPunctualityChartRoot) clientPunctualityChartRoot = window.ReactDOM.createRoot(trendContainer);
        clientPunctualityChartRoot.render(trendChart);
    }
}

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
    ['hours-mon', 'hours-tue', 'hours-wed', 'hours-thu', 'hours-fri'].forEach(id => {
        const input = document.getElementById(id);
        if (input) {
            input.addEventListener('input', updateFormTotalHours);
        }
    });

    const markReadBtn = document.getElementById('btn-mark-all-read');
    if (markReadBtn) {
        markReadBtn.addEventListener('click', markAllNotificationsRead);
    }
}

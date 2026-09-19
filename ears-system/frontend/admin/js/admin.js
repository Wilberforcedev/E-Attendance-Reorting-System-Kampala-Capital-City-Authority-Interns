// EARS Admin Panel JavaScript

// Global variables
let currentSection = 'dashboard';
let users = [];
let divisions = [];
let departments = [];
let auditLogs = [];
let systemSettings = {};

// Theme Management (Light / Dark Mode with LocalStorage persistence)
const THEME_STORAGE_KEY = 'ears_theme';

// Bulk User Selection State
let selectedUserIds = new Set();
let currentVisibleUsers = [];

function getStoredTheme() {
    try {
        return localStorage.getItem(THEME_STORAGE_KEY) || localStorage.getItem('theme') || 'light';
    } catch (e) {
        return 'light';
    }
}

function getEffectiveTheme(preference) {
    if (preference === 'system') {
        return (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
    }
    return preference === 'dark' ? 'dark' : 'light';
}

function applyTheme(themeChoice, updateStorage) {
    if (updateStorage) {
        try {
            localStorage.setItem(THEME_STORAGE_KEY, themeChoice);
            localStorage.setItem('theme', themeChoice);
        } catch (e) {
            console.warn('Could not save theme to localStorage:', e);
        }
    }

    const effective = getEffectiveTheme(themeChoice);

    // Update HTML root attributes and classes
    document.documentElement.setAttribute('data-bs-theme', effective);
    document.documentElement.setAttribute('data-theme', effective);
    document.documentElement.classList.remove('light-mode', 'dark-mode');
    document.documentElement.classList.add(effective === 'dark' ? 'dark-mode' : 'light-mode');

    // Update navbar toggle button
    const toggleBtn = document.getElementById('theme-toggle-btn');
    const toggleIcon = document.getElementById('theme-toggle-icon');
    const toggleText = document.getElementById('theme-toggle-text');

    if (toggleIcon && toggleText) {
        if (effective === 'dark') {
            toggleIcon.className = 'fas fa-sun text-warning';
            toggleText.textContent = 'Light Mode';
            if (toggleBtn) {
                toggleBtn.setAttribute('title', 'Switch to Light Mode');
                toggleBtn.setAttribute('aria-label', 'Switch to Light Mode');
            }
        } else {
            toggleIcon.className = 'fas fa-moon text-warning';
            toggleText.textContent = 'Dark Mode';
            if (toggleBtn) {
                toggleBtn.setAttribute('title', 'Switch to Dark Mode');
                toggleBtn.setAttribute('aria-label', 'Switch to Dark Mode');
            }
        }
    }

    // Update dropdown menu theme item
    const menuBadge = document.getElementById('menu-theme-badge');
    const menuIcon = document.getElementById('menu-theme-icon');
    if (menuBadge) {
        menuBadge.textContent = effective === 'dark' ? 'Dark' : 'Light';
        menuBadge.className = effective === 'dark' ? 'badge bg-warning text-dark' : 'badge bg-secondary';
    }
    if (menuIcon) {
        menuIcon.className = effective === 'dark' ? 'fas fa-sun text-warning me-2' : 'fas fa-moon me-2';
    }

    // Update System Settings radio options if rendered
    const radioLight = document.getElementById('theme-radio-light');
    const radioDark = document.getElementById('theme-radio-dark');
    const radioSystem = document.getElementById('theme-radio-system');
    const settingsBadge = document.getElementById('settings-active-theme-badge');

    const stored = getStoredTheme();
    if (radioLight && radioDark && radioSystem) {
        radioLight.checked = (stored === 'light');
        radioDark.checked = (stored === 'dark');
        radioSystem.checked = (stored === 'system');
    }
    if (settingsBadge) {
        settingsBadge.textContent = `Active: ${effective.charAt(0).toUpperCase() + effective.slice(1)}`;
        settingsBadge.className = effective === 'dark' ? 'badge bg-warning text-dark' : 'badge bg-primary';
    }

    // Re-render Recharts registration trend chart with updated theme colors if on dashboard
    if (typeof renderRegistrationTrendChart === 'function') {
        setTimeout(renderRegistrationTrendChart, 50);
    }
}

function setTheme(themeChoice) {
    applyTheme(themeChoice, true);
    showAlert(`Switched theme to ${themeChoice.charAt(0).toUpperCase() + themeChoice.slice(1)} mode`, 'info');
}

function toggleTheme() {
    const currentEffective = document.documentElement.getAttribute('data-bs-theme') || 'light';
    const newTheme = currentEffective === 'dark' ? 'light' : 'dark';
    setTheme(newTheme);
}

function initializeTheme() {
    const saved = getStoredTheme();
    applyTheme(saved, false);

    // React dynamically to OS theme preference changes when 'system' is chosen
    if (window.matchMedia) {
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function() {
            if (getStoredTheme() === 'system') {
                applyTheme('system', false);
            }
        });
    }

    // React to storage changes across browser tabs
    window.addEventListener('storage', function(e) {
        if (e.key === THEME_STORAGE_KEY || e.key === 'theme') {
            applyTheme(e.newValue || 'light', false);
        }
    });
}

// Make functions globally available for inline onclick handlers
window.toggleTheme = toggleTheme;
window.setTheme = setTheme;
window.getStoredTheme = getStoredTheme;
window.onUserCheckboxChange = onUserCheckboxChange;
window.toggleSelectAllUsers = toggleSelectAllUsers;
window.clearUserSelection = clearUserSelection;
window.bulkDeactivateUsers = bulkDeactivateUsers;
window.bulkActivateUsers = bulkActivateUsers;
window.bulkDeleteUsers = bulkDeleteUsers;
window.bulkResetPasswords = bulkResetPasswords;
window.filterRegistrationTrend = filterRegistrationTrend;
window.refreshRegistrationTrendData = refreshRegistrationTrendData;
window.renderRegistrationTrendChart = renderRegistrationTrendChart;

// Initialize the admin panel
document.addEventListener('DOMContentLoaded', function() {
    initializeTheme();
    initializeAdminPanel();
    loadDashboardData();
    setupEventListeners();
});

// Initialize admin panel
function initializeAdminPanel() {
    // Load initial data
    loadUsers();
    loadOrganizationalStructure();
    loadAuditLogs();
    loadSystemSettings();
    
    // Show dashboard by default
    showDashboard();
}

// Setup event listeners
function setupEventListeners() {
    // Theme toggle button
    document.getElementById('theme-toggle-btn')?.addEventListener('click', function() {
        toggleTheme();
    });

    // Search functionality
    document.getElementById('search-user')?.addEventListener('input', function() {
        filterUsers();
    });
    
    // Form validations
    setupFormValidations();
    
    // Auto-save settings
    setupAutoSave();
}

// Navigation functions
function showDashboard() {
    hideAllSections();
    document.getElementById('dashboard-section').style.display = 'block';
    currentSection = 'dashboard';
    updateNavigation();
    loadDashboardData();
}

function showUserManagement() {
    hideAllSections();
    document.getElementById('user-management-section').style.display = 'block';
    currentSection = 'user-management';
    updateNavigation();
    loadUsers();
}

function showOrganizationalStructure() {
    hideAllSections();
    document.getElementById('organizational-structure-section').style.display = 'block';
    currentSection = 'organizational-structure';
    updateNavigation();
    loadOrganizationalStructure();
}

function showSupervisorAssignment() {
    hideAllSections();
    document.getElementById('supervisor-assignment-section').style.display = 'block';
    currentSection = 'supervisor-assignment';
    updateNavigation();
    loadSupervisorAssignments();
}

function showAuditLogs() {
    hideAllSections();
    document.getElementById('audit-logs-section').style.display = 'block';
    currentSection = 'audit-logs';
    updateNavigation();
    loadAuditLogs();
}

function showSystemSettings() {
    hideAllSections();
    document.getElementById('system-settings-section').style.display = 'block';
    currentSection = 'system-settings';
    updateNavigation();
    loadSystemSettings();
}

function hideAllSections() {
    const sections = document.querySelectorAll('.content-section');
    sections.forEach(section => {
        section.style.display = 'none';
    });
}

function updateNavigation() {
    // Remove active class from all nav links
    document.querySelectorAll('.sidebar .nav-link').forEach(link => {
        link.classList.remove('active');
    });
    
    // Add active class to current section
    const currentNavLink = document.querySelector(`[onclick="show${currentSection.charAt(0).toUpperCase() + currentSection.slice(1)}()"]`);
    if (currentNavLink) {
        currentNavLink.classList.add('active');
    }
}

// Dashboard functions
function loadDashboardData() {
    // Simulate API call to get dashboard statistics
    const stats = {
        totalUsers: 45,
        activeInterns: 32,
        totalSupervisors: 8,
        pendingReports: 12
    };
    
    updateDashboardStats(stats);
    loadRecentActivity();
    renderRegistrationTrendChart();
}

// ============================================================================
// User Registration Trends (Recharts Line Chart)
// ============================================================================
let registrationTrendData = null;
let currentRegistrationFilter = 'all'; // 'all' | 'intern' | 'supervisor'
let chartReactRoot = null;

function generate7DaysRegistrationData() {
    const data = [];
    const today = new Date();
    // Realistic daily registration profile for the last 7 days
    const dailyBases = [
        { interns: 5, supervisors: 2, admins: 1 }, // 6 days ago
        { interns: 8, supervisors: 3, admins: 0 }, // 5 days ago
        { interns: 12, supervisors: 2, admins: 1 }, // 4 days ago
        { interns: 9, supervisors: 1, admins: 0 },  // 3 days ago
        { interns: 15, supervisors: 4, admins: 1 }, // 2 days ago
        { interns: 11, supervisors: 2, admins: 0 }, // Yesterday
        { interns: 16, supervisors: 3, admins: 1 }  // Today
    ];

    for (let i = 6; i >= 0; i--) {
        const d = new Date(today);
        d.setDate(today.getDate() - i);
        const dayIdx = 6 - i;
        const dayBase = dailyBases[dayIdx];
        const dayName = d.toLocaleDateString('en-US', { weekday: 'short' });
        const monthDay = d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
        const label = i === 0 ? 'Today' : (i === 1 ? 'Yesterday' : `${dayName}, ${monthDay}`);
        
        const total = dayBase.interns + dayBase.supervisors + dayBase.admins;
        data.push({
            date: label,
            shortDate: monthDay,
            dayName: dayName,
            interns: dayBase.interns,
            supervisors: dayBase.supervisors,
            admins: dayBase.admins,
            total: total
        });
    }
    return data;
}

function updateRegistrationMetrics(data) {
    if (!data || data.length === 0) return;
    
    let total = 0;
    let totalInterns = 0;
    let peakCount = 0;
    let peakDate = '';

    data.forEach(item => {
        total += item.total;
        totalInterns += item.interns;
        if (item.total > peakCount) {
            peakCount = item.total;
            peakDate = item.shortDate;
        }
    });

    const avg = (total / data.length).toFixed(1);
    const internRatio = Math.round((totalInterns / (total || 1)) * 100) + '%';

    const totalEl = document.getElementById('trend-7day-total');
    const avgEl = document.getElementById('trend-daily-avg');
    const peakCountEl = document.getElementById('trend-peak-count');
    const peakDateEl = document.getElementById('trend-peak-date');
    const shareEl = document.getElementById('trend-intern-share');

    if (totalEl) totalEl.textContent = total;
    if (avgEl) avgEl.textContent = avg;
    if (peakCountEl) peakCountEl.textContent = peakCount;
    if (peakDateEl) peakDateEl.textContent = `(${peakDate})`;
    if (shareEl) shareEl.textContent = internRatio;
}

function filterRegistrationTrend(role) {
    currentRegistrationFilter = role;
    
    // Update active button state
    ['all', 'intern', 'supervisor'].forEach(r => {
        const btn = document.getElementById(`btn-filter-${r}`);
        if (btn) {
            if (r === role) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        }
    });

    renderRegistrationTrendChart();
}

function refreshRegistrationTrendData() {
    // Generate slight variations to show dynamic updates
    registrationTrendData = generate7DaysRegistrationData().map(item => {
        const jitter = Math.floor(Math.random() * 3) - 1;
        const newInterns = Math.max(3, item.interns + jitter);
        const newSupervisors = Math.max(1, item.supervisors + (Math.random() > 0.6 ? 1 : 0));
        return {
            ...item,
            interns: newInterns,
            supervisors: newSupervisors,
            total: newInterns + newSupervisors + item.admins
        };
    });

    renderRegistrationTrendChart();
    showAlert('User registration trend data refreshed', 'success');
}

function renderRegistrationTrendChart() {
    const container = document.getElementById('recharts-registration-trend-root');
    if (!container) return;

    if (!window.React || !window.ReactDOM || !window.Recharts) {
        setTimeout(renderRegistrationTrendChart, 100);
        return;
    }

    if (!registrationTrendData) {
        registrationTrendData = generate7DaysRegistrationData();
    }

    updateRegistrationMetrics(registrationTrendData);

    const isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark' ||
                   document.documentElement.classList.contains('dark-mode');

    const React = window.React;
    const ReactDOM = window.ReactDOM;
    const {
        ResponsiveContainer,
        LineChart,
        Line,
        XAxis,
        YAxis,
        CartesianGrid,
        Tooltip,
        Legend
    } = window.Recharts;
    const e = React.createElement;

    // Harmonized palette
    const gridColor = isDark ? '#27313f' : '#e2e8f0';
    const textColor = isDark ? '#94a3b8' : '#64748b';
    const totalLineColor = '#008540';   // KCCA Brand Green
    const internLineColor = '#E30613';  // KCCA Brand Red
    const superLineColor = '#f59e0b';   // Amber / Supervisor

    // Custom Tooltip component in React
    function CustomChartTooltip(props) {
        const { active, payload, label } = props;
        if (!active || !payload || !payload.length) return null;

        return e('div', { className: 'recharts-custom-tooltip' },
            e('div', { className: 'tooltip-header' },
                e('span', null, label),
                e('span', { className: 'badge bg-primary-subtle text-primary' }, 'Trend')
            ),
            payload.map((entry, idx) => 
                e('div', { key: idx, className: 'tooltip-metric' },
                    e('span', { className: 'd-flex align-items-center' },
                        e('span', {
                            className: 'tooltip-legend-dot',
                            style: { backgroundColor: entry.color || entry.stroke }
                        }),
                        entry.name
                    ),
                    e('span', { className: 'fw-bold' }, entry.value)
                )
            )
        );
    }

    // Determine series to show
    const showTotal = currentRegistrationFilter === 'all';
    const showInterns = currentRegistrationFilter === 'all' || currentRegistrationFilter === 'intern';
    const showSupervisors = currentRegistrationFilter === 'all' || currentRegistrationFilter === 'supervisor';

    const chartChildren = [
        e(CartesianGrid, {
            key: 'grid',
            strokeDasharray: '3 3',
            stroke: gridColor,
            vertical: false
        }),
        e(XAxis, {
            key: 'x-axis',
            dataKey: 'date',
            stroke: textColor,
            tickLine: false,
            axisLine: { stroke: gridColor },
            tick: { fill: textColor, fontSize: 12 },
            padding: { left: 15, right: 15 }
        }),
        e(YAxis, {
            key: 'y-axis',
            stroke: textColor,
            tickLine: false,
            axisLine: false,
            tick: { fill: textColor, fontSize: 12 },
            allowDecimals: false,
            domain: [0, 'dataMax + 4']
        }),
        e(Tooltip, {
            key: 'tooltip',
            content: e(CustomChartTooltip)
        }),
        e(Legend, {
            key: 'legend',
            verticalAlign: 'top',
            height: 36,
            wrapperStyle: { paddingBottom: '10px', fontSize: '0.85rem' }
        })
    ];

    if (showTotal) {
        chartChildren.push(
            e(Line, {
                key: 'line-total',
                type: 'monotone',
                dataKey: 'total',
                name: 'Total Registrations',
                stroke: totalLineColor,
                strokeWidth: 3,
                dot: { r: 4, fill: totalLineColor, strokeWidth: 1.5, stroke: '#ffffff' },
                activeDot: { r: 6, stroke: totalLineColor, strokeWidth: 2, fill: '#ffffff' }
            })
        );
    }

    if (showInterns) {
        chartChildren.push(
            e(Line, {
                key: 'line-interns',
                type: 'monotone',
                dataKey: 'interns',
                name: 'Interns',
                stroke: internLineColor,
                strokeWidth: 2.5,
                strokeDasharray: showTotal ? '4 4' : undefined,
                dot: { r: 4, fill: internLineColor, strokeWidth: 1.5, stroke: '#ffffff' },
                activeDot: { r: 6, stroke: internLineColor, strokeWidth: 2, fill: '#ffffff' }
            })
        );
    }

    if (showSupervisors) {
        chartChildren.push(
            e(Line, {
                key: 'line-supervisors',
                type: 'monotone',
                dataKey: 'supervisors',
                name: 'Supervisors',
                stroke: superLineColor,
                strokeWidth: 2,
                dot: { r: 3.5, fill: superLineColor, strokeWidth: 1, stroke: '#ffffff' },
                activeDot: { r: 5.5, stroke: superLineColor, strokeWidth: 2, fill: '#ffffff' }
            })
        );
    }

    const chart = e(ResponsiveContainer, { width: '100%', height: 320 },
        e(LineChart, {
            data: registrationTrendData,
            margin: { top: 10, right: 25, left: -10, bottom: 0 }
        }, ...chartChildren)
    );

    try {
        if (!chartReactRoot) {
            chartReactRoot = ReactDOM.createRoot(container);
        }
        chartReactRoot.render(chart);
    } catch (err) {
        console.error('Error rendering Recharts registration trend chart:', err);
    }
}

function updateDashboardStats(stats) {
    document.getElementById('total-users').textContent = stats.totalUsers;
    document.getElementById('active-interns').textContent = stats.activeInterns;
    document.getElementById('total-supervisors').textContent = stats.totalSupervisors;
    document.getElementById('pending-reports').textContent = stats.pendingReports;
}

function loadRecentActivity() {
    const activities = [
        {
            time: '2 minutes ago',
            user: 'Tendo',
            action: 'created new user',
            details: 'Added intern: Taliq'
        },
        {
            time: '5 minutes ago',
            user: 'Admin',
            action: 'updated system settings',
            details: 'Changed session timeout to 45 minutes'
        },
        {
            time: '10 minutes ago',
            user: 'Wilber',
            action: 'assigned supervisor',
            details: 'Assigned Aron to 3 interns'
        },
        {
            time: '15 minutes ago',
            user: 'System',
            action: 'backup completed',
            details: 'Daily backup completed successfully'
        }
    ];
    
    const activityContainer = document.getElementById('recent-activity');
    activityContainer.innerHTML = '';
    
    activities.forEach(activity => {
        const activityItem = document.createElement('div');
        activityItem.className = 'activity-item';
        activityItem.innerHTML = `
            <div class="d-flex justify-content-between">
                <div>
                    <span class="activity-user">${activity.user}</span>
                    <span class="activity-action">${activity.action}</span>
                    <small class="text-muted d-block">${activity.details}</small>
                </div>
                <small class="activity-time">${activity.time}</small>
            </div>
        `;
        activityContainer.appendChild(activityItem);
    });
}

// User Management functions
function loadUsers() {
    // Simulate API call to get users
    users = [
        {
            id: 1,
            firstname: 'Aron',
            lastname: 'L',
            email: 'aron.L@kcca.go.ug',
            phone: '+256 701 234 567',
            role: 'admin',
            department: 'IT',
            status: 'active',
            lastLogin: '2024-01-15 10:30:00'
        },
        {
            id: 2,
            firstname: 'Arafat',
            lastname: 'K',
            email: 'arafat.k@kcca.go.ug',
            phone: '+256 702 345 678',
            role: 'intern',
            department: 'Legal',
            status: 'active',
            lastLogin: '2024-01-15 09:15:00'
        },
        {
            id: 3,
            firstname: 'Benjamin',
            lastname: 'N',
            email: 'benjamin.n@kcca.go.ug',
            phone: '+256 703 456 789',
            role: 'supervisor',
            department: 'Finance',
            status: 'active',
            lastLogin: '2024-01-15 08:45:00'
        },
        {
            id: 3,
            firstname: 'Calvin',
            lastname: 'M',
            email: 'calvin.m@kcca.go.ug',
            phone: '+256 703 456 789',
            role: 'supervisor',
            department: 'Revenue',
            status: 'active',
            lastLogin: '2024-01-15 08:45:00'
        },
        {
            id: 3,
            firstname: 'Norman',
            lastname: 'D',
            email: 'norman.d@kcca.go.ug',
            phone: '+256 703 456 789',
            role: 'supervisor',
            department: 'IT',
            status: 'active',
            lastLogin: '2024-01-15 08:45:00'
        }
    ];
    
    renderUsersTable();
}

function createUserRowElement(user) {
    const isChecked = selectedUserIds.has(user.id);
    const row = document.createElement('tr');
    row.id = `user-row-${user.id}`;
    if (isChecked) {
        row.classList.add('user-row-selected');
    }
    
    row.innerHTML = `
        <td class="text-center">
            <input type="checkbox" class="form-check-input user-select-checkbox" 
                   id="user-check-${user.id}" 
                   value="${user.id}" 
                   ${isChecked ? 'checked' : ''} 
                   onchange="onUserCheckboxChange(${user.id}, this.checked)" 
                   aria-label="Select user ${user.firstname} ${user.lastname}">
        </td>
        <td class="fw-semibold">${user.firstname} ${user.lastname}</td>
        <td>${user.email}</td>
        <td><span class="badge bg-${getRoleBadgeColor(user.role)}">${user.role}</span></td>
        <td>${user.department || '-'}</td>
        <td><span class="badge bg-${getStatusBadgeColor(user.status)}">${user.status}</span></td>
        <td>${formatDateTime(user.lastLogin)}</td>
        <td>
            <button class="btn btn-sm btn-outline-primary me-1" onclick="editUser(${user.id})" title="Edit user">
                <i class="fas fa-edit"></i>
            </button>
            <button class="btn btn-sm btn-outline-warning me-1" onclick="resetPassword(${user.id})" title="Reset password">
                <i class="fas fa-key"></i>
            </button>
            <button class="btn btn-sm btn-outline-danger" onclick="deleteUser(${user.id})" title="Delete user">
                <i class="fas fa-trash"></i>
            </button>
        </td>
    `;
    return row;
}

function renderUsersTable() {
    currentVisibleUsers = [...users];
    
    // Prune selections of users that no longer exist
    const validUserIds = new Set(users.map(u => u.id));
    selectedUserIds.forEach(id => {
        if (!validUserIds.has(id)) selectedUserIds.delete(id);
    });

    const tbody = document.getElementById('users-table-body');
    tbody.innerHTML = '';
    
    if (users.length === 0) {
        tbody.innerHTML = `<tr><td colspan="8" class="text-center py-4 text-muted">No users found.</td></tr>`;
    } else {
        users.forEach(user => {
            tbody.appendChild(createUserRowElement(user));
        });
    }

    updateBulkActionsUI();
}

function getRoleBadgeColor(role) {
    const colors = {
        'admin': 'danger',
        'hr': 'info',
        'supervisor': 'success',
        'intern': 'primary'
    };
    return colors[role] || 'secondary';
}

function getStatusBadgeColor(status) {
    const colors = {
        'active': 'success',
        'inactive': 'secondary',
        'suspended': 'danger'
    };
    return colors[status] || 'secondary';
}

function filterUsers() {
    const roleFilter = document.getElementById('role-filter').value;
    const departmentFilter = document.getElementById('department-filter').value;
    const searchTerm = document.getElementById('search-user').value.toLowerCase();
    
    const filteredUsers = users.filter(user => {
        const matchesRole = !roleFilter || user.role === roleFilter;
        const matchesDepartment = !departmentFilter || user.department === departmentFilter;
        const matchesSearch = !searchTerm || 
            user.firstname.toLowerCase().includes(searchTerm) ||
            user.lastname.toLowerCase().includes(searchTerm) ||
            user.email.toLowerCase().includes(searchTerm);
        
        return matchesRole && matchesDepartment && matchesSearch;
    });
    
    renderFilteredUsers(filteredUsers);
}

function renderFilteredUsers(filteredUsers) {
    currentVisibleUsers = [...filteredUsers];
    const tbody = document.getElementById('users-table-body');
    tbody.innerHTML = '';
    
    if (filteredUsers.length === 0) {
        tbody.innerHTML = `<tr><td colspan="8" class="text-center py-4 text-muted">No users match the search/filter criteria.</td></tr>`;
    } else {
        filteredUsers.forEach(user => {
            tbody.appendChild(createUserRowElement(user));
        });
    }

    updateBulkActionsUI();
}

// Bulk Selection and Actions
function onUserCheckboxChange(userId, isChecked) {
    if (isChecked) {
        selectedUserIds.add(userId);
    } else {
        selectedUserIds.delete(userId);
    }
    
    const row = document.getElementById(`user-row-${userId}`);
    if (row) {
        if (isChecked) {
            row.classList.add('user-row-selected');
        } else {
            row.classList.remove('user-row-selected');
        }
    }
    
    updateBulkActionsUI();
}

function toggleSelectAllUsers(isChecked) {
    if (currentVisibleUsers.length === 0) return;
    
    currentVisibleUsers.forEach(user => {
        if (isChecked) {
            selectedUserIds.add(user.id);
        } else {
            selectedUserIds.delete(user.id);
        }
        
        const row = document.getElementById(`user-row-${user.id}`);
        const checkbox = document.getElementById(`user-check-${user.id}`);
        if (checkbox) checkbox.checked = isChecked;
        if (row) {
            if (isChecked) row.classList.add('user-row-selected');
            else row.classList.remove('user-row-selected');
        }
    });
    
    updateBulkActionsUI();
}

function clearUserSelection() {
    selectedUserIds.clear();
    
    document.querySelectorAll('.user-select-checkbox').forEach(cb => {
        cb.checked = false;
    });
    document.querySelectorAll('#users-table-body tr').forEach(row => {
        row.classList.remove('user-row-selected');
    });
    
    updateBulkActionsUI();
}

function updateBulkActionsUI() {
    const selectedCount = selectedUserIds.size;
    const totalVisible = currentVisibleUsers.length;
    
    const headerCheck = document.getElementById('select-all-users');
    const toolbarCheck = document.getElementById('bulk-select-all-checkbox');
    const badge = document.getElementById('bulk-selected-badge');
    const label = document.getElementById('bulk-select-label');
    
    const clearBtn = document.getElementById('bulk-clear-btn');
    const dropdownBtn = document.getElementById('bulk-actions-dropdown-btn');
    const deactivateBtn = document.getElementById('bulk-deactivate-btn');
    const deleteBtn = document.getElementById('bulk-delete-btn');
    
    const allVisibleSelected = totalVisible > 0 && currentVisibleUsers.every(u => selectedUserIds.has(u.id));
    const someVisibleSelected = totalVisible > 0 && currentVisibleUsers.some(u => selectedUserIds.has(u.id)) && !allVisibleSelected;
    
    if (headerCheck) {
        headerCheck.checked = allVisibleSelected;
        headerCheck.indeterminate = someVisibleSelected;
    }
    if (toolbarCheck) {
        toolbarCheck.checked = allVisibleSelected;
        toolbarCheck.indeterminate = someVisibleSelected;
    }
    
    if (badge) {
        badge.textContent = `${selectedCount} selected`;
        if (selectedCount > 0) {
            badge.className = 'badge bg-warning text-dark ms-1';
        } else {
            badge.className = 'badge bg-secondary ms-1';
        }
    }
    
    if (label) {
        if (allVisibleSelected && totalVisible > 0) {
            label.textContent = `All ${totalVisible} selected`;
        } else if (selectedCount > 0) {
            label.textContent = `${selectedCount} selected`;
        } else {
            label.textContent = 'Select All';
        }
    }
    
    const hasSelection = selectedCount > 0;
    if (clearBtn) clearBtn.disabled = !hasSelection;
    if (dropdownBtn) dropdownBtn.disabled = !hasSelection;
    if (deactivateBtn) deactivateBtn.disabled = !hasSelection;
    if (deleteBtn) deleteBtn.disabled = !hasSelection;
}

function bulkDeactivateUsers() {
    if (selectedUserIds.size === 0) {
        showAlert('Please select at least one user to deactivate.', 'warning');
        return;
    }
    
    const count = selectedUserIds.size;
    if (!confirm(`Are you sure you want to deactivate ${count} selected user(s)?`)) {
        return;
    }
    
    let modified = 0;
    users.forEach(user => {
        if (selectedUserIds.has(user.id)) {
            user.status = 'inactive';
            modified++;
        }
    });
    
    filterUsers();
    showAlert(`Successfully deactivated ${modified} user(s)!`, 'success');
    logAuditAction('bulk_update', 'users', `Deactivated ${modified} users in bulk`);
}

function bulkActivateUsers() {
    if (selectedUserIds.size === 0) {
        showAlert('Please select at least one user to activate.', 'warning');
        return;
    }
    
    const count = selectedUserIds.size;
    if (!confirm(`Are you sure you want to activate ${count} selected user(s)?`)) {
        return;
    }
    
    let modified = 0;
    users.forEach(user => {
        if (selectedUserIds.has(user.id)) {
            user.status = 'active';
            modified++;
        }
    });
    
    filterUsers();
    showAlert(`Successfully activated ${modified} user(s)!`, 'success');
    logAuditAction('bulk_update', 'users', `Activated ${modified} users in bulk`);
}

function bulkDeleteUsers() {
    if (selectedUserIds.size === 0) {
        showAlert('Please select at least one user to delete.', 'warning');
        return;
    }
    
    const count = selectedUserIds.size;
    if (!confirm(`Are you sure you want to permanently delete ${count} selected user(s)? This action cannot be undone.`)) {
        return;
    }
    
    const deletedUserNames = users
        .filter(u => selectedUserIds.has(u.id))
        .map(u => `${u.firstname} ${u.lastname}`)
        .join(', ');

    users = users.filter(user => !selectedUserIds.has(user.id));
    selectedUserIds.clear();
    
    filterUsers();
    showAlert(`Successfully deleted ${count} user(s)!`, 'danger');
    logAuditAction('bulk_delete', 'users', `Bulk deleted ${count} users: ${deletedUserNames}`);
}

function bulkResetPasswords() {
    if (selectedUserIds.size === 0) {
        showAlert('Please select at least one user to reset passwords for.', 'warning');
        return;
    }
    
    const count = selectedUserIds.size;
    if (!confirm(`Generate temporary passwords for ${count} selected user(s)?`)) {
        return;
    }
    
    let emails = [];
    users.forEach(user => {
        if (selectedUserIds.has(user.id)) {
            const newPassword = generatePassword();
            console.log(`Generated new password for ${user.email}:`, newPassword);
            emails.push(user.email);
        }
    });
    
    showAlert(`Temporary passwords generated for ${count} user(s).`, 'info');
    logAuditAction('bulk_reset', 'users', `Reset passwords for ${count} users (${emails.join(', ')})`);
}

// Modal functions
function showAddUserModal() {
    const modal = new bootstrap.Modal(document.getElementById('addUserModal'));
    modal.show();
}

function showEditUserModal(userId) {
    const user = users.find(u => u.id === userId);
    if (!user) return;
    
    // Populate form fields
    document.getElementById('edit-user-id').value = user.id;
    document.getElementById('edit-user-firstname').value = user.firstname;
    document.getElementById('edit-user-lastname').value = user.lastname;
    document.getElementById('edit-user-email').value = user.email;
    document.getElementById('edit-user-phone').value = user.phone || '';
    document.getElementById('edit-user-role').value = user.role;
    document.getElementById('edit-user-department').value = user.department || '';
    document.getElementById('edit-user-status').value = user.status;
    document.getElementById('edit-user-password').value = '';
    
    const modal = new bootstrap.Modal(document.getElementById('editUserModal'));
    modal.show();
}

function showAssignSupervisorModal() {
    // Load interns and supervisors for the modal
    loadInternsForAssignment();
    loadSupervisorsForAssignment();
    
    const modal = new bootstrap.Modal(document.getElementById('assignSupervisorModal'));
    modal.show();
}

// User CRUD operations
function addUser() {
    const form = document.getElementById('add-user-form');
    const formData = new FormData(form);
    
    // Validate form
    if (!validateUserForm(formData)) {
        return;
    }
    
    // Simulate API call
    const newUser = {
        id: users.length + 1,
        firstname: formData.get('firstname'),
        lastname: formData.get('lastname'),
        email: formData.get('email'),
        phone: formData.get('phone'),
        role: formData.get('role'),
        department: formData.get('department'),
        status: 'active',
        lastLogin: null
    };
    
    users.push(newUser);
    renderUsersTable();
    
    // Close modal and show success message
    bootstrap.Modal.getInstance(document.getElementById('addUserModal')).hide();
    showAlert('User added successfully!', 'success');
    
    // Log the action
    logAuditAction('create', 'user', `Added user: ${newUser.firstname} ${newUser.lastname}`);
}

function updateUser() {
    const userId = document.getElementById('edit-user-id').value;
    const user = users.find(u => u.id == userId);
    if (!user) return;
    
    // Update user data
    user.firstname = document.getElementById('edit-user-firstname').value;
    user.lastname = document.getElementById('edit-user-lastname').value;
    user.email = document.getElementById('edit-user-email').value;
    user.phone = document.getElementById('edit-user-phone').value;
    user.role = document.getElementById('edit-user-role').value;
    user.department = document.getElementById('edit-user-department').value;
    user.status = document.getElementById('edit-user-status').value;
    
    // Update password if provided
    const newPassword = document.getElementById('edit-user-password').value;
    if (newPassword) {
        // In real implementation, hash the password
        console.log('Password updated for user:', user.email);
    }
    
    renderUsersTable();
    
    // Close modal and show success message
    bootstrap.Modal.getInstance(document.getElementById('editUserModal')).hide();
    showAlert('User updated successfully!', 'success');
    
    // Log the action
    logAuditAction('update', 'user', `Updated user: ${user.firstname} ${user.lastname}`);
}

function deleteUser(userId) {
    if (!confirm('Are you sure you want to delete this user?')) {
        return;
    }
    
    const user = users.find(u => u.id === userId);
    if (!user) return;
    
    // Remove user from array and selection
    users = users.filter(u => u.id !== userId);
    selectedUserIds.delete(userId);
    renderUsersTable();
    
    showAlert('User deleted successfully!', 'success');
    
    // Log the action
    logAuditAction('delete', 'user', `Deleted user: ${user.firstname} ${user.lastname}`);
}

function resetPassword(userId) {
    const user = users.find(u => u.id === userId);
    if (!user) return;
    
    // Generate new password
    const newPassword = generatePassword();
    
    // In real implementation, update password in database
    console.log('New password for', user.email, ':', newPassword);
    
    showAlert(`Password reset for ${user.email}. New password: ${newPassword}`, 'info');
    
    // Log the action
    logAuditAction('update', 'password', `Reset password for user: ${user.email}`);
}

// Organizational Structure functions
function loadOrganizationalStructure() {
    // Simulate API call to get organizational structure
    divisions = [
        { id: 1, name: 'Finance Division', director: 'Norman' },
        { id: 2, name: 'Engineering Division', director: 'Kawuma' },
        { id: 3, name: 'Human Resources Division', director: 'Nabwire' }
    ];
    
    departments = [
        { id: 1, name: 'Accounting', division: 'Finance Division', manager: 'Senoga' },
        { id: 2, name: 'Budgeting', division: 'Finance Division', manager: 'Katumwa' },
        { id: 3, name: 'Civil Engineering', division: 'Engineering Division', manager: 'Mirembe' },
        { id: 4, name: 'Electrical Engineering', division: 'Engineering Division', manager: 'Namazi' },
        { id: 5, name: 'Recruitment', division: 'Human Resources Division', manager: 'Atwine' }
    ];
    
    renderOrganizationalStructure();
}

function renderOrganizationalStructure() {
    renderDivisions();
    renderDepartments();
}

function renderDivisions() {
    const container = document.getElementById('divisions-list');
    container.innerHTML = '';
    
    divisions.forEach(division => {
        const divisionCard = document.createElement('div');
        divisionCard.className = 'card mb-2';
        divisionCard.innerHTML = `
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-1">${division.name}</h6>
                        <small class="text-muted">Director: ${division.director}</small>
                    </div>
                    <div>
                        <button class="btn btn-sm btn-outline-primary me-1" onclick="editDivision(${division.id})">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-danger" onclick="deleteDivision(${division.id})">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            </div>
        `;
        container.appendChild(divisionCard);
    });
}

function renderDepartments() {
    const container = document.getElementById('departments-list');
    container.innerHTML = '';
    
    departments.forEach(department => {
        const departmentCard = document.createElement('div');
        departmentCard.className = 'card mb-2';
        departmentCard.innerHTML = `
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-1">${department.name}</h6>
                        <small class="text-muted">Division: ${department.division}</small><br>
                        <small class="text-muted">Manager: ${department.manager}</small>
                    </div>
                    <div>
                        <button class="btn btn-sm btn-outline-primary me-1" onclick="editDepartment(${department.id})">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn btn-sm btn-outline-danger" onclick="deleteDepartment(${department.id})">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            </div>
        `;
        container.appendChild(departmentCard);
    });
}

// Supervisor Assignment functions
function loadSupervisorAssignments() {
    // Load unassigned interns
    const unassignedInterns = users.filter(user => user.role === 'intern' && !user.supervisorId);
    renderUnassignedInterns(unassignedInterns);
    
    // Load current assignments
    const assignments = [
        { intern: 'Tracy', supervisor: 'Aron', department: 'Finance', date: '2024-01-10' },
        { intern: 'Davis', supervisor: 'Calvin', department: 'Engineering', date: '2024-01-08' },
        { intern: 'Mutoni', supervisor: 'Norman', department: 'HR', date: '2024-01-05' }
    ];
    renderCurrentAssignments(assignments);
}

function renderUnassignedInterns(interns) {
    const container = document.getElementById('unassigned-interns');
    container.innerHTML = '';
    
    if (interns.length === 0) {
        container.innerHTML = '<p class="text-muted">All interns have been assigned supervisors.</p>';
        return;
    }
    
    interns.forEach(intern => {
        const internCard = document.createElement('div');
        internCard.className = 'user-card';
        internCard.innerHTML = `
            <div class="d-flex align-items-center">
                <div class="user-avatar me-3">${intern.firstname.charAt(0)}${intern.lastname.charAt(0)}</div>
                <div class="flex-grow-1">
                    <h6 class="mb-1">${intern.firstname} ${intern.lastname}</h6>
                    <small class="text-muted">${intern.department} • ${intern.email}</small>
                </div>
                <button class="btn btn-sm btn-primary" onclick="assignSupervisorToIntern(${intern.id})">
                    Assign
                </button>
            </div>
        `;
        container.appendChild(internCard);
    });
}

function renderCurrentAssignments(assignments) {
    const container = document.getElementById('current-assignments');
    container.innerHTML = '';
    
    assignments.forEach(assignment => {
        const assignmentCard = document.createElement('div');
        assignmentCard.className = 'assignment-card';
        assignmentCard.innerHTML = `
            <div class="assignment-header">
                <span class="assignment-intern">${assignment.intern}</span>
                <small class="text-muted">${assignment.date}</small>
            </div>
            <div class="assignment-details">
                <small class="text-muted">Supervisor: </small>
                <span class="assignment-supervisor">${assignment.supervisor}</span>
                <br>
                <small class="text-muted">Department: ${assignment.department}</small>
            </div>
            <div class="mt-2">
                <button class="btn btn-sm btn-outline-warning me-1" onclick="reassignSupervisor('${assignment.intern}')">
                    Reassign
                </button>
                <button class="btn btn-sm btn-outline-danger" onclick="removeAssignment('${assignment.intern}')">
                    Remove
                </button>
            </div>
        `;
        container.appendChild(assignmentCard);
    });
}

function assignSupervisor() {
    const internId = document.getElementById('assign-intern').value;
    const supervisorId = document.getElementById('assign-supervisor').value;
    const notes = document.getElementById('assignment-notes').value;
    
    if (!internId || !supervisorId) {
        showAlert('Please select both intern and supervisor.', 'warning');
        return;
    }
    
    // In real implementation, save assignment to database
    console.log('Assigning supervisor', supervisorId, 'to intern', internId, 'with notes:', notes);
    
    // Close modal and show success message
    bootstrap.Modal.getInstance(document.getElementById('assignSupervisorModal')).hide();
    showAlert('Supervisor assigned successfully!', 'success');
    
    // Reload assignments
    loadSupervisorAssignments();
    
    // Log the action
    logAuditAction('create', 'assignment', `Assigned supervisor to intern`);
}

// Audit Logs functions
function loadAuditLogs() {
    // Simulate API call to get audit logs
    auditLogs = [
        {
            id: 1,
            timestamp: '2024-01-15 10:30:00',
            user: 'Tendo',
            action: 'create',
            details: 'Added new user: Taliq',
            ipAddress: '192.168.1.100'
        },
        {
            id: 2,
            timestamp: '2024-01-15 10:25:00',
            user: 'Wilber',
            action: 'login',
            details: 'User logged in successfully',
            ipAddress: '192.168.1.101'
        },
        {
            id: 3,
            timestamp: '2024-01-15 10:20:00',
            user: 'Tendo',
            action: 'update',
            details: 'Updated system settings',
            ipAddress: '192.168.1.100'
        },
        {
            id: 4,
            timestamp: '2024-01-15 10:15:00',
            user: 'Wilber',
            action: 'logout',
            details: 'User logged out',
            ipAddress: '192.168.1.102'
        }
    ];
    
    renderAuditLogs();
}

function renderAuditLogs() {
    const tbody = document.getElementById('audit-logs-table-body');
    tbody.innerHTML = '';
    
    auditLogs.forEach(log => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>${formatDateTime(log.timestamp)}</td>
            <td>${log.user}</td>
            <td><span class="badge bg-${getActionBadgeColor(log.action)}">${log.action}</span></td>
            <td>${log.details}</td>
            <td>${log.ipAddress}</td>
        `;
        tbody.appendChild(row);
    });
}

function getActionBadgeColor(action) {
    const colors = {
        'login': 'success',
        'logout': 'secondary',
        'create': 'primary',
        'update': 'info',
        'delete': 'danger'
    };
    return colors[action] || 'secondary';
}

function filterAuditLogs() {
    const actionFilter = document.getElementById('log-action-filter').value;
    const userFilter = document.getElementById('log-user-filter').value;
    const dateFrom = document.getElementById('log-date-from').value;
    const dateTo = document.getElementById('log-date-to').value;
    
    const filteredLogs = auditLogs.filter(log => {
        const matchesAction = !actionFilter || log.action === actionFilter;
        const matchesUser = !userFilter || log.user === userFilter;
        const matchesDateFrom = !dateFrom || log.timestamp >= dateFrom;
        const matchesDateTo = !dateTo || log.timestamp <= dateTo;
        
        return matchesAction && matchesUser && matchesDateFrom && matchesDateTo;
    });
    
    renderFilteredAuditLogs(filteredLogs);
}

function renderFilteredAuditLogs(filteredLogs) {
    const tbody = document.getElementById('audit-logs-table-body');
    tbody.innerHTML = '';
    
    filteredLogs.forEach(log => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>${formatDateTime(log.timestamp)}</td>
            <td>${log.user}</td>
            <td><span class="badge bg-${getActionBadgeColor(log.action)}">${log.action}</span></td>
            <td>${log.details}</td>
            <td>${log.ipAddress}</td>
        `;
        tbody.appendChild(row);
    });
}

function exportAuditLogs() {
    // In real implementation, generate and download CSV/Excel file
    console.log('Exporting audit logs...');
    showAlert('Audit logs exported successfully!', 'success');
}

function clearAuditLogs() {
    if (!confirm('Are you sure you want to clear all audit logs? This action cannot be undone.')) {
        return;
    }
    
    auditLogs = [];
    renderAuditLogs();
    showAlert('Audit logs cleared successfully!', 'success');
}

// System Settings functions
function loadSystemSettings() {
    // Simulate API call to get system settings
    systemSettings = {
        sessionTimeout: 30,
        maxFileSize: 5,
        maintenanceMode: false,
        requireUppercase: true,
        requireNumbers: true,
        requireSymbols: true,
        minPasswordLength: 8,
        mfaRequired: true
    };
    
    populateSystemSettingsForm();
}

function populateSystemSettingsForm() {
    document.getElementById('session-timeout').value = systemSettings.sessionTimeout;
    document.getElementById('max-file-size').value = systemSettings.maxFileSize;
    document.getElementById('maintenance-mode').checked = systemSettings.maintenanceMode;
    document.getElementById('require-uppercase').checked = systemSettings.requireUppercase;
    document.getElementById('require-numbers').checked = systemSettings.requireNumbers;
    document.getElementById('require-symbols').checked = systemSettings.requireSymbols;
    document.getElementById('min-password-length').value = systemSettings.minPasswordLength;
    document.getElementById('mfa-required').checked = systemSettings.mfaRequired;

    // Sync theme radio buttons
    const storedTheme = getStoredTheme();
    const radioLight = document.getElementById('theme-radio-light');
    const radioDark = document.getElementById('theme-radio-dark');
    const radioSystem = document.getElementById('theme-radio-system');
    if (radioLight && radioDark && radioSystem) {
        radioLight.checked = (storedTheme === 'light');
        radioDark.checked = (storedTheme === 'dark');
        radioSystem.checked = (storedTheme === 'system');
    }
}

function saveSystemSettings() {
    // Check if theme radio was modified
    const selectedThemeRadio = document.querySelector('input[name="admin-theme-radio"]:checked');
    if (selectedThemeRadio) {
        if (selectedThemeRadio.id === 'theme-radio-light') setTheme('light');
        else if (selectedThemeRadio.id === 'theme-radio-dark') setTheme('dark');
        else if (selectedThemeRadio.id === 'theme-radio-system') setTheme('system');
    }

    // Collect form data
    systemSettings = {
        sessionTimeout: parseInt(document.getElementById('session-timeout').value),
        maxFileSize: parseInt(document.getElementById('max-file-size').value),
        maintenanceMode: document.getElementById('maintenance-mode').checked,
        requireUppercase: document.getElementById('require-uppercase').checked,
        requireNumbers: document.getElementById('require-numbers').checked,
        requireSymbols: document.getElementById('require-symbols').checked,
        minPasswordLength: parseInt(document.getElementById('min-password-length').value),
        mfaRequired: document.getElementById('mfa-required').checked
    };
    
    // In real implementation, save to database
    console.log('Saving system settings:', systemSettings);
    
    showAlert('System settings saved successfully!', 'success');
    
    // Log the action
    logAuditAction('update', 'settings', 'Updated system settings');
}

// Utility functions
function formatDateTime(dateTimeString) {
    if (!dateTimeString) return '-';
    const date = new Date(dateTimeString);
    return date.toLocaleString();
}

function generatePassword() {
    const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*';
    let password = '';
    for (let i = 0; i < 12; i++) {
        password += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    return password;
}

function showAlert(message, type) {
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
    alertDiv.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    // Insert at the top of main content
    const main = document.querySelector('main');
    main.insertBefore(alertDiv, main.firstChild);
    
    // Auto-dismiss after 5 seconds
    setTimeout(() => {
        if (alertDiv.parentNode) {
            alertDiv.remove();
        }
    }, 5000);
}

function logAuditAction(action, resource, details) {
    const logEntry = {
        id: auditLogs.length + 1,
        timestamp: new Date().toISOString().replace('T', ' ').substring(0, 19),
        user: 'Admin', // In real implementation, get current user
        action: action,
        details: details,
        ipAddress: '192.168.1.100' // In real implementation, get actual IP
    };
    
    auditLogs.unshift(logEntry);
    
    // Update audit logs table if currently visible
    if (currentSection === 'audit-logs') {
        renderAuditLogs();
    }
}

function setupFormValidations() {
    // Password confirmation validation
    const passwordField = document.getElementById('user-password');
    const confirmPasswordField = document.getElementById('user-confirm-password');
    
    if (passwordField && confirmPasswordField) {
        confirmPasswordField.addEventListener('input', function() {
            if (passwordField.value !== confirmPasswordField.value) {
                confirmPasswordField.setCustomValidity('Passwords do not match');
            } else {
                confirmPasswordField.setCustomValidity('');
            }
        });
    }
}

function setupAutoSave() {
    // Auto-save system settings every 30 seconds
    setInterval(() => {
        if (currentSection === 'system-settings') {
            // Only auto-save if form has been modified
            console.log('Auto-saving system settings...');
        }
    }, 30000);
}

function validateUserForm(formData) {
    const requiredFields = ['firstname', 'lastname', 'email', 'role'];
    
    for (const field of requiredFields) {
        if (!formData.get(field)) {
            showAlert(`Please fill in the ${field} field.`, 'warning');
            return false;
        }
    }
    
    // Validate email format
    const email = formData.get('email');
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(email)) {
        showAlert('Please enter a valid email address.', 'warning');
        return false;
    }
    
    // Validate password strength
    const password = formData.get('password');
    if (password && password.length < 8) {
        showAlert('Password must be at least 8 characters long.', 'warning');
        return false;
    }
    
    return true;
}

// Profile and logout functions
function showProfile() {
    // In real implementation, show user profile modal
    alert('Profile functionality - to be implemented');
}

function changePassword() {
    // In real implementation, show change password modal
    alert('Change password functionality - to be implemented');
}

function logout() {
    if (confirm('Are you sure you want to logout?')) {
        // In real implementation, clear session and redirect to login
        window.location.href = 'login.html';
    }
}

// Additional helper functions for organizational structure
function showAddDivisionModal() {
    // In real implementation, show add division modal
    alert('Add division functionality - to be implemented');
}

function showAddDepartmentModal() {
    // In real implementation, show add department modal
    alert('Add department functionality - to be implemented');
}

function editDivision(divisionId) {
    // In real implementation, show edit division modal
    alert(`Edit division ${divisionId} - to be implemented`);
}

function deleteDivision(divisionId) {
    if (confirm('Are you sure you want to delete this division?')) {
        // In real implementation, delete division
        alert(`Delete division ${divisionId} - to be implemented`);
    }
}

function editDepartment(departmentId) {
    // In real implementation, show edit department modal
    alert(`Edit department ${departmentId} - to be implemented`);
}

function deleteDepartment(departmentId) {
    if (confirm('Are you sure you want to delete this department?')) {
        // In real implementation, delete department
        alert(`Delete department ${departmentId} - to be implemented`);
    }
}

function loadInternsForAssignment() {
    const internSelect = document.getElementById('assign-intern');
    internSelect.innerHTML = '<option value="">Select Intern</option>';
    
    const interns = users.filter(user => user.role === 'intern' && !user.supervisorId);
    interns.forEach(intern => {
        const option = document.createElement('option');
        option.value = intern.id;
        option.textContent = `${intern.firstname} ${intern.lastname} (${intern.department})`;
        internSelect.appendChild(option);
    });
}

function loadSupervisorsForAssignment() {
    const supervisorSelect = document.getElementById('assign-supervisor');
    supervisorSelect.innerHTML = '<option value="">Select Supervisor</option>';
    
    const supervisors = users.filter(user => user.role === 'supervisor');
    supervisors.forEach(supervisor => {
        const option = document.createElement('option');
        option.value = supervisor.id;
        option.textContent = `${supervisor.firstname} ${supervisor.lastname} (${supervisor.department})`;
        supervisorSelect.appendChild(option);
    });
}

function assignSupervisorToIntern(internId) {
    // In real implementation, show assignment modal
    alert(`Assign supervisor to intern ${internId} - to be implemented`);
}

function reassignSupervisor(internName) {
    // In real implementation, show reassignment modal
    alert(`Reassign supervisor for ${internName} - to be implemented`);
}

function removeAssignment(internName) {
    if (confirm(`Are you sure you want to remove the supervisor assignment for ${internName}?`)) {
        // In real implementation, remove assignment
        alert(`Remove assignment for ${internName} - to be implemented`);
    }
} 
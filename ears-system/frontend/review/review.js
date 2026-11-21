(() => {
    'use strict';

    let currentRole = '';
    let selectedReport = null;
    let queuedReports = new Map();
    const modalElement = document.getElementById('report-review-modal');
    const modal = new bootstrap.Modal(modalElement);

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, char => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        })[char]);
    }

    function setAlert(message, type = 'danger') {
        const alert = document.getElementById('page-alert');
        alert.textContent = message;
        alert.className = `alert alert-${type}`;
    }

    async function apiRequest(url, options = {}) {
        const response = await window.EARSAuth.request(url, options);
        const payload = await response.json().catch(() => ({}));
        if (!response.ok || payload.status !== 'success') {
            throw new Error(payload.message || 'The request could not be completed.');
        }
        return payload;
    }

    function reportPeriod(report) {
        if (report.week_start && report.week_end) return `${report.week_start} – ${report.week_end}`;
        return `Week ${report.week_number}, ${report.year}`;
    }

    async function loadQueue() {
        const body = document.getElementById('review-queue-body');
        body.innerHTML = '<tr><td colspan="6" class="text-center py-5 text-secondary">Loading reports…</td></tr>';
        try {
            const result = await apiRequest('/backend/api/review_queue.php');
            const reports = result.reports || [];
            queuedReports = new Map(reports.map(report => [Number(report.id), report]));
            body.replaceChildren();
            if (!reports.length) {
                body.innerHTML = '<tr><td colspan="6" class="text-center py-5 text-secondary">No reports are waiting for your review.</td></tr>';
                return;
            }
            reports.forEach(report => {
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td class="fw-semibold">${escapeHtml(report.intern_name)}</td>
                    <td>${escapeHtml(report.department || '—')}</td>
                    <td>${escapeHtml(reportPeriod(report))}</td>
                    <td>${escapeHtml(report.submitted_at)}</td>
                    <td>${Number(report.entry_count)}</td>
                    <td class="text-end"><button class="btn btn-sm btn-outline-success" type="button" data-report-id="${Number(report.id)}">Review</button></td>`;
                body.appendChild(row);
            });
            body.querySelectorAll('[data-report-id]').forEach(button => {
                button.addEventListener('click', () => openReport(Number(button.dataset.reportId)));
            });
        } catch (error) {
            body.innerHTML = '<tr><td colspan="6" class="text-center py-5 text-danger">Could not load reports.</td></tr>';
            setAlert(error.message);
        }
    }

    function buildReportContent(data) {
        const report = data.report || {};
        const entries = data.daily_entries || [];
        const summary = data.weekly_summary || {};
        const attachments = data.attachments || [];
        const days = entries.map(entry => `
            <tr>
                <td>${escapeHtml(entry.day_of_week)}<div class="small text-secondary">${escapeHtml(entry.entry_date)}</div></td>
                <td>${escapeHtml(entry.time_in || '—')} – ${escapeHtml(entry.time_out || '—')}</td>
                <td class="text-break">${escapeHtml(entry.activities)}</td>
                <td class="text-break">${escapeHtml(entry.challenges || '—')}</td>
                <td class="text-break">${escapeHtml(entry.tasks_in_progress || '—')}</td>
            </tr>`).join('');
        return `
            <div class="mb-3"><strong>Period:</strong> ${escapeHtml(report.week_number)} / ${escapeHtml(report.month)} / ${escapeHtml(report.year)}
                <span class="ms-3"><strong>Status:</strong> ${escapeHtml(report.status)}</span></div>
            ${report.supervisor_comment ? `<div class="alert alert-info"><strong>Supervisor feedback:</strong> ${escapeHtml(report.supervisor_comment)}</div>` : ''}
            <div class="table-responsive"><table class="table table-sm table-bordered align-middle">
                <thead class="table-light"><tr><th>Day</th><th>Attendance</th><th>Activities</th><th>Challenges</th><th>In progress</th></tr></thead>
                <tbody>${days || '<tr><td colspan="5" class="text-center text-secondary">No daily entries were recorded.</td></tr>'}</tbody>
            </table></div>
            <h3 class="h6 mt-4">Weekly summary</h3>
            <dl class="row mb-0">
                <dt class="col-sm-3">Key achievements</dt><dd class="col-sm-9 text-break">${escapeHtml(summary.key_achievements || '—')}</dd>
                <dt class="col-sm-3">Major challenges</dt><dd class="col-sm-9 text-break">${escapeHtml(summary.major_challenges || '—')}</dd>
                <dt class="col-sm-3">Plans for next week</dt><dd class="col-sm-9 text-break">${escapeHtml(summary.plans_next_week || '—')}</dd>
            </dl>
            <h3 class="h6 mt-4">Supporting attachments</h3>
            ${attachments.length ? `<ul>${attachments.map(file => `<li><a href="/backend/api/download_attachment.php?id=${Number(file.id)}" target="_blank" rel="noopener">${escapeHtml(file.file_name)}</a></li>`).join('')}</ul>` : '<p class="text-secondary small">No attachments.</p>'}`;
    }

    async function openReport(reportId) {
        selectedReport = reportId;
        document.getElementById('report-review-content').textContent = 'Loading report…';
        document.getElementById('supervisor-comment').value = '';
        document.getElementById('request-revisions').disabled = false;
        document.getElementById('approve-report').disabled = false;
        document.getElementById('complete-hr-review').disabled = false;
        modal.show();
        try {
            const result = await apiRequest(`/backend/api/details.php?report_id=${encodeURIComponent(reportId)}`);
            const report = result.data?.report;
            if (!report) throw new Error('Report details were not returned.');
            const queueRecord = queuedReports.get(reportId);
            document.getElementById('report-review-title').textContent = `Report from ${queueRecord?.intern_name || `Intern #${report.intern_id}`}`;
            document.getElementById('report-review-subtitle').textContent = `Week ${report.week_number}, ${report.month}/${report.year}`;
            document.getElementById('report-review-content').innerHTML = buildReportContent(result.data);
        } catch (error) {
            document.getElementById('report-review-content').textContent = error.message;
        }
    }

    async function saveDecision(status) {
        if (!selectedReport) return;
        const comment = document.getElementById('supervisor-comment').value.trim();
        if (status === 'rejected' && !comment) {
            setAlert('Add a comment explaining the revisions required.', 'warning');
            return;
        }
        const buttons = ['request-revisions', 'approve-report', 'complete-hr-review']
            .map(id => document.getElementById(id));
        buttons.forEach(button => { button.disabled = true; });
        try {
            await apiRequest('/backend/api/status.php', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ report_id: selectedReport, status, comment }),
            });
            modal.hide();
            setAlert(status === 'rejected' ? 'Revision request recorded.' : 'Report review recorded.', 'success');
            await loadQueue();
        } catch (error) {
            setAlert(error.message);
        } finally {
            buttons.forEach(button => { button.disabled = false; });
        }
    }

    document.addEventListener('DOMContentLoaded', async () => {
        try {
            const response = await window.EARSAuth.request('/backend/auth/me.php');
            const payload = await response.json().catch(() => ({}));
            const user = payload.user;
            if (!response.ok || !user) {
                window.location.replace('/ears-system/frontend/login.html');
                return;
            }
            if (!['supervisor', 'hr'].includes(user.role)) {
                window.location.replace('/ears-system/frontend/login.html?status=role');
                return;
            }
            currentRole = user.role;
            document.querySelector('[data-ears-user-name]').textContent = user.full_name;
            document.getElementById('queue-title').textContent = currentRole === 'hr' ? 'Supervisor-approved reports' : 'Reports awaiting your review';
            document.getElementById('queue-description').textContent = currentRole === 'hr'
                ? 'Review reports approved by supervisors and complete the HR stage.'
                : 'Review reports submitted by interns assigned to you.';
            document.getElementById('supervisor-comment-wrap').classList.toggle('d-none', currentRole !== 'supervisor');
            document.getElementById('request-revisions').classList.toggle('d-none', currentRole !== 'supervisor');
            document.getElementById('approve-report').classList.toggle('d-none', currentRole !== 'supervisor');
            document.getElementById('complete-hr-review').classList.toggle('d-none', currentRole !== 'hr');
            document.getElementById('approve-report').addEventListener('click', () => saveDecision('approved_by_supervisor'));
            document.getElementById('request-revisions').addEventListener('click', () => saveDecision('rejected'));
            document.getElementById('complete-hr-review').addEventListener('click', () => saveDecision('submitted_to_hr'));
            document.getElementById('refresh-queue').addEventListener('click', loadQueue);
            document.documentElement.classList.remove('auth-checking');
            await loadQueue();
        } catch {
            window.location.replace('/ears-system/frontend/login.html?status=unavailable');
        }
    });
})();

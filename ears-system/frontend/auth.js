(function () {
    'use strict';

    function getCsrfToken() {
        const item = document.cookie.split('; ').find((cookie) => cookie.startsWith('ears_csrf='));
        return item ? decodeURIComponent(item.slice('ears_csrf='.length)) : '';
    }

    async function request(url, options = {}) {
        const method = (options.method || 'GET').toUpperCase();
        const headers = new Headers(options.headers || {});
        if (!['GET', 'HEAD', 'OPTIONS'].includes(method)) {
            headers.set('X-CSRF-Token', getCsrfToken());
        }
        return fetch(url, {
            ...options,
            method,
            headers,
            credentials: 'same-origin',
            cache: 'no-store',
        });
    }

    async function requireRole(role) {
        try {
            const response = await request('/backend/auth/me.php');
            if (response.status === 401) {
                window.location.replace('/ears-system/frontend/login.html');
                return null;
            }
            if (!response.ok) {
                window.location.replace('/ears-system/frontend/login.html?status=unavailable');
                return null;
            }

            const result = await response.json();
            const user = result.user;
            if (!user || user.role !== role) {
                window.location.replace('/ears-system/frontend/login.html?status=role');
                return null;
            }

            document.querySelectorAll('[data-ears-user-name]').forEach((element) => {
                element.textContent = user.full_name;
            });
            document.documentElement.classList.remove('auth-checking');
            return user;
        } catch {
            window.location.replace('/ears-system/frontend/login.html?status=unavailable');
            return null;
        }
    }

    async function logout() {
        try {
            await request('/backend/auth/logout.php', { method: 'POST' });
        } finally {
            window.location.replace('/ears-system/frontend/login.html');
        }
    }

    window.EARSAuth = { request, requireRole, logout };

    document.addEventListener('click', (event) => {
        const logoutLink = event.target.closest('[data-ears-logout]');
        if (!logoutLink) return;
        event.preventDefault();
        logout();
    });
})();

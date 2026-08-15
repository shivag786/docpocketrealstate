import * as bootstrap from 'bootstrap';

window.bootstrap = bootstrap;

/**
 * ---------------------------------------------------------------------------
 * AJAX conventions
 * ---------------------------------------------------------------------------
 * Every endpoint answers with the App\Support\ApiResponse envelope:
 *
 *   { success: bool, message: string|null, data: mixed, errors: object|null }
 *
 * `request()` below is the only place that talks to the server, so CSRF,
 * error handling and the 422 validation shape are solved once for all phases.
 */

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

class RequestError extends Error {
    constructor(message, { status = 0, errors = null } = {}) {
        super(message);
        this.name = 'RequestError';
        this.status = status;
        this.errors = errors;
    }
}

/**
 * @param {string} url
 * @param {{method?: string, data?: object|FormData, signal?: AbortSignal}} options
 * @returns {Promise<{success: boolean, message: ?string, data: *, errors: ?object}>}
 */
async function request(url, { method = 'GET', data = null, signal = null } = {}) {
    const headers = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': csrfToken(),
    };

    const init = { method: method.toUpperCase(), headers, signal };

    if (data instanceof FormData) {
        init.body = data;
    } else if (data !== null) {
        headers['Content-Type'] = 'application/json';
        init.body = JSON.stringify(data);
    }

    let response;
    try {
        response = await fetch(url, init);
    } catch (error) {
        if (error.name === 'AbortError') throw error;
        throw new RequestError('Could not reach the server. Check your connection.');
    }

    // A session that expired mid-request returns 401; send the user to login
    // rather than leaving them clicking a dead page.
    if (response.status === 401) {
        window.location.href = '/login';
        throw new RequestError('Your session has expired.', { status: 401 });
    }

    let payload;
    try {
        payload = await response.json();
    } catch {
        throw new RequestError('The server returned an unreadable response.', {
            status: response.status,
        });
    }

    if (!response.ok || payload.success === false) {
        throw new RequestError(payload.message || 'The request failed.', {
            status: response.status,
            errors: payload.errors ?? null,
        });
    }

    return payload;
}

/** Toggle a busy overlay on any container without freezing the page. */
function setLoading(target, isLoading = true) {
    const el = typeof target === 'string' ? document.querySelector(target) : target;
    if (!el) return;
    el.classList.toggle('is-loading', isLoading);
    el.setAttribute('aria-busy', String(isLoading));
}

/** Paint Laravel validation errors onto a form using Bootstrap's markup. */
function showFormErrors(form, errors = {}) {
    clearFormErrors(form);

    Object.entries(errors).forEach(([field, messages]) => {
        const input = form.querySelector(`[name="${field}"]`);
        if (!input) return;

        input.classList.add('is-invalid');

        const feedback = document.createElement('div');
        feedback.className = 'invalid-feedback';
        feedback.textContent = Array.isArray(messages) ? messages[0] : messages;
        input.insertAdjacentElement('afterend', feedback);
    });

    form.querySelector('.is-invalid')?.focus();
}

function clearFormErrors(form) {
    form.querySelectorAll('.is-invalid').forEach((el) => el.classList.remove('is-invalid'));
    form.querySelectorAll('.invalid-feedback').forEach((el) => el.remove());
}

/** Transient toast, bottom-right. */
function notify(message, variant = 'success') {
    let stack = document.getElementById('app-toast-stack');

    if (!stack) {
        stack = document.createElement('div');
        stack.id = 'app-toast-stack';
        stack.className = 'toast-container position-fixed bottom-0 end-0 p-3';
        document.body.appendChild(stack);
    }

    const toast = document.createElement('div');
    toast.className = `toast align-items-center text-bg-${variant} border-0`;
    toast.setAttribute('role', 'alert');
    toast.innerHTML = `
        <div class="d-flex">
            <div class="toast-body"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>`;
    toast.querySelector('.toast-body').textContent = message;

    stack.appendChild(toast);

    const instance = new bootstrap.Toast(toast, { delay: 4000 });
    toast.addEventListener('hidden.bs.toast', () => toast.remove());
    instance.show();
}

window.App = { request, RequestError, setLoading, showFormErrors, clearFormErrors, notify };

/**
 * ---------------------------------------------------------------------------
 * Shell behaviour
 * ---------------------------------------------------------------------------
 */
document.addEventListener('DOMContentLoaded', () => {
    // Sidebar toggle for viewports below lg.
    const sidebar = document.querySelector('.app-sidebar');
    const toggle = document.querySelector('[data-app-sidebar-toggle]');
    let backdrop = null;

    const closeSidebar = () => {
        sidebar?.classList.remove('is-open');
        backdrop?.remove();
        backdrop = null;
    };

    toggle?.addEventListener('click', () => {
        if (!sidebar) return;

        sidebar.classList.toggle('is-open');

        if (sidebar.classList.contains('is-open')) {
            backdrop = document.createElement('div');
            backdrop.className = 'app-sidebar-backdrop d-lg-none';
            backdrop.addEventListener('click', closeSidebar);
            document.body.appendChild(backdrop);
        } else {
            closeSidebar();
        }
    });

    // Confirm destructive actions before they fire.
    document.querySelectorAll('[data-confirm]').forEach((el) => {
        el.addEventListener('submit', (event) => {
            if (!window.confirm(el.dataset.confirm)) {
                event.preventDefault();
            }
        });
    });

    // Auto-dismiss server-rendered flash messages.
    document.querySelectorAll('.alert-dismissible[data-auto-dismiss]').forEach((el) => {
        setTimeout(() => bootstrap.Alert.getOrCreateInstance(el).close(), 5000);
    });
});

import * as bootstrap from 'bootstrap';
import Swal from 'sweetalert2';

window.bootstrap = bootstrap;

// Feature modules. Each is inert unless its markup is present on the page.
import './sponsor-picker.js';
import './member-tree.js';
import './sale-entry.js';
import './company-club.js';

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

/**
 * ---------------------------------------------------------------------------
 * Confirmation dialog
 * ---------------------------------------------------------------------------
 * One dialog for every irreversible action in the back office: marking a reward
 * paid, entering a sale, rebuilding a month.
 *
 * The browser's own confirm() cannot show WHO is about to be paid or HOW MUCH,
 * and a confirmation that only says "are you sure?" is one an operator learns to
 * click through. `details` renders a labelled list inside the dialog, so the
 * facts that matter are in front of them at the moment they commit.
 *
 * @param {{title?: string, text?: string, details?: [string, string][],
 *          confirmText?: string, cancelText?: string, variant?: string,
 *          icon?: string}} options
 * @returns {Promise<boolean>} whether the operator confirmed
 */
async function confirmAction({
    title = 'Are you sure?',
    text = '',
    details = [],
    confirmText = 'Yes, continue',
    cancelText = 'Cancel',
    variant = 'primary',
    icon = 'warning',
} = {}) {
    const escape = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');

    const list = details.length === 0 ? '' : `
        <dl class="row text-start small mb-0 mt-3 border-top pt-3">
            ${details.map(([label, value]) => `
                <dt class="col-5 text-muted fw-normal">${escape(label)}</dt>
                <dd class="col-7 mb-1 fw-semibold">${escape(value)}</dd>
            `).join('')}
        </dl>`;

    const result = await Swal.fire({
        title,
        icon,
        html: `${text ? `<p class="mb-0 small text-muted">${escape(text)}</p>` : ''}${list}`,
        showCancelButton: true,
        confirmButtonText: confirmText,
        cancelButtonText: cancelText,
        reverseButtons: true,
        focusCancel: true,
        buttonsStyling: false,
        customClass: {
            popup: 'shadow',
            title: 'h5',
            confirmButton: `btn btn-${variant} px-4`,
            cancelButton: 'btn btn-outline-secondary px-4 me-2',
        },
    });

    return result.isConfirmed === true;
}

/**
 * Read a confirmation off an element's data attributes.
 *
 * `data-confirm-details` is a JSON array of [label, value] pairs — the person,
 * the amount, the month. Anything malformed is dropped rather than blocking the
 * dialog: a broken detail list must never stop an operator working.
 */
function confirmOptionsFrom(el) {
    let details = [];

    try {
        details = JSON.parse(el.dataset.confirmDetails || '[]');
    } catch {
        details = [];
    }

    return {
        title: el.dataset.confirmTitle || 'Are you sure?',
        text: el.dataset.confirmSubmit || el.dataset.confirm || '',
        details: Array.isArray(details) ? details : [],
        confirmText: el.dataset.confirmButton || 'Yes, continue',
        variant: el.dataset.confirmVariant || 'primary',
        icon: el.dataset.confirmIcon || 'warning',
    };
}

window.App = {
    request,
    RequestError,
    setLoading,
    showFormErrors,
    clearFormErrors,
    notify,
    confirm: confirmAction,
    confirmOptionsFrom,
};

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
    //
    // The dialog is asynchronous, so the submit is ALWAYS stopped first and
    // replayed only once the operator has confirmed. `confirmed` marks the
    // replay so the handler does not ask a second time.
    document.querySelectorAll('[data-confirm]').forEach((form) => {
        form.addEventListener('submit', async (event) => {
            if (form.dataset.confirmed === 'yes') {
                return;
            }

            event.preventDefault();

            if (await window.App.confirm(window.App.confirmOptionsFrom(form))) {
                form.dataset.confirmed = 'yes';
                form.submit();
            }
        });
    });

    // Show/hide a password field.
    //
    // One handler for every [data-password-toggle] button in the application —
    // the sign-in form and the change-password form both use it. The value of
    // the attribute is the id of the field it reveals, so a form with three
    // password fields gets three independent toggles.
    document.querySelectorAll('[data-password-toggle]').forEach((button) => {
        const field = document.getElementById(button.dataset.passwordToggle);
        const icon = button.querySelector('i');
        if (!field) return;

        button.addEventListener('click', () => {
            const revealed = field.type === 'text';

            field.type = revealed ? 'password' : 'text';
            icon?.classList.toggle('bi-eye', revealed);
            icon?.classList.toggle('bi-eye-slash', !revealed);

            const label = revealed ? 'Show password' : 'Hide password';
            button.setAttribute('aria-label', label);
            button.title = label;

            // Typing should carry on where it left off, not at position zero.
            field.focus();
            field.setSelectionRange?.(field.value.length, field.value.length);
        });
    });

    // Typed-phrase gate in front of an irreversible action.
    //
    // The button stays disabled until the operator has typed the exact word.
    // This is a courtesy, NOT the guard: the server checks the same phrase
    // (see ResetSystemRequest), because a disabled attribute is one devtools
    // click away from gone.
    document.querySelectorAll('[data-confirm-phrase]').forEach((input) => {
        const button = document.getElementById(input.dataset.confirmTarget);
        if (!button) return;

        const phrase = input.dataset.confirmPhrase;

        const sync = () => {
            const matches = input.value.trim() === phrase;
            button.disabled = !matches;
            input.classList.toggle('is-valid', matches && input.value !== '');
        };

        input.addEventListener('input', sync);
        sync();
    });

    // Auto-dismiss server-rendered flash messages.
    document.querySelectorAll('.alert-dismissible[data-auto-dismiss]').forEach((el) => {
        setTimeout(() => bootstrap.Alert.getOrCreateInstance(el).close(), 5000);
    });
});

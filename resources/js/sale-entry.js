/**
 * Daily sale entry helpers.
 *
 *  - member lookup over AJAX (the network can be large; never a <select>)
 *  - property dropdown that depends on the chosen project
 *  - explicit confirmation before saving, because a sale is approved on entry
 *    and can never be edited or deleted afterwards
 */

const MIN_QUERY_LENGTH = 2;
const DEBOUNCE_MS = 300;

function initMemberPicker(root) {
    const searchInput = root.querySelector('[data-member-search]');
    const results = root.querySelector('[data-member-results]');
    const selected = root.querySelector('[data-member-selected]');
    const clearBtn = root.querySelector('[data-member-clear]');
    const hidden = document.getElementById('member_id');

    if (!searchInput || !results || !hidden) return;

    const searchUrl = root.dataset.searchUrl;
    let debounce = null;
    let inFlight = null;

    const hide = () => {
        results.classList.add('d-none');
        results.innerHTML = '';
    };

    const choose = (member) => {
        hidden.value = member.id;
        selected.querySelector('[data-member-name]').textContent = member.name;
        selected.querySelector('[data-member-code]').textContent = member.member_code;
        selected.classList.remove('d-none');
        searchInput.value = '';
        hide();
        document.getElementById('project_id')?.focus();
    };

    clearBtn?.addEventListener('click', () => {
        hidden.value = '';
        selected.classList.add('d-none');
        searchInput.focus();
    });

    searchInput.addEventListener('input', () => {
        const term = searchInput.value.trim();
        clearTimeout(debounce);

        if (term.length < MIN_QUERY_LENGTH) {
            hide();
            return;
        }

        debounce = setTimeout(async () => {
            inFlight?.abort();
            inFlight = new AbortController();

            try {
                const { data } = await window.App.request(
                    `${searchUrl}?q=${encodeURIComponent(term)}`,
                    { signal: inFlight.signal }
                );

                results.innerHTML = '';

                if (!data || data.length === 0) {
                    const empty = document.createElement('div');
                    empty.className = 'list-group-item text-muted small';
                    empty.textContent = 'No members found.';
                    results.appendChild(empty);
                } else {
                    data.forEach((member) => {
                        const item = document.createElement('button');
                        item.type = 'button';
                        item.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center';

                        const left = document.createElement('span');
                        const code = document.createElement('strong');
                        code.textContent = member.member_code;
                        const name = document.createElement('span');
                        name.className = 'ms-2';
                        name.textContent = member.name;
                        const mobile = document.createElement('div');
                        mobile.className = 'small text-muted';
                        mobile.textContent = member.mobile;
                        left.append(code, name, mobile);

                        const badge = document.createElement('span');
                        badge.className = `badge ${member.status === 'active' ? 'text-bg-success' : 'text-bg-secondary'}`;
                        badge.textContent = member.status_label;

                        item.append(left, badge);
                        item.addEventListener('click', () => choose(member));
                        results.appendChild(item);
                    });
                }

                results.classList.remove('d-none');
            } catch (error) {
                if (error.name === 'AbortError') return;
                window.App.notify(error.message, 'danger');
            }
        }, DEBOUNCE_MS);
    });

    searchInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') event.preventDefault();
        if (event.key === 'Escape') hide();
    });

    document.addEventListener('click', (event) => {
        if (!root.contains(event.target)) hide();
    });
}

function initProjectPropertyLink(projectSelect) {
    const propertySelect = document.querySelector('[data-property-select]');
    if (!propertySelect) return;

    const url = projectSelect.dataset.propertiesUrl;
    const preselected = propertySelect.dataset.selected;

    const setPlaceholder = (text) => {
        propertySelect.innerHTML = '';
        const option = document.createElement('option');
        option.value = '';
        option.textContent = text;
        propertySelect.appendChild(option);
    };

    const load = async (projectId, keepSelection = null) => {
        // Project and property are optional, so "none" is always a valid choice.
        if (!projectId) {
            setPlaceholder('— None —');
            return;
        }

        window.App.setLoading(propertySelect.parentElement, true);
        setPlaceholder('Loading…');

        try {
            const { data } = await window.App.request(`${url}?project_id=${encodeURIComponent(projectId)}`);

            if (!data || data.length === 0) {
                setPlaceholder('No active properties in this project');
                return;
            }

            setPlaceholder('— None —');

            data.forEach((property) => {
                const option = document.createElement('option');
                option.value = property.id;
                option.textContent = property.details
                    ? `${property.property_code} — ${property.details}`
                    : property.property_code;
                if (keepSelection && String(keepSelection) === String(property.id)) {
                    option.selected = true;
                }
                propertySelect.appendChild(option);
            });
        } catch (error) {
            window.App.notify(error.message, 'danger');
            setPlaceholder('Could not load properties');
        } finally {
            window.App.setLoading(propertySelect.parentElement, false);
        }
    };

    projectSelect.addEventListener('change', (event) => load(event.target.value));

    // Repopulate after a validation failure returns the user to the form.
    if (projectSelect.value) load(projectSelect.value, preselected);
}

/**
 * Numeric-only input.
 *
 * Blocks anything that is not a number as it is typed, so a Sq.Ft. field can
 * never hold letters or stray symbols. Server-side validation still enforces the
 * same rules — this only stops the operator making the mistake in the first place.
 */
function initNumericInput(input) {
    const decimals = Number(input.dataset.decimals ?? 2);

    const clean = (raw) => {
        // Digits and a single decimal point only.
        let value = raw.replace(/[^\d.]/g, '');

        const firstDot = value.indexOf('.');
        if (firstDot !== -1) {
            value =
                value.slice(0, firstDot + 1) +
                value.slice(firstDot + 1).replace(/\./g, '');
        }

        if (decimals >= 0 && firstDot !== -1) {
            value = value.slice(0, firstDot + 1 + decimals);
        }

        return value;
    };

    input.addEventListener('input', () => {
        const start = input.selectionStart;
        const before = input.value;
        const after = clean(before);

        if (before !== after) {
            input.value = after;
            // Keep the caret where the operator expects it.
            const delta = before.length - after.length;
            input.setSelectionRange(Math.max(0, start - delta), Math.max(0, start - delta));
        }

        input.dispatchEvent(new CustomEvent('numeric:change', { bubbles: true }));
    });

    // Normalise a trailing/leading dot on blur: "1500." -> "1500", "." -> "".
    input.addEventListener('blur', () => {
        if (input.value === '' || input.value === '.') {
            input.value = '';
        } else if (input.value.endsWith('.')) {
            input.value = input.value.slice(0, -1);
        } else if (input.value.startsWith('.')) {
            input.value = `0${input.value}`;
        }

        input.dispatchEvent(new CustomEvent('numeric:change', { bubbles: true }));
    });

    // Block the keys that would otherwise slip past the input handler.
    input.addEventListener('keypress', (event) => {
        if (!/[\d.]/.test(event.key)) event.preventDefault();
    });
}

/**
 * Live direct-sale amount: Sq.Ft. × the confirmed ₹40 rate.
 *
 * Display only. The authoritative figure is always the one the server computes
 * with exact decimal arithmetic — Blade and JS are never the financial source of
 * truth (docs/03_DATABASE_AND_ARCHITECTURE.md).
 */
function initDirectAmountPreview(input) {
    const box = document.querySelector('[data-direct-amount-box]');
    const amountEl = document.querySelector('[data-direct-amount]');
    const formulaEl = document.querySelector('[data-direct-formula]');
    if (!box || !amountEl || !formulaEl) return;

    const rate = Number(input.dataset.rate || 0);

    const inr = new Intl.NumberFormat('en-IN', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

    const update = () => {
        const sqft = parseFloat(input.value);

        if (!Number.isFinite(sqft) || sqft <= 0) {
            box.classList.remove('is-active');
            amountEl.textContent = '₹0.00';
            formulaEl.textContent = 'Enter Sq.Ft. to see the amount';
            return;
        }

        box.classList.add('is-active');
        amountEl.textContent = `₹${inr.format(sqft * rate)}`;
        formulaEl.textContent = `${inr.format(sqft)} Sq.Ft. × ₹${rate} — confirmed on save`;
    };

    input.addEventListener('numeric:change', update);
    input.addEventListener('input', update);
    update();
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-member-picker]').forEach(initMemberPicker);
    document.querySelectorAll('[data-project-select]').forEach(initProjectPropertyLink);
    document.querySelectorAll('[data-numeric]').forEach(initNumericInput);
    document.querySelectorAll('[data-sqft-input]').forEach(initDirectAmountPreview);

    // Clearing the form must also clear the selected member and the amount.
    document.querySelectorAll('[data-sale-reset]').forEach((button) => {
        button.addEventListener('click', () => {
            setTimeout(() => {
                const hidden = document.getElementById('member_id');
                if (hidden) hidden.value = '';
                document.querySelector('[data-member-selected]')?.classList.add('d-none');
                document.querySelector('[data-sqft-input]')?.dispatchEvent(new Event('input'));
                document.getElementById('member-search')?.focus();
            }, 0);
        });
    });

    // A sale is irreversible once saved — make the operator acknowledge it.
    document.querySelectorAll('[data-confirm-submit]').forEach((button) => {
        button.addEventListener('click', (event) => {
            if (!window.confirm(button.dataset.confirmSubmit)) {
                event.preventDefault();
            }
        });
    });
});

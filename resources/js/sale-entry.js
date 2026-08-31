/**
 * Daily sale entry helpers.
 *
 *  - member lookup over AJAX (the network can be large; never a <select>)
 *  - block-name suggestions drawn from what the chosen project already has
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
        document.getElementById('sqft')?.focus();
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

/**
 * Block-name suggestions for the chosen project.
 *
 * A plain text input with a suggestion list, NOT a <select>: a project's first
 * sale in a newly laid-out block has to be typeable, so the list may only ever
 * offer, never restrict. The server takes the same view — see
 * BlockSearchController.
 *
 * Suggestions are scoped to the selected project, because a block name only
 * means something inside one. With no project chosen the field still accepts
 * typing; it simply has nothing to suggest.
 */
function initBlockPicker(root) {
    const input = root.querySelector('[data-block-input]');
    const results = root.querySelector('[data-block-results]');
    const hint = document.querySelector('[data-block-hint]');
    const projectSelect = document.querySelector('[data-project-select]');

    if (!input || !results) return;

    const searchUrl = root.dataset.searchUrl;
    let debounce = null;
    let inFlight = null;

    const hide = () => {
        results.classList.add('d-none');
        results.innerHTML = '';
    };

    const render = (blocks) => {
        results.innerHTML = '';

        if (!blocks || blocks.length === 0) {
            hide();
            return;
        }

        blocks.forEach((block) => {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'list-group-item list-group-item-action py-1 small';
            item.textContent = block;
            item.addEventListener('click', () => {
                input.value = block;
                hide();
                document.getElementById('plot_number')?.focus();
            });
            results.appendChild(item);
        });

        results.classList.remove('d-none');
    };

    const search = () => {
        const projectId = projectSelect?.value;

        if (!projectId) {
            hide();
            return;
        }

        clearTimeout(debounce);

        debounce = setTimeout(async () => {
            inFlight?.abort();
            inFlight = new AbortController();

            try {
                const { data } = await window.App.request(
                    `${searchUrl}?project_id=${encodeURIComponent(projectId)}`
                        + `&q=${encodeURIComponent(input.value.trim())}`,
                    { signal: inFlight.signal }
                );
                render(data);
            } catch (error) {
                // A failed suggestion lookup must never interrupt sale entry:
                // the field is free text and stays perfectly usable without it.
                if (error.name !== 'AbortError') hide();
            }
        }, DEBOUNCE_MS);
    };

    // Opening the list on focus is what makes this discoverable — an operator
    // who does not know a project's blocks has nothing to type to find out.
    input.addEventListener('focus', search);
    input.addEventListener('input', search);

    input.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') hide();
    });

    projectSelect?.addEventListener('change', () => {
        hide();

        if (hint) {
            hint.textContent = projectSelect.value
                ? 'Start typing, or click the field to see blocks already recorded here.'
                : 'Pick a project to see the blocks already recorded in it.';
        }
    });

    document.addEventListener('click', (event) => {
        if (!root.contains(event.target)) hide();
    });

    // Reflect a project that survived a validation round-trip.
    if (projectSelect?.value && hint) {
        hint.textContent = 'Start typing, or click the field to see blocks already recorded here.';
    }
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
    document.querySelectorAll('[data-block-picker]').forEach(initBlockPicker);
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

    // A sale is irreversible once saved, and so is confirming a payment — make
    // the operator acknowledge it.
    //
    // This one handler covers every [data-confirm-submit] button in the back
    // office: sale entry, Mark Paid on the target and Company Club screens, and
    // the recalculation controls. The dialog is asynchronous, so the click is
    // always cancelled first and the form submitted afterwards if confirmed.
    document.querySelectorAll('[data-confirm-submit]').forEach((button) => {
        button.addEventListener('click', async (event) => {
            if (button.dataset.confirmed === 'yes') {
                return;
            }

            event.preventDefault();

            if (await window.App.confirm(window.App.confirmOptionsFrom(button))) {
                button.dataset.confirmed = 'yes';

                // requestSubmit keeps the button's own name/value in the payload
                // and still runs native validation, which button.form.submit()
                // would skip.
                if (button.form?.requestSubmit) {
                    button.form.requestSubmit(button);
                } else {
                    button.form?.submit();
                }
            }
        });
    });
});

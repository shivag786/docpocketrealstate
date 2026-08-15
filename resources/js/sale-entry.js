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
        if (!projectId) {
            setPlaceholder('Select a project first');
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

            setPlaceholder('Select a property / site');

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

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-member-picker]').forEach(initMemberPicker);
    document.querySelectorAll('[data-project-select]').forEach(initProjectPropertyLink);

    // A sale is irreversible once saved — make the operator acknowledge it.
    document.querySelectorAll('[data-confirm-submit]').forEach((button) => {
        button.addEventListener('click', (event) => {
            if (!window.confirm(button.dataset.confirmSubmit)) {
                event.preventDefault();
            }
        });
    });
});

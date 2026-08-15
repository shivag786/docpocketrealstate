/**
 * Sponsor picker.
 *
 * Searches members over AJAX and never renders the full network into a select
 * element (docs/04_UI_UX_SPECIFICATION.md). Uses the shared App.request helper,
 * so the response envelope, CSRF and error handling are already solved.
 *
 * Server-side validation still enforces every rule; this only keeps invalid
 * options off the screen.
 */

const MIN_QUERY_LENGTH = 2;
const DEBOUNCE_MS = 300;

function initSponsorPicker(root) {
    if (root.dataset.locked === '1') return;

    const searchInput = root.querySelector('[data-sponsor-search]');
    const resultsBox = root.querySelector('[data-sponsor-results]');
    const selectedBox = root.querySelector('[data-sponsor-selected]');
    const emptyBox = root.querySelector('[data-sponsor-empty]');
    const clearButton = root.querySelector('[data-sponsor-clear]');
    const hiddenInput = document.getElementById('sponsor_id');

    if (!searchInput || !resultsBox || !hiddenInput) return;

    const searchUrl = root.dataset.searchUrl;
    const exclude = root.dataset.exclude || '';

    let debounceTimer = null;
    let inFlight = null;

    const hideResults = () => {
        resultsBox.classList.add('d-none');
        resultsBox.innerHTML = '';
    };

    const select = ({ id, name, member_code: code }) => {
        hiddenInput.value = id;
        selectedBox.querySelector('[data-sponsor-name]').textContent = name;
        selectedBox.querySelector('[data-sponsor-code]').textContent = code;
        selectedBox.classList.remove('d-none');
        emptyBox?.classList.add('d-none');
        searchInput.value = '';
        hideResults();
    };

    const clear = () => {
        hiddenInput.value = '';
        selectedBox.classList.add('d-none');
        emptyBox?.classList.remove('d-none');
        hideResults();
    };

    const render = (members) => {
        resultsBox.innerHTML = '';

        if (members.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'list-group-item text-muted small';
            empty.textContent = 'No members found.';
            resultsBox.appendChild(empty);
            resultsBox.classList.remove('d-none');
            return;
        }

        members.forEach((member) => {
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
            item.addEventListener('click', () => select(member));
            resultsBox.appendChild(item);
        });

        resultsBox.classList.remove('d-none');
    };

    const search = async (term) => {
        // Abort a superseded lookup so slow responses cannot overwrite newer ones.
        inFlight?.abort();
        inFlight = new AbortController();

        const params = new URLSearchParams({ q: term });
        if (exclude) params.set('exclude', exclude);

        window.App.setLoading(resultsBox, true);

        try {
            const { data } = await window.App.request(`${searchUrl}?${params}`, {
                signal: inFlight.signal,
            });
            render(data ?? []);
        } catch (error) {
            if (error.name === 'AbortError') return;
            window.App.notify(error.message, 'danger');
            hideResults();
        } finally {
            window.App.setLoading(resultsBox, false);
        }
    };

    searchInput.addEventListener('input', () => {
        const term = searchInput.value.trim();

        clearTimeout(debounceTimer);

        if (term.length < MIN_QUERY_LENGTH) {
            hideResults();
            return;
        }

        debounceTimer = setTimeout(() => search(term), DEBOUNCE_MS);
    });

    clearButton?.addEventListener('click', clear);

    // Enter in the search field must not submit the whole member form.
    searchInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') event.preventDefault();
        if (event.key === 'Escape') hideResults();
    });

    document.addEventListener('click', (event) => {
        if (!root.contains(event.target)) hideResults();
    });
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-sponsor-picker]').forEach(initSponsorPicker);
});

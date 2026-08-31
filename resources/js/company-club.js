/**
 * Company Club — network tree and calculation preview.
 *
 * Inert unless its markup is on the page, like every other feature module.
 *
 * TWO RULES SHAPE THIS FILE:
 *
 *  1. The tree loads ONE LEVEL AT A TIME. The Club itself is drawn in the Blade
 *     template because it is a system entity with no member row; everything
 *     below it arrives from the server on expansion. Nothing here can pull the
 *     whole network into the page.
 *
 *  2. Preview NEVER writes. It calls a GET endpoint and paints numbers. The
 *     only thing that creates a financial row is submitting the Calculate form,
 *     which is a normal POST with a confirmation dialog.
 */

// ---------------------------------------------------------------- network tree

function initCompanyClubTree(root) {
    const childrenUrl = root.dataset.childrenUrl;
    const explainUrlTemplate = root.dataset.explainUrl;
    const loading = root.querySelector('[data-cc-tree-loading]');

    // Nodes whose children have already been fetched, so collapsing and
    // re-expanding a branch costs nothing.
    const loaded = new Set();

    const buildNode = (node) => {
        const wrapper = document.createElement('div');
        wrapper.className = 'tree-node';
        wrapper.dataset.nodeId = node.id;

        const card = document.createElement('div');
        card.className = 'tree-card';

        // Expand control, only where there is something to expand.
        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'tree-toggle';
        toggle.setAttribute('aria-expanded', 'false');

        if (node.children > 0) {
            toggle.innerHTML = '<i class="bi bi-plus"></i>';
            toggle.addEventListener('click', () => toggleNode(node, wrapper, toggle));
        } else {
            toggle.innerHTML = '<i class="bi bi-dot"></i>';
            toggle.disabled = true;
            toggle.setAttribute('aria-label', 'No downline');
        }

        const identity = document.createElement('div');
        identity.className = 'tree-identity';

        const code = document.createElement('span');
        code.className = 'badge text-bg-light border';
        code.textContent = node.member_code;

        const name = document.createElement('span');
        name.className = 'fw-semibold';
        name.textContent = node.name;

        const status = document.createElement('span');
        status.className = `badge ${node.active ? 'text-bg-success' : 'text-bg-secondary'}`;
        status.textContent = node.active ? 'Active' : 'Inactive';
        // The one thing an operator needs to know here: an inactive member is
        // skipped when the reward walks upward, and does not use up a level.
        status.title = node.active
            ? 'Active — counts as a sponsor level and can receive a Company Club reward.'
            : 'Inactive — skipped when walking upward, and does not use up a level.';

        identity.append(code, name, status);

        const stats = document.createElement('div');
        stats.className = 'tree-stats small text-muted';
        stats.textContent = node.children > 0
            ? `${node.children} direct`
            : 'no downline';

        const actions = document.createElement('div');
        actions.className = 'tree-actions';

        if (explainUrlTemplate) {
            const explain = document.createElement('a');
            explain.className = 'btn btn-sm btn-outline-secondary';
            explain.href = explainUrlTemplate.replace('__ID__', node.id);
            explain.textContent = 'Rewards';
            actions.appendChild(explain);
        }

        card.append(toggle, identity, stats, actions);
        wrapper.appendChild(card);

        const children = document.createElement('div');
        children.className = 'tree-children d-none';
        children.dataset.childrenOf = node.id;
        wrapper.appendChild(children);

        return wrapper;
    };

    const toggleNode = async (node, wrapper, toggle) => {
        const children = wrapper.querySelector(`[data-children-of="${node.id}"]`);
        if (!children) return;

        const isOpen = !children.classList.contains('d-none');

        if (isOpen) {
            children.classList.add('d-none');
            toggle.innerHTML = '<i class="bi bi-plus"></i>';
            toggle.setAttribute('aria-expanded', 'false');
            return;
        }

        if (!loaded.has(node.id)) {
            toggle.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

            try {
                const payload = await window.App.request(
                    `${childrenUrl}?member_id=${encodeURIComponent(node.id)}`,
                );

                payload.data.nodes.forEach((child) => children.appendChild(buildNode(child)));
                loaded.add(node.id);
            } catch (error) {
                window.App.notify(error.message, 'danger');
                toggle.innerHTML = '<i class="bi bi-plus"></i>';
                return;
            }
        }

        children.classList.remove('d-none');
        toggle.innerHTML = '<i class="bi bi-dash"></i>';
        toggle.setAttribute('aria-expanded', 'true');
    };

    // First paint: the members sitting directly under the Club.
    (async () => {
        try {
            const payload = await window.App.request(childrenUrl);

            loading?.remove();

            if (payload.data.nodes.length === 0) {
                root.innerHTML =
                    '<p class="text-muted small mb-0">No members sit directly under the Club yet.</p>';
                return;
            }

            payload.data.nodes.forEach((node) => root.appendChild(buildNode(node)));
        } catch (error) {
            if (loading) {
                loading.className = 'text-danger small';
                loading.textContent = error.message;
            }
        }
    })();
}

// ------------------------------------------------------------------- preview

function initCompanyClubPreview() {
    const button = document.querySelector('[data-cc-preview]');
    const periodInput = document.querySelector('[data-cc-period]');

    if (!button || !periodInput) return;

    // Changing the month reloads the page rather than only repainting numbers,
    // because the commit buttons below depend on whether that month has been
    // calculated — leaving them describing the previous month would be a way to
    // press the wrong one.
    button.addEventListener('click', () => {
        const url = new URL(window.location.href);
        url.searchParams.set('period', periodInput.value);
        window.location.href = url.toString();
    });

    periodInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            button.click();
        }
    });
}

// ------------------------------------------------- income tree "load more"

/**
 * Deeper branches of the income tree, fetched on demand.
 *
 * The markup is recursive, so the server renders it and this only splices the
 * HTML in. Duplicating the node template in JavaScript would mean two versions
 * of it to keep identical by hand.
 *
 * Bound by delegation, because the buttons inside a freshly loaded branch have
 * to work exactly like the ones that were on the page to begin with.
 */
function initIncomeTree(root) {
    const branchUrl = root.dataset.branchUrl;

    root.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-cc-branch]');
        if (!button || !root.contains(button)) return;

        const memberId = button.dataset.memberId;
        const period = button.dataset.period;
        const container = root.querySelector(`[data-children-of="${memberId}"]`);

        if (!container) return;

        // Already loaded: this is now just a collapse toggle.
        if (button.dataset.loaded === 'true') {
            const hidden = container.classList.toggle('d-none');
            button.innerHTML = hidden ? '<i class="bi bi-plus"></i>' : '<i class="bi bi-dash"></i>';
            return;
        }

        button.disabled = true;
        button.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

        try {
            const query = new URLSearchParams({ member_id: memberId, period });
            const payload = await window.App.request(`${branchUrl}?${query}`);

            container.insertAdjacentHTML('beforeend', payload.data.html);
            button.dataset.loaded = 'true';
            button.innerHTML = '<i class="bi bi-dash"></i>';
        } catch (error) {
            window.App.notify(error.message, 'danger');
            button.innerHTML = '<i class="bi bi-plus"></i>';
        } finally {
            button.disabled = false;
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-cc-tree]').forEach(initCompanyClubTree);
    document.querySelectorAll('[data-cc-income-tree]').forEach(initIncomeTree);
    initCompanyClubPreview();
});

/**
 * Sponsor tree.
 *
 * Loads one level at a time. On first paint only the roots are fetched; every
 * expansion requests exactly that node's direct referrals. Nothing here can pull
 * the whole network into the page (docs/04_UI_UX_SPECIFICATION.md).
 *
 * Children are cached per node, so collapsing and re-expanding costs nothing.
 */

const MIN_QUERY_LENGTH = 2;
const DEBOUNCE_MS = 300;

function initMemberTree(root) {
    const container = root.querySelector('[data-tree-container]');
    const loading = root.querySelector('[data-tree-loading]');
    if (!container) return;

    const urls = {
        children: root.dataset.childrenUrl,
        search: root.dataset.searchUrl,
        focus: root.dataset.focusUrl,
        downline: root.dataset.downlineUrl,
    };

    // Nodes whose children have already been fetched.
    const loaded = new Set();
    let levelFilter = null;

    // ---------------------------------------------------------------- rendering

    const statusBadge = (node) => {
        const badge = document.createElement('span');
        badge.className = `badge ${node.status === 'active' ? 'text-bg-success' : 'text-bg-secondary'}`;
        badge.textContent = node.status_label;
        return badge;
    };

    const buildCard = (node) => {
        const wrapper = document.createElement('div');
        wrapper.className = 'tree-node';
        wrapper.dataset.nodeId = node.id;
        wrapper.dataset.level = node.level;

        const card = document.createElement('div');
        card.className = 'tree-card';

        // Expand / collapse
        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'tree-toggle btn btn-sm';
        toggle.setAttribute('aria-expanded', 'false');

        if (node.has_children) {
            toggle.innerHTML = '<i class="bi bi-plus-square"></i>';
            toggle.title = 'Expand direct team';
            toggle.addEventListener('click', () => toggleNode(wrapper, node, toggle));
        } else {
            toggle.innerHTML = '<i class="bi bi-dot"></i>';
            toggle.classList.add('invisible');
            toggle.disabled = true;
        }

        // Identity
        const identity = document.createElement('div');
        identity.className = 'tree-identity';

        const code = document.createElement('a');
        code.href = node.url;
        code.className = 'fw-semibold text-decoration-none';
        code.textContent = node.member_code;

        const name = document.createElement('span');
        name.className = 'ms-2';
        name.textContent = node.name;

        const meta = document.createElement('div');
        meta.className = 'small text-muted';
        meta.textContent = node.mobile;

        identity.append(code, name, meta);

        // Branch summary
        const stats = document.createElement('div');
        stats.className = 'tree-stats d-flex align-items-center gap-2 flex-wrap';

        const level = document.createElement('span');
        level.className = 'badge text-bg-light border';
        level.textContent = `L${node.level}`;
        level.title = 'Level in the network';

        const direct = document.createElement('span');
        direct.className = 'badge text-bg-primary';
        direct.innerHTML = `<i class="bi bi-people me-1"></i>${node.direct_count}`;
        direct.title = 'Direct referrals';

        const team = document.createElement('span');
        team.className = 'badge text-bg-dark';
        team.innerHTML = `<i class="bi bi-diagram-2 me-1"></i>${node.team_total}`;
        team.title = `Total team: ${node.team_total} (${node.team_active} active)`;

        stats.append(level, direct, team, statusBadge(node));

        // Actions
        const actions = document.createElement('div');
        actions.className = 'tree-actions btn-group btn-group-sm';

        const focusBtn = document.createElement('button');
        focusBtn.type = 'button';
        focusBtn.className = 'btn btn-outline-secondary';
        focusBtn.innerHTML = '<i class="bi bi-crosshair"></i>';
        focusBtn.title = 'Focus on this member';
        focusBtn.addEventListener('click', () => focusOn(node));

        const profileBtn = document.createElement('a');
        profileBtn.href = node.url;
        profileBtn.className = 'btn btn-outline-secondary';
        profileBtn.innerHTML = '<i class="bi bi-person-lines-fill"></i>';
        profileBtn.title = 'Member profile';

        actions.append(focusBtn, profileBtn);

        if (node.team_total > 0) {
            const downlineBtn = document.createElement('a');
            downlineBtn.href = `${urls.downline}/${node.id}`;
            downlineBtn.className = 'btn btn-outline-secondary';
            downlineBtn.innerHTML = '<i class="bi bi-list-nested"></i>';
            downlineBtn.title = 'View full downline';
            actions.append(downlineBtn);
        }

        card.append(toggle, identity, stats, actions);

        const childrenBox = document.createElement('div');
        childrenBox.className = 'tree-children d-none';

        wrapper.append(card, childrenBox);
        return wrapper;
    };

    const renderInto = (target, nodes) => {
        target.innerHTML = '';

        if (nodes.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'text-muted small ps-4 py-2';
            empty.textContent = 'No direct referrals.';
            target.appendChild(empty);
            return;
        }

        nodes.forEach((node) => target.appendChild(buildCard(node)));
        applyLevelFilter();
    };

    // ---------------------------------------------------------------- behaviour

    const fetchChildren = async (memberId, level) => {
        const params = new URLSearchParams();
        if (memberId) params.set('member_id', memberId);
        if (level !== undefined && level !== null) params.set('level', level);

        const { data } = await window.App.request(`${urls.children}?${params}`);
        return data.nodes ?? [];
    };

    async function toggleNode(wrapper, node, toggle) {
        const childrenBox = wrapper.querySelector(':scope > .tree-children');
        const isOpen = !childrenBox.classList.contains('d-none');

        if (isOpen) {
            childrenBox.classList.add('d-none');
            toggle.innerHTML = '<i class="bi bi-plus-square"></i>';
            toggle.setAttribute('aria-expanded', 'false');
            return;
        }

        if (!loaded.has(node.id)) {
            // Busy state is scoped to this node — the page never freezes.
            window.App.setLoading(wrapper, true);
            toggle.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

            try {
                const children = await fetchChildren(node.id, node.level);
                renderInto(childrenBox, children);
                loaded.add(node.id);
            } catch (error) {
                window.App.notify(error.message, 'danger');
                toggle.innerHTML = '<i class="bi bi-plus-square"></i>';
                return;
            } finally {
                window.App.setLoading(wrapper, false);
            }
        }

        childrenBox.classList.remove('d-none');
        toggle.innerHTML = '<i class="bi bi-dash-square"></i>';
        toggle.setAttribute('aria-expanded', 'true');
    }

    /** Re-root the tree at one member. */
    async function focusOn(node) {
        const banner = root.parentElement.querySelector('[data-tree-focus-banner]')
            ?? document.querySelector('[data-tree-focus-banner]');

        loaded.clear();
        container.innerHTML = '';
        loading?.classList.remove('d-none');

        try {
            const { data } = await window.App.request(`${urls.focus}/${node.id}`);
            const member = data.member;

            renderInto(container, [member]);

            if (banner) {
                banner.classList.remove('d-none');
                banner.classList.add('d-flex');
                banner.querySelector('[data-tree-focus-name]').textContent = member.name;
                banner.querySelector('[data-tree-focus-code]').textContent = member.member_code;

                const sponsorLink = banner.querySelector('[data-tree-focus-sponsor]');
                const sponsorId = data.path.at(-1);

                if (sponsorId) {
                    sponsorLink.classList.remove('d-none');
                    sponsorLink.href = `?member=${sponsorId}`;
                } else {
                    sponsorLink.classList.add('d-none');
                }
            }

            document.querySelector('[data-tree-reset]')?.classList.remove('d-none');

            // Open the focused member straight away.
            const card = container.querySelector('.tree-node');
            const toggle = card?.querySelector('.tree-toggle');
            if (member.has_children && toggle) toggleNode(card, member, toggle);
        } catch (error) {
            window.App.notify(error.message, 'danger');
        } finally {
            loading?.classList.add('d-none');
        }
    }

    async function loadRoots() {
        loaded.clear();
        container.innerHTML = '';
        loading?.classList.remove('d-none');

        try {
            renderInto(container, await fetchChildren(null, 0));
        } catch (error) {
            window.App.notify(error.message, 'danger');
        } finally {
            loading?.classList.add('d-none');
        }
    }

    /** Hide nodes above the chosen level without refetching anything. */
    function applyLevelFilter() {
        container.querySelectorAll('.tree-node').forEach((node) => {
            const level = Number(node.dataset.level);
            const hide = levelFilter !== null && level > levelFilter;
            node.classList.toggle('d-none', hide);
        });
    }

    // ---------------------------------------------------------------- controls

    document.querySelector('[data-tree-collapse]')?.addEventListener('click', () => {
        container.querySelectorAll('.tree-children').forEach((box) => box.classList.add('d-none'));
        container.querySelectorAll('.tree-toggle').forEach((toggle) => {
            if (toggle.disabled) return;
            toggle.innerHTML = '<i class="bi bi-plus-square"></i>';
            toggle.setAttribute('aria-expanded', 'false');
        });
    });

    // Expands only what has already been fetched — it never triggers a
    // network-wide load.
    document.querySelector('[data-tree-expand]')?.addEventListener('click', () => {
        container.querySelectorAll('.tree-children').forEach((box) => {
            if (box.childElementCount > 0) box.classList.remove('d-none');
        });
        container.querySelectorAll('.tree-toggle').forEach((toggle) => {
            const box = toggle.closest('.tree-node')?.querySelector(':scope > .tree-children');
            if (box?.childElementCount > 0) {
                toggle.innerHTML = '<i class="bi bi-dash-square"></i>';
                toggle.setAttribute('aria-expanded', 'true');
            }
        });
    });

    document.querySelector('[data-tree-reset]')?.addEventListener('click', (event) => {
        event.currentTarget.classList.add('d-none');
        document.querySelector('[data-tree-focus-banner]')?.classList.add('d-none');
        loadRoots();
    });

    document.querySelector('[data-tree-level]')?.addEventListener('change', (event) => {
        levelFilter = event.target.value === '' ? null : Number(event.target.value);
        applyLevelFilter();
    });

    // Search
    const searchInput = document.querySelector('[data-tree-search]');
    const searchResults = document.querySelector('[data-tree-search-results]');
    let debounce = null;
    let inFlight = null;

    searchInput?.addEventListener('input', () => {
        const term = searchInput.value.trim();
        clearTimeout(debounce);

        if (term.length < MIN_QUERY_LENGTH) {
            searchResults.classList.add('d-none');
            return;
        }

        debounce = setTimeout(async () => {
            inFlight?.abort();
            inFlight = new AbortController();

            try {
                const { data } = await window.App.request(
                    `${urls.search}?q=${encodeURIComponent(term)}`,
                    { signal: inFlight.signal }
                );

                searchResults.innerHTML = '';

                if (!data || data.length === 0) {
                    const empty = document.createElement('div');
                    empty.className = 'list-group-item small text-muted';
                    empty.textContent = 'No members found.';
                    searchResults.appendChild(empty);
                } else {
                    data.forEach((member) => {
                        const item = document.createElement('button');
                        item.type = 'button';
                        item.className = 'list-group-item list-group-item-action';
                        item.innerHTML = `
                            <div class="d-flex justify-content-between align-items-center">
                                <span><strong></strong><span class="ms-2"></span></span>
                                <span class="badge text-bg-light border">L${member.level}</span>
                            </div>
                            <div class="small text-muted"></div>`;
                        item.querySelector('strong').textContent = member.member_code;
                        item.querySelector('span span').textContent = member.name;
                        item.querySelector('.small').textContent = member.sponsor
                            ? `Sponsor: ${member.sponsor.member_code} — ${member.sponsor.name}`
                            : 'Root member';

                        item.addEventListener('click', () => {
                            searchResults.classList.add('d-none');
                            searchInput.value = '';
                            focusOn({ id: member.id });
                        });

                        searchResults.appendChild(item);
                    });
                }

                searchResults.classList.remove('d-none');
            } catch (error) {
                if (error.name === 'AbortError') return;
                window.App.notify(error.message, 'danger');
            }
        }, DEBOUNCE_MS);
    });

    document.addEventListener('click', (event) => {
        if (!searchResults?.contains(event.target) && event.target !== searchInput) {
            searchResults?.classList.add('d-none');
        }
    });

    // ---------------------------------------------------------------- start
    if (root.dataset.initialFocus) {
        focusOn({ id: Number(root.dataset.initialFocus) });
    } else {
        loadRoots();
    }
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-member-tree]').forEach(initMemberTree);
});

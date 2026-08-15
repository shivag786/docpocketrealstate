<header class="app-topbar d-flex align-items-center gap-3 px-3">
    <button class="btn btn-sm btn-outline-secondary d-lg-none"
            type="button"
            data-app-sidebar-toggle
            aria-label="Toggle navigation">
        <i class="bi bi-list"></i>
    </button>

    {{--
        Global search (Member ID, name, mobile, sponsor ID, registry number,
        property code) is specified in docs/04_UI_UX_SPECIFICATION.md but has
        nothing to search until members and sales exist in Phases 2 and 4.
    --}}
    <div class="flex-grow-1 d-none d-md-block" style="max-width: 420px;">
        <div class="input-group input-group-sm">
            <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
            <input type="search"
                   class="form-control"
                   placeholder="Global search available from Phase 2"
                   aria-label="Global search"
                   disabled>
        </div>
    </div>

    <div class="ms-auto dropdown">
        <button class="btn btn-sm btn-light dropdown-toggle d-flex align-items-center gap-2"
                type="button"
                data-bs-toggle="dropdown"
                aria-expanded="false">
            <i class="bi bi-person-circle fs-5"></i>
            <span class="d-none d-sm-inline">{{ auth()->user()->name }}</span>
        </button>

        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
            <li class="px-3 py-2">
                <div class="fw-semibold">{{ auth()->user()->name }}</div>
                <div class="small text-muted">{{ auth()->user()->email }}</div>
                <span class="badge text-bg-primary mt-1">{{ auth()->user()->role->label() }}</span>
            </li>
            <li><hr class="dropdown-divider"></li>
            <li>
                <form method="POST" action="{{ route('logout') }}" data-confirm="Sign out of the back office?">
                    @csrf
                    <button type="submit" class="dropdown-item text-danger">
                        <i class="bi bi-box-arrow-right me-2"></i>Sign out
                    </button>
                </form>
            </li>
        </ul>
    </div>
</header>

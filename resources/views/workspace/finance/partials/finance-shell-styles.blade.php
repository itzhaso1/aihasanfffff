<style>
    .hs-fin-app {
        --hs-fin-teal: #06C2A4;
        --hs-fin-teal-dark: #049e86;
        --hs-fin-mint: #E7F8F4;
        --hs-fin-mint-soft: #F7FCFB;
        --hs-fin-navy: #152033;
        --hs-fin-muted: #6B7A8D;
        --hs-fin-line: #EEF3F6;
        --hs-fin-sidebar: 15.5rem;
        background: var(--hs-fin-mint-soft);
        color: var(--hs-fin-navy);
        overflow-x: hidden;
    }

    .hs-fin-app a { text-decoration: none; }

    .hs-fin-sidebar,
    .hs-fin-drawer {
        width: var(--hs-fin-sidebar);
        background: #fff;
        border-left: 1px solid var(--hs-fin-line);
    }

    .hs-fin-sidebar {
        display: none;
        position: fixed;
        top: 0;
        bottom: 0;
        right: 0;
        left: auto;
        z-index: 40;
        flex-direction: column;
    }

    .hs-fin-shell { min-width: 0; }

    .hs-fin-topbar {
        position: sticky;
        top: 0;
        z-index: 30;
        display: flex;
        align-items: center;
        gap: 0.85rem;
        height: 4.5rem;
        padding: 0 1.5rem;
        background: #fff;
        border-bottom: 1px solid var(--hs-fin-line);
    }

    .hs-fin-icon-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 2.4rem;
        height: 2.4rem;
        border: 0;
        border-radius: 999px;
        background: transparent;
        color: #64748b;
    }

    .hs-fin-icon-btn:hover,
    .hs-fin-icon-btn:focus-visible {
        background: var(--hs-fin-mint);
        color: var(--hs-fin-teal-dark);
        outline: none;
    }

    .hs-fin-search {
        flex: 1;
        min-width: 0;
        max-width: 34rem;
        margin-inline: auto;
        position: relative;
    }

    .hs-fin-search input {
        width: 100%;
        height: 2.65rem;
        border: 1px solid #edf2f1;
        border-radius: 999px;
        background: #f6faf9;
        padding: 0 2.6rem 0 1.15rem;
        font-size: 0.84rem;
        color: var(--hs-fin-navy);
        box-shadow: none;
    }

    .hs-fin-search input:focus {
        outline: none;
        border-color: #cdeae3;
        background: #fff;
        box-shadow: 0 0 0 4px rgba(6, 194, 164, 0.08);
    }

    .hs-fin-search-icon {
        position: absolute;
        top: 50%;
        inset-inline-end: 0.95rem;
        width: 1.05rem;
        height: 1.05rem;
        transform: translateY(-50%);
        color: #94a3b8;
        pointer-events: none;
    }

    .hs-fin-user {
        display: flex;
        align-items: center;
        gap: 0.7rem;
        min-width: 0;
    }

    .hs-fin-user-meta { min-width: 0; text-align: start; }

    .hs-fin-user-name {
        font-size: 0.84rem;
        font-weight: 800;
        color: var(--hs-fin-navy);
        line-height: 1.2;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 9.5rem;
    }

    .hs-fin-user-role {
        font-size: 0.7rem;
        color: var(--hs-fin-muted);
        white-space: nowrap;
    }

    .hs-fin-avatar {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 2.4rem;
        height: 2.4rem;
        border-radius: 999px;
        background: #0f172a;
        color: #fff;
        font-size: 0.82rem;
        font-weight: 800;
    }

    .hs-fin-back {
        font-size: 0.75rem;
        font-weight: 800;
        color: #64748b;
        white-space: nowrap;
    }

    .hs-fin-back:hover { color: var(--hs-fin-teal-dark); }

    .hs-fin-main {
        padding: 1.6rem 1.7rem 2.4rem;
        min-width: 0;
    }

    .hs-fin-nav { display: flex; flex-direction: column; height: 100%; min-height: 0; }

    .hs-fin-brand {
        display: flex;
        align-items: center;
        gap: 0.7rem;
        padding: 1.15rem 1rem 0.85rem;
        border-bottom: 1px solid var(--hs-fin-line);
    }

    .hs-fin-brand-mark {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 2.35rem;
        height: 2.35rem;
        flex: 0 0 auto;
        border-radius: 999px;
        background: var(--hs-fin-teal);
        color: #fff;
    }

    .hs-fin-brand-mark svg {
        width: 1.15rem;
        height: 1.15rem;
    }

    .hs-fin-brand-name {
        font-size: 0.94rem;
        font-weight: 800;
        color: var(--hs-fin-navy);
        line-height: 1.2;
    }

    .hs-fin-brand-sub {
        margin-top: 0.12rem;
        font-size: 0.72rem;
        font-weight: 600;
        color: var(--hs-fin-muted);
    }

    .hs-fin-scroll {
        flex: 1;
        overflow-y: auto;
        padding: 0.7rem 0.65rem 1.25rem;
    }

    .hs-fin-divider {
        height: 1px;
        margin: 0.55rem 0.5rem;
        background: var(--hs-fin-line);
    }

    .hs-fin-leaf,
    .hs-fin-parent,
    .hs-fin-child {
        display: flex;
        align-items: center;
        gap: 0.65rem;
        width: 100%;
        border: 0;
        border-radius: 0.65rem;
        background: transparent;
        text-align: start;
        text-decoration: none;
    }

    .hs-fin-leaf,
    .hs-fin-parent {
        min-height: 2.4rem;
        padding: 0.5rem 0.65rem;
        font-size: 0.82rem;
        font-weight: 700;
        color: #475569;
    }

    .hs-fin-leaf svg,
    .hs-fin-parent-icon,
    .hs-fin-child svg {
        width: 1.1rem;
        height: 1.1rem;
        flex: 0 0 auto;
        color: #93A0B0;
    }

    .hs-fin-leaf:hover,
    .hs-fin-parent:hover,
    .hs-fin-child:hover {
        background: #f6fbf9;
        color: var(--hs-fin-navy);
    }

    .hs-fin-leaf.is-active,
    .hs-fin-child.is-active {
        background: var(--hs-fin-mint);
        color: var(--hs-fin-teal-dark);
        font-weight: 800;
    }

    .hs-fin-leaf.is-active svg,
    .hs-fin-child.is-active svg,
    .hs-fin-parent.has-active .hs-fin-parent-icon {
        color: var(--hs-fin-teal-dark);
    }

    .hs-fin-group { margin-bottom: 0.15rem; }

    .hs-fin-chevron {
        width: 1.05rem;
        height: 1.05rem;
        margin-inline-start: auto;
        color: #93A0B0;
        transition: transform .16s ease;
    }

    .hs-fin-parent.is-open .hs-fin-chevron { transform: rotate(180deg); }

    .hs-fin-parent.has-active {
        background: var(--hs-fin-mint);
        color: var(--hs-fin-teal-dark);
    }

    .hs-fin-parent:focus-visible,
    .hs-fin-leaf:focus-visible,
    .hs-fin-child:focus-visible {
        outline: 2px solid #06C2A4;
        outline-offset: 2px;
    }

    .hs-fin-children {
        display: grid;
        gap: 0.1rem;
        padding: 0.05rem 0 0.25rem 0.85rem;
    }

    .hs-fin-child {
        min-height: 2.1rem;
        padding: 0.42rem 0.6rem;
        font-size: 0.78rem;
        font-weight: 600;
        color: #64748b;
    }

    .hs-fin-child svg {
        width: 1rem;
        height: 1rem;
    }

    .hs-fin-card {
        background: #fff;
        border: 1px solid #eef3f1;
        border-radius: 1.35rem;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.025);
        min-width: 0;
    }

    .hs-fin-card-pad { padding: 1.2rem 1.25rem 1.3rem; }

    .hs-fin-kpis {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 1rem;
    }

    .hs-fin-kpi {
        display: block;
        padding: 1.15rem 1.2rem 1.2rem;
        min-width: 0;
    }

    .hs-fin-kpi-top {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.6rem;
    }

    .hs-fin-kpi-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 2.15rem;
        height: 2.15rem;
        border-radius: 0.8rem;
        background: var(--hs-fin-mint);
        color: var(--hs-fin-teal-dark);
    }

    .hs-fin-kpi-icon.is-rose { background: #fff1f2; color: #e11d48; }
    .hs-fin-kpi-icon.is-amber { background: #fff7ed; color: #d97706; }
    .hs-fin-kpi-icon.is-navy { background: #eef4ff; color: #334155; }

    .hs-fin-kpi-label {
        font-size: 0.78rem;
        font-weight: 700;
        color: #64748b;
    }

    .hs-fin-kpi-value {
        margin-top: 0.85rem;
        font-size: 1.55rem;
        font-weight: 800;
        letter-spacing: -0.03em;
        color: var(--hs-fin-navy);
        line-height: 1.1;
        overflow-wrap: anywhere;
    }

    .hs-fin-delta {
        margin-top: 0.5rem;
        font-size: 0.72rem;
        font-weight: 700;
    }

    .hs-fin-delta.is-up { color: #059669; }
    .hs-fin-delta.is-down { color: #e11d48; }
    .hs-fin-delta.is-flat { color: #94a3b8; }

    .hs-fin-welcome h2 {
        font-size: 1.55rem;
        font-weight: 800;
        color: var(--hs-fin-navy);
        line-height: 1.35;
    }

    .hs-fin-welcome p {
        margin-top: 0.3rem;
        font-size: 0.9rem;
        color: #64748b;
    }

    .hs-fin-dates {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.4rem;
        min-height: 2.75rem;
        padding: 0.3rem 0.45rem 0.3rem 0.7rem;
        border: 1px solid #eef3f1;
        border-radius: 1rem;
        background: #fff;
        box-shadow: 0 6px 18px rgba(15, 23, 42, 0.03);
    }

    .hs-fin-dates input[type="date"] {
        border: 0;
        background: transparent;
        font-size: 0.78rem;
        font-weight: 700;
        color: #334155;
        padding: 0.35rem 0.4rem;
    }

    .hs-fin-dates button {
        border: 0;
        border-radius: 0.75rem;
        background: #f8fafc;
        color: #334155;
        font-size: 0.74rem;
        font-weight: 800;
        padding: 0.45rem 0.75rem;
    }

    .hs-fin-filter-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.55rem;
        margin-top: 0.75rem;
    }

    .hs-fin-filter-grid select,
    .hs-fin-filter-grid input {
        width: 100%;
        border: 1px solid var(--hs-fin-line);
        border-radius: 0.85rem;
        font-size: 0.8rem;
        padding: 0.55rem 0.7rem;
        background: #fff;
    }

    .hs-fin-analytics {
        display: grid;
        grid-template-columns: minmax(0, 1.65fr) minmax(16rem, 0.95fr);
        gap: 1rem;
    }

    .hs-fin-split {
        display: grid;
        grid-template-columns: minmax(0, 1.4fr) minmax(16rem, 1fr);
        gap: 1rem;
    }

    .hs-fin-section-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        margin-bottom: 1rem;
    }

    .hs-fin-section-head h3 {
        font-size: 0.95rem;
        font-weight: 800;
        color: var(--hs-fin-navy);
    }

    .hs-fin-more {
        font-size: 0.75rem;
        font-weight: 800;
        color: var(--hs-fin-teal-dark);
    }

    .hs-fin-table-wrap { overflow-x: auto; }

    .hs-fin-table {
        width: 100%;
        min-width: 32rem;
        border-collapse: collapse;
        font-size: 0.8rem;
    }

    .hs-fin-table th {
        padding: 0.65rem 0.55rem;
        text-align: start;
        font-size: 0.7rem;
        font-weight: 700;
        color: #94a3b8;
        border-bottom: 1px solid #f1f5f4;
    }

    .hs-fin-table td {
        padding: 0.85rem 0.55rem;
        border-bottom: 1px solid #f6f8f7;
        color: #334155;
        vertical-align: middle;
    }

    .hs-fin-table tr:last-child td { border-bottom: 0; }
    .hs-fin-table tbody tr:hover { background: #fbfefd; }

    .hs-fin-invoice-cards { display: none; }

    .hs-fin-actions {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 1rem;
    }

    .hs-fin-action {
        display: flex;
        align-items: center;
        gap: 0.85rem;
        padding: 1.05rem 1.15rem;
    }

    .hs-fin-action-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 2.5rem;
        height: 2.5rem;
        border-radius: 0.9rem;
        background: var(--hs-fin-mint);
        color: var(--hs-fin-teal-dark);
        flex: 0 0 auto;
    }

    .hs-fin-action strong {
        display: block;
        font-size: 0.9rem;
        font-weight: 800;
        color: var(--hs-fin-navy);
    }

    .hs-fin-action span {
        display: block;
        margin-top: 0.15rem;
        font-size: 0.74rem;
        color: #64748b;
        line-height: 1.5;
    }

    .hs-fin-legend {
        display: flex;
        flex-wrap: wrap;
        gap: 0.85rem;
        font-size: 0.74rem;
        font-weight: 700;
        color: #64748b;
    }

    .hs-fin-dot {
        display: inline-block;
        width: 0.5rem;
        height: 0.5rem;
        border-radius: 999px;
        margin-inline-end: 0.3rem;
    }

    .hs-fin-chart svg { width: 100%; height: auto; display: block; }

    .hs-fin-donut-wrap {
        display: grid;
        grid-template-columns: minmax(9rem, 11rem) minmax(0, 1fr);
        gap: 1rem;
        align-items: center;
    }

    .hs-fin-donut-list { display: grid; gap: 0.55rem; min-width: 0; }

    .hs-fin-donut-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
        font-size: 0.76rem;
    }

    .hs-fin-donut-row span { color: #64748b; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .hs-fin-donut-row b { color: var(--hs-fin-navy); font-weight: 800; white-space: nowrap; }

    .hs-fin-alert {
        border: 1px solid #fde68a;
        background: #fffbeb;
        border-radius: 1.15rem;
        padding: 0.95rem 1.05rem;
    }

    .hs-fin-extra {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.9rem;
    }

    .hs-fin-drawer-overlay { display: none; }

    @media (min-width: 1024px) {
        .hs-fin-sidebar { display: flex; }
        .hs-fin-shell { padding-right: var(--hs-fin-sidebar); padding-left: 0; }
        .hs-fin-menu-btn { display: none !important; }
        .hs-fin-drawer-overlay { display: none !important; }
        .hs-fin-filter-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    }

    @media (min-width: 1280px) {
        .hs-fin-main { padding: 1.75rem 2rem 2.6rem; }
        .hs-fin-filter-grid { grid-template-columns: repeat(5, minmax(0, 1fr)); }
    }

    @media (max-width: 1279px) {
        .hs-fin-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .hs-fin-analytics,
        .hs-fin-split { grid-template-columns: 1fr; }
    }

    @media (max-width: 1023px) {
        .hs-fin-drawer-overlay { display: block; }
        .hs-fin-user-meta { display: none; }
        .hs-fin-back { display: none; }
        .hs-fin-search { max-width: none; }
        .hs-fin-dates { border-radius: 1rem; }
        .hs-fin-actions { grid-template-columns: 1fr; }
        .hs-fin-extra { grid-template-columns: 1fr; }
        .hs-fin-donut-wrap { grid-template-columns: 1fr; justify-items: center; }
        .hs-fin-main { padding: 1.15rem 1rem 1.8rem; }
    }

    @media (max-width: 767px) {
        .hs-fin-kpis { grid-template-columns: 1fr; }
        .hs-fin-topbar { height: auto; min-height: 4rem; padding: 0.7rem 0.85rem; }
        .hs-fin-welcome h2 { font-size: 1.25rem; }
        .hs-fin-kpi-value { font-size: 1.35rem; }
        .hs-fin-invoices .hs-fin-table-wrap { display: none; }
        .hs-fin-invoice-cards { display: grid; gap: 0.65rem; }
        .hs-fin-search { display: none; }
        .hs-fin-search-mobile { display: block; }
    }

    @media (min-width: 768px) {
        .hs-fin-search-mobile { display: none; }
    }

    @media (max-width: 389px) {
        .hs-fin-kpi-value { font-size: 1.15rem; }
    }
</style>

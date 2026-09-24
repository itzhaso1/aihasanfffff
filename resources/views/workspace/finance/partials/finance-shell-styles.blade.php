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
    }

    .hs-fin-app a { text-decoration: none; }

    .hs-fin-sidebar,
    .hs-fin-drawer {
        width: var(--hs-fin-sidebar);
        background: #fff;
        border-inline-end: 1px solid var(--hs-fin-line);
    }

    .hs-fin-sidebar {
        display: none;
        position: fixed;
        top: 0;
        bottom: 0;
        inset-inline-start: 0;
        inset-inline-end: auto;
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
        padding-block: 0;
        padding-inline: 1.15rem 2.6rem;
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

    .hs-fin-search-mobile { display: none; }

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
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        font-size: 0.75rem;
        font-weight: 800;
        color: #64748b;
        white-space: nowrap;
    }

    .hs-fin-back:hover { color: var(--hs-fin-teal-dark); }

    .hs-fin-main {
        padding: 1.6rem 1.7rem 2.4rem;
        min-width: 0;
        overflow-x: auto;
    }

    .hs-fin-main input:not([type="checkbox"]):not([type="radio"]):not([type="hidden"]):not([type="file"]):not([type="range"]),
    .hs-fin-main select,
    .hs-fin-main textarea {
        border-color: #e2e8f0;
        border-radius: 0.8rem;
        color: var(--hs-fin-navy);
        font-size: 0.84rem;
        max-width: 100%;
    }

    .hs-fin-main input:not([type="checkbox"]):not([type="radio"]):not([type="hidden"]):not([type="file"]):not([type="range"]),
    .hs-fin-main select {
        min-height: 2.45rem;
    }

    .hs-fin-main nav[role="navigation"] {
        display: flex;
        flex-wrap: wrap;
        justify-content: center;
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
        padding-block: 0.05rem 0.25rem;
        padding-inline-start: 0.85rem;
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
        padding: 0.3rem 0.45rem;
        padding-inline-end: 0.7rem;
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
        .hs-fin-shell { padding-inline-start: var(--hs-fin-sidebar); padding-inline-end: 0; }
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

    .hs-inv-page { display: grid; gap: 1.15rem; }
    .hs-inv-form { display: grid; gap: 1.15rem; min-width: 0; }
    .hs-inv-hero {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        flex-wrap: wrap;
    }
    .hs-inv-kicker {
        margin: 0 0 0.2rem;
        font-size: 0.72rem;
        font-weight: 800;
        letter-spacing: 0.04em;
        color: var(--hs-fin-teal-dark);
    }
    .hs-inv-hero h2 {
        margin: 0;
        font-size: 1.45rem;
        font-weight: 900;
        color: var(--hs-fin-navy);
    }
    .hs-inv-hero p {
        margin: 0.35rem 0 0;
        color: var(--hs-fin-muted);
        font-size: 0.88rem;
        max-width: 40rem;
    }
    .hs-inv-pills { display: flex; flex-wrap: wrap; gap: 0.4rem; }
    .hs-inv-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        border-radius: 999px;
        padding: 0.35rem 0.75rem;
        font-size: 0.72rem;
        font-weight: 700;
        background: #fff;
        color: #475569;
        border: 1px solid #e8eef0;
    }
    .hs-inv-pill.is-live { background: var(--hs-fin-mint); color: var(--hs-fin-teal-dark); border-color: #d7f1eb; }
    .hs-inv-layout {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 21.5rem;
        gap: 1.15rem;
        align-items: start;
    }
    .hs-inv-stack { display: grid; gap: 1rem; min-width: 0; }
    .hs-inv-card {
        background: #fff;
        border: 1px solid #e8eef0;
        border-radius: 1.25rem;
        padding: 1.15rem 1.2rem 1.25rem;
        min-width: 0;
    }
    .hs-inv-card-head {
        display: flex;
        align-items: flex-start;
        gap: 0.75rem;
        margin-bottom: 1rem;
    }
    .hs-inv-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 2.15rem;
        height: 2.15rem;
        border-radius: 0.8rem;
        background: var(--hs-fin-mint);
        color: var(--hs-fin-teal-dark);
        flex: 0 0 auto;
    }
    .hs-inv-card-head h3 {
        margin: 0;
        font-size: 0.95rem;
        font-weight: 800;
        color: var(--hs-fin-navy);
    }
    .hs-inv-card-head p {
        margin: 0.15rem 0 0;
        font-size: 0.75rem;
        color: var(--hs-fin-muted);
    }
    .hs-inv-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.85rem 0.9rem;
    }
    .hs-inv-grid.is-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .hs-inv-span-2 { grid-column: span 2; }
    .hs-inv-span-3 { grid-column: span 3; }
    .hs-inv-field { min-width: 0; }
    .hs-inv-label {
        display: flex;
        align-items: center;
        gap: 0.25rem;
        margin-bottom: 0.38rem;
        font-size: 0.74rem;
        font-weight: 800;
        color: #475569;
    }
    .hs-inv-req { color: #e11d48; }
    .hs-inv-help { margin: 0.35rem 0 0; font-size: 0.7rem; color: #94a3b8; line-height: 1.5; }
    .hs-inv-error { margin: 0.35rem 0 0; font-size: 0.72rem; font-weight: 700; color: #dc2626; }
    .hs-inv-input,
    .hs-inv-card select,
    .hs-inv-card textarea,
    .hs-inv-card input[type="text"],
    .hs-inv-card input[type="number"],
    .hs-inv-card input[type="date"],
    .hs-inv-card input[type="file"] {
        width: 100%;
        border: 1px solid #e2e8f0;
        border-radius: 0.8rem;
        background: #f8fbfb;
        color: var(--hs-fin-navy);
        font-size: 0.84rem;
        min-height: 2.55rem;
        padding: 0.5rem 0.75rem;
        box-shadow: none;
    }
    .hs-inv-card textarea { min-height: 5.2rem; resize: vertical; }
    .hs-inv-card input:focus,
    .hs-inv-card select:focus,
    .hs-inv-card textarea:focus {
        outline: none;
        border-color: #9fe1d4;
        background: #fff;
        box-shadow: 0 0 0 4px rgba(6, 194, 164, 0.12);
    }
    .hs-inv-seg {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0.35rem;
        padding: 0.28rem;
        border-radius: 0.95rem;
        background: #f3f7f6;
        border: 1px solid #e8eef0;
    }
    .hs-inv-seg button {
        border: 0;
        background: transparent;
        min-height: 2.25rem;
        border-radius: 0.75rem;
        font-size: 0.8rem;
        font-weight: 800;
        color: #64748b;
        cursor: pointer;
    }
    .hs-inv-seg button.is-on {
        background: #fff;
        color: var(--hs-fin-teal-dark);
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.06);
    }
    .hs-inv-table-wrap { overflow-x: auto; margin: 0; }
    .hs-inv-table { width: 100%; min-width: 64rem; border-collapse: separate; border-spacing: 0; }
    .hs-inv-table th {
        padding: 0.55rem 0.45rem;
        font-size: 0.68rem;
        font-weight: 800;
        color: #64748b;
        text-align: start;
        background: #f7fbfa;
        border-bottom: 1px solid #e8eef0;
        white-space: nowrap;
    }
    .hs-inv-table td {
        padding: 0.55rem 0.4rem;
        vertical-align: top;
        border-bottom: 1px solid #f1f5f4;
    }
    .hs-inv-table input,
    .hs-inv-table select,
    .hs-inv-table textarea {
        min-height: 2.2rem;
        font-size: 0.75rem;
        border-radius: 0.65rem;
        background: #fff;
    }
    .hs-inv-table textarea { min-height: 2.2rem; }
    .hs-inv-line-total {
        display: block;
        padding-top: 0.55rem;
        font-weight: 800;
        color: var(--hs-fin-navy);
        font-variant-numeric: tabular-nums;
        white-space: nowrap;
    }
    .hs-inv-icon-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 1.85rem;
        height: 1.85rem;
        padding: 0 0.45rem;
        border-radius: 0.55rem;
        border: 1px solid #e2e8f0;
        background: #fff;
        color: #64748b;
        cursor: pointer;
        font-size: 0.68rem;
        font-weight: 800;
        white-space: nowrap;
    }
    .hs-inv-icon-btn:disabled { opacity: 0.35; cursor: not-allowed; }
    .hs-inv-icon-btn:hover { background: #f8fafc; color: var(--hs-fin-navy); }
    .hs-inv-icon-btn.is-danger { border-color: #fecaca; color: #dc2626; }
    .hs-inv-icon-btn.is-danger:hover { background: #fef2f2; }
    .hs-inv-add {
        margin-top: 0.85rem;
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        border: 1px dashed #cdeae3;
        background: var(--hs-fin-mint-soft);
        color: var(--hs-fin-teal-dark);
        border-radius: 0.8rem;
        padding: 0.55rem 0.85rem;
        font-size: 0.8rem;
        font-weight: 800;
        cursor: pointer;
    }
    .hs-inv-add:hover { background: var(--hs-fin-mint); }
    .hs-inv-side { display: grid; gap: 1rem; position: sticky; top: 5.4rem; }
    .hs-inv-summary {
        background: #f7fbfa;
        border: 1px solid #d7f1eb;
    }
    .hs-inv-sum-row {
        display: flex;
        justify-content: space-between;
        gap: 0.75rem;
        padding: 0.45rem 0;
        font-size: 0.82rem;
        color: #475569;
        font-variant-numeric: tabular-nums;
    }
    .hs-inv-sum-row strong { color: var(--hs-fin-navy); font-weight: 800; }
    .hs-inv-sum-total {
        margin-top: 0.35rem;
        padding: 0.85rem 0.9rem;
        border-radius: 0.95rem;
        background: var(--hs-fin-navy);
        color: #fff;
        display: flex;
        justify-content: space-between;
        align-items: baseline;
        gap: 0.75rem;
    }
    .hs-inv-sum-total span { font-size: 0.75rem; font-weight: 700; color: #cbd5e1; }
    .hs-inv-sum-total b { font-size: 1.2rem; font-weight: 900; color: #fff; font-variant-numeric: tabular-nums; }
    .hs-inv-remain { color: var(--hs-fin-teal-dark) !important; }
    .hs-inv-actions {
        position: sticky;
        bottom: 0;
        z-index: 20;
        display: flex;
        justify-content: flex-end;
        flex-wrap: wrap;
        gap: 0.55rem;
        margin: 0;
        padding: 0.85rem 0.9rem;
        background: #f7fcfb;
        border: 1px solid #e8eef0;
        border-radius: 1.1rem;
    }
    .hs-inv-btn:disabled { opacity: 0.55; cursor: not-allowed; }
    .hs-inv-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.4rem;
        min-height: 2.6rem;
        padding: 0.55rem 1rem;
        border-radius: 0.85rem;
        font-size: 0.84rem;
        font-weight: 800;
        text-decoration: none;
        cursor: pointer;
        border: 1px solid transparent;
    }
    .hs-inv-btn-primary { background: var(--hs-fin-teal); color: #fff; }
    .hs-inv-btn-primary:hover { background: var(--hs-fin-teal-dark); }
    .hs-inv-btn-secondary { background: #fff; color: var(--hs-fin-navy); border-color: #dbe4e2; }
    .hs-inv-btn-secondary:hover { background: #f8fafc; }
    .hs-inv-btn-ghost { background: transparent; color: #64748b; }
    .hs-inv-btn-ghost:hover { background: #fff; color: var(--hs-fin-navy); }
    .hs-inv-preview {
        position: fixed;
        inset: 0;
        z-index: 80;
        display: grid;
        place-items: center;
        padding: 1.25rem;
    }
    .hs-inv-preview-scrim { position: absolute; inset: 0; background: rgba(15, 23, 42, 0.45); }
    .hs-inv-preview-sheet {
        position: relative;
        width: min(52rem, 100%);
        max-height: min(90vh, 54rem);
        overflow: auto;
        background: #fff;
        border-radius: 1.2rem;
        padding: 1.4rem 1.5rem 1.6rem;
        box-shadow: 0 24px 64px rgba(15, 23, 42, 0.18);
    }
    @media (max-width: 1279px) {
        .hs-inv-layout { grid-template-columns: 1fr; }
        .hs-inv-side { position: static; }
        .hs-inv-grid.is-3 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .hs-inv-span-3 { grid-column: span 2; }
    }
    @media (max-width: 1023px) {
        .hs-inv-table { min-width: 0; }
        .hs-inv-table thead { display: none; }
        .hs-inv-table,
        .hs-inv-table tbody {
            display: block;
            width: 100%;
        }
        .hs-inv-table tr {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.55rem 0.65rem;
            margin-bottom: 0.85rem;
            padding: 0.85rem;
            border: 1px solid #e8eef0;
            border-radius: 1rem;
            background: #fff;
        }
        .hs-inv-table td {
            display: flex;
            flex-direction: column;
            gap: 0.28rem;
            padding: 0;
            border: 0;
            min-width: 0;
        }
        .hs-inv-table td[data-span="all"] { grid-column: 1 / -1; }
        .hs-inv-table td::before {
            content: attr(data-label);
            font-size: 0.68rem;
            font-weight: 800;
            color: #64748b;
        }
        .hs-inv-table td:empty::before,
        .hs-inv-table td:not([data-label])::before { content: none; }
        .hs-inv-line-total { padding-top: 0; font-size: 1rem; }
        .hs-inv-table textarea { min-height: 4.2rem; }
    }
    @media (max-width: 767px) {
        .hs-inv-grid,
        .hs-inv-grid.is-3 { grid-template-columns: 1fr; }
        .hs-inv-span-2,
        .hs-inv-span-3 { grid-column: auto; }
        .hs-inv-actions { justify-content: stretch; }
        .hs-inv-actions .hs-inv-btn { flex: 1 1 calc(50% - 0.55rem); }
        .hs-inv-actions .hs-inv-btn-primary { flex-basis: 100%; }
        .hs-inv-table tr { grid-template-columns: 1fr; }
        .hs-inv-hero h2 { font-size: 1.2rem; }
        .hs-inv-preview { padding: 0.75rem; align-items: start; }
        .hs-inv-preview-sheet { padding: 1rem; max-height: 92vh; }
    }
</style>

<style>
    html { scroll-behavior: smooth; }
    .hs-legal-app { overflow-x: hidden; background: #F7FCFB; color: #0f172a; }
    .hs-legal-hero {
        border-bottom: 1px solid #e6eeeb;
        background: linear-gradient(180deg, #fff 0%, #F7FCFB 100%);
    }
    .hs-legal-kicker {
        font-size: 0.72rem;
        font-weight: 800;
        letter-spacing: 0.12em;
        color: #049e86;
    }
    .hs-legal-card {
        background: #fff;
        border: 1px solid #e6eeeb;
        border-radius: 1.25rem;
        box-shadow: 0 12px 32px rgba(15, 23, 42, 0.04);
    }
    .hs-legal-layout {
        display: grid;
        gap: 1.5rem;
        min-width: 0;
    }
    .hs-legal-toc {
        position: sticky;
        top: 6rem;
        min-width: 0;
    }
    .hs-legal-toc a {
        display: block;
        border-radius: 0.75rem;
        padding: 0.45rem 0.7rem;
        font-size: 0.8rem;
        font-weight: 700;
        color: #475569;
    }
    .hs-legal-toc a:hover,
    .hs-legal-toc a:focus-visible {
        background: #E8FAF6;
        color: #0f7668;
        outline: none;
    }
    footer a[aria-current="page"] { color: #0f7668; }
    .hs-legal-section {
        scroll-margin-top: 6.5rem;
        padding-bottom: 1.6rem;
        margin-bottom: 1.6rem;
        border-bottom: 1px solid #f1f5f4;
    }
    .hs-legal-section:last-child { border-bottom: 0; margin-bottom: 0; padding-bottom: 0; }
    .hs-legal-section h2 {
        font-size: 1.15rem;
        font-weight: 900;
        color: #0f172a;
        margin-bottom: 0.7rem;
    }
    .hs-legal-prose p,
    .hs-legal-prose li {
        font-size: 0.95rem;
        line-height: 1.9;
        color: #334155;
        overflow-wrap: anywhere;
    }
    .hs-legal-prose ul { margin: 0.7rem 0 0; padding-inline-start: 1.15rem; }
    .hs-legal-prose li + li { margin-top: 0.35rem; }
    .hs-legal-contact dt { font-size: 0.75rem; font-weight: 800; color: #64748b; }
    .hs-legal-contact dd { margin-top: 0.15rem; font-weight: 700; color: #0f172a; }
    .hs-legal-toc-toggle {
        display: flex;
        width: 100%;
        align-items: center;
        justify-content: space-between;
        border: 1px solid #e6eeeb;
        border-radius: 1rem;
        background: #fff;
        padding: 0.85rem 1rem;
        font-size: 0.9rem;
        font-weight: 800;
        color: #0f172a;
    }
    .hs-legal-toc-toggle:focus-visible { outline: 2px solid #06C2A4; outline-offset: 2px; }
    @media (min-width: 1024px) {
        .hs-legal-mobile-toc { display: none; }
        .hs-legal-layout {
            grid-template-columns: 16.5rem minmax(0, 1fr);
            align-items: start;
        }
    }
    @media (max-width: 1023px) {
        .hs-legal-desktop-toc { display: none; }
        .hs-legal-toc { position: static; }
    }
</style>

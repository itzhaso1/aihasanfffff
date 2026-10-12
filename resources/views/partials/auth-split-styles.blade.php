<style>
    .hs-login-page {
        min-height: 100dvh;
        display: flex;
        flex-direction: column;
        overflow-x: hidden;
    }
    .hs-login-form-col {
        display: flex;
        justify-content: center;
        align-items: flex-start;
        padding: 1.25rem 1rem 1.75rem;
        background: #F7FCFB;
        min-width: 0;
    }
    .hs-login-card {
        width: 100%;
        max-width: 28rem;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 1.5rem;
        box-shadow: 0 16px 40px -28px rgba(15, 23, 42, 0.35);
        padding: 1.6rem 1.35rem 1.45rem;
    }
    .hs-login-fade {
        animation: hsLoginFade .5s ease-out both;
    }
    .hs-login-fade-delay { animation-delay: .08s; }
    @keyframes hsLoginFade {
        from { opacity: 0; transform: translateY(8px); }
        to { opacity: 1; transform: none; }
    }
    .hs-login-input {
        width: 100%;
        border-radius: 0.9rem;
        border-color: #cbd5e1;
        background: #fff;
        color: #0f172a;
        transition: border-color .15s ease, box-shadow .15s ease;
    }
    .hs-login-input:hover { border-color: #94a3b8; }
    .hs-login-input:focus {
        border-color: #06C2A4;
        box-shadow: 0 0 0 3px rgba(6, 194, 164, 0.16);
    }
    .hs-login-input.is-invalid {
        border-color: #dc2626;
        box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.1);
    }
    .hs-login-social {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        width: 100%;
        border: 1px solid #e2e8f0;
        background: #fff;
        border-radius: 0.9rem;
        padding: 0.7rem 1rem;
        font-size: 0.875rem;
        font-weight: 700;
        color: #334155;
        transition: background .15s ease, border-color .15s ease, transform .15s ease;
    }
    .hs-login-social:hover {
        background: #F7FCFB;
        border-color: #cbd5e1;
    }
    .hs-login-social:focus-visible {
        outline: none;
        box-shadow: 0 0 0 3px rgba(6, 194, 164, 0.16);
    }
    .hs-login-toggle {
        position: absolute;
        inset-inline-end: 0.7rem;
        top: 50%;
        transform: translateY(-50%);
        color: #64748b;
        padding: 0.25rem;
        border-radius: 0.5rem;
    }
    .hs-login-toggle:hover { color: #0f172a; background: #F7FCFB; }
    .hs-login-toggle:focus-visible {
        outline: none;
        box-shadow: 0 0 0 3px rgba(6, 194, 164, 0.16);
    }
    .hs-field-icon {
        position: absolute;
        inset-inline-start: 0.8rem;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
        pointer-events: none;
        display: inline-flex;
    }
    .hs-login-input.has-icon {
        padding-inline-start: 2.55rem;
    }
    .hs-login-showcase {
        position: relative;
        overflow: hidden;
        background: #E8FAF6;
        padding: 1.5rem 1.15rem 2rem;
        min-width: 0;
    }
    .hs-login-showcase::before,
    .hs-login-showcase::after {
        content: "";
        position: absolute;
        border-radius: 999px;
        background: rgba(6, 194, 164, 0.08);
        pointer-events: none;
    }
    .hs-login-showcase::before {
        width: 18rem;
        height: 18rem;
        top: -6rem;
        inset-inline-start: -5rem;
    }
    .hs-login-showcase::after {
        width: 14rem;
        height: 14rem;
        bottom: 8rem;
        inset-inline-end: -4rem;
    }
    .hs-login-showcase-inner {
        position: relative;
        z-index: 1;
        max-width: 40rem;
        margin-inline: auto;
    }
    .hs-login-features {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 0.65rem;
    }
    .hs-login-feature {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 1rem;
        padding: 0.85rem 0.55rem 0.95rem;
        text-align: center;
        box-shadow: 0 10px 24px -20px rgba(15, 23, 42, 0.35);
        min-width: 0;
    }
    .hs-login-feature h3 {
        margin: 0.55rem 0 0;
        font-size: 0.86rem;
        font-weight: 800;
        color: #0f172a;
    }
    .hs-login-feature p {
        margin: 0.3rem 0 0;
        font-size: 0.7rem;
        font-weight: 600;
        line-height: 1.55;
        color: #64748b;
    }
    .hs-login-devices {
        position: relative;
        margin-top: 1.75rem;
        padding: 0.25rem 0 0 2.4rem;
        min-height: 13.5rem;
        max-width: 100%;
        overflow: hidden;
        direction: ltr;
    }
    .hs-login-laptop {
        width: min(100%, 34rem);
        margin-inline-start: auto;
    }
    .hs-login-laptop-screen {
        background: #0f172a;
        border-radius: 0.85rem 0.85rem 0.3rem 0.3rem;
        padding: 0.42rem 0.42rem 0;
        box-shadow: 0 18px 40px -24px rgba(15, 23, 42, 0.42);
    }
    .hs-login-laptop-screen img {
        display: block;
        width: 100%;
        height: 11.5rem;
        object-fit: cover;
        object-position: top center;
        border-radius: 0.45rem 0.45rem 0 0;
        background: #fff;
    }
    .hs-login-laptop-base {
        height: 0.65rem;
        border-radius: 0 0 0.75rem 0.75rem;
        background: #d6dee8;
    }
    .hs-login-phone {
        position: absolute;
        left: 0;
        bottom: 0.15rem;
        width: 6.4rem;
        height: 13.1rem;
        max-width: 34%;
        background: #0f172a;
        border-radius: 1.3rem;
        padding: 0.38rem;
        box-shadow: 0 16px 32px -18px rgba(15, 23, 42, 0.5);
    }
    .hs-login-phone-notch {
        position: absolute;
        top: 0.45rem;
        left: 50%;
        width: 2rem;
        height: 0.28rem;
        transform: translateX(-50%);
        border-radius: 999px;
        background: #334155;
        z-index: 2;
    }
    .hs-login-phone img {
        display: block;
        width: 100%;
        height: 100%;
        object-fit: cover;
        object-position: 8% 12%;
        border-radius: 1rem;
        background: #fff;
    }
    @media (max-width: 767.98px) {
        .hs-login-page--login {
            min-height: 100dvh;
        }
        .hs-login-page--login .hs-login-showcase {
            display: none !important;
        }
        .hs-login-page--login .hs-login-form-col {
            min-height: 100dvh;
            align-items: center;
            padding: 1.35rem 1.1rem 1.75rem;
            background: #F7FCFB;
        }
        .hs-login-page--login .hs-login-card {
            max-width: none;
            width: 100%;
            padding: 1.45rem 1.1rem 1.3rem;
            box-shadow: 0 12px 28px -24px rgba(15, 23, 42, 0.28);
        }
    }
    @media (min-width: 768px) and (max-width: 1023.98px) {
        .hs-login-page {
            display: flex;
            flex-direction: column;
        }
        .hs-login-form-col {
            align-items: center;
            padding: 2rem 1.5rem 1.5rem;
        }
        .hs-login-card {
            max-width: 30rem;
            padding: 1.7rem 1.5rem 1.45rem;
        }
        .hs-login-showcase {
            display: flex;
            align-items: center;
            padding: 1.5rem 1.5rem 2rem;
        }
        .hs-login-showcase-inner {
            max-width: 40rem;
        }
        .hs-login-laptop-screen img { height: 12rem; }
        .hs-login-phone {
            width: 6rem;
            height: 12.2rem;
        }
        .hs-login-devices {
            min-height: 13.5rem;
            padding-left: 2.2rem;
        }
    }
    @media (min-width: 1024px) {
        .hs-login-page {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            min-height: 100dvh;
        }
        .hs-login-form-col {
            align-items: center;
            padding: 2rem 2rem;
            min-width: 0;
        }
        .hs-login-form-col.hs-register-form-col {
            align-items: flex-start;
            max-height: 100dvh;
            overflow-y: auto;
            padding-top: 1.5rem;
            padding-bottom: 1.5rem;
        }
        .hs-login-card {
            max-width: 28rem;
            width: 100%;
            padding: 2rem 1.85rem 1.7rem;
        }
        .hs-login-card.hs-register-card {
            max-width: 30rem;
            padding: 1.55rem 1.65rem 1.45rem;
        }
        .hs-login-showcase {
            min-height: 100dvh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 2.25rem;
            min-width: 0;
        }
        .hs-login-showcase-inner {
            width: 100%;
            max-width: 36rem;
            margin-inline: auto;
        }
        .hs-login-laptop { width: min(100%, 34rem); }
        .hs-login-laptop-screen img { height: 14.5rem; }
        .hs-login-phone {
            width: 7rem;
            height: 14.4rem;
        }
        .hs-login-devices {
            margin-top: 1.75rem;
            min-height: 16rem;
            padding-left: 2.6rem;
        }
    }
    @media (min-width: 1440px) {
        .hs-login-card { max-width: 30rem; }
        .hs-login-showcase { padding: 2.5rem 3rem; }
        .hs-login-showcase-inner { max-width: 40rem; }
        .hs-login-laptop-screen img { height: 17.5rem; }
        .hs-login-phone {
            width: 8rem;
            height: 16.4rem;
        }
        .hs-login-devices { min-height: 18.5rem; padding-left: 3rem; }
    }
    @media (min-width: 1920px) {
        .hs-login-card { max-width: 32rem; }
        .hs-login-laptop-screen img { height: 19rem; }
        .hs-login-phone {
            width: 8.4rem;
            height: 17.2rem;
        }
    }
</style>

/**
 * Keeps the app shell mounted. Ordinary clicks and form submits update only
 * the page region; they never cover the sidebar or navbar with a page loader.
 */
const STYLE_ID = 'hs-page-nav-style';

const style = `
[data-hs-region][data-hs-busy] { position: relative; }
[data-hs-region][data-hs-busy]::before {
    content: "";
    position: absolute;
    inset-inline: 0;
    top: 0;
    height: 2px;
    z-index: 40;
    pointer-events: none;
    background: linear-gradient(90deg, transparent, #06C2A4, transparent);
    background-size: 200% 100%;
    animation: hs-nav-slide 0.9s linear infinite;
}
[data-hs-region][data-hs-busy] [data-hs-filter-group] a[data-hs-selected] {
    background-color: #E8FAF6 !important;
    color: #067e6b !important;
}
[data-hs-region][data-hs-busy] [data-hs-filter-group] a:not([data-hs-selected]) {
    background-color: transparent !important;
    color: #64748b !important;
}
.hs-skel-row {
    height: 3.25rem;
    margin: 0.5rem 0.75rem;
    border-radius: 0.75rem;
    background: linear-gradient(90deg, #f1f5f9 25%, #e8faf6 37%, #f1f5f9 63%);
    background-size: 400% 100%;
    animation: hs-skel 1.2s ease infinite;
}
.hs-local-error {
    margin: 0.5rem 0.75rem;
    border-radius: 0.75rem;
    background: #fff1f2;
    color: #be123c;
    padding: 0.5rem 0.75rem;
    font-size: 0.8rem;
    font-weight: 600;
}
.hs-action-busy { cursor: progress; }
.hs-action-busy::after {
    content: "";
    display: inline-block;
    width: 0.8rem;
    height: 0.8rem;
    margin-inline-start: 0.45rem;
    border: 2px solid currentColor;
    border-inline-end-color: transparent;
    border-radius: 999px;
    vertical-align: -0.12rem;
    animation: hs-nav-spin 0.6s linear infinite;
}
@keyframes hs-nav-slide { from { background-position: 100% 0; } to { background-position: -100% 0; } }
@keyframes hs-skel { from { background-position: 100% 0; } to { background-position: 0 0; } }
@keyframes hs-nav-spin { to { transform: rotate(-360deg); } }
`;

const DOWNLOAD = /\.(pdf|zip|csv|xlsx|xls|xml|png|jpe?g|gif|webp|svg|mp4|mp3|wav|doc|docx)(\?|$)/i;
const OAUTH_CONNECT = /\/channels\/(?:facebook|instagram|tiktok)\/connect\/?$/;

let navSeq = 0;
let navController = null;
let listSnapshot = null;

function ensureStyle() {
    if (document.getElementById(STYLE_ID)) return;
    const node = document.createElement('style');
    node.id = STYLE_ID;
    node.textContent = style;
    document.head.appendChild(node);
}

function documentMode() {
    return document.body?.dataset.hsNav === 'document' || !document.querySelector('[data-hs-region]');
}

function optedOut(node) {
    return Boolean(node?.closest('[data-hs-nav="document"]'));
}

function shouldHandleLink(link, event) {
    if (documentMode() || optedOut(link) || event.defaultPrevented) return false;
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return false;
    if (link.target && link.target !== '_self') return false;
    if (link.hasAttribute('download')) return false;
    const href = link.getAttribute('href') || '';
    if (href === '' || href.startsWith('#') || href.startsWith('mailto:') || href.startsWith('tel:') || href.startsWith('javascript:')) return false;
    let next;
    try {
        next = new URL(link.href, window.location.origin);
    } catch (_) {
        return false;
    }
    if (next.origin !== window.location.origin) return false;
    if (DOWNLOAD.test(next.pathname)) return false;
    if (OAUTH_CONNECT.test(next.pathname)) return false;
    const current = new URL(window.location.href);
    if (next.pathname === current.pathname && next.search === current.search) return false;
    return true;
}

function shouldHandleForm(form, event) {
    if (documentMode() || optedOut(form) || event.defaultPrevented) return false;
    if (form.target && form.target !== '_self') return false;
    let action;
    try {
        action = new URL(form.action, window.location.origin);
    } catch (_) {
        return false;
    }
    return action.origin === window.location.origin;
}

function applyOptimistic(link) {
    const group = link.closest('[data-hs-filter-group]');
    if (group) {
        group.querySelectorAll('a').forEach((item) => {
            if (item === link) item.setAttribute('data-hs-selected', '1');
            else item.removeAttribute('data-hs-selected');
        });
    }

    const menu = link.closest('[data-hs-menu]');
    if (!menu) return;
    const label = menu.querySelector('[data-hs-filter-label]');
    const text = (link.querySelector('span')?.textContent || link.textContent || '').trim();
    if (label && text) label.textContent = text;
    menu.querySelectorAll('a').forEach((item) => item.classList.toggle('is-active', item === link));
    const trigger = menu.querySelector('.inbox-filter-trigger, button');
    if (trigger && text && text !== 'جميع الحالات' && text !== 'كل المحادثات') trigger.classList.add('is-on');
}

function skeletonMarkup() {
    return Array.from({ length: 4 }, () => '<div class="hs-skel-row"></div>').join('');
}

function resultSlot(region, url, method, source) {
    if (method !== 'GET') return null;
    if (source?.closest?.('[data-hs-result], tbody')) return null;
    const marked = region.querySelector('[data-hs-result]');
    if (marked) return marked;
    const next = new URL(url, window.location.origin);
    if (next.pathname !== window.location.pathname) return null;
    const table = region.querySelector('table');
    if (!table) return null;
    const body = table.tBodies?.[0];
    if (body) return body;
    return table.parentElement && table.parentElement !== region ? table.parentElement : table;
}

function armLocalWait(region, url, method, source) {
    region.dataset.hsBusy = '1';
    const slot = resultSlot(region, url, method, source);
    if (slot && slot.dataset.hsWaiting !== '1') {
        listSnapshot = { slot, html: slot.innerHTML };
        slot.dataset.hsWaiting = '1';
        slot.innerHTML = skeletonMarkup();
    }
    return () => {
        if (listSnapshot?.slot?.isConnected && listSnapshot.slot.dataset.hsWaiting === '1') {
            listSnapshot.slot.innerHTML = listSnapshot.html;
            delete listSnapshot.slot.dataset.hsWaiting;
        }
        listSnapshot = null;
        if (region.isConnected) delete region.dataset.hsBusy;
    };
}

function showLocalError(region, message) {
    if (!region) return;
    let bar = region.querySelector(':scope > .hs-local-error');
    if (!bar) {
        bar = document.createElement('div');
        bar.className = 'hs-local-error';
        bar.setAttribute('role', 'alert');
        region.prepend(bar);
    }
    bar.textContent = message;
}

function optimisticDelete(form) {
    const spoof = form.querySelector('input[name="_method"]')?.value || form.method || 'get';
    if (String(spoof).toUpperCase() !== 'DELETE') return () => {};
    const row = form.closest('tr, [data-hs-item]');
    if (!row) return () => {};
    const previous = row.style.display;
    row.style.display = 'none';
    return () => {
        row.style.display = previous;
    };
}

function releaseButton(button) {
    if (!button) return;
    button.removeAttribute('aria-busy');
    button.classList.remove('hs-action-busy');
    if (button.dataset.hsPrevDisabled === '0') button.disabled = false;
    delete button.dataset.hsPrevDisabled;
}

async function executeScript(old) {
    const script = document.createElement('script');
    if (old.type) script.type = old.type;
    if (old.src) {
        if ([...document.scripts].some((existing) => existing.src === old.src)) return;
        script.src = old.src;
        script.async = false;
        await new Promise((resolve) => {
            script.onload = resolve;
            script.onerror = resolve;
            document.body.appendChild(script);
        });
        return;
    }
    if (!old.textContent || !old.textContent.trim()) return;
    script.textContent = old.textContent;
    document.body.appendChild(script);
}

async function runScripts(root) {
    if (!root) return;
    const scripts = [...root.querySelectorAll('script')];
    for (const script of scripts) {
        await executeScript(script);
        script.remove();
    }
}

function mountChrome(source, anchor) {
    const imported = document.importNode(source, true);
    if (anchor?.parentNode) anchor.parentNode.insertBefore(imported, anchor);
    else document.body.appendChild(imported);
    imported.scrollTop = 0;
    window.Alpine?.initTree?.(imported);
    return imported;
}

function swapChrome(doc) {
    const names = new Set(
        [...document.querySelectorAll('[data-hs-chrome]'), ...doc.querySelectorAll('[data-hs-chrome]')]
            .map((el) => el.dataset.hsChrome),
    );
    names.forEach((name) => {
        const selector = `[data-hs-chrome="${CSS.escape(name)}"]`;
        const current = [...document.querySelectorAll(selector)];
        const next = [...doc.querySelectorAll(selector)];
        const count = Math.max(current.length, next.length);
        for (let index = 0; index < count; index += 1) {
            const el = current[index];
            const source = next[index];
            if (el && source) {
                if (el.innerHTML === source.innerHTML) continue;
                const scroll = el.scrollTop;
                const imported = document.importNode(source, true);
                el.replaceWith(imported);
                imported.scrollTop = scroll;
                window.Alpine?.initTree?.(imported);
            } else if (el && !source) {
                el.remove();
            } else if (!el && source) {
                mountChrome(source, document.querySelector('[data-hs-region]'));
            }
        }
    });
}

function syncHead(doc) {
    const title = doc.querySelector('title')?.textContent;
    if (title) document.title = title;
    const token = doc.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    const current = document.querySelector('meta[name="csrf-token"]');
    if (token && current) current.setAttribute('content', token);
}

async function applyDocument(html, responseUrl, seq) {
    const doc = new DOMParser().parseFromString(html, 'text/html');
    const nextShell = doc.body?.dataset.hsShell || '';
    const currentShell = document.body?.dataset.hsShell || '';
    const nextRegion = doc.querySelector('[data-hs-region]');
    const region = document.querySelector('[data-hs-region]');
    if (!region || !nextRegion || !nextShell || nextShell !== currentShell) {
        window.location.assign(responseUrl);
        return false;
    }
    if (seq !== navSeq) return false;

    const samePath = new URL(responseUrl, window.location.origin).pathname === window.location.pathname;
    const scrollY = window.scrollY;
    region.innerHTML = nextRegion.innerHTML;
    listSnapshot = null;
    delete region.dataset.hsBusy;
    const currentRoot = document.querySelector('[data-hs-shell-root]');
    const nextRoot = doc.querySelector('[data-hs-shell-root]');
    if (currentRoot && nextRoot && currentRoot.className !== nextRoot.className) {
        currentRoot.className = nextRoot.className;
    }
    swapChrome(doc);
    syncHead(doc);
    if (new URL(responseUrl, window.location.origin).href !== window.location.href) {
        history.replaceState({ hs: 1 }, '', responseUrl);
    }
    await runScripts(region);
    await runScripts(doc.querySelector('[data-hs-boot]'));
    if (seq !== navSeq) return false;
    window.Alpine?.initTree?.(region);
    document.dispatchEvent(new CustomEvent('hs:navigated', { detail: { url: responseUrl } }));
    if (!samePath) window.scrollTo(0, 0);
    else window.scrollTo(0, scrollY);
    return true;
}

async function visit(url, { fromPop = false, form = null, button = null, rollback = null, source = null } = {}) {
    const region = document.querySelector('[data-hs-region]');
    if (!region) {
        window.location.assign(url);
        return;
    }

    const seq = ++navSeq;
    const isMutation = Boolean(form) && (form.method || 'get').toLowerCase() !== 'get';
    if (!isMutation) {
        navController?.abort();
        navController = new AbortController();
    }
    const signal = isMutation ? undefined : navController.signal;
    const previous = window.location.href;
    const restore = armLocalWait(region, url, isMutation ? 'POST' : 'GET', source || form);

    if (!fromPop && !isMutation) {
        history.pushState({ hs: 1 }, '', url);
    }

    try {
        const options = {
            headers: { Accept: 'text/html' },
            credentials: 'same-origin',
            signal,
        };
        if (isMutation) {
            const formData = new FormData(form);
            if (button?.name) formData.append(button.name, button.value);
            options.method = (form.getAttribute('method') || 'POST').toUpperCase() === 'GET' ? 'GET' : 'POST';
            if (options.method !== 'GET') options.body = formData;
        }
        const response = await fetch(url, options);
        if (seq !== navSeq) return;
        const type = response.headers.get('content-type') || '';
        if (!response.ok || !type.includes('text/html')) {
            window.location.assign(response.url || url);
            return;
        }
        const html = await response.text();
        if (seq !== navSeq) return;
        const applied = await applyDocument(html, response.url || url, seq);
        if (applied) releaseButton(button);
    } catch (error) {
        if (error?.name === 'AbortError' || seq !== navSeq) return;
        if (!fromPop && !isMutation && window.location.href !== previous) {
            history.replaceState({ hs: 1 }, '', previous);
        }
        restore();
        rollback?.();
        releaseButton(button);
        showLocalError(document.querySelector('[data-hs-region]'), 'تعذر تحديث هذا الجزء. حاول مرة أخرى.');
    }
}

function onClick(event) {
    const link = event.target.closest?.('a[href]');
    if (!link || !shouldHandleLink(link, event)) return;
    event.preventDefault();
    applyOptimistic(link);
    visit(link.href, { source: link });
}

function onSubmit(event) {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !shouldHandleForm(form, event)) return;
    event.preventDefault();
    const button = event.submitter || null;
    const method = (form.getAttribute('method') || 'GET').toUpperCase();
    const rollback = optimisticDelete(form);
    if (method !== 'GET' && button) {
        button.dataset.hsPrevDisabled = button.disabled ? '1' : '0';
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        button.classList.add('hs-action-busy');
    }
    if (method === 'GET') {
        const action = new URL(form.action, window.location.origin);
        const params = new URLSearchParams();
        new FormData(form).forEach((value, key) => {
            if (typeof value === 'string') params.append(key, value);
        });
        action.search = params.toString();
        visit(action.toString());
        return;
    }
    visit(form.action, { form, button, rollback });
}

function onPop(event) {
    if (documentMode() || !event.state?.hs) {
        if (!documentMode() && document.querySelector('[data-hs-region]')) {
            visit(window.location.href, { fromPop: true });
        }
        return;
    }
    visit(window.location.href, { fromPop: true });
}

function start() {
    if (!document.body || document.body.dataset.hsNav === 'document') return;
    if (!document.querySelector('[data-hs-region]')) return;
    ensureStyle();
    document.addEventListener('click', onClick);
    document.addEventListener('submit', onSubmit);
    window.addEventListener('popstate', onPop);
    history.replaceState({ hs: 1, ...(history.state || {}) }, '', window.location.href);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, { once: true });
} else {
    start();
}

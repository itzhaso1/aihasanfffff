/**
 * Shared POS feedback: soft add-to-cart sound + lightweight toast.
 * Safe when browsers block autoplay until a user gesture.
 */
(function registerHasoPosFeedback(global) {
    const state = {
        unlocked: false,
        lastPlayAt: 0,
        audioCtx: null,
        toastTimer: null,
    };

    function ensureToastHost() {
        let host = document.getElementById('haso-pos-toast-host');
        if (host) return host;
        host = document.createElement('div');
        host.id = 'haso-pos-toast-host';
        host.className = 'pointer-events-none fixed inset-x-0 bottom-24 z-[80] flex justify-center px-3 sm:bottom-8';
        document.body.appendChild(host);
        return host;
    }

    function unlockAudio() {
        if (state.unlocked) return;
        try {
            const Ctx = global.AudioContext || global.webkitAudioContext;
            if (!Ctx) return;
            state.audioCtx = state.audioCtx || new Ctx();
            if (state.audioCtx.state === 'suspended') {
                state.audioCtx.resume().catch(() => {});
            }
            state.unlocked = true;
        } catch (_error) {
            // Ignore — browsers may block audio until gesture; never throw.
        }
    }

    function playAddSound() {
        const now = Date.now();
        if (now - state.lastPlayAt < 180) return;
        state.lastPlayAt = now;

        try {
            unlockAudio();
            const Ctx = global.AudioContext || global.webkitAudioContext;
            if (!Ctx) return;
            const ctx = state.audioCtx || new Ctx();
            state.audioCtx = ctx;
            if (ctx.state === 'suspended') {
                ctx.resume().catch(() => {});
            }

            const oscillator = ctx.createOscillator();
            const gain = ctx.createGain();
            oscillator.type = 'sine';
            oscillator.frequency.setValueAtTime(880, ctx.currentTime);
            oscillator.frequency.exponentialRampToValueAtTime(1320, ctx.currentTime + 0.06);
            gain.gain.setValueAtTime(0.0001, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.045, ctx.currentTime + 0.015);
            gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.12);
            oscillator.connect(gain);
            gain.connect(ctx.destination);
            oscillator.start();
            oscillator.stop(ctx.currentTime + 0.14);
        } catch (_error) {
            // Silent fail — never surface audio errors to the cashier.
        }
    }

    function showToast(message, { duration = 1800 } = {}) {
        const host = ensureToastHost();
        const toast = document.createElement('div');
        toast.setAttribute('role', 'status');
        toast.className = 'pointer-events-auto max-w-sm rounded-xl border border-emerald-200 bg-white px-3 py-2 text-sm font-semibold text-emerald-800 shadow-lg transition';
        toast.textContent = message;
        host.innerHTML = '';
        host.appendChild(toast);
        if (state.toastTimer) clearTimeout(state.toastTimer);
        state.toastTimer = setTimeout(() => {
            toast.style.opacity = '0';
            setTimeout(() => toast.remove(), 220);
        }, duration);
    }

    function playNewOrderSound() {
        try {
            unlockAudio();
            const Ctx = global.AudioContext || global.webkitAudioContext;
            if (!Ctx) return;
            const ctx = state.audioCtx || new Ctx();
            state.audioCtx = ctx;
            if (ctx.state === 'suspended') {
                ctx.resume().catch(() => {});
            }

            [660, 880, 1100].forEach((freq, index) => {
                const oscillator = ctx.createOscillator();
                const gain = ctx.createGain();
                const start = ctx.currentTime + (index * 0.12);
                oscillator.type = 'triangle';
                oscillator.frequency.setValueAtTime(freq, start);
                gain.gain.setValueAtTime(0.0001, start);
                gain.gain.exponentialRampToValueAtTime(0.06, start + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.15);
                oscillator.connect(gain);
                gain.connect(ctx.destination);
                oscillator.start(start);
                oscillator.stop(start + 0.16);
            });
        } catch (_error) {
            // Silent fail
        }
    }

    function notifyNewMenuOrder(order) {
        playNewOrderSound();
        const table = order?.table_name ? `طاولة ${order.table_name}` : 'المنيو';
        const number = order?.order_number ? `#${order.order_number}` : '';
        showToast(`🛎️ طلب جديد من ${table} ${number}`.trim(), { duration: 4200 });
    }

    function notifyItemAdded(itemName) {
        playAddSound();
        const label = (itemName || 'المنتج').toString().trim() || 'المنتج';
        showToast(`✓ تمت إضافة ${label} إلى الطلب`);
    }

    ['pointerdown', 'keydown', 'touchstart'].forEach((eventName) => {
        global.addEventListener(eventName, unlockAudio, { once: true, passive: true });
    });

    global.HasoPosFeedback = {
        unlockAudio,
        playAddSound,
        playNewOrderSound,
        showToast,
        notifyItemAdded,
        notifyNewMenuOrder,
    };
})(window);

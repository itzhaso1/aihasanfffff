
import Alpine from 'alpinejs';
import './pos-feedback';

window.Alpine = Alpine;

document.addEventListener('alpine:init', () => {
    Alpine.store('posCartUi', {
        count: 0,
        open: false,
        bumpPulse: false,
        setCount(count) {
            this.count = Math.max(0, Number(count) || 0);
        },
        openDrawer() {
            this.open = true;
        },
        closeDrawer() {
            this.open = false;
        },
        toggleDrawer() {
            this.open = !this.open;
        },
        pulseBadge() {
            this.bumpPulse = true;
            setTimeout(() => { this.bumpPulse = false; }, 280);
        },
    });

    Alpine.data('assistantWidget', (chatUrl) => ({
        open: false,
        loading: false,
        message: '',
        messages: [
            { role: 'assistant', text: 'مرحبًا، أنا مساعد حاسم. كيف يمكنني مساعدتك اليوم؟' },
        ],
        x: 24,
        y: null,
        dragging: false,
        moved: false,
        offsetX: 0,
        offsetY: 0,
        init() {
            const storedX = Number.parseFloat(localStorage.getItem('assistant-widget-x') || '');
            const storedY = Number.parseFloat(localStorage.getItem('assistant-widget-y') || '');

            if (Number.isFinite(storedX)) this.x = storedX;
            if (Number.isFinite(storedY)) this.y = storedY;

            if (!Number.isFinite(this.y)) {
                this.y = Math.max(24, window.innerHeight - 96);
            }

            this.clampToViewport();
            window.addEventListener('resize', () => this.clampToViewport());
        },
        launcherStyle() {
            return `left:${this.x}px;top:${this.y}px;`;
        },
        startDrag(event) {
            this.dragging = true;
            this.moved = false;
            this.offsetX = event.clientX - this.x;
            this.offsetY = event.clientY - this.y;
        },
        onDrag(event) {
            if (!this.dragging) return;
            this.moved = true;
            this.x = event.clientX - this.offsetX;
            this.y = event.clientY - this.offsetY;
            this.clampToViewport();
        },
        endDrag() {
            if (!this.dragging) return;
            this.dragging = false;
            localStorage.setItem('assistant-widget-x', String(this.x));
            localStorage.setItem('assistant-widget-y', String(this.y));
        },
        toggleFromLauncher() {
            if (this.moved) {
                this.moved = false;
                return;
            }
            this.open = !this.open;
        },
        clampToViewport() {
            const width = window.innerWidth;
            const height = window.innerHeight;
            const bubble = 56;
            const margin = 12;
            this.x = Math.min(Math.max(margin, this.x), Math.max(margin, width - bubble - margin));
            this.y = Math.min(Math.max(margin, this.y ?? margin), Math.max(margin, height - bubble - margin));
        },
        async send() {
            const text = this.message.trim();
            if (!text || this.loading) return;

            this.messages.push({ role: 'user', text });
            this.message = '';
            this.loading = true;

            try {
                const response = await fetch(chatUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    },
                    body: JSON.stringify({ message: text }),
                });

                const payload = await response.json();
                const reply = payload?.data?.reply || 'تعذر إنشاء الرد الآن. حاول مرة أخرى.';
                this.messages.push({ role: 'assistant', text: reply });
            } catch (error) {
                this.messages.push({ role: 'assistant', text: 'حدث خطأ أثناء الاتصال بالمساعد. حاول مجددًا.' });
            } finally {
                this.loading = false;
                this.$nextTick(() => {
                    if (this.$refs.log) {
                        this.$refs.log.scrollTop = this.$refs.log.scrollHeight;
                    }
                });
            }
        },
    }));
});

Alpine.start();

<x-communication-layout>
    @php
        $channelOrder = ['whatsapp', 'instagram', 'facebook_messenger', 'email'];
        $orderedChannels = collect($channelOrder)
            ->map(fn (string $key) => collect($channels)->firstWhere('key', $key))
            ->filter()
            ->values();

        $channelCopy = [
            'whatsapp' => 'اربط حساب WhatsApp Business لاستقبال وإدارة المحادثات.',
            'instagram' => 'اربط حساب Instagram لاستقبال الرسائل والتعليقات.',
            'facebook_messenger' => 'اربط صفحة Facebook لاستقبال وإدارة المحادثات.',
            'email' => 'اربط حساب البريد الإلكتروني لاستقبال وإرسال الرسائل.',
        ];

        $whatsappConfigMissing = $metaAppId === '' || $metaConfigId === '';
    @endphp

    <div class="mx-auto max-w-6xl space-y-5">
        @include('workspace.partials.nav')
        @include('partials.flash')

        <div class="flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-bold text-slate-900">Channels</h1>
                <p class="mt-1 text-sm text-slate-500">إدارة وربط قنوات التواصل في مكان واحد.</p>
            </div>
            <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-[#E8FAF6] text-[#06C2A4]">
                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M8.288 15.038a5.25 5.25 0 0 1 7.424 0M5.106 11.856c3.807-3.808 9.98-3.808 13.788 0M1.924 8.674c5.565-5.565 14.587-5.565 20.152 0M12.53 18.22l-.53.53-.53-.53a.75.75 0 0 1 1.06 0Z" />
                </svg>
            </span>
        </div>

        <section class="rounded-2xl border border-[#C9F0E8] bg-[#F3FCFA] px-5 py-4 sm:px-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-base font-bold text-[#067e6b]">قنوات التواصل</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-600">
                        يمكن ربط جميع قنوات التواصل وإدارتها من مكان واحد لعرض المحادثات والرد عليها عبر الحسابات المضافة.
                    </p>
                </div>
                <div class="hidden items-center gap-2 text-[#06C2A4] sm:flex">
                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-white shadow-sm">
                        <svg viewBox="0 0 24 24" class="h-4 w-4" fill="currentColor"><path d="M17.5 6.5A7.42 7.42 0 0 0 12.2 4a7.7 7.7 0 0 0-6.66 11.52L4 20l4.63-1.5A7.7 7.7 0 0 0 12.2 20h.01a7.8 7.8 0 0 0 7.78-7.77A7.7 7.7 0 0 0 17.5 6.5Z" /></svg>
                    </span>
                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-white shadow-sm">
                        <svg viewBox="0 0 24 24" class="h-4 w-4" fill="currentColor"><path d="M7.5 2h9A5.5 5.5 0 0 1 22 7.5v9a5.5 5.5 0 0 1-5.5 5.5h-9A5.5 5.5 0 0 1 2 16.5v-9A5.5 5.5 0 0 1 7.5 2Zm4.5 5a5 5 0 1 1 0 10 5 5 0 0 1 0-10Z" /></svg>
                    </span>
                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-white shadow-sm">
                        <svg viewBox="0 0 24 24" class="h-4 w-4" fill="currentColor"><path d="M12 2C6.47 2 2 6.15 2 11.3c0 2.94 1.46 5.56 3.74 7.26V22l3.18-1.75c.99.27 2.03.42 3.08.42 5.53 0 10-4.15 10-9.3S17.53 2 12 2Z" /></svg>
                    </span>
                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-white shadow-sm">
                        <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M3 6.75A1.75 1.75 0 0 1 4.75 5h14.5A1.75 1.75 0 0 1 21 6.75v10.5A1.75 1.75 0 0 1 19.25 19H4.75A1.75 1.75 0 0 1 3 17.25V6.75Zm1.75.35L12 12l7.25-4.9" /></svg>
                    </span>
                </div>
            </div>
        </section>

        <div class="grid gap-4 md:grid-cols-2">
            @foreach($orderedChannels as $channel)
                @php
                    $connected = (bool) $channel['connected'];
                    $needsSetup = $channel['key'] === 'whatsapp' && ! $connected && $whatsappConfigMissing;
                    $statusLabel = $connected ? 'متصل' : ($needsSetup ? 'يحتاج إعداد' : 'غير متصل');
                    $statusClass = $connected
                        ? 'bg-emerald-50 text-emerald-700'
                        : ($needsSetup ? 'bg-amber-50 text-amber-700' : 'bg-slate-100 text-slate-600');
                    $statusDot = $connected
                        ? 'bg-emerald-500'
                        : ($needsSetup ? 'bg-amber-500' : 'bg-slate-400');
                    $iconWrap = $connected
                        ? 'bg-emerald-50 text-emerald-600'
                        : 'bg-slate-100 text-slate-500';
                @endphp
                <article class="flex h-full min-h-[220px] flex-col rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl {{ $iconWrap }}">
                                @switch($channel['icon'])
                                    @case('whatsapp')
                                        <svg viewBox="0 0 24 24" class="h-6 w-6" fill="currentColor" aria-hidden="true">
                                            <path d="M17.5 6.5A7.42 7.42 0 0 0 12.2 4a7.7 7.7 0 0 0-6.66 11.52L4 20l4.63-1.5A7.7 7.7 0 0 0 12.2 20h.01a7.8 7.8 0 0 0 7.78-7.77A7.7 7.7 0 0 0 17.5 6.5Zm-5.29 12.2a6.42 6.42 0 0 1-3.27-.9l-.24-.14-2.74.89.9-2.67-.15-.27a6.42 6.42 0 1 1 5.5 3.09Zm3.54-4.8c-.2-.1-1.18-.58-1.36-.65-.18-.06-.31-.1-.45.1-.13.2-.51.65-.63.78-.11.13-.23.15-.42.05-.2-.1-.82-.3-1.56-.95a5.8 5.8 0 0 1-1.08-1.34c-.11-.2-.01-.3.08-.4.09-.09.2-.23.3-.34.1-.12.13-.2.2-.33.07-.13.03-.25-.02-.35-.05-.1-.45-1.08-.62-1.48-.16-.39-.32-.33-.45-.34h-.38a.73.73 0 0 0-.53.25c-.18.2-.68.67-.68 1.64 0 .96.7 1.89.8 2.02.1.13 1.37 2.1 3.33 2.95.46.2.83.33 1.11.42.47.15.9.13 1.24.08.38-.06 1.18-.48 1.35-.95.17-.47.17-.87.12-.95-.05-.08-.18-.13-.38-.24Z" />
                                        </svg>
                                        @break
                                    @case('instagram')
                                        <svg viewBox="0 0 24 24" class="h-6 w-6" fill="currentColor" aria-hidden="true">
                                            <path d="M7.5 2h9A5.5 5.5 0 0 1 22 7.5v9a5.5 5.5 0 0 1-5.5 5.5h-9A5.5 5.5 0 0 1 2 16.5v-9A5.5 5.5 0 0 1 7.5 2Zm0 1.8A3.7 3.7 0 0 0 3.8 7.5v9a3.7 3.7 0 0 0 3.7 3.7h9a3.7 3.7 0 0 0 3.7-3.7v-9a3.7 3.7 0 0 0-3.7-3.7h-9Zm9.55 1.35a1.1 1.1 0 1 1 0 2.2 1.1 1.1 0 0 1 0-2.2ZM12 7a5 5 0 1 1 0 10 5 5 0 0 1 0-10Zm0 1.8a3.2 3.2 0 1 0 0 6.4 3.2 3.2 0 0 0 0-6.4Z" />
                                        </svg>
                                        @break
                                    @case('messenger')
                                        <svg viewBox="0 0 24 24" class="h-6 w-6" fill="currentColor" aria-hidden="true">
                                            <path d="M12 2C6.47 2 2 6.15 2 11.3c0 2.94 1.46 5.56 3.74 7.26V22l3.18-1.75c.99.27 2.03.42 3.08.42 5.53 0 10-4.15 10-9.3S17.53 2 12 2Zm.96 12.48-2.54-2.7-4.85 2.7 5.34-5.67 2.67 2.69 4.71-2.69-5.33 5.67Z" />
                                        </svg>
                                        @break
                                    @default
                                        <svg viewBox="0 0 24 24" class="h-6 w-6" fill="none" stroke="currentColor" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M3 6.75A1.75 1.75 0 0 1 4.75 5h14.5A1.75 1.75 0 0 1 21 6.75v10.5A1.75 1.75 0 0 1 19.25 19H4.75A1.75 1.75 0 0 1 3 17.25V6.75Zm1.75.35L12 12l7.25-4.9" />
                                        </svg>
                                @endswitch
                            </span>
                            <div class="min-w-0">
                                <h3 class="truncate text-base font-semibold text-slate-900">{{ $channel['name'] }}</h3>
                                <p class="mt-0.5 truncate text-xs text-slate-500">{{ $channel['hint'] }}</p>
                            </div>
                        </div>
                        <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold {{ $statusClass }}">
                            <span class="h-1.5 w-1.5 rounded-full {{ $statusDot }}"></span>
                            {{ $statusLabel }}
                        </span>
                    </div>

                    <p class="mt-4 text-sm leading-6 text-slate-600">
                        {{ $channelCopy[$channel['key']] ?? $channel['hint'] }}
                    </p>

                    @if($channel['key'] === 'whatsapp')
                        <p
                            data-whatsapp-feedback
                            @class([
                                'mt-3 rounded-xl px-3 py-2 text-[11px] leading-5',
                                'bg-rose-50 text-rose-700' => $whatsappConfigMissing,
                                'hidden' => ! $whatsappConfigMissing,
                            ])
                        >@if($whatsappConfigMissing)إعدادات Meta مفقودة. يرجى ضبط META_APP_ID و WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID.@endif</p>
                    @endif

                    <div class="mt-auto flex flex-wrap items-center justify-end gap-2 pt-5">
                        @if($channel['key'] === 'whatsapp')
                            <a href="{{ $channel['manage_url'] }}" class="hs-btn-secondary px-4 py-2 text-sm">
                                إدارة القناة
                            </a>
                            <button
                                type="button"
                                data-whatsapp-connect
                                data-meta-app-id="{{ $metaAppId }}"
                                data-meta-config-id="{{ $metaConfigId }}"
                                data-graph-version="{{ $graphApiVersion }}"
                                data-connect-url="{{ route('workspace.channels.whatsapp.connect') }}"
                                class="hs-btn-primary px-4 py-2 text-sm"
                            >
                                ربط WhatsApp
                                <span class="hidden">{{ $channel['primary_action'] }}</span>
                            </button>
                        @elseif($channel['key'] === 'email')
                            <a href="{{ $channel['manage_url'] }}" class="{{ $connected ? 'hs-btn-secondary' : 'hs-btn-primary' }} px-4 py-2 text-sm">
                                {{ $connected ? 'إدارة القناة' : 'ربط البريد الإلكتروني' }}
                            </a>
                        @else
                            <a href="{{ $channel['manage_url'] }}" class="hs-btn-secondary px-4 py-2 text-sm">
                                إدارة القناة
                            </a>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>

        <section class="flex flex-col gap-4 rounded-2xl border border-slate-200 bg-white px-5 py-4 shadow-sm sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-start gap-3">
                <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-[#E8FAF6] text-[#06C2A4]">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M12 3a7 7 0 0 0-7 7v2.2a2 2 0 0 1-.6 1.4L4 14.5h16l-.4-.9a2 2 0 0 1-.6-1.4V10a7 7 0 0 0-7-7Zm-3 16a3 3 0 0 0 6 0" />
                    </svg>
                </span>
                <div>
                    <h2 class="text-sm font-bold text-slate-900">هل تحتاج إلى مساعدة؟</h2>
                    <p class="mt-1 text-sm text-slate-500">راجع دليل الربط أو تواصل مع الدعم لإعداد القنوات.</p>
                </div>
            </div>
            @if(\Illuminate\Support\Facades\Route::has('workspace.whatsapp-accounts.index'))
                <a href="{{ route('workspace.whatsapp-accounts.index') }}" class="hs-btn-secondary shrink-0 px-4 py-2 text-sm">
                    زيارة مركز المساعدة
                </a>
            @endif
        </section>
    </div>

    <script>
        (function () {
            const connectButton = document.querySelector('[data-whatsapp-connect]');
            if (!connectButton) {
                return;
            }

            const feedbackEl = document.querySelector('[data-whatsapp-feedback]');
            const metaAppId = connectButton.dataset.metaAppId || '';
            const metaConfigId = connectButton.dataset.metaConfigId || '';
            const graphVersion = connectButton.dataset.graphVersion || 'v20.0';
            const connectUrl = connectButton.dataset.connectUrl || '';
            let sdkReady = false;
            let signupSessionInfo = {};

            function setFeedback(message, tone) {
                if (!feedbackEl) {
                    return;
                }
                feedbackEl.textContent = message;
                feedbackEl.classList.toggle('hidden', !message);
                feedbackEl.classList.remove('bg-rose-50', 'text-rose-700', 'bg-emerald-50', 'text-emerald-700', 'bg-slate-50', 'text-slate-600', 'text-emerald-600', 'text-rose-600', 'text-slate-500');
                if (tone === 'success') {
                    feedbackEl.classList.add('bg-emerald-50', 'text-emerald-700');
                } else if (tone === 'error') {
                    feedbackEl.classList.add('bg-rose-50', 'text-rose-700');
                } else {
                    feedbackEl.classList.add('bg-slate-50', 'text-slate-600');
                }
            }

            function setButtonLoading(loading) {
                connectButton.disabled = loading;
                connectButton.classList.toggle('opacity-60', loading);
                connectButton.classList.toggle('cursor-not-allowed', loading);
            }

            if (!metaAppId || !metaConfigId || !connectUrl) {
                connectButton.disabled = true;
                connectButton.classList.add('opacity-60', 'cursor-not-allowed');
                setFeedback('Meta App configuration is missing. Please set META_APP_ID and WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID.', 'error');
                return;
            }

            window.addEventListener('message', function (event) {
                if (!['https://www.facebook.com', 'https://web.facebook.com'].includes(event.origin)) {
                    return;
                }

                let payload = event.data;
                if (typeof payload === 'string') {
                    try {
                        payload = JSON.parse(payload);
                    } catch (error) {
                        return;
                    }
                }

                if (!payload || payload.type !== 'WA_EMBEDDED_SIGNUP') {
                    return;
                }

                if (payload.event === 'FINISH' && payload.data) {
                    signupSessionInfo = payload.data;
                }

                if (payload.event === 'CANCEL') {
                    setFeedback('تم إلغاء عملية ربط واتساب من نافذة Meta.', 'error');
                }
            });

            window.fbAsyncInit = function () {
                window.FB.init({
                    appId: metaAppId,
                    cookie: true,
                    xfbml: false,
                    version: graphVersion,
                });
                sdkReady = true;
                setFeedback('Meta SDK جاهز. يمكنك بدء ربط واتساب.', 'info');
            };

            const sdkScript = document.createElement('script');
            sdkScript.async = true;
            sdkScript.defer = true;
            sdkScript.src = 'https://connect.facebook.net/en_US/sdk.js';
            sdkScript.onerror = function () {
                setFeedback('تعذر تحميل Meta SDK. تحقق من الشبكة أو حظر السكربتات.', 'error');
            };
            document.head.appendChild(sdkScript);

            async function sendCodeToBackend(code) {
                setButtonLoading(true);
                setFeedback('جاري إتمام الربط مع Meta...', 'info');

                try {
                    const response = await fetch(connectUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        },
                        body: JSON.stringify({
                            code: code,
                            session_info: signupSessionInfo,
                        }),
                    });

                    const payload = await response.json();
                    if (!response.ok) {
                        throw new Error(payload.message || 'Meta connection failed.');
                    }

                    setFeedback('تم ربط واتساب بنجاح. جارٍ تحديث الحالة...', 'success');
                    window.setTimeout(function () {
                        window.location.reload();
                    }, 900);
                } catch (error) {
                    setFeedback(error.message || 'فشل ربط واتساب. حاول مرة أخرى.', 'error');
                } finally {
                    setButtonLoading(false);
                }
            }

            connectButton.addEventListener('click', function () {
                if (!sdkReady || typeof window.FB?.login !== 'function') {
                    setFeedback('Meta SDK غير جاهز بعد. أعد المحاولة خلال ثوانٍ.', 'error');
                    return;
                }

                window.FB.login(function (response) {
                    const code = response?.authResponse?.code;
                    if (!code) {
                        setFeedback('لم تكتمل عملية الربط أو تم إلغاؤها.', 'error');
                        return;
                    }

                    sendCodeToBackend(code);
                }, {
                    config_id: metaConfigId,
                    response_type: 'code',
                    override_default_response_type: true,
                    extras: {
                        setup: {},
                    },
                });
            });
        })();
    </script>
</x-communication-layout>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>ربط واتساب</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f8fafc; color: #0f172a; margin: 0; padding: 24px; }
        .card { max-width: 440px; margin: 40px auto; background: #fff; border: 1px solid #e2e8f0; border-radius: 20px; padding: 24px; }
        h1 { font-size: 20px; margin: 0 0 8px; }
        p { color: #64748b; line-height: 1.6; }
        button { width: 100%; border: 0; background: #06C2A4; color: #fff; font-weight: 700; border-radius: 12px; padding: 12px; margin-top: 16px; }
        button:disabled { opacity: .6; }
        .msg { margin-top: 12px; padding: 10px 12px; border-radius: 12px; font-size: 13px; display: none; }
        .msg.error { display: block; background: #fff1f2; color: #be123c; }
        .msg.info { display: block; background: #f1f5f9; color: #334155; }
        .msg.success { display: block; background: #ecfdf5; color: #047857; }
    </style>
</head>
<body>
    <div class="card">
        <h1>ربط واتساب</h1>
        <p>أكمل تسجيل Meta Embedded Signup ثم ستعود تلقائيًا إلى تطبيق حاسم.</p>
        <button type="button" data-whatsapp-connect
            data-meta-app-id="{{ $metaAppId }}"
            data-meta-config-id="{{ $metaConfigId }}"
            data-graph-version="{{ $graphApiVersion }}"
            data-connect-url="{{ $completeUrl }}">
            متابعة الربط
        </button>
        <div class="msg" data-whatsapp-feedback></div>
    </div>
    <script>
        (function () {
            const connectButton = document.querySelector('[data-whatsapp-connect]');
            const feedbackEl = document.querySelector('[data-whatsapp-feedback]');
            const metaAppId = connectButton.dataset.metaAppId || '';
            const metaConfigId = connectButton.dataset.metaConfigId || '';
            const graphVersion = connectButton.dataset.graphVersion || 'v20.0';
            const connectUrl = connectButton.dataset.connectUrl || '';
            let sdkReady = false;
            let signupSessionInfo = {};

            function setFeedback(message, tone) {
                feedbackEl.textContent = message || '';
                feedbackEl.className = 'msg' + (tone ? ' ' + tone : '');
            }

            if (!metaAppId || !metaConfigId || !connectUrl) {
                connectButton.disabled = true;
                setFeedback('إعدادات Meta غير مكتملة.', 'error');
                return;
            }

            window.addEventListener('message', function (event) {
                if (!['https://www.facebook.com', 'https://web.facebook.com'].includes(event.origin)) {
                    return;
                }
                let payload = event.data;
                if (typeof payload === 'string') {
                    try { payload = JSON.parse(payload); } catch (e) { return; }
                }
                if (!payload || payload.type !== 'WA_EMBEDDED_SIGNUP') {
                    return;
                }
                if (payload.event === 'FINISH' && payload.data) {
                    signupSessionInfo = payload.data;
                }
                if (payload.event === 'CANCEL') {
                    setFeedback('تم إلغاء عملية الربط.', 'error');
                }
            });

            window.fbAsyncInit = function () {
                window.FB.init({ appId: metaAppId, cookie: true, xfbml: false, version: graphVersion });
                sdkReady = true;
            };
            const sdkScript = document.createElement('script');
            sdkScript.async = true;
            sdkScript.defer = true;
            sdkScript.src = 'https://connect.facebook.net/en_US/sdk.js';
            document.head.appendChild(sdkScript);

            connectButton.addEventListener('click', function () {
                if (!sdkReady || typeof window.FB?.login !== 'function') {
                    setFeedback('Meta SDK غير جاهز بعد.', 'error');
                    return;
                }
                window.FB.login(function (response) {
                    const code = response?.authResponse?.code;
                    if (!code) {
                        setFeedback('لم تكتمل عملية الربط أو تم إلغاؤها.', 'error');
                        return;
                    }
                    connectButton.disabled = true;
                    setFeedback('جاري إتمام الربط...', 'info');
                    fetch(connectUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        },
                        body: JSON.stringify({ code: code, session_info: signupSessionInfo }),
                    }).then(async function (res) {
                        const payload = await res.json();
                        if (payload.redirect) {
                            window.location = payload.redirect;
                            return;
                        }
                        throw new Error(payload.message || 'فشل الربط.');
                    }).catch(function (error) {
                        connectButton.disabled = false;
                        setFeedback(error.message || 'فشل ربط واتساب.', 'error');
                    });
                }, {
                    config_id: metaConfigId,
                    response_type: 'code',
                    override_default_response_type: true,
                    extras: { setup: {} },
                });
            });
        })();
    </script>
</body>
</html>

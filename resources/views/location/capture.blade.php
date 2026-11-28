<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>تحديد موقع التوصيل — {{ $workspaceName ?? 'HASEM' }}</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
    <style>
        :root {
            --brand: #067E6B;
            --brand-dark: #056153;
            --brand-soft: #E8FAF6;
            --brand-border: #BEE9DF;
            --ink: #0F172A;
            --muted: #64748B;
            --bg: #F4F7F6;
            --card: #FFFFFF;
            --line: #E2E8F0;
            --warn-bg: #FFFBEB;
            --warn-ink: #92400E;
            --warn-border: #FDE68A;
            --danger-bg: #FEF2F2;
            --danger-ink: #991B1B;
            --danger-border: #FECACA;
        }
        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        body {
            margin: 0;
            min-height: 100dvh;
            background: var(--bg);
            color: var(--ink);
            font-family: 'Segoe UI', Tahoma, 'Noto Sans Arabic', system-ui, -apple-system, sans-serif;
            line-height: 1.5;
            padding: 12px 12px max(18px, env(safe-area-inset-bottom));
        }
        .shell {
            max-width: 560px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .header {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 18px;
            padding: 14px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            box-shadow: 0 2px 10px rgba(15, 23, 42, .04);
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }
        .brand-icon {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: var(--brand-soft);
            color: var(--brand);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 20px;
        }
        .brand h1 {
            margin: 0;
            font-size: 16px;
            font-weight: 800;
            color: var(--ink);
        }
        .brand p {
            margin: 2px 0 0;
            font-size: 12px;
            color: var(--muted);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            border-radius: 999px;
            padding: 4px 10px;
            font-size: 11px;
            font-weight: 700;
            background: var(--brand-soft);
            color: var(--brand);
            border: 1px solid var(--brand-border);
            white-space: nowrap;
        }
        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: 18px;
            padding: 16px;
            box-shadow: 0 2px 12px rgba(15, 23, 42, .04);
        }
        .search-row {
            display: flex;
            gap: 8px;
            margin-bottom: 10px;
        }
        .input {
            width: 100%;
            border: 1px solid #CBD5E1;
            border-radius: 12px;
            padding: 10px 12px;
            font-size: 14px;
            font-family: inherit;
            background: #FFFFFF;
            color: var(--ink);
            outline: none;
            transition: border-color .15s, box-shadow .15s;
        }
        .input:focus {
            border-color: var(--brand);
            box-shadow: 0 0 0 3px rgba(6, 126, 107, .12);
        }
        .btn {
            border: 0;
            border-radius: 12px;
            padding: 11px 16px;
            font-family: inherit;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: background .15s, opacity .15s, transform .08s;
            text-decoration: none;
        }
        .btn:active { transform: scale(.99); }
        .btn:disabled { opacity: .55; cursor: not-allowed; }
        .btn-primary { background: var(--brand); color: #FFFFFF; width: 100%; }
        .btn-primary:hover:not(:disabled) { background: var(--brand-dark); }
        .btn-gps {
            background: var(--brand-soft);
            color: var(--brand);
            border: 1px solid var(--brand-border);
            width: 100%;
            margin-bottom: 10px;
        }
        .btn-secondary {
            background: #F1F5F9;
            color: var(--ink);
            border: 1px solid var(--line);
            flex-shrink: 0;
        }
        #map-box {
            width: 100%;
            height: 300px;
            border-radius: 14px;
            border: 1px solid var(--line);
            overflow: hidden;
            position: relative;
            background: #E9F1EF;
        }
        .results-list {
            margin: 0 0 10px;
            padding: 0;
            list-style: none;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: #FFFFFF;
            max-height: 180px;
            overflow-y: auto;
        }
        .results-list li button {
            width: 100%;
            text-align: right;
            border: 0;
            border-bottom: 1px solid #F1F5F9;
            background: transparent;
            padding: 10px 12px;
            font-family: inherit;
            font-size: 13px;
            color: var(--ink);
            cursor: pointer;
        }
        .results-list li:last-child button { border-bottom: 0; }
        .results-list li button:hover { background: #F8FAFC; }
        .alert {
            border-radius: 12px;
            padding: 10px 12px;
            font-size: 13px;
            margin-bottom: 10px;
            display: none;
        }
        .alert.show { display: block; }
        .alert-warn { background: var(--warn-bg); color: var(--warn-ink); border: 1px solid var(--warn-border); }
        .alert-danger { background: var(--danger-bg); color: var(--danger-ink); border: 1px solid var(--danger-border); }
        .alert-success { background: var(--brand-soft); color: var(--brand-dark); border: 1px solid var(--brand-border); }
        .meta-panel {
            background: #F8FAFC;
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 12px;
            margin: 12px 0;
            font-size: 13px;
        }
        .meta-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 8px;
            margin-bottom: 6px;
        }
        .meta-row:last-child { margin-bottom: 0; }
        .meta-label { color: var(--muted); font-size: 12px; }
        .meta-val { font-weight: 700; color: var(--ink); text-align: left; }
        .coords { font-family: ui-monospace, monospace; font-size: 12px; direction: ltr; color: #334155; }
        .hint { font-size: 12px; color: var(--muted); margin: 6px 0 0; text-align: center; }
        .hidden { display: none !important; }
    </style>
</head>
<body>
    <div class="shell">
        <div class="header">
            <div class="brand">
                <div class="brand-icon" aria-hidden="true">📍</div>
                <div style="min-width:0">
                    <h1>تحديد موقع التوصيل</h1>
                    <p>{{ $workspaceName ?? 'HASEM AI' }}@if(! empty($orderNumber)) — طلب #{{ $orderNumber }}@endif</p>
                </div>
            </div>
            @if($state === 'usable')
                <span class="badge">تشفير آمن</span>
            @endif
        </div>

        @if($state === 'usable')
            <div id="capture-card" class="card">
                <button type="button" id="btn-gps" class="btn btn-gps">
                    <span>📡</span>
                    <span id="gps-btn-label">استخدم موقعي الحالي (GPS)</span>
                </button>

                <div class="search-row">
                    <input type="text" id="search-input" class="input" placeholder="ابحث عن اسم الحي أو الشارع أو المعلم..." autocomplete="off">
                    <button type="button" id="btn-search" class="btn btn-secondary">بحث</button>
                </div>
                <ul id="search-results" class="results-list hidden"></ul>

                <div id="status-alert" class="alert" role="alert"></div>

                <div id="map-box"></div>
                <p class="hint">اضغط على الخريطة أو اسحب الدبوس الأحمر لتعديل موقع التوصيل بدقة</p>

                <div class="meta-panel">
                    <div class="meta-row">
                        <span class="meta-label">العنوان المستخرج:</span>
                        <span class="meta-val" id="display-address">لم يتم تحديد موقع بعد</span>
                    </div>
                    <div class="meta-row">
                        <span class="meta-label">دقة التحديد:</span>
                        <span class="meta-val" id="display-accuracy">—</span>
                    </div>
                    <div class="meta-row">
                        <span class="meta-label">الإحداثيات:</span>
                        <span class="coords" id="display-coords">—</span>
                    </div>
                </div>

                <div style="margin-bottom: 12px;">
                    <label for="customer-notes" class="meta-label" style="display:block;margin-bottom:6px;font-weight:700;">
                        تفاصيل إضافية للسائق (اختياري)
                    </label>
                    <textarea id="customer-notes" rows="2" class="input" maxlength="500" placeholder="مثال: عمارة 14، الدور الثاني، بجانب المسجد..."></textarea>
                </div>

                <button type="button" id="btn-confirm" class="btn btn-primary" disabled>
                    <span>✅</span>
                    <span id="confirm-btn-label">تأكيد الموقع</span>
                </button>
            </div>

            <div id="confirmed-card" class="card hidden">
                <div style="text-align:center;padding:8px 0 14px;">
                    <div style="font-size:40px;">✅</div>
                    <h2 style="margin:6px 0 4px;font-size:18px;">تم تأكيد موقع التوصيل بنجاح</h2>
                    <p style="margin:0;font-size:13px;color:var(--muted);">تم إرسال الموقع للمتجر وربطه بالمحادثة، يمكنك العودة الآن للمحادثة.</p>
                </div>
                <div class="meta-panel">
                    <div class="meta-row">
                        <span class="meta-label">العنوان:</span>
                        <span class="meta-val" id="confirmed-address">—</span>
                    </div>
                    <div class="meta-row">
                        <span class="meta-label">الإحداثيات:</span>
                        <span class="coords" id="confirmed-coords">—</span>
                    </div>
                </div>
                <a id="confirmed-map-link" href="#" target="_blank" rel="noopener noreferrer" class="btn btn-gps" style="margin-bottom:0;">
                    فتح الموقع في خرائط جوجل ↗
                </a>
            </div>
        @elseif($state === 'locked')
            <div class="card">
                <div style="text-align:center;padding:6px 0 12px;">
                    <div style="font-size:38px;">🔒</div>
                    <h2 style="margin:6px 0 4px;font-size:18px;">تم تأكيد الموقع مسبقاً</h2>
                    <p style="margin:0;font-size:13px;color:var(--muted);">تم إقفال هذا الرابط بعد تأكيد موقعك لحماية بياناتك. لتحديث الموقع يرجى طلب رابط جديد داخل المحادثة.</p>
                </div>
                @if(! empty($existingLocation))
                    <div class="meta-panel">
                        <div class="meta-row">
                            <span class="meta-label">العنوان المؤكد:</span>
                            <span class="meta-val">{{ $existingLocation['formatted_address'] ?? 'موقع مسجل' }}</span>
                        </div>
                        <div class="meta-row">
                            <span class="meta-label">الحالة:</span>
                            <span class="meta-val">{{ $existingLocation['verification_label'] ?? 'مؤكد' }}</span>
                        </div>
                        <div class="meta-row">
                            <span class="meta-label">الإحداثيات:</span>
                            <span class="coords">{{ $existingLocation['latitude'] }}, {{ $existingLocation['longitude'] }}</span>
                        </div>
                    </div>
                    <a href="{{ $existingLocation['google_maps_url'] }}" target="_blank" rel="noopener noreferrer" class="btn btn-gps" style="margin-bottom:0;">
                        عرض في خرائط جوجل ↗
                    </a>
                @endif
            </div>
        @else
            <div class="card" style="text-align:center;padding:24px 16px;">
                <div style="font-size:42px;">{{ $state === 'session_expired' ? '⏳' : '⚠️' }}</div>
                <h2 style="margin:8px 0 6px;font-size:18px;">{{ $state === 'session_expired' ? 'انتهت صلاحية الرابط' : 'الرابط غير متاح' }}</h2>
                <p style="margin:0;font-size:14px;color:var(--muted);">{{ $errorMessage ?? 'تعذر فتح رابط تحديد الموقع.' }}</p>
            </div>
        @endif
    </div>

    @if($state === 'usable' && ! empty($configPayload))
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
        <script>
            (function () {
                const cfg = @json($configPayload);
                const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

                const state = {
                    lat: null,
                    lng: null,
                    accuracy: null,
                    source: 'manual_pin',
                    pinSelected: false,
                    formattedAddress: null,
                    placeName: null,
                    reverseTimer: null,
                };

                const alertEl = document.getElementById('status-alert');
                const addrEl = document.getElementById('display-address');
                const accEl = document.getElementById('display-accuracy');
                const coordsEl = document.getElementById('display-coords');
                const confirmBtn = document.getElementById('btn-confirm');
                const gpsBtn = document.getElementById('btn-gps');
                const gpsLabel = document.getElementById('gps-btn-label');
                const searchInput = document.getElementById('search-input');
                const searchBtn = document.getElementById('btn-search');
                const searchList = document.getElementById('search-results');

                function showAlert(kind, msg) {
                    alertEl.className = `alert show alert-${kind}`;
                    alertEl.textContent = msg;
                }

                function hideAlert() {
                    alertEl.className = 'alert';
                    alertEl.textContent = '';
                }

                let map = null;
                let marker = null;
                let circle = null;

                function initMap() {
                    if (typeof L === 'undefined') {
                        document.getElementById('map-box').innerHTML =
                            '<div style="display:flex;align-items:center;justify-content:center;height:100%;padding:16px;text-align:center;color:#475569;font-size:13px;">' +
                            'تعذر تحميل عرض الخريطة التفاعلية الآن. يمكنك استخدام زر GPS أعلاه أو البحث عن الحي لتحديد الموقع.' +
                            '</div>';
                        return;
                    }

                    map = L.map('map-box', { zoomControl: true }).setView([cfg.defaultLat, cfg.defaultLng], 12);
                    L.tileLayer(cfg.tileUrl, {
                        maxZoom: 19,
                        attribution: '&copy; OpenStreetMap'
                    }).addTo(map);

                    map.on('click', function (ev) {
                        const hadGps = state.source === 'gps' || state.source === 'gps_adjusted';
                        setPin(ev.latlng.lat, ev.latlng.lng, null, hadGps ? 'gps_adjusted' : 'manual_pin', false);
                    });
                }

                function setPin(lat, lng, accuracy, source, recenter) {
                    state.lat = Number(lat);
                    state.lng = Number(lng);
                    state.accuracy = accuracy !== null && accuracy !== undefined ? Math.round(Number(accuracy)) : null;
                    state.source = source || 'manual_pin';
                    state.pinSelected = true;

                    confirmBtn.disabled = false;
                    coordsEl.textContent = `${state.lat.toFixed(6)}, ${state.lng.toFixed(6)}`;

                    if (state.accuracy !== null) {
                        if (state.accuracy <= 50) {
                            accEl.textContent = `ممتازة (±${state.accuracy} م)`;
                            hideAlert();
                        } else if (state.accuracy <= cfg.weakAccuracyMeters) {
                            accEl.textContent = `جيدة (±${state.accuracy} م)`;
                            hideAlert();
                        } else {
                            accEl.textContent = `منخفضة (±${state.accuracy} م)`;
                            showAlert('warn', `دقة GPS منخفضة (±${state.accuracy} متر). يُفضّل تحريك الدبوس على الخريطة للتأكد من موقع المبنى.`);
                        }
                    } else {
                        accEl.textContent = state.source === 'manual_pin' ? 'تحديد يدوي' : 'عبر البحث';
                    }

                    if (map && typeof L !== 'undefined') {
                        if (!marker) {
                            marker = L.marker([state.lat, state.lng], { draggable: true }).addTo(map);
                            marker.on('dragend', function (ev) {
                                const pos = ev.target.getLatLng();
                                const hadGps = state.source === 'gps' || state.source === 'gps_adjusted';
                                setPin(pos.lat, pos.lng, null, hadGps ? 'gps_adjusted' : 'manual_pin', false);
                            });
                        } else {
                            marker.setLatLng([state.lat, state.lng]);
                        }

                        if (circle) {
                            map.removeLayer(circle);
                            circle = null;
                        }
                        if (state.accuracy !== null && state.accuracy > 0) {
                            circle = L.circle([state.lat, state.lng], {
                                radius: Math.min(state.accuracy, 1500),
                                color: '#067E6B',
                                fillColor: '#067E6B',
                                fillOpacity: 0.12,
                                weight: 1
                            }).addTo(map);
                        }

                        if (recenter) {
                            map.setView([state.lat, state.lng], 16, { animate: true });
                        }
                    }

                    scheduleReverseLookup(state.lat, state.lng);
                }

                function scheduleReverseLookup(lat, lng) {
                    clearTimeout(state.reverseTimer);
                    addrEl.textContent = 'جاري قراءة اسم الحي والشارع...';
                    state.reverseTimer = setTimeout(async () => {
                        try {
                            const url = new URL(cfg.reverseUrl, window.location.origin);
                            url.searchParams.set('lat', lat.toFixed(7));
                            url.searchParams.set('lng', lng.toFixed(7));
                            const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
                            if (!res.ok) throw new Error('reverse_http');
                            const data = await res.json();
                            state.formattedAddress = data.formatted_address || null;
                            state.placeName = data.place_name || null;
                            addrEl.textContent = data.formatted_address || `${lat.toFixed(5)}, ${lng.toFixed(5)}`;
                        } catch (_) {
                            state.formattedAddress = null;
                            addrEl.textContent = `إحداثيات (${lat.toFixed(5)}, ${lng.toFixed(5)})`;
                        }
                    }, 300);
                }

                gpsBtn.addEventListener('click', function () {
                    if (!navigator.geolocation) {
                        showAlert('danger', 'المتصفح الحالي لا يدعم خدمة تحديد الموقع التلقائي. يمكنك البحث عن الحي أو الضغط على الخريطة.');
                        return;
                    }
                    gpsBtn.disabled = true;
                    gpsLabel.textContent = 'جاري التقاط موقعك عبر GPS...';
                    hideAlert();

                    navigator.geolocation.getCurrentPosition(
                        function (pos) {
                            gpsBtn.disabled = false;
                            gpsLabel.textContent = 'تحديث موقعي الحالي (GPS)';
                            setPin(pos.coords.latitude, pos.coords.longitude, pos.coords.accuracy, 'gps', true);
                        },
                        function (err) {
                            gpsBtn.disabled = false;
                            gpsLabel.textContent = 'إعادة المحاولة عبر GPS';
                            if (err && err.code === 1) {
                                showAlert('warn', 'تم رفض إذن الموقع. يرجى تفعيل الموقع أو البحث عن الحي وتحديد الدبوس يدوياً على الخريطة.');
                            } else {
                                showAlert('warn', 'تعذر التقاط إشارة GPS الآن. يمكنك تحريك الدبوس يدوياً على الخريطة أو إعادة المحاولة.');
                            }
                        },
                        { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 }
                    );
                });

                async function runSearch() {
                    const q = searchInput.value.trim();
                    if (q.length < 2) {
                        showAlert('warn', 'اكتب حرفين على الأقل للبحث عن الحي أو الشارع.');
                        return;
                    }
                    searchBtn.disabled = true;
                    searchList.innerHTML = '';
                    searchList.classList.add('hidden');
                    hideAlert();
                    try {
                        const url = new URL(cfg.searchUrl, window.location.origin);
                        url.searchParams.set('q', q);
                        const res = await fetch(url.toString(), { headers: { 'Accept': 'application/json' } });
                        const data = await res.json();
                        const items = Array.isArray(data.results) ? data.results : [];
                        if (items.length === 0) {
                            showAlert('warn', 'لم يتم العثور على نتائج مطابقة، جرّب اسماً آخر أو حرّك الدبوس على الخريطة.');
                            return;
                        }
                        items.forEach((item) => {
                            const li = document.createElement('li');
                            const btn = document.createElement('button');
                            btn.type = 'button';
                            btn.textContent = item.display_name;
                            btn.addEventListener('click', () => {
                                searchList.classList.add('hidden');
                                setPin(item.latitude, item.longitude, null, 'search_pin', true);
                                state.formattedAddress = item.display_name;
                                addrEl.textContent = item.display_name;
                            });
                            li.appendChild(btn);
                            searchList.appendChild(li);
                        });
                        searchList.classList.remove('hidden');
                    } catch (_) {
                        showAlert('warn', 'تعذر البحث الآن، يمكنك الضغط مباشرة على الخريطة.');
                    } finally {
                        searchBtn.disabled = false;
                    }
                }

                searchBtn.addEventListener('click', runSearch);
                searchInput.addEventListener('keydown', function (ev) {
                    if (ev.key === 'Enter') {
                        ev.preventDefault();
                        runSearch();
                    }
                });

                confirmBtn.addEventListener('click', async function () {
                    if (!state.pinSelected || state.lat === null || state.lng === null) {
                        showAlert('warn', 'يرجى تحديد موقعك على الخريطة أولاً.');
                        return;
                    }

                    confirmBtn.disabled = true;
                    document.getElementById('confirm-btn-label').textContent = 'جاري تأكيد الموقع...';
                    hideAlert();

                    try {
                        const res = await fetch(cfg.confirmUrl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'Accept': 'application/json',
                                'X-CSRF-TOKEN': csrf
                            },
                            body: JSON.stringify({
                                latitude: state.lat,
                                longitude: state.lng,
                                accuracy_meters: state.accuracy,
                                source: state.source,
                                confirm: true,
                                customer_notes: document.getElementById('customer-notes').value.trim() || null,
                                formatted_address: state.formattedAddress,
                                place_name: state.placeName
                            })
                        });

                        const data = await res.json();
                        if (!res.ok || !data.success) {
                            showAlert('danger', data.message || 'تعذر حفظ الموقع، حاول مرة أخرى.');
                            confirmBtn.disabled = false;
                            document.getElementById('confirm-btn-label').textContent = 'تأكيد الموقع';
                            return;
                        }

                        const loc = data.location || {};
                        document.getElementById('capture-card').classList.add('hidden');
                        document.getElementById('confirmed-card').classList.remove('hidden');
                        document.getElementById('confirmed-address').textContent = loc.formatted_address || addrEl.textContent;
                        document.getElementById('confirmed-coords').textContent =
                            `${Number(loc.latitude || state.lat).toFixed(6)}, ${Number(loc.longitude || state.lng).toFixed(6)}`;
                        document.getElementById('confirmed-map-link').href =
                            loc.google_maps_url || `https://www.google.com/maps?q=${state.lat},${state.lng}`;
                    } catch (_) {
                        showAlert('danger', 'حدث خطأ في الاتصال بالشبكة. يرجى المحاولة مرة أخرى.');
                        confirmBtn.disabled = false;
                        document.getElementById('confirm-btn-label').textContent = 'تأكيد الموقع';
                    }
                });

                initMap();
            })();
        </script>
    @endif
</body>
</html>

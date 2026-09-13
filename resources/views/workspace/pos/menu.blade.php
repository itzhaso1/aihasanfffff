<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $workspace->name }} - Menu</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-100 text-slate-900">
    @php
        $groups = $items->groupBy(fn ($item) => $item->category?->name ?: ($item->item_type ?: 'عام'));
        $categoryKeys = $groups->keys()->values()->all();
        $defaultCategory = (string) ($categoryKeys[0] ?? '');
        $sliderImages = collect($sliderImages ?? [])->filter()->values();
        $sliderUrls = $sliderImages->map(fn ($path) => asset('storage/'.$path))->values();
        $orderRoute = $table
            ? route('menu.table.order', ['workspace' => $workspace->slug, 'token' => $table->qr_token])
            : route('menu.general.order', ['workspace' => $workspace->slug]);
        $aiRoute = $table
            ? route('menu.table.ai', ['workspace' => $workspace->slug, 'token' => $table->qr_token])
            : route('menu.general.ai', ['workspace' => $workspace->slug]);
    @endphp

    <div
        x-data="customerMenu({
            items: @js($items),
            aiRoute: @js($aiRoute),
            defaultCategory: @js($defaultCategory),
            sliderImages: @js($sliderUrls)
        })"
        @pos-cart-toggle.window="cartOpen = !cartOpen"
    >
        {{-- Menu navbar with cart --}}
        <header class="sticky top-0 z-40 border-b border-slate-200 bg-white/95 backdrop-blur" data-menu-navbar>
            <div class="mx-auto flex h-14 max-w-6xl items-center justify-between gap-3 px-3 sm:px-4">
                <div class="min-w-0">
                    <p class="truncate text-sm font-extrabold text-slate-900">{{ $workspace->name }}</p>
                    <p class="truncate text-[11px] text-slate-500">
                        @if($table)
                            طاولة {{ $table->name }}
                        @else
                            المنيو العام
                        @endif
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    @include('workspace.pos.partials.cart-nav-button')
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-6xl px-3 py-4 sm:px-4">
            @include('partials.flash')

            @if($table && (session('session_expired') || ($sessionExpired ?? false)))
                <section class="mb-3 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900" data-session-expired>
                    <p class="font-bold">انتهت جلسة الطاولة</p>
                    <p class="mt-1 text-xs">هذه الجلسة لم تعد متاحة. يمكنك بدء جلسة جديدة للطلب على {{ $table->name }}.</p>
                    <a
                        href="{{ route('menu.table', ['workspace' => $workspace->slug, 'token' => $table->qr_token, 'fresh' => 1]) }}"
                        class="mt-3 inline-flex rounded-xl bg-emerald-600 px-3 py-2 text-xs font-bold text-white"
                    >بدء جلسة جديدة</a>
                </section>
            @endif

            @if(session('payment_link'))
                <section class="mb-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                    تم تجهيز رابط الدفع الإلكتروني:
                    <a href="{{ session('payment_link') }}" class="font-bold underline" target="_blank" rel="noopener">{{ session('payment_link') }}</a>
                </section>
            @endif

            @if($sliderUrls->isNotEmpty())
                <section class="relative mb-4 overflow-hidden rounded-2xl border border-slate-200 bg-slate-950 shadow-sm">
                    <div class="relative h-36 sm:h-48">
                        <template x-for="(image, index) in sliderImages" :key="`slide-${index}`">
                            <img
                                :src="image"
                                alt="Menu Banner"
                                class="absolute inset-0 h-full w-full object-cover transition-opacity duration-500"
                                :class="currentSlide === index ? 'opacity-100' : 'opacity-0'"
                            />
                        </template>
                        <div class="absolute inset-0 bg-gradient-to-t from-slate-950/70 via-slate-900/20 to-transparent"></div>
                        <div class="absolute bottom-3 right-3 left-3 flex items-end justify-between">
                            <p class="text-xs font-bold text-white sm:text-sm">منيو سريع وواضح</p>
                            <div class="flex items-center gap-1.5">
                                <template x-for="(image, index) in sliderImages" :key="`dot-${index}`">
                                    <button type="button" @click="currentSlide = index" class="h-1.5 w-1.5 rounded-full" :class="currentSlide === index ? 'bg-white' : 'bg-white/40'"></button>
                                </template>
                            </div>
                        </div>
                    </div>
                </section>
            @endif

            <section class="mb-3 rounded-2xl border border-indigo-200 bg-indigo-50 p-3">
                <h2 class="text-sm font-bold text-indigo-900">مساعد المنيو (AI)</h2>
                <div class="mt-2 flex gap-2">
                    <input x-model="aiQuestion" type="text" class="flex-1 rounded-lg border-indigo-300 text-sm" placeholder="مثلاً: شو عندكم مشروبات اليوم؟" />
                    <button type="button" @click="askAi" class="rounded-lg bg-indigo-600 px-3 py-2 text-xs font-semibold text-white">اسأل</button>
                </div>
                <p class="mt-2 whitespace-pre-wrap text-xs text-indigo-900" x-text="aiAnswer"></p>
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white p-3 shadow-sm" data-menu-products>
                <h2 class="text-sm font-bold text-slate-900">الأقسام</h2>
                <div class="mt-3 flex gap-2 overflow-x-auto pb-1">
                    @foreach($groups as $typeName => $groupItems)
                        <button
                            type="button"
                            @click="selectedCategory = @js($typeName)"
                            class="whitespace-nowrap rounded-full border px-3 py-1.5 text-xs font-semibold transition"
                            :class="selectedCategory === @js($typeName) ? 'border-emerald-600 bg-emerald-600 text-white' : 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50'"
                        >
                            {{ $typeName }} ({{ $groupItems->count() }})
                        </button>
                    @endforeach
                </div>

                <div class="mt-3">
                    @forelse($groups as $typeName => $groupItems)
                        <section x-show="selectedCategory === @js($typeName)" x-cloak>
                            {{-- Compact: 2 mobile / 3 tablet / 4 desktop --}}
                            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4" data-menu-product-grid>
                                @foreach($groupItems as $item)
                                    @php($imagePath = $item->image_path ? asset('storage/'.$item->image_path) : null)
                                    <button
                                        type="button"
                                        @click="addItemById({{ $item->id }})"
                                        class="group flex flex-col overflow-hidden rounded-xl border border-slate-200 bg-white text-right transition hover:border-emerald-500 hover:shadow-sm active:scale-[0.98]"
                                        data-menu-product-card
                                    >
                                        <div class="relative aspect-[4/3] w-full bg-slate-50">
                                            @if($imagePath)
                                                <img src="{{ $imagePath }}" alt="{{ $item->name }}" class="h-full w-full object-cover" loading="lazy" />
                                            @else
                                                <div class="flex h-full w-full items-center justify-center text-slate-300">
                                                    <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                                </div>
                                            @endif
                                        </div>
                                        <div class="flex flex-1 flex-col gap-0.5 p-2">
                                            <p class="line-clamp-2 text-xs font-semibold leading-snug text-slate-900">{{ $item->name }}</p>
                                            @if($item->size_label)
                                                <p class="truncate text-[10px] text-slate-400">{{ $item->size_label }}</p>
                                            @endif
                                            <p class="text-xs font-bold text-slate-900">
                                                {{ number_format((float) $item->price, 2) }}
                                                <span class="text-[10px] font-medium text-slate-500">{{ $item->currency }}</span>
                                            </p>
                                            <span class="mt-1 inline-flex items-center justify-center rounded-lg border border-emerald-600 px-2 py-1 text-[10px] font-semibold text-emerald-700">+ إضافة</span>
                                        </div>
                                    </button>
                                @endforeach
                            </div>
                        </section>
                    @empty
                        <section class="rounded-2xl border border-slate-200 bg-slate-50 p-5 text-center text-sm text-slate-500">
                            لا توجد منتجات متاحة في المنيو حاليًا.
                        </section>
                    @endforelse
                </div>
            </section>
        </main>

        {{-- Cart drawer / bottom sheet --}}
        <div
            x-show="cartOpen || $store.posCartUi.open"
            x-cloak
            class="fixed inset-0 z-50"
            data-menu-cart-drawer
        >
            <div class="absolute inset-0 bg-slate-900/40" @click="closeCart()"></div>
            <aside class="absolute inset-x-0 bottom-0 max-h-[85vh] overflow-hidden rounded-t-2xl border border-slate-200 bg-white shadow-2xl sm:inset-y-0 sm:right-auto sm:left-0 sm:max-h-none sm:w-full sm:max-w-md sm:rounded-none sm:rounded-l-2xl">
                <div class="flex h-full flex-col">
                    <div class="flex items-center justify-between border-b border-slate-100 px-4 py-3">
                        <div>
                            <h2 class="text-sm font-bold text-slate-900">سلة الطلبات</h2>
                            <p class="text-[11px] text-slate-500"><span x-text="cartCount"></span> صنف</p>
                        </div>
                        <button type="button" @click="closeCart()" class="rounded-lg border border-slate-200 px-2 py-1 text-xs font-semibold text-slate-600">إغلاق</button>
                    </div>

                    <div class="flex-1 space-y-2 overflow-y-auto px-4 py-3">
                        <template x-if="cart.length === 0">
                            <p class="py-10 text-center text-sm text-slate-400">السلة فارغة.</p>
                        </template>
                        <template x-for="(line, index) in cart" :key="line.key">
                            <div class="rounded-xl border border-slate-100 bg-slate-50 p-2.5 text-xs">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="truncate font-semibold text-slate-800" x-text="line.name"></p>
                                        <p class="text-[11px] text-slate-500" x-text="line.size || ''"></p>
                                        <p class="mt-1 font-bold text-slate-900">
                                            <span x-text="money(line.unit_price)"></span>
                                            <span class="text-[10px] font-medium text-slate-500" x-text="line.currency"></span>
                                        </p>
                                    </div>
                                    <button type="button" @click="removeLine(index)" class="text-rose-600">حذف</button>
                                </div>
                                <div class="mt-2 flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <button type="button" @click="decrease(index)" class="h-7 w-7 rounded-lg border border-slate-200 bg-white text-sm font-bold">−</button>
                                        <span class="min-w-5 text-center font-semibold" x-text="line.quantity"></span>
                                        <button type="button" @click="increase(index)" class="h-7 w-7 rounded-lg border border-slate-200 bg-white text-sm font-bold">+</button>
                                    </div>
                                    <p class="font-semibold text-slate-900">
                                        <span x-text="money(line.quantity * line.unit_price)"></span>
                                        <span x-text="line.currency"></span>
                                    </p>
                                </div>
                            </div>
                        </template>
                    </div>

                    <div class="border-t border-slate-100 bg-white px-4 py-3">
                        <div class="mb-3 flex items-center justify-between text-sm">
                            <span class="font-semibold text-slate-600">الإجمالي</span>
                            <span class="font-extrabold text-emerald-700">
                                <span x-text="money(total)"></span>
                                <span class="text-xs" x-text="currencyLabel"></span>
                            </span>
                        </div>

                        <template x-if="!checkoutStep">
                            <div class="grid gap-2">
                                <button
                                    type="button"
                                    @click="startCheckout()"
                                    :disabled="cart.length === 0"
                                    class="w-full rounded-xl bg-emerald-600 px-3 py-2.5 text-sm font-bold text-white hover:bg-emerald-700 disabled:opacity-50"
                                >متابعة الطلب</button>
                                <button type="button" @click="clearCart()" :disabled="cart.length === 0" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-600 disabled:opacity-50">إفراغ السلة</button>
                            </div>
                        </template>

                        <template x-if="checkoutStep">
                            <form method="POST" action="{{ $orderRoute }}" @submit="prepareSubmit" class="space-y-2">
                                @csrf
                                @if($table && !empty($guestSessionToken))
                                    <input type="hidden" name="guest_session_token" value="{{ $guestSessionToken }}" />
                                @endif
                                <input name="customer_name" class="w-full rounded-lg border-slate-300 text-sm" placeholder="اسم العميل (اختياري)" />
                                <input name="customer_phone" class="w-full rounded-lg border-slate-300 text-sm" placeholder="رقم الجوال (اختياري)" />
                                <textarea name="notes" rows="2" class="w-full rounded-lg border-slate-300 text-sm" placeholder="ملاحظات الطلب"></textarea>

                                <div class="rounded-lg border border-slate-200 p-2 text-xs text-slate-700">
                                    <p class="mb-2 font-semibold">طريقة الدفع</p>
                                    <label class="flex items-center gap-2">
                                        <input type="radio" name="payment_method" value="pay_later" checked>
                                        الدفع عند الخروج
                                    </label>
                                    <label class="mt-2 flex items-center gap-2">
                                        <input type="radio" name="payment_method" value="pay_now">
                                        الدفع الآن
                                    </label>
                                </div>

                                <button class="w-full rounded-xl bg-emerald-600 px-3 py-2.5 text-sm font-bold text-white hover:bg-emerald-700">
                                    تأكيد الطلب
                                </button>
                                <button type="button" @click="checkoutStep = false" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-xs font-semibold text-slate-600">رجوع</button>
                            </form>
                        </template>
                    </div>
                </div>
            </aside>
        </div>
    </div>

    <script>
        function customerMenu({ items, aiRoute, defaultCategory, sliderImages }) {
            return {
                items,
                aiRoute,
                sliderImages: sliderImages || [],
                currentSlide: 0,
                slideTimer: null,
                selectedCategory: defaultCategory || '',
                aiQuestion: '',
                aiAnswer: '',
                cart: [],
                cartOpen: false,
                checkoutStep: false,
                clientReference: null,
                itemsById: {},
                init() {
                    this.itemsById = this.items.reduce((acc, item) => {
                        acc[String(item.id)] = item;
                        return acc;
                    }, {});
                    this.syncBadge();
                    if (this.sliderImages.length > 1) {
                        this.slideTimer = setInterval(() => this.nextSlide(), 4500);
                    }
                    this.$watch('cartOpen', (open) => {
                        if (window.Alpine?.store('posCartUi')) {
                            Alpine.store('posCartUi').open = open;
                        }
                    });
                },
                get cartCount() {
                    return this.cart.reduce((sum, line) => sum + Number(line.quantity || 0), 0);
                },
                syncBadge() {
                    if (window.Alpine?.store('posCartUi')) {
                        Alpine.store('posCartUi').setCount(this.cartCount);
                    }
                },
                closeCart() {
                    this.cartOpen = false;
                    this.checkoutStep = false;
                    if (window.Alpine?.store('posCartUi')) {
                        Alpine.store('posCartUi').closeDrawer();
                    }
                },
                openCart() {
                    this.cartOpen = true;
                    if (window.Alpine?.store('posCartUi')) {
                        Alpine.store('posCartUi').openDrawer();
                    }
                },
                feedback(name) {
                    window.HasoPosFeedback?.notifyItemAdded(name);
                    if (window.Alpine?.store('posCartUi')) {
                        Alpine.store('posCartUi').pulseBadge();
                    }
                },
                addItemById(itemId) {
                    const item = this.itemsById[String(itemId)];
                    if (!item) return;
                    this.addItem({
                        id: item.id,
                        name: item.name,
                        size: item.size_label,
                        price: Number(item.price || 0),
                        currency: item.currency || '',
                    });
                },
                addItem(item) {
                    const lineKey = `${item.id}:${item.size || ''}:${item.currency || ''}`;
                    const existing = this.cart.find((line) => line.key === lineKey);
                    if (existing) {
                        existing.quantity += 1;
                    } else {
                        this.cart.push({
                            key: lineKey,
                            pos_menu_item_id: item.id,
                            name: item.name,
                            size: item.size,
                            unit_price: Number(item.price || 0),
                            currency: item.currency || '',
                            quantity: 1,
                        });
                    }
                    this.syncBadge();
                    this.feedback(item.name);
                },
                increase(index) {
                    this.cart[index].quantity += 1;
                    this.syncBadge();
                },
                decrease(index) {
                    if (this.cart[index].quantity <= 1) {
                        this.cart.splice(index, 1);
                    } else {
                        this.cart[index].quantity -= 1;
                    }
                    this.syncBadge();
                },
                removeLine(index) {
                    this.cart.splice(index, 1);
                    this.syncBadge();
                },
                clearCart() {
                    this.cart = [];
                    this.checkoutStep = false;
                    this.syncBadge();
                },
                startCheckout() {
                    if (this.cart.length === 0) return;
                    this.clientReference = this.clientReference
                        || (window.crypto?.randomUUID?.() || (`menu-${Date.now()}-${Math.random().toString(16).slice(2)}`));
                    this.checkoutStep = true;
                },
                get total() {
                    return this.cart.reduce((sum, line) => sum + (line.quantity * line.unit_price), 0);
                },
                get currencyLabel() {
                    const currencies = [...new Set(this.cart.map((line) => line.currency).filter(Boolean))];
                    if (currencies.length === 0) return '';
                    if (currencies.length === 1) return currencies[0];
                    return 'MIX';
                },
                nextSlide() {
                    if (this.sliderImages.length <= 1) return;
                    this.currentSlide = (this.currentSlide + 1) % this.sliderImages.length;
                },
                prevSlide() {
                    if (this.sliderImages.length <= 1) return;
                    this.currentSlide = (this.currentSlide - 1 + this.sliderImages.length) % this.sliderImages.length;
                },
                money(amount) {
                    return Number(amount || 0).toFixed(2);
                },
                async askAi() {
                    if ((this.aiQuestion || '').trim() === '') return;
                    this.aiAnswer = 'جاري التحضير...';
                    try {
                        const response = await fetch(this.aiRoute, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                                'Accept': 'application/json',
                            },
                            body: JSON.stringify({ message: this.aiQuestion }),
                        });
                        const data = await response.json();
                        this.aiAnswer = data.answer || 'لا يوجد رد حالياً.';
                    } catch (e) {
                        this.aiAnswer = 'تعذر الوصول إلى المساعد الآن.';
                    }
                },
                prepareSubmit(event) {
                    if (this.cart.length === 0) {
                        event.preventDefault();
                        window.HasoPosFeedback?.showToast('اختر منتجًا واحدًا على الأقل.');
                        return;
                    }

                    if (!this.clientReference) {
                        this.clientReference = (window.crypto?.randomUUID?.() || (`menu-${Date.now()}-${Math.random().toString(16).slice(2)}`));
                    }

                    event.target.querySelectorAll('[data-cart-input]').forEach((node) => node.remove());

                    const refInput = document.createElement('input');
                    refInput.type = 'hidden';
                    refInput.name = 'client_reference';
                    refInput.value = this.clientReference;
                    refInput.dataset.cartInput = '1';
                    event.target.appendChild(refInput);

                    this.cart.forEach((line, index) => {
                        const itemInput = document.createElement('input');
                        itemInput.type = 'hidden';
                        itemInput.name = `items[${index}][pos_menu_item_id]`;
                        itemInput.value = line.pos_menu_item_id;
                        itemInput.dataset.cartInput = '1';
                        event.target.appendChild(itemInput);

                        const quantityInput = document.createElement('input');
                        quantityInput.type = 'hidden';
                        quantityInput.name = `items[${index}][quantity]`;
                        quantityInput.value = line.quantity;
                        quantityInput.dataset.cartInput = '1';
                        event.target.appendChild(quantityInput);
                    });
                },
            };
        }
    </script>
</body>
</html>

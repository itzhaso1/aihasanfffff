<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ config('app.name', 'HASEM') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-white text-gray-900 font-sans">
    <div class="min-h-screen">
        <header class="border-b border-gray-100">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-[#06C2A4]">حاسم</h1>
                    <p class="text-sm text-gray-600">منصة SaaS لإدارة المحادثات والذكاء الاصطناعي والتجارة</p>
                </div>
                <div class="flex items-center gap-2">
                    @auth
                        <a href="{{ route('workspace.choose') }}" class="px-4 py-2 rounded-md bg-blue-600 text-white hover:bg-blue-700">الدخول إلى المساحات</a>
                    @else
                        <a href="{{ route('login') }}" class="px-4 py-2 rounded-md border border-gray-300 text-gray-700 hover:bg-gray-50">تسجيل الدخول</a>
                        <a href="{{ route('register') }}" class="px-4 py-2 rounded-md bg-blue-600 text-white hover:bg-blue-700">إنشاء حساب</a>
                    @endauth
                </div>
            </div>
        </header>

        <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
            <section class="grid lg:grid-cols-2 gap-8 items-center">
                <div>
                    <h2 class="text-3xl font-bold leading-tight">منصة واحدة تدعم الأفراد والشركات والمتاجر</h2>
                    <p class="mt-4 text-gray-600 leading-7">
                        واجهات تشغيلية كاملة، عزل تام لمساحات العمل، REST API منظمة، ونظام قابل للتوسع
                        لإدارة المنتجات والمخزون والطلبات والمحادثات والذكاء الاصطناعي والمدفوعات.
                    </p>
                    <div class="mt-6 flex gap-3">
                        <a href="{{ route('register') }}" class="px-5 py-3 rounded-md bg-blue-600 text-white hover:bg-blue-700">ابدأ الآن</a>
                        <a href="{{ route('otp.login') }}" class="px-5 py-3 rounded-md border border-gray-300 hover:bg-gray-50">دخول OTP</a>
                    </div>
                </div>

                <div class="bg-gray-50 border border-gray-100 rounded-2xl p-6">
                    <h3 class="text-xl font-semibold mb-4">Core Modules</h3>
                    <ul class="grid grid-cols-2 gap-3 text-sm text-gray-700">
                        <li class="bg-white rounded-md p-3 border border-gray-100">Products</li>
                        <li class="bg-white rounded-md p-3 border border-gray-100">Inventory</li>
                        <li class="bg-white rounded-md p-3 border border-gray-100">Customers</li>
                        <li class="bg-white rounded-md p-3 border border-gray-100">Orders</li>
                        <li class="bg-white rounded-md p-3 border border-gray-100">Conversations</li>
                        <li class="bg-white rounded-md p-3 border border-gray-100">AI</li>
                        <li class="bg-white rounded-md p-3 border border-gray-100">WhatsApp</li>
                        <li class="bg-white rounded-md p-3 border border-gray-100">Payments</li>
                    </ul>
                </div>
            </section>
        </main>
    </div>
</body>
</html>

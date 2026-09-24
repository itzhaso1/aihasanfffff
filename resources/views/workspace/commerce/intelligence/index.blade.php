<x-communication-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">محرك الذكاء التجاري — Commerce Intelligence</h2></x-slot>
    <div class="py-8">
        <div class="mx-auto max-w-6xl px-4">
            @include('workspace.partials.nav')
            @if(session('status'))
                <div class="mb-4 rounded bg-green-100 p-3 text-sm text-green-800">{{ session('status') }}</div>
            @endif

            <div class="mb-6 flex gap-4 text-sm font-medium border-b pb-2">
                <a href="{{ route('workspace.commerce.intelligence.index') }}" class="border-b-2 border-indigo-600 pb-2 text-indigo-600">الملف التجاري</a>
                <a href="{{ route('workspace.commerce.intelligence.attributes') }}" class="text-gray-600 hover:text-indigo-600">تعاريف الخصائص ({{ $attributeCount }})</a>
                <a href="{{ route('workspace.commerce.intelligence.requirements') }}" class="text-gray-600 hover:text-indigo-600">المتطلبات ({{ $requirementCount }})</a>
                <a href="{{ route('workspace.commerce.intelligence.rules') }}" class="text-gray-600 hover:text-indigo-600">القواعد ({{ $ruleCount }})</a>
                <a href="{{ route('workspace.commerce.intelligence.compatibilities') }}" class="text-gray-600 hover:text-indigo-600">التوافقية ({{ $compatibilityCount }})</a>
            </div>

            <div class="rounded-xl border bg-white p-6 shadow-sm">
                <h3 class="text-lg font-bold mb-4">إعدادات ملف القطاع التجاري (Commerce Domain Profile)</h3>
                <form method="POST" action="{{ route('workspace.commerce.intelligence.profile.update') }}" class="space-y-4 max-w-xl">
                    @csrf
                    @method('PUT')
                    <div>
                        <label class="block text-sm font-medium text-gray-700">القطاع الرئيسي (Primary Domain)</label>
                        <select name="primary_domain" class="mt-1 block w-full rounded-md border-gray-300 text-sm">
                            @foreach($domains as $domain)
                                <option value="{{ $domain->value }}" @selected($profile->primary_domain?->value === $domain->value)>{{ $domain->name }} ({{ $domain->value }})</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">القدرات المفعّلة (Enabled Capabilities)</label>
                        <div class="space-y-2">
                            @foreach(['attributes' => 'خصائص المنتجات المطبقة (Attributes)', 'requirements' => 'متطلبات سياق العميل (Requirements)', 'compatibility' => 'فحص التوافقية (Compatibility)', 'rules' => 'قواعد الاستبعاد والتوصية (Rules)'] as $cap => $label)
                                <label class="flex items-center gap-2 text-sm text-gray-600">
                                    <input type="checkbox" name="enabled_capabilities[]" value="{{ $cap }}" @checked(in_array($cap, $profile->enabled_capabilities ?? []))>
                                    {{ $label }}
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div class="pt-4">
                        <button type="submit" class="rounded bg-indigo-600 px-4 py-2 text-sm text-white hover:bg-indigo-700">حفظ الملف التجاري</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-communication-layout>

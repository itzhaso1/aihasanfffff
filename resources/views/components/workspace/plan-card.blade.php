@props([
    'card',
    'canManageBilling' => false,
])

@php
    $plan = $card['plan'];
    $visual = $card['visual'];
    $icon = (string) ($visual['icon'] ?? 'rocket');
    $tierName = $visual['tier_label'] ?? $plan->name;
    $ctaClass = match ($icon) {
        'crown' => 'hs-plan-cta hs-plan-cta-pro',
        'briefcase' => 'hs-plan-cta hs-plan-cta-business',
        'building' => 'hs-plan-cta hs-plan-cta-enterprise',
        default => 'hs-plan-cta hs-plan-cta-starter',
    };
    $iconWrap = match ($icon) {
        'crown' => 'background:#FEF3C7;color:#E9890A;',
        'briefcase' => 'background:#E8F8F4;color:#0B9E88;',
        'building' => 'background:#EDE9FE;color:#7C3AED;',
        default => 'background:#DBEAFE;color:#2563EB;',
    };
    $cardClass = ($card['is_current'] || $card['is_popular'])
        ? 'hs-plan-card hs-plan-card-current'
        : 'hs-plan-card hs-plan-card-normal';
@endphp

@once
<style>
    .hs-plan-card { min-width: 0; width: 100%; max-width: 100%; border-radius: 32px; background: #fff; box-shadow: 0 10px 30px rgba(15, 23, 42, .05); }
    .hs-plan-card-current { border: 2px solid #06C2A4; background: #F3FCFA; }
    .hs-plan-card-normal { border: 1px solid #EEF2F6; }
    .hs-plan-icon { width: 44px; height: 44px; border-radius: 9999px; display: inline-flex; align-items: center; justify-content: center; }
    .hs-plan-select { width: 100%; border: 1px solid #E5E7EB; background: #fff; border-radius: 9999px; padding: .7rem 1rem; font-size: .875rem; font-weight: 600; color: #374151; }
    .hs-plan-cta { display: inline-flex; width: 100%; align-items: center; justify-content: center; gap: .5rem; border: 0; border-radius: 9999px; padding: .85rem 1rem; font-size: .875rem; font-weight: 800; color: #fff; cursor: pointer; }
    .hs-plan-cta-starter { background: #2563EB; }
    .hs-plan-cta-pro { background: #E9890A; }
    .hs-plan-cta-business { background: #0B9E88; }
    .hs-plan-cta-enterprise { background: #7C3AED; }
    .hs-plan-cta-current { background: #E8F8F4; color: #06C2A4; }
    .hs-plan-cta:hover { filter: brightness(1.05); }
    .hs-plan-card-header { display: flex; align-items: center; justify-content: space-between; gap: .75rem; }
    .hs-plan-card-name { font-size: .75rem; font-weight: 600; color: #9CA3AF; }
</style>
@endonce

<article {{ $attributes->merge(['class' => 'relative flex min-h-[380px] min-w-0 w-full flex-col p-6 '.$cardClass]) }}>
    @if($card['is_popular'] || $card['is_recommended'])
        <span class="absolute -top-3 left-1/2 z-10 -translate-x-1/2 rounded-full px-4 py-1 text-[11px] font-bold text-white" style="background:#06C2A4;">
            {{ $card['is_recommended'] ? __('الباقة المقترحة') : __('الأكثر شيوعاً') }}
        </span>
    @endif

    <div class="hs-plan-card-header">
        <span class="hs-plan-card-name">{{ $tierName }}</span>
        <span class="hs-plan-icon" style="{{ $iconWrap }}" aria-hidden="true">
            <x-workspace.plan-icon :name="$icon" class="h-5 w-5" />
        </span>
    </div>

    <h3 class="mt-5 text-[26px] font-extrabold leading-tight text-gray-900">
        {{ $plan->display_name_ar ?: $plan->name }}
    </h3>

    <p class="mt-4" style="color:#06C2A4;">
        <span class="text-sm font-semibold">{{ $plan->currency }}</span>
        <span class="text-[34px] font-extrabold leading-none">{{ number_format((float) $plan->price, 2) }}</span>
        <span class="text-xs text-gray-400"> / {{ $card['period_label'] }}</span>
    </p>

    @if($plan->description)
        <p class="mt-3 line-clamp-3 text-sm leading-6 text-gray-500">{{ $plan->description }}</p>
    @endif

    <div class="mt-auto space-y-3 pt-6">
        @if($card['is_current'])
            <div class="hs-plan-cta hs-plan-cta-current" role="status">
                {{ __('باقتك الحالية') }}
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                </svg>
            </div>
        @elseif($card['cta']['can_submit'] && $canManageBilling)
            <p class="text-xs font-semibold text-gray-400">{{ __('بوابة الدفع') }}</p>
            <select class="hs-plan-select" aria-label="{{ __('بوابة الدفع') }}">
                <option value="hyperpay" selected>HyperPay</option>
            </select>
            <form method="POST" action="{{ route('workspace.subscriptions.store') }}">
                @csrf
                <input type="hidden" name="plan_id" value="{{ $plan->id }}">
                <button type="submit" class="{{ $ctaClass }}">
                    <span>{{ $card['cta']['label'] }}</span>
                    <x-workspace.plan-icon name="arrow-left" class="h-4 w-4 shrink-0" />
                </button>
            </form>
        @elseif(! $canManageBilling)
            <p class="inline-flex w-full items-center justify-center rounded-full bg-gray-100 px-4 py-3.5 text-sm font-semibold text-gray-500">
                {{ __('لا تملك صلاحية تغيير الباقة') }}
            </p>
        @endif
    </div>
</article>

<?php

namespace App\Support\Ui;

use App\Services\Worker\IndependentWorkerWebBoundary;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

class CommunicationNav
{
    /**
     * @return array{
     *     topLinks: Collection<int, array<string, mixed>>,
     *     secondaryLinks: Collection<int, array<string, mixed>>,
     *     settingsLink: array{label: string, route: string, active: string, icon: string}|null,
     *     icons: array<string, string>,
     *     isCommunicationModule: bool
     * }
     */
    public static function data(): array
    {
        $topLinks = collect([
            ['label' => 'الرئيسية', 'route' => 'workspace.dashboard', 'active' => 'workspace.dashboard', 'icon' => 'home'],
            ['label' => 'التواصل', 'route' => 'workspace.conversations.index', 'active' => 'workspace.conversations.*', 'icon' => 'chat', 'module' => true],
            ['label' => 'العملاء', 'route' => 'workspace.customers.index', 'active' => 'workspace.customers.*', 'icon' => 'users'],
            ['label' => 'المنتجات والمخزون', 'route' => 'workspace.chat-catalog.index', 'active' => 'workspace.chat-catalog.*', 'icon' => 'cube'],
            ['label' => 'التجارة', 'route' => 'workspace.commerce.orders.index', 'active' => 'workspace.commerce.*', 'icon' => 'box'],
            ['label' => 'التقارير', 'route' => 'workspace.analytics.index', 'active' => 'workspace.analytics.*', 'icon' => 'chart'],
            ['label' => 'الإعدادات', 'route' => 'workspace.ai-settings.edit', 'active' => ['workspace.ai-settings.*', 'workspace.payments.*', 'workspace.subscriptions.*', 'workspace.employees.*', 'profile.*'], 'icon' => 'settings'],
        ])->filter(fn (array $link): bool => Route::has($link['route']))->values();

        $secondaryLinks = collect([
            ['label' => 'المحادثات', 'route' => 'workspace.conversations.index', 'active' => 'workspace.conversations.*', 'icon' => 'chat', 'excludeQuery' => ['status' => 'archived']],
            ['label' => 'مؤرشفة', 'route' => 'workspace.conversations.index', 'active' => 'workspace.conversations.*', 'icon' => 'archive', 'query' => ['status' => 'archived'], 'nav' => 'archive'],
            ['label' => 'العملاء', 'route' => 'workspace.customers.index', 'active' => 'workspace.customers.*', 'icon' => 'users'],
            ['label' => 'القنوات', 'route' => 'workspace.channels.index', 'active' => 'workspace.channels.*', 'icon' => 'channels'],
            ['label' => 'واتساب', 'route' => 'workspace.whatsapp-accounts.index', 'active' => 'workspace.whatsapp-accounts.*', 'icon' => 'whatsapp'],
            ['label' => 'المنتجات والمخزون', 'route' => 'workspace.chat-catalog.index', 'active' => 'workspace.chat-catalog.*', 'icon' => 'cube'],
            ['label' => 'البريد', 'route' => 'workspace.emails.index', 'active' => 'workspace.emails.*', 'icon' => 'mail'],
        ])->filter(fn (array $link): bool => Route::has($link['route']))->values();

        return [
            'topLinks' => $topLinks,
            'secondaryLinks' => $secondaryLinks,
            'settingsLink' => self::settingsLink(),
            'icons' => self::icons(),
            'isCommunicationModule' => request()->routeIs([
                'workspace.conversations.*',
                'workspace.chat-catalog.*',
                'workspace.customers.*',
                'workspace.channels.*',
                'workspace.emails.*',
                'workspace.whatsapp-accounts.*',
            ]),
        ];
    }

    public static function linkIsActive(array $link, bool $isCommunicationModule): bool
    {
        $patterns = (array) ($link['active'] ?? $link['route']);
        $matches = request()->routeIs(...$patterns);

        if (! empty($link['module'])) {
            return $isCommunicationModule;
        }

        if ($isCommunicationModule && $link['route'] === 'workspace.customers.index') {
            return false;
        }

        return $matches;
    }

    /**
     * @param  array<string, mixed>  $link
     */
    public static function secondaryHref(array $link): string
    {
        return route($link['route'], $link['query'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $link
     */
    public static function secondaryLinkIsActive(array $link): bool
    {
        if (! request()->routeIs(...(array) ($link['active'] ?? $link['route']))) {
            return false;
        }

        foreach ($link['query'] ?? [] as $key => $value) {
            if (request()->string((string) $key)->toString() !== (string) $value) {
                return false;
            }
        }

        foreach ($link['excludeQuery'] ?? [] as $key => $value) {
            if (request()->string((string) $key)->toString() === (string) $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{label: string, route: string, active: string, icon: string}|null
     */
    private static function settingsLink(): ?array
    {
        if (app(IndependentWorkerWebBoundary::class)->isIndependentWorker(auth()->user())) {
            return null;
        }

        if (! Route::has('workspace.ai-settings.edit')) {
            return null;
        }

        return [
            'label' => 'الإعدادات',
            'route' => 'workspace.ai-settings.edit',
            'active' => 'workspace.ai-settings.*',
            'icon' => 'settings',
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function icons(): array
    {
        return [
            'home' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M3 10.75 12 3l9 7.75V21a1 1 0 0 1-1 1h-5.5v-7h-5v7H4a1 1 0 0 1-1-1V10.75Z" />',
            'chat' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M7.5 17.25H4.875A1.875 1.875 0 0 1 3 15.375V5.625A1.875 1.875 0 0 1 4.875 3.75h14.25A1.875 1.875 0 0 1 21 5.625v9.75a1.875 1.875 0 0 1-1.875 1.875H12l-4.5 3v-3Z" />',
            'users' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M16 19.5v-1.125A3.375 3.375 0 0 0 12.625 15h-4.25A3.375 3.375 0 0 0 5 18.375V19.5m11-9.375a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Zm-8.75 0a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />',
            'box' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="m3.75 7.5 8.25-4.5 8.25 4.5m-16.5 0 8.25 4.5m8.25-4.5v9L12 21.75m0-9.75v9.75" />',
            'cube' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M21 7.5 12 3 3 7.5m18 0-9 4.5m9-4.5v9L12 21m0-9.5L3 7.5m9 4v9.5m0 0L3 16.5v-9" />',
            'chart' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M3 19.5h18M6.75 16.5V10.5m5.25 6V6.75m5.25 9.75V8.25" />',
            'settings' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />',
            'tag' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M9.568 3H5.25A2.25 2.25 0 0 0 3 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 0 0 5.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 0 0 9.568 3Z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M6 6h.008v.008H6V6Z" />',
            'channels' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M8.288 15.038a5.25 5.25 0 0 1 7.424 0M5.106 11.856c3.807-3.808 9.98-3.808 13.788 0M1.924 8.674c5.565-5.565 14.587-5.565 20.152 0M12.53 18.22l-.53.53-.53-.53a.75.75 0 0 1 1.06 0Z" />',
            'mail' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M3 6.75A1.75 1.75 0 0 1 4.75 5h14.5A1.75 1.75 0 0 1 21 6.75v10.5A1.75 1.75 0 0 1 19.25 19H4.75A1.75 1.75 0 0 1 3 17.25V6.75Zm1.75.35L12 12l7.25-4.9" />',
            'whatsapp' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M8.25 9.75c.75 2.25 2.75 4.25 5 5l1.5-1.5a1.5 1.5 0 0 1 1.5-.37l3.12.94A1.5 1.5 0 0 1 20.5 15.3v2.45A1.75 1.75 0 0 1 18.6 19.5 16.1 16.1 0 0 1 4.5 5.4 1.75 1.75 0 0 1 6.25 3.5H8.7a1.5 1.5 0 0 1 1.43 1.13l.94 3.12a1.5 1.5 0 0 1-.37 1.5L8.25 9.75Z" />',
            'archive' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125ZM4.5 7.5V18.75A1.5 1.5 0 0 0 6 20.25h12a1.5 1.5 0 0 0 1.5-1.5V7.5M9.75 12h4.5" />',
        ];
    }
}

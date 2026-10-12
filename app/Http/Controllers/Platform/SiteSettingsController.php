<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\UpdatePublicSiteRequest;
use App\Services\Platform\PublicSiteSettingsService;
use App\Support\Site\PublicSite;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SiteSettingsController extends Controller
{
    public function edit(): View
    {
        $site = PublicSite::current();

        return view('platform.site.edit', [
            'site' => $site->formValues(),
            'logoUrl' => $site->logoUrl(),
            'faviconUrl' => $site->faviconUrl(),
        ]);
    }

    public function update(UpdatePublicSiteRequest $request, PublicSiteSettingsService $settings): RedirectResponse
    {
        $settings->update(
            $request->validated(),
            $request->file('logo'),
            $request->boolean('remove_logo'),
            $request->file('favicon'),
            $request->boolean('remove_favicon'),
        );

        return redirect()->route('platform.site.edit')->with('success', 'تم حفظ إعدادات الموقع.');
    }
}

<?php

namespace App\Http\Controllers\Workspace\Chat;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\InteractsWithWorkspace;
use App\Http\Requests\Chat\UpdateChatCatalogCommunicationProfileRequest;
use App\Http\Requests\Chat\UpdateChatCatalogSettingsRequest;
use App\Services\Chat\ChatCatalogRootService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use InvalidArgumentException;

class ChatCatalogSettingsController extends Controller
{
    use InteractsWithWorkspace;

    public function __construct(private readonly ChatCatalogRootService $catalogs) {}

    public function edit(): View
    {
        $catalog = $this->catalogs->forWorkspace($this->currentWorkspace());
        $this->authorize('view', $catalog);

        return view('workspace.chat-catalog.settings', [
            'catalog' => $catalog->load('communicationProfile'),
        ]);
    }

    public function update(UpdateChatCatalogSettingsRequest $request): RedirectResponse
    {
        $catalog = $this->catalogs->forWorkspace($this->currentWorkspace());
        $this->authorize('update', $catalog);
        try {
            $this->catalogs->updateSettings($catalog, $request->validated());
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['slug' => $exception->getMessage()]);
        }

        return back()->with('success', 'تم حفظ إعدادات كتالوج الشات.');
    }

    public function updateCommunication(UpdateChatCatalogCommunicationProfileRequest $request): RedirectResponse
    {
        $catalog = $this->catalogs->forWorkspace($this->currentWorkspace());
        $this->authorize('update', $catalog);
        $this->catalogs->updateCommunicationProfile($catalog, $request->validated());

        return back()->with('success', 'تم حفظ إعدادات اللغة والتواصل.');
    }

    public function publish(): RedirectResponse
    {
        $catalog = $this->catalogs->forWorkspace($this->currentWorkspace());
        $this->authorize('update', $catalog);
        $this->catalogs->publish($catalog);

        return back()->with('success', 'تم نشر الكتالوج.');
    }

    public function draft(): RedirectResponse
    {
        $catalog = $this->catalogs->forWorkspace($this->currentWorkspace());
        $this->authorize('update', $catalog);
        $this->catalogs->draft($catalog);

        return back()->with('success', 'تمت إعادة الكتالوج إلى مسودة.');
    }

    public function pause(): RedirectResponse
    {
        $catalog = $this->catalogs->forWorkspace($this->currentWorkspace());
        $this->authorize('update', $catalog);
        $this->catalogs->pause($catalog);

        return back()->with('success', 'تم إيقاف الكتالوج مؤقتاً.');
    }

    public function archive(): RedirectResponse
    {
        $catalog = $this->catalogs->forWorkspace($this->currentWorkspace());
        $this->authorize('update', $catalog);
        $this->catalogs->archive($catalog);

        return back()->with('success', 'تمت أرشفة الكتالوج.');
    }
}

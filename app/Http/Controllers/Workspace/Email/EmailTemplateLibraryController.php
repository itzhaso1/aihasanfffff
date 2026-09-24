<?php

namespace App\Http\Controllers\Workspace\Email;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\InteractsWithWorkspace;
use App\Http\Requests\Email\UpdateEmailTemplatePreferenceRequest;
use App\Services\Email\EmailBrandingService;
use App\Services\Email\EmailTemplateLibraryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class EmailTemplateLibraryController extends Controller
{
    use InteractsWithWorkspace;

    public function index(EmailTemplateLibraryService $library, EmailBrandingService $brandingService): View
    {
        $this->authorizeLibrary();

        return view('workspace.emails.templates.index', [
            'categories' => $library->categories(),
            'preference' => $library->preference($this->currentWorkspace()),
        ]);
    }

    public function category(string $category, EmailTemplateLibraryService $library): View
    {
        $this->authorizeLibrary();

        $meta = collect($library->categories())->firstWhere('slug', $category);
        abort_unless($meta, 404);

        return view('workspace.emails.templates.category', [
            'category' => $meta,
            'templates' => $library->templatesFor($category),
            'preference' => $library->preference($this->currentWorkspace()),
        ]);
    }

    public function preview(string $category, string $template, EmailTemplateLibraryService $library): View
    {
        $this->authorizeLibrary();
        $item = $library->templateOrFail($template);
        abort_unless(($item['category'] ?? null) === $category, 404);

        return view('workspace.emails.templates.preview', [
            'template' => $item,
            'previewHtml' => $this->inWorkspacePreview($library, $template),
        ]);
    }

    public function use(string $template, EmailTemplateLibraryService $library): RedirectResponse
    {
        $this->authorizeLibrary();
        $library->select($this->currentWorkspace(), $template);

        return redirect()
            ->route('workspace.emails.templates.edit')
            ->with('success', 'تم اختيار القالب. يمكنك تخصيص ظهوره الآن.');
    }

    public function edit(EmailTemplateLibraryService $library): View
    {
        $this->authorizeLibrary();
        $preference = $library->preference($this->currentWorkspace());
        abort_unless($preference, 404);

        $template = $library->templateOrFail($preference->template_key);
        $values = $library->viewData($preference->template_key, (array) $preference->settings);

        return view('workspace.emails.templates.edit', [
            'template' => $template,
            'preference' => $preference,
            'fields' => $library->editorFields($preference->template_key),
            'values' => $values,
            'previewHtml' => $this->inWorkspacePreview($library, $preference->template_key, (array) $preference->settings),
        ]);
    }

    public function update(UpdateEmailTemplatePreferenceRequest $request, EmailTemplateLibraryService $library): RedirectResponse
    {
        $this->authorizeLibrary();
        $library->saveSettings($this->currentWorkspace(), $request->validated());

        return redirect()
            ->route('workspace.emails.templates.edit')
            ->with('success', 'تم حفظ تخصيص القالب.');
    }

    public function livePreview(Request $request, EmailTemplateLibraryService $library): Response
    {
        $this->authorizeLibrary();
        $preference = $library->preference($this->currentWorkspace());
        abort_unless($preference, 404);

        $html = $this->inWorkspacePreview($library, $preference->template_key, $request->all());

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Frame-Options' => 'SAMEORIGIN',
        ]);
    }

    private function authorizeLibrary(): void
    {
        $branding = app(EmailBrandingService::class)->forWorkspace($this->currentWorkspace());
        $this->authorize('update', $branding);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function inWorkspacePreview(EmailTemplateLibraryService $library, string $key, array $overrides = []): string
    {
        return $library->render($key, $overrides);
    }
}

<?php

namespace App\Http\Controllers\Workspace\Email;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\InteractsWithWorkspace;
use App\Http\Requests\Email\UpdateEmailBrandingRequest;
use App\Models\Workspace;
use App\Services\Email\EmailBrandingHtmlBuilder;
use App\Services\Email\EmailBrandingService;
use App\Services\Email\EmailTemplateLibraryService;
use App\Support\Email\EmailBrandPublicUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class EmailBrandingController extends Controller
{
    use InteractsWithWorkspace;

    public function edit(EmailBrandingService $brandingService, EmailBrandingHtmlBuilder $htmlBuilder, EmailTemplateLibraryService $library): View
    {
        $workspace = $this->currentWorkspace();
        $branding = $brandingService->forWorkspace($workspace);
        $this->authorize('update', $branding);

        $preview = $htmlBuilder->forPreview((int) $workspace->id, $branding->toArray(), $branding);
        $preference = $library->preference($workspace);
        $selectedTemplate = $preference ? $library->templateOrFail($preference->template_key) : null;
        $templateValues = $preference && $selectedTemplate && ($selectedTemplate['view'] ?? null) !== 'emails.workspace-branded'
            ? $library->viewData($preference->template_key, (array) $preference->settings)
            : null;

        return view('workspace.emails.branding.edit', [
            'branding' => $branding,
            'logoUrl' => EmailBrandPublicUrl::forWorkspacePath((int) $workspace->id, $branding->logo_path),
            'headerLinks' => $branding->header_links ?: $brandingService->defaultHeaderLinks(),
            'previewHtml' => $library->renderSelectedForBranding($workspace)
                ?? $htmlBuilder->render($preview),
            'previewSubject' => $preview['subject'],
            'previewBody' => $preview['body'],
            'selectedTemplate' => $selectedTemplate,
            'templateValues' => $templateValues,
            'tab' => $this->editorTab(old('current_tab', request('tab'))) ?? 'general',
        ]);
    }

    public function update(UpdateEmailBrandingRequest $request, EmailBrandingService $brandingService, EmailTemplateLibraryService $library): RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        $brandingService->update(
            $workspace,
            $request->validated(),
            $request->file('logo'),
            $request->boolean('remove_logo'),
        );

        $this->syncSelectedTemplateSettings($workspace, $library, $request->validated());

        $tab = $this->editorTab($request->input('current_tab'));

        return redirect()
            ->route('workspace.emails.branding.edit', $tab ? ['tab' => $tab] : [])
            ->with('success', 'تم حفظ هوية البريد الإلكتروني.');
    }

    public function preview(Request $request, EmailBrandingService $brandingService, EmailTemplateLibraryService $library): Response
    {
        $workspace = $this->currentWorkspace();
        $branding = $brandingService->forWorkspace($workspace);
        $this->authorize('update', $branding);

        $html = $library->renderSelectedForBranding($workspace, $request->all())
            ?? $brandingService->previewHtml($workspace, $request->all());

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Frame-Options' => 'SAMEORIGIN',
        ]);
    }

    private function editorTab(mixed $tab): ?string
    {
        return in_array($tab, ['general', 'colors', 'header', 'footer', 'preview'], true)
            ? $tab
            : null;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function syncSelectedTemplateSettings(Workspace $workspace, EmailTemplateLibraryService $library, array $validated): void
    {
        $preference = $library->preference($workspace);
        if (! $preference) {
            return;
        }

        $template = $library->templateOrFail($preference->template_key);
        if (($template['view'] ?? null) === 'emails.workspace-branded') {
            return;
        }

        $settings = (array) $preference->settings;
        foreach ([
            'companyName' => $validated['company_name'] ?? null,
            'companyDescription' => $validated['company_description'] ?? null,
            'primaryColor' => $validated['primary_color'] ?? null,
            'secondaryColor' => $validated['secondary_color'] ?? null,
            'backgroundColor' => $validated['background_color'] ?? null,
            'textColor' => $validated['text_color'] ?? null,
            'ctaLabel' => $validated['cta_label'] ?? null,
            'ctaUrl' => $validated['cta_url'] ?? null,
            'showCta' => $validated['show_cta'] ?? null,
        ] as $key => $value) {
            if ($value !== null) {
                $settings[$key] = $value;
            }
        }

        $library->saveSettings($workspace, $settings);
    }
}

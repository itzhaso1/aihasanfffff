<?php

namespace App\Http\Controllers\Workspace\Chat;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Workspace\Concerns\InteractsWithWorkspace;
use App\Http\Requests\Chat\CloneChatCatalogProductTemplateRequest;
use App\Http\Requests\Chat\StoreChatCatalogProductTemplateRequest;
use App\Models\ChatCatalogProductTemplate;
use App\Services\Chat\ProductTemplateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ChatCatalogProductTemplateController extends Controller
{
    use InteractsWithWorkspace;

    public function __construct(
        private readonly ProductTemplateService $productTemplates,
    ) {}

    public function index(): View
    {
        $this->authorize('viewAny', ChatCatalogProductTemplate::class);

        $workspace = $this->currentWorkspace();
        $templates = $this->productTemplates->listAvailableForWorkspace($workspace);

        return view('workspace.chat-catalog.templates.index', [
            'templates' => $templates,
        ]);
    }

    public function show(ChatCatalogProductTemplate $template): View
    {
        $this->authorize('view', $template);

        $template->load('fields');

        return view('workspace.chat-catalog.templates.show', [
            'template' => $template,
        ]);
    }

    public function store(StoreChatCatalogProductTemplateRequest $request): RedirectResponse
    {
        $this->authorize('create', ChatCatalogProductTemplate::class);

        $template = $this->productTemplates->createWorkspaceTemplate(
            $this->currentWorkspace(),
            $request->validated(),
        );

        return redirect()
            ->route('workspace.chat-catalog.templates.show', $template)
            ->with('success', 'تم إنشاء القالب.');
    }

    public function clone(CloneChatCatalogProductTemplateRequest $request, ChatCatalogProductTemplate $template): RedirectResponse
    {
        $this->authorize('clone', $template);

        $cloned = $this->productTemplates->cloneSystemTemplate(
            $this->currentWorkspace(),
            $template,
            (string) $request->validated('name'),
        );

        return redirect()
            ->route('workspace.chat-catalog.templates.show', $cloned)
            ->with('success', 'تم نسخ القالب إلى مساحة العمل.');
    }
}

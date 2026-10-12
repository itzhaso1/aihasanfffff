<?php

declare(strict_types=1);

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class WorkerAccountNav extends Component
{
    public function __construct(
        public ?int $engagementId = null,
        public ?int $workspaceId = null,
        public ?bool $canReadInbox = null,
    ) {}

    public function render(): View
    {
        return view('components.worker-account-nav');
    }
}

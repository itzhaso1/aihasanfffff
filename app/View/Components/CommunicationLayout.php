<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

class CommunicationLayout extends Component
{
    public function __construct(
        public bool $flush = false,
        public bool $showNavbar = true,
        public bool $showSecondary = true,
        public bool $secondarySidebar = false,
        public bool $showAssistant = true,
        public ?string $pageTitle = null,
    ) {}

    public function render(): View
    {
        return view('layouts.communication');
    }
}

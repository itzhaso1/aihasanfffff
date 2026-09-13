<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

class CommunicationLayout extends Component
{
    public function __construct(
        public bool $flush = false,
        public bool $showSecondary = true,
        public ?string $pageTitle = null,
    ) {}

    public function render(): View
    {
        return view('layouts.communication');
    }
}

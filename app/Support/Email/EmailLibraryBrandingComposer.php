<?php

namespace App\Support\Email;

use Illuminate\View\View;

class EmailLibraryBrandingComposer
{
    public function compose(View $view): void
    {
        foreach (EmailLibraryBranding::forCurrentWorkspace() as $key => $value) {
            if (! array_key_exists($key, $view->getData())) {
                $view->with($key, $value);
            }
        }
    }
}

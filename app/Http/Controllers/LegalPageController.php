<?php

namespace App\Http\Controllers;

use App\Support\Site\PublicSite;
use Illuminate\View\View;

class LegalPageController extends Controller
{
    public function privacy(): View
    {
        return $this->page('privacy');
    }

    public function terms(): View
    {
        return $this->page('terms');
    }

    public function cookies(): View
    {
        return $this->page('cookies');
    }

    public function acceptableUse(): View
    {
        return $this->page('acceptable-use');
    }

    public function refund(): View
    {
        abort_unless((bool) config('legal.pages.refund'), 404);

        return $this->page('refund');
    }

    private function page(string $view): View
    {
        abort_unless((bool) config('legal.pages.'.$view, true), 404);

        return view('legal.'.$view, [
            'legal' => PublicSite::current()->legalFacts(),
        ]);
    }
}

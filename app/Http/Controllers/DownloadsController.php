<?php

namespace App\Http\Controllers;

use App\Support\Downloads\HasemAppCatalog;
use Illuminate\View\View;

class DownloadsController extends Controller
{
    public function index(): View
    {
        return view('downloads.index', [
            'apps' => HasemAppCatalog::all(),
        ]);
    }

    public function show(string $app): View
    {
        $catalog = HasemAppCatalog::find($app);
        abort_if($catalog === null, 404);

        return view('downloads.show', [
            'app' => $catalog,
        ]);
    }
}

<?php

namespace App\Http\Controllers\Workspace\Pos;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PosKitchenController extends PosBaseController
{
    public function index(Request $request): RedirectResponse
    {
        $this->authorizePos($request, 'orders.manage');

        return redirect()
            ->route('workspace.pos.qr-orders.index')
            ->with('info', 'المطبخ التشغيلي يعمل من تطبيق الكاشير. هنا طلبات QR للعرض.');
    }
}

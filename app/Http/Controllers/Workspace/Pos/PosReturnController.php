<?php

namespace App\Http\Controllers\Workspace\Pos;

use App\Models\Order;
use App\Models\PosOrderReturn;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PosReturnController extends PosBaseController
{
    public function create(Request $request, Order $order): RedirectResponse
    {
        $this->abortWebPosOperation();
    }

    public function store(Request $request, Order $order): RedirectResponse
    {
        $this->abortWebPosOperation();
    }

    public function markRefunded(Request $request, PosOrderReturn $return): RedirectResponse
    {
        $this->abortWebPosOperation();
    }
}

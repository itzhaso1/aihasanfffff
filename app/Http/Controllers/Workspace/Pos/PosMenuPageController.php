<?php

namespace App\Http\Controllers\Workspace\Pos;

use App\Models\DiningTable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PosMenuPageController extends PosBaseController
{
    public function index(Request $request): View
    {
        $this->authorizePos($request, 'menu.manage');
        $workspace = $this->currentWorkspace();

        $tables = DiningTable::query()
            ->orderBy('name')
            ->get(['id', 'name', 'qr_token', 'status']);

        return view('workspace.pos.menu-links.index', [
            'workspace' => $workspace,
            'tables' => $tables,
        ]);
    }
}

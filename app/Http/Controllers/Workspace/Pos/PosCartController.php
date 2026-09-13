<?php

namespace App\Http\Controllers\Workspace\Pos;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PosCartController extends PosBaseController
{
    public function show(Request $request): JsonResponse
    {
        $this->abortWebPosOperation();
    }

    public function addItem(Request $request): JsonResponse
    {
        $this->abortWebPosOperation();
    }

    public function updateItem(Request $request, string $key): JsonResponse
    {
        $this->abortWebPosOperation();
    }

    public function removeItem(Request $request, string $key): JsonResponse
    {
        $this->abortWebPosOperation();
    }

    public function updateMeta(Request $request): JsonResponse
    {
        $this->abortWebPosOperation();
    }

    public function clear(Request $request): JsonResponse
    {
        $this->abortWebPosOperation();
    }

    public function checkout(Request $request): JsonResponse|RedirectResponse
    {
        $this->abortWebPosOperation();
    }
}

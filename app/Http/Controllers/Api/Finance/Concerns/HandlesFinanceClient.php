<?php

namespace App\Http\Controllers\Api\Finance\Concerns;

use App\Exceptions\Api\ApiErrorCode;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

trait HandlesFinanceClient
{
    use AuthorizesFinanceApi;

    protected function clientWorkspace(WorkspaceContext $workspaceContext): Workspace
    {
        return $this->requireWorkspace($workspaceContext);
    }

    protected function clientActor(Request $request, Workspace $workspace, string $permission): User
    {
        return $this->authorizeFinanceApi($request, $workspace, $permission);
    }

    protected function runFinanceDomain(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (RuntimeException $exception) {
            [$code, $status] = $this->financeDomainFailure($exception);
            throw new HttpResponseException($this->fail(
                $exception->getMessage(),
                $code,
                $status,
            ));
        }
    }

    /**
     * @return array{0: ApiErrorCode, 1: int}
     */
    protected function financeDomainFailure(RuntimeException $exception): array
    {
        $message = $exception->getMessage();
        $conflicts = [
            'Invoice is already paid.',
            'رقم الفاتورة مستخدم مسبقاً في هذه المنشأة.',
            'رقم عرض السعر مستخدم مسبقاً في هذه المنشأة.',
            'تعذر توليد فاتورة الجدول بسبب تكرار عملية متزامنة.',
            'تعذر توليد رقم فاتورة فريد لهذه المنشأة.',
            'تعذر توليد رقم عرض سعر فريد لهذه المنشأة.',
            'تعذر توليد رقم إيصال فريد لهذه المنشأة.',
        ];

        foreach ($conflicts as $needle) {
            if ($message === $needle || str_contains($message, $needle)) {
                return [ApiErrorCode::IdempotencyConflict, 409];
            }
        }

        return [ApiErrorCode::ValidationFailed, 422];
    }

    /**
     * @return array{current_page:int,last_page:int,per_page:int,total:int}
     */
    protected function pageMeta(LengthAwarePaginator $page): array
    {
        return [
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function documentItemsFromRequest(Request $request): array
    {
        $raw = $request->input('items');
        if ($raw === null && is_string($request->input('items_json'))) {
            $decoded = json_decode((string) $request->input('items_json'), true);
            $raw = $decoded;
        }

        if (! is_array($raw) || $raw === []) {
            throw ValidationException::withMessages([
                'items' => 'يجب إدخال بند واحد على الأقل.',
            ]);
        }

        return array_values(array_map(function ($item) {
            if (! is_array($item)) {
                return $item;
            }

            unset($item['total'], $item['tax_amount'], $item['taxable_amount'], $item['subtotal']);

            $productId = (int) ($item['product_id'] ?? 0);
            $item['product_id'] = $productId > 0 ? $productId : null;
            $item['unit'] = mb_substr(trim((string) ($item['unit'] ?? '')), 0, 32);

            return $item;
        }, $raw));
    }

    /**
     * @return array<string, mixed>
     */
    protected function emailFields(Request $request): array
    {
        $validated = $request->validate([
            'email' => ['required', 'email:filter', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'subject' => ['nullable', 'string', 'max:255'],
            'message' => ['nullable', 'string', 'max:15000'],
            'attach_pdf' => ['nullable', 'boolean'],
        ], [
            'email.required' => 'لا يوجد بريد إلكتروني للعميل.',
            'email.email' => 'البريد الإلكتروني غير صالح.',
        ]);

        return [
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'subject' => $validated['subject'] ?? null,
            'message' => $validated['message'] ?? null,
            'attach_pdf' => $request->boolean('attach_pdf', true),
        ];
    }
}

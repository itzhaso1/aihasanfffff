<?php

namespace App\Http\Controllers\Workspace\Pos;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\StorePublicMenuOrderRequest;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PosMenuItem;
use App\Models\Workspace;
use App\Services\AI\AiUsageAccountant;
use App\Services\Pos\PosMenuAiService;
use App\Services\Pos\PosOrderService;
use App\Services\Pos\TableGuestSessionService;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Symfony\Component\HttpFoundation\Cookie;

class CustomerMenuController extends Controller
{
    public function __construct(
        private readonly PosOrderService $posOrderService,
        private readonly PosMenuAiService $posMenuAiService,
        private readonly TableGuestSessionService $guestSessionService,
    ) {}

    public function generalMenu(Workspace $workspace): Response
    {
        return response()->view('workspace.pos.menu', [
            'workspace' => $workspace,
            'table' => null,
            'items' => $this->menuItems($workspace->id),
            'sliderImages' => $this->menuSliderImages($workspace),
            'guestSession' => null,
            'guestSessionToken' => null,
            'sessionExpired' => false,
        ]);
    }

    public function tableMenu(Request $request, Workspace $workspace, string $token): Response|RedirectResponse
    {
        return app(WorkspaceContext::class)->runFor(
            $workspace,
            fn () => $this->renderTableMenu($request, $workspace, $token),
        );
    }

    private function renderTableMenu(Request $request, Workspace $workspace, string $token): Response|RedirectResponse
    {
        $table = $this->resolveTable($workspace, $token);

        $cookieName = $this->guestSessionService->cookieName($table);
        $incoming = (string) ($request->cookie($cookieName) ?: $request->query('guest_session_token', ''));

        if ($request->boolean('fresh')) {
            $guest = $this->guestSessionService->startFresh($table);
            $response = redirect()->route('menu.table', [
                'workspace' => $workspace->slug,
                'token' => $table->qr_token,
            ]);

            return $response->withCookie($this->guestCookie($cookieName, $guest->token));
        }

        $guest = $this->guestSessionService->bootstrap($table, $incoming !== '' ? $incoming : null);

        return response()
            ->view('workspace.pos.menu', [
                'workspace' => $workspace,
                'table' => $table,
                'items' => $this->menuItems($workspace->id),
                'sliderImages' => $this->menuSliderImages($workspace),
                'guestSession' => $guest,
                'guestSessionToken' => $guest->token,
                'sessionExpired' => false,
            ])
            ->withCookie($this->guestCookie($cookieName, $guest->token));
    }

    public function placeGeneralOrder(StorePublicMenuOrderRequest $request, Workspace $workspace): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $order = $this->posOrderService->createQrMenuOrder($workspace, null, $validated, null);
        } catch (RuntimeException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        try {
            $payment = $this->prepareMenuCheckout($order, $validated);
        } catch (RuntimeException $exception) {
            return back()->with('success', 'تم إرسال طلبك بنجاح. رقم الطلب: '.$order->order_number)
                ->with('error', $exception->getMessage());
        }

        if ($payment) {
            return back()
                ->with('success', 'تم إرسال طلبك بنجاح. رقم الطلب: '.$order->order_number.' (طريقة الدفع: الدفع الآن)')
                ->with('payment_link', $payment->payment_link);
        }

        return back()->with('success', 'تم إرسال طلبك بنجاح. رقم الطلب: '.$order->order_number.' (طريقة الدفع: الدفع عند الخروج)');
    }

    public function placeTableOrder(StorePublicMenuOrderRequest $request, Workspace $workspace, string $token): RedirectResponse
    {
        return app(WorkspaceContext::class)->runFor(
            $workspace,
            fn () => $this->placeTableOrderInWorkspace($request, $workspace, $token),
        );
    }

    private function placeTableOrderInWorkspace(StorePublicMenuOrderRequest $request, Workspace $workspace, string $token): RedirectResponse
    {
        $table = $this->resolveTable($workspace, $token);
        $cookieName = $this->guestSessionService->cookieName($table);
        $guestToken = (string) ($request->input('guest_session_token') ?: $request->cookie($cookieName) ?: '');

        $validated = $request->validated();

        try {
            $guest = $this->guestSessionService->assertValidForOrder($table, $guestToken);
            $order = $this->posOrderService->createQrMenuOrder($workspace, $table, $validated, $guest);
        } catch (RuntimeException $exception) {
            return back()
                ->withInput()
                ->with('error', $exception->getMessage())
                ->with('session_expired', true);
        }

        try {
            $payment = $this->prepareMenuCheckout($order, $validated);
        } catch (RuntimeException $exception) {
            return back()->with('success', 'تم إرسال طلب الطاولة بنجاح. رقم الطلب: '.$order->order_number)
                ->with('error', $exception->getMessage())
                ->withCookie($this->guestCookie($cookieName, $guestToken));
        }

        $redirect = back()->withCookie($this->guestCookie($cookieName, $guest->token));

        if ($payment) {
            return $redirect
                ->with('success', 'تم إرسال طلب الطاولة بنجاح. رقم الطلب: '.$order->order_number.' (طريقة الدفع: الدفع الآن)')
                ->with('payment_link', $payment->payment_link);
        }

        return $redirect->with('success', 'تم إرسال طلب الطاولة بنجاح. رقم الطلب: '.$order->order_number.' (طريقة الدفع: الدفع عند الخروج)');
    }

    public function askAi(Request $request, Workspace $workspace): JsonResponse
    {
        $token = (string) $request->route('token', '');
        if ($token !== '') {
            $this->resolveTable($workspace, $token);
        }

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:800'],
            'model' => ['prohibited'],
            'provider' => ['prohibited'],
            'max_tokens' => ['prohibited'],
            'temperature' => ['prohibited'],
            'workspace_id' => ['prohibited'],
        ]);

        $rateKey = 'pos-menu-ai:'.sha1((string) $workspace->id.'|'.(string) $request->ip());
        $dayKey = $rateKey.':day';
        if (RateLimiter::tooManyAttempts($rateKey, 8)) {
            return response()->json([
                'message' => 'تم تجاوز حد المساعد. حاول بعد قليل.',
                'retry_after' => RateLimiter::availableIn($rateKey),
            ], 429);
        }
        if (RateLimiter::tooManyAttempts($dayKey, 40)) {
            return response()->json([
                'message' => 'تم استهلاك حد المساعد اليومي. حاول غدًا.',
                'retry_after' => RateLimiter::availableIn($dayKey),
            ], 429);
        }
        RateLimiter::hit($rateKey, 60);
        RateLimiter::hit($dayKey, 86400);
        app(AiUsageAccountant::class)->incrementPublicBucket('pos-menu');

        try {
            $answer = $this->posMenuAiService->answer($workspace, (string) $validated['message']);
        } catch (\Throwable) {
            $answer = 'تعذر الرد الآن، حاول بعد قليل.';
        }

        return response()->json(['answer' => $answer]);
    }

    private function resolveTable(Workspace $workspace, string $token): DiningTable
    {
        return DiningTable::withoutGlobalScopes()
            ->where('workspace_id', $workspace->id)
            ->where('qr_token', $token)
            ->firstOrFail();
    }

    private function guestCookie(string $name, string $token): Cookie
    {
        return cookie(
            name: $name,
            value: $token,
            minutes: 60 * 24 * 7,
            path: '/',
            secure: (bool) config('session.secure', false),
            httpOnly: true,
            raw: false,
            sameSite: 'lax'
        );
    }

    private function menuItems(int $workspaceId)
    {
        return PosMenuItem::withoutGlobalScopes()
            ->with('category:id,name')
            ->where('workspace_id', $workspaceId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get([
                'id',
                'pos_item_category_id',
                'name',
                'price',
                'currency',
                'item_type',
                'size_label',
                'description',
                'image_path',
            ]);
    }

    /**
     * @return array<int,string>
     */
    private function menuSliderImages(Workspace $workspace): array
    {
        return collect(data_get((array) ($workspace->settings ?? []), 'pos.menu_slider_images', []))
            ->filter(fn ($path): bool => is_string($path) && $path !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $validated
     */
    private function prepareMenuCheckout(Order $order, array $validated): ?Payment
    {
        $paymentMethod = (string) ($validated['payment_method'] ?? ((bool) ($validated['pay_now'] ?? false) ? 'pay_now' : 'pay_later'));
        if ($paymentMethod !== 'pay_now') {
            return null;
        }

        try {
            return $this->posOrderService->createPaymentLinkForOrder($order);
        } catch (RuntimeException $exception) {
            throw new RuntimeException('تم إرسال الطلب لكن تعذر تجهيز الدفع الآن: '.$exception->getMessage(), 0, $exception);
        }
    }
}

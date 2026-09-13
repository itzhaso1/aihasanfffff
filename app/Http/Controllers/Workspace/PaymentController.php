<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\CreatePaymentLinkRequest;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Services\Payment\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $paymentService) {}

    public function index(): View
    {
        $payments = Payment::query()
            ->with(['order', 'gateway'])
            ->latest('id')
            ->paginate(15);

        return view('workspace.payments.index', compact('payments'));
    }

    public function create(): View
    {
        return view('workspace.payments.create', [
            'orders' => Order::query()->orderByDesc('id')->get(['id', 'order_number', 'total_amount', 'payment_status']),
            'gateways' => PaymentGateway::query()->orderBy('provider')->get(['id', 'provider', 'status']),
        ]);
    }

    public function store(CreatePaymentLinkRequest $request): RedirectResponse
    {
        $order = Order::query()->findOrFail($request->integer('order_id'));
        $gateway = $request->filled('payment_gateway_id')
            ? PaymentGateway::query()->findOrFail($request->integer('payment_gateway_id'))
            : null;

        $this->paymentService->createPaymentLink($order, $gateway);

        return redirect()->route('workspace.payments.index')->with('success', 'تم إنشاء رابط الدفع.');
    }
}

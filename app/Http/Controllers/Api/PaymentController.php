<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\CreatePaymentLinkRequest;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentGateway;
use App\Services\Payment\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $payments = Payment::query()
            ->with(['order', 'gateway'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->orderByDesc('id')
            ->paginate((int) $request->input('per_page', 15));

        return response()->json($payments);
    }

    public function store(CreatePaymentLinkRequest $request): JsonResponse
    {
        $order = Order::query()->findOrFail($request->integer('order_id'));
        $gateway = $request->filled('payment_gateway_id')
            ? PaymentGateway::query()->findOrFail($request->integer('payment_gateway_id'))
            : null;

        $payment = $this->paymentService->createPaymentLink($order, $gateway);

        return response()->json(['data' => $payment], 201);
    }

    public function show(Payment $payment): JsonResponse
    {
        $this->authorize('view', $payment);

        return response()->json(['data' => $payment->load(['order', 'gateway'])]);
    }

    public function destroy(Payment $payment): JsonResponse
    {
        return response()->json(status: 405);
    }
}

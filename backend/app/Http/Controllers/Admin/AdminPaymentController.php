<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPaymentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $payments = Payment::with('user')
            ->when($request->user_id, fn ($q, $id) => $q->where('user_id', $id))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('created_at')
            ->paginate(25)
            ->through(fn (Payment $p) => [
                'id'               => $p->id,
                'user_id'          => $p->user_id,
                'user_name'        => $p->user?->name,
                'user_email'       => $p->user?->email,
                'amount'           => $p->amount,
                'amount_formatted' => $p->formattedAmount(),
                'currency'         => $p->currency,
                'status'           => $p->status,
                'description'      => $p->description,
                'stripe_charge_id' => $p->stripe_charge_id,
                'refunded_at'      => $p->refunded_at?->toIso8601String(),
                'created_at'       => $p->created_at->toIso8601String(),
            ]);

        return $this->success($payments);
    }

    public function refund(Request $request, Payment $payment): JsonResponse
    {
        $request->validate([
            'amount' => ['nullable', 'integer', 'min:1', 'max:'.$payment->amount],
        ]);

        if ($payment->status === 'refunded') {
            return $this->error('ALREADY_REFUNDED', 'This payment has already been refunded.', 422);
        }

        // In mock mode: mark as refunded in DB.
        // When Stripe is wired in: call \Stripe\Refund::create(['charge' => $payment->stripe_charge_id, 'amount' => $request->amount])
        $payment->update([
            'status'      => 'refunded',
            'refunded_at' => now(),
        ]);

        $admin = auth('admin')->user();

        AdminAuditLog::record(
            $admin->id,
            AdminAuditLog::PAYMENT_REFUNDED,
            'Payment',
            $payment->id,
            [
                'user_id'  => $payment->user_id,
                'amount'   => $request->amount ?? $payment->amount,
                'currency' => $payment->currency,
            ],
        );

        return $this->success([
            'id'          => $payment->id,
            'status'      => 'refunded',
            'refunded_at' => $payment->refunded_at->toIso8601String(),
        ]);
    }
}

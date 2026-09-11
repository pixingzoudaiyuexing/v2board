<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\TelegramService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function notify($method, $uuid, Request $request)
    {
        try {
            $paymentService = new PaymentService($method, null, $uuid);
            $verify = $paymentService->notify($request->input());
            if (!$verify) abort(500, 'verify error');
            if (!$this->handle($verify['trade_no'], $verify['callback_no'])) {
                abort(500, 'handle error');
            }
            return(isset($verify['custom_result']) ? $verify['custom_result'] : 'success');
        } catch (\Exception $e) {
            abort(500, 'fail');
        }
    }

    private function handle($tradeNo, $callbackNo)
    {
        $receivedAt = Carbon::now()->getTimestamp();
        $order = Order::where('trade_no', $tradeNo)->first();
        if (!$order) {
            abort(500, 'order is not found');
        }
        if ($order->status !== 0) return true;
        if ($order->isExpiredAt($receivedAt)) {
            $this->logLatePayment($order, $callbackNo, $receivedAt);
            return true;
        }
        $orderService = new OrderService($order);
        if (!$orderService->paid($callbackNo)) {
            // paid() mutates this instance only after this call wins the transition.
            if ($order->status === 1) return false;
            $currentOrder = $order->fresh();
            if (!$currentOrder) return false;
            if (
                $currentOrder->status === 0 &&
                $currentOrder->isExpiredAt(Carbon::now()->getTimestamp())
            ) {
                $this->logLatePayment($currentOrder, $callbackNo, $receivedAt);
                return true;
            }
            return $currentOrder->status !== 0;
        }
        $telegramService = new TelegramService();
        $message = sprintf(
            "💰成功收款%s元\n———————————————\n订单号：%s",
            $order->total_amount / 100,
            $order->trade_no
        );
        $telegramService->sendMessageWithAdmin($message);
        return true;
    }

    private function logLatePayment(Order $order, $callbackNo, int $receivedAt): void
    {
        Log::channel('daily')->error('LATE_PAYMENT_EXPIRED_ORDER', [
            'trade_no' => $order->trade_no,
            'callback_no' => (string)$callbackNo,
            'order_id' => (int)$order->id,
            'expires_at' => $order->expires_at,
            'received_at' => $receivedAt
        ]);
    }
}

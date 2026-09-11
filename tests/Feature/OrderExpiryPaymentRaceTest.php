<?php

namespace Tests\Feature;

require_once __DIR__ . '/../Fixtures/OrderExpiryTestPayment.php';

use App\Http\Controllers\V1\Guest\PaymentController;
use App\Http\Controllers\V1\User\OrderController;
use App\Jobs\OrderHandleJob;
use App\Jobs\SendTelegramJob;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Payments\OrderExpiryTestPayment;
use App\Services\OrderService;
use Carbon\Carbon;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class OrderExpiryPaymentRaceTest extends TestCase
{
    private const NOW = 1700000000;

    protected function setUp(): void
    {
        parent::setUp();

        $connection = getenv('ORDER_RACE_TEST_DB') ?: 'sqlite';
        $databaseConfig = [
            'database.default' => $connection,
            'queue.default' => 'sync',
            'v2board.telegram_bot_enable' => 1,
        ];
        if ($connection === 'sqlite') {
            $databaseConfig['database.connections.sqlite.database'] = ':memory:';
        }
        config($databaseConfig);
        DB::purge($connection);
        Carbon::setTestNow(Carbon::createFromTimestampUTC(self::NOW));
        $this->dropSchema();
        $this->createSchema();
        OrderExpiryTestPayment::$payCalls = 0;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testExpiryBoundaryUsesTheSingleOrderTtl(): void
    {
        $active = $this->makeOrder(['created_at' => self::NOW - 7199]);
        $atDeadline = $this->makeOrder(['created_at' => self::NOW - 7200]);
        $pastDeadline = $this->makeOrder(['created_at' => self::NOW - 7201]);

        $this->assertSame(self::NOW + 1, $active->expires_at);
        $this->assertFalse($active->isExpiredAt(self::NOW));
        $this->assertSame(self::NOW, $atDeadline->expires_at);
        $this->assertTrue($atDeadline->isExpiredAt(self::NOW));
        $this->assertTrue($pastDeadline->isExpiredAt(self::NOW));
    }

    public function testExpiresAtIsOnlyAppendedExplicitly(): void
    {
        $order = $this->makeOrder(['created_at' => self::NOW - 100]);

        $this->assertArrayNotHasKey('expires_at', $order->toArray());
        $order->append('expires_at');
        $this->assertSame(
            self::NOW - 100 + Order::PENDING_TTL_SECONDS,
            $order->toArray()['expires_at']
        );
    }

    public function testUserFetchAndDetailExposeExpiresAt(): void
    {
        $order = $this->makeOrder([
            'plan_id' => 0,
            'created_at' => self::NOW - 100,
        ]);
        $controller = new OrderController();

        $fetchRequest = Request::create('/api/v1/user/order/fetch', 'GET');
        $fetchRequest->merge(['user' => ['id' => $order->user_id]]);
        $fetchData = $controller->fetch($fetchRequest)->getOriginalContent()['data'];
        $expectedExpiresAt = self::NOW - 100 + 7200;
        $this->assertArrayHasKey('expires_at', $fetchData->first()->toArray());
        $this->assertSame(
            $expectedExpiresAt,
            $fetchData->first()->toArray()['expires_at']
        );

        $detailRequest = Request::create('/api/v1/user/order/detail', 'GET', [
            'trade_no' => $order->trade_no,
            'user' => ['id' => $order->user_id],
        ]);
        $detailData = $controller->detail($detailRequest)->getOriginalContent()['data'];
        $this->assertArrayHasKey('expires_at', $detailData->toArray());
        $this->assertSame($expectedExpiresAt, $detailData->toArray()['expires_at']);
    }

    public function testActivePaidCheckoutIsAllowed(): void
    {
        $order = $this->makeOrder(['created_at' => self::NOW - 7199]);
        $payment = $this->makePayment();

        $response = (new OrderController())->checkout($this->checkoutRequest(
            $order,
            $payment->id
        ));

        $this->assertSame(0, $response->getOriginalContent()['type']);
        $this->assertSame('test-qr-payload', $response->getOriginalContent()['data']);
        $this->assertSame(1, OrderExpiryTestPayment::$payCalls);
        $this->assertSame($payment->id, $order->fresh()->payment_id);
    }

    public function testExpiredPaidCheckoutIsRejectedBeforePaymentMutation(): void
    {
        $order = $this->makeOrder(['created_at' => self::NOW - 7200]);
        $payment = $this->makePayment();

        try {
            (new OrderController())->checkout($this->checkoutRequest($order, $payment->id));
            $this->fail('Expired checkout was not rejected');
        } catch (HttpException $exception) {
            $this->assertSame('Order has expired', $exception->getMessage());
        }

        $order->refresh();
        $this->assertSame(0, $order->status);
        $this->assertNull($order->payment_id);
        $this->assertSame(0, OrderExpiryTestPayment::$payCalls);
    }

    public function testActiveFreeOrderStillCompletesCheckout(): void
    {
        Queue::fake();
        $order = $this->makeOrder([
            'created_at' => self::NOW - 7199,
            'total_amount' => 0,
            'balance_amount' => 100,
        ]);

        $response = (new OrderController())->checkout($this->checkoutRequest($order));

        $this->assertSame(-1, $response->getOriginalContent()['type']);
        $this->assertSame(1, $order->fresh()->status);
        Queue::assertPushed(OrderHandleJob::class, 1);
    }

    public function testExpiredFreeOrderIsRejectedBeforePaidTransition(): void
    {
        Queue::fake();
        $order = $this->makeOrder([
            'created_at' => self::NOW - 7200,
            'total_amount' => 0,
            'balance_amount' => 100,
        ]);

        try {
            (new OrderController())->checkout($this->checkoutRequest($order));
            $this->fail('Expired free checkout was not rejected');
        } catch (HttpException $exception) {
            $this->assertSame('Order has expired', $exception->getMessage());
        }

        $this->assertSame(0, $order->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function testSchedulerOnlyCancelsExpiredPendingOrders(): void
    {
        $active = $this->makeOrder(['created_at' => self::NOW - 7199]);
        $expired = $this->makeOrder(['created_at' => self::NOW - 7200]);

        (new OrderHandleJob($active->trade_no))->handle();
        (new OrderHandleJob($expired->trade_no))->handle();

        $this->assertSame(0, $active->fresh()->status);
        $this->assertSame(2, $expired->fresh()->status);
    }

    public function testPaidOrderStillActivatesThroughOrderHandleJob(): void
    {
        $user = $this->makeUser(['balance' => 10]);
        $order = $this->makeOrder([
            'user_id' => $user->id,
            'type' => 9,
            'status' => 1,
            'total_amount' => 100,
        ]);

        (new OrderHandleJob($order->trade_no))->handle();

        $this->assertSame(3, $order->fresh()->status);
        $this->assertSame(110, $user->fresh()->balance);
    }

    public function testActiveCallbackWinsTransitionAndSendsOneSuccessNotification(): void
    {
        Queue::fake();
        $this->makeUser([
            'is_admin' => 1,
            'telegram_id' => 123456,
        ]);
        $order = $this->makeOrder(['created_at' => self::NOW - 7199]);
        $controller = new PaymentController();

        $this->assertTrue($this->invokePaymentHandle($controller, $order, 'callback-1'));
        $this->assertSame(1, $order->fresh()->status);
        Queue::assertPushed(OrderHandleJob::class, 1);
        Queue::assertPushed(SendTelegramJob::class, 1);
    }

    public function testExpiredCallbackReturnsProviderSuccessLogsAndSendsNoTelegram(): void
    {
        Queue::fake();
        $order = $this->makeOrder(['created_at' => self::NOW - 7200]);
        $payment = $this->makePayment(['uuid' => 'late-payment-uuid']);
        $expectedContext = [
            'trade_no' => $order->trade_no,
            'callback_no' => 'late-callback-1',
            'order_id' => $order->id,
            'expires_at' => self::NOW,
            'received_at' => self::NOW,
        ];
        Log::shouldReceive('channel')
            ->once()
            ->with('daily')
            ->andReturnSelf();
        Log::shouldReceive('error')
            ->once()
            ->with('LATE_PAYMENT_EXPIRED_ORDER', $expectedContext);
        $request = Request::create('/api/v1/guest/payment/notify', 'POST', [
            'trade_no' => $order->trade_no,
            'callback_no' => 'late-callback-1',
            'merchant_key' => 'must-not-be-logged',
            'signature' => 'must-not-be-logged',
            'payment_url' => 'must-not-be-logged',
            'qr_payload' => 'must-not-be-logged',
        ]);

        $result = (new PaymentController())->notify(
            'OrderExpiryTestPayment',
            $payment->uuid,
            $request
        );

        $this->assertSame('PROVIDER_OK', $result);
        $this->assertSame(0, $order->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function testDuplicateCallbackDoesNotDispatchOrNotifyTwice(): void
    {
        Queue::fake();
        Log::shouldReceive('channel')->never();
        $this->makeUser([
            'is_admin' => 1,
            'telegram_id' => 123456,
        ]);
        $order = $this->makeOrder(['created_at' => self::NOW - 7199]);
        $controller = new PaymentController();

        $this->assertTrue($this->invokePaymentHandle($controller, $order, 'callback-1'));
        $this->assertTrue($this->invokePaymentHandle($controller, $order, 'callback-1'));

        Queue::assertPushed(OrderHandleJob::class, 1);
        Queue::assertPushed(SendTelegramJob::class, 1);
    }

    public function testExpiredCancelledOrderCallbackLogsLatePaymentAndReturnsSuccess(): void
    {
        Queue::fake();
        $order = $this->makeOrder([
            'created_at' => self::NOW - 7200,
            'status' => 2,
        ]);
        $this->expectPaymentAnomalyLog(
            'LATE_PAYMENT_EXPIRED_ORDER',
            $order,
            'cancelled-callback-1'
        );

        $this->assertSame(
            'PROVIDER_OK',
            $this->notifyPaymentCallback($order, 'cancelled-callback-1')
        );

        $this->assertSame(2, $order->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function testActiveCancelledOrderCallbackLogsAnomalyAndReturnsSuccess(): void
    {
        Queue::fake();
        $order = $this->makeOrder([
            'created_at' => self::NOW - 7199,
            'status' => 2,
        ]);
        $this->expectPaymentAnomalyLog(
            'PAYMENT_RECEIVED_FOR_CANCELLED_ORDER',
            $order,
            'cancelled-callback-2'
        );

        $this->assertSame(
            'PROVIDER_OK',
            $this->notifyPaymentCallback($order, 'cancelled-callback-2')
        );

        $this->assertSame(2, $order->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function testConcurrentCancelDuringCallbackStillLogsAnomaly(): void
    {
        Queue::fake();
        $order = $this->makeOrder(['created_at' => self::NOW - 7199]);
        $this->expectPaymentAnomalyLog(
            'PAYMENT_RECEIVED_FOR_CANCELLED_ORDER',
            $order,
            'cancelled-race-callback'
        );
        $cancelled = false;
        Order::retrieved(function (Order $retrieved) use (&$cancelled, $order) {
            if (!$cancelled && $retrieved->id === $order->id) {
                $cancelled = true;
                Order::where('id', $order->id)
                    ->where('status', 0)
                    ->update(['status' => 2]);
            }
        });

        try {
            $this->assertSame(
                'PROVIDER_OK',
                $this->notifyPaymentCallback($order, 'cancelled-race-callback')
            );
        } finally {
            Order::flushEventListeners();
        }

        $this->assertSame(2, $order->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function testCompletedDuplicateCallbackHasNoAnomalyOrTelegram(): void
    {
        Queue::fake();
        Log::shouldReceive('channel')->never();
        $order = $this->makeOrder([
            'created_at' => self::NOW - 7200,
            'status' => 3,
        ]);

        $this->assertSame(
            'PROVIDER_OK',
            $this->notifyPaymentCallback($order, 'completed-callback-1')
        );

        $this->assertSame(3, $order->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function testDispatchFailureLeavesPaidOrderRecoverableByScheduler(): void
    {
        $dispatcher = Mockery::mock(BusDispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->andThrow(new \RuntimeException('dispatch failed'));
        $this->app->instance(BusDispatcher::class, $dispatcher);
        $user = $this->makeUser(['balance' => 10]);
        $order = $this->makeOrder([
            'user_id' => $user->id,
            'type' => 9,
            'created_at' => self::NOW - 7199,
            'total_amount' => 100,
        ]);

        $this->assertFalse(
            $this->invokePaymentHandle(new PaymentController(), $order, 'callback-1')
        );
        $this->assertSame(1, $order->fresh()->status);

        (new OrderHandleJob($order->trade_no))->handle();

        $this->assertSame(3, $order->fresh()->status);
        $this->assertSame(110, $user->fresh()->balance);
    }

    public function testCancelWinPreventsPaidActivationAndRefundsBalanceOnce(): void
    {
        Queue::fake();
        $user = $this->makeUser(['balance' => 0]);
        $order = $this->makeOrder([
            'user_id' => $user->id,
            'balance_amount' => 100,
            'created_at' => self::NOW - 7199,
        ]);
        $staleForPaid = Order::find($order->id);
        $staleForCancel = Order::find($order->id);

        $this->assertTrue((new OrderService($staleForCancel))->cancel());
        $this->assertFalse((new OrderService($staleForPaid))->paid('callback-1'));
        $this->assertFalse((new OrderService($staleForCancel))->cancel());

        $this->assertSame(2, $order->fresh()->status);
        $this->assertSame(100, $user->fresh()->balance);
        Queue::assertNothingPushed();
    }

    public function testPaidWinPreventsCancelRefundAndDispatchesOnce(): void
    {
        Queue::fake();
        $user = $this->makeUser(['balance' => 0]);
        $order = $this->makeOrder([
            'user_id' => $user->id,
            'balance_amount' => 100,
            'created_at' => self::NOW - 7199,
        ]);
        $staleForPaid = Order::find($order->id);
        $staleForCancel = Order::find($order->id);

        $this->assertTrue((new OrderService($staleForPaid))->paid('callback-1'));
        $this->assertFalse((new OrderService($staleForCancel))->cancel());
        $this->assertFalse((new OrderService($staleForPaid))->paid('callback-1'));

        $this->assertSame(1, $order->fresh()->status);
        $this->assertSame(0, $user->fresh()->balance);
        Queue::assertPushed(OrderHandleJob::class, 1);
    }

    public function testAtomicPaidTransitionRejectsTheExactExpiryBoundary(): void
    {
        Queue::fake();
        $active = $this->makeOrder(['created_at' => self::NOW - 7199]);
        $expired = $this->makeOrder(['created_at' => self::NOW - 7200]);

        $this->assertTrue((new OrderService($active))->paid('active-callback'));
        $this->assertFalse((new OrderService($expired))->paid('late-callback'));

        $this->assertSame(1, $active->fresh()->status);
        $this->assertSame(self::NOW, $active->fresh()->paid_at);
        $this->assertSame('active-callback', $active->fresh()->callback_no);
        $this->assertSame(0, $expired->fresh()->status);
        Queue::assertPushed(OrderHandleJob::class, 1);
    }

    /**
     * @dataProvider balanceOrderProvider
     */
    public function testPaidTransitionPreservesPartialAndFullBalanceOrders(
        int $totalAmount,
        int $balanceAmount
    ): void {
        Queue::fake();
        $user = $this->makeUser(['balance' => 0]);
        $order = $this->makeOrder([
            'user_id' => $user->id,
            'created_at' => self::NOW - 100,
            'total_amount' => $totalAmount,
            'balance_amount' => $balanceAmount,
        ]);

        $this->assertTrue((new OrderService($order))->paid('callback-1'));

        $order->refresh();
        $this->assertSame(1, $order->status);
        $this->assertSame($totalAmount, $order->total_amount);
        $this->assertSame($balanceAmount, $order->balance_amount);
        $this->assertSame(0, $user->fresh()->balance);
    }

    public function balanceOrderProvider(): array
    {
        return [
            'partial balance' => [75, 25],
            'full balance' => [0, 100],
        ];
    }

    private function createSchema(): void
    {
        Schema::create('v2_order', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('plan_id')->default(0);
            $table->unsignedInteger('payment_id')->nullable();
            $table->unsignedTinyInteger('type')->default(1);
            $table->string('period')->default('month_price');
            $table->string('trade_no', 36)->unique();
            $table->string('callback_no')->nullable();
            $table->integer('total_amount')->default(100);
            $table->integer('handling_amount')->nullable();
            $table->integer('balance_amount')->nullable();
            $table->unsignedTinyInteger('status')->default(0);
            $table->integer('paid_at')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
        });
        Schema::create('v2_user', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('balance')->default(0);
            $table->boolean('is_admin')->default(false);
            $table->boolean('is_staff')->default(false);
            $table->unsignedBigInteger('telegram_id')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
        });
        Schema::create('v2_plan', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
        Schema::create('v2_payment', function (Blueprint $table) {
            $table->increments('id');
            $table->string('uuid')->unique();
            $table->string('payment');
            $table->text('config');
            $table->boolean('enable')->default(true);
            $table->string('notify_domain')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
        });
    }

    private function dropSchema(): void
    {
        Schema::dropIfExists('v2_payment');
        Schema::dropIfExists('v2_plan');
        Schema::dropIfExists('v2_order');
        Schema::dropIfExists('v2_user');
    }

    private function makeOrder(array $attributes = []): Order
    {
        static $sequence = 0;
        $sequence++;

        return Order::create(array_merge([
            'user_id' => 1,
            'plan_id' => 0,
            'type' => 1,
            'period' => 'month_price',
            'trade_no' => 'test-order-' . $sequence,
            'total_amount' => 100,
            'status' => 0,
            'created_at' => self::NOW - 100,
            'updated_at' => self::NOW - 100,
        ], $attributes));
    }

    private function makeUser(array $attributes = []): User
    {
        return User::create(array_merge([
            'balance' => 0,
            'is_admin' => 0,
            'is_staff' => 0,
            'telegram_id' => null,
            'created_at' => self::NOW - 100,
            'updated_at' => self::NOW - 100,
        ], $attributes));
    }

    private function makePayment(array $attributes = []): Payment
    {
        static $sequence = 0;
        $sequence++;

        return Payment::create(array_merge([
            'uuid' => 'test-payment-' . $sequence,
            'payment' => 'OrderExpiryTestPayment',
            'config' => [],
            'enable' => 1,
            'notify_domain' => null,
            'created_at' => self::NOW - 100,
            'updated_at' => self::NOW - 100,
        ], $attributes));
    }

    private function checkoutRequest(Order $order, ?int $paymentId = null): Request
    {
        return Request::create('/api/v1/user/order/checkout', 'POST', [
            'trade_no' => $order->trade_no,
            'method' => $paymentId,
            'user' => ['id' => $order->user_id],
        ]);
    }

    private function expectPaymentAnomalyLog(
        string $event,
        Order $order,
        string $callbackNo
    ): void {
        Log::shouldReceive('channel')
            ->once()
            ->with('daily')
            ->andReturnSelf();
        Log::shouldReceive('error')
            ->once()
            ->with($event, [
                'trade_no' => $order->trade_no,
                'callback_no' => $callbackNo,
                'order_id' => $order->id,
                'expires_at' => $order->expires_at,
                'received_at' => self::NOW,
            ]);
    }

    private function notifyPaymentCallback(Order $order, string $callbackNo)
    {
        $payment = $this->makePayment();
        $request = Request::create('/api/v1/guest/payment/notify', 'POST', [
            'trade_no' => $order->trade_no,
            'callback_no' => $callbackNo,
            'merchant_key' => 'must-not-be-logged',
            'signature' => 'must-not-be-logged',
            'payment_url' => 'must-not-be-logged',
            'qr_payload' => 'must-not-be-logged',
        ]);

        return (new PaymentController())->notify(
            'OrderExpiryTestPayment',
            $payment->uuid,
            $request
        );
    }

    private function invokePaymentHandle(
        PaymentController $controller,
        Order $order,
        string $callbackNo
    ): bool {
        $handle = new ReflectionMethod(PaymentController::class, 'handle');
        $handle->setAccessible(true);

        return $handle->invoke($controller, $order->trade_no, $callbackNo);
    }
}

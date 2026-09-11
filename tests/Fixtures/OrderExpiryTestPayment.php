<?php

namespace App\Payments;

class OrderExpiryTestPayment
{
    public static $payCalls = 0;

    public function __construct($config)
    {
    }

    public function pay($order)
    {
        self::$payCalls++;

        return [
            'type' => 0,
            'data' => 'test-qr-payload'
        ];
    }

    public function notify($params)
    {
        return [
            'trade_no' => $params['trade_no'],
            'callback_no' => $params['callback_no'],
            'custom_result' => 'PROVIDER_OK'
        ];
    }
}

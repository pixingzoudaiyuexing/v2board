<?php

namespace App\Services;


use App\Models\Payment;

class PaymentService
{
    public $method;
    protected $class;
    protected $config;
    protected $payment;

    public function __construct($method, $id = NULL, $uuid = NULL)
    {
        $this->method = $method;
        $this->class = '\\App\\Payments\\' . $this->method;
        if (!class_exists($this->class)) abort(500, 'gate is not found');
        if ($id) $payment = Payment::find($id)->toArray();
        if ($uuid) $payment = Payment::where('uuid', $uuid)->first()->toArray();
        $this->config = [];
        if (isset($payment)) {
            $this->config = $payment['config'];
            $this->config['enable'] = $payment['enable'];
            $this->config['id'] = $payment['id'];
            $this->config['uuid'] = $payment['uuid'];
            $this->config['notify_domain'] = $payment['notify_domain'];
        };
        $this->payment = new $this->class($this->config);
    }

    public function notify($params)
    {
        if (!$this->config['enable']) abort(500, 'gate is not enable');
        return $this->payment->notify($params);
    }

    public function pay($order)
    {
        // custom notify domain name
        $notifyUrl = url("/api/v1/guest/payment/notify/{$this->method}/{$this->config['uuid']}");
        if ($this->config['notify_domain']) {
            $parseUrl = parse_url($notifyUrl);
            $notifyUrl = $this->config['notify_domain'] . $parseUrl['path'];
        }

        return $this->payment->pay([
            'notify_url' => $notifyUrl,
            // 生产环境支付完成页自定义：支付平台完成同步跳转后，用户浏览器统一进入既有完成页。
            // 此处只替换 return_url，不影响 notify_url、异步回调、交易验证或订单状态确认；后续同步 wyx2685/v2board 时需确认支付参数结构变化后该 return_url 仍被正确传递。
            'return_url' => 'https://paycompleted.pages.dev/',
            'trade_no' => $order['trade_no'],
            'total_amount' => $order['total_amount'],
            'user_id' => $order['user_id'],
            'stripe_token' => $order['stripe_token']
        ]);
    }

    public function form()
    {
        $form = $this->payment->form();
        $keys = array_keys($form);
        foreach ($keys as $key) {
            if (isset($this->config[$key])) $form[$key]['value'] = $this->config[$key];
        }
        return $form;
    }
}

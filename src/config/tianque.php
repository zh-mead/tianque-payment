<?php
return [
    //机构编号
    'orgId' => '08770583',
    //商户编号
    'mno' => '399230525900232',
    //微信子公众号
    'subAppid' => 'wx0e09e453a535e660',
    //支付结果通知地址不上送则交易成功后，无异步交易结果通知
    'notifyUrl' => env('APP_URL') . '/api/payments/tianque-notify',
    'refundNotifyUrl' => env('APP_URL') . '/api/payments/tianque-refund-notify',
    'privateKeyPath' => base_path() . '/tianque-cert/pri.key',
    'publicKeyPath' => base_path() . '/tianque-cert/pub.key',
];
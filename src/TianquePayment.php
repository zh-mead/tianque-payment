<?php

namespace ZhMead\TianquePayment;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use ZhMead\TianquePayment\Kernel\AopClient;
use ZhMead\TianquePayment\Kernel\BaseClient;

class TianquePayment extends BaseClient
{
    protected $url = 'https://openapi.tianquetech.com';
    protected $userConfig = [];
    protected $AopClient = null;

    public function __construct(array $config = [])
    {
        $this->userConfig = $config;
        $this->AopClient = new AopClient($this->url, $config['orgId'], $config['privateKeyPath']);
    }

    /**
     * 统一下单
     * @param array $params
     * @param $isContract
     * @return array
     */
    public function unify(array $wxParams, $payType = 'WECHAT')
    {
        $validator = Validator::make($wxParams, [
            'out_trade_no' => 'required|string|max:64',
            'openid' => 'required|string',
            'body' => 'required|string',
            'attach' => 'required|string',
            'total_fee' => 'required|numeric'
        ]);

        if ($validator->fails()) {
            throw  new \Exception($validator->getMessageBag());
        }

        $payWay = '02';
        if ($payType == 'WECHAT') $payWay = '03';

        $params = [
            'mno' => Arr::get($wxParams, 'mno', $this->userConfig['mno']),
            'ordNo' => $wxParams['out_trade_no'],
            'amt' => bcdiv($wxParams['total_fee'], 100, 2),
            'payType' => $payType,
            'payWay' => $payWay,
            'subject' => $wxParams['body'],
            'trmIp' => '',

            'notifyUrl' => Arr::get($wxParams, 'notify_url', $this->userConfig['notifyUrl']),
            'subAppid' => Arr::get($wxParams, 'subAppid', $this->userConfig['subAppid']),
            'userId' => $wxParams['openid'],
            'extend' => $wxParams['attach'],
        ];

        if (empty($params['trmIp'])) {
            $params['trmIp'] = $this->get_client_ip();
        }

        $resp = $this->AopClient->request('/order/jsapiScan', $params);

        $resp = json_decode($resp, true);
        $return_code = 'SUCCESS';
        $result_code = 'SUCCESS';
        $return_msg = $resp['msg'];
        if ($resp['code'] !== '0000') {
            $re = [];
            switch ($return_msg) {
                case 'ordNo不能重复':
                    $re = [
                        'return_code' => $return_code,
                        'result_code' => 'FAIL',
                        'err_code' => 'OUT_TRADE_NO_USED',
                    ];
                    break;
                default:
                    $re = [
                        'return_code' => 'FAIL',
                        'return_msg' => $return_msg,
                    ];
                    break;

            }
            return $re;
        }
        $respData = $resp['respData'];

        if ($respData['bizCode'] !== '0000') {
            $re = [
                'return_code' => 'SUCCESS',
                'result_code' => 'FAIL',
                'err_code' => $respData['bizCode'],
                'err_code_des' => $respData['bizMsg'],
            ];
            throw new \Exception($respData['bizMsg']);
        }
        return [
            'return_code' => $return_code,
            'result_code' => $result_code,
            "appid" => $respData['payAppId'],
            "nonce_str" => $respData['paynonceStr'],
            "sign" => $respData['paySign'],
            "prepay_id" => $respData['payPackage'],
            "payTimeStamp" => $respData['payTimeStamp'],
//            'respData' => $respData
        ];
    }

    /**
     * 退款
     * @param $orderNo
     * @param $refundNo
     * @param $orderMoney
     * @param $refundMoney
     * @param $params
     * @return array
     */
    public function byOutTradeNumber($orderNo, $refundNo, $orderMoney, $refundMoney, $params = [])
    {
        $params = [
            'mno' => Arr::get($params, 'mno', $this->userConfig['mno']),
            'ordNo' => $refundNo,
            'origOrderNo' => $orderNo,
            'amt' => bcdiv($refundMoney, 100, 2),
            'notifyUrl' => Arr::get($params, 'notify_url', $this->userConfig['refundNotifyUrl']),
            'refundReason' => Arr::get($params, 'refund_desc', '业务退款'),
        ];

        $resp = $this->AopClient->request('/order/refund', $params);

        $resp = json_decode($resp, true);
        $return_code = 'SUCCESS';
        $result_code = 'SUCCESS';
        if ($resp['code'] !== '0000') {
            throw new \Exception($resp['msg']);
        }

        $respData = $resp['respData'];
        if ($respData['bizCode'] !== '0000') {
            throw new \Exception($respData['bizMsg']);
        }
        return [
            'return_code' => $return_code,
            'result_code' => $result_code,
            'respData' => $respData
        ];
    }

    /**
     * 关闭订单
     * @param $orderNo
     * @return array
     */
    public function close($orderNo, $params = [])
    {
        $params = [
            'mno' => Arr::get($params, 'mno', $this->userConfig['mno']),
            'origOrderNo' => $orderNo,
        ];

        $resp = $this->AopClient->request('/query/close', $params);
        return $this->tq2wx($resp);
    }

    public function queryByOutTradeNumber($orderNo)
    {

    }

    /**
     * 根据退单号查询退款状态
     * @param $refundNo
     * @param array $params
     * @return array
     */
    public function queryByOutRefundNumber($refundNo, array $params = [])
    {
        $params = [
            'mno' => Arr::get($params, 'mno', $this->userConfig['mno']),
            'ordNo' => $refundNo,
        ];
        $resp = $this->AopClient->request('/query/refundQuery', $params);

        $resp = json_decode($resp, true);
        if ($resp['code'] !== '0000') {
            if ($resp['msg'] === '上送订单信息不匹配，请查验参数是否正确') {
                return [
                    'return_code' => 'FAIL',
                    'result_code' => 'FAIL',
                    'err_code' => 'REFUNDNOTEXIST',
                    'err_code_des' => '上送订单信息不匹配，请查验参数是否正确',
                ];

            }
            return [
                'return_code' => 'FAIL',
                'result_code' => 'FAIL',
                'err_code' => $resp['code'],
                'err_code_des' => $resp['msg'],
            ];
//            throw new \Exception($resp['msg']);
        }
        $respData = $resp['respData'];
        if ($respData['bizCode'] !== '0000') {
            return [
                'return_code' => 'FAIL',
                'result_code' => 'FAIL',
                'err_code' => $respData['bizCode'],
                'err_code_des' => $respData['bizMsg'],
            ];
//            throw new \Exception($data['bizMsg']);
        }
        return [
            'return_code' => 'SUCCESS',
            'result_code' => 'SUCCESS',
            'refund_success_time_0' => date("Y-m-d H:i:s", strtotime($respData['payTime'])),
            'refund_status_0' => 'SUCCESS',
            'respData' => $respData
        ];
    }

    /**
     * 支付结果回调
     * @param \Closure $closure
     * @return \Symfony\Component\HttpFoundation\Response|void
     */
    public function handlePaidNotify(\Closure $closure)
    {
        $data = request()->all();
        if ($data['bizCode'] !== '0000') throw new \Exception($data['bizMsg']);
        $message = [
            'return_code' => 'SUCCESS',
            'result_code' => 'SUCCESS',
            'uuid' => $data['uuid'],
            'out_trade_no' => $data['ordNo'],
            'total_fee' => $data['amt'] * 100,
            'attach' => $data['extend'],
        ];
        $re = \call_user_func($closure, $message, $data);
        if ($re) return $this->AopClient->toResponse();
    }

    /**
     * 退款结果回调
     * @param \Closure $closure
     * @return \Symfony\Component\HttpFoundation\Response|void
     */
    public function handleRefundedNotify(\Closure $closure)
    {
        $data = request()->all();
        if ($data['bizCode'] !== '0000') throw new \Exception($data['bizMsg']);
        $message = [
            'return_code' => 'SUCCESS',
            'result_code' => 'SUCCESS',
            'uuid' => $data['uuid'],
            'out_trade_no' => $data['origOrdNo'],
            'out_refund_no' => $data['ordNo'],
            'total_fee' => $data['amt'] * 100,
        ];
        $re = \call_user_func($closure, $message, $data);
        if ($re) return $this->AopClient->toResponse();
    }

    /**
     * 查询余额
     * @param $mno
     * @return void
     */
    public function queryBalance($mno = false)
    {
        $params = [
            'mno' => $mno ?? $this->userConfig['mno'],
        ];

        $resp = $this->AopClient->request('/capital/query/queryBalance', $params);
        dd($resp);
    }
}
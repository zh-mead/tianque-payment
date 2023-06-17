<?php

namespace ZhMead\TianquePayment;

use Illuminate\Support\Arr;
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
            'total_fee' => 'required|numeric',
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
            'ledgerAccountFlag' => Arr::get($wxParams, 'ledgerAccountFlag', '01'),
            'trmIp' => '',

            'notifyUrl' => Arr::get($wxParams, 'notify_url', $this->userConfig['notifyUrl']),
            'subAppid' => Arr::get($wxParams, 'subAppid', $this->userConfig['subAppid']),
            'userId' => $wxParams['openid'],
            'extend' => $wxParams['attach'],
        ];

        //分账
        if (isset($wxParams['ledgerAccountFlag']) && in_array($wxParams['ledgerAccountFlag'], ['00', '04'])) $params['ledgerAccountEffectTime'] = Arr::get($wxParams, 'ledgerAccountEffectTime', '30');

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

    /**
     * 添加商户
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function addMerchant(array $data = [])
    {
        $validator = Validator::make($data, [
            'applyNo' => 'required|string|max:32',
            'projectNo' => 'required|string',
            'mno' => 'required|string',
            'role' => 'required|string',
            'balanceLedgerFlag' => 'sometimes|string',
            'cancelFlag' => 'sometimes|string',
            'businessDescPicId' => 'sometimes|string',
            'otherProvePicId' => 'sometimes|string',
            'remark' => 'sometimes|string',
            'callbackUrl' => 'sometimes|string',
        ]);

        if ($validator->fails()) {
            throw  new \Exception($validator->getMessageBag());
        }

        $params = [
            'applyNo' => $data['applyNo'],
            'projectNo' => $data['projectNo'],
            'mno' => $data['mno'],
            'role' => $data['role'],
        ];

        if (isset($params['balanceLedgerFlag'])) $params['balanceLedgerFlag'] = $data['balanceLedgerFlag'];
        if (isset($params['cancelFlag'])) $params['cancelFlag'] = $data['cancelFlag'];
        if (isset($params['otherProvePicId'])) $params['balanceLedgerFlag'] = $data['otherProvePicId'];
        if (isset($params['businessDescPicId'])) $params['businessDescPicId'] = $data['businessDescPicId'];
        if (isset($params['remark'])) $params['remark'] = $data['remark'];

        $resp = $this->AopClient->request('/capital/fundProject/merJoin', $params);

        $resp = json_decode($resp, true);
        if ($resp['code'] !== '0000') {
            throw new \Exception($resp['msg']);
        }

        $respData = $resp['respData'];
        if ($respData['bizCode'] !== '0000') {
            throw new \Exception($respData['bizMsg']);
        }
        return [
            'status' => 0,
            'applyNo' => $respData['applyNo'],
            'applyStatus' => $respData['applyStatus']
        ];
    }

    /**
     * 变更商户
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function changeMerchant(array $data = [])
    {
        $validator = Validator::make($data, [
            'applyNo' => 'required|string|max:32',
            'projectNo' => 'required|string',
            'mno' => 'required|string',
            'role' => 'required|string',
            'balanceLedgerFlag' => 'sometimes|string',
            'cancelFlag' => 'sometimes|string',
            'businessDescPicId' => 'sometimes|string',
            'otherProvePicId' => 'sometimes|string',
            'remark' => 'sometimes|string',
            'callbackUrl' => 'required|string',
        ]);

        if ($validator->fails()) {
            throw  new \Exception($validator->getMessageBag());
        }

        $params = [
            'applyNo' => $data['applyNo'],
            'projectNo' => $data['projectNo'],
            'mno' => $data['mno'],
            'role' => $data['role'],
        ];

        if (isset($params['balanceLedgerFlag'])) $params['balanceLedgerFlag'] = $data['balanceLedgerFlag'];
        if (isset($params['cancelFlag'])) $params['cancelFlag'] = $data['cancelFlag'];
        if (isset($params['otherProvePicId'])) $params['balanceLedgerFlag'] = $data['otherProvePicId'];
        if (isset($params['businessDescPicId'])) $params['businessDescPicId'] = $data['businessDescPicId'];
        if (isset($params['remark'])) $params['remark'] = $data['remark'];

        $resp = $this->AopClient->request('/capital/fundProject/merChange', $params);

        $resp = json_decode($resp, true);
        if ($resp['code'] !== '0000') {
            throw new \Exception($resp['msg']);
        }

        $respData = $resp['respData'];
        if ($respData['bizCode'] !== '0000') {
            throw new \Exception($respData['bizMsg']);
        }
        return [
            'applyNo' => $respData['applyNo'],
            'applyStatus' => $respData['applyStatus']
        ];
    }

    /**
     * 变更商户
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function queryMerchant(string $applyNo)
    {
        $params = [
            'applyNo' => $applyNo,
        ];

        $resp = $this->AopClient->request('/capital/fundProject/applyDetail', $params);

        $resp = json_decode($resp, true);
        if ($resp['code'] !== '0000') {
            throw new \Exception($resp['msg']);
        }

        $respData = $resp['respData'];
        if ($respData['bizCode'] !== '0000') {
            throw new \Exception($respData['bizMsg']);
        }

        return [
            'applyNo' => $respData['applyNo'],
            'applyStatus' => $respData['applyStatus'],
            'auditTime' => $respData['auditTime'],
            'auditSuggest' => $respData['auditSuggest'],
        ];
    }

    /**
     * 订单分账商户协议签署
     * @param array $data
     * @return array
     * @throws \Exception
     */
    public function orderMerchantSign(array $data = [])
    {
        $validator = Validator::make($data, [
            'mno' => 'required|string',
            'signType' => 'required|string',
        ]);

        if ($validator->fails()) {
            throw  new \Exception($validator->getMessageBag());
        }

        $params = [
            'mno' => $data['mno'],
            'signType' => $data['signType'],
        ];

        if (isset($params['ledgerLetter'])) $params['ledgerLetter'] = $data['ledgerLetter'];

        $resp = $this->AopClient->request('/merchant/sign/getUrl', $params);

        $resp = json_decode($resp, true);
        if ($resp['code'] !== '0000') {
            throw new \Exception($resp['msg']);
        }

        $respData = $resp['respData'];
        if ($respData['bizCode'] !== '0000') {
            throw new \Exception($respData['bizMsg']);
        }

        return [
            'retUrl' => $respData['retUrl'],
        ];
    }

    /**
     * 订单分账商户申请结果查询
     * @param string $mno
     * @return array
     * @throws \Exception
     */
    public function queryOrderMerchant($mno = false)
    {
        $params = [
            'mno' => $mno ?? $this->userConfig['mno'],
        ];

        $resp = $this->AopClient->request('/merchant/sign/querySignContract', $params);

        $resp = json_decode($resp, true);
        if ($resp['code'] !== '0000') {
            throw new \Exception($resp['msg']);
        }

        $respData = $resp['respData'];
        if ($respData['bizCode'] !== '0000') {
            throw new \Exception($respData['bizMsg']);
        }

        return [
            'signResult' => $respData['signResult'],
            'signTime' => $respData['signTime'],
        ];
    }

    /**
     * 订单商户分账设置
     * @param string $mno
     * @param string $mnoArray
     * @return array
     * @throws \Exception
     */
    public function setOrderMerchants(string $mnoArray, $mno = false)
    {
        $params = [
            'mno' => $mno ?? $this->userConfig['mno'],
            'mnoArray' => $mnoArray,
        ];

        $resp = $this->AopClient->request('/query/ledger/setMnoArray', $params);

        $resp = json_decode($resp, true);
        if ($resp['code'] !== '0000') {
            throw new \Exception($resp['msg']);
        }

        $respData = $resp['respData'];
        if ($respData['bizCode'] !== '0000') {
            throw new \Exception($respData['bizMsg']);
        }

        return $respData;
    }

    /**
     * 订单分账
     * @param string $mno
     * @param string $mnoArray
     * @return array
     * @throws \Exception
     */
    public function orderLedger(array $data = [])
    {
        $validator = Validator::make($data, [
//            'mno' => 'required|string',
            'ordNo' => 'required|string',
            'uuid' => 'required|string',
            'ledgerAccountFlag' => 'required|string',
            'ledgerRule' => 'required|string',
        ]);

        if ($validator->fails()) {
            throw  new \Exception($validator->getMessageBag());
        }

        $params = [
            'mno' => Arr::get($data, 'mno', $this->userConfig['mno']),
            'ordNo' => $data['ordNo'],
            'uuid' => $data['uuid'],
            'ledgerAccountFlag' => $data['ledgerAccountFlag'],
            'ledgerRule' => $data['ledgerRule'],
        ];

        if (isset($params['notifyAddress'])) $params['notifyAddress'] = $data['notifyAddress'];
        if (isset($params['thirdPartyUuid'])) $params['thirdPartyUuid'] = $data['thirdPartyUuid'];

        $resp = $this->AopClient->request('/query/ledger/launchLedger', $params);

        $resp = json_decode($resp, true);
        if ($resp['code'] !== '0000') {
            throw new \Exception($resp['msg']);
        }

        $respData = $resp['respData'];
        if ($respData['bizCode'] !== '0000') {
            throw new \Exception($respData['bizMsg']);
        }

        return $respData;
    }

    /**
     * 订单分账分账结果查询
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function queryOrderLedger(array $data = [])
    {
        $validator = Validator::make($data, [
//            'mno' => 'required|string',
            'ordNo' => 'required|string',
        ]);

        if ($validator->fails()) {
            throw  new \Exception($validator->getMessageBag());
        }

        $params = [
            'mno' => Arr::get($data, 'mno', $this->userConfig['mno']),
            'ordNo' => $data['ordNo'],
        ];

        if (isset($params['refundOrdNo'])) $params['refundOrdNo'] = $data['refundOrdNo'];
        if (isset($params['thirdPartyUuid'])) $params['thirdPartyUuid'] = $data['thirdPartyUuid'];
        if (isset($params['isCardLedge'])) $params['isCardLedge'] = $data['isCardLedge'];

        $resp = $this->AopClient->request('/query/ledger/queryLedgerAccount', $params);

        $resp = json_decode($resp, true);
        if ($resp['code'] !== '0000') {
            throw new \Exception($resp['msg']);
        }

        $respData = $resp['respData'];
        if ($respData['bizCode'] !== '0000') {
            throw new \Exception($respData['bizMsg']);
        }

        return $respData;
    }

    /**
     * 订单分账分账结果查询
     * @param array $data
     * @return mixed
     * @throws \Exception
     */
    public function queryLedgerAmt(array $data = [])
    {
        $validator = Validator::make($data, [
//            'mno' => 'required|string',
            'ordNo' => 'required|string',
        ]);

        if ($validator->fails()) {
            throw  new \Exception($validator->getMessageBag());
        }

        $params = [
            'mno' => Arr::get($data, 'mno', $this->userConfig['mno']),
            'ordNo' => $data['ordNo'],
        ];

        $resp = $this->AopClient->request('/query/ledger/queryLedgerAmt', $params);

        $resp = json_decode($resp, true);
        if ($resp['code'] !== '0000') {
            throw new \Exception($resp['msg']);
        }

        $respData = $resp['respData'];
        if ($respData['bizCode'] !== '0000') {
            throw new \Exception($respData['bizMsg']);
        }

        return $respData;
    }

    /**
     * 订单分账结果回调
     * @param \Closure $closure
     * @return \Symfony\Component\HttpFoundation\Response|void
     */
    public function orderLedgerNotify(\Closure $closure)
    {
        $data = request()->all();
//        if ($data['bizCode'] !== '0000') throw new \Exception($data['bizMsg']);
        $message = [
            'return_code' => 'SUCCESS',
            'result_code' => 'SUCCESS',
        ];
        $re = \call_user_func($closure, $message, $data);
        if ($re) return $this->AopClient->toResponse();
    }
}
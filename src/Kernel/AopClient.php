<?php

namespace ZhMead\TianquePayment\Kernel;

use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Str;

class AopClient
{
    //返回数据格式
    public $format = "json";
    //api版本
    public $apiVersion = "1.0";

    // 表单提交字符集编码
    public $postCharset = "UTF-8";

    public $debugInfo = false;
    public $signType = "RSA";
    public $encryptKey;
    public $encryptType = "AES";
    public $url = "";
    //加密XML节点名称
    public $orgId = "";
    public $privateKey = "";
    //签名类型
    public $privateKeyPath = "";

    //加密密钥和类型
    private $fileCharset = "UTF-8";
    private $RESPONSE_SUFFIX = "_response";
    private $ERROR_RESPONSE = "error_response";
    private $SIGN_NODE_NAME = "sign";
    private $ENCRYPT_XML_NODE_NAME = "response_encrypted";
    private $needEncrypt = false;

    public function __construct($url, $orgId, $privateKeyPath)
    {
        $this->url = $url;
        $this->orgId = $orgId;
        $this->privateKey = $privateKeyPath;
        $this->privateKeyPath = $privateKeyPath;
    }

    public function rsaSign($params, $privateKey, $signType = "RSA")
    {
        return $this->sign($this->getSignContent($params), $privateKey, $signType);
    }

    protected function sign($data, $rsaPrivateKey, $signType = "RSA")
    {
//        $res = "-----BEGIN RSA PRIVATE KEY-----\n" .
//            wordwrap($rsaPrivateKey, 64, "\n", true) .
//            "\n-----END RSA PRIVATE KEY-----";

        $res = file_get_contents($rsaPrivateKey);

        ($res) or die('您使用的私钥格式错误，请检查RSA私钥配置');

        if ("RSA2" == $signType) {
            openssl_sign($data, $sign, $res, OPENSSL_ALGO_SHA256);
        } else {
            openssl_sign($data, $sign, $res);
        }

        $sign = base64_encode($sign);
        return $sign;
    }

    public function getSignContent($params)
    {
        ksort($params);
        $stringToBeSigned = "";
        foreach ($params as $k => $v) {
            $isarray = is_array($v);
            if ($isarray) {
                $stringToBeSigned .= "$k" . "=" . json_encode($v, 320) . "&";
            } else {
                $stringToBeSigned .= "$k" . "=" . "$v" . "&";
            }
        }
        unset ($k, $v);
        $stringToBeSigned = substr($stringToBeSigned, 0, strlen($stringToBeSigned) - 1);

        return $stringToBeSigned;
    }

    function unicodeDecode($name)
    {
        $json = '{"str":"' . $name . '"}';
        $arr = json_decode($json, true);
        if (empty($arr)) return '';
        return $arr['str'];
    }

    /**
     * 发送请求
     * @param string $urlPath
     * @param array $params
     * @return bool|string
     */
    public function request(string $urlPath, array $params)
    {
        $content = $this->getContent($params, $this->orgId);
        $content['sign'] = $this->generateSign($content, $this->privateKey);

        $reqStr = json_encode($content, 320);
        $requestUrl = $this->url . $urlPath;
        $resp = $this->curl($requestUrl, $reqStr);

        return $resp;
    }

    /**
     * 组装数据包
     * @param $params
     * @param $orgId
     * @param $signType
     * @return array
     */
    public function getContent($params, $orgId, $signType = "RSA")
    {
        return [
            "orgId" => $orgId,
            "reqData" => $params,
            "reqId" => strtolower(Str::random(32)),
            "signType" => $signType,
            "timestamp" => date("YmdHis"),
            "version" => $this->apiVersion,
        ];
    }

//废弃

    /**
     * 生成签名
     * @param $params
     * @param $privateKey
     * @param $signType
     * @return string|void
     */
    public function generateSign($params, $privateKey, $signType = "RSA")
    {
        return $this->sign($this->getSignContent($params), $privateKey, $signType);
    }

    /**
     * 发送请求
     * @param $url
     * @param $postBodyString
     * @return bool|string
     */
    public function curl($url, $postBodyString = null)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_FAILONERROR, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $encodeArray = array();
        $postMultipart = false;

        //echo  $postBodyString;

        unset ($k, $v);
        curl_setopt($ch, CURLOPT_POST, true);

        curl_setopt($ch, CURLOPT_POSTFIELDS, $postBodyString);
        $this->writeLog("postBodyString" . $postBodyString);
        $headers = array('content-type:application/json;charset=' . $this->postCharset);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $reponse = curl_exec($ch);

        //echo  "reponse".$reponse;
        if (curl_errno($ch)) {
            throw new Exception(curl_error($ch), 0);
        } else {
            $httpStatusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if (200 !== $httpStatusCode) {
                throw new Exception($reponse, $httpStatusCode);
            }
        }

        curl_close($ch);
        $this->writeLog("responseBodyString" . $reponse);
        return $reponse;
    }

    function writeLog($text)
    {
        $day = date("Y-m-d");
        file_put_contents(base_path('storage/logs/tianque-' . $day . '.log'), date("Y-m-d H:i:s") . "  " . $text . "\r\n", FILE_APPEND);
    }

    /**
     * 转换字符集编码
     * @param $data
     * @param $targetCharset
     * @return string
     */
    function characet($data, $targetCharset)
    {

        if (!empty($data)) {
            $fileType = $this->fileCharset;
            if (strcasecmp($fileType, $targetCharset) != 0) {
                $data = mb_convert_encoding($data, $targetCharset, $fileType);
                //				$data = iconv($fileType, $targetCharset.'//IGNORE', $data);
            }
        }
        return $data;
    }

    //请确保项目文件有可写权限，不然打印不了日志。

    /** rsaCheckV1 & rsaCheckV2
     *  验证签名
     *  在使用本方法前，必须初始化AopClient且传入公钥参数。
     *  公钥是否是读取字符串还是读取文件，是根据初始化传入的值判断的。
     **/
    public function rsaCheckV1($params, $rsaPublicKeyFilePath, $signType = 'RSA')
    {
        $sign = $params['sign'];
        $params['sign_type'] = null;
        $params['sign'] = null;
        return $this->verify($this->getSignContent($params), $sign, $rsaPublicKeyFilePath, $signType);
    }

    function verify($paramStr, $sign, $rsaPublicKey)
    {
        // $pubKey = $this->$rsaPublicKey;
        //将字符串格式公私钥转为pem格式公私钥
        $pubKeyPem = $this->format_secret_key($rsaPublicKey, 'pub');
        //转换为openssl密钥，必须是没有经过pkcs8转换的公钥
        $res = openssl_get_publickey($pubKeyPem);
        //url解码签名
        //$signUrl = urldecode($sign);
        //base64解码签名
        $signBase64 = base64_decode($sign);
        //调用openssl内置方法验签，返回bool值
        $result = (bool)openssl_verify($paramStr, $signBase64, $res);
        //释放资源
        openssl_free_key($res);
        //返回资源是否成功
        return $result;
    }

    function format_secret_key($secret_key, $type)
    {
        // 64个英文字符后接换行符"\n",最后再接换行符"\n"
        $key = (wordwrap($secret_key, 64, "\n", true)) . "\n";
        // 添加pem格式头和尾
        if ($type == 'pub') {
            $pem_key = "-----BEGIN PUBLIC KEY-----\n" . $key . "-----END PUBLIC KEY-----\n";
        } else if ($type == 'pri') {
            $pem_key = "-----BEGIN RSA PRIVATE KEY-----\n" . $key . "-----END RSA PRIVATE KEY-----\n";
        } else {
            echo('公私钥类型非法');
            exit();
        }
        return $pem_key;
    }

    public function checkSignAndDecrypt($params, $rsaPublicKeyPem, $rsaPrivateKeyPem, $isCheckSign, $isDecrypt)
    {
        $charset = $params['charset'];
        $bizContent = $params['biz_content'];
        if ($isCheckSign) {
            if (!$this->rsaCheckV2($params, $rsaPublicKeyPem)) {
                echo "<br/>checkSign failure<br/>";
                exit;
            }
        }
        if ($isDecrypt) {
            return $this->rsaDecrypt($bizContent, $rsaPrivateKeyPem, $charset);
        }

        return $bizContent;
    }

    public function rsaCheckV2($params, $rsaPublicKeyFilePath, $signType = 'RSA')
    {
        $sign = $params['sign'];
        $params['sign'] = null;
        return $this->verify($this->getSignContent($params), $sign, $rsaPublicKeyFilePath, $signType);
    }

    public function rsaDecrypt($data, $rsaPrivateKeyPem, $charset)
    {
        //读取私钥文件
        $priKey = file_get_contents($rsaPrivateKeyPem);
        //转换为openssl格式密钥
        $res = openssl_get_privatekey($priKey);
        $decodes = explode(',', $data);
        $strnull = "";
        $dcyCont = "";
        foreach ($decodes as $n => $decode) {
            if (!openssl_private_decrypt($decode, $dcyCont, $res)) {
                echo "<br/>" . openssl_error_string() . "<br/>";
            }
            $strnull .= $dcyCont;
        }
        return $strnull;
    }

    public function encryptAndSign($bizContent, $rsaPublicKeyPem, $rsaPrivateKeyPem, $charset, $isEncrypt, $isSign)
    {
        // 加密，并签名
        if ($isEncrypt && $isSign) {
            $encrypted = $this->rsaEncrypt($bizContent, $rsaPublicKeyPem, $charset);
            $sign = $this->sign($bizContent);
            $response = "<?xml version=\"1.0\" encoding=\"$charset\"?><alipay><response>$encrypted</response><encryption_type>RSA</encryption_type><sign>$sign</sign><sign_type>RSA</sign_type></alipay>";
            return $response;
        }
        // 加密，不签名
        if ($isEncrypt && (!$isSign)) {
            $encrypted = $this->rsaEncrypt($bizContent, $rsaPublicKeyPem, $charset);
            $response = "<?xml version=\"1.0\" encoding=\"$charset\"?><alipay><response>$encrypted</response><encryption_type>RSA</encryption_type></alipay>";
            return $response;
        }
        // 不加密，但签名
        if ((!$isEncrypt) && $isSign) {
            $sign = $this->sign($bizContent);
            $response = "<?xml version=\"1.0\" encoding=\"$charset\"?><alipay><response>$bizContent</response><sign>$sign</sign><sign_type>RSA</sign_type></alipay>";
            return $response;
        }
        // 不加密，不签名
        $response = "<?xml version=\"1.0\" encoding=\"$charset\"?>$bizContent";
        return $response;
    }

    public function rsaEncrypt($data, $rsaPublicKeyPem, $charset)
    {
        //读取公钥文件
        $pubKey = file_get_contents($rsaPublicKeyPem);
        //转换为openssl格式密钥
        $res = openssl_get_publickey($pubKey);
        $blocks = $this->splitCN($data, 0, 30, $charset);
        $chrtext  = null;
        $encodes  = array();
        foreach ($blocks as $n => $block) {
            if (!openssl_public_encrypt($block, $chrtext , $res)) {
                echo "<br/>" . openssl_error_string() . "<br/>";
            }
            $encodes[] = $chrtext ;
        }
        $chrtext = implode(",", $encodes);

        return $chrtext;
    }

    function splitCN($cont, $n = 0, $subnum, $charset)
    {
        //$len = strlen($cont) / 3;
        $arrr = array();
        for ($i = $n; $i < strlen($cont); $i += $subnum) {
            $res = $this->subCNchar($cont, $i, $subnum, $charset);
            if (!empty ($res)) {
                $arrr[] = $res;
            }
        }

        return $arrr;
    }

    function subCNchar($str, $start = 0, $length, $charset = "gbk")
    {
        if (strlen($str) <= $length) {
            return $str;
        }
        $re['utf-8'] = "/[\x01-\x7f]|[\xc2-\xdf][\x80-\xbf]|[\xe0-\xef][\x80-\xbf]{2}|[\xf0-\xff][\x80-\xbf]{3}/";
        $re['gb2312'] = "/[\x01-\x7f]|[\xb0-\xf7][\xa0-\xfe]/";
        $re['gbk'] = "/[\x01-\x7f]|[\x81-\xfe][\x40-\xfe]/";
        $re['big5'] = "/[\x01-\x7f]|[\x81-\xfe]([\x40-\x7e]|\xa1-\xfe])/";
        preg_match_all($re[$charset], $str, $match);
        $slice = join("", array_slice($match[0], $start, $length));
        return $slice;
    }

    function parserJSONSignData($request, $responseContent, $responseJSON)
    {

        $signData = new SignData();

        $signData->sign = $this->parserJSONSign($responseJSON);
        $signData->signSourceData = $this->parserJSONSignSource($request, $responseContent);


        return $signData;

    }

    function parserJSONSign($responseJSon)
    {

        return $responseJSon->sign;
    }

    function parserJSONSignSource($request, $responseContent)
    {

        $apiName = $request->getApiMethodName();
        $rootNodeName = str_replace(".", "_", $apiName) . $this->RESPONSE_SUFFIX;

        $rootIndex = strpos($responseContent, $rootNodeName);
        $errorIndex = strpos($responseContent, $this->ERROR_RESPONSE);


        if ($rootIndex > 0) {

            return $this->parserJSONSource($responseContent, $rootNodeName, $rootIndex);
        } else if ($errorIndex > 0) {

            return $this->parserJSONSource($responseContent, $this->ERROR_RESPONSE, $errorIndex);
        } else {

            return null;
        }


    }

    function parserJSONSource($responseContent, $nodeName, $nodeIndex)
    {
        $signDataStartIndex = $nodeIndex + strlen($nodeName) + 2;
        $signIndex = strpos($responseContent, "\"" . $this->SIGN_NODE_NAME . "\"");
        // 签名前-逗号
        $signDataEndIndex = $signIndex - 1;
        $indexLen = $signDataEndIndex - $signDataStartIndex;
        if ($indexLen < 0) {

            return null;
        }

        return substr($responseContent, $signDataStartIndex, $indexLen);

    }

    function parserXMLSignData($request, $responseContent)
    {


        $signData = new SignData();

        $signData->sign = $this->parserXMLSign($responseContent);
        $signData->signSourceData = $this->parserXMLSignSource($request, $responseContent);


        return $signData;


    }

    function parserXMLSign($responseContent)
    {
        $signNodeName = "<" . $this->SIGN_NODE_NAME . ">";
        $signEndNodeName = "</" . $this->SIGN_NODE_NAME . ">";

        $indexOfSignNode = strpos($responseContent, $signNodeName);
        $indexOfSignEndNode = strpos($responseContent, $signEndNodeName);


        if ($indexOfSignNode < 0 || $indexOfSignEndNode < 0) {
            return null;
        }

        $nodeIndex = ($indexOfSignNode + strlen($signNodeName));

        $indexLen = $indexOfSignEndNode - $nodeIndex;

        if ($indexLen < 0) {
            return null;
        }

        // 签名
        return substr($responseContent, $nodeIndex, $indexLen);

    }

    function parserXMLSignSource($request, $responseContent)
    {


        $apiName = $request->getApiMethodName();
        $rootNodeName = str_replace(".", "_", $apiName) . $this->RESPONSE_SUFFIX;


        $rootIndex = strpos($responseContent, $rootNodeName);
        $errorIndex = strpos($responseContent, $this->ERROR_RESPONSE);
        //		$this->echoDebug("<br/>rootNodeName:" . $rootNodeName);
        //		$this->echoDebug("<br/> responseContent:<xmp>" . $responseContent . "</xmp>");


        if ($rootIndex > 0) {

            return $this->parserXMLSource($responseContent, $rootNodeName, $rootIndex);
        } else if ($errorIndex > 0) {

            return $this->parserXMLSource($responseContent, $this->ERROR_RESPONSE, $errorIndex);
        } else {

            return null;
        }


    }

    function parserXMLSource($responseContent, $nodeName, $nodeIndex)
    {
        $signDataStartIndex = $nodeIndex + strlen($nodeName) + 1;
        $signIndex = strpos($responseContent, "<" . $this->SIGN_NODE_NAME . ">");
        // 签名前-逗号
        $signDataEndIndex = $signIndex - 1;
        $indexLen = $signDataEndIndex - $signDataStartIndex + 1;

        if ($indexLen < 0) {
            return null;
        }

        return substr($responseContent, $signDataStartIndex, $indexLen);
    }

    /**
     * 验签
     * @param $request
     * @param $signData
     * @param $resp
     * @param $respObject
     * @throws Exception
     */
    public function checkResponseSign($request, $signData, $resp, $respObject)
    {
        if (!$this->checkEmpty($this->alipayPublicKey) || !$this->checkEmpty($this->alipayrsaPublicKey)) {

            if ($signData == null || $this->checkEmpty($signData->sign) || $this->checkEmpty($signData->signSourceData)) {
                throw new Exception(" check sign Fail! The reason : signData is Empty");
            }

            // 获取结果sub_code
            $responseSubCode = $this->parserResponseSubCode($request, $resp, $respObject, $this->format);
            if (!$this->checkEmpty($responseSubCode) || ($this->checkEmpty($responseSubCode) && !$this->checkEmpty($signData->sign))) {
                $checkResult = $this->verify($signData->signSourceData, $signData->sign, $this->alipayPublicKey, $this->signType);
                if (!$checkResult) {
                    if (strpos($signData->signSourceData, "\\/") > 0) {
                        $signData->signSourceData = str_replace("\\/", "/", $signData->signSourceData);
                        $checkResult = $this->verify($signData->signSourceData, $signData->sign, $this->alipayPublicKey, $this->signType);
                        if (!$checkResult) {
                            throw new Exception("check sign Fail! [sign=" . $signData->sign . ", signSourceData=" . $signData->signSourceData . "]");
                        }
                    } else {
                        throw new Exception("check sign Fail! [sign=" . $signData->sign . ", signSourceData=" . $signData->signSourceData . "]");
                    }

                }
            }
        }
    }

    function parserResponseSubCode($request, $responseContent, $respObject, $format)
    {
        if ("json" == $format) {
            $apiName = $request->getApiMethodName();
            $rootNodeName = str_replace(".", "_", $apiName) . $this->RESPONSE_SUFFIX;
            $errorNodeName = $this->ERROR_RESPONSE;

            $rootIndex = strpos($responseContent, $rootNodeName);
            $errorIndex = strpos($responseContent, $errorNodeName);
            if ($rootIndex > 0) {
                // 内部节点对象
                $rInnerObject = $respObject->$rootNodeName;
            } elseif ($errorIndex > 0) {

                $rInnerObject = $respObject->$errorNodeName;
            } else {
                return null;
            }
            // 存在属性则返回对应值
            if (isset($rInnerObject->sub_code)) {
                return $rInnerObject->sub_code;
            } else {
                return null;
            }
        } elseif ("xml" == $format) {
            // xml格式sub_code在同一层级
            return $respObject->sub_code;
        }
    }

    function echoDebug($content)
    {
        if ($this->debugInfo) {
            echo "<br/>" . $content;
        }
    }

    /**
     * 返回接受成功消息
     * @return Response
     */
    public function toResponse()
    {
        $res = [
            'code' => 'success',
            'msg' => '成功',
        ];
        return new Response(json_encode($res));
    }

    protected function signold($data, $signType = "RSA")
    {
        if ($this->checkEmpty($this->rsaPrivateKeyFilePath)) {
            $priKey = $this->rsaPrivateKey;
            $res = "-----BEGIN RSA PRIVATE KEY-----\n" .
                wordwrap($priKey, 64, "\n", true) .
                "\n-----END RSA PRIVATE KEY-----";
        } else {
            $priKey = file_get_contents($this->rsaPrivateKeyFilePath);
            $res = openssl_get_privatekey($priKey);
        }

        ($res) or die('您使用的私钥格式错误，请检查RSA私钥配置');

        if ("RSA2" == $signType) {
            openssl_sign($data, $sign, $res, OPENSSL_ALGO_SHA256);
        } else {
            openssl_sign($data, $sign, $res);
        }

        if (!$this->checkEmpty($this->rsaPrivateKeyFilePath)) {
            openssl_free_key($res);
        }
        $sign = base64_encode($sign);
        return $sign;
    }

    // 获取加密内容

    /**
     * 校验$value是否非空
     *  if not set ,return true;
     *    if is null , return true;
     **/
    protected function checkEmpty($value)
    {
        if (!isset($value))
            return true;
        if ($value === null)
            return true;
        if (trim($value) === "")
            return true;

        return false;
    }

    protected function getMillisecond()
    {
        list($s1, $s2) = explode(' ', microtime());
        return (float)sprintf('%.0f', (floatval($s1) + floatval($s2)) * 1000);
    }

    private function setupCharsets($request)
    {
        if ($this->checkEmpty($this->postCharset)) {
            $this->postCharset = 'UTF-8';
        }
        $str = preg_match('/[\x80-\xff]/', $this->appId) ? $this->appId : print_r($request, true);
        $this->fileCharset = mb_detect_encoding($str, "UTF-8, GBK") == 'UTF-8' ? 'UTF-8' : 'GBK';
    }

    // 获取加密内容

    private function encryptJSONSignSource($request, $responseContent)
    {

        $parsetItem = $this->parserEncryptJSONSignSource($request, $responseContent);

        $bodyIndexContent = substr($responseContent, 0, $parsetItem->startIndex);
        $bodyEndContent = substr($responseContent, $parsetItem->endIndex, strlen($responseContent) + 1 - $parsetItem->endIndex);

        $bizContent = decrypt($parsetItem->encryptContent, $this->encryptKey);
        return $bodyIndexContent . $bizContent . $bodyEndContent;

    }

    private function parserEncryptJSONSignSource($request, $responseContent)
    {

        $apiName = $request->getApiMethodName();
        $rootNodeName = str_replace(".", "_", $apiName) . $this->RESPONSE_SUFFIX;

        $rootIndex = strpos($responseContent, $rootNodeName);
        $errorIndex = strpos($responseContent, $this->ERROR_RESPONSE);


        if ($rootIndex > 0) {

            return $this->parserEncryptJSONItem($responseContent, $rootNodeName, $rootIndex);
        } else if ($errorIndex > 0) {

            return $this->parserEncryptJSONItem($responseContent, $this->ERROR_RESPONSE, $errorIndex);
        } else {

            return null;
        }


    }

    private function parserEncryptJSONItem($responseContent, $nodeName, $nodeIndex)
    {
        $signDataStartIndex = $nodeIndex + strlen($nodeName) + 2;
        $signIndex = strpos($responseContent, "\"" . $this->SIGN_NODE_NAME . "\"");
        // 签名前-逗号
        $signDataEndIndex = $signIndex - 1;

        if ($signDataEndIndex < 0) {

            $signDataEndIndex = strlen($responseContent) - 1;
        }

        $indexLen = $signDataEndIndex - $signDataStartIndex;

        $encContent = substr($responseContent, $signDataStartIndex + 1, $indexLen - 2);


        $encryptParseItem = new EncryptParseItem();

        $encryptParseItem->encryptContent = $encContent;
        $encryptParseItem->startIndex = $signDataStartIndex;
        $encryptParseItem->endIndex = $signDataEndIndex;

        return $encryptParseItem;

    }

    private function encryptXMLSignSource($request, $responseContent)
    {

        $parsetItem = $this->parserEncryptXMLSignSource($request, $responseContent);

        $bodyIndexContent = substr($responseContent, 0, $parsetItem->startIndex);
        $bodyEndContent = substr($responseContent, $parsetItem->endIndex, strlen($responseContent) + 1 - $parsetItem->endIndex);
        $bizContent = decrypt($parsetItem->encryptContent, $this->encryptKey);

        return $bodyIndexContent . $bizContent . $bodyEndContent;

    }

    private function parserEncryptXMLSignSource($request, $responseContent)
    {


        $apiName = $request->getApiMethodName();
        $rootNodeName = str_replace(".", "_", $apiName) . $this->RESPONSE_SUFFIX;


        $rootIndex = strpos($responseContent, $rootNodeName);
        $errorIndex = strpos($responseContent, $this->ERROR_RESPONSE);
        //		$this->echoDebug("<br/>rootNodeName:" . $rootNodeName);
        //		$this->echoDebug("<br/> responseContent:<xmp>" . $responseContent . "</xmp>");


        if ($rootIndex > 0) {

            return $this->parserEncryptXMLItem($responseContent, $rootNodeName, $rootIndex);
        } else if ($errorIndex > 0) {

            return $this->parserEncryptXMLItem($responseContent, $this->ERROR_RESPONSE, $errorIndex);
        } else {

            return null;
        }


    }

    private function parserEncryptXMLItem($responseContent, $nodeName, $nodeIndex)
    {

        $signDataStartIndex = $nodeIndex + strlen($nodeName) + 1;

        $xmlStartNode = "<" . $this->ENCRYPT_XML_NODE_NAME . ">";
        $xmlEndNode = "</" . $this->ENCRYPT_XML_NODE_NAME . ">";

        $indexOfXmlNode = strpos($responseContent, $xmlEndNode);
        if ($indexOfXmlNode < 0) {

            $item = new EncryptParseItem();
            $item->encryptContent = null;
            $item->startIndex = 0;
            $item->endIndex = 0;
            return $item;
        }

        $startIndex = $signDataStartIndex + strlen($xmlStartNode);
        $bizContentLen = $indexOfXmlNode - $startIndex;
        $bizContent = substr($responseContent, $startIndex, $bizContentLen);

        $encryptParseItem = new EncryptParseItem();
        $encryptParseItem->encryptContent = $bizContent;
        $encryptParseItem->startIndex = $signDataStartIndex;
        $encryptParseItem->endIndex = $indexOfXmlNode + strlen($xmlEndNode);

        return $encryptParseItem;

    }

}
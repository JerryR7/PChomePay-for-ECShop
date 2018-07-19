<?php
/**
 * Created by PhpStorm.
 * User: Jerry
 * Date: 2017/11/27
 * Time: 下午3:19
 */
if (!class_exists('ApiException', false)) {
    if (!include('ApiException.php')) {
        throw new Exception('ApiException Class not found');
    }
}
class PChomepayClient
{
    const BASE_URL = "https://api.pchomepay.com.tw/v1";
    const SB_BASE_URL = "https://sandbox-api.pchomepay.com.tw/v1";
    const TOKEN_EXPIRE_SEC = 1800;

    public function __construct($appID, $secret, $sandboxSecret, $sandBox = false, $debug = false)
    {
        $baseURL = $sandBox ? PChomePayClient::SB_BASE_URL : PChomePayClient::BASE_URL;

        $this->debug = $debug;
        $this->appID = $appID;
        $this->secret = $sandBox ? $sandboxSecret : $secret;

        $this->tokenURL = $baseURL . "/token";
        $this->postPaymentURL = $baseURL . "/payment";
        $this->getPaymentURL = $baseURL . "/payment/{order_id}";
        $this->getRefundURL = $baseURL . "/refund/{refund_id}";
        $this->postRefundURL = $baseURL . "/refund";
        $this->postPaymentAuditURL = $baseURL . "/payment/audit";

        $this->userAuth = "{$this->appID}:{$this->secret}";
    }

    // 紀錄log
    public function log($string)
    {
        $fp = fopen('/var/www/ecshop/pchomepay_error_log.txt','w+');
        fwrite($fp, $string);
        fclose($fp);
    }

    // 建立訂單
    public function postPayment($data)
    {
        return $this->postAPI($this->postPaymentURL, $data);
    }

    // 建立退款
    public function postRefund($data)
    {
        return $this->postAPI($this->postRefundURL, $data);
    }

    // 查詢訂單
    public function getPayment($orderID)
    {
        if (!is_string($orderID) || stristr($orderID, "/")) {
            throw new Exception('Order does not exist!', 20002);
        }

        return $this->getAPI(str_replace("{order_id}", $orderID, $this->getPaymentURL));
    }

    // 訂單審單
    public function postPaymentAudit($data)
    {
        return $this->postAPI($this->postPaymentAuditURL, $data);
    }

    // 取Token
    protected function getToken()
    {
        $userAuth = "{$this->appID}:{$this->secret}";

        return $this->postToken($userAuth, $this->tokenURL);

    }

    protected function postToken($userAuth, $url)
    {
        $body = $this->post($url, null, [], ["CURLOPT_USERPWD" => $userAuth]);

        return $this->handleResult($body);
    }

    protected function postAPI($url, $data)
    {
        $token = $this->getToken();

        $body = $this->post($url, null, ["pcpay-token: {$token}"], ["CURLOPT_POSTFIELDS" => $data]);

        return $this->handleResult($body);
    }

    protected function getAPI($url, $data = [])
    {
        $token = $this->getToken();

        $body = $this->get($url, $data, ["pcpay-token: $token"]);

        return $this->handleResult($body);
    }

    private function handleResult($result)
    {
        $jsonErrMap = [
            JSON_ERROR_NONE => 'No error has occurred',
            JSON_ERROR_DEPTH => 'The maximum stack depth has been exceeded',
            JSON_ERROR_STATE_MISMATCH => 'Invalid or malformed JSON',
            JSON_ERROR_CTRL_CHAR => 'Control character error, possibly incorrectly encoded',
            JSON_ERROR_SYNTAX => 'Syntax error',
            JSON_ERROR_UTF8 => 'Malformed UTF-8 characters, possibly incorrectly encoded	PHP 5.3.3',
            JSON_ERROR_RECURSION => 'One or more recursive references in the value to be encoded	PHP 5.5.0',
            JSON_ERROR_INF_OR_NAN => 'One or more NAN or INF values in the value to be encoded	PHP 5.5.0',
            JSON_ERROR_UNSUPPORTED_TYPE => 'A value of a type that cannot be encoded was given	PHP 5.5.0'
        ];

        $obj = json_decode($result);

        $err = json_last_error();

        if ($err) {
            $errStr = "($err)" . $jsonErrMap[$err];
            if (empty($errStr)) {
                $errStr = " - unknow error, error code ({$err})";
            }
            $this->log("server result error($err) {$errStr}:$result");
            throw new Exception("server result error($err) {$errStr}:$result");
        }

        if (isset($obj->error_type)) {
            $this->log("\n錯誤類型：" . $obj->error_type . "\n錯誤代碼：" . $obj->code . "\n錯誤訊息：" . ApiException::getErrMsg($obj->code));
            throw new Exception("交易失敗，請聯絡網站管理員。錯誤代碼：" . $obj->code, $obj->code);
        }

        if (empty($obj->token) && empty($obj->order_id)) {

            return false;
        }

        if (isset($obj->status_code)) {
            $this->log("訂單編號：" . $obj->order_id . " 已失敗。\n原因：" . OrderStatusCodeEnum::getErrMsg($obj->status_code));
        }

        return $obj;
    }

    /**
     * @param $url
     * @param $params
     * @param array $headers
     * @param array $settings
     * @param int $timeout
     * @return mixed
     */
    private function post($url, $params, array $headers = null, array $settings = [], $timeout = 500)
    {
        $reqData = $this->parseReqData($params);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $reqData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        //curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);

        if ($headers !== null) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        if (!empty($settings)) {
            foreach ($settings as $key => $value) {
                if (defined($key)) {
                    curl_setopt($ch, constant($key), $value);
                }
            }
        }

        $content = curl_exec($ch);

        $err = curl_errno($ch);

        if ($err) {
            $errMessage = "curl error => (" . $err . ")" . curl_error($ch);
            curl_close($ch);
            throw new RuntimeException($errMessage);
        }

        curl_close($ch);
        return $content;
    }

    private function get($url, $params, array $headers = null, array $settings = [], $timeout = 500)
    {
        $query = "?";

        if ($params !== null) {
            $query .= http_build_query($params);
        }

        $query .= "&xdebug_session_start=PHPSTORM";

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url . $query);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

        if ($headers !== null) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        if (!empty($settings)) {
            foreach ($settings as $key => $value) {
                if (defined($key)) {
                    curl_setopt($ch, constant($key), $value);
                }
            }
        }

        $content = curl_exec($ch);

        $err = curl_errno($ch);

        if ($err) {
            $errMessage = "curl error => (" . $err . ")" . curl_error($ch);
            curl_close($ch);
            throw new RuntimeException($errMessage);
        }

        curl_close($ch);
        return $content;
    }

    /**
     * @param $params
     * @return string
     */
    private function parseReqData($params)
    {
        $reqData = '';
        if (is_array($params) && !empty($params)) {
            foreach ($params as $key => $value) {
                $reqData .= "{$key}={$value}&";
            }
            $reqData = rtrim($reqData, '&');
        } else {
            $reqData = $params;
        }

        return $reqData;
    }

    public function formatOrderTotal($order_total)
    {
        return intval(round($order_total));
    }
}
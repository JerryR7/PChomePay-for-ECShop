<?php
/**
 * Created by PhpStorm.
 * User: Jerry
 * Date: 2017/11/27
 * Time: 下午3:19
 */
if (!class_exists('ApiException', false)) {
    if (!include('ApiException.php')) {
        throw new Exception('Class not found');
    }
}
class PChomepayClient
{
    const BASE_URL = "https://api.pchomepay.com.tw/v1";
    const SB_BASE_URL = "https://sandbox-api.pchomepay.com.tw/v1";

    public function __construct($order, $payment)
    {
        $this->baseURL  = $payment['PChomepay_test_mode'] === 'Yes' ? PChomepayClient::SB_BASE_URL : PChomepayClient::BASE_URL;

        $this->secret   = $payment['PChomepay_test_mode'] === 'Yes' ? $payment['PChomepay_test_secret'] : $payment['PChomepay_secret'];

        $this->appID    = $payment['PChomepay_appid'];
    }

    // 建立訂單
    public function postPayment($data)
    {
        $token = $this->getToken()->token;
        $postPaymentURL = $this->baseURL . '/payment';
        $result = $this->postAPI($token, $postPaymentURL, $data);
        $this->log($token);
        $this->log($result);
        return $this->handleResult($result);
    }

    // 建立退款
    public function postRefund($data)
    {
        $token = $this->getToken()->token;
        $postRefundURL = $this->baseURL . '/refund';

        $result = $this->postAPI($token, $postRefundURL, $data);

        return $this->handleResult($result);

    }

    // 查詢訂單
    public function getPayment($orderID)
    {
        if (!is_string($orderID) || stristr($orderID, "/")) {
            throw new Exception('Order does not exist!', 20002);
        }

        $token = $this->getToken()->token;
        $getPaymentURL = $this->baseURL . "/payment/{$orderID}";

        $result = $this->getAPI($token, $getPaymentURL);

        $this->log($result);

        return $this->handleResult($result);

    }

    // 取Token
    public function getToken()
    {
        $tokenURL = $this->baseURL . "/token";

        $userAuth = "{$this->appID}:{$this->secret}";

        $body = $this->postToken($userAuth, $tokenURL);

        return $this->handleResult($body);
    }

    /**
     * @param $url
     * @param $userAuth
     * @return string
     */
    private function postToken($userAuth, $url)
    {
        return $this->post($url, null, [], ["CURLOPT_USERPWD" => $userAuth]);
    }

    private function postAPI($token, $url, $data)
    {
        return $this->post($url, null, ["pcpay-token: {$token}"], ["CURLOPT_POSTFIELDS" => $data]);
    }

    private function getAPI($token, $url, $data = [])
    {
        return $this->get($url, $data, ["pcpay-token: $token"]);

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
        $this->log($obj);
        $err = json_last_error();

        if ($err) {
            $errStr = "($err)" . $jsonErrMap[$err];
            if (empty($errStr)) {
                $errStr = " - unknow error, error code ({$err})";
            }
            throw new Exception("server result error($err) {$errStr}:$result");
        }

        if (property_exists($obj, "error_type")) {

            $apiException = new ApiException();
            $error_message = $apiException->getErrMsg($obj->code);

            throw new Exception($error_message, $obj->code);
        }

        return $obj;
    }

    public function formatOrderTotal($order_total)
    {
        return intval(round($order_total));
    }

    public function log($string)
    {
        $fp = fopen('/var/www/ecshop/error_log2.txt','w+');
        fwrite($fp, $string);
        fclose($fp);
    }

}
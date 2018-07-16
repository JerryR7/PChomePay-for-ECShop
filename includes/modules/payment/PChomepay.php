<?php
if (!defined('IN_ECS')) {
    die('Hacking attempt');
}

//require(dirname(__FILE__) . '/includes/init.php');

$payment_lang = ROOT_PATH . 'languages/' . $GLOBALS['_CFG']['lang'] . '/payment/PChomepay.php';

if (file_exists($payment_lang)) {
    global $_LANG;

    include_once($payment_lang);
}

/* PChomePay模組 */
if (isset($set_modules) && $set_modules == TRUE) {
    $i = isset($modules) ? count($modules) : 0;

    /* 代碼 */
    $modules[$i]['code'] = basename(__FILE__, '.php');

    /* 描述檔 */
    $modules[$i]['desc'] = 'PChomepay_desc';

    /* 是否支持貨到付款 */
    $modules[$i]['is_cod'] = '0';

    /* 是否支持線上支付 */
    $modules[$i]['is_online'] = '1';

    /* 排序 */
    //$modules[$i]['pay_order']  = '1';

    /* 作者 */
    $modules[$i]['author'] = '<img src="/languages/zh_tw/payment/pchomepay_logo.png">';

    /* 網址 */
    $modules[$i]['website'] = 'https://www.pchomepay.com.tw/';

    /* 版本號 */
    $modules[$i]['version'] = 'beta';

    /* 設定資訊 */
    $modules[$i]['config'] = array(
        array('name' => 'PChomepay_appid', 'type' => 'text', 'value' => ''),
        array('name' => 'PChomepay_test_secret', 'type' => 'text', 'value' => ''),
        array('name' => 'PChomepay_secret', 'type' => 'text', 'value' => ''),
        array('name' => 'PChomepay_test_mode', 'type' => 'select', 'value' => 'Yes'),
        array('name' => 'PChomepay_card_mode', 'type' => 'select', 'value' => 'Yes'),
        array('name' => 'PChomepay_card_mode_3', 'type' => 'select', 'value' => 'Yes'),
        array('name' => 'PChomepay_card_mode_6', 'type' => 'select', 'value' => 'Yes'),
        array('name' => 'PChomepay_card_mode_12', 'type' => 'select', 'value' => 'Yes'),
        array('name' => 'PChomepay_atm_mode', 'type' => 'select', 'value' => 'Yes'),
        array('name' => 'PChomepay_acct_mode', 'type' => 'select', 'value' => 'Yes'),
        array('name' => 'PChomepay_bank_mode', 'type' => 'select', 'value' => 'Yes'),

    );
    return;
}

if (!class_exists('OrderStatusCodeEnum', false)) {
    if (!include('OrderStatusCodeEnum.php')) {
        throw new Exception('Class not found');
    }
}

if (!class_exists('PChomepayClient', false)) {
    if (!include('PChomepayClient.php')) {
        throw new Exception('Class not found');
    }
}

if (!class_exists('ApiException', false)) {
    if (!include('ApiException.php')) {
        throw new Exception('Class not found');
    }
}

/**
 * class
 */
class PChomepay
{
    function get_code($order, $payment)
    {
        $order_id = (string)$order['order_id'];

        $paytype_array = array();
        if ($payment['PChomepay_card_mode'] === 'Yes')
            $paytype_array[] = 'CARD';
        if ($payment['PChomepay_atm_mode'] === 'Yes')
            $paytype_array[] = 'ATM';
        if ($payment['PChomepay_acct_mode'] === 'Yes')
            $paytype_array[] = 'ACCT';
        if ($payment['PChomepay_bank_mode'] === 'Yes')
            $paytype_array[] = 'EACH';


        $pay_type = $paytype_array;
        $amount = (int)$order['order_amount'];
        $return_url = return_url(basename(__FILE__, '.php')) . "&order_id=" . $order['order_id'];
        $fail_return_url = null;
        $notify_url = return_url(basename(__FILE__, '.php')) . "&order_id=" . $order['order_id'] . "&notify=1";
        $items_url = $GLOBALS['ecs']->url() . '/user.php?act=order_detail&order_id=' . $order['order_id'];
        $items_name = $order['order_sn'];
        $items_array = array();
        $items_array = ['name' => $items_name, 'url' => $items_url];
        $items[] = (object)$items_array;

        $atm_info = (object)['expire_days' => 3];

        $card_info = [];
        $card_mode = array();
        if ($payment['PChomepay_card_mode_3'] === 'Yes') {
            $card_mode = ['installment' => 3];
            $card_info[] = (object)$card_mode;
        }
        if ($payment['PChomepay_card_mode_6'] === 'Yes') {
            $card_mode = ['installment' => 6];
            $card_info[] = (object)$card_mode;
        }
        if ($payment['PChomepay_card_mode_12'] === 'Yes') {
            $card_mode = ['installment' => 12];
            $card_info[] = (object)$card_mode;
        }

        $pchomepay_data = [
            'order_id' => $order_id,
            'pay_type' => $pay_type,
            'amount' => $amount,
            'return_url' => $return_url,
            'notify_url' => $notify_url,
            'items' => $items,
            'atm_info' => $atm_info,
            'card_info' => $card_info,
        ];

        $paymentData = json_encode($pchomepay_data);

        $PChomepayClient = new PChomepayClient($order, $payment);

        try {
            $result = $PChomepayClient->postPayment($paymentData);
            $button = '<div style="text-align:center"><input type="button" onclick="window.open(\'' . $result->payment_url . '\')" value="' . $GLOBALS['_LANG']['PChomepay_button'] . '"/></div>';
            return $button;

        } catch (Exception $e) {

            $this->log($e->getMessage());
            $msg = $e->getMessage();
            $error_code = $e->getCode();
            $error = '<div style="text-align:center"><span>' . $error_code . $msg . '</span></div>';
            return $error;
        }
    }

    function respond()
    {
        $payment = get_payment($_GET['code']);
        $order = order_info($_GET['order_id']);
        $notify = order_info($_GET['notify']);
        $order_id = isset($order['order_id']) ? $order['order_id'] : null;


        try {
            $sql = 'SELECT log_id FROM ' . $GLOBALS['ecs']->table('pay_log') .
                " WHERE order_id = '$order_id'";
            $log_id = $GLOBALS['db']->getOne($sql);

            $PChomepayClient = new PChomepayClient($order, $payment);
            $result = $PChomepayClient->getPayment($order_id);


            # 紀錄訂單付款方式
            switch ($result->pay_type) {
                case 'ATM':
                    $pay_type_note = 'ATM 付款';
                    break;
                case 'CARD':
                    if ($result->payment_info->installment == 1) {
                        $pay_type_note = '信用卡 付款 (一次付清)';
                    } else {
                        $pay_type_note = '信用卡 分期付款 (' . $result->payment_info->installment . '期)';
                    }
                    break;
                case 'ACCT':
                    $pay_type_note = '支付連餘額 付款';
                    break;
                case 'EACH':
                    $pay_type_note = '銀行支付 付款';
                    break;
                default:
                    $pay_type_note = $result->pay_type . '付款';
            }

            $code = ($result->status_code);             //回傳狀態碼
            $OrderStatusCodeEnum = new OrderStatusCodeEnum();
            $pay_status_note = $OrderStatusCodeEnum->getErrMsg($code);


            $pay_success = $GLOBALS['_LANG']['pay_success'] . " " . $pay_type_note . " " . $pay_status_note . " " . date("Y-m-d H:i:s");

            $pay_wait = $GLOBALS['_LANG']['pay_wait'] . " " . $pay_type_note . " " . $pay_status_note . " " . date("Y-m-d H:i:s");

            $pay_fail = $GLOBALS['_LANG']['pay_fail'] . " " . $pay_type_note . " " . $pay_status_note . " " . date("Y-m-d H:i:s");

            if ($result->status == "W") {
                order_paid($log_id, 1, $pay_wait);

                /* 修改此次支付操作的状态为已付款 */
                $sql = 'UPDATE ' . $GLOBALS['ecs']->table('pay_log') .
                    " SET is_paid = '0' WHERE log_id = '$log_id'";
                $GLOBALS['db']->query($sql);
                if ($notify) {
                    echo "success";
                    exit;
                }
                return $result;
            }
            if ($result->status == "S") {
                order_paid($log_id, 2, $pay_success);
                if ($notify) {
                    echo "success";
                    exit;
                }
                return $result;
            }
            if ($result->status == "F") {
                order_paid($log_id, 0, $pay_fail);
                if ($notify) {
                    echo "success";
                    exit;
                }
                return null;
            }

        } catch (Exception $e) {
            $this->log($e->getMessage());

            return null;
        }


    }

    public function log($string)
    {
        $fp = fopen('/var/www/ecshop/error_log.txt', "w+");
        fwrite($fp, $string);
        fclose($fp);
    }

}

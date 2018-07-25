<?php
if (!defined('IN_ECS')) {
    die('Hacking attempt');
}

//require(dirname(__FILE__) . '/includes/init.php');

$payment_lang = ROOT_PATH . 'languages/' . $GLOBALS['_CFG']['lang'] . '/payment/pchomepay.php';

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
    $modules[$i]['desc'] = 'pchomepay_desc';

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
        array('name' => 'pchomepay_appid', 'type' => 'text', 'value' => ''),
        array('name' => 'pchomepay_test_secret', 'type' => 'text', 'value' => ''),
        array('name' => 'pchomepay_secret', 'type' => 'text', 'value' => ''),
        array('name' => 'pchomepay_test_mode', 'type' => 'select', 'value' => 'Yes'),
        array('name' => 'pchomepay_card_mode', 'type' => 'select', 'value' => 'Yes'),
        array('name' => 'pchomepay_card_mode_3', 'type' => 'select', 'value' => 'Yes'),
        array('name' => 'pchomepay_card_mode_6', 'type' => 'select', 'value' => 'Yes'),
        array('name' => 'pchomepay_card_mode_12', 'type' => 'select', 'value' => 'Yes'),
        array('name' => 'pchomepay_atm_mode', 'type' => 'select', 'value' => 'Yes'),
        array('name' => 'pchomepay_acct_mode', 'type' => 'select', 'value' => 'Yes'),
        array('name' => 'pchomepay_bank_mode', 'type' => 'select', 'value' => 'Yes'),
        array('name' => 'pchomepay_atm_expiredays', 'type' => 'text', 'value' => '5'),
        array('name' => 'pchomepay_card_last_number_mode', 'type' => 'select', 'value' => 'Yes')
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
        $order_id = 'AE' . date('Ymd') . (string)$order['order_id'];

        $pay_type = array();
        if ($payment['pchomepay_card_mode'] === 'Yes')
            $pay_type[] = 'CARD';
        if ($payment['pchomepay_atm_mode'] === 'Yes')
            $pay_type[] = 'ATM';
        if ($payment['pchomepay_acct_mode'] === 'Yes')
            $pay_type[] = 'ACCT';
        if ($payment['pchomepay_bank_mode'] === 'Yes')
            $pay_type[] = 'EACH';

        $amount = (int)$order['order_amount'];

        $return_url = return_url(basename(__FILE__, '.php')) . "&order_id=" . $order['order_id'] . '&paymentresult=1';
        $fail_return_url = return_url(basename(__FILE__, '.php')) . "&order_id=" . $order['order_id'] . '&paymentresult=0';
        $notify_url = return_url(basename(__FILE__, '.php')) . '&order_id=' . $order['order_id'];

        $items_url = $GLOBALS['ecs']->url() . '/user.php?act=order_detail&order_id=' . $order['order_id'];
        $items_name = $order['order_sn'];
        $items_array = array();
        $items_array = ['name' => $items_name, 'url' => $items_url];
        $items[] = (object)$items_array;

        if ($payment['pchomepay_atm_expiredays']) {
            $atm_info = (object)['expire_days' => (int)$payment['pchomepay_atm_expiredays']];
        } else {
            $atm_info = (object)['expire_days' => 5];
        }

        $card_info = [];
        $card_mode = array();
        if ($payment['pchomepay_card_mode_3'] === 'Yes') {
            $card_mode = ['installment' => 3];
            $card_info[] = (object)$card_mode;
        }
        if ($payment['pchomepay_card_mode_6'] === 'Yes') {
            $card_mode = ['installment' => 6];
            $card_info[] = (object)$card_mode;
        }
        if ($payment['pchomepay_card_mode_12'] === 'Yes') {
            $card_mode = ['installment' => 12];
            $card_info[] = (object)$card_mode;
        }

        $pchomepay_data = [
            'order_id' => $order_id,
            'pay_type' => $pay_type,
            'amount' => $amount,
            'return_url' => $return_url,
            'fail_return_url' => $fail_return_url,
            'notify_url' => $notify_url,
            'items' => $items,
            'atm_info' => $atm_info,
            'card_info' => $card_info,
        ];

        $paymentData = json_encode($pchomepay_data);

        $appID = $payment['pchomepay_appid'];
        $secret = $payment['pchomepay_secret'];
        $sandboxSecret = $payment['pchomepay_test_secret'];
        $sandBoxMode = $payment['pchomepay_test_mode'] == 'Yes' ? true : false;

        $pchomepayClient = new PChomepayClient($appID, $secret, $sandboxSecret, $sandBoxMode);

        try {
            $result = $pchomepayClient->postPayment($paymentData);
            $button = '<div style="text-align:center"><input type="button" onclick="window.open(\'' . $result->payment_url . '\')" value="' . $GLOBALS['_LANG']['pchomepay_button'] . '"/></div>';
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
        $paymentresult = $_GET['paymentresult'];

        if ($paymentresult === '1') {
            return $paymentresult;
        } elseif ($paymentresult === '0') {
            return null;
        }

//        $order_id = isset($order['order_id']) ? $order['order_id'] : null;

        try {
//            $appID = $payment['pchomepay_appid'];
//            $secret = $payment['pchomepay_secret'];
//            $sandboxSecret = $payment['pchomepay_test_secret'];
//            $sandBoxMode = $payment['pchomepay_test_mode'];
//
//            $pchomepayClient = new PChomepayClient($appID, $secret, $sandboxSecret, $sandBoxMode);
//            $result = $pchomepayClient->getPayment($order_id);

            $notify_type = $_POST['notify_type'];
            $notify_message = $_POST['notify_message'];

            $order_data = json_decode(str_replace('\"', '"', $notify_message));

            $order_id = substr($order_data->order_id, 10);

            $sql = 'SELECT log_id FROM ' . $GLOBALS['ecs']->table('pay_log') . " WHERE order_id = '$order_id'";
            $log_id = $GLOBALS['db']->getOne($sql);

            $pay_type_note = '平台訂單編號: ' . $order_data->order_id . '<br>';

            # 紀錄訂單付款方式
            switch ($order_data->pay_type) {
                case 'ATM':
                    $pay_type_note .= 'ATM 付款';
                    $pay_type_note .= '<br>ATM虛擬帳號: ' . $order_data->payment_info->bank_code . ' - ' . $order_data->payment_info->virtual_account;
                    break;
                case 'CARD':
                    if ($order_data->payment_info->installment == 1) {
                        $pay_type_note .= '信用卡 付款 (一次付清)';
                    } else {
                        $pay_type_note .= '信用卡 分期付款 (' . $order_data->payment_info->installment . '期)';
                    }

                    if ($payment['pchomepay_card_last_number_mode'] == 'Yes') $pay_type_note .= '<br>末四碼: ' . $order_data->payment_info->card_last_number;

                    break;
                case 'ACCT':
                    $pay_type_note .= '支付連餘額 付款';
                    break;
                case 'EACH':
                    $pay_type_note .= '銀行支付 付款';
                    break;
                default:
                    $pay_type_note .= $order_data->pay_type . '付款';
            }

            if ($order_data->status == 'W') {
                $comment = sprintf('訂單交易等待中。<br>error code : %1$s<br>message : %2$s', $order_data->status_code, OrderStatusCodeEnum::getErrMsg($order_data->status_code));
                order_paid($log_id, 1, $comment);

                /* 修改此次支付操作的状态为已付款 */
                $sql = 'UPDATE ' . $GLOBALS['ecs']->table('pay_log') .
                    " SET is_paid = '0' WHERE log_id = '$log_id'";
                $GLOBALS['db']->query($sql);

            } elseif ($order_data->status == 'F') {
                if ($order_data->status_code) {
                    $comment = $pay_type_note . '<br>' . sprintf('訂單已失敗。<br>error code : %1$s<br>message : %2$s', $order_data->status_code, OrderStatusCodeEnum::getErrMsg($order_data->status_code));
                    order_paid($log_id, 0, $comment);
                } else {
                    order_paid($log_id, 0, '訂單已失敗。');
                }

            } elseif ($order_data->status == 'S') {
                order_paid($log_id, 2, $pay_type_note . '<br>訂單已成功。');
            }

            echo "success";
            exit;

        } catch (Exception $e) {
            $this->log($e->getMessage());

            return null;
        }
    }

    public function log($string)
    {
        if (!is_dir(ROOT_PATH . 'log/')) {
            mkdir(ROOT_PATH . 'log/', 0755);
        }
        $fp = fopen(ROOT_PATH . 'log/pchomepay_log.txt', "a+");
        fwrite($fp, $string . PHP_EOL);
        fclose($fp);
    }

}

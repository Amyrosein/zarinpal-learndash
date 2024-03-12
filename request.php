<?php

if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    die('you have no access');
}
//const WP_USE_THEMES = true;
require_once("../../../wp-load.php");

class ReqToZarinPal
{
    private mixed $option;

    public function __construct()
    {
        $this->option = get_option('learndash_zarinpal_settings', array());

        $data = array(
            'merchant_id'  => $this->option['MerchantID'],
            'amount'       => $_POST['amount'],
            'callback_url' => $_POST['callback_url'],
            'currency'     => $_POST['currency'],
            'description'  => $_POST['description'],
            'metadata'     => [
                'order_id' => $_POST['order_id'],
            ],
        );

        if ( ! empty($_POST['phone'])) {
            $data['metadata']['mobile'] = $_POST['phone'];
        }
        if ( ! empty($_POST['email'])) {
            $data['metadata']['email'] = $_POST['email'];
        }
//        echo "<pre>".print_r($data, true)."</pre>";
//        die;

        $result = $this->SendRequest_ToZarinPal(json_encode($data));
        if ($result === false) {
            echo '<div class="learndash_checkout_button learndash_stripe_button">
    <button class="learndash-stripe-checkout-button btn-join button"><i class="vc_btn3-icon fa fa-check-square-o"></i>
        خطا در ارتباط با زرین پال
    </button>
</div>';
            die;
        } else {
//            echo "<pre>" . print_r($result, true) . "</pre>";
//            die;
            if ( ! empty($result['errors'])) {
                echo '<div class="errors">کد خطا : ' . $result['errors']['code'] . '</div>
					<div class="errors">' . $this->getZarinPalResponseStatus($result['errors']['code']) . '</div>';
                die;
            } elseif ( ! empty($result["data"]) && $result["data"]["code"] == 100) {
                header('Location: https://www.zarinpal.com/pg/StartPay/' . $result['data']["authority"]);
                exit();
            }
        }
    }

    function SendRequest_ToZarinPal($params)
    {
        try {
            $ch = curl_init('https://api.zarinpal.com/pg/v4/payment/request.json');
            curl_setopt($ch, CURLOPT_USERAGENT, 'ZarinPal Rest Api v4');
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json',
                'Content-Length: ' . strlen($params),
            ));
            $result = curl_exec($ch);

            return json_decode($result, true);
        } catch (Exception $ex) {
            return false;
        }
    }

    public function getZarinPalResponseStatus($code)
    {
        switch ($code) {
            case -9:
                return 'اطلاعات ارسالی ناقص می باشد';
            case -10:
                return 'مرچنت یا آیپی نامعتبر';
            case -11:
                return 'مرچنت نامعتبر';
            case -12:
                return 'تلاش بیش از حد در یک بازه زمانی کوتاه';
            case -15:
                return 'ترمینال شما به حالت تعلیق در آمده با تیم پشتیبانی تماس بگیرید';
            case -16:
                return 'سطح تاييد پذيرنده پايين تر از سطح نقره اي است';
            case -34:
                return 'مبلغ از کل تراکنش بیشتر است';
            case -50:
                return 'مبلغ پرداخت شده با مقدار مبلغ در وریفای متفاوت است';
            case -51:
                return 'پرداخت ناموفق';
            case -53:
                return 'اتوریتی برای این مرچنت کد نیست';
            case -54:
                return 'اتوریتی نامعتبر است';
            case 100:
                return 'تراکنش با موفقیت انجام شد';
            case 101:
                return 'تراکنش قبلا با موفقیت انجام و تعیین وضعیت شده';
            case 'NOK':
                return 'پرداخت از سوی کاربر لغو شد';
        }

        return 'کد خطا ' . $code;
    }

}

new ReqToZarinPal();



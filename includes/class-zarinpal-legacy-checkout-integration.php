<?php

/**
 * Zarinpal legacy checkout integration class
 */


class LearnDash_Zarinpal_Legacy_Checkout_Integration
{

    /**
     * Plugin options
     *
     * @var array
     */
    private mixed $options;

    private mixed $MerchantID;

    private $message_page_id;

    private $return_url;

    private $default_button;

    /**
     * Variable to hold the Zarinpal Button HTML. This variable can be checked from other methods.
     */
    private $zarinpal_button;

    private $dropdown_button;
    /**
     * Variable to hold the Course object we are working with.
     */
    private $course;


    private $zarinpal_script_loaded_once = false;


    /**
     * Class construction function
     */
    public function __construct()
    {
        $this->options = get_option('learndash_zarinpal_settings', array());

        $this->MerchantID = @$this->options['MerchantID'];

        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_filter('learndash_payment_button', array($this, 'payment_button'), 10, 2);
        add_filter('learndash_dropdown_payment_button', array($this, 'payment_button'), 10, 2);

        add_action('init', array($this, 'process_checkout'));

        add_action('get_footer', array($this, 'get_footer'));
    }


    /**
     * Load necessary scripts and stylesheets
     */
    public function enqueue_scripts()
    {
        wp_enqueue_style(
            'ld-zarinpal-style',
            LEARNDASH_ZARINPAL_PLUGIN_URL . 'assets/css/learndash-zarinpal-style.css',
            array(),
            LEARNDASH_ZARINPAL_VERSION
        );
    }


    function get_footer()
    {
        if (is_admin()) {
            return;
        }

        if (empty($this->zarinpal_button)) {
            wp_dequeue_script('learndash_zarinpal_checkout_handler');
        }
    }

    private function is_active()
    {
        return isset($this->options['enabled']) && $this->options['enabled'] == 1;
    }

    public function payment_button($join_button, $params = null)
    {
        $join_button_parts = explode('<', $join_button);
        $post_id           = '';
        foreach ($join_button_parts as $element) {
            // Check if the element contains the input with name "item_number"
            if (str_contains($element, 'name="item_number"')) {
                // Extract the value of the input
                preg_match('/value="([^"]+)"/', $element, $matches);
                // Check if the match is found
                if (isset($matches[1])) {
                    $post_id = $matches[1];
                    // Break the loop once the value is found
                    break;
                }
            }
        }
        $this->course = get_post($post_id);
        // Also ensure the price it not zero
        if ((empty($params['price']))) {
            return $join_button;
        }
        $this->default_button = $join_button;

        return $this->zarinpal_button();
    }

    /**
     * Process zarinpal checkout
     */

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

    public function zarinpal_learndash_payment_complete($gateway, $resnum, $refnum)
    {
        $meta         = get_post_meta($_REQUEST['c'], '_sfwd-courses', true);
        $course_price = @$meta['sfwd-courses_course_price'];

        $course_price = preg_replace('/.*?(\d+(?:\.?\d+))/', '$1', $course_price);

        $user_id      = get_current_user_id();
        $course_id    = $_REQUEST['c'];
        $course       = get_post($course_id);
        $user         = get_userdata($user_id);
        $course_title = $course->post_title;
        $user_email   = $user->user_email;
        ld_update_course_access($user_id, $course_id);
        $usermeta = get_user_meta($user_id, '_sfwd-courses', true);
        if (empty($usermeta)) {
            $usermeta = $course_id;
        } else {
            $usermeta .= ",$course_id";
        }
        update_user_meta($user_id, '_sfwd-courses', $usermeta);
        $post_id = wp_insert_post(
            array(
                'post_title'  => "درس {$course_title} توسط کاربر {$user_email} خریداری شد",
                'post_type'   => 'sfwd-transactions',
                'post_status' => 'publish',
                'post_author' => $user_id,
            )
        );
        update_post_meta($post_id, 'user_id', $user_id);
        update_post_meta($post_id, 'user_name', $user->user_login);
        update_post_meta($post_id, 'user_email', $user_email);
        update_post_meta($post_id, 'course_id', $course_id);
        update_post_meta($post_id, 'course_title', $course_title);
        update_post_meta($post_id, 'res_num', $resnum);
        update_post_meta($post_id, 'ref_num', $refnum);
        update_post_meta($post_id, 'paid_price', $course_price);
        update_post_meta($post_id, 'gateway', $gateway);
        update_post_meta($post_id, 'time', time());
        update_post_meta($post_id, 'date', date('Y/m/d H:i:s'));
    }

    function SendRequest_ToZarinPal($action, $params)
    {
        try {
            $ch = curl_init('https://api.zarinpal.com/pg/v4/payment/' . $action . '.json');
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

    public function process_checkout()
    {
        if ( ! isset($_REQUEST['c'])) {
            return;
        }
        $page = get_post(MESSAGE_PAGE_ID);
        if (isset($_REQUEST['Status'], $_REQUEST['Authority'])) {
            $url               = get_permalink($_REQUEST['c']);
            $meta              = get_post_meta($_REQUEST['c'], '_sfwd-courses', true);
            $course_price      = @$meta['sfwd-courses_course_price'];
            $course_price_copy = @$meta['sfwd-courses_course_price'];

            //                echo $course_price;
            //                die;

            $course_price = str_replace("تومان", "", $course_price);
            $course_price = str_replace("ریال", "", $course_price);
            $course_price = str_replace("$", "", $course_price);
            $course_price = str_replace("هزار", "", $course_price);

            if (is_numeric(strpos($course_price_copy, 'هزار تومان'))) {
                $course_price = $course_price * 1000;
            }

            $course_price            = trim($course_price);
            $_SESSION['course_link'] = $url;
            if (strtoupper($_REQUEST['Status']) == 'OK') {
                $data   = array(
                    'merchant_id' => $this->MerchantID,
                    'authority'   => $_REQUEST['Authority'],
                    'amount'      => intval($course_price),
                );
                $result = $this->SendRequest_ToZarinPal('verify', json_encode($data));
                if ($result === false) {
                    $_SESSION['sfwd-lms-tx'] = 'پرداخت لغو شد';
                } else {
                    if ( ! empty($result['data']) && $result["data"]["code"] == 100) {
                        $this->zarinpal_learndash_payment_complete(
                            'ZarinPal',
                            ltrim($_REQUEST['Authority'], 0),
                            $result["data"]["ref_id"]
                        );

                        $_SESSION['sfwd-lms-tx'] = 'پرداخت با موفقیت تکمیل شد . شماره پیگیری  : ' . $result["data"]["ref_id"];
                    } elseif ( ! empty($result['data']) && $result["data"]["code"] == 101) {
                        $_SESSION['sfwd-lms-tx'] = 'پرداخت قبلا وریفای شده است . شماره پیگیری  : ' . $result["data"]["ref_id"];
                    } else {
                        $_SESSION['sfwd-lms-tx'] = 'خطا در تکمیل پرداخت  : ' . $this->getZarinPalResponseStatus(
                                $result["errors"]["code"]
                            );
                    }
                }
            } elseif ( ! empty($_REQUEST['Status']) && $_REQUEST['Status'] == 'NOK') {
                $_SESSION['sfwd-lms-tx'] = 'پرداخت لغو شد';
            }
        }
        $data        = '<div style="text-align: center;border: 3px solid Green;padding: 30px ;margin: 50px;border-radius: 15px"><p style="font-size: 1.5em">' . $_SESSION['sfwd-lms-tx'] . '</p></div>
			<div style="text-align: center;">
			<a style=";padding: 15px 35px; background: greenyellow; color: black; text-decoration: none; border:dashed 2px blue; border-radius: 20px; font-size: 2em" href="' . $_SESSION['course_link'] . '"> برگشت به دوره </a>
</div>';
        $post_update = array(
            'ID'           => MESSAGE_PAGE_ID,
            'post_content' => $data,
        );
        wp_update_post($post_update);
        unset($_SESSION['sfwd-lms-tx']);
        unset($_SESSION['course_link']);
    }


    /**
     * zarinpal payment button
     *
     * @return string Payment button
     */
    public function zarinpal_button(): string
    {
        if (!$this->is_active()){
            return $this->default_button;
        }
        $user      = wp_get_current_user();

        $user_id   = $user->ID;
        $post_slug = $this->course->post_name;

        $this->return_url = get_permalink(MESSAGE_PAGE_ID);
        $meta             = get_post_meta($this->course->ID, '_sfwd-courses', true);

        $course_price      = @$meta['sfwd-courses_course_price'];
        $course_price_copy = @$meta['sfwd-courses_course_price'];
        $course_price_type = @$meta['sfwd-courses_course_price_type'];
        //$course_image        = get_the_post_thumbnail_url( $this->course->ID, 'medium' );
        $custom_button_url = @$meta['sfwd-courses_custom_button_url'];

        $course_interval_count = get_post_meta($this->course->ID, 'course_price_billing_p3', true);
        $course_interval       = get_post_meta($this->course->ID, 'course_price_billing_t3', true);

        $course_name    = $this->course->post_title;
        $course_id      = $this->course->ID;
        $course_plan_id = 'learndash-course-' . $this->course->ID;

        $course_price = preg_replace('/.*?(\d+(?:\.?\d+))/', '$1', $course_price);

        $course_price = str_replace("تومان", "", $course_price);
        $course_price = str_replace("ریال", "", $course_price);
        $course_price = str_replace("$", "", $course_price);
        $course_price = str_replace("هزار", "", $course_price);

        if (is_user_logged_in()) {
            $user_phone = null;
            if (isset(get_user_meta($user->ID)['digits_phone_no'][0])){
                $user_phone = '0' . get_user_meta($user->ID)['digits_phone_no'][0];
            }
            $user_email = null;
            if (!empty($user->data->user_email)){
                $user_email = $user->data->user_email;
            }

            $ZPLUrl     = add_query_arg(array('c' => $course_id), $this->return_url);
            $ZPLUrl     = str_replace('#038;', '&', $ZPLUrl);

            if (is_numeric(strpos($course_price_copy, 'تومان'))) {
                $currency = "IRT";
            } elseif (is_numeric(strpos($course_price_copy, 'هزار تومان'))) {
                $course_price = $course_price * 1000;
                $currency     = "IRT";
            } else {
                $currency = "IRR";
            }

            $desc = sprintf("خرید دوره ی %s برای کاربر %s", $this->course->post_title, $user->display_name);

            $course_price = trim($course_price);

            $data            = array(
                'amount'       => intval($course_price),
                'callback_url' => $ZPLUrl,
                'currency'     => $currency,
                'description'  => $desc,
                'order_id'     => $course_id,
            );
            if (isset($user_phone)){
                $data['phone'] = $user_phone;
            };
            if (isset($user_email)){
                $data['email'] = $user_email;
            }
            $form_url        = LEARNDASH_ZARINPAL_PLUGIN_URL;
            $zarinpal_button = '<div class="learndash_checkout_button learndash_stripe_button">
                  <form action="' . $form_url . 'request.php" method="post" class="learndash-stripe-checkout">
                    <button type="submit" class="learndash-stripe-checkout-button btn-join button">پرداخت با زرین پال</button>
                    ';

            foreach ($data as $key => $value) {
                $zarinpal_button .= '<input type="hidden" name="' . htmlspecialchars(
                        $key
                    ) . '" value="' . htmlspecialchars($value) . '">';
            }

            $zarinpal_button .= '</form></div>';
        } else {
            $login_model = LearnDash_Settings_Section::get_section_setting(
                'LearnDash_Settings_Theme_LD30',
                'login_mode_enabled'
            );
            /** This filter is documented in themes/ld30/includes/shortcode.php */
            $login_url       = apply_filters(
                'learndash_login_url',
                ('yes' === $login_model ? '#login' : wp_login_url(get_permalink()))
            );
            $zarinpal_button = '<a class="ld-button" href="' . esc_url($login_url) . '">' . esc_html__(
                    'Login to Enroll',
                    'learndash'
                ) . '</a></span>';
        }

        return $zarinpal_button;
    }

}

new LearnDash_Zarinpal_Legacy_Checkout_Integration();

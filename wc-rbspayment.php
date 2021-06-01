<?php
/*
    Plugin Name: WooCommerce RBSPayment Checkout Gateway
    Plugin URI:
    Description: Allows to use a payment gateway with the WooCommerce.
    Version: 3.4
    Author: RBSPayment
    Author URI: http://www.rbspayment.ru/
    Text Domain: wc-rbspayment-text-domain
    Domain Path: /lang
 */

if (!defined('ABSPATH')) exit;
require_once(ABSPATH . 'wp-admin/includes/plugin.php');
require_once(__DIR__ . '/include.php');

add_filter('plugin_row_meta', 'rbs_register_plugin_links', 10, 2);
function rbs_register_plugin_links($links, $file)
{
    $base = plugin_basename(__FILE__);
    if ($file == $base) {
        $links[] = '<a href="admin.php?page=wc-settings&tab=checkout&section=rbspayment">' . __('Settings', 'woocommerce') . '</a>';
    }
    return $links;
}


add_action('plugins_loaded', 'woocommerce_rbspayment', 0);
function woocommerce_rbspayment()
{

    load_plugin_textdomain( 'wc-rbspayment-text-domain', false, dirname( plugin_basename(__FILE__) ) . '/lang' );

    if (!class_exists('WC_Payment_Gateway'))
        return;
    if (class_exists('WC_RBSPAYMENT'))
        return;

    class WC_RBSPAYMENT extends WC_Payment_Gateway
    {
        public $currency_codes = array(
            'USD' => '840',
            'UAH' => '980',
            'RUB' => '643',
            'RON' => '946',
            'KZT' => '398',
            'KGS' => '417',
            'JPY' => '392',
            'GBR' => '826',
            'EUR' => '978',
            'CNY' => '156',
            'BYR' => '974',
            'BYN' => '933'
        );

        public function __construct()
        {

            $this->id = 'rbspayment';
            $icon_path = is_file(__DIR__ . DIRECTORY_SEPARATOR . 'logo.png') ? plugin_dir_url(__FILE__) . 'logo.png' : null;

            $this->method_title = "RBSPayment";
            $this->method_description = __('Online acquiring and payment processing.', 'wc-rbspayment-text-domain');

            // Load the settings
            $this->init_form_fields();
            $this->init_settings();

            // Endpoints
            $this->prod_url = RBS_PROD_URL;
            $this->test_url = RBS_TEST_URL;
            $this->logging = ENABLE_LOGGING;
            $this->fiscale_options = ENABLE_FISCALE_OPTIONS;

            // Define user set variables
            $this->title = $this->get_option('title');
            $this->merchant = $this->get_option('merchant');
            $this->password = $this->get_option('password');
            $this->test_mode = $this->get_option('test_mode');
            $this->stage_mode = $this->get_option('stage_mode');
            $this->description = $this->get_option('description');
            $this->order_status = $this->get_option('order_status');
            $this->icon = $icon_path;

            $this->send_order = $this->get_option('send_order');
            $this->tax_system = $this->get_option('tax_system');
            $this->tax_type = $this->get_option('tax_type');

            $this->success_url = $this->get_option('success_url');
            $this->fail_url = $this->get_option('fail_url');
//            $this->ffd_version = $this->get_option('ffd_version');
            $this->ffd_paymentMethodType = $this->get_option('ffd_paymentMethodType');
            $this->ffd_paymentObjectType = $this->get_option('ffd_paymentObjectType');
            $this->ffd_paymentObjectType_delivery = $this->get_option('ffd_paymentMethodType_delivery');

            $this->pData = get_plugin_data(__FILE__);
            $this->measurement_name = 'шт.';

            // Actions
            add_action('valid-rbspayment-standard-ipn-reques', array($this, 'successful_request'));
            add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));

            // Save options
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

            if (!$this->is_valid_for_use()) {
                $this->enabled = false;
            }

            $this->callb();
        }


        public function callb()
        {

            if (isset($_GET['rbspayment']) AND $_GET['rbspayment'] == 'result') {
                if ($this->test_mode == 'yes') {
                    $action_adr = $this->test_url;
                } else {
                    $action_adr = $this->prod_url;
                }
                $action_adr .= 'getOrderStatusExtended.do';

                $args = array(
                    'userName' => $this->merchant,
                    'password' => $this->password,
                    'language' => 'en', //todo?
                    'orderId' => $_GET['orderId'],
                );

                $rbsCurl = curl_init();
                curl_setopt_array($rbsCurl, array(
                    CURLOPT_URL => $action_adr,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_POSTFIELDS => http_build_query($args, '', '&'),
                    CURLOPT_HTTPHEADER => array(
                        'CMS: Wordpress ' . get_bloginfo('version') . " + woocommerce version: " . wpbo_get_woo_version_number(),
                        'Module-Version: ' . $this->pData['Version']
                    ),
                ));

                $response = curl_exec($rbsCurl);
                curl_close($rbsCurl);

                if ($this->logging) {
                    $this->rbs_logger('Request: ' . $action_adr . ': ' . print_r($args, true) . 'Response: ' . $response);
                }
                $response = json_decode($response, true);

                $orderStatus = $response['orderStatus'];
                if ($orderStatus == '1' || $orderStatus == '2') {
                    $order_id = $_GET['order_id'];
                    $order = new WC_Order($order_id);
                    $order->update_status($this->order_status, __('Payment successful', 'wc-rbspayment-text-domain'));

                    try {
                        wc_reduce_stock_levels($order_id);

                    } catch (Exception $e) {
                        //noop
                    }

                    $order->payment_complete();

                    if (!empty($this->success_url)) {
                        wp_redirect($this->success_url . "?order_id=" . $order_id);
                        exit;
                    }

                    wp_redirect($this->get_return_url($order));

                    exit;
                } else {
                    $order_id = $_GET['order_id'];
                    $order = new WC_Order($order_id);
                    $order->update_status('failed', __('Payment failed', 'wc-rbspayment-text-domain'));
                    add_filter('woocommerce_add_to_cart_message', 'my_cart_messages', 99);
                    $order->cancel_order();

                    wc_add_notice(__('There was an error while processing payment<br/>' . $response['actionCodeDescription'], 'wc-rbspayment-text-domain'), 'error');

                    if (!empty($this->fail_url)) {
                        wp_redirect($this->fail_url . "?order_id=" . $order_id);
                        exit;
                    }
                    wp_redirect($order->get_cancel_order_url());
                    exit;
                }
            }
        }

        /**
         * Check if this gateway is enabled and available in the user's country
         */
        function is_valid_for_use()
        {
            return true;
        }

        /*
         * Admin Panel Options
         */
        public function admin_options()
        {
            ?>
            <h3>RBSPayment</h3>
            <p><?php _e("Allow customers to conveniently checkout directly with ", 'wc-rbspayment-text-domain'); ?><?echo RBS_PAYMENT_NAME;?></p>

            <?php if ($this->is_valid_for_use()) : ?>
            <table class="form-table">
                <?php
                // Generate the HTML For the settings form.
                $this->generate_settings_html();
                ?>
            </table>
        <?php else : ?>
            <div class="inline error"><p>
                    <strong><?php _e('Error: ', 'woocommerce'); ?></strong>: <?php echo $this->id;?><?php _e(' does not support your currency.', 'wc-rbspayment-text-domain'); ?>
                </p></div>
            <?php
        endif;

        }

        /*
         * Initialise Gateway Settings Form Fields
         */
        function init_form_fields()
        {

            $form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'wc-rbspayment-text-domain'),
                    'type' => 'checkbox',
                    'label' => __('Enable', 'woocommerce') . " RBSPayment",
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' => __('Title', 'wc-rbspayment-text-domain'),
                    'type' => 'text',
                    'description' => __('Title displayed to your customer when they make their order.', 'wc-rbspayment-text-domain'),
//todo                    'default' => __('Оплата картой банка', 'wc-rbspayment-text-domain'),
                    'desc_tip' => true,
                ),
                'merchant' => array(
                    'title' => __('Login-API', 'wc-rbspayment-text-domain'),
                    'type' => 'text',
//todo                    'description' => __('Пожалуйста введите Логин выданный банком.', 'wc-rbspayment-text-domain'),
                    'default' => '',
                    'desc_tip' => true,
                ),
                'password' => array(
                    'title' => __('Password', 'wc-rbspayment-text-domain'),
                    'type' => 'password',
//todo                    'description' => __('Пожалуйста введите Пароль выданный банком.', 'wc-rbspayment-text-domain'),
                    'default' => '',
                    'desc_tip' => true,
                ),
                'test_mode' => array(
                    'title' => __('Test mode', 'wc-rbspayment-text-domain'),
                    'type' => 'checkbox',
                    'label' => __('Enable', 'woocommerce'),
                    'description' => __('In this mode no actual payments are processed.', 'wc-rbspayment-text-domain'),
                    'default' => 'no'
                ),
                'stage_mode' => array(
                    'title' => __('Payments type', 'wc-rbspayment-text-domain'),
                    'type' => 'select',
                    'class' => 'wc-enhanced-select',
                    'default' => 'one-stage',
                    'options' => array(
                        'one-stage' => __('One-phase payments', 'wc-rbspayment-text-domain'),
                        'two-stage' => __('Two-phase payments', 'wc-rbspayment-text-domain'),
                    ),
                ),
                'description' => array(
                    'title' => __('Description', 'wc-rbspayment-text-domain'),
                    'type' => 'textarea',
                    'description' => __('Payment description displayed to your customer.', 'wc-rbspayment-text-domain'),

                ),
                'order_status' => array(
                    'title' => __('Payed order status', 'wc-rbspayment-text-domain'),
                    'type' => 'select',
                    'class' => 'wc-enhanced-select',
//todo                    'description' => __('Payed order status.', 'wc-rbspayment-text-domain'),
                    'default' => 'wc-completed',
                    'desc_tip' => true,
                    'options' => wc_get_order_statuses()
                ),

                'success_url' => array(
                    'title' => __('success_url', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('Page your customer will be redirected to after a <b>successful payment</b>.<br/>Leave this field blank, if you want to use default settings.', 'wc-rbspayment-text-domain'),
                ),
                'fail_url' => array(
                    'title' => __('fail_url', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('Page your customer will be redirected to after an <b>unsuccessful payment</b>.<br/>Leave this field blank, if you want to use default settings.', 'wc-rbspayment-text-domain'),
                ),

            );

            $form_fields_ext = array(
                'send_order' => array(
                    'title' => __("Send cart data<br />(including customer info)", 'wc-rbspayment-text-domain'),
                    'type' => 'checkbox',
                    'label' => __('Enable', 'woocommerce'),
                    'description' => __('If this option is enabled order receipts will be created and sent to your customer and to the revenue service.<br/>This is a paid option, contact your bank to enable it. If you use it, configure VAT settings. VAT is calculated according to the Russian legislation. VAT amounts calculated by your store may differ from the actual VAT amounts that can be applied.', 'wc-rbspayment-text-domain'),
                    'default' => 'no'
                ),
                'tax_system' => array(
                    'title' => __('Tax system', 'wc-rbspayment-text-domain'),
                    'type' => 'select',
                    'class' => 'wc-enhanced-select',
                    'default' => '0',
                    'options' => array(
                        '0' => __('General', 'wc-rbspayment-text-domain'),
                        '1' => __('Simplified, income', 'wc-rbspayment-text-domain'),
                        '2' => __('Simplified, income minus expences', 'wc-rbspayment-text-domain'),
                        '3' => __('Unified tax on imputed income', 'wc-rbspayment-text-domain'),
                        '4' => __('Unified agricultural tax', 'wc-rbspayment-text-domain'),
                        '5' => __('Patent taxation system', 'wc-rbspayment-text-domain'),
                    ),
                ),
                'tax_type' => array(
                    'title' => __('Default VAT', 'wc-rbspayment-text-domain'),
                    'type' => 'select',
                    'class' => 'wc-enhanced-select',
                    'default' => '0',
                    'options' => array(
                        '0' => __('No VAT', 'wc-rbspayment-text-domain'),
                        '1' => __('VAT 0%', 'wc-rbspayment-text-domain'),
                        '2' => __('VAT 10%', 'wc-rbspayment-text-domain'),
                        '3' => __('VAT 18%', 'wc-rbspayment-text-domain'),
                        '6' => __('VAT 20%', 'wc-rbspayment-text-domain'),
                        '4' => __('VAT applicable rate 10/110', 'wc-rbspayment-text-domain'),
                        '5' => __('VAT applicable rate 18/118', 'wc-rbspayment-text-domain'),
                        '7' => __('VAT applicable rate 20/120', 'wc-rbspayment-text-domain'),
                    ),
                ),
//                'ffd_version' => array(
//                    'title' => __('Fiscal document format', 'wc-rbspayment-text-domain'),
//                    'type' => 'select',
//                    'class' => 'wc-enhanced-select',
//                    'default' => 'v10',
//                    'options' => array(
//                        'v10' => __('FFD 1.0', 'wc-rbspayment-text-domain'),
//                        'v105' => __('FFD 1.05', 'wc-rbspayment-text-domain'),
////                        'v11' => __('FFD 1.1', 'wc-rbspayment-text-domain'),
//                    ),
//                    'description' => __('Also specify the version in your bank web account and in your fiscal service web account.', 'wc-rbspayment-text-domain'),
//                ),
                'ffd_paymentMethodType' => array(
                    'title' => __('Payment type', 'wc-rbspayment-text-domain'),
                    'type' => 'select',
                    'class' => 'wc-enhanced-select',
                    'default' => '1',
                    'options' => array(
                        '1' => __('Full prepayment', 'wc-rbspayment-text-domain'),
                        '2' => __('Partial prepayment', 'wc-rbspayment-text-domain'),
                        '3' => __('Advance payment', 'wc-rbspayment-text-domain'),
                        '4' => __('Full payment', 'wc-rbspayment-text-domain'),
                        '5' => __('Partial payment with further credit', 'wc-rbspayment-text-domain'),
                        '6' => __('No payment with further credit', 'wc-rbspayment-text-domain'),
                        '7' => __('Payment on credit', 'wc-rbspayment-text-domain'),
                    ),
//                    'description' => __('Used in FFD starting from 1.05', 'wc-rbspayment-text-domain'),
                ),
                'ffd_paymentMethodType_delivery' => array(
                    'title' => __('Payment type for delivery', 'wc-rbspayment-text-domain'),
                    'type' => 'select',
                    'class' => 'wc-enhanced-select',
                    'default' => '1',
                    'options' => array(
                        '1' => __('Full prepayment', 'wc-rbspayment-text-domain'),
                        '2' => __('Partial prepayment', 'wc-rbspayment-text-domain'),
                        '3' => __('Advance payment', 'wc-rbspayment-text-domain'),
                        '4' => __('Full payment', 'wc-rbspayment-text-domain'),
                        '5' => __('Partial payment with further credit', 'wc-rbspayment-text-domain'),
                        '6' => __('No payment with further credit', 'wc-rbspayment-text-domain'),
                        '7' => __('Payment on credit', 'wc-rbspayment-text-domain'),
                    ),
//                    'description' => __('Used in FFD starting from 1.05', 'wc-rbspayment-text-domain'),
                ),
                'ffd_paymentObjectType' => array(
                    'title' => __('Type of goods and services', 'wc-rbspayment-text-domain'),
                    'type' => 'select',
                    'class' => 'wc-enhanced-select',
                    'default' => '1',
                    'options' => array(
                        '1' => __('Goods', 'wc-rbspayment-text-domain'),
                        '2' => __('Excised goods', 'wc-rbspayment-text-domain'),
                        '3' => __('Job', 'wc-rbspayment-text-domain'),
                        '4' => __('Service', 'wc-rbspayment-text-domain'),
                        '5' => __('Stake in gambling', 'wc-rbspayment-text-domain'),
//                        '6' => __('Gambling gain', 'wc-rbspayment-text-domain'),
                        '7' => __('Lottery ticket', 'wc-rbspayment-text-domain'),
//                        '8' => __('Lottery gain', 'wc-rbspayment-text-domain'),
                        '9' => __('Intellectual property provision', 'wc-rbspayment-text-domain'),
                        '10' => __('Payment', 'wc-rbspayment-text-domain'),
                        '11' => __("Agent's commission", 'wc-rbspayment-text-domain'),
                        '12' => __('Combined', 'wc-rbspayment-text-domain'),
                        '13' => __('Other', 'wc-rbspayment-text-domain'),

                    ),
//                    'description' => __('Used in FFD starting from 1.05', 'wc-rbspayment-text-domain'),
                ),

            );

            if (ENABLE_FISCALE_OPTIONS === true) {
                $form_fields = array_merge($form_fields, $form_fields_ext);
            }
            $this->form_fields = $form_fields;
        }

        function get_product_price_with_discount($price, $type, $c_amount, &$order_data)
        {

            switch ($type) {
                case 'percent':
                    $new_price = ceil($price * (1 - $c_amount / 100));

                    // remove this discount from discount_total
                    $order_data['discount_total'] -= ($price - $new_price);
                    break;

//                case 'fixed_cart':
//                    //wrong
//                    $new_price = $price;
//                    break;

                case 'fixed_product':
                    $new_price = $price - $c_amount;

                    // remove this discount from discount_total
                    $order_data['discount_total'] -= $c_amount / 100;
                    break;

                default:
                    $new_price = $price;
            }
            return $new_price;
        }

        /*
         * Generate the dibs button link
         */
        public function generate_form($order_id)
        {
            $order = new WC_Order($order_id);

            $amount = $order->get_total() * 100;

            // COUPONS
            $coupons = array();
            global $woocommerce;
            if (!empty($woocommerce->cart->applied_coupons)) {
                foreach ($woocommerce->cart->applied_coupons as $code) {
                    $coupons[] = new WC_Coupon($code);
                }
            }


            if ($this->test_mode == 'yes') {
                $action_adr = $this->test_url;
            } else {
                $action_adr = $this->prod_url;
            }

            $extra_url_param = '';
            if ($this->stage_mode == 'two-stage') {
                $action_adr .= 'registerPreAuth.do';
            } else if ($this->stage_mode == 'one-stage') {
                $extra_url_param = '&wc-callb=callback_function';
                $action_adr .= 'register.do';
            }

            $order_data = $order->get_data();

            $language = substr(get_bloginfo("language"), 0, 2);
            //fix Gate bug locale2country
            switch ($language) {
                case  ('uk'):
                    $language = 'ua';
                    break;
                case ('be'):
                    $language = 'by';
                    break;
            }

            // prepare args array
            $args = array(
                'userName' => $this->merchant,
                'password' => $this->password,
                'amount' => $amount,
                'language' => $language,
                'returnUrl' => get_option('siteurl') . '?wc-api=WC_RBSPAYMENT&rbspayment=result&order_id=' . $order_id . $extra_url_param,
                'currency' => $this->currency_codes[get_woocommerce_currency()],
                'jsonParams' => json_encode(
                    array(
                        'CMS:' => 'Wordpress ' . get_bloginfo('version') . " + woocommerce version: " . wpbo_get_woo_version_number(),
                        'Module-Version: ' => $this->pData['Version'],
                        'email' => $order_data['billing']['email'],
                        'phone' => $order_data['billing']['phone'],
                    )
                ),

            );


            if ($this->send_order == 'yes' && $this->fiscale_options === true) {

                $args['taxSystem'] = $this->tax_system;

                $order_items = $order->get_items();

                $order_timestamp_created = $order_data['date_created']->getTimestamp();
                $order_billing_email = $order_data['billing']['email'];

                $items = array();
                $itemsCnt = 1;

                foreach ($order_items as $value) {
                    $item = array();
                    $tax = new WC_Tax();
                    $product_variation_id = $value['variation_id'];

                    if ($product_variation_id) {
                        $product = new WC_Product_Variation($value['variation_id']);
                        $item_code = $itemsCnt . "-" . $value['variation_id'];
                    } else {
                        $product = new WC_Product($value['product_id']);
                        $item_code = $itemsCnt . "-" . $value['product_id'];
                    }

                    $base_tax_rates = $tax->get_base_tax_rates($product->get_tax_class(true));
                    if (!empty($base_tax_rates)) {
                        $rates = array_shift($tax->get_rates($product->get_tax_class()));
                        $item_rate = round(array_shift($rates));
                        if ($item_rate == 20) {
                            $tax_type = 6;
                        } else if ($item_rate == 18) {
                            $tax_type = 3;
                        } else if ($item_rate == 10) {
                            $tax_type = 2;
                        } else if ($item_rate == 0) {
                            $tax_type = 1;
                        } else {
                            $tax_type = 0;
                        }
                    } else {
                        $tax_type = $this->tax_type;
                    }

                    $product_price = round(($product->get_price()) * 100);


                    if ($product->get_type() == 'variation') {
                        //TODO
                    }


                    // if discount (coupon etc)
                    // see DISCOUNT SECTION
//                    foreach ($coupons as $coupon) {
//                        $coupon_amount = $coupon->get_amount() * 100;
//                        $product_price = $this->get_product_price_with_discount($product_price, $coupon->get_discount_type(), $coupon_amount, $order_data );
//                    }

                    $item['positionId'] = $itemsCnt++;
                    $item['name'] = $value['name'];
                    $item['quantity'] = array(
                        'value' => $value['quantity'],
                        'measure' => $this->measurement_name
                    );
                    $item['itemAmount'] = $product_price * $value['quantity'];
                    $item['itemCode'] = $item_code;
                    $item['tax'] = array(
                        'taxType' => $tax_type
                    );
                    $item['itemPrice'] = $product_price;

                    // FFD 1.05 куьщмув
//                    if ($this->ffd_version == 'v105') {

                    $attributes = array();
                    $attributes[] = array(
                        "name" => "paymentMethod",
                        "value" => $this->ffd_paymentMethodType
                    );
                    $attributes[] = array(
                        "name" => "paymentObject",
                        "value" => $this->ffd_paymentObjectType
                    );

                    $item['itemAttributes']['attributes'] = $attributes;

//                    }

                    $items[] = $item;
                }


                // delivery_total
                $shipping_total = $order->get_shipping_total();

                // DISCOUNT
                if (!empty($order_data['discount_total'])) {
                    $discount = ($order_data['discount_total'] + $order_data['discount_tax']) * 100;

                    $new_order_total = 0;

                    // coze delivery will be another position
                    $delivery_sum = ($shipping_total > 0) ? $shipping_total * 100 : 0;

                    foreach ($items as &$i) {

                        $p_discount = intval(round(($i['itemAmount'] / ($amount - $delivery_sum + $discount)) * $discount, 2));

                        $this->correctBundleItem($i, $p_discount);
                        $new_order_total += $i['itemAmount'];
                    }

                    // reset order amount
                    // return delivery_sum into amount
                    $args['amount'] = $new_order_total + $delivery_sum;
                }


                // DELIVERY POSITION
                if ($shipping_total > 0) {
                    $itemShipment['positionId'] = $itemsCnt;
                    $itemShipment['name'] = 'Delivery'; //todo
                    $itemShipment['quantity'] = array(
                        'value' => 1,
                        'measure' => $this->measurement_name
                    );
                    $itemShipment['itemAmount'] = $itemShipment['itemPrice'] = $shipping_total * 100;
                    $itemShipment['itemCode'] = 'Delivery';
                    $itemShipment['tax'] = array(
                        'taxType' => $this->tax_type
                    );

                    // FFD 1.05 removed
//                    if ($this->ffd_version == 'v105') {
                        $attributes = array();
                        $attributes[] = array(
                            "name" => "paymentMethod",
                            "value" => $this->ffd_paymentObjectType_delivery
                        );
                        $attributes[] = array(
                            "name" => "paymentObject",
                            "value" => 4
                        );

                        $itemShipment['itemAttributes']['attributes'] = $attributes;

//                    }

                    $items[] = $itemShipment;
                }

                $order_bundle = array(
                    'orderCreationDate' => $order_timestamp_created,
                    'customerDetails' => array(
                        'email' => $order_billing_email
                    ),
                    'cartItems' => array('items' => $items)
                );


                $args['orderBundle'] = json_encode($order_bundle);
            }

            $args['orderNumber'] = $order_id . '_' . time();

            $rbsCurl = curl_init();
            curl_setopt_array($rbsCurl, array(
                CURLOPT_HTTPHEADER => array(
                    'CMS: Wordpress ' . get_bloginfo('version') . " + woocommerce version: " . wpbo_get_woo_version_number(),
                    'Module-Version: ' . $this->pData['Version'],
                ),
                CURLOPT_URL => $action_adr,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($args, '', '&')
            ));

            $response = curl_exec($rbsCurl);
            curl_close($rbsCurl);

            if ($this->logging) {
                $this->rbs_logger('Request: ' . $action_adr . ': ' . print_r($args, true) . 'Response: ' . $response, true);
            }

            $response = json_decode($response, true);

            if (empty($response['errorCode'])) {

                wp_redirect($response['formUrl']);

                //EI: if is error in the headers (already send)
                echo '<p><a class="button cancel" href="' . $response['formUrl'] . '">' . __('Proceed with payment', 'wc-rbspayment-text-domain') . '</a></p>';
                exit;

            } else {
                return '<p>' . __('Error code #' . $response['errorCode'] . ': ' . $response['errorMessage'], 'wc-rbspayment-text-domain') . '</p>' .
                '<a class="button cancel" href="' . $order->get_cancel_order_url() . '">' . __('Cancel payment and return to cart', 'wc-rbspayment-text-domain') . '</a>';
            }
        }


        function correctBundleItem(&$item, $discount)
        {

            $item['itemAmount'] -= $discount;
            $item['itemPrice'] = $item['itemAmount'] % $item['quantity']['value'];
            if ($item['itemPrice'] != 0) {
                $item['itemAmount'] += $item['quantity']['value'] - $item['itemPrice'];
            }

            $item['itemPrice'] = $item['itemAmount'] / $item['quantity']['value'];
        }


        /*
         * Process the payment and return the result
         */
        function process_payment($order_id)
        {
            $order = new WC_Order($order_id);

            //if PLUG-2403
            if (!empty($_GET['pay_for_order']) && $_GET['pay_for_order'] == 'true') {
                $this->generate_form($order_id);
                die;
            }

            $pay_now_url = esc_url($order->get_checkout_payment_url(true));

            return array(
                'result' => 'success',
                'redirect' => $pay_now_url
            );

        }

        /*
         * Receipt page
         */
        function receipt_page($order)
        {
            echo $this->generate_form($order);
        }


        function rbs_logger($var, $info = false)
        {
            $information = "";
            if ($var) {
                if ($info) {
                    $information = "\n\n";
                    $information .= str_repeat("-=", 64);
                    $information .= "\nDate: " . date('Y-m-d H:i:s');
                    $information .= "\nWordpress version " . get_bloginfo('version') . "; Woocommerce version: " . wpbo_get_woo_version_number() . "\n";
                }

                $result = $var;
                if (is_array($var) || is_object($var)) {
                    $result = "\n" . print_r($var, true);
                }
                $result .= "\n\n";
                $path = dirname(__FILE__) . '/wc_rbspayment.log';
                error_log($information . $result, 3, $path);
                return true;
            }
            return false;
        }

    }


    function add_rbspayment_gateway($methods)
    {
        $methods[] = 'WC_RBSPAYMENT';
        return $methods;
    }

    if (!function_exists('wpbo_get_woo_version_number')) {
        function wpbo_get_woo_version_number()
        {
            // If get_plugins() isn't available, require it
            if (!function_exists('get_plugins'))
                require_once(ABSPATH . 'wp-admin/includes/plugin.php');

            // Create the plugins folder and file variables
            $plugin_folder = get_plugins('/' . 'woocommerce');
            $plugin_file = 'woocommerce.php';

            // If the plugin version number is set, return it
            if (isset($plugin_folder[$plugin_file]['Version'])) {
                return $plugin_folder[$plugin_file]['Version'];

            } else {
                // Otherwise return null
                return NULL;
            }
        }
    }

    add_filter('woocommerce_payment_gateways', 'add_rbspayment_gateway');
}
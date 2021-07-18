<?php
/**
 * Plugin Name: usd2pay.com Pay Checkout for WooCommerce
 * Plugin URI:  https://usd2pay.com/
 * Description: Accept cryptocurrency using usd2pay.com Pay Checkout.
 * Author:      usd2pay.com
 * Author URI:  paosong91@gmail.com Pay Checkout for WooCommerce
 * Version:     1.0.0
 *
 * WC requires at least: 4.5
 * WC tested up to: 5.1
 * 
 * @package     Usd2Pay/Classes
 */

/**
 * Copyright (c) 2018 - 2021, Foris Limited ("usd2pay.com")
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

define('USD2PAY_PLUGIN_VERSION', '1.0.0');

/**
 * add or update plugin version to database
 */
function cp_usd2pay_save_plugin_version()
{
    $usd2pay_plugin_version = get_option('usd2pay_plugin_version');
    if (!$usd2pay_plugin_version) {
        add_option('usd2pay_plugin_version', USD2PAY_PLUGIN_VERSION);
    } else {
        update_option('usd2pay_plugin_version', USD2PAY_PLUGIN_VERSION);
    }
}

register_activation_hook(__FILE__, 'cp_usd2pay_save_plugin_version');

add_action('plugins_loaded', 'cp_load_usd2payment_gateway', 0);

// Register http://example.com/wp-json/usd2pay/v1/webhook
add_action('rest_api_init', function () {
    register_rest_route('usd2pay/v1', '/webhook', array(
        'methods' => 'POST',
        'callback' => 'usd2pay_process_webhook',
        'permission_callback' => 'usd2pay_process_webhook_verify_signature',
    ));
});

add_filter('http_request_args', 'my_http_request_args', 100, 1);
function my_http_request_args( $r ) {
    $r['timeout'] = 10;
    return $r;
}

add_filter( 'woocommerce_currencies', 'add_usdt_currency' );
function add_usdt_currency( $usdt_currency ) {
     $usdt_currency['USDT'] = __( 'USDT', 'woocommerce' );
     return $usdt_currency;
}
add_filter('woocommerce_currency_symbol', 'add_usdt_currency_symbol', 10, 2);
function add_usdt_currency_symbol( $custom_currency_symbol, $custom_currency ) {
     switch( $custom_currency ) {
         case 'USDT': $custom_currency_symbol = 'USDT$'; break;
     }
     return $custom_currency_symbol;
}

//Setting WP HTTP API Timeout
add_action('http_api_curl', 'my_http_api_curl', 100, 1);
function my_http_api_curl( $handle ) {
    curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt( $handle, CURLOPT_TIMEOUT, 10 );
}

// Setting custom timeout for the HTTP request
add_filter('http_request_timeout', 'my_custom_http_request_timeout', 101 );
function my_custom_http_request_timeout( $timeLimit ) {
    return 10;
}
add_filter( 'https_local_ssl_verify', '__return_false' );
add_filter( 'block_local_requests', '__return_false' );

ob_start();

/**
 * notice message when WooCommerce is not active
 */
function cp_notice_activate_woocommerce()
{

    echo '<div id="message" class="error notice is-dismissible"><p><strong>USD2Pay Checkout: </strong>' .
    esc_attr(__('WooCommerce must be active to make this plugin working properly', 'usd2pay')) .
        '</p></div>';
}

/**
 * Init payment gateway
 */
function cp_load_usd2payment_gateway()
{

    /**
     * Loads translation
     */
    load_plugin_textdomain('usd2pay', false, dirname(plugin_basename(__FILE__)) . '/languages/');

    if (!class_exists('WC_Payment_Gateway')) {
        add_action('admin_notices', 'cp_notice_activate_woocommerce');
        return;
    }

    include_once dirname(__FILE__) . '/includes/class-usd2pay-helper.php';
    include_once dirname(__FILE__) . '/includes/class-usd2pay-payment-api.php';
    include_once dirname(__FILE__) . '/includes/class-usd2pay-signature.php';

    if (!class_exists('usd2pay')) {

        /**
         * Crypto Payment Gateway
         *
         * @class usd2pay
         */
        class usd2pay extends WC_Payment_Gateway
        {

            public $id = 'usd2pay';

            /**
             * Woocommerce order
             *
             * @var object $wc_order
             */
            protected $wc_order;

            /**
             * Main function
             */
            public function __construct()
            {
                $plugin_dir = plugin_dir_url(__FILE__);
                $this->form_fields = $this->get_crypto_form_fields();
                $this->method_title = __('USD2Pay', 'usd2pay');
                $this->method_description = __('接受 USDT 和更多加密貨幣而沒有價格波動的風險', 'usd2pay');
                $this->icon = apply_filters('woocommerce_gateway_icon', '' . $plugin_dir . '/assets/icon.svg', $this->id);

                $this->supports = array('products', 'refunds');

                $this->init_settings();


                // action to save crypto pay backend configuration
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
                // action to show payment page
                add_action('woocommerce_receipt_' . $this->id, array(&$this, 'payment_state'));
                // action to show success page
                add_action('woocommerce_thankyou_' . $this->id, array(&$this, 'success_state'));

                if (isset(WC()->session->crypto_success_state)) {
                    unset(WC()->session->crypto_success_state);
                }
                if (isset(WC()->session->usd2payment_state)) {
                    unset(WC()->session->usd2payment_state);
                }
                if (isset(WC()->session->crypto_display_error)) {
                    $_POST['crypto_error'] = '1';
                    unset(WC()->session->crypto_display_error);
                }
            }

            /**
             * Get payment method title
             *
             * @return string
             */
            public function get_title()
            {
                return $this->method_title;
            }

            /**
             * set crypto backend configuration fields
             */
            public function get_crypto_form_fields()
            {

                $form_fields = array(
                    'enabled' => array(
                        'title' => __('Enabled', 'usd2pay'),
                        'type' => 'checkbox',
                        'default' => '',
                    ),
                    'test_secret_key' => array(
                        'title' => __('Test Secret Key', 'usd2pay'),
                        'type' => 'password',
                        'default' => '',
                    ),
                    'live_secret_key' => array(
                        'title' => __('Live Secret Key', 'usd2pay'),
                        'type' => 'password',
                        'default' => '',
                    ),

                    'live_merchant_id' => array(
                        'title' => __('Live Merchant id', 'usd2pay'),
                        'type' => 'password',
                        'default' => '',
                    ),
                    'environment' => array(
                        'title' => __('Environment', 'usd2pay'),
                        'type' => 'select',
                        'description' => __('Select <b>Test</b> for testing the plugin, <b>Production</b> when you are ready to go live.'),
                        'options' => array(
                            'production' => 'Production',
                            'test' => 'Test',
                        ),
                        'default' => 'test',
                    ),
                    'exchange' => array(
                        'title' => __('Exchange Rate', 'usd2pay'),
                        'type' => 'number',
                        'step' => '0.01',
                        'default' => '1',
                        'options' => array(
                            'step' => '0.01'
                        )
                    ),
                );

                return $form_fields;
            }

            public function admin_options() {
                ?>
                <h2>USD2Pay</h2>
                <p><strong>Accept USDT and more cryptocurrencies without the risk of price fluctuation.</strong></p>
                <p>Please login to <a href="https://usd2pay.com/merchant#/transactionList" target="_blank">USD2Pay Merchant Dashboard</a>
                to get your API keys to fill into the forms below. You will also need to add a webhook 
                in Merchant Dashboard so that payment refund status are synchronized back to WooCommerce.
                Please refer to <a href="http://support.usd2pay.com/" target="_blank">this FAQ page</a> for the detail setup guide.</p>
                <table class="form-table">
                <?php 
                    $this->generate_settings_html();
                ?>
                <tfoot>
                    <tr>
                    <th>Webhook URL</th>
                    <td><?= get_rest_url(null, 'usd2pay/v1/webhook'); ?>
                    <p>Copy this URL to create a new webhook in <strong>Merchant Dashboard</strong> and copy the signature secret to the above <strong>Signature Secret</strong> field.</p>
                    </td>
                    </tr>
                </tfoot>
                </table>
                <script type="text/javascript">
                	// 1.3.0 update - Add secret visibility toggles.
                    jQuery( function( $ ) {
                        $( '#woocommerce_usd2pay_live_merchant_id, #woocommerce_usd2pay_test_secret_key, #woocommerce_usd2pay_test_webhook_signature_secret, #woocommerce_usd2pay_test_merchant_id, #woocommerce_usd2pay_live_secret_key, #woocommerce_usd2pay_live_webhook_signature_secret' ).after(
                            '<button class="wc-crypto-pay-toggle-secret" style="height: 30px; margin-left: 2px; cursor: pointer"><span class="dashicons dashicons-visibility"></span></button>'
                        );
                        $( '.wc-crypto-pay-toggle-secret' ).on( 'click', function( event ) {
                            event.preventDefault();
                            var $dashicon = $( this ).closest( 'button' ).find( '.dashicons' );
                            var $input = $( this ).closest( 'tr' ).find( '.input-text' );
                            var inputType = $input.attr( 'type' );
                            if ( 'text' == inputType ) {
                                $input.attr( 'type', 'password' );
                                $dashicon.removeClass( 'dashicons-hidden' );
                                $dashicon.addClass( 'dashicons-visibility' );
                            } else {
                                $input.attr( 'type', 'text' );
                                $dashicon.removeClass( 'dashicons-visibility' );
                                $dashicon.addClass( 'dashicons-hidden' );
                            }
                        } );
                    });
                    jQuery('#woocommerce_usd2pay_exchange').attr( 'step', 0.01 );
                </script>
                <?php
            }

            /**
             * Process the payment
             *
             * @param int $order_id order id.
             * @return array
             */
            public function process_payment($order_id)
            {   
                $order = wc_get_order($order_id);
                $order_data = $order->get_data();
                $payment_url = $order->get_checkout_payment_url(true);

                // 1.0.0 redirect to payment out if redirect flow is selected (or no flow selected)
                
                    $amount = $order->get_total() * $this->settings['exchange'];
                    // $currency = $order->get_currency();
                    $currency = 'USDT';
                    $customer_name = $order->get_billing_first_name() . " " . $order->get_billing_last_name();
                    $customer_email = $order_data['billing']['email'];
                    $secret_key = ($this->settings['environment'] == 'production' ? $this->settings['live_secret_key'] : $this->settings['test_secret_key']);
                    $merchant_id = $this->settings['live_merchant_id'];
                    
                    $return_url = $order->get_checkout_order_received_url();
                    $cancel_url = $payment_url;
                    

                    $result = Usd2Pay_Payment_Api::request_payment($order_id, $currency, $amount, $customer_email, $merchant_id, $secret_key);

                    if (isset($result['error'])) {
                        wc_add_notice('usd2pay.com Pay Error: ' . ($result['error']['message'] ?? print_r($result, true)), 'error');
                        return array(
                            'result' => 'failure',
                            'messages' => 'failure'
                        );
                    }
                    
                    $redirect = 'http://localhost:8080/merchant#/pay/'.$result['success']['data']["address"].'/'.$result['success']['data']["cryptoAmount"].'/'.$result['success']['data']["amount"].'/'.$result['success']['data']["currency"].'/'.$result['success']['data']["tokenAddress"].'/'.$merchant_id.'/'.$result['success']['data']["orderId"];
                    
                    $payment_id = $result['success']['data']['orderId'];
                    $order->add_meta_data('usd2pay_paymentId', $payment_id, true);
                    $order->save_meta_data();


                return array(
                    'result' => 'success',
                    'redirect' => $redirect
                );
            }

            /**
             * Calls from hook "woocommerce_receipt_{gateway_id}"
             *
             * @param int $order_id order id.
             */
            public function payment_state($order_id)
            {
                $payment_id = Usd2Pay_Helper::get_request_value('id');
                $error_payment = Usd2Pay_Helper::get_request_value('error');

                if (!empty($payment_id)) {
                    $this->crypto_process_approved_payment($order_id, $payment_id);
                } elseif (!empty($error_payment)) {
                    $this->crypto_process_error_payment($order_id, 'wc-failed', 'payment failed');
                }

                if (!isset(WC()->session->usd2payment_state)) {
                    $this->crypto_render_payment_button($order_id);
                    WC()->session->set('usd2payment_state', true);
                }
            }

            /**
             * render crypto payment button
             *
             * @param int $order_id order id.
             */
            private function crypto_render_payment_button($order_id)
            {
                global $wp;
                // if ( 'USD' !== get_woocommerce_currency() ) {
                //     $this->crypto_process_error_payment( $order_id, 'wc-failed', 'currency not allowed' );
                // }

                $payment_parameters = $this->get_usd2payment_parameters($order_id);
                $key = Usd2Pay_Helper::get_request_value('key');
                $order_pay = Usd2Pay_Helper::get_request_value('order-pay');

                if (isset($wp->request)) {
                    $result_url = $this->crypto_get_home_url($wp->request) . 'key=' . $key;
                    $result_url = str_replace("order-pay", "order-received", $result_url);
                } else {
                    $result_url = get_page_link() . '&order-pay=' . $order_pay . '&key=' . $key;
                }

                $args = array(
                    'result_url' => $result_url,
                    'payment_parameters' => $payment_parameters,
                );

                $path = dirname(__FILE__) . '/templates/checkout/template-payment-button.php';
                Usd2Pay_Helper::set_template($path, $args);
            }

            /**
             * Get base url
             *
             * @param string $wp_request wp request
             * @return string
             */
            private function crypto_get_home_url($wp_request)
            {
                if (false !== strpos(home_url($wp_request), '/?')) {
                    $home_url = home_url($wp_request) . '&';
                } else {
                    $home_url = home_url($wp_request) . '/?';
                }
                return $home_url;
            }

            /**
             * check payment status with payment id
             *
             * @param int $order_id order id.
             * @param string $payment_id payment id.
             */
            private function crypto_process_approved_payment($order_id, $payment_id)
            {

                // check payment status with payment_id
                // TODO: Review the usage of this function [Thomas, 20201027]

                $this->crypto_show_success_page($order_id);
            }

            /**
             * cancel the order
             *
             * @param int $order_id order id.
             */
            private function crypto_cancel_order($order_id)
            {
                $this->crypto_process_error_payment($order_id, 'wc-cancelled', 'cancelled by user');
            }

            /**
             * set order status, reduce stock, empty cart and show success page.
             *
             * @param int     $order_id order id.
             */
            private function crypto_show_success_page($order_id)
            {
                $order = wc_get_order($order_id);
                wc_reduce_stock_levels($order_id);
                WC()->cart->empty_cart();
                wp_safe_redirect($this->get_return_url($order));
                exit();
            }

            /**
             * Error payment action
             *
             * @param int          $order_id order id.
             * @param string       $payment_status payment status.
             * @param string|array $error_message error identifier.
             */
            private function crypto_process_error_payment($order_id, $payment_status, $error_message = 'payment error')
            {
                global $woocommerce;

                $order = wc_get_order($order_id);

                // Cancel the order.
                $order->update_status($error_message);
                $order->update_status($payment_status, 'order_note');

                // To display failure messages from woocommerce session.
                if (isset($error_message)) {
                    $woocommerce->session->errors = $error_message;
                    wc_add_notice($error_message, 'error');
                    WC()->session->set('crypto_display_error', true);
                }

                wp_safe_redirect(wc_get_checkout_url());
                exit();
            }

            /**
             * Calls from hook "woocommerce_thankyou_{gateway_id}"
             */
            public function success_state($order_id)
            {
                // 1.1.0 update: Update metadata here so we can process refund from woocommerce
                $payment_id = Usd2Pay_Helper::get_request_value('id');
                if (!isset($payment_id)) {
                    $order = wc_get_order($order_id);
                    $order->add_meta_data('usd2pay_paymentId', $payment_id, true);
                    $order->save_meta_data();
                }

                if (!isset(WC()->session->crypto_success_state)) {
                    WC()->session->set('crypto_success_state', true);
                }
            }

            /**
             * get customer parameters by order
             *
             * @return array
             */
            private function crypto_get_customer_parameters()
            {
                $customer['first_name'] = $this->wc_order->get_billing_first_name();
                $customer['last_name'] = $this->wc_order->get_billing_last_name();
                $customer['email'] = $this->wc_order->get_billing_email();
                $customer['phone'] = $this->wc_order->get_billing_phone();

                return $customer;
            }

            /**
             * get billing parameters by order
             *
             * @return array
             */
            private function crypto_get_billing_parameters()
            {
                $billing['address'] = $this->wc_order->get_billing_address_1();
                $billing_address_2 = trim($this->wc_order->get_billing_address_2());
                if (!empty($billing_address_2)) {
                    $billing['address'] .= ', ' . $billing_address_2;
                }
                $billing['city'] = $this->wc_order->get_billing_city();
                $billing['postcode'] = $this->wc_order->get_billing_postcode();
                $billing['country'] = $this->wc_order->get_billing_country();

                return $billing;
            }

            /**
             * get payment parameters by order
             *
             * @param int $order_id order id.
             * @return array
             */
            private function get_usd2payment_parameters($order_id)
            {
                $this->wc_order = wc_get_order($order_id);

                $payment_parameters['publishable_key'] = ($this->settings['environment'] == 'production' ? $this->settings['live_publishable_key'] : $this->settings['test_publishable_key']);
                $payment_parameters['order_id'] = $order_id;
                $payment_parameters['amount'] = (float) $this->get_order_total() * 100;
                $payment_parameters['currency'] = get_woocommerce_currency();
                $payment_parameters['customer'] = $this->crypto_get_customer_parameters();
                $payment_parameters['billing'] = $this->crypto_get_billing_parameters();
                $payment_parameters['description'] = "WooCommerce order ID: $order_id";
                $payment_parameters['first_name'] = $this->wc_order->get_billing_first_name();
                $payment_parameters['last_name'] = $this->wc_order->get_billing_last_name();

                return $payment_parameters;
            }

          
            /**
             * get number of decimals from a number
             *
             * @param f number to evaluate
             * @return int number of decimals
             * @since 1.1.0
             */
            private function get_decimal_count($f)
            {
                $num = 0;
                while (true) {
                    if ((string) $f === (string) round($f)) {
                        break;
                    }
                    if (is_infinite($f)) {
                        break;
                    }

                    $f *= 10;
                    $num++;
                }
                return $num;
            }
        }
    }

    /**
     * Add Crypto Pay to WooCommerce
     *
     * @access public
     * @param array $gateways gateways.
     * @return array
     */
    function usd2pay_add_to_gateways($gateways)
    {
        $gateways[] = 'usd2pay';
        return $gateways;
    }
    add_filter('woocommerce_payment_gateways', 'usd2pay_add_to_gateways');

    /**
     * Handle a custom 'usd2pay_paymentId' query var to get orders with the 'usd2pay_paymentId' meta.
     * @param array $query - Args for WP_Query.
     * @param array $query_vars - Query vars from WC_Order_Query.
     * @return array modified $query
     */
    function handle_custom_query( $query, $query_vars ) {
        if ( ! empty( $query_vars['usd2pay_paymentId'] ) ) {
            $query['meta_query'][] = array(
                'key' => 'usd2pay_paymentId',
                'value' => esc_attr( $query_vars['usd2pay_paymentId'] ),
            );
        }

        return $query;
    }
    add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', 'handle_custom_query', 10, 2 );
}

/**
 * Process webhook
 *
 * @param array $request Options for the function.
 * @return boolean True or false based on success, or a WP_Error object.
 * @since 1.2.0
 */
function usd2pay_process_webhook(WP_REST_Request $request)
{
    
    $json = $request->get_json_params();

    $payment_gateway_id = 'usd2pay';

    // Get an instance of the WC_Payment_Gateways object
    $payment_gateways = WC_Payment_Gateways::instance();

    // Get the desired WC_Payment_Gateway object
    $payment_gateway = $payment_gateways->payment_gateways()[$payment_gateway_id];
    
    $merchant_id = $payment_gateway->settings['live_merchant_id']; 
    
    $merchant_id_replace =  str_replace("-","", $merchant_id);

    $decrypted = openssl_decrypt($json['value'], 'aes-256-cbc', $merchant_id_replace, 0, $json['iv']);
    $decrypted = json_decode($decrypted);
    
    // return $currency;
    if ($decrypted->currency == "USDT") {

        // handle payment capture event from Crypto.com Pay server webhook
        // if payment is captured (i.e. status = 'succeeded'), set woo order status to processing
        /**
         * {
        *            "orderId": "xxxxxxxx-e7ab-11eb-b602-ed9f2e20eac2",
        *            "merchantOrderId": "xx",
        *            "customerEmail": "xxx@gmail.com",
        *            "amount": 354,
        *            "currency": "USDT"
        *        }
         * 
         * */
            $order_id = $decrypted->merchantOrderId;
            $order = wc_get_order($order_id);
            
            if (!is_null($order)) {
                // update_post_meta( $post_id, '_order_currency', $_POST['_wcj_order_currency'] );
                $order->set_currency($decrypted->currency);
                $order->set_total($decrypted->amount);
                return $order->update_status('completed');
                
                
                
            }

    }

    return false;
}

function usd2pay_process_webhook_verify_signature(WP_REST_Request $request) {

    $webhook_signature  = $request->get_header('Pay-Signature');
    $body = $request->get_body();
    return true;

    if(empty($webhook_signature) || empty($body)) {
        return false;
    }

    $payment_gateway_id = 'usd2pay';

    // Get an instance of the WC_Payment_Gateways object
    $payment_gateways = WC_Payment_Gateways::instance();

    // Get the desired WC_Payment_Gateway object
    $payment_gateway = $payment_gateways->payment_gateways()[$payment_gateway_id];
    $webhook_signature_secret = ($payment_gateway->settings['environment'] == 'production' ? $payment_gateway->settings['live_webhook_signature_secret'] : $payment_gateway->settings['test_webhook_signature_secret']);

    if(empty($webhook_signature_secret)) {
        return false;
    }

    return Usd2Pay_Signature::verify_header($body, $webhook_signature, $webhook_signature_secret, null);
}
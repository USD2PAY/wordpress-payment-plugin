<?php
/**
 * Plugin Name: Crypto.com Pay Checkout for WooCommerce
 * Plugin URI:  http://www.crypto.com/
 * Description: Accept cryptocurrency using Crypto.com Pay Checkout.
 * Author:      Crypto.com
 * Author URI:  mailto:tech@crypto.com?subject=Crypto.com Pay Checkout for WooCommerce
 * Version:     1.3.0
 *
 * WC requires at least: 4.5
 * WC tested up to: 5.1
 * 
 * @package     Crypto/Classes
 */

/**
 * Copyright (c) 2018 - 2021, Foris Limited ("Crypto.com")
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

define('CRYPTO_PLUGIN_VERSION', '1.3.0');

/**
 * add or update plugin version to database
 */
function cp_crypto_save_plugin_version()
{
    $crypto_plugin_version = get_option('crypto_plugin_version');
    if (!$crypto_plugin_version) {
        add_option('crypto_plugin_version', CRYPTO_PLUGIN_VERSION);
    } else {
        update_option('crypto_plugin_version', CRYPTO_PLUGIN_VERSION);
    }
}

register_activation_hook(__FILE__, 'cp_crypto_save_plugin_version');

add_action('plugins_loaded', 'cp_load_crypto_payment_gateway', 0);

// Register http://example.com/wp-json/crypto-pay/v1/webhook
add_action('rest_api_init', function () {
    register_rest_route('crypto-pay/v1', '/webhook', array(
        'methods' => 'POST',
        'callback' => 'cp_process_webhook',
        'permission_callback' => 'cp_process_webhook_verify_signature',
    ));
});

ob_start();

/**
 * notice message when WooCommerce is not active
 */
function cp_notice_to_activate_woocommerce()
{

    echo '<div id="message" class="error notice is-dismissible"><p><strong>Crypto.com Pay Checkout: </strong>' .
    esc_attr(__('WooCommerce must be active to make this plugin working properly', 'crypto-pay')) .
        '</p></div>';
}

/**
 * Init payment gateway
 */
function cp_load_crypto_payment_gateway()
{

    /**
     * Loads translation
     */
    load_plugin_textdomain('crypto-pay', false, dirname(plugin_basename(__FILE__)) . '/languages/');

    if (!class_exists('WC_Payment_Gateway')) {
        add_action('admin_notices', 'cp_notice_to_activate_woocommerce');
        return;
    }

    include_once dirname(__FILE__) . '/includes/class-crypto-helper.php';
    include_once dirname(__FILE__) . '/includes/class-crypto-payment-api.php';
    include_once dirname(__FILE__) . '/includes/class-crypto-signature.php';

    if (!class_exists('Crypto_Pay')) {

        /**
         * Crypto Payment Gateway
         *
         * @class Crypto_Pay
         */
        class Crypto_Pay extends WC_Payment_Gateway
        {

            public $id = 'crypto_pay';

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
                $this->method_title = __('Crypto.com Pay', 'crypto-pay');
                $this->method_description = __('Accept Bitcoin and more cryptocurrencies without the risk of price fluctuation.', 'crypto-pay');
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
                if (isset(WC()->session->crypto_payment_state)) {
                    unset(WC()->session->crypto_payment_state);
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
                        'title' => __('Enabled', 'crypto-pay'),
                        'type' => 'checkbox',
                        'default' => '',
                    ),
                    'test_publishable_key' => array(
                        'title' => __('Test Publishable Key', 'crypto-pay'),
                        'type' => 'password',
                        'default' => '',
                    ),
                    'test_secret_key' => array(
                        'title' => __('Test Secret Key', 'crypto-pay'),
                        'type' => 'password',
                        'default' => '',
                    ),
                    'test_webhook_signature_secret' => array(
                        'title' => __('Test Webhook Signature Secret', 'crypto-pay'),
                        'type' => 'password',
                        'default' => '',
                    ),
                    'live_publishable_key' => array(
                        'title' => __('Live Publishable Key', 'crypto-pay'),
                        'type' => 'password',
                        'default' => '',
                    ),
                    'live_secret_key' => array(
                        'title' => __('Live Secret Key', 'crypto-pay'),
                        'type' => 'password',
                        'default' => '',
                    ),
                    'live_webhook_signature_secret' => array(
                        'title' => __('Live Webhook Signature Secret', 'crypto-pay'),
                        'type' => 'password',
                        'default' => '',
                    ),
                    'environment' => array(
                        'title' => __('Environment', 'crypto-pay'),
                        'type' => 'select',
                        'description' => __('Select <b>Test</b> for testing the plugin, <b>Production</b> when you are ready to go live.'),
                        'options' => array(
                            'production' => 'Production',
                            'test' => 'Test',
                        ),
                        'default' => 'test',
                    ),
                    'checkout_experience' => array(
                        'title' => __('Checkout Experience', 'crypto-pay'),
                        'type' => 'select',
                        'description' => __('In <strong>Redirection</strong> mode, your customers will be redirected to Crypto.com\'s payment page. After the payment is finished, they will be redirected back to your shop. In <strong>Popup</strong> mode, your customers will be redirected to a confirmation page within your store. Your customers will need to click on a Pay button to launch a payment popup and complete the payment.'),
                        'options' => array(
                            'redirect' => 'Redirection',
                            'popup' => 'Popup',
                        ),
                        'default' => 'redirect',
                    ),
                );

                return $form_fields;
            }

            public function admin_options() {
                ?>
                <h2>Crypto.com Pay</h2>
                <p><strong>Accept Bitcoin and more cryptocurrencies without the risk of price fluctuation.</strong></p>
                <p>Please login to <a href="https://merchant.crypto.com/" target="_blank">Crypto.com Pay Merchant Dashboard</a>
                to get your API keys to fill into the forms below. You will also need to add a webhook 
                in Merchant Dashboard so that payment refund status are synchronized back to WooCommerce.
                Please refer to <a href="https://help.crypto.com/en/articles/4535228-woocommerce-setup-guide" target="_blank">this FAQ page</a> for the detail setup guide.</p>
                <table class="form-table">
                <?php $this->generate_settings_html(); ?>
                <tfoot>
                    <tr>
                    <th>Webhook URL</th>
                    <td><?= get_rest_url(null, 'crypto-pay/v1/webhook'); ?>
                    <p>Copy this URL to create a new webhook in <strong>Merchant Dashboard</strong> and copy the signature secret to the above <strong>Signature Secret</strong> field.</p>
                    </td>
                    </tr>
                </tfoot>
                </table>
                <script type="text/javascript">
                	// 1.3.0 update - Add secret visibility toggles.
                    jQuery( function( $ ) {
                        $( '#woocommerce_crypto_pay_test_publishable_key, #woocommerce_crypto_pay_test_secret_key, #woocommerce_crypto_pay_test_webhook_signature_secret, #woocommerce_crypto_pay_live_publishable_key, #woocommerce_crypto_pay_live_secret_key, #woocommerce_crypto_pay_live_webhook_signature_secret' ).after(
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
                $payment_url = $order->get_checkout_payment_url(true);

                // 1.3.0 redirect to payment out if redirect flow is selected (or no flow selected)
                if ($this->settings['checkout_experience'] == 'redirect' || $this->settings['checkout_experience'] != 'popup') {
                    $amount = $order->get_total();
                    $currency = $order->get_currency();
                    $customer_name = $order->get_billing_first_name() . " " . $order->get_billing_last_name();

                    $return_url = $order->get_checkout_order_received_url();
                    $cancel_url = $payment_url;
                    $secret_key = ($this->settings['environment'] == 'production' ? $this->settings['live_secret_key'] : $this->settings['test_secret_key']);

                    $result = Crypto_Payment_Api::request_payment($order_id, $currency, $amount, $customer_name, $return_url, $cancel_url, $secret_key);

                    if (isset($result['error'])) {
                        wc_add_notice('Crypto.com Pay Error: ' . ($result['error']['message'] ?? print_r($result, true)), 'error');
                        return array(
                            'result' => 'failure',
                            'messages' => 'failure'
                        );
                    }

                    $payment_id = $result['success']['id'];
                    $order->add_meta_data('crypto_pay_paymentId', $payment_id, true);
                    $order->save_meta_data();

                    $payment_url = $payment_id = $result['success']['payment_url'];
                }

                return array(
                    'result' => 'success',
                    'redirect' => $payment_url
                );
            }

            /**
             * Calls from hook "woocommerce_receipt_{gateway_id}"
             *
             * @param int $order_id order id.
             */
            public function payment_state($order_id)
            {
                $payment_id = Crypto_Helper::get_request_value('id');
                $error_payment = Crypto_Helper::get_request_value('error');

                if (!empty($payment_id)) {
                    $this->crypto_process_approved_payment($order_id, $payment_id);
                } elseif (!empty($error_payment)) {
                    $this->crypto_process_error_payment($order_id, 'wc-failed', 'payment failed');
                }

                if (!isset(WC()->session->crypto_payment_state)) {
                    $this->crypto_render_payment_button($order_id);
                    WC()->session->set('crypto_payment_state', true);
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

                $payment_parameters = $this->get_crypto_payment_parameters($order_id);
                $key = Crypto_Helper::get_request_value('key');
                $order_pay = Crypto_Helper::get_request_value('order-pay');

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
                Crypto_Helper::set_template($path, $args);
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
                $payment_id = Crypto_Helper::get_request_value('id');
                if (!isset($payment_id)) {
                    $order = wc_get_order($order_id);
                    $order->add_meta_data('crypto_pay_paymentId', $payment_id, true);
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
            private function get_crypto_payment_parameters($order_id)
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
             * Process refund.
             *
             * @param int    $order_id Order ID
             * @param float  $amount   Order amount
             * @param string $reason   Refund reason
             *
             * @return boolean True or false based on success, or a WP_Error object.
             * @since 1.1.0
             */
            public function process_refund($order_id, $amount = null, $reason = '')
            {
                $order = wc_get_order($order_id);

                if (0 == $amount || null == $amount) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
                    return new WP_Error('crypto_pay_refund_error', __('Refund Error: You need to specify a refund amount.', 'crypto-pay'));
                }

                // actually woo converts to 2 d.p. automatically so unable to catch this, but just in case
                if ($this->get_decimal_count($amount) > 2) {
                    return new WP_Error('crypto_pay_refund_error', __('Refund Error: Refund amount cannot be larger than 2 decimal places.', 'crypto-pay'));
                }

                $secret_key = ($this->settings['environment'] == 'production' ? $this->settings['live_secret_key'] : $this->settings['test_secret_key']);
                $payment_id = $order->get_meta('crypto_pay_paymentId', true);
                $currency = $order->get_currency();

                if (!isset($payment_id)) {
                    return new WP_Error('crypto_pay_refund_error', __('Refund Error: This order cannot be refunded automatically as custom field `crypto_pay_paymentId` is not present.', 'crypto-pay'));
                }

                $result = Crypto_Payment_Api::request_refund($payment_id, $order_id, $currency, $amount, $reason, $secret_key);

                if (isset($result['error'])) {
                    return new WP_Error('crypto_pay_refund_error', __('Refund Error: ' . ($result['error']['message'] ?? print_r($result, true)), 'crypto-pay'));
                }

                $refund_id = $result['success']['id'];
                $order->add_meta_data('crypto_pay_refundId', $refund_id, false);
                $order->save_meta_data();

                return true;
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
    function crypto_add_to_gateways($gateways)
    {
        $gateways[] = 'crypto_pay';
        return $gateways;
    }
    add_filter('woocommerce_payment_gateways', 'crypto_add_to_gateways');

    /**
     * Handle a custom 'crypto_pay_paymentId' query var to get orders with the 'crypto_pay_paymentId' meta.
     * @param array $query - Args for WP_Query.
     * @param array $query_vars - Query vars from WC_Order_Query.
     * @return array modified $query
     */
    function handle_custom_query_var( $query, $query_vars ) {
        if ( ! empty( $query_vars['crypto_pay_paymentId'] ) ) {
            $query['meta_query'][] = array(
                'key' => 'crypto_pay_paymentId',
                'value' => esc_attr( $query_vars['crypto_pay_paymentId'] ),
            );
        }

        return $query;
    }
    add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', 'handle_custom_query_var', 10, 2 );
}

/**
 * Process webhook
 *
 * @param array $request Options for the function.
 * @return boolean True or false based on success, or a WP_Error object.
 * @since 1.2.0
 */
function cp_process_webhook(WP_REST_Request $request)
{

    $json = $request->get_json_params();
    $event = $json['type'];

    if ($event == 'payment.captured') {

        // handle payment capture event from Crypto.com Pay server webhook
        // if payment is captured (i.e. status = 'succeeded'), set woo order status to processing
        $payment_status = $json['data']['object']['status'];
        if ($payment_status == 'succeeded') {
            $order_id = $json['data']['object']['order_id'];
            $order = wc_get_order($order_id);
            if (!is_null($order)) {
                return $order->update_status('processing');
            }
        }

    } elseif ($event == 'payment.refund_requested') {

        // find the woo order by payment_id, then add refund entry if not exist
        $payment_id = $json['data']['object']['payment_id'];
        $refund_id = $json['data']['object']['id'];
        $refund_amount = ((float) $json['data']['object']['amount']) / 100;
        $refund_reason = $json['data']['object']['reason'] . ": " . $json['data']['object']['description'] . ", synchronized from Crypto.com Pay ({$refund_id}).";

        $orders = wc_get_orders(array('crypto_pay_paymentId' => $payment_id));

        if (count($orders) > 0) {
            $metadata_found = false;
            $order = $orders[0];
            $order_id = $order->get_order_number();

            foreach ($order->get_meta('crypto_pay_refundId', false) as $metadata) {
                foreach ($metadata->get_data() as $key => $value) {
                    if ($value == $refund_id) {
                        $metadata_found = true;
                        break;
                    }
                }
            }

            if ($metadata_found) {
                // refund entry already exist, skip
                return true;
            } else {
                $args = array(
                    'amount'         => $refund_amount,
                    'order_id'       => $order_id,
                    'reason'         => $refund_reason,
                    'refund_payment' => false
                );

                $refund = wc_create_refund($args);
                $order->add_meta_data('crypto_pay_refundId', $refund_id, false);
                $order->save_meta_data();
                return true;
            }
        }

    } elseif ($event == 'payment.created' || $event == 'payment.refund_transferred') {
        // no need to handle
    }

    return false;
}

function cp_process_webhook_verify_signature(WP_REST_Request $request) {

    $webhook_signature  = $request->get_header('Pay-Signature');
    $body = $request->get_body();

    if(empty($webhook_signature) || empty($body)) {
        return false;
    }

    $payment_gateway_id = 'crypto_pay';

    // Get an instance of the WC_Payment_Gateways object
    $payment_gateways = WC_Payment_Gateways::instance();

    // Get the desired WC_Payment_Gateway object
    $payment_gateway = $payment_gateways->payment_gateways()[$payment_gateway_id];
    $webhook_signature_secret = ($payment_gateway->settings['environment'] == 'production' ? $payment_gateway->settings['live_webhook_signature_secret'] : $payment_gateway->settings['test_webhook_signature_secret']);

    if(empty($webhook_signature_secret)) {
        return false;
    }

    return Crypto_Signature::verify_header($body, $webhook_signature, $webhook_signature_secret, null);
}
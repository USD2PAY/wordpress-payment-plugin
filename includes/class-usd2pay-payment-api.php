<?php
/**
 * Usd2Pay Payment API
 *
 * The Class for Process Crypto Payment Gateways
 * Copyright (c) 2020 - 2021, Foris Limited ("usd2pay.com")
 *
 * @class      Usd2Pay_Payment_Api
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * The Class for Processing Usd2Pay Payment API
 */
class Usd2Pay_Payment_Api
{
    /**
     * payment api url
     *
     * @var string $usd2pay_payment_url
     */
    protected static $crypto_api_payment_url = 'https://api.usd2pay.com/customer_api/v2/merchant/'; // + :merchantId/order/

    /**
     * Get http response
     *
     * @param string $url url.
     * @param string $secret_key secret key.
     * @param string $method method.
     * @param string $data data.
     * @return array
     */
    private static function get_http_response($url, $secret_key, $method = 'get', $data = '')
    {

        if ('get' === $method) {
            $response = wp_remote_get($url,
                array(
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $secret_key,
                    ),
                )
            );
        } else {
            $response = wp_remote_post($url,
                array(
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $secret_key,
                    ),
                    'body' => $data,
                )
            );
        }

        $result = array();

        // if wordpress error
        if (is_wp_error($response)) {
            $result['error'] = $response->get_error_message();
            $result['request'] = $data;
            return $result;
        }

        $response = wp_remote_retrieve_body($response);
        $response_json = json_decode($response, true);

        // if outgoing request get back a normal response, but containing an error field in JSON body
        if ($response_json['error']) {
            $result['error'] = $response_json['error'];
            $result['error']['message'] = $result['error']['param'] . ' ' . $result['error']['code'];
            $result['request'] = $data;
            return $result;
        }

        // if everything normal
        $result['success'] = $response_json;
        return $result;
    }

    /**
     * create a payment
     * 
     * @param string $order_id
     * @param string $currency currency
     * @param string $amount amount
     * @param string $customer_name customer name
     * @param string $secret_key secret key
     * @since 1.3.0
     */
    public static function request_payment($order_id, $currency, $amount, $customer_name, $return_url, $cancel_url, $merchantId, $secret_key) 
    {
        $data = array(
            'order_id' => $order_id,
            'currency' => $currency,
            'amount' => (float) $amount * 100,
            'reason' => $reason,
            'description' => 'WooCommerce order ID: ' . $order_id,
            'metadata' => array (
                'customer_name' => $customer_name,
				'plugin_name' => 'woocommerce',
                'plugin_flow' => 'redirect'
            ),
            'return_url' => $return_url,
            'cancel_url' => $cancel_url
        );
        $apiEndPoint = self::$crypto_api_payment_url . $merchantId . '/order';
        return self::get_http_response($apiEndPoint, $secret_key, 'post', $data);
    }



    /**
     * retrieve a payment by payment unique id
     *
     * @param string $payment_id payment id.
     * @param string $secret_key secret key.
     * @return array
     */
    public static function retrieve_payment($payment_id, $secret_key)
    {
        $crypto_api_payment_url = self::$crypto_api_payment_url . $payment_id;
        return self::get_http_response($crypto_api_payment_url, $secret_key);
    }

 

}

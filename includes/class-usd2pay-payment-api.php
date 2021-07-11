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
    protected static $usd2pay_api_payment_url = 'http://355fe88fb759.ngrok.io/customer_api/v2/merchant/'; // + :merchantId/order/

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
                        'Content-Type' => 'application/json; charset=utf-8',
                        'Content-Length: ' . strlen($data),
                    ),
                    'timeout'     => 30,
                    'sslverify'   => false,
                    'method' => 'GET',
                    
                )
            );
        } else {
            
            $response = wp_remote_post($url,
                array(
                    'headers' => array(
                        'Content-Type' => 'application/json; charset=utf-8',
                        'Content-Length: ' . strlen($data),
                    ),
                    'timeout'     => 30,
                    'sslverify'   => false,
                    'method' => 'POST',
                    'body' => json_encode($data),
                )
            );
        }

        $result = array();

        // // if wordpress error
        // echo "<script> console.log('" . is_wp_error($response) .  "')</script>";
        // echo "<script> console.log('" . wp_remote_retrieve_response_code( $response )  .  "')</script>";
        
        if (is_wp_error($response)) {
            $result['error'] = $response->get_error_message();
            $result['request'] = $data;
            return $result;
        }

        if($response['response']["code"] != 200) {
            $result['error'] = $response['response']["message"];
            $result['request'] = $data;
            $result['endpoint'] = $url;
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
    public static function request_payment($order_id, $currency, $amount, $customer_email, $merchant_id, $secret_key) 
    {
        $data = array(
            "customerEmail" => $customer_email,
            'merchantCompareOrderId' => (string) $order_id,
            'amount' => (float) $amount,
            'currency' => $currency,
        );
        $data['hash'] = hash('sha256', $merchant_id.$data['customerEmail'].$data['merchantCompareOrderId'].$data['amount'].$data['currency'].$secret_key);
        
        $apiEndPoint = self::$usd2pay_api_payment_url . $merchant_id . '/order';
    
        return self::get_http_response($apiEndPoint, $secret_key, 'post', $data);
    }

}

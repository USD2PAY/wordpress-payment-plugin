<?php
/**
 * Usd2Pay Helper
 *
 * Helper for reuseable function
 * Copyright (c) 2020 - 2021, Foris Limited ("usd2pay.com")
 *
 * @class       Usd2Pay_Payment_Helper
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

/**
 * helper for reuseable function
 */
class Usd2Pay_Helper
{
    /**
     * get request value
     *
     * @param string $key key.
     * @return string
     */
    public static function get_request_value($key)
    {
        if (isset($_REQUEST[$key])) { // input var okay.
            return sanitize_text_field(wp_unslash($_REQUEST[$key])); // input var okay.
        }
        return '';
    }

    /**
     * set template
     *
     * @param string $path template path.
     * @param array  $args template arguments.
     * @return void
     */
    public static function set_template($path, $args = array())
    {
        if (function_exists('wc_get_template')) {
            $path_info = pathinfo($path);
            $path_dirname = $path_info['dirname'] . '/';
            $path_basename = $path_info['basename'];
            wc_get_template($path_basename,
                $args,
                $path_dirname,
                $path_dirname
            );
        } else {
            foreach ($args as $key => $value) {
                $$key = $value;
            }
            include $path;
        }
    }
}

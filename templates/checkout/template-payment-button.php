<?php
/**
 * Crypto Payment Button
 *
 * The file is for displaying the Crypto payment button
 * Copyright (c) 2018 - 2021, Foris Limited ("Crypto.com")
 *
 * @package     Crypto/Templates
 * @located at  /template/ckeckout/
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}
?>

<script
    src="https://js.crypto.com/sdk?publishable-key=<?php echo esc_attr($payment_parameters['publishable_key']) ?>">
</script>

<script>
    cryptopay.Button({
		createPayment: function(actions) {
		    return actions.payment.create({
		      currency: '<?php echo esc_attr($payment_parameters['currency']) ?>',
		      amount: '<?php echo esc_attr($payment_parameters['amount']) ?>',
		      description : '<?php echo esc_attr($payment_parameters['description']) ?>',
		      order_id: '<?php echo esc_attr($payment_parameters['order_id']) ?>',
			  metadata: {
				  customer_name: '<?php echo esc_attr($payment_parameters['first_name']) ?> <?php echo esc_attr($payment_parameters['last_name']) ?> ',
				  plugin_name: 'woocommerce',
				  plugin_flow: 'popup'
			  }
		    });
		},
		onApprove: function (d, actions) {
			actions.payment.fetch().then(function (data) {
				window.open('<?php echo esc_attr($result_url) ?>'+'&id='+data.id, '_self');
		 	})
		 	.catch(function (err) {
		 		window.open('<?php echo esc_attr($result_url) ?>'+'&error=1', '_self');
		 	});
		}
    }).render("#pay-button")
</script>

<div id="pay-button"></div>

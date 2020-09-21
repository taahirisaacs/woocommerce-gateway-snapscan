<?php

/**
 * SnapScan Payment Gateway
 *
 * Provides a SnapScan Payment Gateway.
 *
 * @author        SnapScan
 */
class WC_Gateway_SnapScan extends WC_Payment_Gateway {

	public $version = '1.0.1';

	public function __construct() {
		$this->id                 = 'snapscan';  // Unique ID for your gateway
		$this->logger_id 		  = 'woocommerce-gateway-snapscan';
		$this->method_title       = __( 'SnapScan', 'woocommerce-gateway-snapscan' ); // Title of the payment method shown on the admin page.
		$this->method_description = __( 'SnapScan Payments', 'woocommerce-gateway-snapscan' ); // Description for the payment method shown on the admin page.
		$this->icon               = $this->plugin_url() . '/assets/images/snapscan_logo.png'; // Show an image next to the gateway’s name on the frontend
		$this->has_fields         = true;  // true if you want payment fields to show on the checkout (if doing a direct integration).

		// Setup available countries.
		$this->available_countries = array( 'ZA' );

		// Setup available currency codes.
		$this->available_currencies = array( 'ZAR' );

		// Load the form fields.
		$this->init_form_fields();

		// Load the settings.
		$this->init_settings();

		$this->title = $this->settings['title'];  // Displayed on the 'choose payment method' screen

		// Setup default merchant data.
		$this->url          = 'https://www.snapscan.co.za';
		$this->api_endpoint = 'https://pos.snapscan.io/merchant/api/v1/payments';
		$this->response_url = add_query_arg( 'wc-api', 'WC_Gateway_SnapScan', home_url( '/' ) );

		// Debug mode.
		$this->debug = false;
		if ( isset( $this->settings['debug'] ) && 'yes' == $this->settings['debug'] ) {
			$this->debug = true;
		}

		if ( class_exists( 'WC_Logger' ) ) {
			$this->logger = new WC_Logger();
		} else {
			$this->logger = WC()->logger();
		}

		add_action( 'woocommerce_api_wc_gateway_snapscan', array( $this, 'api_callback' ) );
		add_action( 'valid-snapscan-itn-request', array( $this, 'successful_request' ) );

		/* 1.6.6 */
		add_action( 'woocommerce_update_options_payment_gateways', array( $this, 'process_admin_options' ) );

		/* 2.0.0 */
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array(
			$this,
			'process_admin_options'
		) );

		add_action( 'woocommerce_receipt_snapscan', array( $this, 'receipt_page' ) );

		if ( ! $this->gateway_available_and_enabled() ) {
			$this->enabled = false; // 'yes' if enabled
		}

		add_filter( 'jetpack_photon_skip_for_url', array( $this, 'skip_photon' ), 10, 4 );
	}

	/**
	 * Log a message to the WC_Logger, if debug mode is on.
	 *
	 * @param string $message
	 * @return void
	 */
	private function log( $message ) {
		if ( $this->debug ) {
			$this->logger->add( $this->logger_id, $message );
		}
	}

	/**
	 * Initialise Gateway Settings Form Fields
	 *
	 */
	function init_form_fields() {
		$label = __( 'Enable Logging', 'woocommerce-gateway-snapscan' );
		$description = __( 'Enable the logging of errors.', 'woocommerce-gateway-snapscan' );

		if ( defined( 'WC_LOG_DIR' ) ) {
			$log_url = add_query_arg( 'tab', 'logs', add_query_arg( 'page', 'wc-status', admin_url( 'admin.php' ) ) );
			$log_key = 'woocommerce-gateway-snapscan-' . sanitize_file_name( wp_hash( 'woocommerce-gateway-snapscan' ) );
			$log_url = add_query_arg( 'log_file', $log_key, $log_url );

			$label .= ' | ' . sprintf( __( '%1$sView Log%2$s', 'woocommerce-gateway-snapscan' ), '<a href="' . esc_url( $log_url ) . '">', '</a>' );
		}

		$this->form_fields = array(
			'enabled'                 => array(
				'title'   => __( 'Enable/Disable', 'woocommerce-gateway-snapscan' ),
				'label'   => __( 'Enable SnapScan', 'woocommerce-gateway-snapscan' ),
				'type'    => 'checkbox',
				'default' => 'yes'
			),
			'title'                   => array(
				'title'       => __( 'Title', 'woocommerce-gateway-snapscan' ),
				'type'        => 'text',
				'description' => __( 'This is the title which the user sees during checkout.', 'woocommerce-gateway-snapscan' ),
				'default'     => __( 'SnapScan', 'woocommerce-gateway-snapscan' )
			),
			'description'             => array(
				'title'       => __( 'Description', 'woocommerce-gateway-snapscan' ),
				'type'        => 'text',
				'description' => __( 'Optional: This is the description which the user sees during checkout.', 'woocommerce-gateway-snapscan' ),
				'default'     => __( 'Pay using your mobile phone.', 'woocommerce-gateway-snapscan' )
			),
			'snapcode'                => array(
				'title'       => __( 'SnapCode', 'woocommerce-gateway-snapscan' ),
				'type'        => 'text',
				'description' => __( 'This is the merchant SnapCode, received from SnapScan.', 'woocommerce-gateway-snapscan' ),
				'default'     => ''
			),
			'merchant_api_key'        => array(
				'title'       => __( 'Merchant API Key', 'woocommerce-gateway-snapscan' ),
				'type'        => 'text',
				'description' => __( 'This is the Merchant API Key, received from SnapScan.', 'woocommerce-gateway-snapscan' ),
				'default'     => ''
			),
			'merchant_callback_token' => array(
				'title'       => __( 'Merchant Callback Token', 'woocommerce-gateway-snapscan' ),
				'type'        => 'text',
				'description' => __( 'This is the Merchant Callback Token, received from SnapScan.', 'woocommerce-gateway-snapscan' ),
				'default'     => ''
			),
			'debug' => array(
				'title'       => __( 'Debug Log', 'woocommerce-gateway-snapscan' ),
				'label'       => $label,
				'description' => $description,
				'type'        => 'checkbox',
				'default'     => 'no'
			)
		);

	}

	/**
	 * Get the plugin URL
	 */
	function plugin_url() {
		if ( isset( $this->plugin_url ) ) {
			return $this->plugin_url;
		}

		if ( is_ssl() ) {
			return $this->plugin_url = str_replace( 'http://', 'https://', WP_PLUGIN_URL ) . "/" . plugin_basename( dirname( dirname( __FILE__ ) ) );
		} else {
			return $this->plugin_url = WP_PLUGIN_URL . "/" . plugin_basename( dirname( dirname( __FILE__ ) ) );
		}
	}

	/**
	 * gateway_available_and_enabled()
	 *
	 * Check if this gateway is enabled and available in the base currency being traded with.
	 */
	function gateway_available_and_enabled() {
		$is_available  = false;
		$user_currency = get_option( 'woocommerce_currency' );

		$is_available_currency = in_array( $user_currency, $this->available_currencies );

		if ( $is_available_currency && $this->enabled == 'yes' && $this->settings['merchant_api_key'] != '' &&
		     $this->settings['merchant_callback_token'] != '' && $this->settings['snapcode'] != ''
		) {
			$is_available = true;
		}

		return $is_available;
	}

	/**
	 * Admin Panel Options
	 */
	public function admin_options() {
		print '<h3>' . __( 'SnapScan', 'woocommerce-gateway-snapscan' ) . '</h3>';
		print "<p>";
		printf( __( 'SnapScan works by allowing the user to scan a QR code to complete payment.', 'woocommerce-gateway-snapscan' ), '<a href="http://snapscan.co.za/">', '</a>' );
		print '<br>';
		print __( 'To get started you will need an API Key, Token and a SnapCode. Email <a href="mailto:help@snapscan.co.za">help@snapscan.co.za</a> for your free key.', 'woocommerce-gateway-snapscan' );
		print '<br>';
		print  __( 'Be sure to mention you use the <b>SnapScan WooCommerce plugin</b> and that your url is <b>' . home_url( '/' ) . '</b>.', 'woocommerce-gateway-snapscan' );
		print "</p>";

		if ( 'ZAR' == get_option( 'woocommerce_currency' ) ) {
			print '<table class="form-table">';
			print $this->generate_settings_html();
			print '</table>';
		} else {
			// Determine the settings URL where currency is adjusted.
			$url = admin_url( 'admin.php?page=wc-settings&tab=general' );
			// Older settings screen.s
			if ( isset( $_GET['page'] ) && 'woocommerce' == $_GET['page'] ) {
				$url = admin_url( 'admin.php?page=woocommerce&tab=catalog' );
			}
			print '<div class="inline error"><p><strong>' . _e( 'Gateway Disabled', 'woocommerce-gateway-snapscan' ) . '</strong>';
			print sprintf( __( 'Choose South African Rands as your store currency in %1$sGeneral Settings%2$s to enable the SnapScan Gateway.', 'woocommerce-gateway-snapscan' ), '<a href="' . esc_url( $url ) . '">', '</a>' );
			print '</p></div>';
		}
	}

	/**
	 * Show the description if set.
	 */
	function payment_fields() {
		if ( isset( $this->settings['description'] ) && ( '' != $this->settings['description'] ) ) {
			print wpautop( wptexturize( $this->settings['description'] ) );
		}
	}

	/**
	 * Process the payment and return the result.
	 *
	 * @param int $order_id
	 *
	 * @return array
	 */
	function process_payment( $order_id ) {

		$order = new WC_Order( $order_id );

		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true )
		);
	}

	/**
	 * Receipt page.
	 *
	 * Display text and a button to direct the user to SnapScan.
	 *
	 * @param $order_id
	 */
	function receipt_page( $order_id ) {
		$order          = new WC_Order( $order_id );
		$total_in_cents = round( $order->get_total() * 100 );
		$snapcode       = $this->settings['snapcode'];
		$qr_url         = 'https://pos.snapscan.io/qr/' . $snapcode . '?id=' . $order_id . '&strict=true&amount=' . $total_in_cents;
		$qr_image_url   = 'https://pos.snapscan.io/qr/' . $snapcode . '.png?id=' . $order_id . '&strict=true&amount=' . $total_in_cents;
		$qr_image_url .= '&snap_code_size=160';

		print '
		<div class="snapscan-wrapper">
			<style type="text/css">
				#snapscan-widget {
				  width: 160px;
				  padding: 30px 20px;
				  background-color: #F2F2F2;
				  box-sizing: content-box;
				}
				#snapscan-widget div, #snapscan-widget img, #snapscan-widget a {
				  line-height: 1em;
				}
				#snapscan-widget .snapscan-logo {
				  background: #F2F2F2 url("' . $this->plugin_url() . "/assets/images/logo_320.png" . '") no-repeat center center;
				  background-size: 160px auto;
				  width: 160px;
				  height: 33px;
				  margin: 0;
				  padding: 10px 0;
				  box-sizing: content-box;
				}
				#snapscan-widget .snap-code {
				  background-color: #ffffff;
				  padding: 0;
				  box-sizing: content-box;
				}
				#snapscan-widget .scan-text {
				  background: #F2F2F2 url("' . $this->plugin_url() . "/assets/images/scan_text_320.png" . '") no-repeat center center;
				  background-size: 160px auto;
				  width: 160px;
				  height: 35px;
				  box-sizing: content-box;
				}

				#snapscan-widget a {
				  text-decoration:none;
				  border:none;
				  display: block;
				  box-sizing: content-box;
				  margin: 0;
				  padding: 0;
				}

				#snapscan-widget a.faq-link {
				  margin-top: 10px;
				  box-sizing: content-box;
				}

				#snapscan-widget img {
				  border:none;
				  margin: 0;
				  padding: 0;
				  background:transparent;
				  box-sizing: content-box;
				  box-shadow: none;
				  display:block;
				}
				@media screen and (max-device-width: 667px){
				  #snapscan-widget .snap-code  {
					display: none;
				  }
				  #snapscan-widget .scan-text {
					display: none;
				  }
				}
			</style>
			<div id="snapscan-widget" style="margin:0 auto;text-align: center; border:none">
				<div class="snapscan-logo"></div>
				<div class="snap-code">
				  <img class="snapcode" src="' . $qr_image_url . '" width="160" height="160" style="padding:0px; background-color:white; border:none; background:transparent">
				</div>
				<div class="scan-text"></div>
				<a class="pay-link" href="' . $qr_url . '" target="_blank"><img src="' . $this->plugin_url() . "/assets/images/pay_link_320.png" . '" width="160"></a>
				<a class="faq-link" href="http://www.snapscan.co.za/faq" target="_blank"><img src="' . $this->plugin_url() . "/assets/images/faq_link_320.png" . '" width="160"></a>
			</div>
		</div>';

		$check_order_url = $this->response_url; // http://yoursite.com/?wc-api=CALLBACK

		// both http://yoursite.com/?wc-api=CALLBACK or http://yoursite.com/wc-api/CALLBACK/ is valid
		if ( strrpos( $check_order_url, '?' ) === false ) {
			$check_order_url .= '?check_status=1&oid=' . $order_id;
		} else {
			$check_order_url .= '&check_status=1&oid=' . $order_id;
		}

		$order_received_url         = $order->get_checkout_order_received_url();
		$order_checkout_payment_url = $order->get_checkout_payment_url( true );

		$polling_script = '';
		if ( in_array( $order->get_status(), array( 'pending', 'failed' ) ) ) {
			$polling_script = '
			<script type="text/javascript">
				var snapscanPollCount = 0;
				function pollSnapScanPayment() {
					snapscanPollCount++;
					jQuery.getJSON("' . $check_order_url . '&count=" + snapscanPollCount).then(
					function(r) { // success
					    if (r.status == "processing" || r.status == "completed") {
					        window.location.replace("' . $order_received_url . '");
					    } else if (r.continue_polling) {
					            setTimeout(pollSnapScanPayment, 1000);
					    } else {
					        window.location.replace("' . $order_checkout_payment_url . '");
					    }
					},
					function(r) { // fail
					    // invalid response, try again after a few seconds
					    setTimeout(pollSnapScanPayment, 3000);
					});
				}
				pollSnapScanPayment();
			</script>';
		}
		print $polling_script;
	}

	/**
	 * Validate itn request
	 *
	 */
	function validate_itn_request() {

		if ( ! isset( $_POST['payload'] ) ) {
			status_header( 400 );
			exit( 'payload missing' );
		}

		if ( ! ( isset( $_GET['token'] ) && $_GET['token'] === $this->settings['merchant_callback_token'] ) ) {
			status_header( 400 );
			exit( 'Missing or incorrect token' );
		}
	}

	function poll_snapscan_payment( $merchantReference ) {
		$args = array(
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $this->settings['merchant_api_key'] . ':' . '' )
			)
		);

		$params = '?status=completed&merchantReference=' . urlencode( $merchantReference );
		$res    = wp_remote_request( $this->api_endpoint . $params, $args );

		if ( $res['response']['code'] == 200 ) {
			$payments_array = json_decode( $res['body'], true );
			if ( count( $payments_array ) > 0 ) {
				$this->successful_request( $payments_array[0] );
			}
		} else {
			if ( is_wp_error( $res ) ) {
				$this->log( $res->get_error_code() . ' - ' . $res->get_error_message() );
			}
		}
	}

	function exit_with_json_result( $order_status, $continue_polling ) {
		print wp_json_encode(
			array(
				'status'           => $order_status,
				'continue_polling' => $continue_polling
			)
		);
		exit;
	}

	/**
	 * API Callback
	 */
	function api_callback() {

		if ( isset( $_GET['check_status'] ) && $_GET['check_status'] ) {
			$order_id = (int) $_GET['oid'];
			$order    = wc_get_order( $order_id );
			// if the order is not pending or failed then we can stop polling
			if ( ! in_array( $order->get_status(), array( 'pending', 'failed' ) ) ) {
				$this->exit_with_json_result( $order->get_status(), false );
			} else {
				// poll snapscan servers directly every 5 seconds
				$last_poll_time = get_post_meta( $order_id, 'snapscan_poll_request', true );
				if ( $last_poll_time === '' || ( time() - $last_poll_time > 5 ) ) {
					update_post_meta( $order_id, 'snapscan_poll_request', time() );
					$this->poll_snapscan_payment( $order_id );
				}
			}
			$this->exit_with_json_result( $order->get_status(), true );
		}

		// Payment itn callback
		$_POST = stripslashes_deep( $_POST );
		$this->validate_itn_request();
		do_action( 'valid-snapscan-itn-request', json_decode( $_POST['payload'], true ) );
	}

	function validate_order_price( $payload, WC_Order $order ) {
		if ( intval( $payload['totalAmount'] ) < round( $order->get_total() * 100 ) ) {
			if ( 'on-hold' !== $order->get_status() ) {
				$order->add_order_note( sprintf( __( "Incorrect Payment Amount: Expected %s but got %s", 'woocommerce-gateway-snapscan' ),
					wc_price( $order->get_total() ), wc_price( $payload['totalAmount'] / 100 ) ) );
				$order->update_status( 'on-hold', sprintf( __( 'Payment %s via ITN.', 'woocommerce-gateway-snapscan' ), strtolower( sanitize_text_field( $payload['status'] ) ) ) );
			}
			$this->exit_with_json_result( $order->get_status(), false );
		}
	}

	/**
	 * Successful Payment
	 *
	 * @param $payload
	 */
	function successful_request( $payload ) {
		if ( ! ( isset( $payload['merchantReference'] ) && is_numeric( $payload['merchantReference'] ) ) ) {
			status_header( 400 );
			exit( 'Missing or incorrect merchantReference' );
		}
		if ( ! ( isset( $payload['totalAmount'] ) && is_numeric( $payload['totalAmount'] ) ) ) {
			status_header( 400 );
			exit( 'Missing or incorrect totalAmount' );
		}

		$order_id = (int) $payload['merchantReference'];
		$order    = new WC_Order( $order_id );

		if ( ( 'completed' === $payload['status'] ) && ( ! in_array( $order->get_status(), array(
				'completed',
				'processing'
			) ) )
		) {
			$this->validate_order_price( $payload, $order );
			$order->add_order_note( __( "SnapScan ITN payment completed\nauthCode: {$payload['authCode']}", 'woocommerce-gateway-snapscan' ) );
			$order->payment_complete();
			delete_post_meta( $order_id, 'snapscan_poll_request' );
		}
	}

	public function skip_photon( $skip, $image_url, $args, $scheme ) {
		// Jetpack strips query params from the image url which we cannot allow for the QR code image.
		if ( false !== strpos( $image_url, 'pos.snapscan.io' ) ) {
			$skip = true;
		}
		return $skip;
	}

}

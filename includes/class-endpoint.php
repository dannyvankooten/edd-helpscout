<?php

namespace EDD\HelpScout;

use EDD_Customer;
use EDD_Software_Licensing;
use EDD_Download;
use EDD_Payment;
use EDD_Recurring_Subscriber;

/**
 * This class takes care of requests coming from HelpScout App Integrations
 */
class Endpoint {

	/**
	 * @var array|mixed
	 */
	private $data;

	/**
	 * @var bool|obj
	 */
	private $edd_customer = false;

	/**
	 * @var array
	 */
	private $customer_emails = array();

	/**
	 * @var array
	 */
	private $customer_payments = array();

	/**
	 * Constructor
	 */
	public function __construct() {

		// get request data
		$this->data = $this->parse_data();
				
		// validate request
		if ( ! $this->validate() ) {
			$this->respond( 'Invalid signature' );
			exit;
		}

		// get EDD customer details
		$this->edd_customers = $this->get_edd_customers();
				
		// get customer email(s)
		$this->customer_emails = $this->get_customer_emails();
				
		// get customer payment(s)
		$this->customer_payments = $this->query_customer_payments();
				
		// build the final response HTML for HelpScout
		$html = $this->build_response_html();

		// respond with the built HTML string
		$this->respond( $html );
	}

	/**
	 * @return array|mixed
	 */
	private function parse_data() {

		/**
		 * use dummy data, e.g. for local environments
		 */
		if( defined( 'HELPSCOUT_DUMMY_DATA' ) ){
			$email = defined( 'HELPSCOUT_DUMMY_DATA_EMAIL' ) ? HELPSCOUT_DUMMY_DATA_EMAIL : 'user@example.com';
		
			$data = array(
				'ticket' => array(
					'id'        => 123456789,
					'number'    => 12345,
					'subject'   => 'I need help using your plugin'
				),
				'customer' => array(
					'id' => 987654321,
					'fname' => 'Firstname',
					'lname' => 'Lastname',
					'email' => $email,
					'emails' => array( $email ),
				),
			);
		} else {
			$data_string = file_get_contents( 'php://input' );
			$data        = json_decode( $data_string, true );
		}

		return $data;
	}

	/**
	 * Validate the request
	 *
	 * - Validates the payload
	 * - Validates the request signature
	 *
	 * @return bool
	 */
	private function validate() {

		// we need at least this
		if ( ! isset( $this->data['customer']['email'] ) && ! isset( $this->data['customer']['emails'] ) ) {
			return false;
		}
			
		// check request signature
		$request = new Request( $this->data );

		if ( defined( 'HELPSCOUT_DUMMY_DATA' ) || ( isset( $_SERVER['HTTP_X_HELPSCOUT_SIGNATURE'] ) && $request->signature_equals( $_SERVER['HTTP_X_HELPSCOUT_SIGNATURE'] ) ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Get customers from EDD by email addresses
	 *
	 * @return array
	 */
	private function get_edd_customers() {
		$customers = array();

		$helpscout_emails = $this->data['customer']['emails'];
		foreach ($helpscout_emails as $email) {
			$customer = new EDD_Customer( $email );
			if ( $customer->id == 0 || !empty($customers[$customer->id]) ) {
				continue;
			} else {
				$customers[$customer->id] = $customer;
			}
		}

		return $customers;
	}

	/**
	 * Get an array of emails belonging to the customer
	 *
	 * @return array
	 */
	private function get_customer_emails() {
		$emails = $this->data['customer']['emails'];

		foreach ($this->edd_customers as $customer_id => $customer) {
			/**
			 * merge multiple emails from the EDD customer profile
			 */
			if ( isset( $customer->emails ) && is_array( $customer->emails ) && count( $customer->emails ) > 1 ) {
				$emails = array_merge( $emails, $customer->emails );
			}
		}

		/**
		 * remove possible duplicates
		 */
		$emails = array_unique( $emails );

		/**
		 * Filter email address of the customer
		 * @since 1.1
		 */
		$emails = apply_filters( 'edd_helpscout_customer_emails', $emails, $this->data );

		if ( count( $emails ) === 0 ) {
			$this->respond( 'No customer email given.' );
		}

		return $emails;
	}

	/**
	 * Query all payments belonging to the customer (by email)
	 *
	 * @return array
	 */
	private function query_customer_payments() {

		$payments = array();

		/**
		 * Allows you to perform your own search for customer payments, based on given data.
		 *
		 * @since 1.1
		 */
		$payments = apply_filters( 'edd_helpscout_customer_payments', $payments, $this->customer_emails, $this->data );

		if ( ! empty( $payments ) ) {
			return $payments;
		}
				
		global $wpdb;

		/**
		 * query by email(s)
		 * should be replaced with another method at some point
		 * using EDD_Customer->get_payments() would be the best choice, but we would need to guarantee that
		 * we also find payments no longer attached to a customer
		 */
		$sql = "SELECT p.ID";
		$sql .= " FROM {$wpdb->posts} p, {$wpdb->postmeta} pm";
		$sql .= " WHERE p.post_type = 'edd_payment'";
		$sql .= " AND p.ID = pm.post_id";
		$sql .= " AND pm.meta_key = '_edd_payment_user_email'";

		if ( count( $this->customer_emails ) > 1 ) {
			$in_clause = rtrim( str_repeat( "'%s', ", count( $this->customer_emails ) ), ", " );
			$sql .= " AND pm.meta_value IN($in_clause)";
		} else {
			$sql .= " AND pm.meta_value = '%s'";
		}

		$sql .= " GROUP BY p.ID  ORDER BY p.ID DESC";
				
		$query   = $wpdb->prepare( $sql, $this->customer_emails );
		$results = $wpdb->get_col( $query );

		if ( is_array( $results ) ) {
			return $results;
		}

		return array();
	}

	private function get_customer_data() {
		$customers = array();
		foreach ( $this->edd_customers as $customer_id => $edd_customer ) {
			$customers[$customer_id] = array(
				'name'      => $edd_customer->name,
				'id'        => $customer_id,
				'link'      => esc_attr( admin_url( 'edit.php?post_type=download&page=edd-customers&view=overview&id='. $customer_id ) ),
				'user_id'   => $edd_customer->user_id,
				'user_link' => esc_attr( admin_url( 'user-edit.php?user_id='. $edd_customer->user_id ) ),
			);
		}
		return $customers;
	}

	private function get_customer_orders() {
		$orders = array();
		foreach ($this->query_customer_payments() as $payment_id) {
			$payment = new EDD_Payment( $payment_id );
			$order_items = array();
			foreach ($payment->downloads as $key => $item) {
				$download = new EDD_Download( $item['id'] );
				$price_id = edd_get_cart_item_price_id( $item );

				$order_items[$key] = array(
					'title'        => $download->get_name(),
					'price_option' => ( ! empty( $price_id ) && 0 !== $price_id ) ? edd_get_price_option_name( $item['id'], $price_id, $payment->ID ) : '',
					'files'        => edd_get_download_files( $download->ID, $price_id ),
				);
			}

			switch ($payment->status) {
				case 'edd_subscription':
				case 'publish':
					$status_color = 'green';
					break;
				case 'refunded':
				case 'revoked':
					$status_color = 'red';
					break;
				case 'cancelled':
				case 'failed':
				case 'preapproval':
				case 'preapproval_pending':
					$status_color = 'orange';
					break;
				case 'abandoned':
				case 'pending':
				case 'processing':
				default:
					$status_color = ''; // grey
					break;
			}

			$orders[$payment_id] = array(
				'id'             => $payment_id,
				'total'          => edd_payment_amount( $payment_id ),
				'items'          => $order_items,
				'payment_method' => $this->get_payment_method( $payment ),
				'date'           => !empty( $payment->completed_date ) ? $payment->completed_date : $payment->date,
				'status'         => $payment->status,
				'status_label'   => $payment->status_nicename,
				'status_color'   => $status_color,
				'link'           => esc_attr( admin_url( 'edit.php?post_type=download&page=edd-payment-history&view=view-order-details&id='. $payment_id ) ),
			);
		}
		return $orders;
	}

	private function get_customer_licenses() {
		$licenses = array();
		if ( !function_exists( 'edd_software_licensing' ) || empty( $this->edd_customers ) ) {
			return $licenses;
		}

		foreach ( $this->edd_customers as $customer_id => $customer ) {
			$customer_licenses = edd_software_licensing()->licenses_db->get_licenses( array(
				'number'      => -1,
				'customer_id' => $customer->id,
				'orderby'     => 'id',
				'order'       => 'ASC',
			) );
			if ( !empty( $customer_licenses ) ) {
				foreach ( $customer_licenses as $license ) {
					switch ($payment->status) {
						case 'active':
							$status_color = 'green';
							break;
						case 'disabled':
							$status_color = 'red';
							break;
						case 'expired':
							$status_color = 'orange';
							break;
						case 'inactive':
						default:
							$status_color = ''; // grey
							break;
					}

					$license_data = array(
						'key'              => $license->key,
						'link'             => esc_url( admin_url( 'edit.php?post_type=download&page=edd-licenses&view=overview&license_id=' . $license->ID ) ),
						'title'            => $license->get_download()->get_name(),
						'price_option'     => '',
						'status'           => $license->status,
						'status_color'     => $status_color,
						'expires'          => !empty( $license->expiration ) ? date_i18n( get_option( 'date_format', 'Y-m-d' ), $license->expiration ) : '-',
						'is_lifetime'      => $license->is_lifetime,
						'limit'            => $license->activation_limit,
						'activation_count' => $license->activation_count,
						'sites'            => $license->sites,
						'upgrades'         => array(),
						'renewal_link'     => ( edd_sl_renewals_allowed() && ! $license->is_lifetime ) ? $license->get_renewal_url() : '',
					);

					if( $license->get_download()->has_variable_prices() && empty( $license->parent ) ) {
						$prices   = $license->get_download()->get_prices();
						$license_data['price_option'] = $prices[ $license->price_id ]['name'];
					}

					if ( $license->status != 'expired' && empty( $license->parent ) ) {
						if( $upgrades = edd_sl_get_license_upgrades( $license->ID ) ) {
							foreach( $upgrades as $upgrade_id => $upgrade ) {
								$license_data['upgrades'][$upgrade_id] = array(
									'title'        =>  get_the_title( $upgrade['download_id'] ),
									'price_option' => isset( $upgrade['price_id'] ) && edd_has_variable_prices( $upgrade['download_id'] ) ? edd_get_price_option_name( $upgrade['download_id'], $upgrade['price_id'] ) : '',
									'price'        => edd_currency_filter( edd_sanitize_amount( edd_sl_get_license_upgrade_cost( $license->ID, $upgrade_id ) ) ),
									'url'          => edd_sl_get_license_upgrade_url( $license->ID, $upgrade_id ),
								);
							}
						}
					}

					// move child licenses to parent
					if ( ! empty( $license->parent ) ) {
						$children = ! empty( $licenses[$license->parent]['children'] ) ? $licenses[$license->parent]['children'] : array();
						$children = $children + array( $license->ID => $license_data );
						$licenses[$license->parent]['children'] = $children;
					} else { // parent or regular
						if (isset($licenses[$license->ID])) {
							$licenses[$license->ID] = array_merge( $licenses[$license->ID], $license_data );
						} else {
							$licenses[$license->ID] = $license_data;
						}
					}
				}
			}
		}

		krsort( $licenses ); // sort new to old

		return $licenses;
	}

	private function get_customer_subscriptions() {
		$subscriptions = array();
		if ( !function_exists( 'EDD_Recurring' ) || empty( $this->edd_customers ) ) {
			return $subscriptions;
		}

		foreach ( $this->edd_customers as $customer_id => $customer ) {
			$subscriber    = new EDD_Recurring_Subscriber( $customer->id );
			if( $customer_subscriptions = $subscriber->get_subscriptions() ) {
				foreach ( $customer_subscriptions as $subscription ) {
					switch ($subscription->get_status()) {
						case 'active':
						case 'trialling':
							$status_color = 'green';
							break;
						case 'cancelled':
							$status_color = 'red';
							break;
						case 'expired':
							$status_color = 'orange';
							break;
						default:
							$status_color = '';
							break;
					}

					$subscriptions[$subscription->id] = array(
						'title'        => get_the_title( $subscription->product_id ),
						'link'         => esc_url( admin_url( 'edit.php?post_type=download&page=edd-subscriptions&id=' . $subscription->id ) ),
						'status'       => $subscription->get_status(),
						'status_label' => $subscription->get_status_label(),
						'status_color' => $status_color,
					);
				}
			}
		}

		krsort( $subscriptions ); // sort new to old

		return $subscriptions;
	}

	/**
	 * Process the request
	 *  - Find purchase data
	 *  - Generate response*
	 * @link http://developer.helpscout.net/custom-apps/style-guide/ HelpScout Custom Apps Style Guide
	 * @return string
	 */
	private function build_response_html() {

		// general customer data
		$customers = $this->get_customer_data();
		$html = $this->render_template_html( 'customers.php', compact( 'customers' ) );

		// customer licenses (EDD Software Licensing)
		if ( function_exists( 'edd_software_licensing' ) ) {
			$licenses = $this->get_customer_licenses();
			$html .= $this->render_template_html( 'licenses.php', compact( 'licenses' ) );
		}

		// customer orders
		$orders = $this->get_customer_orders();
		$html .= $this->render_template_html( 'orders.php', compact( 'orders' ) );

		// customer subscriptions (EDD Recurring)
		if ( function_exists('EDD_Recurring') ) {
			$subscriptions = $this->get_customer_subscriptions();
			$html .= $this->render_template_html( 'subscriptions.php', compact( 'subscriptions' ) );
		}

		return $html;
	}

	/**
	 * @param $order
	 *
	 * @return string
	 */
	public function render_template_html( $file, $args = array() ) {
		$helpscout_data = $this->data;
		if ( ! empty( $args ) && is_array( $args ) ) {
			extract( $args );
		}
		$path = $this->get_template_path( $file );
		ob_start();
		if (file_exists($path)) {
			include($path);
		}
		return ob_get_clean();

		return $html;
	}

	public function get_template_path( $file ) {
		$template_base_path = dirname( EDD_HELPSCOUT_FILE ) . '/views';
		return "{$template_base_path}/{$file}";
	}

	/**
	 * Get the payment method used for the given $payment_id. Returns a link to the transaction in Stripe or PayPal if possible.
	 *
	 * @param int $payment_id
	 *
	 * @return string
	 */
	private function get_payment_method( $payment ) {
		$gateway        = $payment->gateway;
		$transaction_id = $payment->transaction_id;

		$payment_method = edd_get_gateway_admin_label( $gateway );

		switch ( $gateway ) {
			case 'paypal':
			case 'paypalexpress':
				if ( !empty($transaction_id) ) {
					$url = 'https://www.paypal.com/us/vst/id='.esc_attr( $transaction_id );
					$payment_method = sprintf('<a href="%s" target="_blank">%s</a>', $url, $payment_method );
				}
				break;

			case 'stripe':
				if ( !empty($transaction_id) ) {
					$url = 'https://dashboard.stripe.com/payments/' . esc_attr( $transaction_id );
					$payment_method = sprintf('<a href="%s" target="_blank">%s</a>', $url, $payment_method );
				}
				break;
			case 'manual_purchases':
				$payment_method = 'Manual';
				break;
			default:
				if ( $transaction_link = apply_filters( 'edd_payment_details_transaction_id-'.$gateway, $transaction_id, $payment->ID ) ) {
					// Always use payment method as link text
					$payment_method = preg_replace('/<a(.+?)>.+?<\/a>/i',"<a$1>".$payment_method."</a>",$transaction_link);
				}
				
				break;
		}

		return $payment_method;
	}

	/**
	 * Set JSON headers, return the given response string
	 *
	 * @param string $html HTML content of the response
	 * @param int    $code The HTTP status code to respond with
	 */
	private function respond( $html, $code = 200 ) {
		$response = array( 'html' => $html );

		// clear output, some plugins might have thrown errors by now.
		if ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		wp_send_json( $response, $code );
	}

}

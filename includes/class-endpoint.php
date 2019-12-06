<?php

namespace EDD\HelpScout;

use EDD_Customer;
use EDD_Software_Licensing;

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
		$this->edd_customer = $this->get_edd_customer();
                
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
                            'ticket' => array
                                    (
                                    'id'        => 123456789,
                                    'number'    => 12345,
                                    'subject'   => 'I need help using your plugin'
                                    ),
                            'customer' => array
                                    (
                                    'id' => 987654321,
                                    'fname' => 'Firstname',
                                    'lname' => 'Lastname',
                                    'email' => $email,
                                    'emails' => array
                                        (
                                            $email
                                        )

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

		if ( defined( 'HELPSCOUT_DUMMY_DATA' ) 
                        || ( isset( $_SERVER['HTTP_X_HELPSCOUT_SIGNATURE'] ) && $request->signature_equals( $_SERVER['HTTP_X_HELPSCOUT_SIGNATURE'] ) ) ) {
			return true;
		}

		return false;
	}
        
	/**
	 * Get customer details from EDD
	 *
	 * @return array
	 */
	private function get_edd_customer() {

                // this is the customer data received from Help Scout
		$this->data['customer'];
                
                if ( ! isset( $this->data['customer']['email'] ) || ! class_exists( 'EDD_Customer', false ) ) {
			return false;
		}
		
                /**
                 * returns Customer object or false
                 */
                return new EDD_Customer( $this->data['customer']['email'] );                
	}        

	/**
	 * get customer mail address by license key, if added as the last word in the subject line
         * this feature is not yet documented. Not sure if it is even practically useful, i.e. how to tell our clients about that?
	 *
	 * @return array
	 */
	private function get_customer_emails_by_license_key() {

		if ( ! class_exists( 'EDD_Software_Licensing' ) || !isset( $this->data['ticket']['subject'] ) ) {
			return array();
		}

		$subject_line = $this->data['ticket']['subject'];
		$last_word    = substr( $subject_line, strrpos( $subject_line, ' ' ) + 1 );

		// only search for license key if last word actually looks like a license key
		// this check is dirty, as people could be using the filter in EDD for generating their own type of licenes key...
		if ( strlen( $last_word ) === 32 ) {
			$license_key = $last_word;
			$edd_sl      = edd_software_licensing();
			$license_id  = $edd_sl->get_license_by_key( $license_key );
			$payment_id  = get_post_meta( $license_id, '_edd_sl_payment_id', true );
			$user_info   = edd_get_payment_meta_user_info( $payment_id );

			if ( ! empty( $user_info['email'] ) ) {
				return array( $user_info['email'] );
			}
		}

		return array();
	}

	/**
	 * Get an array of emails belonging to the customer
	 *
	 * @return array
	 */
	private function get_customer_emails() {

		$customer_data = $this->data['customer'];
		$emails        = array();

		$emails = array_merge( $emails, $this->get_customer_emails_by_license_key() );
                
                /**
                 * merge multiple emails from the Help Scout customer details
                 */
		if ( isset( $customer_data['emails'] ) && is_array( $customer_data['emails'] ) && count( $customer_data['emails'] ) > 1 ) {
			$emails = array_merge( $emails, $customer_data['emails'] );
		} elseif ( isset( $customer_data['email'] ) ) {
			$emails[] = $customer_data['email'];
		}
                
                /**
                 * merge multiple emails from the EDD customer profile
                 */
                if ( isset( $this->edd_customer->emails ) && is_array( $this->edd_customer->emails ) && count( $this->edd_customer->emails ) > 1 ) {
			$emails = array_merge( $emails, $this->edd_customer->emails );
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
		$sql = "SELECT p.ID, p.post_status, p.post_date";
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
		$results = $wpdb->get_results( $query );

		if ( is_array( $results ) ) {
			return $results;
		}

		return array();
	}

	/**
	 * Process the request
	 *  - Find purchase data
	 *  - Generate response*
	 * @link http://developer.helpscout.net/custom-apps/style-guide/ HelpScout Custom Apps Style Guide
	 * @return string
	 */
	private function build_response_html() {

		if ( count( $this->customer_payments ) === 0 ) {

			// No purchase data was found
			return sprintf( '<p>No payments found for %s.</p>', '<strong>' . join( '</strong> or <strong>', $this->customer_emails ) . '</strong>' );
		}

		// build array of purchases
		$orders = array();
		foreach ( $this->customer_payments as $payment ) {

			$order                        = array();
			$order['payment_id']          = $payment->ID;
			$order['date']                = $payment->post_date;
			$order['amount']              = edd_get_payment_amount( $payment->ID );
			$order['currency']            = edd_get_payment_currency_code( $payment->ID );
			$order['status']              = $payment->post_status;
			$order['payment_method']      = $this->get_payment_method( $payment->ID );
			$order['downloads']           = array();
			$order['resend_receipt_link'] = '';
			$order['is_renewal']          = false;
			$order['is_completed']        = ( $payment->post_status === 'publish' );

			// do stuff for completed orders
			if ( $payment->post_status === 'publish' ) {
				$args                         = array(
					'payment_id' => (string) $order['payment_id'],
				);
				$request                      = new Request( $args );
				$order['resend_receipt_link'] = $request->get_signed_url( 'resend_purchase_receipt' );
			}

			// find purchased Downloads.
			$order['downloads'] = (array) edd_get_payment_meta_downloads( $payment->ID );

			// for each download, find license + sites
			if ( function_exists( 'edd_software_licensing' ) && version_compare( EDD_SL_VERSION, '3.6', '>' ) ) {

				$payment_licenses = edd_software_licensing()->get_licenses_of_purchase( $order['payment_id'] );

				if ( ! empty( $payment_licenses ) ) {
					foreach ( $order['downloads'] as $download_key => $download ) {

						$licenses = array(
							'parent' => array(),
							'child'  => array(),
						);
						// get parent first
						foreach( $payment_licenses as $key => $license ) {
							if ( $license->download_id != $download['id'] ) {
								continue;
							}

							// if for some reason we have multiple parent licenses for this download, defer them to children
							if ( empty($licenses['parent']) ) {
								$licenses['parent'][$key] = $license;
							} else {
								$licenses['child'][$key] = $license;
							}
						}

						// get children for this license
						foreach ( $licenses['parent'] as $parent_key => $parent_license ) {
							foreach( $payment_licenses as $key => $license ) {
								if( $license->parent && $license->parent == $parent_license->ID ) {
									$licenses['child'][$key] = $license;
								}
							}
						}


						foreach ($licenses as $license_type => $licenses ) {
							foreach ($licenses as $key => $license) {
								$license_data = array(
									'limit'      => $license->activation_limit,
									'key'        => $license->key,
									'view_url'   => admin_url( 'edit.php?post_type=download&page=edd-licenses&view=overview&license_id=' . $license->ID ),
									'is_expired' => $license->is_expired(),
									'is_revoked' => $license->post_status === 'revoked',
									'sites'      => array(),
									'expires_at' => $license->expiration,
								);

								foreach ( $license->sites as $site ) {
									$args = array(
										'license_id' => (string) $license->ID,
										'site_url'   => $site,
									);

									// make sure site url is prefixed with "http://"
									$site_url = strpos( $site, '://' ) !== false ? $site : 'https://' . $site;

									$request                                          = new Request( $args );
									$license_data['sites'][] = array(
										'url'             => $site_url,
										'deactivate_link' => $request->get_signed_url( 'deactivate_site_license' )
									);
								}

								if ($license_type == 'parent') {
									$order['downloads'][ $download_key ]['license'] = $license_data;
								} else {
									$order['downloads'][ $download_key ]['child_licenses'][] = $license_data;									
								}
							}
						}
					}
				}
			}

			$orders[] = $order;
		}

		// build HTML output
		$html = '';
                
                // add name of the customer at the top, since we only have one
                if( $this->edd_customer ){
                        $html .= '<strong><a target="_blank" href="' . esc_attr( admin_url( 'edit.php?post_type=download&page=edd-customers&view=overview&id='. $this->edd_customer->id ) ) . '">' . $this->edd_customer->name . '</a></strong>';
                }
                
		foreach ( $orders as $order ) {
			$html .= str_replace( '\t', '', $this->order_row( $order ) );
		}

		return $html;
	}

	/**
	 * @param $order
	 *
	 * @return string
	 */
	public function order_row( array $order ) {
		ob_start();
		include dirname( EDD_HELPSCOUT_FILE ) . '/views/order-row.php';
		$html = ob_get_clean();

		return $html;
	}

	/**
	 * Get the payment method used for the given $payment_id. Returns a link to the transaction in Stripe or PayPal if possible.
	 *
	 * @param int $payment_id
	 *
	 * @return string
	 */
	private function get_payment_method( $payment_id ) {

		$payment_method = edd_get_payment_gateway( $payment_id );

		switch ( $payment_method ) {
			case 'paypal':
			case 'paypalexpress':
				$notes = edd_get_payment_notes( $payment_id );
				foreach ( $notes as $note ) {
					if ( preg_match( '/^PayPal Transaction ID: ([^\s]+)/', $note->comment_content, $match ) ) {
						$transaction_id = $match[1];
						$payment_method = '<a href="https://www.paypal.com/us/vst/id=' . esc_attr( $transaction_id ) . '" target="_blank">PayPal</a>';
						break 2;
					}
				}
				break;

			case 'stripe':
				$notes = edd_get_payment_notes( $payment_id );
				foreach ( $notes as $note ) {
					if ( preg_match( '/^Stripe Charge ID: ([^\s]+)/', $note->comment_content, $match ) ) {
						$transaction_id = $match[1];
						$payment_method = '<a href="https://dashboard.stripe.com/payments/' . esc_attr( $transaction_id ) . '" target="_blank">Stripe</a>';
						break 2;
					}
				}
				break;
			case 'manual_purchases':
				$payment_method = 'Manual';
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

		status_header( $code );
		header( "Content-Type: application/json" );
		echo json_encode( $response );
		die();
	}

}

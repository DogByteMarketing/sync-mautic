<?php

/**
 * Plugin Name: Sync Mautic
 * Plugin URI: https://www.dogbytemarketing.com/contact/
 * Description: Syncs leads passed via webhooks along with syncing order product, categories, and brands to Mautic.
 * Author: Dog Byte Marketing
 * Version: 1.1.0
 * Requires at least: 6.6.2
 * Requires PHP: 7.4
 * Author URI: https://www.dogbytemarketing.com
 * License: GPL3
 */

namespace DogByteMarketing;

use WP_Error;
use WP_REST_Request;

if (!defined('ABSPATH')) exit;

register_deactivation_hook(__FILE__, array(__NAMESPACE__ . '\Sync_Mautic', 'deactivation'));

/**
 * ToDo
 * 
 * Untag orders if refunded / cancelled
 */

class Sync_Mautic
{

	const VERSION = '1.1.0';

	const PAGE_HIT_LINE_ITEM_CAP = 20;

	const WEBHOOK_TIMEOUT = 5;

	const META_WEBHOOK_QUEUED = '_mautic_ecommerce_order_queued';

	const META_WEBHOOK_SENT = '_mautic_ecommerce_order_sent';

	const META_CLICKTHROUGH = '_mautic_clickthrough';

	const CLICKTHROUGH_COOKIE = 'sync_mautic_ct';

	const CLICKTHROUGH_COOKIE_TTL = 2592000;

	const CLICKTHROUGH_MAX_LENGTH = 4096;

	/**
	 * Sync Mautic Settings
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      array    $settings      Sync Mautic Settings
	 */
	private $settings;

	/**
	 * Mautic Base URL
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $base_url     Mautic Base URL
	 */
	private $base_url;

	/**
	 * Mautic Client ID
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $client_id    Mautic API Key
	 */
	private $client_id;

	/**
	 * Mautic Secret Key
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $client_secret   Mautic API Key
	 */
	private $client_secret;

	/**
	 * Mautic Checkout Optin
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $checkout_optin      Mautic Checkout Optin
	 */
	private $checkout_optin;

	/**
	 * WooCommerce Order Tracking
	 *
	 * @since    1.0.8
	 * @access   private
	 * @var      bool      $order_tracking      Enable WooCommerce Order Tracking
	 */
	private $order_tracking;

	/**
	 * WooCommerce order tracking method
	 *
	 * @since    1.1.0
	 * @access   private
	 * @var      string    $order_tracking_method    page_hits or webhook
	 */
	private $order_tracking_method;

	/**
	 * Send order line items to Ecommerce Connector
	 *
	 * @since    1.1.0
	 * @access   private
	 * @var      bool      $order_line_items         Enable line item tracking
	 */
	private $order_line_items;

	/**
	 * Ecommerce Connector webhook secret
	 *
	 * @since    1.1.0
	 * @access   private
	 * @var      string    $webhook_secret           HMAC webhook secret
	 */
	private $webhook_secret;

	/**
	 * Collect Mautic clickthrough tokens for email attribution
	 *
	 * @since    1.1.0
	 * @access   private
	 * @var      bool      $collect_clickthrough     Store ct for order attribution
	 */
	private $collect_clickthrough;

	/**
	 * Store admin notices
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      array     $admin_notice Store admin notices
	 */
  private $admin_notice;
	
	/**
	 * __construct
	 *
	 * @return void
	 */
	public function __construct() {
		$settings = get_option('dogbytemarketing_sync_mautic_settings');
		$this->settings = is_array($settings) ? $settings : array();
		$this->base_url       = isset($this->settings['base_url']) ? $this->settings['base_url'] : '';
		$this->client_id      = isset($this->settings['client_id']) ? $this->settings['client_id'] : '';
		$this->client_secret  = isset($this->settings['client_secret']) ? $this->settings['client_secret'] : '';
		$this->checkout_optin          = isset($this->settings['checkout_optin']) ? $this->settings['checkout_optin'] : 'disabled';
		$this->order_tracking          = isset($this->settings['order_tracking']) ? (bool) $this->settings['order_tracking'] : false;
		$this->order_tracking_method   = isset($this->settings['order_tracking_method']) ? $this->settings['order_tracking_method'] : 'page_hits';
		$this->order_line_items        = isset($this->settings['order_line_items']) ? (bool) $this->settings['order_line_items'] : false;
		$this->webhook_secret          = isset($this->settings['webhook_secret']) ? $this->settings['webhook_secret'] : '';
		$this->collect_clickthrough    = isset($this->settings['collect_clickthrough']) ? (bool) $this->settings['collect_clickthrough'] : false;

		if ($this->order_tracking_method !== 'webhook') {
			$this->order_tracking_method = 'page_hits';
		}
	}
	
	/**
	 * Init
	 *
	 * @return void
	 */
	public function init() {
		self::debug('Init');

		if ($this->base_url && $this->client_id && $this->client_secret) {
			add_action('rest_api_init', array($this, 'add_endpoints'));
			add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
			
			// WooCommerce
			add_action('woocommerce_order_status_completed', array($this, 'order_tagging'));
			
			// Add WooCommerce order tracking to WP Mautic frontend tracking
			
			if ($this->order_tracking) {
				self::debug('Start Order Tracking');

				if ($this->order_tracking_method === 'webhook') {
					add_action('woocommerce_order_status_completed', array($this, 'queue_ecommerce_order_webhook'));

					if ($this->collect_clickthrough) {
						add_action('send_headers', array($this, 'capture_clickthrough'));
						add_action('woocommerce_checkout_order_processed', array($this, 'store_clickthrough_on_order'));
						add_action('woocommerce_store_api_checkout_order_processed', array($this, 'store_clickthrough_on_store_api_order'));
					}
				} else {
					add_filter('wpmautic_tracking_attributes', array($this, 'add_order_tracking_attributes'));
				}
			}

			if ($this->checkout_optin !== 'disabled') {
				add_action('woocommerce_review_order_before_submit', array($this, 'add_checkout_optin'));
				add_action('woocommerce_checkout_order_processed', array($this, 'add_checkout_lead'));
			}

			// We need to handle compatibility for those previously on the beta
			if ($this->has_used_beta()) {
				add_shortcode('mautic', array($this, 'mautic_form'));
			} else {
				add_shortcode('mautic_form', array($this, 'mautic_form'));
			}

			if (is_admin()) {
				$sync_mautic_start_time = get_option('dogbytemarketing_sync_mautic_start_time');

				if ($sync_mautic_start_time) {
					$total_orders = get_option('dogbytemarketing_sync_mautic_total_orders');
					$order_index  = get_option('dogbytemarketing_last_sync_mautic_order_index');

					if ($total_orders && $order_index) {
						$this->add_notice('Sync Mautic has synced ' . $order_index . ' out of ' . $total_orders . ' orders.', 'info');
					}
				}
			}
		} else {
			if (is_admin()) {
				$this->add_notice('Sync Mautic is not running as it needs to be <a href="options-general.php?page=sync-mautic">configured</a>.');
			}
		}

		add_action('dogbytemarketing_sync_mautic_past_orders', array($this, 'sync_mautic_past_orders'));
		add_action('dogbytemarketing_sync_mautic_send_ecommerce_order', array($this, 'send_ecommerce_order_webhook'));
		add_action('admin_post_dogbytemarketing_dismiss_sync_mautic_1_1_0', array($this, 'dismiss_1_1_0_notice'));
		add_filter('cron_schedules', array($this, 'mautic_schedule'));
		add_action('admin_menu', array($this, 'add_admin_menu'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
		add_action('admin_init', array($this, 'settings_init'));
		add_action('admin_init', array($this, 'maybe_show_1_1_0_notice'));
	}
	
	/**
	 * Enqueue scripts
	 *
	 * @return void
	 */
	public function enqueue_scripts() {
		wp_enqueue_style('newsletter-signup', plugins_url('/css/public.css', __FILE__), array(), filemtime(plugin_dir_path(dirname(__FILE__)) . dirname(plugin_basename(__FILE__))  . '/css/public.css'));
		wp_enqueue_script('newsletter-signup', plugins_url('/js/public.js', __FILE__), array('jquery'), filemtime(plugin_dir_path(dirname(__FILE__)) . dirname(plugin_basename(__FILE__))  . '/js/public.js'), true);
		wp_localize_script('newsletter-signup', 'newsletter_signup_object',
			array(
				'site_url' => site_url(),
				'nonce'    => wp_create_nonce('newsletter-signup-nonce'),
			)
		);
	}

	/**
	 * Add a new cron schedule for every minute
	 *
	 * @param  mixed $schedules
	 * @return void
	 */
	public function mautic_schedule($schedules) {
		$schedules['every_minute'] = array(
			'interval'  => 60,
			'display'   => __('Every Minute', 'sync-mautic')
		);
		
		return $schedules;
	}
	
	/**
	 * Cron job to sync past orders.
	 *
	 * @return void
	 */
	public function sync_mautic_past_orders() {
		$sync_complete = get_option('dogbytemarketing_sync_mautic_past_orders_complete');

		if (!$sync_complete) {
			$order_tagging = isset($this->settings['order_tagging']) ? $this->settings['order_tagging'] : array();

			// Exit if order tagging is disabled
			if (!$order_tagging) {
				self::debug('Order Sync Disabled');
				return;
			}

			self::debug('Sync Started');

			if (class_exists('WooCommerce')) {
				if ($this->base_url && $this->client_id && $this->client_secret) {
					$last_sync_mautic_order_index = get_option('dogbytemarketing_last_sync_mautic_order_index', 0);

					self::debug('Last synced index ' . $last_sync_mautic_order_index);
					
					$sync_mautic_start_time = get_option('dogbytemarketing_sync_mautic_start_time');

					if (!$sync_mautic_start_time) {
						$sync_mautic_start_time = time();
						update_option('dogbytemarketing_sync_mautic_start_time', time());

						self::debug('Added start time');
					}

					$sync_mautic_total_orders = get_option('dogbytemarketing_sync_mautic_total_orders');

					if (!$sync_mautic_total_orders) {
						self::debug('Total synced orders not found, adding...');

						$sync_mautic_total_orders = update_option('dogbytemarketing_sync_mautic_total_orders', wc_orders_count('wc-completed'));
					}

					if ($sync_mautic_total_orders > 0) {
						self::debug('Has total sync orders');

						$order_args = array(
							'order'          => 'ASC',
							'date_created'   => '<' . ($sync_mautic_start_time),
							'type'           => 'shop_order',
							'offset'         => $last_sync_mautic_order_index,
							'limit'          => 50,
							'status'         => array('wc-completed'),
							'return'         => 'ids',
						);

						self::debug('Order args: ' . print_r($order_args, true));

						$orders = wc_get_orders($order_args);

						self::debug('Order ids: ' . print_r($orders, true));

						if ($orders) {
							foreach ($orders as $order_id) {
								self::debug('Tagging order #' . $order_id);

								$this->order_tagging($order_id);
							}
					
							$last_sync_mautic_order_index += count($orders);
							update_option('dogbytemarketing_last_sync_mautic_order_index', $last_sync_mautic_order_index);

							self::debug('New order sync index ' . $last_sync_mautic_order_index);
						} else {
							update_option('dogbytemarketing_sync_mautic_past_orders_complete', true);
    					wp_clear_scheduled_hook('dogbytemarketing_sync_mautic_past_orders');

							delete_option('dogbytemarketing_sync_mautic_start_time');
							delete_option('dogbytemarketing_sync_mautic_total_orders');
							delete_option('dogbytemarketing_last_sync_mautic_order_index');

							self::debug('Order sync complete');
						}
					} else {
						self::error('Failed to sync past orders as the plugin has not detected any completed orders.');
					}
				} else {
					self::error('Failed to sync past orders as the plugin has not been configured.');
				}
			} else {
				self::debug('WooCommerce not enabled, syncing canceled.');
			}
		}
	}
	
	/**
	 * Tag orders with product categories and/or brands.
	 *
	 * @param  int $order_id
	 * @return void
	 */
	public function order_tagging($order_id) {
		$order_tagging = isset($this->settings['order_tagging']) ? $this->settings['order_tagging'] : array();

		// Exit if order tagging is disabled
		if (!$order_tagging) {
			self::debug('Order tagging disabled, exiting...');

			return;
		}

		$order = wc_get_order($order_id);

		// Exit if no order data
		if (!$order) {
			self::error('Failed to tag order #' . $order_id . ' - Unable to retrieve order.');

			return;
		}

		self::debug('Attempting to tag order #' . $order_id);

		$user_id    = $order->get_user_id();
		$email      = $order->get_billing_email();
		$first_name = $order->get_billing_first_name();
		$last_name  = $order->get_billing_last_name();
		$is_tagged  = $order->get_meta('_is_mautic_tagged', true);
		$lead_id    = $this->get_lead_id($email, $user_id);
    $tags       = array();

		if ($lead_id) {
			self::debug('Found lead id for order #' . $order_id);

			// Only need to tag contacts from their order once
			if (!$is_tagged) {
				self::debug('Lead ID ' . $lead_id . ' has not been tagged yet, getting tags...');

				$items = $order->get_items();

				if (in_array('categories', $order_tagging)) {
					self::debug('Getting categories...');

					$tags = array_merge($tags, $this->get_product_terms($items, 'product_cat'));

					if (!$tags) {
						self::debug('No product categories detected on order #' . $order_id);

						return;
					}
				}

				if (in_array('brands', $order_tagging)) {
					self::debug('Getting brands...');

					$tags = array_merge($tags, $this->get_product_terms($items, 'brand'));

					if (!$tags) {
						self::debug('No product brands detected on order #' . $order_id);

						return;
					}
				}

				if (in_array('products', $order_tagging)) {
					self::debug('Getting products...');

					$tags = array_merge($tags, $this->get_product_names($items));

					if (!$tags) {
						self::debug('No products detected on order #' . $order_id);

						return;
					}
				}

				self::debug('Updating lead tags');
				$update_lead_tags = $this->update_lead($lead_id, $tags, $first_name, $last_name);
				
				if (is_wp_error($update_lead_tags)) {
					if ($update_lead_tags->get_error_code() == 404) {
						self::debug('Cached user ' . $user_id . ' lead id ' . $lead_id . ' is no longer valid, attempting to get updated user.');

						$new_lead_id = $this->get_lead_id($email, $user_id, false);

						if ($new_lead_id) {
							self::debug('Updated lead id from ' . $lead_id . ' to ' . $new_lead_id);
						} else {
							self::error('Failed to get new lead id for user ' . $user_id . '. They may no longer be a contact.');
						}
					}
				} else if ($update_lead_tags) {
					self::debug('Lead tags updated successfully.');

					$order->update_meta_data('_is_mautic_tagged', true);
					$order->save();
				}
			} else {
				self::debug('Order #' . $order_id . ' was already tagged.');
			}
		} else {
			self::debug('Unable to get lead id for order #' . $order_id . '. '. $email . ' may not be subscribed.');
		}
	}
	
	/**
	 * Add WooCommerce order tracking attributes to WP Mautic frontend tracking
	 *
	 * @param  array $attrs Existing tracking attributes
	 * @return array $attrs Modified tracking attributes with order data
	 */
	public function add_order_tracking_attributes($attrs) {
		self::debug('Order Tracking Initializing');

		if (!function_exists('is_order_received_page') || !function_exists('wc_get_order')) {
			self::debug('Order Tracking Not Initialized');

			return $attrs;
		}

		if (is_order_received_page() && isset($_GET['key'])) {
			self::debug('Order Tracking Initialized');

			$order_id  = (int) get_query_var('order-received');
			$order_key = sanitize_text_field(wp_unslash($_GET['key']));

			if ($order_id > 0 && $order_key !== '') {
				$order = wc_get_order($order_id);

				if ($order && hash_equals($order->get_order_key(), $order_key)) {
					$attrs['order_id']       = $order->get_order_number();
					$attrs['order_total']    = $order->get_total();
					$attrs['order_currency'] = $order->get_currency();
					$attrs['order_source']   = 'woocommerce';

					if ($this->order_line_items) {
						$items = $this->get_order_line_items($order, self::PAGE_HIT_LINE_ITEM_CAP);
						if ($items) {
							$attrs['items'] = wp_json_encode($items);
						}
					}

					self::debug('Added order tracking attributes for order #' . $order_id);
				}
			}
		}

		return $attrs;
	}
	
	/**
	 * Get the lead id from email and add it to their profile
	 *
	 * @param  string $email
	 * @param  int    $user_id
	 * @param  bool   $cache_enabled
	 * @return void
	 */
	private function get_lead_id($email, $user_id, $cache_enabled = true) {
		$lead_id = $user_id > 0 ? get_user_meta($user_id, '_mautic_lead_id', true) : '';

		if ($lead_id && $cache_enabled) {
			self::debug('Cached lead ID ' . $lead_id . ' found.');
			return $lead_id;
		}

		self::debug('No cached lead ID found, fetching lead id...');

		$args = array(
			'method' => 'GET',
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->get_token(),
			),
		);

		$request = wp_remote_request($this->base_url . "/api/contacts?search=" . urlencode($email), $args);

		if (!is_wp_error($request)) {
			$response_code = wp_remote_retrieve_response_code($request);

			if ($response_code == 200 || $response_code == 201) {
				$body = isset($request['body']) ? json_decode($request['body'], true) : '';

				if ($body) {
					$total_contacts = isset($body['total']) ? $body['total'] : '';

					if ($total_contacts > 0) {
						$contacts = isset($body['contacts']) ? $body['contacts'] : '';

						if ($contacts) {
							self::debug('Fetching first lead id from the following data ' . print_r($contacts, true));

							$lead_id = array_key_first($contacts);

							self::debug('Adding lead ID ' . $lead_id . ' to cache.');

							update_user_meta($user_id, '_mautic_lead_id', (int) $lead_id);

							return $lead_id;
						}
					} else {
						self::debug('No contact found for ' . $email . ', they may not be subscribed.');
					}
				} else {
					self::error('There was no body in the response from the Mautic search endpoint.');
				}
			} else {
				self::error('Failed to get lead id. Either the request failed or you did not configure your Mautic Base URL and API Key settings.');
			}
		} else {
			$error_message = $request->get_error_message() ? $request->get_error_message() : 'WordPress encountered an error when attempting to get the lead id for: ' . $email;
			
			self::error($error_message);
		}
	}

	/**
	 * Update the lead
	 *
	 * @param  int    $lead_id
	 * @param  array  $tags
	 * @param  string $first_name
	 * @param  string $last_name
	 */
	private function update_lead($lead_id, $tags, $first_name = '', $last_name = '') {
		$args = array(
			'method' => 'PATCH',
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->get_token(),
			),
			'body' => array(
				'tags' => $tags,
				'firstname' => $first_name ? $first_name : sanitize_text_field($first_name),
				'lastname' => $last_name ? $last_name : sanitize_text_field($last_name),
			)
		);

		self::debug('Updating lead ID ' . $lead_id . ' to have the following tags ' . print_r($tags, true));

		$request = wp_remote_request($this->base_url . "/api/contacts/" . $lead_id . "/edit", $args);

		if (!is_wp_error($request)) {
			$response_code = wp_remote_retrieve_response_code($request);

			if ($response_code == 200 || $response_code == 201) {
				self::debug('Updated tags for lead ID ' . $lead_id);

				return true;
			} elseif ($response_code == 404) {
				// Lead not found
				return new WP_Error(404, 'Lead not found.');
			} else {
				self::error('Lead update failed. Either the lead does not exist or you have not configured the Mautic Base URL and API Key settings.');
			}
		} else {
			$error_message = $request->get_error_message() ? $request->get_error_message() : 'WordPress encountered an error when attempting to update the lead id: ' . $lead_id;
			
			self::error($error_message);
		}
	}

	/**
	 * Get the product terms
	 *
	 * @param  object $items
	 * @param  string $taxonomy
	 * @return array  $product_terms
	 */
	private function get_product_terms($items, $taxonomy) {
		$product_terms = array();

		self::debug('Fetching ' . $taxonomy . ' terms');

		foreach ($items as $item_id => $item) {
			$product = $item->get_product();
			
			if ($product) {
				$terms = get_the_terms($product->get_id(), $taxonomy);

				if (!is_wp_error($terms) && $terms) {
					foreach ($terms as $term) {
						$product_terms[] = $term->name;

						self::debug('Added ' . $term->name . ' to tags.');
					}
				} else {
					self::error('Failed to fetch term for item ' . print_r($item_id, true));
				}
			} else {
				self::error('Product term not found for item ' . print_r($item_id, true));
			}
		}

		return $product_terms;
	}

	/**
	 * Get the product names
	 *
	 * @param  object $items
	 * @return array  $product_names
	 */
	private function get_product_names($items) {
		$product_names = array();

		self::debug('Fetching products');

		foreach ($items as $item_id => $item) {
			$product = $item->get_product();
			
			if ($product) {
        $product_names[] = $product->get_name();
        
        self::debug('Added ' . $product->get_name() . ' to tags.');
			} else {
				self::error('Product not found for item ' . print_r($item_id, true));
			}
		}

		return $product_names;
	}

	/**
	 * Build Ecommerce Connector line items from a WooCommerce order.
	 *
	 * @param  \WC_Order $order
	 * @param  int       $limit  Max items to include. 0 means no cap.
	 * @return array
	 */
	private function get_order_line_items($order, $limit = 0) {
		$items = array();

		if (!$order) {
			return $items;
		}

		foreach ($order->get_items() as $item) {
			if ($limit > 0 && count($items) >= $limit) {
				break;
			}

			$qty = (int) $item->get_quantity();

			if ($qty < 1) {
				continue;
			}

			$product = $item->get_product();

			if (!$product) {
				continue;
			}

			$item_id   = (string) $product->get_id();
			$item_name = $item->get_name();

			if ($item_id === '' || $item_name === '') {
				continue;
			}

			$line = array(
				'item_id'   => $item_id,
				'item_name' => $item_name,
				'qty'       => $qty,
			);

			$category = $this->get_product_first_category($product);

			if ($category) {
				$line['category'] = $category;
			}

			$items[] = $line;
		}

		return $items;
	}

	/**
	 * Get the first product category name. Variations use the parent product.
	 *
	 * @param  \WC_Product $product
	 * @return string
	 */
	private function get_product_first_category($product) {
		$product_id = $product->get_id();

		if ($product->is_type('variation')) {
			$product_id = $product->get_parent_id();
		}

		if (!$product_id) {
			return '';
		}

		$terms = get_the_terms($product_id, 'product_cat');

		if (is_wp_error($terms) || empty($terms)) {
			return '';
		}

		$first = reset($terms);

		return ($first && isset($first->name)) ? $first->name : '';
	}

	/**
	 * Queue a signed ecommerce webhook when an order is completed.
	 *
	 * @param  int $order_id
	 * @return void
	 */
	public function queue_ecommerce_order_webhook($order_id) {
		$order_id = (int) $order_id;

		if ($order_id < 1) {
			return;
		}

		if (!function_exists('wc_get_order')) {
			return;
		}

		if (!$this->order_tracking || $this->order_tracking_method !== 'webhook') {
			return;
		}

		if (!$this->webhook_secret) {
			self::error('Skipped ecommerce webhook for order #' . $order_id . ' because the webhook secret is not configured.');
			return;
		}

		$order = wc_get_order($order_id);

		if (!$order) {
			self::error('Failed to queue ecommerce webhook for order #' . $order_id . ' - Unable to retrieve order.');
			return;
		}

		if ($order->get_meta(self::META_WEBHOOK_SENT, true) || $order->get_meta(self::META_WEBHOOK_QUEUED, true)) {
			self::debug('Ecommerce webhook already queued or sent for order #' . $order_id);
			return;
		}

		$order->update_meta_data(self::META_WEBHOOK_QUEUED, true);
		$order->save();

		wp_schedule_single_event(time(), 'dogbytemarketing_sync_mautic_send_ecommerce_order', array($order_id));

		self::debug('Queued ecommerce webhook for order #' . $order_id);
	}

	/**
	 * Send a signed JSON order payload to Ecommerce Connector.
	 *
	 * @param  int $order_id
	 * @return void
	 */
	public function send_ecommerce_order_webhook($order_id) {
		$order_id = (int) $order_id;

		if ($order_id < 1) {
			return;
		}

		if (!function_exists('wc_get_order')) {
			return;
		}

		if (!$this->order_tracking || $this->order_tracking_method !== 'webhook') {
			return;
		}

		if (!$this->base_url || !$this->webhook_secret) {
			self::error('Skipped ecommerce webhook for order #' . $order_id . ' because the webhook is not configured.');
			return;
		}

		$order = wc_get_order($order_id);

		if (!$order) {
			self::error('Failed to send ecommerce webhook for order #' . $order_id . ' - Unable to retrieve order.');
			return;
		}

		if ($order->get_meta(self::META_WEBHOOK_SENT, true)) {
			self::debug('Ecommerce webhook already sent for order #' . $order_id);
			return;
		}

		$payload = array(
			'order_id'       => $order->get_order_number(),
			'order_total'    => $order->get_total(),
			'order_source'   => 'woocommerce',
			'order_currency' => $order->get_currency(),
		);

		$email = sanitize_email($order->get_billing_email());

		if ($email) {
			$payload['email'] = $email;
		}

		$clickthrough = $this->get_order_clickthrough($order);
		if ($clickthrough) {
			$payload['ct'] = $clickthrough;
		}

		if ($this->order_line_items) {
			$items = $this->get_order_line_items($order);

			if ($items) {
				$payload['items'] = $items;
			}
		}

		$json = wp_json_encode($payload);

		if (!$json) {
			self::error('Failed to encode ecommerce webhook payload for order #' . $order_id);
			return;
		}

		$signature = hash_hmac('sha256', $json, $this->webhook_secret);
		$url       = untrailingslashit($this->base_url) . '/ecommerce/order';

		self::debug('Sending ecommerce webhook for order #' . $order_id);

		$request = wp_remote_post(
			$url,
			array(
				'timeout' => self::WEBHOOK_TIMEOUT,
				'headers' => array(
					'Content-Type'                 => 'application/json',
					'X-Mautic-Ecommerce-Signature' => $signature,
				),
				'body'    => $json,
			)
		);

		if (is_wp_error($request)) {
			self::error('Ecommerce webhook failed for order #' . $order_id . ': ' . $request->get_error_message());
			return;
		}

		$response_code = wp_remote_retrieve_response_code($request);

		if ($response_code == 200 || $response_code == 201) {
			$order->update_meta_data(self::META_WEBHOOK_SENT, true);
			$order->save();

			self::debug('Ecommerce webhook sent for order #' . $order_id);
			return;
		}

		self::error('Ecommerce webhook failed for order #' . $order_id . ', response code: ' . $response_code);
	}

	/**
	 * Capture a Mautic clickthrough token from the URL into a first-party cookie.
	 *
	 * @return void
	 */
	public function capture_clickthrough() {
		if (is_admin() || wp_doing_cron() || !$this->is_webhook_clickthrough_enabled()) {
			return;
		}

		if (!isset($_GET['ct'])) {
			return;
		}

		$clickthrough = $this->sanitize_clickthrough(wp_unslash($_GET['ct']));

		if ($clickthrough === '') {
			return;
		}

		$this->set_clickthrough_cookie($clickthrough);
		self::debug('Captured Mautic clickthrough token');
	}

	/**
	 * Store a captured clickthrough token on a classic checkout order.
	 *
	 * @param  int $order_id
	 * @return void
	 */
	public function store_clickthrough_on_order($order_id) {
		$this->persist_clickthrough_to_order((int) $order_id);
	}

	/**
	 * Store a captured clickthrough token on a block checkout order.
	 *
	 * @param  mixed $order
	 * @return void
	 */
	public function store_clickthrough_on_store_api_order($order) {
		if (is_object($order) && method_exists($order, 'get_id')) {
			$this->persist_clickthrough_to_order((int) $order->get_id());
		}
	}

	/**
	 * Persist the clickthrough cookie onto the order for the signed webhook.
	 *
	 * @param  int $order_id
	 * @return void
	 */
	private function persist_clickthrough_to_order($order_id) {
		if ($order_id < 1 || !$this->is_webhook_clickthrough_enabled() || !function_exists('wc_get_order')) {
			return;
		}

		$order = wc_get_order($order_id);

		if (!$order) {
			return;
		}

		if ($order->get_meta(self::META_CLICKTHROUGH, true)) {
			return;
		}

		$clickthrough = $this->get_clickthrough_cookie();

		if ($clickthrough === '') {
			return;
		}

		$order->update_meta_data(self::META_CLICKTHROUGH, $clickthrough);
		$order->save();
	}

	/**
	 * Clickthrough token for an order: stored meta, then cookie as a fallback.
	 *
	 * @param  \WC_Order $order
	 * @return string
	 */
	private function get_order_clickthrough($order) {
		if (!$this->is_webhook_clickthrough_enabled() || !$order) {
			return '';
		}

		$stored = $this->sanitize_clickthrough($order->get_meta(self::META_CLICKTHROUGH, true));

		if ($stored !== '') {
			return $stored;
		}

		$from_cookie = $this->get_clickthrough_cookie();

		if ($from_cookie === '') {
			return '';
		}

		$order->update_meta_data(self::META_CLICKTHROUGH, $from_cookie);
		$order->save();

		return $from_cookie;
	}

	/**
	 * @return string
	 */
	private function get_clickthrough_cookie() {
		if (empty($_COOKIE[self::CLICKTHROUGH_COOKIE])) {
			return '';
		}

		return $this->sanitize_clickthrough(rawurldecode(wp_unslash($_COOKIE[self::CLICKTHROUGH_COOKIE])));
	}

	/**
	 * @param  string $clickthrough
	 * @return void
	 */
	private function set_clickthrough_cookie($clickthrough) {
		$path   = (defined('COOKIEPATH') && COOKIEPATH) ? COOKIEPATH : '/';
		$domain = (defined('COOKIE_DOMAIN') && COOKIE_DOMAIN) ? COOKIE_DOMAIN : '';
		$value  = rawurlencode($clickthrough);

		setcookie(
			self::CLICKTHROUGH_COOKIE,
			$value,
			array(
				'expires'  => time() + self::CLICKTHROUGH_COOKIE_TTL,
				'path'     => $path,
				'domain'   => $domain,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);

		$_COOKIE[self::CLICKTHROUGH_COOKIE] = $value;
	}

	/**
	 * @param  mixed $clickthrough
	 * @return string
	 */
	private function sanitize_clickthrough($clickthrough) {
		if (!is_string($clickthrough) || $clickthrough === '') {
			return '';
		}

		$clickthrough = trim($clickthrough);

		if (strlen($clickthrough) > self::CLICKTHROUGH_MAX_LENGTH) {
			return '';
		}

		if (!preg_match('/^[A-Za-z0-9+\/=%_-]+$/', $clickthrough)) {
			return '';
		}

		return $clickthrough;
	}

	/**
	 * Clickthrough collection applies only to signed webhook tracking.
	 *
	 * @return bool
	 */
	private function is_webhook_clickthrough_enabled() {
		return $this->order_tracking && $this->order_tracking_method === 'webhook' && $this->collect_clickthrough;
	}
	
	/**
	 * Add endpoint for webhooks to connect to
	 *
	 * @return void
	 */
	public function add_endpoints() {
		register_rest_route(
			'sync-mautic/v1',
			'/optinmonster/',
			array(
				'methods' => 'POST',
				'callback' => array($this, 'get_optinmonster_request'),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			'sync-mautic/v1',
			'/add-lead/',
			array(
				'methods' => 'POST',
				'callback' => array($this, 'get_lead_request'),
				'permission_callback' => '__return_true',
			)
		);

		// We need to handle compatibility for those previously on the beta
		if ($this->has_used_beta()) {
			register_rest_route(
				'mautic/v1',
				'/add-lead/',
				array(
					'methods' => 'POST',
					'callback' => array($this, 'get_optinmonster_request'),
					'permission_callback' => '__return_true',
				)
			);
			register_rest_route(
				'newsletter/v1',
				'/add-lead/',
				array(
					'methods' => 'POST',
					'callback' => array($this, 'get_lead_request'),
					'permission_callback' => '__return_true',
				)
			);
		}
	}
	
	/**
	 * Add checkout optin
	 *
	 * @return void
	 */
	public function add_checkout_optin() {
	?>
		<p class="form-row input-checkbox" id="newsletter_signup_field" data-priority="">
			<span class="woocommerce-input-wrapper">
				<label class="checkbox ">
				<?php if ($this->checkout_optin === 'checked') : ?>
					<input type="checkbox" class="input-checkbox" name="newsletter_signup" id="newsletter_signup" value="1" checked="checked">
				<?php elseif ($this->checkout_optin === 'unchecked') : ?>
					<input type="checkbox" class="input-checkbox" name="newsletter_signup" id="newsletter_signup" value="1">
				<?php endif; ?>
				Subscribe To Our Newsletter
				</label>
			</span>
		</p>
	<?php
	}
	
	/**
	 * Add lead from checkout optin if checked
	 *
	 * @param  mixed $order_id
	 * @return void
	 */
	public function add_checkout_lead($order_id) {
		$nonce = isset($_POST['woocommerce-process-checkout-nonce'])
			? sanitize_text_field(wp_unslash($_POST['woocommerce-process-checkout-nonce']))
			: '';

		if (!$nonce || !wp_verify_nonce($nonce, 'woocommerce-process_checkout')) {
			return;
		}

		$newsletter_signup = isset($_POST['newsletter_signup']) ? sanitize_text_field(wp_unslash($_POST['newsletter_signup'])) : '';

		if ($newsletter_signup) {
			self::debug('Adding lead from checkout for order #' . $order_id);

			$order = wc_get_order($order_id);
			$email = $order->get_billing_email();
			$tags  = array("WooCommerce");

			$order->update_meta_data('checkout_mautic_signup', true);

			$args = array(
				'method' => 'POST',
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->get_token(),
				),
				'body' => array(
					'email'      => sanitize_email($email),
					'lastActive' => current_time('Y-m-d H:i:s', true),
				)
			);
	
			if ($tags) {
				$args['body']['tags'] = array_map('sanitize_text_field', $tags);
			}
	
			$request = wp_remote_request($this->base_url . '/api/contacts/new', $args);

			if (!is_wp_error($request)) {
				$response_body = wp_remote_retrieve_body($request);

				// Decode the JSON response
				$response_data = json_decode($response_body, true);

				if ($response_data) {
					$contact = isset($response_data['contact']) ? $response_data['contact'] : '';
					
					if ($contact) {
						$id           = isset($contact['id']) ? $contact['id'] : '';
						$is_published = isset($contact['isPublished']) ? $contact['isPublished'] : '';

						if (!$id) {
							self::error('The response from Mautic was missing the id.');
						} else if (!$is_published) {
							self::error('The response from Mautic was indicated the lead was not published.');
						} else {
							self::debug('Added Lead #' . $id . ': ' . $email);
						}
					} else {
						self::error('The response from Mautic was malformed:');
						self::error(print_r($response_data, true));
					}
				} else {
					self::error('The response from Mautic was empty');
				}
			} else {
				$error_message = $request->get_error_message() ? $request->get_error_message() : 'WordPress encountered an error when attempting to add the lead: ' . $email;

				self::error($error_message);
			}

			$order->save();
		}
	}
	
	/**
	 * Get request data
	 *
	 * @param  mixed $request
	 * @return void
	 */
	public function get_optinmonster_request(WP_REST_Request $request) {
		$lead_data = $request->get_json_params();

		if (!$lead_data) {
			wp_send_json_error("No lead data found.", 400);
		}

		$this->optinmonster($lead_data);
	}
	
	/**
	 * Process OptinMonster lead data
	 *
	 * @param  mixed $lead_data
	 * @return void
	 */
	private function optinmonster($lead_data) {
		$email = isset($lead_data['lead']['email']) ? sanitize_email($lead_data['lead']['email']) : '';

		if (!$email) {
			wp_send_json_error("Invalid email.", 400);
		}

		if ($email) {
			$tags  = isset($lead_data['lead_options']['tags']) ? array_map('sanitize_text_field', $lead_data['lead_options']['tags']) : '';

			$this->add_lead($email, $tags);
		}
	}
	
	/**
	 * Get request custom form data
	 *
	 * @param  mixed $request
	 * @return void
	 */
	public function get_lead_request(WP_REST_Request $request) {
		$lead_data = $request->get_json_params();

		$this->custom_form($lead_data);
	}

	/**
	 * Process custom form lead data
	 *
	 * @param  mixed $lead_data
	 * @return void
	 */
	public function custom_form($lead_data) {
		$email    = isset($lead_data['email']) ? sanitize_email($lead_data['email']) : '';
		$get_tags = isset($lead_data['tag']) ? sanitize_text_field($lead_data['tag']) : '';
		$tags     = array_map('trim', explode(',', $get_tags));
		
		if (!$email) {
			wp_send_json_error("You must enter a valid email.", 400);

			return;
		}

		$this->add_lead($email, $tags);
	}
	
	/**
	 * Add lead into Mautic
	 *
	 * @param  string $email The email lead.
	 * @param  array  $tags  The lead tags. Optional.
	 * @return void
	 */
	private function add_lead($email, $tags = array()) {
		$args = array(
			'method' => 'POST',
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->get_token(),
			),
			'body' => array(
				'email'      => sanitize_email($email),
				'lastActive' => current_time('Y-m-d H:i:s', true),
			)
		);

		if ($tags) {
			$args['body']['tags'] = array_map('sanitize_text_field', $tags);
		}

		$request = wp_remote_request($this->base_url . '/api/contacts/new', $args);

		if (!is_wp_error($request)) {
			$response_code = wp_remote_retrieve_response_code($request);

			if ($response_code == 200 || $response_code == 201) {
				wp_send_json_success("Added lead", $response_code);
			} else {
				wp_send_json_error("Failed to add lead", $response_code);
			}
		} else {
			wp_send_json_error("Bad Request", 400);
		}
	}
	
	/**
	 * Shortcode to add Mautic form
	 *
	 * @return void
	 */
	public function mautic_form($atts) {
		$atts = shortcode_atts(
			array(
				'tag'         => '',
				'tags'        => '',
				'template'    => 'stacked',
				'placeholder' => 'Enter your email here...',
			),
			$atts,
			'mautic_form'
		);

		if ($atts['tags']) {
			$atts['tag'] = $atts['tags'];
		}

		ob_start();
		
		if ($atts['template'] === 'stacked') {
			$this->stacked_template($atts);
		} else {
			$this->column_template($atts);
		}

		$output = ob_get_clean();

    return $output;
	}
	
	/**
	 * Display stacked template
	 * 
	 * @param  array $attr
	 * @return void
	 */
	public function stacked_template($atts) {
		?>
		<div class="newsletter-signup stacked-template">
			<input type="email" name="email" class="required email newsletter-signup-email" placeholder="<?php echo esc_attr($atts['placeholder']); ?>" required="" value="">
			<input type="hidden" name="tag" class="tag newsletter-signup-tag" value="<?php echo esc_attr($atts['tag']); ?>">
			<input type="submit" name="subscribe" class="btn btn-primary align-self-end newsletter-signup-subscribe" value="Subscribe">
			<div class="newsletter-signup-responses">
				<p class="loading"></p>
				<p class="error"></p>
				<p class="success"></p>
			</div>
		</div>
		<?php
	}
	
	/**
	 * Display column template
	 * 
	 * @param  array $attr
	 * @return void
	 */
	public function column_template($atts) {
		?>
		<div class="row newsletter-signup column-template">
			<div class="col-12 col-sm-12 col-md-12 col-lg-12 col-xl-9">
				<input type="email" name="email" class="required email newsletter-signup-email" placeholder="<?php echo esc_attr($atts['placeholder']); ?>" required="" value="">
				<input type="hidden" name="tag" class="tag newsletter-signup-tag" value="<?php echo esc_attr($atts['tag']); ?>">
				<div class="newsletter-signup-responses">
					<p class="loading"></p>
					<p class="error"></p>
					<p class="success"></p>
				</div>
			</div>
			<div class="col-12 col-sm-12 col-md-12 col-lg-12 col-xl-3 pt-3 pt-sm-3 pt-md-3 pt-lg-3 pt-xl-0">
				<input type="submit" name="subscribe" class="btn btn-primary align-self-end newsletter-signup-subscribe" value="Subscribe">
			</div>
		</div>
		<?php
	}
	
	/**
	 * Add admin menu to backend
	 *
	 * @return void
	 */
	public function add_admin_menu() {
		add_submenu_page('options-general.php', 'Sync Mautic Settings', 'Sync Mautic', 'manage_options', 'sync-mautic', array($this, 'options_page'));
	}

	/**
	 * Enqueue admin styles on the settings page.
	 *
	 * @param  string $hook
	 * @return void
	 */
	public function enqueue_admin_scripts($hook) {
		if ($hook !== 'settings_page_sync-mautic') {
			return;
		}

		$style_path = plugin_dir_path(__FILE__) . 'css/admin.css';

		wp_enqueue_style(
			'sync-mautic-admin',
			plugins_url('/css/admin.css', __FILE__),
			array(),
			file_exists($style_path) ? filemtime($style_path) : self::VERSION
		);
	}
	
	/**
	 * Initialize Settings
	 *
	 * @return void
	 */
	public function settings_init() {
		register_setting(
			'dogbytemarketing_sync_mautic',
			'dogbytemarketing_sync_mautic_settings', 
			array($this, 'sanitize')
		);

		add_settings_section(
			'dogbytemarketing_sync_mautic_api_section',
			__('API Configuration', 'sync-mautic'),
			array($this, 'api_howto_render'),
			'dogbytemarketing_sync_mautic'
		);

		add_settings_section(
			'dogbytemarketing_sync_mautic_section',
			__('Other Configuration', 'sync-mautic'),
			'',
			'dogbytemarketing_sync_mautic'
		);

		add_settings_field(
			'base_url',
			__('Mautic Base URL', 'sync-mautic'),
			array($this, 'base_url_render'),
			'dogbytemarketing_sync_mautic',
			'dogbytemarketing_sync_mautic_api_section'
		);

		add_settings_field(
			'client_id',
			__('Client ID', 'sync-mautic'),
			array($this, 'client_id_render'),
			'dogbytemarketing_sync_mautic',
			'dogbytemarketing_sync_mautic_api_section'
		);

		add_settings_field(
			'client_secret',
			__('Client Secret', 'sync-mautic'),
			array($this, 'client_secret_render'),
			'dogbytemarketing_sync_mautic',
			'dogbytemarketing_sync_mautic_api_section'
		);

		if (class_exists('WooCommerce')) {
			add_settings_field(
				'order_tagging',
				__('Order Tagging', 'sync-mautic'),
				array($this, 'order_tagging_render'),
				'dogbytemarketing_sync_mautic',
				'dogbytemarketing_sync_mautic_section'
			);

		add_settings_field(
			'checkout_optin',
			__('Checkout Optin', 'sync-mautic'),
			array($this, 'checkout_optin_render'),
			'dogbytemarketing_sync_mautic',
			'dogbytemarketing_sync_mautic_section'
		);

		add_settings_field(
			'order_tracking',
			__('WooCommerce Order Tracking', 'sync-mautic'),
			array($this, 'order_tracking_render'),
			'dogbytemarketing_sync_mautic',
			'dogbytemarketing_sync_mautic_section'
		);

		add_settings_field(
			'resync_past_orders',
			__('Resync Past Orders', 'sync-mautic'),
			array($this, 'resync_past_orders_render'),
			'dogbytemarketing_sync_mautic',
			'dogbytemarketing_sync_mautic_section'
		);

			add_settings_field(
				'debug_mode',
				__('Debug Mode', 'sync-mautic'),
				array($this, 'debug_mode_render'),
				'dogbytemarketing_sync_mautic',
				'dogbytemarketing_sync_mautic_section'
			);
		}
	}

	/**
	 * Render Base URL field
	 *
	 * @return void
	 */
	public function base_url_render() {
	?>
		<input type="text" class="sync-mautic-input" name="dogbytemarketing_sync_mautic_settings[base_url]" placeholder="https://mautic.domain.com" value="<?php echo esc_attr($this->base_url); ?>">
		<p class="description">The base URL of your Mautic instance. Example: https://mautic.domain.com</p>
	<?php
	}

	
	public function api_howto_render() {
		echo '<div class="sync-mautic-callout">';
		echo '<p style="margin:0;">When you set up API credentials in Mautic, your Redirect URI will be <code>' . esc_html(untrailingslashit(home_url())) . '</code></p>';
		echo '</div>';
	}
	
	/**
	 * Render API Key Field
	 *
	 * @return void
	 */
	public function client_id_render() {
	?>
		<input type="text" class="sync-mautic-input" name="dogbytemarketing_sync_mautic_settings[client_id]" placeholder="XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX" value="<?php echo esc_attr($this->client_id); ?>">
		<p class="description">The Public Key or Client ID from Mautic Settings → Integrations → API Credentials. Older Mautic versions are under Settings → API Credentials.</p>
	<?php
	}
	
	/**
	 * Render API Key Field
	 *
	 * @return void
	 */
	public function client_secret_render() {
	?>
		<input type="password" class="sync-mautic-input" name="dogbytemarketing_sync_mautic_settings[client_secret]" placeholder="XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX" value="<?php echo esc_attr($this->client_secret); ?>" autocomplete="new-password">
		<p class="description">The Secret Key or Client Secret from Mautic Settings → API Credentials. Older Mautic versions are under Settings → API Credentials.</p>
	<?php
	}
	
	/**
	 * Render Order Tagging Field
	 *
	 * @return void
	 */
	public function order_tagging_render() {
		$order_tagging = isset($this->settings['order_tagging']) ? $this->settings['order_tagging'] : array();
    ?>
    <div class="sync-mautic-choices">
		<label class="sync-mautic-choice" for="sync-mautic-tag-categories">
			<input type="checkbox" id="sync-mautic-tag-categories" name="dogbytemarketing_sync_mautic_settings[order_tagging][]" value="categories"<?php echo in_array('categories', $order_tagging) ? ' checked' : ''; ?>>
			Categories
		</label>
		<label class="sync-mautic-choice" for="sync-mautic-tag-brands">
			<input type="checkbox" id="sync-mautic-tag-brands" name="dogbytemarketing_sync_mautic_settings[order_tagging][]" value="brands"<?php echo in_array('brands', $order_tagging) ? ' checked' : ''; ?>>
			Brands
		</label>
		<label class="sync-mautic-choice" for="sync-mautic-tag-products">
			<input type="checkbox" id="sync-mautic-tag-products" name="dogbytemarketing_sync_mautic_settings[order_tagging][]" value="products"<?php echo in_array('products', $order_tagging) ? ' checked' : ''; ?>>
			Products
		</label>
    </div>
		<p class="description">Adds tags to existing contacts for the categories, brands, and/or products they purchased on completed orders. This will sync past orders.</p>
		<p class="sync-mautic-notice">This ties Mautic IDs to WordPress user IDs. Make sure you are in compliance with data protection regulations before enabling.</p>
		<?php
	}
	
	/**
	 * Render Checkout Optin Field
	 *
	 * @return void
	 */
	public function checkout_optin_render() {
		$checkout_optin = isset($this->settings['checkout_optin']) ? $this->settings['checkout_optin'] : 'disabled';
    ?>
    <select class="sync-mautic-select" name="dogbytemarketing_sync_mautic_settings[checkout_optin]" id="checkout_checkbox">
			<option value="disabled" <?php selected($checkout_optin, 'disabled'); ?>>Disabled</option>
			<option value="unchecked" <?php selected($checkout_optin, 'unchecked'); ?>>Unchecked</option>
			<option value="checked" <?php selected($checkout_optin, 'checked'); ?>>Checked</option>
    </select>
		<p class="description">Adds a newsletter checkbox at WooCommerce checkout.</p>
		<p class="sync-mautic-notice">Use Unchecked for data protection regulation compliance.</p>
		<?php
	}
	
	/**
	 * Render WooCommerce Order Tracking Field
	 *
	 * @return void
	 */
	public function order_tracking_render() {
		$order_tracking        = isset($this->settings['order_tracking']) ? $this->settings['order_tracking'] : false;
		$order_tracking_method = isset($this->settings['order_tracking_method']) ? $this->settings['order_tracking_method'] : 'page_hits';
		$order_line_items      = isset($this->settings['order_line_items']) ? $this->settings['order_line_items'] : false;
		$collect_clickthrough  = isset($this->settings['collect_clickthrough']) ? $this->settings['collect_clickthrough'] : false;
		$webhook_secret        = isset($this->settings['webhook_secret']) ? $this->settings['webhook_secret'] : '';

		if ($order_tracking_method !== 'webhook') {
			$order_tracking_method = 'page_hits';
		}
	?>
		<label class="sync-mautic-switch" for="order_tracking">
			<input type="checkbox" name="dogbytemarketing_sync_mautic_settings[order_tracking]" id="order_tracking" value="on" <?php checked(1, $order_tracking, true); ?> />
			Enable order tracking
		</label>
		<p class="description">Track WooCommerce revenue related to emails using our <a href="https://www.dogbytemarketing.com/shop/ecommerce-connector-for-mautic/" target="_blank" rel="noopener noreferrer">eCommerce Connector</a>.</p>
		<p class="sync-mautic-notice">Update your privacy and cookie policies along with cookie acceptance banners so they disclose that order, purchase, and revenue information may be sent to and stored in Mautic for marketing analytics, email attribution, segmentation, and contact profiling. Tracking only applies after you enable it.</p>

		<div id="sync-mautic-order-tracking-options" class="sync-mautic-panel">
			<p class="sync-mautic-label">Tracking method</p>
			<div class="sync-mautic-methods">
				<label class="sync-mautic-method" for="order_tracking_method_page_hits">
					<span class="sync-mautic-method__title">
						<input type="radio" name="dogbytemarketing_sync_mautic_settings[order_tracking_method]" id="order_tracking_method_page_hits" value="page_hits" <?php checked($order_tracking_method, 'page_hits'); ?> />
						Page hits (mtc)
					</span>
					<span class="sync-mautic-method__body">Adds order fields to the WP Mautic pageview on the confirmation page. Requires WP Mautic. Uses cookies. Mautic already attributes the email from that page hit. Line items are capped at 20.</span>
				</label>
				<label class="sync-mautic-method" for="order_tracking_method_webhook">
					<span class="sync-mautic-method__title">
						<input type="radio" name="dogbytemarketing_sync_mautic_settings[order_tracking_method]" id="order_tracking_method_webhook" value="webhook" <?php checked($order_tracking_method, 'webhook'); ?> />
						Signed webhook
					</span>
					<span class="sync-mautic-method__body">Server-to-server POST when the order is completed. Does not set cookies, but still processes personal data. Requires eCommerce Connector 1.3.0. Enable Collect clickthrough to attribute revenue to the clicked email.</span>
				</label>
			</div>

			<div class="sync-mautic-option">
				<label for="order_line_items">
					<input type="checkbox" name="dogbytemarketing_sync_mautic_settings[order_line_items]" id="order_line_items" value="on" <?php checked(1, $order_line_items, true); ?> />
					Send line items
				</label>
				<p class="description">Include product ID, name, quantity, and first category. Requires eCommerce Connector 1.3.0. Defaults to off. Page hits send the first 20 line items; signed webhook sends every line item.</p>
			</div>

			<div id="sync-mautic-webhook-options">
				<div class="sync-mautic-option">
					<label for="collect_clickthrough">
						<input type="checkbox" name="dogbytemarketing_sync_mautic_settings[collect_clickthrough]" id="collect_clickthrough" value="on" <?php checked(1, $collect_clickthrough, true); ?> />
						Collect clickthrough
					</label>
					<p class="description">Signed webhook only. Stores the Mautic <code>ct</code> token in a first-party cookie for 30 days and sends it with the webhook so revenue can be attributed to that email. Defaults to off. Update your privacy and cookie policies along with cookie acceptance banners before enabling.</p>
				</div>
				<p class="sync-mautic-label"><label for="webhook_secret">Webhook secret</label></p>
				<input type="password" class="sync-mautic-input" name="dogbytemarketing_sync_mautic_settings[webhook_secret]" id="webhook_secret" value="<?php echo esc_attr($webhook_secret); ?>" autocomplete="new-password" />
				<p class="description">Required for signed webhook. Paste the webhook secret from Mautic -> Plugins -> Ecommerce Connector -> Webhook. This is not your OAuth Client Secret.</p>
			</div>
		</div>
		<script>
		(function () {
			function syncMauticToggleOrderTracking() {
				var enabled = document.getElementById('order_tracking');
				var webhook = document.getElementById('order_tracking_method_webhook');
				var options = document.getElementById('sync-mautic-order-tracking-options');
				var secretRow = document.getElementById('sync-mautic-webhook-options');
				if (!enabled || !webhook || !options || !secretRow) {
					return;
				}
				options.classList.toggle('is-disabled', !enabled.checked);
				secretRow.style.display = webhook.checked ? 'block' : 'none';
			}
			document.getElementById('order_tracking') && document.getElementById('order_tracking').addEventListener('change', syncMauticToggleOrderTracking);
			document.getElementById('order_tracking_method_page_hits') && document.getElementById('order_tracking_method_page_hits').addEventListener('change', syncMauticToggleOrderTracking);
			document.getElementById('order_tracking_method_webhook') && document.getElementById('order_tracking_method_webhook').addEventListener('change', syncMauticToggleOrderTracking);
			syncMauticToggleOrderTracking();
		})();
		</script>
	<?php
	}
	
	public function resync_past_orders_render() {
    $resync_past_orders = isset($this->settings['resync_past_orders']) ? $this->settings['resync_past_orders'] : '';
	?>
    <label class="sync-mautic-switch" for="resync_past_orders">
		<input type="checkbox" name="dogbytemarketing_sync_mautic_settings[resync_past_orders]" id="resync_past_orders" <?php checked(1, $resync_past_orders, true); ?> />
		Resync past orders
	</label>
		<p class="description">Check this if you change order tagging and want past completed orders tagged again. This only re-applies tags. It does not send past orders to Ecommerce Connector tracking.</p>
	<?php
	}
	
	/**
	 * Render Debug Field
	 *
	 * @return void
	 */
	public function debug_mode_render() {
    $debug_mode = isset($this->settings['debug_mode']) ? $this->settings['debug_mode'] : '';
	?>
    <label class="sync-mautic-switch" for="debug_mode">
		<input type="checkbox" name="dogbytemarketing_sync_mautic_settings[debug_mode]" id="debug_mode" <?php checked(1, $debug_mode, true); ?> />
		Enable debug logging
	</label>
	<?php
	}
	
	/**
	 * Render options page
	 *
	 * @return void
	 */
	public function options_page() {
	?>
		<div class="wrap sync-mautic-settings">
			<form action="options.php" method="post">
				<h1>Sync Mautic Settings</h1>

				<?php
				settings_fields('dogbytemarketing_sync_mautic');
				do_settings_sections('dogbytemarketing_sync_mautic');
				?>
				<div class="sync-mautic-support">
					If you need assistance with your email marketing efforts, we at <a href="https://www.dogbytemarketing.com" target="_blank" rel="noopener noreferrer">Dog Byte Marketing</a> are here to help. Call <a href="tel:4237248922">(423) 724-8922</a>. We're based in Tennessee in the US.
				</div>
				<?php submit_button(); ?>
			</form>
		</div>
	<?php
	}
  
  /**
   * Sanitize Options
   *
   * @param  array $input Array of option inputs
   * @return array $sanitary_values Array of sanitized options
   */
  public function sanitize($input) {
	$sanitary_values = array();

	if (isset($input['client_id']) && $input['client_id']) {
		$sanitary_values['client_id'] = sanitize_text_field($input['client_id']);
	}

	if (isset($input['client_secret']) && $input['client_secret']) {
		$sanitary_values['client_secret'] = sanitize_text_field($input['client_secret']);
	}

	if (isset($input['base_url']) && $input['base_url']) {
		if (filter_var($input['base_url'], FILTER_VALIDATE_URL)) {
			$sanitary_values['base_url'] = esc_url_raw($input['base_url']);
		} else {
			add_settings_error('base_url', 'base_url', "Invalid URL");
			
			// Revert back to old base url
			$sanitary_values['base_url'] = isset($this->settings['base_url']) ? $this->settings['base_url'] : '';
		}
	}

	if (isset($input['checkout_optin']) && $input['checkout_optin']) {
		$sanitary_values['checkout_optin'] = sanitize_text_field($input['checkout_optin']);
	} else {
		$sanitary_values['checkout_optin'] = 'disabled';
	}

	if (isset($input['order_tagging']) && $input['order_tagging']) {
		$sanitary_values['order_tagging'] = array_map('sanitize_text_field', $input['order_tagging']);
	} else {
		$sanitary_values['order_tagging'] = array();
	}

	if ($sanitary_values['order_tagging']) {
		if (!get_option('dogbytemarketing_sync_mautic_past_orders_complete')) {
			if (!wp_next_scheduled('dogbytemarketing_sync_mautic_past_orders')) {
				wp_schedule_event(time(), 'every_minute', 'dogbytemarketing_sync_mautic_past_orders');
			}
		}
	}

    if (isset($input['resync_past_orders']) && $input['resync_past_orders']) {
      if ($input['resync_past_orders'] === 'on') {
        delete_option('dogbytemarketing_sync_mautic_past_orders_complete');

				if (!wp_next_scheduled('dogbytemarketing_sync_mautic_past_orders')) {
					wp_schedule_event(time(), 'every_minute', 'dogbytemarketing_sync_mautic_past_orders');
				}
      }
    }

	if (isset($input['order_tracking']) && $input['order_tracking']) {
      $sanitary_values['order_tracking'] = $input['order_tracking'] === 'on' ? true : false;
    } else {
      $sanitary_values['order_tracking'] = false;
    }

	$order_tracking_method = isset($input['order_tracking_method']) ? sanitize_text_field($input['order_tracking_method']) : 'page_hits';
	if ($order_tracking_method !== 'webhook') {
		$order_tracking_method = 'page_hits';
	}
	$sanitary_values['order_tracking_method'] = $order_tracking_method;

	if (isset($input['order_line_items']) && $input['order_line_items']) {
		$sanitary_values['order_line_items'] = $input['order_line_items'] === 'on' ? true : false;
	} else {
		$sanitary_values['order_line_items'] = false;
	}

	if (isset($input['collect_clickthrough']) && $input['collect_clickthrough']) {
		$sanitary_values['collect_clickthrough'] = $input['collect_clickthrough'] === 'on' ? true : false;
	} else {
		$sanitary_values['collect_clickthrough'] = false;
	}

	$sanitary_values['webhook_secret'] = isset($input['webhook_secret']) ? sanitize_text_field($input['webhook_secret']) : '';

	if ($sanitary_values['order_tracking'] && $sanitary_values['order_tracking_method'] === 'webhook' && $sanitary_values['webhook_secret'] === '') {
		add_settings_error(
			'dogbytemarketing_sync_mautic',
			'missing_webhook_secret',
			'Webhook secret is required when Signed webhook is selected. Copy it from Mautic → Plugins → Ecommerce Connector → Webhook.',
			'error'
		);
	}

		if (isset($input['debug_mode']) && $input['debug_mode']) {
      $sanitary_values['debug_mode'] = $input['debug_mode'] === 'on' ? true : false;
    } else {
      $sanitary_values['debug_mode'] = false;
    }

	// Clear cache on save
	delete_transient('dogbytemarketing_mautic_access_token');

	
	if (!$this->is_valid_token($sanitary_values)) {
		add_settings_error(
			'dogbytemarketing_sync_mautic',
			'invalid_token_error',
			'Failed to connect to Mautic, please check your Base URL, Client ID, and Client Secret.',
			'error'
		);
	}

    return $sanitary_values;
  }

	/**
   * Display the notice on the admin backend
   *
   * @return void
   */
  public function display_notice() {
    ?>
    <div class="notice notice-<?php echo esc_attr($this->admin_notice['type']); ?>">
      <p>
				<?php
				echo wp_kses(
					$this->admin_notice['message'],
						array(
							'a' => array(
								'href' => array(),
								'title' => array(),
							),
						)
					);
				?>
			</p>
    </div>
    <?php
  }

	/**
	 * Show a one-time notice about 1.1.0 ecommerce tracking options.
	 *
	 * @return void
	 */
	public function maybe_show_1_1_0_notice() {
		if (!current_user_can('manage_options')) {
			return;
		}

		if (get_option('dogbytemarketing_sync_mautic_dismissed_notice_1_1_0')) {
			return;
		}

		add_action('admin_notices', array($this, 'display_1_1_0_notice'));
	}

	/**
	 * Display the 1.1.0 update notice.
	 *
	 * @return void
	 */
	public function display_1_1_0_notice() {
		$dismiss_url = wp_nonce_url(
			admin_url('admin-post.php?action=dogbytemarketing_dismiss_sync_mautic_1_1_0'),
			'dogbytemarketing_dismiss_sync_mautic_1_1_0'
		);
		$settings_url = admin_url('options-general.php?page=sync-mautic');
		?>
		<div class="notice notice-info">
			<p>
				<strong>Sync Mautic 1.1.0:</strong>
				WooCommerce order tracking now has optional line items and a signed webhook method. Both default to off.
				Line items and signed webhook require <strong>eCommerce Connector 1.3.0</strong>.
				Collect clickthrough is optional for signed webhook only.
				Update your privacy and cookie policies along with cookie acceptance banners so they disclose that order, purchase, and revenue information may be sent to and stored in Mautic for marketing analytics, email attribution, segmentation, and contact profiling.
			</p>
			<p>
				<a href="<?php echo esc_url($settings_url); ?>">Review settings</a>
				|
				<a href="<?php echo esc_url($dismiss_url); ?>">Dismiss</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Dismiss the 1.1.0 update notice.
	 *
	 * @return void
	 */
	public function dismiss_1_1_0_notice() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to dismiss this notice.', 'sync-mautic'));
		}

		check_admin_referer('dogbytemarketing_dismiss_sync_mautic_1_1_0');

		update_option('dogbytemarketing_sync_mautic_dismissed_notice_1_1_0', true);

		$redirect = wp_get_referer();
		if (!$redirect) {
			$redirect = admin_url('options-general.php?page=sync-mautic');
		}

		wp_safe_redirect($redirect);
		exit;
	}
	
	/**
	 * Gets the Mautic access token.
	 *
	 * @return string $token
	 */
	private function get_token() {
		$token = get_transient('dogbytemarketing_mautic_access_token');

		if (!$token) {
			$args = array(
				'method' => 'POST',
				'body' => array(
					'client_id'     => $this->client_id,
					'client_secret' => $this->client_secret,
					'grant_type'    => 'client_credentials',
				)
			);
	
			$request = wp_remote_request($this->base_url . '/oauth/v2/token', $args);

			if (!is_wp_error($request)) {
				$response_code = wp_remote_retrieve_response_code($request);

				if ($response_code == 200 || $response_code == 201) {
					$body = isset($request['body']) ? json_decode($request['body'], true) : '';

					if ($body) {
						if (isset($body['access_token']) && isset($body['expires_in'])) {
							$token      = $body['access_token'] ? sanitize_text_field($body['access_token']) : '';
							$expires_in = is_numeric($body['expires_in']) ? (int) ($body['expires_in']) : '';

							set_transient('dogbytemarketing_mautic_access_token', $token, $expires_in);
						} else {
							self::error('Access token or expiration not provided from "/oauth/v2/token" call.');
						}
					} else {
						self::error('Failed to get Mautic token: Malformed body.');
					}
				} else {
					self::error('Attempt to get Mautic token failed, response code: ' . $response_code);
				}
			} else {
				$error_message = $request->get_error_message() ? $request->get_error_message() : 'WordPress encountered an error attempting to fetch Mautic Token.';

				self::error($error_message);
			}
		}

		return $token;
	}
	
	/**
	 * Check if the token is valid
	 *
	 * @return bool $is_valid_token Is the token valid
	 */
	private function is_valid_token($settings) {
		$this->base_url      = $settings['base_url'];
		$this->client_id     = $settings['client_id'];
		$this->client_secret = $settings['client_secret'];

		$is_valid_token = is_string($this->get_token()) ? true : false;

		return $is_valid_token;
	}

	/**
   * Enqueue the admin notice
   *
   * @param  string $message The message being displayed in admin
   * @param  string $type Optional. The type of message displayed. Default error.
   * @return void
   */
  private function add_notice($message, $type = 'error') {
    $this->admin_notice = array(
      'message' => $message,
      'type'   => $type
		);

    add_action('admin_notices', array($this, 'display_notice'));
  }
  
  /**
   * Deactivation
   *
   * @return void
   */
  public static function deactivation() {
    wp_clear_scheduled_hook('dogbytemarketing_sync_mautic_past_orders');
    wp_unschedule_hook('dogbytemarketing_sync_mautic_send_ecommerce_order');
  }
	
	/**
	 * Check if previously used beta
	 *
	 * @return mixed $has_used_beta
	 */
	private function has_used_beta() {
		$has_used_beta = get_option('mautic_sync_settings');

		return $has_used_beta;
	}

	/**
	 * Logging
	 *
	 * @param mixed  $log
	 * @param string $type
	 * @return void
	 */
	private static function log($log, $type = 'debug') {
		$log_dir = self::setup_log_directory();

		// Sanitize type for filename safety
		$type = sanitize_key($type);

		$log_file = $log_dir . 'debug.log';

		// Normalize message
		if (is_array($log) || is_object($log)) {
			$log = print_r($log, true);
		}

		// ISO-8601 timestamp with timezone
		$timestamp = gmdate('c');

		$entry = sprintf(
			'[%s] %s: %s' . PHP_EOL,
			$timestamp,
			ucfirst($type),
			$log
		);

		file_put_contents($log_file, $entry, FILE_APPEND | LOCK_EX);
	}
	
	/**
	 * Debug logging
	 *
	 * @param  mixed  $log
	 * @param  string $context
	 * @return void
	 */
	private static function debug($log, $context = 'sync-mautic') {
		$settings   = get_option('dogbytemarketing_sync_mautic_settings');
		$debug_mode = isset($settings['debug_mode']) ? $settings['debug_mode'] : false;

		// Don't log debug messages if it's not enabled. Errors are still logged.
		if ($debug_mode) {
			self::log($log, 'debug', $context);
		}
	}
	
	/**
	 * Error logging
	 *
	 * @param  mixed  $log
	 * @param  string $context
	 * @return void
	 */
	private static function error($log, $context = 'sync-mautic') {
		self::log($log, 'error', $context);
	}

	/**
	 * Ensure log directory and security files exist
	 *
	 * @return string Absolute log directory path
	 */
	private static function setup_log_directory() {
		$upload_dir = wp_upload_dir();
		$log_dir    = trailingslashit($upload_dir['basedir']) . 'sync-mautic-logs/';

		// Create directory if needed
		if (!file_exists($log_dir)) {
			wp_mkdir_p($log_dir);
		}

		// .htaccess (deny all)
		$htaccess_file = $log_dir . '.htaccess';

		if (!file_exists($htaccess_file)) {
			file_put_contents($htaccess_file, "Deny from all\n");
		}

		// index.php (silence is golden)
		$index_file = $log_dir . 'index.php';
		if (!file_exists($index_file)) {
			file_put_contents($index_file, "<?php\n// Silence is golden.\n");
		}

		return $log_dir;
	}

}

$sync_mautic = new Sync_Mautic;
$sync_mautic->init();
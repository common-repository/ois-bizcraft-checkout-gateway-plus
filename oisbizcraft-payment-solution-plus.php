<?php
/**
 * Plugin Name:  OIS-Bizcraft Checkout Gateway Plus
 * Description: Accept all major credit and debit cards.
 * Author: OIS-Bizcraft team
 * Version: 1.2.1
 * Author: OIS-Bizcraft
 * Author URI: https://oisbizcraft.com
 * Copyright: © 2021 WooCommerce / OIS-Bizcraft.
 * License: GNU General Public License v2.0
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * WC tested up to: 6.1
 * WC requires at least: 4.7
 */

defined('ABSPATH') or exit;


// Make sure WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}


/**
 * Add the gateway to WC Available Gateways
 *
 * @param array $gateways all available WC gateways
 * @return array $gateways all WC gateways + OIS-Bizcraft gateway
 * @since 1.1.4
 */
function wc_oisbizcraft_plus_add_to_gateways($gateways)
{
    $gateways[] = 'WC_Gateway_Oisbizcraft_Plus';
    return $gateways;
}

add_filter('woocommerce_payment_gateways', 'wc_oisbizcraft_plus_add_to_gateways');


/**
 * Adds plugin page links
 *
 * @param array $links all plugin links
 * @return array $links all plugin links + our custom links (i.e., "Settings")
 * @since 1.1.4
 */
function wc_oisbizcraft_gateway_plus_plugin_links($links)
{

    $plugin_links = array(
        '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=oisbizcraft_gateway_plus') . '">' . __('Configure', 'wc-gateway-oisbizcraft-plus') . '</a>'
    );

    return array_merge($plugin_links, $links);
}

add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'wc_oisbizcraft_gateway_plus_plugin_links');

add_action('plugins_loaded', 'wc_oisbizcraft_gateway_plus_init', 11);

function wc_oisbizcraft_gateway_plus_init()
{

    class WC_Gateway_Oisbizcraft_Plus extends WC_Payment_Gateway
    {

        /**
         * Constructor for the gateway.
         */
        public function __construct()
        {

            $this->id = 'oisbizcraft_gateway_plus';
            $this->icon = apply_filters('woocommerce_oisbizcraft_icon',
                plugins_url('/assets/logo-compact.jpg', __FILE__));
            $this->has_fields = false;
            $this->method_title = __('Oisbizcraft', 'wc-gateway-oisbizcraft-plus');
            $this->method_description = __('Allows OIS-Bizcraft payments plus.', 'wc-gateway-oisbizcraft-plus');

            // Load the settings.
            $this->init_form_fields();
            $this->init_settings();

            // Define user set variables
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->cust_code = $this->get_option('cust_code');
            $this->merchant_return_url = $this->get_option('merchant_return_url');
            $this->merchant_outlet_id = $this->get_option('merchant_outlet_id');
            $this->terminal_id = $this->get_option('terminal_id');
            $this->secret_key = $this->get_option('secret_key');
            $this->test_mode = $this->get_option('test_mode');
            $this->instructions = $this->get_option('instructions', $this->description);
            $this->completed_order_status = $this->get_option('completed_order_status', $this->description);

            $this->portalURL = 'https://'.$this->test_mode.'.oisbizcraft.com';
            $this->portalOrderID = null;
            // Actions
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

            add_filter( 'woocommerce_thankyou_order_received_text', array( $this, 'order_received_text' ), 10, 2 );
            add_filter( 'woocommerce_thankyou_title', array( $this, 'order_received_text' ), 10, 2 );
            // API callback for OIS-Bizcraft to update the transaction status
            add_action('woocommerce_api_wc_gateway_oisbizcraft_plus', array($this, 'check_callback'));
        }
        /**
         * Custom OIS-bizcraft order received text.
         *
         * @since 0.4.0
         * @param string   $text Default text.
         * @param WC_Order $order Order data.
         * @return string
         */
        public function order_received_text( $text, $order ) {
            if ( $order && $this->id === $order->get_payment_method() ) {
                if($order->status == 'pending'){
                    return esc_html__( 'Your payment is still on processing', 'wc-gateway-oisbizcraft-plus' );
                }
                if($order->status == 'cancelled'){
                    return esc_html__( 'Your payment is cancelled', 'wc-gateway-oisbizcraft-plus' );
                }
                if($order->status == 'completed') {
                    return esc_html__('Thank you for your payment. Your transaction has been completed, and a receipt for your purchase has been emailed to you.', 'wc-gateway-oisbizcraft-plus');
                }
                if($order->status == 'failed'){
                    return esc_html__( '<div style="color: red!important">Unfortunately your order cannot be processed as the originating bank/merchant has declined your transaction. Please attempt your purchase again.</div>', 'wc-gateway-oisbizcraft-plus' );
                }
            }

            return $text;
        }


        /* Get the plugin response URL */
        public function wc_ois_response_url($order_Id){
            $order = new WC_Order($order_Id);

            $redirect_url = $this->get_return_url($order);

//            if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=')) {
//                $redirect_url = add_query_arg('wc-api', get_class($this), $redirect_url);
//            }

            return $redirect_url;
        }


        /* Get the plugin response URL */
        public function wc_ois_cancel_response_url($order_Id){
            $order = new WC_Order($order_Id);
            $redirect_url = esc_url_raw( $order->get_cancel_order_url_raw());
            if (version_compare(WOOCOMMERCE_VERSION, '2.1.0', '>=')) { // For WC 2.1.0
                $redirect_url = $order->get_cancel_order_url_raw();
            }

            return $redirect_url;
        }

        function check_callback()
        {
            try {
                header('Content-Type: application/json;');
                $data = json_decode(file_get_contents('php://input'), true);

                // Add validation for required params hash_key, order_id and status
                if ($data['order_id'] == '' || $data['status'] == '' || $data['hash_key'] == '') {
                    return wp_send_json(['success' => false, 'message' => 'either order id, status or hash key is empty ss']);
                }

                // Authentication using HMAC
                $validateKey = $this->generateValidateKey($data);
                if ($validateKey != $data['hash_key']) {
                    wp_send_json(['message' => 'Transaction not match'], 401);
                }
                // Extract Woocommerce order by from portal transaction code
                $explode = explode('-', $data['order_id']);
                $orderId = $explode[3];

                // get Woocomerce order by order ID
                $order = wc_get_order($orderId);


                // Add validation for required params hash_key, order_id and status
                if (!$order) {
                    wp_send_json(['message' => 'Order ID is not available'], 404);
                }

                if($order->get_status() == 'processing' || $order->get_status() == 'completed'){
                    wp_send_json(['message' => 'Already update, current status is '. $order->get_status()]);
                    return false;
                }

                if(isset($data['timelines_note'])){
                    $order->add_order_note($data['timelines_note'],true);
                }
                if ($data['status'] == 'success') {
                    $status = $this->completed_order_status == 'default' ? 'wc-completed' : 'wc-processing';
                    $order->reduce_order_stock();
                    WC()->cart->empty_cart();
                    $order->update_status($status, __('Completed by OIS-Bizcraft payment', 'wc-gateway-oisbizcraft'));
                }
                if ($data['status'] == 'cancelled') {
                    $status = 'wc-cancelled';
                    $order->update_status($status, __('Cancelled by OIS-Bizcraft payment', 'wc-gateway-oisbizcraft'));
                }
                if ($data['status'] == 'failed') {
                    $status = 'wc-failed';
                    $order->update_status($status, __('Failed by OIS-Bizcraft payment', 'wc-gateway-oisbizcraft'));
                }

                wp_send_json(['success' => true]);
            } catch (\Throwable $th) {
                echo $th;
                die();
            }

        }

        /**
         * Initialize Gateway Settings Form Fields
         */
        public function init_form_fields()
        {

            $this->form_fields = apply_filters('woo_oisbizcraft_form_fields', [
                'enabled' => [
                    'title' => __('Enable/Disable', 'wc-gateway-oisbizcraft-plus'),
                    'type' => 'checkbox',
                    'label' => __('Enable or Disable Oisbizcraft Payments', 'wc-gateway-oisbizcraft-plus'),
                    'default' => 'yes',
                ],
                'title' => [
                    'title' => __('Oisbizcraft Payment', 'wc-gateway-oisbizcraft-plus'),
                    'type' => 'text',
                    'description' => __('Add a new title for the Oisbizcraft Payments Gateway that customers will see when they are in the checkout page.', 'wc-gateway-oisbizcraft-plus'),
                    'default' => __('Oisbizcraft Payment Plus', 'wc-gateway-oisbizcraft-plus'),
                    'desc_tip' => true,
                ],
                'description' => [
                    'title' => __('Oisbizcraft Payments Gateway Description', 'wc-gateway-oisbizcraft-plus'),
                    'type' => 'textarea',
                    'default' => __('Pay via Oisbizcraft Plus;', 'wc-gateway-oisbizcraft-plus'),
                    'desc_tip' => true,
                    'description' => __('Set the description so the customers know about it.', 'wc-gateway-oisbizcraft-plus'),
                ],
                'instructions' => [
                    'title' => __('Instructions', 'wc-gateway-oisbizcraft-plus'),
                    'type' => 'textarea',
                    'default' => __('Default instructions', 'wc-gateway-oisbizcraft-plus'),
                    'desc_tip' => true,
                    'description' => __('Instructions that will be added to the thank you page and order email', 'wc-gateway-oisbizcraft-plus'),
                ],
                'cust_code' => [
                    'title' => __('Customer Code', 'wc-gateway-oisbizcraft-plus'),
                    'type' => 'text',
                    'desc_tip' => true,
                    'description' => __('Add your customer code', 'wc-gateway-oisbizcraft-plus'),
                ],
                'test_mode' => array(
                    'title' => __('Mode', 'wc-gateway-oisbizcraft-plus'),
                    'type' => 'select',
                    'default' => 'portalapi',
                    'description' => __('Mode of OIS activities'),
                    'desc_tip' => true,
                    'class'        => 'wc-enhanced-select',
                    'options' => array(
                        'portalapi' => 'Live Mode',
                        'devapiportal' => 'Sandbox',
                    ),
                ),
                'completed_order_status' => array(
                    'title' => __('Order Status', 'wc-gateway-oisbizcraft-plus'),
                    'type' => 'select',
                    'default' => 'Default',
                    'description' => __('Default status after success transaction'),
                    'desc_tip' => true,
                    'class'        => 'wc-enhanced-select',
                    'options' => array(
                        'default' => 'Completed',
                        'processing' => 'Processing'
                    ),
                ),
                'merchant_outlet_id' => [
                    'title' => __('Merchant Outlet ID', 'wc-gateway-oisbizcraft-plus'),
                    'type' => 'text',
                    'desc_tip' => true,
                    'description' => __('Add your merchant outlet id', 'wc-gateway-oisbizcraft-plus'),
                ],
                'terminal_id' => [
                    'title' => __('Terminal Id', 'wc-gateway-oisbizcraft-plus'),
                    'type' => 'text',
                    'desc_tip' => true,
                    'description' => __('Add your terminal id', 'wc-gateway-oisbizcraft-plus'),
                ],
                'secret_key' => [
                    'title' => __('Secret Key', 'wc-gateway-oisbizcraft-plus'),
                    'type' => 'password',
                    'desc_tip' => true,
                    'description' => __('Add your secret key', 'wc-gateway-oisbizcraft-plus'),
                ],
            ]);
        }

        /**
         * Process the payment and return the result
         *
         * @param int $order_id
         * @return array
         */
        public function process_payment($order_id)
        {

            $order = wc_get_order($order_id);

            $order->update_status('pending', __('Awaiting OIS-Bizcraft payment plus', 'wc-gateway-oisbizcraft-plus'));

            return array(
                'result' => 'success',
                'redirect' => $this->get_request_url($order)
            );
        }

        public function get_request_url($order)
        {
            $curl = curl_init();

            // Generate Order ID for OIS-Bizcraft portal
            $this->portalOrderID = uniqid('WC-', false) .'-'.$this->random_str(12). '-' . $order->get_id();

            $form = [
                'cust_code' => $this->get_option('cust_code'),
                'merchant_return_url' => $this->wc_ois_response_url($order),
                'merchant_outlet_id' => $this->get_option('merchant_outlet_id'),
                'terminal_id' => $this->get_option('terminal_id'),
                'currency' => $order->get_currency(),
                'description' => $this->get_option('description'),
                'amount' => (int)($order->get_total() * 100),
                'user_fullname' => $order->get_billing_first_name().' '.$order->get_billing_last_name(),
                'user_email' => $order->get_billing_email(),
                'backend_callback_url' => get_site_url().'?wc-api=wc_gateway_oisbizcraft_plus',
                'origin_call' => 'woocommerce',
                'invoice_number' => '',
                'order_id' => $this->portalOrderID,
                'secret_key' => $this->get_option('secret_key'),
            ];
            // Generate HMAC key for OIS-Bizcraft portal authentication
            $form['hash'] = $this->generatehash($form);

            // Call OIS-Bizcraft API to get the checkout page url
            try {
                $args = array(
                    'body'        => json_encode($form),
                    'httpversion' => '1.1',
                    'blocking'    => true,
                    'headers'     => [
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json'
                    ],
                );
                $response = wp_remote_retrieve_body(wp_remote_post( $this->portalURL.'/api/payments', $args ));
            } catch (\Throwable $th) {
                return $th;
            }

            curl_close($curl);

            error_log($response);
            $result = json_decode($response);
            if (isset($result->response)) {
                error_log($response);
                error_log(json_encode($form));

                return $response;
            } else {
                if ($result->errors) {
                    return $result;
                }

                return $result->data->url;
            }
        }

        public function generatehash($data)
        {
            $string =
                $data['cust_code'] . $data['merchant_outlet_id'] . $data['terminal_id'] .
                $this->portalURL.'/payment/check-status/'.$data['order_id']. $data['description'] . $data['currency'] .
                $data['amount'] .
                $data['order_id'] . $data['user_fullname'];

            return strtoupper(hash_hmac('SHA1', $string, $data['secret_key']));
        }

        public function generateValidateKey($data)
        {
            $string = $this->terminal_id . $data['order_id'] . $this->merchant_outlet_id . $this->cust_code . $data['status'];
            return strtoupper(hash_hmac('SHA1', $string, $this->secret_key));
        }

        /**
         * Generate a random string, using a cryptographically secure
         * pseudorandom number generator (random_int)
         *
         * This function uses type hints now (PHP 7+ only), but it was originally
         * written for PHP 5 as well.
         *
         * For PHP 7, random_int is a PHP core function
         * For PHP 5.x, depends on https://github.com/paragonie/random_compat
         *
         * @param int $length      How many characters do we want?
         * @param string $keyspace A string of all possible characters
         *                         to select from
         * @return string
         */
        protected function random_str(
            int $length = 64,
            string $keyspace = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'
        ): string {
            if ($length < 1) {
                throw new \RangeException("Length must be a positive integer");
            }
            $pieces = [];
            $max = mb_strlen($keyspace, '8bit') - 1;
            for ($i = 0; $i < $length; ++$i) {
                $pieces []= $keyspace[random_int(0, $max)];
            }
            return implode('', $pieces);
        }
    }
}
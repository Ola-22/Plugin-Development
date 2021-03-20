<?php
/*
Plugin Name: WooCommerce Payement Gateway for Payd.ae
Author: Ola Nazly
Version: 1.0
*/

function payd_init_class() {
    
    class Wc_Gateway_Payd extends WC_Payment_Gateway
    {
        public function __construct()
        {
            $this->id = 'wc_gateway_payd';
            $this->icon = 'https://www.payd.ae/img/payd.svg';
            $this->has_fields = false;
            $this->method_title = __('Payd.ae - مدفوع', 'wc_gw_payd');
            $this->method_description = __('Payment gateway for payd.ae', 'wc_gw_payd');

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option( 'title' );
            $this->description = $this->get_option( 'description' );

            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
            
            add_action( 'woocommerce_api_' . strtolower(__CLASS__), [$this, 'process_response'] );

        }

        public function init_form_fields()
        {
            $pages = [''];
            foreach (get_pages() as $page) {
                $pages[$page->ID] = $page->post_title;
            }

            $this->form_fields = [
                'enabled' => array(
                    'title' => __( 'Enable/Disable', 'wc_gw_payd' ),
                    'type' => 'checkbox',
                    'label' => __( 'Enable Payd.ae Payment', 'wc_gw_payd' ),
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' => __( 'Title', 'wc_gw_payd' ),
                    'type' => 'text',
                    'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
                    'default' => __( 'Payd - مدفوع', 'wc_gw_payd' ),
                    'desc_tip'      => false,
                ),
                'description' => array(
                    'title' => __( 'Description', 'wc_gw_payd' ),
                    'type' => 'textarea',
                    'default' => ''
                ),
                'secret-key' => array(
                    'title' => __( 'Secret Key', 'wc_gw_payd' ),
                    'type' => 'text',
                    //'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
                    'desc_tip'      => false,
                ),
                'testmode' => array(
                    'title' => __( 'Test Mode', 'wc_gw_payd' ),
                    'type' => 'checkbox',
                    'label' => __( 'Test Mode', 'wc_gw_payd' ),
                    'default' => 'yes'
                ),
                'success-page' => array(
                    'title' => __( 'Success Page', 'wc_gw_payd' ),
                    'type' => 'select',
                    'options' => $pages,
                ),
            ];
        }

        public function process_payment( $order_id )
        {
            global $woocommerce;
            $order = new WC_Order( $order_id );

            // Mark as on-hold (we're awaiting the cheque)
            $order->update_status('on-hold', __( 'Awaiting payment', 'wc_gw_payd' ));

            // Remove cart
            $woocommerce->cart->empty_cart();

            // Process payment
            $redirect = $this->makeTransaction($order);

            // Return thankyou redirect
            return array(
                'result' => 'success',
                'redirect' => $redirect, //$this->get_return_url( $order )
            );
        }

        protected function makeTransaction(WC_Order $order)
        {
            //$customer = $order->get_user();
            $items = [];
            foreach ($order->get_items() as $item) {
                $vat = floatval($item->get_subtotal_tax()) * 100 / floatval($item->get_subtotal());
                $items[] = [
                    'title' => $item->get_name(),
                    'amount' => $item->get_subtotal(),
                    'vat' => round($vat, 2),
                    'total' => $item->get_total(),
                ];
            }

            
            $ch = curl_init('https://www.payd.ae/pg/public/api/generateTransactionId');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => false,
                //CURLOPT_POST => true,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => [
                    'name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                    'phone' => $order->get_billing_phone(),
                    'email' => $order->get_billing_email(),
                    'amount' => $order->get_total(),
                    'remarks' => $order->get_id(),
                    'redirecturl' => home_url('wc-api/' .  __CLASS__),
                    'product' => json_encode($items),
                ],
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'secretkey: ' . $this->get_option('secret-key'),
                ],
            ]);
            $response = curl_exec($ch);
            curl_close($ch);
            $json = json_decode($response);
            
            if (isset($json->statuscode) && $json->statuscode == 1) {
                return $json->data;
            }

            return $order->get_view_order_url();
        }

        public function process_response()
        {
            $reference = $_GET['reference'] ?? false;
            if (!$reference) {
                return;
            }
            $mode = $this->get_option('testmode', 0)? 1 : 0;
            $ch = curl_init("https://www.payd.ae/pg/public/api/paymentdetails/$reference/$mode");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => false,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: text/plain',
                    'Accept: application/json',
                    'secretkey: ' . $this->get_option('secret-key'),
                ],
            ]);
            $response = curl_exec($ch);
            curl_close($ch);
            $json = json_decode($response);
            if (isset($json->remarks)) {
                $order = new WC_Order($json->remarks);
                if ($json->status == 1 && $json->data->payment_status == 'completed') {
                    $order->payment_complete($reference);
                    //$order->update_status('completed', '');
                } else {
                    $order->update_status('failed', 'Payment failed ' . $json->message);
                }
                if ($page = $this->get_option('success-page')) {
                    wp_redirect(get_permalink($page));
                    exit;
                }
                wp_redirect($order->get_view_order_url());
                exit;
            }

        }
    }

}
add_action('plugins_loaded', 'payd_init_class');

function payd_add_gateway_class( $methods ) {
    $methods[] = 'Wc_Gateway_Payd'; 
    return $methods;
}
add_filter( 'woocommerce_payment_gateways', 'payd_add_gateway_class' );
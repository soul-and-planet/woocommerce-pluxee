<?php
/*
Plugin Name: WooCommerce Pluxee
Plugin URI: https://www.pluxeegroup.com/fr/
Description: Ce module vous permet d'accepter les paiements en ligne avec Pluxee
Version: 1.0.0
Author: Soul & Planet
Author URI: https://soulandplanet.tn/
*/

/*
 * The class itself, please note that it is inside plugins_loaded action hook
 */
add_action('plugins_loaded', 'init_wc_pluxee');

function init_wc_pluxee()
{
    #[AllowDynamicProperties]
    class WC_Pluxee extends WC_Payment_Gateway
    {
        private $testDomain = "https://196.203.11.70:26443";
        private $prodDomain = "https://sodexo.monetiquetunisie.com";

        /**
         * Class constructor
         */
        public function __construct()
        {

            $this->id = 'wc_pluxee';
            $this->icon = apply_filters('wc_pluxee_icon', 'pluxee.svg');
            $this->has_fields = false;
            $this->method_title = 'Pluxee';
            $this->method_description = 'Enable paying with Pluxee';

            $this->init_form_fields();

            $this->init_settings();

            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->enabled = $this->get_option('enabled');
            $this->testmode = 'yes' === $this->get_option('testmode');
            $this->idpv = $this->get_option('idpv');
            $this->username = $this->get_option('username');
            $this->password = $this->get_option('password');
            $this->timeout = $this->get_option('timeout', 20 * 60);

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

            add_action('woocommerce_api_' . strtolower(get_class($this)), array($this, 'handle_callback'));
        }

        public function get_icon()
        {
            global $woocommerce;

            $icon = '';
            if ($this->icon) {
                $icon = '<img src="' . plugins_url('images/' . $this->icon, __FILE__) . '" alt="' . $this->title . '" />';
            }

            return apply_filters('woocommerce_gateway_icon', $icon, $this->id);
        }

        /**
         * Plugin options
         */
        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => 'Statut',
                    'label' => 'Activer les paiements Pluxee',
                    'type' => 'checkbox',
                    'description' => '',
                    'default' => 'no'
                ),
                'title' => array(
                    'title' => 'Title',
                    'type' => 'text',
                    'description' => 'Le nom du moyen de paiement que l\'utilisateur voit sur le site.',
                    'default' => 'Paiement en ligne',
                ),
                'description' => array(
                    'title' => 'Description',
                    'type' => 'text',
                    'description' => 'La description que l\'utilisateur voit sur le site.',
                    'default' => 'Payer en ligne avec Pluxee.',
                ),
                'testmode' => array(
                    'title' => 'Test mode',
                    'label' => 'Activer le mode test',
                    'type' => 'checkbox',
                    'description' => 'A activer durant vos développements, à désactiver en production',
                    'default' => 'yes',
                    'desc_tip' => true,
                ),
                'idpv' => array(
                    'title' => 'IDPV',
                    'type' => 'text',
                    'description' => 'Fourni par Pluxee'
                ),
                'username' => array(
                    'title' => 'UsernamePtVente',
                    'type' => 'text',
                    'description' => 'Fourni par Pluxee'
                ),
                'password' => array(
                    'title' => 'PwdPtVente',
                    'type' => 'password',
                    'description' => 'Fourni par Pluxee'
                ),
                'timeout' => array(
                    'title' => 'SessionTimeoutSec',
                    'type' => 'number',
                    'description' => 'Durée de validité de la page de paiement, en secondes',
                    'default' => 20 * 60,
                    'css' => 'width: 65px',
                )
            );
        }

        /*
         * We're processing the payments here
         */
        public function process_payment($order_id)
        {
            global $woocommerce;
            ini_set('display_errors', 'off');

            $order = wc_get_order($order_id);

            $return_url = add_query_arg(['wc-api' => 'wc_pluxee', 'order_id' => $order_id], home_url('/'));


            $params = [
                'IDPV' => urlencode($this->idpv),
                'UsernamePtVente' => urlencode($this->username),
                'PwdPtVente' => urlencode($this->password),
                'OrderNumber' => $order_id,
                'TotalAmountPaid' => $order->get_total() * 1_000,
                'PurchaseAmount' => ($order->get_total() - $order->get_shipping_total()) * 1_000,
                'PurchaseDiscountAmount' => $order->get_discount_total() * 1_000,
//                'DonorOrderDiscount' => '',
//                'PurchaseAmountAfterDiscount' => '',
                'DeliveryCost' => $order->get_shipping_total() * 1_000,
                //'DiscountOnDeliveryCost' => '',
                //'DonorDeliveryDiscount' => '',
                //'DeliveryCostAfterDiscount' => '',
                'ReturnUrl' => urlencode($return_url),
                'FailUrl' => urlencode($return_url),
                // 'CommandDescription' => '',
                // 'DeviceType' => '',
                'SessionTimeoutSec' => $this->timeout,
            ];

            $response = $this->callEndpoint('Autorisation', $params);

            $body = json_decode($response['body'], true);

            if (isset($body['errorCode']) && (int)$body['errorCode'] !== 0) {
                wc_add_notice($body['errorMessage'], 'error');
                return array(
                    'result' => 'failure',
                );
            }
            session_start();
            $_SESSION['PLUXEE_ORDER_ID'] = $body['orderId'];

            return array(
                'result' => 'success',
                'redirect' => $body['formUrl']
            );

        }

        private $codes = [
            "0" => "Success",
            "1" => "Input Error",
            "2" => "Username/PWD not correct",
            "3" => "General Error",
            "4" => "Order saved but not paid",
            "5" => "Order paid",
            "6" => "Order Cancelled",
            "7" => "Order Not Found",
            "100" => "OTP Sent",
            "8" => "Error Fetching Card",
            "9" => "No Associated Terminal",
            "10" => "MSISDN not found for OTP sending",
            "11" => "OTP Fetching KO",
            "12" => "OTP Expired",
            "13" => "MSISDN/Pin Incorrect",
            "101" => "Error Sending SMS (OTP)",
            "30" => "Format Error",
            "15" => "Error processing Code",
            "14" => "Card Not Found",
            "62" => "Blocked Card",
            "36" => "Blacklisted Card",
            "54" => "Expired Card",
            "61" => "Withdrawal limit reached",
            "51" => "Insufficient balance",
            "99" => "General Error in payment",
        ];

        public function handle_callback()
        {
            session_start();
            ini_set('display_errors', 'off');
            $order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;

            if ($order_id > 0) {
                $pluxeeOrderId = $_SESSION['PLUXEE_ORDER_ID'];

                unset($_SESSION['PLUXEE_ORDER_ID']);

                $params = [
                    'UsernamePtVente' => urlencode($this->username),
                    'PwdPtVente' => urlencode($this->password),
                    'OrderId' => urlencode($pluxeeOrderId),
                ];

                $response = $this->callEndpoint('OrderStatus', $params);

                $body = json_decode($response['body'], true);

                if (in_array($body['orderStatus'], array("0", "5"))) {
                    // Mark the order as paid
                    $order = wc_get_order($order_id);
                    $order->add_order_note(sprintf('Payée avec Pluxee. Numéro de la transaction %s.', $pluxeeOrderId));

                    $order->payment_complete();

                    wp_redirect($this->get_return_url($order));
                    exit;
                }

                wc_add_notice("Erreur de paiement Pluxee ($order_id / $pluxeeOrderId): Code {$body['orderStatus']} - Message {$this->codes[$body['orderStatus']]}", 'error');
                wp_redirect(get_permalink(get_option('woocommerce_checkout_page_id')));
                exit;
            }

            wc_add_notice("Erreur de paiement Pluxee ($order_id)", 'error');
            wp_redirect(get_permalink(get_option('woocommerce_checkout_page_id')));
            exit;
        }

        private function callEndpoint($endpoint, $params = [])
        {
            if ($this->testmode) {
                // FIXME: We should have a valid certificate chain in test as well
                add_filter('https_ssl_verify', '__return_false');
            }

            $url = ($this->testmode ? $this->testDomain : $this->prodDomain) . "/BackKitMerchant/api/KitMarchand/$endpoint";
            $url = add_query_arg($params, $url);
            return wp_remote_post($url);
        }
    }

    /*
     * This hook registers our PHP class as a WooCommerce payment gateway
     */
    add_filter('woocommerce_payment_gateways', 'wc_pluxee_add_credit_card_gateway_class');
    function wc_pluxee_add_credit_card_gateway_class($gateways)
    {
        $gateways[] = 'WC_Pluxee';
        return $gateways;
    }
}

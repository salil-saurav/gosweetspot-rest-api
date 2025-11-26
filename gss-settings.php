<?php

/**
 * Plugin Name: WooCommerce GoSweetSpot Integration
 * Description: Integration with GoSweetSpot for dynamic shipping calculation and label generation. Includes Freight logic.
 * Version: 1.1
 * Author: DWS
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

// Include the helper classes
require_once plugin_dir_path(__FILE__) . 'includes/class-gss-api.php';

add_action('woocommerce_loaded', function () {

    if (!class_exists('WC_GoSweetSpot_Shipping_Method')) {
        class WC_GoSweetSpot_Shipping_Method extends WC_Shipping_Method
        {
            public function __construct($instance_id = 0)
            {
                $this->id                 = 'gosweetspot';
                $this->instance_id        = absint($instance_id);
                $this->method_title       = __('GoSweetSpot Shipping', 'woocommerce');
                $this->method_description = __('Dynamic rates via GoSweetSpot API.', 'woocommerce');
                $this->supports           = ['shipping-zones', 'instance-settings'];

                $this->init();
            }

            public function init()
            {
                $this->init_form_fields();
                $this->init_settings();
                $this->title = $this->get_option('title', __('GoSweetSpot Shipping', 'woocommerce'));

                add_action('woocommerce_update_options_shipping_' . $this->id, [$this, 'process_admin_options']);
            }

            public function init_form_fields()
            {
                $this->form_fields = [
                    'title' => [
                        'title'       => __('Method Title', 'woocommerce'),
                        'type'        => 'text',
                        'default'     => __('GoSweetSpot Shipping', 'woocommerce'),
                    ],
                ];
            }

            public function calculate_shipping($package = [])
            {
                // 1. Check for Freight/Heavy Logic using Helper
                if (GSS_Helper::is_freight_order()) {
                    $rate = [
                        'id'       => $this->id . ':freight_tba',
                        'label'    => 'Freight - Cost to be calculated and emailed',
                        'cost'     => 0,
                        'calc_tax' => 'per_order'
                    ];
                    $this->add_rate($rate);
                    return;
                }

                // 2. Standard Logic (API rates selected via AJAX)
                $selected_cost = WC()->session->get('gss_selected_cost');
                $selected_name = WC()->session->get('gss_selected_name');

                if (empty($selected_cost) || floatval($selected_cost) <= 0) {
                    return; // User hasn't clicked "Calculate" yet
                }

                $this->add_rate([
                    'id'      => $this->id . ':' . $this->instance_id,
                    'label'   => $selected_name ?: $this->title,
                    'cost'    => (float) $selected_cost,
                    'taxes'   => false, // Set true if tax calculation needed
                    'package' => $package,
                ]);
            }
        }
    }

    if (!class_exists('WC_GoSweetSpot_Integration')) {
        class WC_GoSweetSpot_Integration
        {
            private $logger;
            private $api;

            public function __construct()
            {
                $this->logger = wc_get_logger();
                $this->api = new GSS_API();

                // Admin
                add_action('admin_menu', [$this, 'add_settings_page']);
                add_action('admin_init', [$this, 'register_settings']);

                // Assets & Frontend
                add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
                add_action('woocommerce_review_order_after_shipping', [$this, 'render_shipping_ui']);
                add_action('woocommerce_after_order_notes', [$this, 'add_hidden_checkout_fields']);

                // AJAX Handlers
                add_action('wp_ajax_gss_calculate_shipping', [$this, 'ajax_calculate_shipping']);
                add_action('wp_ajax_nopriv_gss_calculate_shipping', [$this, 'ajax_calculate_shipping']);
                add_action('wp_ajax_gss_save_shipping_session', [$this, 'ajax_save_session']);
                add_action('wp_ajax_nopriv_gss_save_shipping_session', [$this, 'ajax_save_session']);

                // Order Processing
                add_action('woocommerce_checkout_update_order_meta', [$this, 'save_order_meta'], 10, 1);
                add_action('woocommerce_checkout_order_processed', [$this, 'process_freight_logic'], 10, 1);

                // This ensures that if the user adds/removes items, the old shipping quote (based on old weight) is removed.
                add_action('woocommerce_cart_emptied', [$this, 'clear_session']);
                add_action('woocommerce_payment_successful', [$this, 'clear_session']);

                // New hooks to force reset when cart content changes
                add_action('woocommerce_add_to_cart', [$this, 'clear_session']);
                add_action('woocommerce_cart_item_removed', [$this, 'clear_session']);
                add_action('woocommerce_cart_item_restored', [$this, 'clear_session']);
                add_action('woocommerce_after_cart_item_quantity_update', [$this, 'clear_session']);

                // Freight UI Logic (Notices)
                add_filter('woocommerce_no_shipping_available_html', [$this, 'custom_no_shipping_message']);
                add_action('woocommerce_order_details_after_order_table', [$this, 'order_received_freight_message']);
                add_filter('woocommerce_shipping_methods', [$this, 'add_shipping_method']);
                add_action('woocommerce_proceed_to_checkout', [$this, 'show_continue_shopping_btn'], 5);

                // Cron for Labels
                add_action('gss_generate_label_event', [$this, 'generate_label_cron'], 10, 2);
            }

            // --- Registration ---
            public function add_shipping_method($methods)
            {
                $methods['gosweetspot'] = 'WC_GoSweetSpot_Shipping_Method';
                return $methods;
            }

            // --- Frontend UI ---
            public function enqueue_scripts()
            {
                if (is_checkout()) {
                    wp_enqueue_script('gss-checkout', plugin_dir_url(__FILE__) . 'checkout.js', ['jquery'], '1.2.0', true);
                    wp_localize_script('gss-checkout', 'gss_ajax', [
                        'ajax_url' => admin_url('admin-ajax.php'),
                        'nonce'    => wp_create_nonce('gss_nonce'),
                    ]);
                    wp_enqueue_style('gss-checkout', plugin_dir_url(__FILE__) . 'checkout.css');
                }
            }

            public function render_shipping_ui()
            {
                // Show UI only when NOT a freight order
                if (!GSS_Helper::is_freight_order()) {

                    $button_text = 'Select Shipping Options';

                    // Extra safety: Session exists and is initialized
                    if (WC()->session instanceof WC_Session) {
                        $selected_cost = WC()->session->get('gss_selected_cost');

                        if (!empty($selected_cost)) {
                            $button_text = 'Change Shipping Option';
                        } else {
                            $button_text = 'Select Shipping Options';
                        }
                    }

                    // Clean HTML output (no broken table structure)
                    echo sprintf(
                        '<tr class="gss-shipping-row">
                            <td colspan="2">
                                <button type="button" id="gss-calculate-shipping" class="button">%s</button>
                                <div id="gss-shipping-options" style="margin-top: 15px;"></div>
                            </td>
                        </tr>',
                        esc_html($button_text)
                    );
                } else {

                    if (wp_doing_ajax()) {
                        return;
                    }
                    echo '<div class="woocommerce_info" style="background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 15px;">' .
                        '<p><strong>Shipping Information:</strong></p>' .
                        '<p>This order contains multiple or heavy items. Freight will be calculated by volume and weight.</p>' .
                        '<p>We will email you the freight cost shortly.</p>' .
                        '</div>';
                }
            }

            public function add_hidden_checkout_fields()
            {
                echo '<input type="hidden" id="gss-selected-courier" name="gss_selected_courier" />';
                echo '<input type="hidden" id="gss-selected-cost" name="gss_selected_cost" />';
                echo '<input type="hidden" id="gss-selected-name" name="gss_selected_name" />';
            }


            public function custom_no_shipping_message($default_msg)
            {
                if (GSS_Helper::is_freight_order()) {
                    return '<div class="woocommerce_info" style="background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 15px;">' .
                        '<p><strong>Shipping Information:</strong></p>' .
                        '<p>This order contains multiple or heavy items. Freight will be calculated by volume and weight.</p>' .
                        '<p>We will email you the freight cost shortly.</p>' .
                        '</div>';
                }

                return '<tr class="gss-instruction-notice">
                    <td colspan="2">
                        <div class="woocommerce-info">
                            <p style="margin: 0;">
                                <strong>📦 Shipping Method Required </strong><br>
                                Please tap the <strong>"Select Shipping Options"</strong> button below and select one of the available shipping methods to continue.
                            </p>
                        </div>
                    </td>
                </tr>';
            }

            /**
             * Show custom continue shopping button on cart page
             */

            public function show_continue_shopping_btn()
            {
                $shop_url = wc_get_page_permalink('shop');
                echo '<a href="' . esc_url($shop_url) . '" class="checkout-button button alt wc-forward elementor-animation-pulse-shrink" style="margin-bottom:10px" >Continue Shopping</a>';
            }

            public function order_received_freight_message($order)
            {
                if (GSS_Helper::is_freight_order($order)) {
                    echo '<div class="woocommerce-message" style="margin: 20px 0; border-left: 4px solid #4caf50;">';
                    echo '<p><strong>Shipping Notice:</strong> We will email you the final freight calculation.</p>';
                    echo '</div>';
                }
            }

            // --- AJAX Logic ---
            public function ajax_calculate_shipping()
            {
                check_ajax_referer('gss_nonce', 'nonce');

                $destination = [
                    'name' => sanitize_text_field($_POST['name'] ?? ''),
                    'address' => [
                        'streetaddress' => sanitize_text_field($_POST['address'] ?? ''),
                        'suburb'        => sanitize_text_field($_POST['suburb'] ?? ''),
                        'city'          => sanitize_text_field($_POST['city'] ?? ''),
                        'postcode'      => sanitize_text_field($_POST['postcode'] ?? ''),
                        'countrycode'   => sanitize_text_field($_POST['country'] ?? 'NZ'),
                    ]
                ];

                $origin = [
                    'name' => get_option('gss_sender_name'),
                    'address' => [
                        'streetaddress' => get_option('gss_sender_address'),
                        'suburb'        => get_option('gss_sender_suburb'),
                        'city'          => get_option('gss_sender_city'),
                        'postcode'      => get_option('gss_sender_postcode'),
                        'countrycode'   => get_option('gss_sender_country', 'NZ'),
                    ]
                ];

                // Build Packages
                $packages = [];
                foreach (WC()->cart->get_cart() as $cart_item) {
                    $prod = $cart_item['data'];
                    $qty  = $cart_item['quantity'];
                    for ($i = 0; $i < $qty; $i++) {
                        $packages[] = [
                            'name'   => $prod->get_name(),
                            'kg'     => round(GSS_Helper::convert_to_kg($prod->get_weight()), 3),
                            'length' => round(GSS_Helper::convert_to_cm($prod->get_length()), 3),
                            'width'  => round(GSS_Helper::convert_to_cm($prod->get_width()), 3),
                            'height' => round(GSS_Helper::convert_to_cm($prod->get_height()), 3),
                        ];
                    }
                }
                if (empty($packages)) {
                    $packages[] = ['name' => 'Default', 'kg' => 1, 'length' => 20, 'width' => 20, 'height' => 10];
                }

                $response = $this->api->make_request('rates', [
                    'origin'      => $origin,
                    'destination' => $destination,
                    'packages'    => $packages
                ]);

                if ($response['success'] && !empty($response['data']['Available'])) {
                    wp_send_json_success(['rates' => $response['data']['Available']]);
                } else {
                    wp_send_json_error(['message' => 'No rates found', 'debug' => $response['error'] ?? '']);
                }
            }

            public function ajax_save_session()
            {
                check_ajax_referer('gss_nonce', 'nonce');
                if (!WC()->session) return;

                $cost = floatval($_POST['cost']);
                WC()->session->set('gss_selected_courier', sanitize_text_field($_POST['courier']));
                WC()->session->set('gss_selected_cost', $cost);
                WC()->session->set('gss_selected_name', sanitize_text_field($_POST['name']));

                wp_send_json_success();
            }

            public function clear_session()
            {
                if (isset(WC()->session) && WC()->session->has_session()) {
                    // Use 'set' to null/empty string to ensure overwrite if unset behaves unexpectedly in some cache environments
                    WC()->session->set('gss_selected_courier', '');
                    WC()->session->set('gss_selected_cost', '');
                    WC()->session->set('gss_selected_name', '');

                    // Also perform the standard unset
                    WC()->session->__unset('gss_selected_courier');
                    WC()->session->__unset('gss_selected_cost');
                    WC()->session->__unset('gss_selected_name');

                    // Optional: Save immediately if needed, though WC usually handles this on shutdown
                    // WC()->session->save_data();
                }
            }


            // --- Order Saving & Freight Processing ---

            public function save_order_meta($order_id)
            {
                // Verify this isn't a freight order first
                if (isset($_POST['gss_selected_courier']) && !empty($_POST['gss_selected_courier'])) {
                    $order = wc_get_order($order_id);
                    $order->update_meta_data('_gss_selected_courier', sanitize_text_field($_POST['gss_selected_courier']));
                    $order->update_meta_data('_gss_selected_cost', sanitize_text_field($_POST['gss_selected_cost']));
                    $order->update_meta_data('_gss_selected_name', sanitize_text_field($_POST['gss_selected_name']));
                    $order->save();

                    // Schedule Label Generation
                    // We pass both ID and Courier to ensure Cron has context
                    if (!wp_next_scheduled('gss_generate_label_event', [$order_id, $_POST['gss_selected_courier']])) {
                        wp_schedule_single_event(time() + 10, 'gss_generate_label_event', [$order_id, $_POST['gss_selected_courier']]);
                    }
                }
            }

            /**
             * Handles email notifications if the order is Freight/TBD
             */
            public function process_freight_logic($order_id)
            {
                $order = wc_get_order($order_id);
                if (!GSS_Helper::is_freight_order($order)) return;

                $this->logger->info("Processing Freight Email for Order #$order_id");

                $mailer = WC()->mailer();
                $headers = ['Content-Type: text/html; charset=UTF-8'];

                // 1. Customer Email
                $subject_cust = "Information regarding Shipping for Order #{$order_id}";
                $msg_cust = "
                    <p>Hi " . $order->get_billing_first_name() . ",</p>
                    <p>Thank you for your order. Since this is a heavy or multi-item order, freight is calculated manually.</p>
                    <p style='background-color:#fff3cd;padding:10px;'>We will email you the freight cost shortly. Please confirm acceptance so we can process your order.</p>
                ";
                $mailer->send($order->get_billing_email(), $subject_cust, $mailer->wrap_message($subject_cust, $msg_cust), $headers);

                // 2. Admin Email
                $admin_email = 'salil.saurav@digitalwebsolutions.in'; // Or get_option('admin_email')
                $subject_admin = "ACTION REQUIRED: Freight Quote Order #{$order_id}";
                $msg_admin = "<h2>Freight Quote Required</h2><p>Please manually calculate freight for Order #{$order_id}.</p>";
                $msg_admin .= "<p><a href='" . admin_url('post.php?post=' . $order_id . '&action=edit') . "'>View Order</a></p>";

                $mailer->send($admin_email, $subject_admin, $msg_admin, $headers);
            }

            // --- Cron: Label Generation ---

            public function generate_label_cron($order_id, $courier_id)
            {
                $order = wc_get_order($order_id);
                if (!$order) return;

                // 1. Construct Payload (simplified for brevity, similar to Ajax logic but using Order object)
                $origin = [
                    'name' => get_option('gss_sender_name'),
                    'address' => [
                        'streetaddress' => get_option('gss_sender_address'),
                        'suburb'        => get_option('gss_sender_suburb'),
                        'city'          => get_option('gss_sender_city'),
                        'postcode'      => get_option('gss_sender_postcode'),
                        'countrycode'   => get_option('gss_sender_country', 'NZ'),
                    ]
                ];

                $destination = [
                    'name' => $order->get_formatted_shipping_full_name(),
                    'address' => [
                        'streetaddress' => $order->get_shipping_address_1(),
                        'suburb' => $order->get_shipping_city(),
                        'city' => $order->get_shipping_city(),
                        'postcode' => $order->get_shipping_postcode(),
                        'countrycode' => $order->get_shipping_country(),
                    ]
                ];

                $packages = [];
                foreach ($order->get_items() as $item) {
                    $prod = $item->get_product();
                    if ($prod) {
                        $packages[] = [
                            'name' => $prod->get_name(),
                            'kg' => round(GSS_Helper::convert_to_kg($prod->get_weight()), 3),
                            'length' => round(GSS_Helper::convert_to_cm($prod->get_length()), 3),
                            'width' => round(GSS_Helper::convert_to_cm($prod->get_width()), 3),
                            'height' => round(GSS_Helper::convert_to_cm($prod->get_height()), 3),
                        ];
                    }
                }

                // NOTE: To generate a label, you usually need a 'QuoteId' or specific 'CarrierService'.
                // If your API requires the *exact* QuoteID from the checkout selection,
                // you must save that in 'save_order_meta' via hidden field and retrieve it here.
                // Assuming 'courier_id' passed here is enough or you re-quote:

                $payload = [
                    'origin' => $origin,
                    'destination' => $destination,
                    'packages' => $packages,
                    'Carrier' => $order->get_meta('_gss_selected_name'), // This might need mapping depending on API
                    'deliveryreference' => $order->get_order_number(),
                    'outputs' => 'LABEL_PDF_100X175'
                ];

                // Perform API Call to create shipment
                // Note: The specific API endpoint for creation usually requires specific fields
                // matched from the Rate response (like QuoteID).
                // Ensure your JS saves the QuoteID into a hidden field named 'gss_quote_id' and you save it to order meta.

                // For now, assuming you have the logic to fill $payload correctly:
                // $result = $this->api->make_request('shipments', $payload);

                // Placeholder logic for the structure:
                // if ($result['success']) {
                //      $file_info = GSS_Label_Manager::save_label_pdf($order_id, $result['data']['Consignments']['outputs']);
                //      if($file_info) {
                //          $order->update_meta_data('_gss_label_url', $file_info['url']);
                //          $order->save();
                //          GSS_Label_Manager::email_label_to_admin($order_id, $file_info);
                //      }
                // }
            }

            // --- Admin Settings Page ---
            public function add_settings_page()
            {
                add_submenu_page(
                    'woocommerce',
                    'GoSweetSpot',
                    'GoSweetSpot',
                    'manage_options',
                    'gss-settings',
                    function () {
                        if (!current_user_can('manage_options')) return;
?>
                    <div class="wrap">
                        <h1>GoSweetSpot Settings</h1>
                        <form action="options.php" method="post">
                            <?php
                            settings_fields('gss_settings');
                            do_settings_sections('gss_settings');
                            ?>
                            <table class="form-table">
                                <?php
                                $fields = ['gss_api_key' => 'API Key', 'gss_site_id' => 'Site ID', 'gss_sender_name' => 'Sender Name', 'gss_sender_address' => 'Address', 'gss_sender_suburb' => 'Suburb', 'gss_sender_city' => 'City', 'gss_sender_postcode' => 'Postcode', 'gss_sender_country' => 'Country Code'];
                                foreach ($fields as $id => $label) {
                                    echo "<tr><th scope='row'>$label</th><td><input type='text' name='$id' value='" . esc_attr(get_option($id)) . "' class='regular-text'></td></tr>";
                                }
                                ?>
                            </table>
                            <?php submit_button(); ?>
                        </form>
                    </div>
<?php
                    }
                );
            }

            public function register_settings()
            {
                $settings = ['gss_api_key', 'gss_site_id', 'gss_sender_name', 'gss_sender_address', 'gss_sender_suburb', 'gss_sender_city', 'gss_sender_postcode', 'gss_sender_country'];
                foreach ($settings as $s) register_setting('gss_settings', $s);
            }
        }

        new WC_GoSweetSpot_Integration();
    }
});

<?php

if (!defined('ABSPATH')) {
   exit;
}

/**
 * Class GSS_Helper
 * Centralizes logic for unit conversion and Freight/Heavy checks.
 */
class GSS_Helper
{
   /**
    * Check if the current cart/order qualifies as Freight (Heavy or Multiple Items).
    *
    * @param WC_Order|null $order Optional order object. If null, checks Cart.
    * @return bool
    */
   public static function is_freight_order($order = null)
   {
      $count = 0;
      $items = [];

      if ($order) {
         $count = $order->get_item_count();
         $items = $order->get_items();
      } elseif (WC()->cart) {
         $count = WC()->cart->get_cart_contents_count();
         $items = WC()->cart->get_cart();
      } else {
         return false;
      }

      // Rule 1: Multiple Items
      if ($count > 1) {
         return true;
      }

      // Rule 2: Heavy Items (>25kg)
      foreach ($items as $item) {
         $product = $order ? $item->get_product() : $item['data'];
         if (!$product) continue;

         // Get weight in store unit
         $weight = (float) $product->get_weight();

         // Assuming store unit is KG based on your original code logic.
         // If store is LBS, 25lbs is different.
         // Better to normalize, but keeping your logic:
         if ($weight > 25) {
            return true;
         }
      }

      return false;
   }

   public static function convert_to_cm($value)
   {
      $unit = strtolower(get_option('woocommerce_dimension_unit', 'cm'));
      $val = (float) $value;
      if (!$val) return 0.0;

      $conversions = ['mm' => 0.1, 'cm' => 1.0, 'in' => 2.54, 'm'  => 100.0];
      return $val * ($conversions[$unit] ?? 1.0);
   }

   public static function convert_to_kg($value)
   {
      $unit = strtolower(get_option('woocommerce_weight_unit', 'kg'));
      $val = (float) $value;
      if (!$val) return 0.0;

      $conversions = ['g' => 0.001, 'kg' => 1.0, 'lb' => 0.45359237, 'oz' => 0.0283495, 'mg' => 0.000001];
      return $val * ($conversions[$unit] ?? 1.0);
   }
}

/**
 * Class GSS_API
 * Handles direct communication with the GoSweetSpot API.
 */
class GSS_API
{
   private $api_url = 'https://api.gosweetspot.com/api';
   private $api_key;

   public function __construct()
   {
      $this->api_key = get_option('gss_api_key');
   }

   private function log($message, $context = [], $level = 'info')
   {
      $logger = wc_get_logger();
      $logger->log($level, "[GSS API] $message", ['source' => 'gosweetspot', 'context' => $context]);
   }

   public function make_request($endpoint, $data, $method = 'POST')
   {
      $url = trailingslashit($this->api_url) . $endpoint;

      $args = [
         'headers' => [
            'Content-Type' => 'application/json',
            'access_key'   => $this->api_key,
         ],
         'body'    => wp_json_encode($data),
         'timeout' => 30,
         'method'  => $method
      ];

      $response = wp_remote_request($url, $args);

      if (is_wp_error($response)) {
         $this->log("Request failed: $endpoint", ['error' => $response->get_error_message()], 'error');
         return ['success' => false, 'error' => $response->get_error_message()];
      }

      $body = json_decode(wp_remote_retrieve_body($response), true);
      $code = wp_remote_retrieve_response_code($response);

      if ($code < 200 || $code >= 300) {
         $this->log("API Error $code", ['body' => $body], 'error');
         return ['success' => false, 'error' => 'API Error: ' . $code];
      }

      return ['success' => true, 'data' => $body];
   }

   // ... (Helper methods for building addresses/packages moved here or kept in Ajax handler if preferred.
   // To keep it clean, I will keep data building in the Manager/Ajax class and just use this for transport.)
}

/**
 * Class GSS_Label_Manager
 * Handles File Saving and Emailing logic.
 */
class GSS_Label_Manager
{
   public static function save_label_pdf($order_id, $pdf_base64)
   {
      $upload_dir = wp_upload_dir();
      $gss_dirname = $upload_dir['basedir'] . '/gss-labels';
      $gss_urlname = $upload_dir['baseurl'] . '/gss-labels';

      if (!file_exists($gss_dirname)) {
         wp_mkdir_p($gss_dirname);
         // Add index.php / .htaccess to protect this folder if needed
      }

      $filename = "label-order-{$order_id}-" . time() . ".pdf";
      $file_path = $gss_dirname . '/' . $filename;
      $file_url  = $gss_urlname . '/' . $filename;

      $decoded = base64_decode($pdf_base64, true);
      // Fallback if data was raw binary
      if ($decoded === false) $decoded = $pdf_base64;

      if (file_put_contents($file_path, $decoded) === false) {
         wc_get_logger()->error("Failed to save PDF for Order $order_id", ['source' => 'gosweetspot']);
         return false;
      }

      return ['path' => $file_path, 'url' => $file_url];
   }

   public static function email_label_to_admin($order_id, $file_info)
   {
      $order = wc_get_order($order_id);
      $to = get_option('admin_email');
      $subject = 'Shipping Label: Order #' . $order_id;

      $message = "A label has been generated for Order #{$order_id}.\n";
      $message .= "URL: " . $file_info['url'] . "\n";

      wp_mail($to, $subject, $message, [], [$file_info['path']]);
   }
}

# WooCommerce GoSweetSpot Integration Plugin

A powerful WooCommerce shipping plugin that integrates with the **GoSweetSpot API** to provide dynamic shipping rate calculations and automated label generation. Includes intelligent freight detection for heavy and multi-item orders.

## Features

- **Dynamic Shipping Rates**: Real-time shipping calculations from GoSweetSpot API based on package weight, dimensions, and destination
- **Freight Detection**: Automatically identifies heavy items (>25kg) and multi-item orders for manual freight calculation
- **Custom Checkout UI**: Interactive shipping method selection interface with live rate displays
- **Automatic Label Generation**: Scheduled cron job to generate and email shipping labels
- **Session Management**: Persistent shipping selection across cart and checkout
- **Unit Conversion**: Automatic conversion between different weight and dimension units
- **Admin Settings**: Centralized configuration page for API credentials and sender information
- **Comprehensive Logging**: Built-in logging for debugging API calls and errors

## Requirements

- WordPress 5.0 or higher
- WooCommerce 4.0 or higher
- PHP 7.4 or higher
- Active GoSweetSpot API account
- GoSweetSpot API credentials (API Key and Site ID)

## Installation

1. Download or clone this plugin into your WordPress `/wp-content/plugins/` directory
2. Activate the plugin through the WordPress admin panel
3. Navigate to **WooCommerce → GoSweetSpot** to configure your settings
4. Enter your GoSweetSpot API credentials and sender information

## Configuration

### Admin Settings

Access settings at: **WooCommerce → GoSweetSpot Settings**

**Required Fields:**
- **API Key**: Your GoSweetSpot API access key
- **Site ID**: Your GoSweetSpot site identifier
- **Sender Name**: Your business or store name
- **Sender Address**: Street address of origin
- **Sender Suburb**: Suburb/area of origin
- **Sender City**: City of origin
- **Sender Postcode**: Postal code of origin
- **Sender Country**: Country code (default: NZ)

### Shipping Zone Setup

1. Go to **WooCommerce → Settings → Shipping → Shipping Zones**
2. Create or edit a shipping zone
3. Add the "GoSweetSpot Shipping" method to your zone(s)
4. Set the method title if desired (default: "GoSweetSpot Shipping")

## How It Works

### Standard Shipping Flow

1. **Customer adds items to cart** and proceeds to checkout
2. **Customer enters delivery address**
3. **Customer clicks "Select Shipping Options"** button
4. **Plugin queries GoSweetSpot API** for available rates based on:
   - Package weight and dimensions
   - Destination address
   - All items in cart
5. **Available shipping methods display** with costs and delivery times
6. **Customer selects preferred method** and confirms
7. **Order total updates** with selected shipping cost
8. **Upon order completion**, shipping label is automatically generated via cron job

### Freight Orders (Heavy/Multiple Items)

Orders are automatically classified as "Freight" if:
- **Multiple items**: Cart contains more than 1 item, OR
- **Heavy items**: Any product weighs more than 25kg

For freight orders:
- Standard rate calculation is skipped
- Customer sees informational message about manual freight calculation
- Emails are sent to both customer and admin
- Manual freight quote is required before order processing
- No automatic label generation

## File Structure

```
gss-settings/
├── README.md                    # This file
├── gss-settings.php             # Main plugin file
├── checkout.js                  # Frontend rate selection logic
├── checkout.css                 # Checkout UI styling
└── includes/
    └── class-gss-api.php        # API helper classes
        ├── GSS_Helper           # Unit conversion & freight logic
        ├── GSS_API              # API request handling
        └── GSS_Label_Manager    # Label file management
```

## API Integration

### Endpoints Used

- **`rates`**: Calculate shipping rates for a given origin/destination/packages
- **`shipments`**: Generate shipping labels (with future enhancement)

### API Request Example

```json
{
  "origin": {
    "name": "Store Name",
    "address": {
      "streetaddress": "123 Main St",
      "suburb": "Downtown",
      "city": "Auckland",
      "postcode": "1010",
      "countrycode": "NZ"
    }
  },
  "destination": {
    "name": "Customer Name",
    "address": {
      "streetaddress": "456 Oak Ave",
      "suburb": "Suburb",
      "city": "Wellington",
      "postcode": "6011",
      "countrycode": "NZ"
    }
  },
  "packages": [
    {
      "name": "Product Name",
      "kg": 2.5,
      "length": 30,
      "width": 20,
      "height": 15
    }
  ]
}
```

## Hooks & Actions

### Custom Hooks

- **`gss_generate_label_event`**: Fires when label generation is scheduled
  - Parameters: `$order_id`, `$courier_id`

### WooCommerce Hooks Used

- `woocommerce_loaded`: Initialize plugin after WooCommerce loads
- `woocommerce_shipping_methods`: Register shipping method
- `woocommerce_review_order_after_shipping`: Render shipping selection UI
- `wp_enqueue_scripts`: Load checkout assets
- `woocommerce_checkout_update_order_meta`: Save shipping selection to order
- `woocommerce_checkout_order_processed`: Process freight logic

### AJAX Actions

- `wp_ajax_gss_calculate_shipping`: Calculate and return available rates
- `wp_ajax_gss_save_shipping_session`: Save selected rate to session
- (Both include `nopriv` variants for non-logged-in users)

## Unit Conversion

The plugin automatically converts product dimensions and weights to the API's required units:

**Weight Conversions (to kg):**
- Grams (g) → 0.001
- Kilograms (kg) → 1.0
- Pounds (lb) → 0.45359237
- Ounces (oz) → 0.0283495
- Milligrams (mg) → 0.000001

**Dimension Conversions (to cm):**
- Millimeters (mm) → 0.1
- Centimeters (cm) → 1.0
- Inches (in) → 2.54
- Meters (m) → 100.0

Conversions are based on WooCommerce store settings.

## Troubleshooting

### No shipping rates appear

1. Verify API credentials are correct in GoSweetSpot settings
2. Ensure all required fields (address, city, postcode) are filled on checkout
3. Check WooCommerce logs: **WooCommerce → Logs → gosweetspot**
4. Confirm destination country is supported by your GoSweetSpot account

### Freight detection not working

- Verify weight units are correctly configured in WooCommerce (WooCommerce → Settings → Products)
- Check product weight values are set correctly
- Review freight threshold (currently 25kg) in `class-gss-api.php` `GSS_Helper::is_freight_order()`

### Labels not generating

- Ensure WordPress cron is properly configured (check with `wp cron test`)
- Verify file permissions on `/wp-content/uploads/gss-labels/` directory
- Check WooCommerce logs for cron execution errors

### Session issues

- Confirm WooCommerce sessions are enabled
- Check for session storage issues (redis, memcached, etc.)
- Verify user's browser allows cookies

## Logging

All API calls and errors are logged to:
**WooCommerce → Status → Logs → `gosweetspot-*.log`**

Access logs via WordPress admin or direct file access in `/wp-content/uploads/wc-logs/`

## Security

- All AJAX requests are protected with nonce verification
- API calls use HTTPS
- Sensitive data (API keys) stored via WordPress `get_option()`
- User input is sanitized before API requests
- Output is escaped in HTML

## Future Enhancements

- [ ] Label PDF storage and retrieval
- [ ] Tracking number integration
- [ ] Multi-address support
- [ ] Rate caching to reduce API calls
- [ ] Custom freight threshold configuration
- [ ] Integration with order tracking notifications

## Support

For issues or feature requests, please contact your plugin provider or submit a support ticket.

## License

Proprietary - DWS

## Changelog

### Version 1.1
- Improved freight logic for multiple items and heavy packages
- Enhanced checkout UI with better rate selection
- Added comprehensive logging for debugging
- Optimized API request handling
- Added automatic session cleanup on cart changes

### Version 1.0
- Initial release
- Basic rate calculation
- Freight detection
- Admin settings page
- AJAX shipping calculation

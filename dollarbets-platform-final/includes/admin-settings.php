<?php
if (!defined('ABSPATH')) exit;

/**
 * Admin Settings for DollarBets Platform
 */

class DollarBets_Admin_Settings {
    
    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
    }
    
    public function add_admin_menu() {
        add_options_page(
            'DollarBets Settings',
            'DollarBets',
            'manage_options',
            'dollarbets-settings',
            [$this, 'settings_page']
        );
    }
    
    public function register_settings() {
        // Stripe Settings
        register_setting('dollarbets_settings', 'dollarbets_stripe_publishable_key');
        register_setting('dollarbets_settings', 'dollarbets_stripe_secret_key');
        
        // PayPal Settings
        register_setting('dollarbets_settings', 'dollarbets_paypal_client_id');
        register_setting('dollarbets_settings', 'dollarbets_paypal_secret');
        register_setting('dollarbets_settings', 'dollarbets_paypal_sandbox');
        
        // General Settings
        register_setting('dollarbets_settings', 'dollarbets_currency');
        register_setting('dollarbets_settings', 'dollarbets_default_packages');
    }
    
    public function settings_page() {
        if (isset($_POST['submit'])) {
            // Save settings
            update_option('dollarbets_stripe_publishable_key', sanitize_text_field($_POST['stripe_publishable_key']));
            update_option('dollarbets_stripe_secret_key', sanitize_text_field($_POST['stripe_secret_key']));
            update_option('dollarbets_paypal_client_id', sanitize_text_field($_POST['paypal_client_id']));
            update_option('dollarbets_paypal_secret', sanitize_text_field($_POST['paypal_secret']));
            update_option('dollarbets_paypal_sandbox', isset($_POST['paypal_sandbox']));
            update_option('dollarbets_currency', sanitize_text_field($_POST['currency']));
            update_option('dollarbets_default_packages', sanitize_textarea_field($_POST['default_packages']));
            
            echo '<div class="notice notice-success"><p>Settings saved successfully!</p></div>';
        }
        
        $stripe_publishable = get_option('dollarbets_stripe_publishable_key', '');
        $stripe_secret = get_option('dollarbets_stripe_secret_key', '');
        $paypal_client_id = get_option('dollarbets_paypal_client_id', '');
        $paypal_secret = get_option('dollarbets_paypal_secret', '');
        $paypal_sandbox = get_option('dollarbets_paypal_sandbox', true);
        $currency = get_option('dollarbets_currency', 'USD');
        $default_packages = get_option('dollarbets_default_packages', '500:5,1000:9,2500:20,5000:35');
        ?>
        
        <div class="wrap">
            <h1>DollarBets Platform Settings</h1>
            
            <form method="post" action="">
                <?php wp_nonce_field('dollarbets_settings_nonce'); ?>
                
                <h2>Payment Gateway Settings</h2>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Currency</th>
                        <td>
                            <select name="currency">
                                <option value="USD" <?php selected($currency, 'USD'); ?>>USD - US Dollar</option>
                                <option value="EUR" <?php selected($currency, 'EUR'); ?>>EUR - Euro</option>
                                <option value="GBP" <?php selected($currency, 'GBP'); ?>>GBP - British Pound</option>
                                <option value="CAD" <?php selected($currency, 'CAD'); ?>>CAD - Canadian Dollar</option>
                                <option value="AUD" <?php selected($currency, 'AUD'); ?>>AUD - Australian Dollar</option>
                            </select>
                        </td>
                    </tr>
                </table>
                
                <h3>Stripe Configuration</h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">Stripe Publishable Key</th>
                        <td>
                            <input type="text" name="stripe_publishable_key" value="<?php echo esc_attr($stripe_publishable); ?>" class="regular-text" />
                            <p class="description">Your Stripe publishable key (starts with pk_)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Stripe Secret Key</th>
                        <td>
                            <input type="password" name="stripe_secret_key" value="<?php echo esc_attr($stripe_secret); ?>" class="regular-text" />
                            <p class="description">Your Stripe secret key (starts with sk_)</p>
                        </td>
                    </tr>
                </table>
                
                <h3>PayPal Configuration</h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">PayPal Client ID</th>
                        <td>
                            <input type="text" name="paypal_client_id" value="<?php echo esc_attr($paypal_client_id); ?>" class="regular-text" />
                            <p class="description">Your PayPal application client ID</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">PayPal Secret</th>
                        <td>
                            <input type="password" name="paypal_secret" value="<?php echo esc_attr($paypal_secret); ?>" class="regular-text" />
                            <p class="description">Your PayPal application secret</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">PayPal Sandbox Mode</th>
                        <td>
                            <label>
                                <input type="checkbox" name="paypal_sandbox" <?php checked($paypal_sandbox); ?> />
                                Enable sandbox mode for testing
                            </label>
                        </td>
                    </tr>
                </table>
                
                <h3>BetCoins Packages</h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">Default Packages</th>
                        <td>
                            <textarea name="default_packages" rows="4" cols="50"><?php echo esc_textarea($default_packages); ?></textarea>
                            <p class="description">
                                Format: betcoins:price,betcoins:price<br>
                                Example: 500:5,1000:9,2500:20,5000:35<br>
                                This creates packages of 500 BetCoins for $5, 1000 for $9, etc.
                            </p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
            
            <hr>
            
            <h2>Shortcodes</h2>
            <div class="dollarbets-shortcodes">
                <h3>Purchase Form</h3>
                <p><code>[dollarbets_purchase]</code> - Display the full purchase form with all packages</p>
                <p><code>[dollarbets_purchase packages="1000:10,2000:18" title="Buy BetCoins" description="Get more coins!"]</code> - Custom packages and text</p>
                
                <h3>Purchase Button</h3>
                <p><code>[dollarbets_purchase_button betcoins="1000" price="9.99"]</code> - Single purchase button</p>
                <p><code>[dollarbets_purchase_button betcoins="500" price="5.00" text="Get {betcoins} coins for ${price}"]</code> - Custom button text</p>
                
                <h3>Header</h3>
                <p><code>[dollarbets_header]</code> - Full header with user info, balance, and dark mode toggle</p>
                <p><code>[dollarbets_simple_header]</code> - Simple header with balance and toggle only</p>
            </div>
            
            <style>
            .dollarbets-shortcodes {
                background: #f9f9f9;
                padding: 20px;
                border-radius: 5px;
                margin-top: 20px;
            }
            .dollarbets-shortcodes h3 {
                margin-top: 0;
                color: #333;
            }
            .dollarbets-shortcodes code {
                background: #fff;
                padding: 2px 6px;
                border-radius: 3px;
                font-family: Consolas, Monaco, monospace;
            }
            </style>
        </div>
        <?php
    }
}

// Initialize admin settings
if (function_exists('is_admin') && is_admin()) {
    new DollarBets_Admin_Settings();
}


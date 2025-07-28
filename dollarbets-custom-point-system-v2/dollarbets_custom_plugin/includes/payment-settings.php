<?php
if (!defined('ABSPATH')) exit;

/**
 * Payment Settings Admin Page
 * Configure Stripe and PayPal for BetCoins purchases
 */

class DollarBets_Payment_Settings {
    
    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
    }
    
    /**
     * Add admin menu for payment settings
     */
    public function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=prediction',
            'Payment Settings',
            'Payment Settings',
            'manage_options',
            'dollarbets-payment-settings',
            [$this, 'render_admin_page']
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        // Stripe settings
        register_setting('dollarbets_payment_settings', 'dollarbets_stripe_publishable_key');
        register_setting('dollarbets_payment_settings', 'dollarbets_stripe_secret_key');
        register_setting('dollarbets_payment_settings', 'dollarbets_stripe_webhook_secret');
        
        // PayPal settings
        register_setting('dollarbets_payment_settings', 'dollarbets_paypal_client_id');
        register_setting('dollarbets_payment_settings', 'dollarbets_paypal_secret');
        register_setting('dollarbets_payment_settings', 'dollarbets_paypal_sandbox');
        
        // General settings
        register_setting('dollarbets_payment_settings', 'dollarbets_betcoin_rate');
        register_setting('dollarbets_payment_settings', 'dollarbets_payment_currency');
    }
    
    /**
     * Render admin page
     */
    public function render_admin_page() {
        if (isset($_POST['submit'])) {
            $this->save_settings();
        }
        
        $stripe_publishable = get_option('dollarbets_stripe_publishable_key', '');
        $stripe_secret = get_option('dollarbets_stripe_secret_key', '');
        $stripe_webhook = get_option('dollarbets_stripe_webhook_secret', '');
        
        $paypal_client_id = get_option('dollarbets_paypal_client_id', '');
        $paypal_secret = get_option('dollarbets_paypal_secret', '');
        $paypal_sandbox = get_option('dollarbets_paypal_sandbox', true);
        
        $betcoin_rate = get_option('dollarbets_betcoin_rate', 100);
        $currency = get_option('dollarbets_payment_currency', 'USD');
        
        ?>
        <div class="wrap">
            <h1>Payment Settings</h1>
            
            <div class="notice notice-info">
                <p><strong>Payment Gateway Configuration:</strong> Configure Stripe and/or PayPal to enable BetCoins purchases. At least one payment method must be configured for the purchase functionality to work.</p>
            </div>
            
            <form method="post" action="">
                <?php wp_nonce_field('dollarbets_payment_settings', 'dollarbets_payment_nonce'); ?>
                
                <div class="payment-settings-tabs">
                    <nav class="nav-tab-wrapper">
                        <a href="#stripe" class="nav-tab nav-tab-active">Stripe</a>
                        <a href="#paypal" class="nav-tab">PayPal</a>
                        <a href="#general" class="nav-tab">General</a>
                    </nav>
                    
                    <div id="stripe" class="tab-content active">
                        <h2>Stripe Configuration</h2>
                        <p>Get your Stripe API keys from <a href="https://dashboard.stripe.com/apikeys" target="_blank">Stripe Dashboard</a></p>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="stripe_publishable_key">Publishable Key</label>
                                </th>
                                <td>
                                    <input type="text" id="stripe_publishable_key" name="stripe_publishable_key" value="<?php echo esc_attr($stripe_publishable); ?>" class="regular-text" placeholder="pk_test_...">
                                    <p class="description">Your Stripe publishable key (starts with pk_)</p>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <label for="stripe_secret_key">Secret Key</label>
                                </th>
                                <td>
                                    <input type="password" id="stripe_secret_key" name="stripe_secret_key" value="<?php echo esc_attr($stripe_secret); ?>" class="regular-text" placeholder="sk_test_...">
                                    <p class="description">Your Stripe secret key (starts with sk_)</p>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <label for="stripe_webhook_secret">Webhook Secret</label>
                                </th>
                                <td>
                                    <input type="password" id="stripe_webhook_secret" name="stripe_webhook_secret" value="<?php echo esc_attr($stripe_webhook); ?>" class="regular-text" placeholder="whsec_...">
                                    <p class="description">Optional: Webhook endpoint secret for enhanced security</p>
                                </td>
                            </tr>
                        </table>
                        
                        <div class="stripe-test">
                            <button type="button" id="test-stripe" class="button">Test Stripe Connection</button>
                            <span id="stripe-status"></span>
                        </div>
                    </div>
                    
                    <div id="paypal" class="tab-content">
                        <h2>PayPal Configuration</h2>
                        <p>Get your PayPal API credentials from <a href="https://developer.paypal.com/developer/applications/" target="_blank">PayPal Developer</a></p>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="paypal_client_id">Client ID</label>
                                </th>
                                <td>
                                    <input type="text" id="paypal_client_id" name="paypal_client_id" value="<?php echo esc_attr($paypal_client_id); ?>" class="regular-text">
                                    <p class="description">Your PayPal application client ID</p>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <label for="paypal_secret">Client Secret</label>
                                </th>
                                <td>
                                    <input type="password" id="paypal_secret" name="paypal_secret" value="<?php echo esc_attr($paypal_secret); ?>" class="regular-text">
                                    <p class="description">Your PayPal application client secret</p>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <label for="paypal_sandbox">Sandbox Mode</label>
                                </th>
                                <td>
                                    <label>
                                        <input type="checkbox" id="paypal_sandbox" name="paypal_sandbox" value="1" <?php checked($paypal_sandbox, true); ?>>
                                        Use PayPal Sandbox (for testing)
                                    </label>
                                    <p class="description">Uncheck this for live payments</p>
                                </td>
                            </tr>
                        </table>
                        
                        <div class="paypal-test">
                            <button type="button" id="test-paypal" class="button">Test PayPal Connection</button>
                            <span id="paypal-status"></span>
                        </div>
                    </div>
                    
                    <div id="general" class="tab-content">
                        <h2>General Settings</h2>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="betcoin_rate">BetCoin Exchange Rate</label>
                                </th>
                                <td>
                                    <input type="number" id="betcoin_rate" name="betcoin_rate" value="<?php echo esc_attr($betcoin_rate); ?>" min="1" step="1">
                                    <span>BetCoins = $1.00</span>
                                    <p class="description">How many BetCoins equal $1.00 (default: 100)</p>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <label for="payment_currency">Currency</label>
                                </th>
                                <td>
                                    <select id="payment_currency" name="payment_currency">
                                        <option value="USD" <?php selected($currency, 'USD'); ?>>USD - US Dollar</option>
                                        <option value="EUR" <?php selected($currency, 'EUR'); ?>>EUR - Euro</option>
                                        <option value="GBP" <?php selected($currency, 'GBP'); ?>>GBP - British Pound</option>
                                        <option value="CAD" <?php selected($currency, 'CAD'); ?>>CAD - Canadian Dollar</option>
                                        <option value="AUD" <?php selected($currency, 'AUD'); ?>>AUD - Australian Dollar</option>
                                    </select>
                                    <p class="description">Currency for payments</p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <p class="submit">
                    <input type="submit" name="submit" class="button-primary" value="Save Settings">
                </p>
            </form>
            
            <div class="payment-status-overview">
                <h3>Payment Gateway Status</h3>
                <div class="status-grid">
                    <div class="status-card">
                        <h4>Stripe</h4>
                        <div class="status <?php echo !empty($stripe_publishable) && !empty($stripe_secret) ? 'configured' : 'not-configured'; ?>">
                            <?php echo !empty($stripe_publishable) && !empty($stripe_secret) ? '✅ Configured' : '❌ Not Configured'; ?>
                        </div>
                    </div>
                    
                    <div class="status-card">
                        <h4>PayPal</h4>
                        <div class="status <?php echo !empty($paypal_client_id) && !empty($paypal_secret) ? 'configured' : 'not-configured'; ?>">
                            <?php echo !empty($paypal_client_id) && !empty($paypal_secret) ? '✅ Configured' : '❌ Not Configured'; ?>
                        </div>
                    </div>
                </div>
                
                <?php if (empty($stripe_publishable) && empty($stripe_secret) && empty($paypal_client_id) && empty($paypal_secret)): ?>
                <div class="notice notice-warning">
                    <p><strong>Warning:</strong> No payment gateways are configured. Users will not be able to purchase BetCoins until at least one payment method is set up.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <style>
        .payment-settings-tabs {
            margin-top: 20px;
        }
        
        .tab-content {
            display: none;
            padding: 20px 0;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .stripe-test, .paypal-test {
            margin-top: 20px;
            padding: 15px;
            background: #f9f9f9;
            border-radius: 5px;
        }
        
        .payment-status-overview {
            margin-top: 30px;
            padding: 20px;
            background: #f1f1f1;
            border-radius: 5px;
        }
        
        .status-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        
        .status-card {
            background: white;
            padding: 15px;
            border-radius: 5px;
            text-align: center;
        }
        
        .status-card h4 {
            margin: 0 0 10px 0;
        }
        
        .status.configured {
            color: #46b450;
            font-weight: bold;
        }
        
        .status.not-configured {
            color: #dc3232;
            font-weight: bold;
        }
        
        #stripe-status, #paypal-status {
            margin-left: 10px;
            font-weight: bold;
        }
        
        #stripe-status.success, #paypal-status.success {
            color: #46b450;
        }
        
        #stripe-status.error, #paypal-status.error {
            color: #dc3232;
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // Tab switching
            $('.nav-tab').click(function(e) {
                e.preventDefault();
                
                $('.nav-tab').removeClass('nav-tab-active');
                $('.tab-content').removeClass('active');
                
                $(this).addClass('nav-tab-active');
                $($(this).attr('href')).addClass('active');
            });
            
            // Test Stripe connection
            $('#test-stripe').click(function() {
                const publishableKey = $('#stripe_publishable_key').val();
                const secretKey = $('#stripe_secret_key').val();
                
                if (!publishableKey || !secretKey) {
                    $('#stripe-status').text('Please enter both keys first').removeClass('success').addClass('error');
                    return;
                }
                
                $(this).prop('disabled', true).text('Testing...');
                $('#stripe-status').text('Testing connection...');
                
                $.post(ajaxurl, {
                    action: 'dollarbets_test_stripe',
                    publishable_key: publishableKey,
                    secret_key: secretKey,
                    nonce: '<?php echo wp_create_nonce('dollarbets_test_stripe'); ?>'
                }, function(response) {
                    if (response.success) {
                        $('#stripe-status').text('✅ Connection successful').removeClass('error').addClass('success');
                    } else {
                        $('#stripe-status').text('❌ ' + response.data).removeClass('success').addClass('error');
                    }
                }).always(function() {
                    $('#test-stripe').prop('disabled', false).text('Test Stripe Connection');
                });
            });
            
            // Test PayPal connection
            $('#test-paypal').click(function() {
                const clientId = $('#paypal_client_id').val();
                const secret = $('#paypal_secret').val();
                const sandbox = $('#paypal_sandbox').is(':checked');
                
                if (!clientId || !secret) {
                    $('#paypal-status').text('Please enter both client ID and secret first').removeClass('success').addClass('error');
                    return;
                }
                
                $(this).prop('disabled', true).text('Testing...');
                $('#paypal-status').text('Testing connection...');
                
                $.post(ajaxurl, {
                    action: 'dollarbets_test_paypal',
                    client_id: clientId,
                    secret: secret,
                    sandbox: sandbox,
                    nonce: '<?php echo wp_create_nonce('dollarbets_test_paypal'); ?>'
                }, function(response) {
                    if (response.success) {
                        $('#paypal-status').text('✅ Connection successful').removeClass('error').addClass('success');
                    } else {
                        $('#paypal-status').text('❌ ' + response.data).removeClass('success').addClass('error');
                    }
                }).always(function() {
                    $('#test-paypal').prop('disabled', false).text('Test PayPal Connection');
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Save settings
     */
    private function save_settings() {
        if (!wp_verify_nonce($_POST['dollarbets_payment_nonce'], 'dollarbets_payment_settings') || !current_user_can('manage_options')) {
            wp_die('Security check failed');
        }
        
        // Save Stripe settings
        update_option('dollarbets_stripe_publishable_key', sanitize_text_field($_POST['stripe_publishable_key'] ?? ''));
        update_option('dollarbets_stripe_secret_key', sanitize_text_field($_POST['stripe_secret_key'] ?? ''));
        update_option('dollarbets_stripe_webhook_secret', sanitize_text_field($_POST['stripe_webhook_secret'] ?? ''));
        
        // Save PayPal settings
        update_option('dollarbets_paypal_client_id', sanitize_text_field($_POST['paypal_client_id'] ?? ''));
        update_option('dollarbets_paypal_secret', sanitize_text_field($_POST['paypal_secret'] ?? ''));
        update_option('dollarbets_paypal_sandbox', isset($_POST['paypal_sandbox']));
        
        // Save general settings
        update_option('dollarbets_betcoin_rate', absint($_POST['betcoin_rate'] ?? 100));
        update_option('dollarbets_payment_currency', sanitize_text_field($_POST['payment_currency'] ?? 'USD'));
        
        echo '<div class="notice notice-success"><p>Settings saved successfully!</p></div>';
    }
}

// Initialize payment settings
new DollarBets_Payment_Settings();

// Add AJAX handlers for testing connections
add_action('wp_ajax_dollarbets_test_stripe', function() {
    if (!wp_verify_nonce($_POST['nonce'], 'dollarbets_test_stripe') || !current_user_can('manage_options')) {
        wp_send_json_error('Security check failed');
    }
    
    $secret_key = sanitize_text_field($_POST['secret_key']);
    
    $response = wp_remote_get('https://api.stripe.com/v1/account', [
        'headers' => [
            'Authorization' => 'Bearer ' . $secret_key,
        ],
        'timeout' => 15
    ]);
    
    if (is_wp_error($response)) {
        wp_send_json_error($response->get_error_message());
    }
    
    $body = json_decode(wp_remote_retrieve_body($response), true);
    
    if (isset($body['id'])) {
        wp_send_json_success('Stripe connection successful');
    } else {
        wp_send_json_error($body['error']['message'] ?? 'Invalid Stripe credentials');
    }
});

add_action('wp_ajax_dollarbets_test_paypal', function() {
    if (!wp_verify_nonce($_POST['nonce'], 'dollarbets_test_paypal') || !current_user_can('manage_options')) {
        wp_send_json_error('Security check failed');
    }
    
    $client_id = sanitize_text_field($_POST['client_id']);
    $secret = sanitize_text_field($_POST['secret']);
    $sandbox = $_POST['sandbox'] === 'true';
    
    $api_url = $sandbox ? 'https://api.sandbox.paypal.com' : 'https://api.paypal.com';
    
    $response = wp_remote_post($api_url . '/v1/oauth2/token', [
        'headers' => [
            'Accept' => 'application/json',
            'Accept-Language' => 'en_US',
            'Authorization' => 'Basic ' . base64_encode($client_id . ':' . $secret),
        ],
        'body' => 'grant_type=client_credentials',
        'timeout' => 15
    ]);
    
    if (is_wp_error($response)) {
        wp_send_json_error($response->get_error_message());
    }
    
    $body = json_decode(wp_remote_retrieve_body($response), true);
    
    if (isset($body['access_token'])) {
        wp_send_json_success('PayPal connection successful');
    } else {
        wp_send_json_error($body['error_description'] ?? 'Invalid PayPal credentials');
    }
});


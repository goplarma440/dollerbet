<?php
if (!defined('ABSPATH')) exit;

/**
 * Payment Gateway Integration for BetCoins Purchase
 * Supports Stripe and PayPal for purchasing BetCoins
 * FIXED VERSION - Addresses popup and integration issues
 */

class DollarBets_Payment_Gateway_Fixed {
    
    public function __construct() {
        add_action('rest_api_init', [$this, 'register_payment_routes']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_payment_scripts']);
        add_action('wp_footer', [$this, 'add_payment_modal_html']);
    }
    
    /**
     * Register REST API routes for payment processing
     */
    public function register_payment_routes() {
        register_rest_route('dollarbets/v1', '/create-payment-intent', [
            'methods' => 'POST',
            'callback' => [$this, 'create_stripe_payment_intent'],
            'permission_callback' => function () {
                return is_user_logged_in();
            }
        ]);
        
        register_rest_route('dollarbets/v1', '/confirm-payment', [
            'methods' => 'POST',
            'callback' => [$this, 'confirm_payment'],
            'permission_callback' => function () {
                return is_user_logged_in();
            }
        ]);
        
        register_rest_route('dollarbets/v1', '/paypal-create-order', [
            'methods' => 'POST',
            'callback' => [$this, 'create_paypal_order'],
            'permission_callback' => function () {
                return is_user_logged_in();
            }
        ]);
        
        register_rest_route('dollarbets/v1', '/paypal-capture-order', [
            'methods' => 'POST',
            'callback' => [$this, 'capture_paypal_order'],
            'permission_callback' => function () {
                return is_user_logged_in();
            }
        ]);
    }
    
    /**
     * Enqueue payment scripts
     */
    public function enqueue_payment_scripts() {
        // Only enqueue on pages that might need payment functionality
        if (is_user_logged_in()) {
            $stripe_key = $this->get_stripe_publishable_key();
            $paypal_client_id = $this->get_paypal_client_id();
            
            // Enqueue Stripe if configured
            if ($stripe_key) {
                wp_enqueue_script('stripe-js', 'https://js.stripe.com/v3/', [], null, true);
            }
            
            // Enqueue PayPal if configured
            if ($paypal_client_id) {
                wp_enqueue_script('paypal-js', 'https://www.paypal.com/sdk/js?client-id=' . $paypal_client_id, [], null, true);
            }
            
            // Enqueue our payment script
            wp_enqueue_script(
                'dollarbets-payment',
                DOLLARBETS_URL . 'assets/js/payment-system.js',
                ['jquery'],
                '1.0.0',
                true
            );
            
            // Localize script with configuration
            wp_localize_script('dollarbets-payment', 'dollarbetsPayment', [
                'restUrl' => rest_url(),
                'nonce' => wp_create_nonce('wp_rest'),
                'stripeKey' => $stripe_key,
                'paypalClientId' => $paypal_client_id,
                'isConfigured' => !empty($stripe_key) || !empty($paypal_client_id)
            ]);
            
            // Enqueue payment styles
            wp_enqueue_style(
                'dollarbets-payment',
                DOLLARBETS_URL . 'assets/css/payment-system.css',
                [],
                '1.0.0'
            );
        }
    }
    
    /**
     * Add payment modal HTML to footer
     */
    public function add_payment_modal_html() {
        if (!is_user_logged_in()) return;
        
        $stripe_key = $this->get_stripe_publishable_key();
        $paypal_client_id = $this->get_paypal_client_id();
        
        if (!$stripe_key && !$paypal_client_id) return;
        
        ?>
        <!-- DollarBets Payment Modal -->
        <div id="dollarbets-payment-modal" class="dollarbets-modal" style="display: none;">
            <div class="dollarbets-modal-content">
                <span class="dollarbets-modal-close">&times;</span>
                <div class="dollarbets-modal-header">
                    <h3>Purchase BetCoins</h3>
                    <p>Buy <span id="modal-betcoins-amount">0</span> BetCoins for $<span id="modal-price-amount">0.00</span></p>
                </div>
                
                <div class="dollarbets-modal-body">
                    <div id="payment-method-selection" class="payment-methods">
                        <?php if ($stripe_key): ?>
                            <button id="select-stripe" class="payment-method-btn stripe-btn">
                                <span class="payment-icon">💳</span>
                                Pay with Credit Card
                            </button>
                        <?php endif; ?>
                        
                        <?php if ($paypal_client_id): ?>
                            <button id="select-paypal" class="payment-method-btn paypal-btn">
                                <span class="payment-icon">🅿️</span>
                                Pay with PayPal
                            </button>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($stripe_key): ?>
                        <div id="stripe-payment-form" class="payment-form" style="display: none;">
                            <div id="stripe-card-element" class="card-element"></div>
                            <div id="stripe-card-errors" class="payment-errors"></div>
                            <button id="stripe-submit-btn" class="submit-payment-btn" disabled>
                                Complete Payment
                            </button>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($paypal_client_id): ?>
                        <div id="paypal-payment-form" class="payment-form" style="display: none;">
                            <div id="paypal-button-container"></div>
                        </div>
                    <?php endif; ?>
                    
                    <div id="payment-status" class="payment-status" style="display: none;"></div>
                </div>
                
                <div class="dollarbets-modal-footer">
                    <button id="back-to-methods" class="back-btn" style="display: none;">
                        ← Back to Payment Methods
                    </button>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Create Stripe Payment Intent
     */
    public function create_stripe_payment_intent(WP_REST_Request $request) {
        $body = $request->get_json_params();
        $amount = absint($body['amount'] ?? 0);
        $betcoins = absint($body['betcoins'] ?? 0);
        
        if (!$amount || !$betcoins) {
            return new WP_Error('invalid_data', 'Amount and betcoins are required.', ['status' => 400]);
        }
        
        $stripe_secret = $this->get_stripe_secret_key();
        if (!$stripe_secret) {
            return new WP_Error('stripe_not_configured', 'Stripe is not configured.', ['status' => 500]);
        }
        
        try {
            $response = wp_remote_post('https://api.stripe.com/v1/payment_intents', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $stripe_secret,
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => http_build_query([
                    'amount' => $amount * 100, // Stripe uses cents
                    'currency' => 'usd',
                    'metadata' => [
                        'user_id' => get_current_user_id(),
                        'betcoins' => $betcoins,
                        'type' => 'betcoins_purchase'
                    ]
                ])
            ]);
            
            if (is_wp_error($response)) {
                return new WP_Error('stripe_error', 'Failed to create payment intent.', ['status' => 500]);
            }
            
            $body = json_decode(wp_remote_retrieve_body($response), true);
            
            if (wp_remote_retrieve_response_code($response) !== 200) {
                return new WP_Error('stripe_error', $body['error']['message'] ?? 'Unknown error', ['status' => 500]);
            }
            
            return [
                'success' => true,
                'client_secret' => $body['client_secret'],
                'payment_intent_id' => $body['id']
            ];
            
        } catch (Exception $e) {
            return new WP_Error('stripe_error', $e->getMessage(), ['status' => 500]);
        }
    }
    
    /**
     * Confirm payment and award BetCoins
     */
    public function confirm_payment(WP_REST_Request $request) {
        $body = $request->get_json_params();
        $payment_intent_id = sanitize_text_field($body['payment_intent_id'] ?? '');
        $betcoins = absint($body['betcoins'] ?? 0);
        
        if (!$payment_intent_id || !$betcoins) {
            return new WP_Error('invalid_data', 'Payment intent ID and betcoins are required.', ['status' => 400]);
        }
        
        $stripe_secret = $this->get_stripe_secret_key();
        if (!$stripe_secret) {
            return new WP_Error('stripe_not_configured', 'Stripe is not configured.', ['status' => 500]);
        }
        
        try {
            // Retrieve payment intent from Stripe
            $response = wp_remote_get('https://api.stripe.com/v1/payment_intents/' . $payment_intent_id, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $stripe_secret,
                ]
            ]);
            
            if (is_wp_error($response)) {
                return new WP_Error('stripe_error', 'Failed to retrieve payment intent.', ['status' => 500]);
            }
            
            $payment_intent = json_decode(wp_remote_retrieve_body($response), true);
            
            if ($payment_intent['status'] === 'succeeded') {
                $user_id = get_current_user_id();
                
                // Award BetCoins using custom point system
                $new_balance = db_award_points($user_id, $betcoins, 'betcoins', 'BetCoins purchase via Stripe');
                
                // Log transaction
                $this->log_transaction($user_id, 'stripe', $payment_intent_id, $betcoins, $payment_intent['amount'] / 100);
                
                return [
                    'success' => true,
                    'new_balance' => $new_balance,
                    'betcoins_awarded' => $betcoins
                ];
            } else {
                return new WP_Error('payment_failed', 'Payment was not successful.', ['status' => 400]);
            }
            
        } catch (Exception $e) {
            return new WP_Error('stripe_error', $e->getMessage(), ['status' => 500]);
        }
    }
    
    /**
     * Create PayPal Order
     */
    public function create_paypal_order(WP_REST_Request $request) {
        $body = $request->get_json_params();
        $amount = floatval($body['amount'] ?? 0);
        $betcoins = absint($body['betcoins'] ?? 0);
        
        if (!$amount || !$betcoins) {
            return new WP_Error('invalid_data', 'Amount and betcoins are required.', ['status' => 400]);
        }
        
        $paypal_client_id = $this->get_paypal_client_id();
        $paypal_secret = $this->get_paypal_secret();
        
        if (!$paypal_client_id || !$paypal_secret) {
            return new WP_Error('paypal_not_configured', 'PayPal is not configured.', ['status' => 500]);
        }
        
        try {
            // Get PayPal access token
            $access_token = $this->get_paypal_access_token();
            if (!$access_token) {
                return new WP_Error('paypal_error', 'Failed to get PayPal access token.', ['status' => 500]);
            }
            
            // Create order
            $order_data = [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'amount' => [
                        'currency_code' => 'USD',
                        'value' => number_format($amount, 2, '.', '')
                    ],
                    'description' => $betcoins . ' BetCoins Purchase'
                ]],
                'application_context' => [
                    'return_url' => home_url('/betcoins-purchase-success'),
                    'cancel_url' => home_url('/betcoins-purchase-cancel')
                ]
            ];
            
            $response = wp_remote_post($this->get_paypal_api_url() . '/v2/checkout/orders', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $access_token,
                ],
                'body' => json_encode($order_data)
            ]);
            
            if (is_wp_error($response)) {
                return new WP_Error('paypal_error', 'Failed to create PayPal order.', ['status' => 500]);
            }
            
            $order = json_decode(wp_remote_retrieve_body($response), true);
            
            if (wp_remote_retrieve_response_code($response) !== 201) {
                return new WP_Error('paypal_error', $order['message'] ?? 'Unknown error', ['status' => 500]);
            }
            
            // Store order details temporarily
            set_transient('dollarbets_paypal_order_' . $order['id'], [
                'user_id' => get_current_user_id(),
                'betcoins' => $betcoins,
                'amount' => $amount
            ], 3600); // 1 hour
            
            return [
                'success' => true,
                'order_id' => $order['id']
            ];
            
        } catch (Exception $e) {
            return new WP_Error('paypal_error', $e->getMessage(), ['status' => 500]);
        }
    }
    
    /**
     * Capture PayPal Order
     */
    public function capture_paypal_order(WP_REST_Request $request) {
        $body = $request->get_json_params();
        $order_id = sanitize_text_field($body['order_id'] ?? '');
        
        if (!$order_id) {
            return new WP_Error('invalid_data', 'Order ID is required.', ['status' => 400]);
        }
        
        // Get stored order details
        $order_details = get_transient('dollarbets_paypal_order_' . $order_id);
        if (!$order_details) {
            return new WP_Error('order_not_found', 'Order details not found.', ['status' => 404]);
        }
        
        try {
            $access_token = $this->get_paypal_access_token();
            if (!$access_token) {
                return new WP_Error('paypal_error', 'Failed to get PayPal access token.', ['status' => 500]);
            }
            
            // Capture order
            $response = wp_remote_post($this->get_paypal_api_url() . '/v2/checkout/orders/' . $order_id . '/capture', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $access_token,
                ],
                'body' => '{}'
            ]);
            
            if (is_wp_error($response)) {
                return new WP_Error('paypal_error', 'Failed to capture PayPal order.', ['status' => 500]);
            }
            
            $capture_result = json_decode(wp_remote_retrieve_body($response), true);
            
            if (wp_remote_retrieve_response_code($response) === 201 && $capture_result['status'] === 'COMPLETED') {
                $user_id = $order_details['user_id'];
                $betcoins = $order_details['betcoins'];
                
                // Award BetCoins using custom point system
                $new_balance = db_award_points($user_id, $betcoins, 'betcoins', 'BetCoins purchase via PayPal');
                
                // Log transaction
                $this->log_transaction($user_id, 'paypal', $order_id, $betcoins, $order_details['amount']);
                
                // Clean up transient
                delete_transient('dollarbets_paypal_order_' . $order_id);
                
                return [
                    'success' => true,
                    'new_balance' => $new_balance,
                    'betcoins_awarded' => $betcoins
                ];
            } else {
                return new WP_Error('payment_failed', 'PayPal payment was not successful.', ['status' => 400]);
            }
            
        } catch (Exception $e) {
            return new WP_Error('paypal_error', $e->getMessage(), ['status' => 500]);
        }
    }
    
    /**
     * Log transaction to database
     */
    private function log_transaction($user_id, $gateway, $transaction_id, $betcoins, $amount) {
        $transaction = [
            'user_id' => $user_id,
            'gateway' => $gateway,
            'transaction_id' => $transaction_id,
            'betcoins' => $betcoins,
            'amount' => $amount,
            'timestamp' => current_time('mysql'),
            'status' => 'completed'
        ];
        
        $history = get_user_meta($user_id, 'db_purchase_history', true);
        if (!is_array($history)) $history = [];
        $history[] = $transaction;
        
        update_user_meta($user_id, 'db_purchase_history', $history);
    }
    
    /**
     * Get PayPal access token
     */
    private function get_paypal_access_token() {
        $client_id = $this->get_paypal_client_id();
        $secret = $this->get_paypal_secret();
        
        if (!$client_id || !$secret) {
            return false;
        }
        
        $response = wp_remote_post($this->get_paypal_api_url() . '/v1/oauth2/token', [
            'headers' => [
                'Accept' => 'application/json',
                'Accept-Language' => 'en_US',
                'Authorization' => 'Basic ' . base64_encode($client_id . ':' . $secret),
            ],
            'body' => 'grant_type=client_credentials'
        ]);
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body['access_token'] ?? false;
    }
    
    /**
     * Get configuration values
     */
    private function get_stripe_secret_key() {
        return get_option('dollarbets_stripe_secret_key', '');
    }
    
    private function get_stripe_publishable_key() {
        return get_option('dollarbets_stripe_publishable_key', '');
    }
    
    private function get_paypal_client_id() {
        return get_option('dollarbets_paypal_client_id', '');
    }
    
    private function get_paypal_secret() {
        return get_option('dollarbets_paypal_secret', '');
    }
    
    private function get_paypal_api_url() {
        $sandbox = get_option('dollarbets_paypal_sandbox', true);
        return $sandbox ? 'https://api.sandbox.paypal.com' : 'https://api.paypal.com';
    }
}

// Initialize fixed payment gateway
new DollarBets_Payment_Gateway_Fixed();

/**
 * Shortcode for BetCoins purchase form
 */
function dollarbets_purchase_form_shortcode($atts) {
    $atts = shortcode_atts([
        'packages' => '500:5,1000:9,2500:20,5000:35',
        'title' => 'Purchase BetCoins',
        'description' => 'Buy BetCoins to place bets on predictions!'
    ], $atts);
    
    if (!is_user_logged_in()) {
        return '<p>Please log in to purchase BetCoins.</p>';
    }
    
    // Parse packages
    $packages = [];
    foreach (explode(',', $atts['packages']) as $package) {
        list($betcoins, $price) = explode(':', $package);
        $packages[] = [
            'betcoins' => intval($betcoins),
            'price' => floatval($price)
        ];
    }
    
    ob_start();
    ?>
    <div class="dollarbets-purchase-form">
        <h3><?php echo esc_html($atts['title']); ?></h3>
        <p><?php echo esc_html($atts['description']); ?></p>
        
        <div class="purchase-packages">
            <?php foreach ($packages as $package): ?>
                <div class="package-option">
                    <div class="package-betcoins"><?php echo number_format($package['betcoins']); ?> BetCoins</div>
                    <div class="package-price">$<?php echo number_format($package['price'], 2); ?></div>
                    <button class="purchase-btn" onclick="initiatePurchase(<?php echo $package['betcoins']; ?>, <?php echo $package['price']; ?>)">
                        Purchase
                    </button>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('dollarbets_purchase', 'dollarbets_purchase_form_shortcode');

/**
 * Simple purchase button shortcode
 */
function dollarbets_purchase_button_shortcode($atts) {
    $atts = shortcode_atts([
        'betcoins' => '1000',
        'price' => '9.99',
        'text' => 'Buy {betcoins} BetCoins for ${price}'
    ], $atts);
    
    if (!is_user_logged_in()) {
        return '<p>Please log in to purchase BetCoins.</p>';
    }
    
    $button_text = str_replace(['{betcoins}', '{price}'], [number_format($atts['betcoins']), $atts['price']], $atts['text']);
    
    return sprintf(
        '<button class="dollarbets-purchase-btn" onclick="initiatePurchase(%d, %s)">%s</button>',
        intval($atts['betcoins']),
        floatval($atts['price']),
        esc_html($button_text)
    );
}
add_shortcode('dollarbets_purchase_button', 'dollarbets_purchase_button_shortcode');


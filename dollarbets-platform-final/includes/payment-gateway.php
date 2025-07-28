<?php
if (!defined('ABSPATH')) exit;

/**
 * Payment Gateway Integration for BetCoins Purchase
 * Supports Stripe and PayPal for purchasing BetCoins
 */

class DollarBets_Payment_Gateway {
    
    public function __construct() {
        add_action('rest_api_init', [$this, 'register_payment_routes']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_payment_scripts']);
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
        wp_enqueue_script('stripe-js', 'https://js.stripe.com/v3/', [], null, true);
        wp_enqueue_script('paypal-js', 'https://www.paypal.com/sdk/js?client-id=' . $this->get_paypal_client_id(), [], null, true);
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
                
                // Award BetCoins
                $new_balance = db_award_points_manual($user_id, $betcoins, 'betcoins', 'BetCoins purchase via Stripe');
                
                // Log transaction
                db_log_transaction($user_id, 'purchase', $betcoins, 'BetCoins purchase via Stripe', [
                    'gateway' => 'stripe',
                    'transaction_id' => $payment_intent_id,
                    'currency_amount' => $payment_intent['amount'] / 100,
                    'status' => 'completed'
                ]);
                
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
                
                // Award BetCoins
                $new_balance = db_award_points_manual($user_id, $betcoins, 'betcoins', 'BetCoins purchase via PayPal');
                
                // Log transaction
                db_log_transaction($user_id, 'purchase', $betcoins, 'BetCoins purchase via PayPal', [
                    'gateway' => 'paypal',
                    'transaction_id' => $order_id,
                    'currency_amount' => $order_details['amount'],
                    'status' => 'completed'
                ]);
                
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

// Initialize payment gateway
new DollarBets_Payment_Gateway();

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
                <div class="package-option" data-betcoins="<?php echo $package['betcoins']; ?>" data-price="<?php echo $package['price']; ?>">
                    <div class="package-betcoins"><?php echo number_format($package['betcoins']); ?> BetCoins</div>
                    <div class="package-price">$<?php echo number_format($package['price'], 2); ?></div>
                    <button class="purchase-btn" onclick="initiatePurchase(<?php echo $package['betcoins']; ?>, <?php echo $package['price']; ?>)">
                        Purchase
                    </button>
                </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Payment Modal -->
        <div id="payment-modal" class="payment-modal" style="display: none;">
            <div class="modal-content">
                <span class="close-modal">&times;</span>
                <h4>Purchase <span id="modal-betcoins"></span> BetCoins</h4>
                <p>Total: $<span id="modal-price"></span></p>
                
                <div class="payment-methods">
                    <button id="stripe-payment-btn" class="payment-method-btn">
                        Pay with Card (Stripe)
                    </button>
                    <button id="paypal-payment-btn" class="payment-method-btn">
                        Pay with PayPal
                    </button>
                </div>
                
                <div id="stripe-elements" style="display: none;">
                    <div id="card-element"></div>
                    <button id="submit-stripe" disabled>Complete Payment</button>
                </div>
                
                <div id="paypal-buttons" style="display: none;"></div>
                
                <div id="payment-status"></div>
            </div>
        </div>
    </div>
    
    <style>
    .dollarbets-purchase-form {
        max-width: 600px;
        margin: 0 auto;
        padding: 20px;
        border: 1px solid #ddd;
        border-radius: 8px;
        background: #fff;
    }
    
    .purchase-packages {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 15px;
        margin: 20px 0;
    }
    
    .package-option {
        text-align: center;
        padding: 20px;
        border: 2px solid #e0e0e0;
        border-radius: 8px;
        transition: all 0.3s ease;
    }
    
    .package-option:hover {
        border-color: #007cba;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,124,186,0.15);
    }
    
    .package-betcoins {
        font-size: 18px;
        font-weight: bold;
        color: #333;
        margin-bottom: 5px;
    }
    
    .package-price {
        font-size: 24px;
        font-weight: bold;
        color: #007cba;
        margin-bottom: 15px;
    }
    
    .purchase-btn {
        background: #007cba;
        color: white;
        border: none;
        padding: 10px 20px;
        border-radius: 5px;
        cursor: pointer;
        font-size: 14px;
        transition: background 0.3s ease;
    }
    
    .purchase-btn:hover {
        background: #005a87;
    }
    
    .payment-modal {
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0,0,0,0.5);
    }
    
    .modal-content {
        background-color: #fefefe;
        margin: 5% auto;
        padding: 20px;
        border-radius: 8px;
        width: 90%;
        max-width: 500px;
        position: relative;
    }
    
    .close-modal {
        color: #aaa;
        float: right;
        font-size: 28px;
        font-weight: bold;
        cursor: pointer;
        position: absolute;
        right: 15px;
        top: 10px;
    }
    
    .close-modal:hover {
        color: black;
    }
    
    .payment-methods {
        margin: 20px 0;
    }
    
    .payment-method-btn {
        display: block;
        width: 100%;
        padding: 12px;
        margin: 10px 0;
        background: #007cba;
        color: white;
        border: none;
        border-radius: 5px;
        cursor: pointer;
        font-size: 16px;
    }
    
    .payment-method-btn:hover {
        background: #005a87;
    }
    
    #card-element {
        padding: 12px;
        border: 1px solid #ccc;
        border-radius: 4px;
        margin: 10px 0;
    }
    
    #submit-stripe {
        background: #28a745;
        color: white;
        border: none;
        padding: 12px 24px;
        border-radius: 5px;
        cursor: pointer;
        font-size: 16px;
        width: 100%;
    }
    
    #submit-stripe:disabled {
        background: #6c757d;
        cursor: not-allowed;
    }
    
    #payment-status {
        margin-top: 15px;
        padding: 10px;
        border-radius: 4px;
        text-align: center;
    }
    
    .status-success {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }
    
    .status-error {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }
    
    /* Dark mode styles */
    body.dark-mode .dollarbets-purchase-form {
        background: #2c2c2c;
        border-color: #555;
        color: #fff;
    }
    
    body.dark-mode .package-option {
        background: #3c3c3c;
        border-color: #555;
        color: #fff;
    }
    
    body.dark-mode .package-option:hover {
        border-color: #007cba;
    }
    
    body.dark-mode .modal-content {
        background: #2c2c2c;
        color: #fff;
    }
    </style>
    
    <script>
    let stripe, elements, card;
    let currentBetcoins = 0;
    let currentPrice = 0;
    
    // Initialize Stripe
    if (typeof Stripe !== 'undefined') {
        stripe = Stripe('<?php echo get_option('dollarbets_stripe_publishable_key', ''); ?>');
        elements = stripe.elements();
    }
    
    window.initiatePurchase = function(betcoins, price) {
        // Check if payment gateways are configured
        const stripeKey = '<?php echo esc_js($this->get_stripe_publishable_key()); ?>';
        const paypalClientId = '<?php echo esc_js($this->get_paypal_client_id()); ?>';
        
        if (!stripeKey && !paypalClientId) {
            alert('Payment functionality is not available. Please contact support.');
            return;
        }
        
        currentBetcoins = betcoins;
        currentPrice = price;
        
        document.getElementById('modal-betcoins').textContent = betcoins.toLocaleString();
        document.getElementById('modal-price').textContent = price.toFixed(2);
        
        // Show/hide payment methods based on configuration
        const stripeBtn = document.getElementById('stripe-payment-btn');
        const paypalBtn = document.getElementById('paypal-payment-btn');
        
        if (stripeKey) {
            stripeBtn.style.display = 'block';
        } else {
            stripeBtn.style.display = 'none';
        }
        
        if (paypalClientId) {
            paypalBtn.style.display = 'block';
        } else {
            paypalBtn.style.display = 'none';
        }
        
        document.getElementById('payment-modal').style.display = 'block';
    }
    
    // Close modal
    document.querySelector('.close-modal').onclick = function() {
        document.getElementById('payment-modal').style.display = 'none';
        resetPaymentForm();
    }
    
    // Stripe payment
    document.getElementById('stripe-payment-btn').onclick = function() {
        document.querySelector('.payment-methods').style.display = 'none';
        document.getElementById('stripe-elements').style.display = 'block';
        
        if (!card) {
            card = elements.create('card');
            card.mount('#card-element');
            
            card.on('change', function(event) {
                document.getElementById('submit-stripe').disabled = !event.complete;
            });
        }
    }
    
    // PayPal payment
    document.getElementById('paypal-payment-btn').onclick = function() {
        document.querySelector('.payment-methods').style.display = 'none';
        document.getElementById('paypal-buttons').style.display = 'block';
        initPayPalButtons();
    }
    
    // Submit Stripe payment
    document.getElementById('submit-stripe').onclick = async function() {
        showPaymentStatus('Processing payment...', 'info');
        
        try {
            // Create payment intent
            const response = await fetch('/wp-json/dollarbets/v1/create-payment-intent', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>'
                },
                body: JSON.stringify({
                    amount: currentPrice,
                    betcoins: currentBetcoins
                })
            });
            
            const result = await response.json();
            
            if (!result.success) {
                throw new Error(result.message || 'Failed to create payment intent');
            }
            
            // Confirm payment
            const {error} = await stripe.confirmCardPayment(result.client_secret, {
                payment_method: {
                    card: card
                }
            });
            
            if (error) {
                throw new Error(error.message);
            }
            
            // Confirm with backend
            const confirmResponse = await fetch('/wp-json/dollarbets/v1/confirm-payment', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>'
                },
                body: JSON.stringify({
                    payment_intent_id: result.payment_intent_id,
                    betcoins: currentBetcoins
                })
            });
            
            const confirmResult = await confirmResponse.json();
            
            if (confirmResult.success) {
                showPaymentStatus(`Success! ${confirmResult.betcoins_awarded} BetCoins added to your account.`, 'success');
                setTimeout(() => {
                    location.reload();
                }, 2000);
            } else {
                throw new Error(confirmResult.message || 'Payment confirmation failed');
            }
            
        } catch (error) {
            showPaymentStatus('Error: ' + error.message, 'error');
        }
    }
    
    function initPayPalButtons() {
        if (typeof paypal === 'undefined') {
            showPaymentStatus('PayPal SDK not loaded', 'error');
            return;
        }
        
        paypal.Buttons({
            createOrder: async function(data, actions) {
                const response = await fetch('/wp-json/dollarbets/v1/paypal-create-order', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>'
                    },
                    body: JSON.stringify({
                        amount: currentPrice,
                        betcoins: currentBetcoins
                    })
                });
                
                const result = await response.json();
                
                if (!result.success) {
                    throw new Error(result.message || 'Failed to create PayPal order');
                }
                
                return result.order_id;
            },
            onApprove: async function(data, actions) {
                showPaymentStatus('Processing PayPal payment...', 'info');
                
                const response = await fetch('/wp-json/dollarbets/v1/paypal-capture-order', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>'
                    },
                    body: JSON.stringify({
                        order_id: data.orderID
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showPaymentStatus(`Success! ${result.betcoins_awarded} BetCoins added to your account.`, 'success');
                    setTimeout(() => {
                        location.reload();
                    }, 2000);
                } else {
                    showPaymentStatus('Error: ' + (result.message || 'Payment failed'), 'error');
                }
            },
            onError: function(err) {
                showPaymentStatus('PayPal Error: ' + err, 'error');
            }
        }).render('#paypal-buttons');
    }
    
    function showPaymentStatus(message, type) {
        const statusDiv = document.getElementById('payment-status');
        statusDiv.textContent = message;
        statusDiv.className = 'status-' + type;
        statusDiv.style.display = 'block';
    }
    
    function resetPaymentForm() {
        document.querySelector('.payment-methods').style.display = 'block';
        document.getElementById('stripe-elements').style.display = 'none';
        document.getElementById('paypal-buttons').style.display = 'none';
        document.getElementById('payment-status').style.display = 'none';
        
        if (card) {
            card.unmount();
            card = null;
        }
        
        // Clear PayPal buttons
        document.getElementById('paypal-buttons').innerHTML = '';
    }
    
    // Close modal when clicking outside
    window.onclick = function(event) {
        const modal = document.getElementById('payment-modal');
        if (event.target == modal) {
            modal.style.display = 'none';
            resetPaymentForm();
        }
    }
    </script>
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


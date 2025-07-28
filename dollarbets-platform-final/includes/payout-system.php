<?php
if (!defined('ABSPATH')) exit;

/**
 * BetCoin Payout System for DollarBets Platform
 * Handles conversion of BetCoins to real money and payout processing
 */

class DollarBets_Payout_System {
    
    private $exchange_rate = 0.01; // 100 BetCoins = $1.00
    private $processing_fee = 0.05; // 5% processing fee
    private $minimum_payout = 100; // Minimum 100 BetCoins
    
    public function __construct() {
        // Add admin menu for payout management
        add_action('admin_menu', [$this, 'add_admin_menu']);
        
        // Add REST API endpoints
        add_action('rest_api_init', [$this, 'register_payout_routes']);
        
        // Add admin AJAX handlers
        add_action('wp_ajax_process_payout', [$this, 'process_payout_admin']);
        add_action('wp_ajax_reject_payout', [$this, 'reject_payout_admin']);
        
        // Add shortcode for payout button
        add_shortcode('dollarbets_payout_button', [$this, 'payout_button_shortcode']);
        
        // Schedule payout processing
        add_action('init', [$this, 'schedule_payout_processing']);
        add_action('dollarbets_process_payouts', [$this, 'process_pending_payouts']);
        
        // Add user profile fields for payout methods
        add_action('show_user_profile', [$this, 'add_payout_profile_fields']);
        add_action('edit_user_profile', [$this, 'add_payout_profile_fields']);
        add_action('personal_options_update', [$this, 'save_payout_profile_fields']);
        add_action('edit_user_profile_update', [$this, 'save_payout_profile_fields']);
    }
    
    /**
     * Add admin menu for payout management
     */
    public function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=prediction',
            'Payout Requests',
            'Payout Requests',
            'manage_options',
            'dollarbets-payouts',
            [$this, 'render_admin_page']
        );
    }
    
    /**
     * Register REST API routes
     */
    public function register_payout_routes() {
        register_rest_route('dollarbets/v1', '/payout-request', [
            'methods' => 'POST',
            'callback' => [$this, 'create_payout_request'],
            'permission_callback' => function() {
                return is_user_logged_in();
            }
        ]);
        
        register_rest_route('dollarbets/v1', '/payout-history/(?P<user_id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_payout_history'],
            'permission_callback' => function($request) {
                $user_id = absint($request['user_id']);
                $current_user = get_current_user_id();
                return $current_user == $user_id || (function_exists('current_user_can') && current_user_can('manage_options'));
            }
        ]);
        
        register_rest_route('dollarbets/v1', '/payout-rates', [
            'methods' => 'GET',
            'callback' => [$this, 'get_payout_rates'],
            'permission_callback' => '__return_true'
        ]);
    }
    
    /**
     * Schedule payout processing
     */
    public function schedule_payout_processing() {
        if (!wp_next_scheduled('dollarbets_process_payouts')) {
            wp_schedule_event(time(), 'daily', 'dollarbets_process_payouts');
        }
    }
    
    /**
     * Create a payout request
     */
    public function create_payout_request(WP_REST_Request $request) {
        $body = $request->get_json_params();
        $user_id = get_current_user_id();
        $betcoins = absint($body['betcoins'] ?? 0);
        $method = sanitize_text_field($body['method'] ?? '');
        $account_details = sanitize_textarea_field($body['account_details'] ?? '');
        
        // Validation
        if ($betcoins < $this->minimum_payout) {
            return new WP_Error('invalid_amount', "Minimum payout is {$this->minimum_payout} BetCoins", ['status' => 400]);
        }
        
        if (!in_array($method, ['paypal', 'bank', 'crypto', 'check'])) {
            return new WP_Error('invalid_method', 'Invalid payout method', ['status' => 400]);
        }
        
        if (empty($account_details)) {
            return new WP_Error('missing_details', 'Account details are required', ['status' => 400]);
        }
        
        // Check user balance
        $current_balance = $this->get_user_betcoin_balance($user_id);
        if ($betcoins > $current_balance) {
            return new WP_Error('insufficient_funds', 'Insufficient BetCoins balance', ['status' => 400]);
        }
        
        // Check for pending payouts
        $pending_payouts = $this->get_user_pending_payouts($user_id);
        if (count($pending_payouts) > 0) {
            return new WP_Error('pending_payout', 'You have a pending payout request. Please wait for it to be processed.', ['status' => 400]);
        }
        
        // Calculate amounts
        $cash_amount = $betcoins * $this->exchange_rate;
        $fee = $cash_amount * $this->processing_fee;
        $final_amount = $cash_amount - $fee;
        
        // Create payout request
        $payout_id = $this->generate_payout_id();
        $payout_data = [
            'id' => $payout_id,
            'user_id' => $user_id,
            'betcoins' => $betcoins,
            'cash_amount' => $cash_amount,
            'processing_fee' => $fee,
            'final_amount' => $final_amount,
            'method' => $method,
            'account_details' => $account_details,
            'status' => 'pending',
            'created_date' => current_time('mysql'),
            'processed_date' => null,
            'transaction_id' => null,
            'notes' => ''
        ];
        
        // Deduct BetCoins from user balance (hold in escrow)
        $this->deduct_user_betcoins($user_id, $betcoins, "Payout request #{$payout_id}");
        
        // Save payout request
        $this->save_payout_request($payout_data);
        
        // Log transaction
        $this->log_payout_transaction($user_id, 'payout_requested', $payout_data);
        
        // Send notification to admin
        $this->notify_admin_new_payout($payout_data);
        
        return rest_ensure_response([
            'success' => true,
            'payout_id' => $payout_id,
            'message' => 'Payout request submitted successfully. You will receive your payment within 3-5 business days.',
            'details' => [
                'betcoins' => $betcoins,
                'cash_amount' => $cash_amount,
                'processing_fee' => $fee,
                'final_amount' => $final_amount,
                'method' => $method
            ]
        ]);
    }
    
    /**
     * Get payout history for a user
     */
    public function get_payout_history(WP_REST_Request $request) {
        $user_id = absint($request['user_id']);
        $payouts = $this->get_user_payouts($user_id);
        
        return rest_ensure_response([
            'success' => true,
            'payouts' => $payouts,
            'rates' => [
                'exchange_rate' => $this->exchange_rate,
                'processing_fee' => $this->processing_fee,
                'minimum_payout' => $this->minimum_payout
            ]
        ]);
    }
    
    /**
     * Get payout rates
     */
    public function get_payout_rates(WP_REST_Request $request) {
        return rest_ensure_response([
            'success' => true,
            'rates' => [
                'exchange_rate' => $this->exchange_rate,
                'processing_fee_percentage' => $this->processing_fee * 100,
                'minimum_payout' => $this->minimum_payout,
                'description' => "Exchange rate: {$this->minimum_payout} BetCoins = $" . ($this->minimum_payout * $this->exchange_rate) . " (before fees)"
            ]
        ]);
    }
    
    /**
     * Render admin page for payout management
     */
    public function render_admin_page() {
        $tab = $_GET['tab'] ?? 'pending';
        $payouts = $this->get_all_payouts($tab);
        
        ?>
        <div class="wrap">
            <h1>Payout Requests</h1>
            
            <nav class="nav-tab-wrapper">
                <a href="?post_type=prediction&page=dollarbets-payouts&tab=pending" class="nav-tab <?php echo $tab === 'pending' ? 'nav-tab-active' : ''; ?>">
                    Pending (<?php echo count($this->get_all_payouts('pending')); ?>)
                </a>
                <a href="?post_type=prediction&page=dollarbets-payouts&tab=processed" class="nav-tab <?php echo $tab === 'processed' ? 'nav-tab-active' : ''; ?>">
                    Processed
                </a>
                <a href="?post_type=prediction&page=dollarbets-payouts&tab=rejected" class="nav-tab <?php echo $tab === 'rejected' ? 'nav-tab-active' : ''; ?>">
                    Rejected
                </a>
                <a href="?post_type=prediction&page=dollarbets-payouts&tab=settings" class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    Settings
                </a>
            </nav>
            
            <div class="tab-content">
                <?php if ($tab === 'settings'): ?>
                    <?php $this->render_settings_tab(); ?>
                <?php else: ?>
                    <?php $this->render_payouts_table($payouts, $tab); ?>
                <?php endif; ?>
            </div>
        </div>
        
        <style>
        .payout-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .payout-table th,
        .payout-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        
        .payout-table th {
            background-color: #f9f9f9;
            font-weight: 600;
        }
        
        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-processed {
            background: #d4edda;
            color: #155724;
        }
        
        .status-rejected {
            background: #f8d7da;
            color: #721c24;
        }
        
        .action-buttons {
            display: flex;
            gap: 5px;
        }
        
        .btn-process {
            background: #28a745;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
        }
        
        .btn-reject {
            background: #dc3545;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
        }
        
        .payout-details {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 4px;
            margin: 5px 0;
            font-size: 12px;
        }
        </style>
        
        <script>
        function processPayout(payoutId) {
            if (!confirm('Are you sure you want to process this payout? This action cannot be undone.')) {
                return;
            }
            
            const data = new FormData();
            data.append('action', 'process_payout');
            data.append('payout_id', payoutId);
            data.append('nonce', '<?php echo wp_create_nonce('payout_admin'); ?>');
            
            fetch(ajaxurl, {
                method: 'POST',
                body: data
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    alert('Payout processed successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + result.data);
                }
            })
            .catch(error => {
                alert('Network error: ' + error.message);
            });
        }
        
        function rejectPayout(payoutId) {
            const reason = prompt('Please enter a reason for rejection:');
            if (!reason) return;
            
            const data = new FormData();
            data.append('action', 'reject_payout');
            data.append('payout_id', payoutId);
            data.append('reason', reason);
            data.append('nonce', '<?php echo wp_create_nonce('payout_admin'); ?>');
            
            fetch(ajaxurl, {
                method: 'POST',
                body: data
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    alert('Payout rejected successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + result.data);
                }
            })
            .catch(error => {
                alert('Network error: ' + error.message);
            });
        }
        </script>
        <?php
    }
    
    /**
     * Render payouts table
     */
    private function render_payouts_table($payouts, $status) {
        if (empty($payouts)) {
            echo '<p>No ' . $status . ' payouts found.</p>';
            return;
        }
        
        ?>
        <table class="payout-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>User</th>
                    <th>BetCoins</th>
                    <th>Cash Amount</th>
                    <th>Final Amount</th>
                    <th>Method</th>
                    <th>Date</th>
                    <th>Status</th>
                    <?php if ($status === 'pending'): ?>
                    <th>Actions</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($payouts as $payout): ?>
                <tr>
                    <td><?php echo esc_html($payout['id']); ?></td>
                    <td>
                        <?php 
                        $user = get_user_by('id', $payout['user_id']);
                        echo $user ? esc_html($user->display_name) : 'Unknown User';
                        ?>
                    </td>
                    <td><?php echo number_format($payout['betcoins']); ?></td>
                    <td>$<?php echo number_format($payout['cash_amount'], 2); ?></td>
                    <td>$<?php echo number_format($payout['final_amount'], 2); ?></td>
                    <td><?php echo ucfirst($payout['method']); ?></td>
                    <td><?php echo date('M j, Y', strtotime($payout['created_date'])); ?></td>
                    <td>
                        <span class="status-badge status-<?php echo $payout['status']; ?>">
                            <?php echo ucfirst($payout['status']); ?>
                        </span>
                    </td>
                    <?php if ($status === 'pending'): ?>
                    <td>
                        <div class="action-buttons">
                            <button class="btn-process" onclick="processPayout('<?php echo esc_js($payout['id']); ?>')">
                                Process
                            </button>
                            <button class="btn-reject" onclick="rejectPayout('<?php echo esc_js($payout['id']); ?>')">
                                Reject
                            </button>
                        </div>
                        <div class="payout-details">
                            <strong>Account Details:</strong><br>
                            <?php echo nl2br(esc_html($payout['account_details'])); ?>
                        </div>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
    
    /**
     * Render settings tab
     */
    private function render_settings_tab() {
        if (isset($_POST['save_settings'])) {
            $this->save_payout_settings();
            echo '<div class="notice notice-success"><p>Settings saved successfully!</p></div>';
        }
        
        $settings = get_option('dollarbets_payout_settings', [
            'exchange_rate' => $this->exchange_rate,
            'processing_fee' => $this->processing_fee,
            'minimum_payout' => $this->minimum_payout,
            'auto_process' => false,
            'notification_email' => get_option('admin_email')
        ]);
        
        ?>
        <form method="post">
            <table class="form-table">
                <tr>
                    <th scope="row">Exchange Rate</th>
                    <td>
                        <input type="number" name="exchange_rate" value="<?php echo $settings['exchange_rate']; ?>" step="0.001" min="0" />
                        <p class="description">How much $1 USD is worth in BetCoins (e.g., 0.01 = 100 BetCoins per $1)</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Processing Fee (%)</th>
                    <td>
                        <input type="number" name="processing_fee" value="<?php echo $settings['processing_fee'] * 100; ?>" step="0.1" min="0" max="50" />
                        <p class="description">Percentage fee charged for processing payouts</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Minimum Payout (BetCoins)</th>
                    <td>
                        <input type="number" name="minimum_payout" value="<?php echo $settings['minimum_payout']; ?>" min="1" />
                        <p class="description">Minimum amount of BetCoins required for a payout request</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Notification Email</th>
                    <td>
                        <input type="email" name="notification_email" value="<?php echo $settings['notification_email']; ?>" class="regular-text" />
                        <p class="description">Email address to receive payout notifications</p>
                    </td>
                </tr>
            </table>
            
            <?php submit_button('Save Settings', 'primary', 'save_settings'); ?>
        </form>
        <?php
    }
    
    /**
     * Save payout settings
     */
    private function save_payout_settings() {
        $settings = [
            'exchange_rate' => floatval($_POST['exchange_rate']),
            'processing_fee' => floatval($_POST['processing_fee']) / 100,
            'minimum_payout' => absint($_POST['minimum_payout']),
            'notification_email' => sanitize_email($_POST['notification_email'])
        ];
        
        update_option('dollarbets_payout_settings', $settings);
        
        // Update instance variables
        $this->exchange_rate = $settings['exchange_rate'];
        $this->processing_fee = $settings['processing_fee'];
        $this->minimum_payout = $settings['minimum_payout'];
    }
    
    /**
     * Process payout (admin AJAX)
     */
    public function process_payout_admin() {
        if (!wp_verify_nonce($_POST['nonce'], 'payout_admin') || !current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $payout_id = sanitize_text_field($_POST['payout_id']);
        $result = $this->process_payout($payout_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success('Payout processed successfully');
        }
    }
    
    /**
     * Reject payout (admin AJAX)
     */
    public function reject_payout_admin() {
        if (!wp_verify_nonce($_POST['nonce'], 'payout_admin') || !current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $payout_id = sanitize_text_field($_POST['payout_id']);
        $reason = sanitize_textarea_field($_POST['reason']);
        $result = $this->reject_payout($payout_id, $reason);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } else {
            wp_send_json_success('Payout rejected successfully');
        }
    }
    
    /**
     * Process a payout
     */
    private function process_payout($payout_id) {
        $payout = $this->get_payout_by_id($payout_id);
        if (!$payout) {
            return new WP_Error('payout_not_found', 'Payout not found');
        }
        
        if ($payout['status'] !== 'pending') {
            return new WP_Error('invalid_status', 'Payout is not pending');
        }
        
        // Update payout status
        $this->update_payout_status($payout_id, 'processed', [
            'processed_date' => current_time('mysql'),
            'transaction_id' => $this->generate_transaction_id()
        ]);
        
        // Log transaction
        $this->log_payout_transaction($payout['user_id'], 'payout_processed', $payout);
        
        // Send notification to user
        $this->notify_user_payout_processed($payout);
        
        return true;
    }
    
    /**
     * Reject a payout
     */
    private function reject_payout($payout_id, $reason) {
        $payout = $this->get_payout_by_id($payout_id);
        if (!$payout) {
            return new WP_Error('payout_not_found', 'Payout not found');
        }
        
        if ($payout['status'] !== 'pending') {
            return new WP_Error('invalid_status', 'Payout is not pending');
        }
        
        // Refund BetCoins to user
        $this->refund_user_betcoins($payout['user_id'], $payout['betcoins'], "Payout #{$payout_id} rejected: {$reason}");
        
        // Update payout status
        $this->update_payout_status($payout_id, 'rejected', [
            'processed_date' => current_time('mysql'),
            'notes' => $reason
        ]);
        
        // Log transaction
        $this->log_payout_transaction($payout['user_id'], 'payout_rejected', $payout);
        
        // Send notification to user
        $this->notify_user_payout_rejected($payout, $reason);
        
        return true;
    }
    
    /**
     * Payout button shortcode
     */
    public function payout_button_shortcode($atts) {
        if (!is_user_logged_in()) {
            return '<p>Please log in to request a payout.</p>';
        }
        
        $atts = shortcode_atts([
            'text' => 'Request Payout',
            'class' => 'dollarbets-payout-btn'
        ], $atts);
        
        $user_id = get_current_user_id();
        $balance = $this->get_user_betcoin_balance($user_id);
        $pending_payouts = $this->get_user_pending_payouts($user_id);
        
        ob_start();
        ?>
        <div class="dollarbets-payout-widget">
            <div class="payout-balance">
                <span class="balance-label">Your BetCoins:</span>
                <span class="balance-amount"><?php echo number_format($balance); ?></span>
            </div>
            
            <?php if (count($pending_payouts) > 0): ?>
                <div class="payout-pending">
                    <p>⏳ You have a pending payout request. Please wait for it to be processed.</p>
                </div>
            <?php elseif ($balance >= $this->minimum_payout): ?>
                <button class="<?php echo esc_attr($atts['class']); ?>" onclick="openPayoutModal()">
                    <?php echo esc_html($atts['text']); ?>
                </button>
            <?php else: ?>
                <div class="payout-insufficient">
                    <p>Minimum payout: <?php echo number_format($this->minimum_payout); ?> BetCoins</p>
                    <p>You need <?php echo number_format($this->minimum_payout - $balance); ?> more BetCoins to request a payout.</p>
                </div>
            <?php endif; ?>
        </div>
        
        <style>
        .dollarbets-payout-widget {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
            max-width: 300px;
            margin: 20px auto;
        }
        
        .payout-balance {
            margin-bottom: 15px;
            font-size: 18px;
        }
        
        .balance-label {
            color: #666;
        }
        
        .balance-amount {
            font-weight: bold;
            color: #28a745;
            margin-left: 10px;
        }
        
        .dollarbets-payout-btn {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            font-size: 16px;
            transition: all 0.3s ease;
        }
        
        .dollarbets-payout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
        }
        
        .payout-pending,
        .payout-insufficient {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 6px;
            color: #666;
        }
        
        .payout-pending p {
            margin: 0;
            color: #856404;
        }
        </style>
        <?php
        return ob_get_clean();
    }
    
    // Helper methods for database operations
    private function get_user_betcoin_balance($user_id) {
        if (function_exists('gamipress_get_user_points')) {
            return gamipress_get_user_points($user_id, 'betcoins');
        }
        return 0;
    }
    
    private function deduct_user_betcoins($user_id, $amount, $reason) {
        if (function_exists('gamipress_deduct_points_to_user')) {
            gamipress_deduct_points_to_user($user_id, $amount, 'betcoins', ['reason' => $reason]);
        }
    }
    
    private function refund_user_betcoins($user_id, $amount, $reason) {
        if (function_exists('gamipress_award_points_to_user')) {
            gamipress_award_points_to_user($user_id, $amount, 'betcoins', ['reason' => $reason]);
        }
    }
    
    private function generate_payout_id() {
        return 'PO' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 6));
    }
    
    private function generate_transaction_id() {
        return 'TX' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 8));
    }
    
    private function save_payout_request($payout_data) {
        $payouts = get_option('dollarbets_payouts', []);
        $payouts[] = $payout_data;
        update_option('dollarbets_payouts', $payouts);
    }
    
    private function get_payout_by_id($payout_id) {
        $payouts = get_option('dollarbets_payouts', []);
        foreach ($payouts as $payout) {
            if ($payout['id'] === $payout_id) {
                return $payout;
            }
        }
        return null;
    }
    
    private function update_payout_status($payout_id, $status, $additional_data = []) {
        $payouts = get_option('dollarbets_payouts', []);
        foreach ($payouts as &$payout) {
            if ($payout['id'] === $payout_id) {
                $payout['status'] = $status;
                foreach ($additional_data as $key => $value) {
                    $payout[$key] = $value;
                }
                break;
            }
        }
        update_option('dollarbets_payouts', $payouts);
    }
    
    private function get_all_payouts($status = 'all') {
        $payouts = get_option('dollarbets_payouts', []);
        if ($status === 'all') {
            return $payouts;
        }
        return array_filter($payouts, function($payout) use ($status) {
            return $payout['status'] === $status;
        });
    }
    
    private function get_user_payouts($user_id) {
        $payouts = get_option('dollarbets_payouts', []);
        return array_filter($payouts, function($payout) use ($user_id) {
            return $payout['user_id'] == $user_id;
        });
    }
    
    private function get_user_pending_payouts($user_id) {
        $payouts = $this->get_user_payouts($user_id);
        return array_filter($payouts, function($payout) {
            return $payout['status'] === 'pending';
        });
    }
    
    private function log_payout_transaction($user_id, $type, $payout_data) {
        $transaction_history = get_user_meta($user_id, 'db_transaction_history', true);
        if (!is_array($transaction_history)) $transaction_history = [];
        
        $transaction_history[] = [
            'id' => uniqid(),
            'type' => $type,
            'amount' => $payout_data['final_amount'],
            'betcoins' => $payout_data['betcoins'],
            'description' => "Payout {$type}: {$payout_data['id']}",
            'timestamp' => current_time('mysql'),
            'status' => 'completed',
            'payout_id' => $payout_data['id']
        ];
        
        update_user_meta($user_id, 'db_transaction_history', $transaction_history);
    }
    
    private function notify_admin_new_payout($payout_data) {
        $settings = get_option('dollarbets_payout_settings', []);
        $email = $settings['notification_email'] ?? get_option('admin_email');
        
        $user = get_user_by('id', $payout_data['user_id']);
        $subject = "New Payout Request: {$payout_data['id']}";
        $message = "A new payout request has been submitted:\n\n";
        $message .= "Payout ID: {$payout_data['id']}\n";
        $message .= "User: {$user->display_name} ({$user->user_email})\n";
        $message .= "BetCoins: {$payout_data['betcoins']}\n";
        $message .= "Final Amount: $" . number_format($payout_data['final_amount'], 2) . "\n";
        $message .= "Method: {$payout_data['method']}\n\n";
        $message .= "Please review and process this request in the admin panel.";
        
        wp_mail($email, $subject, $message);
    }
    
    private function notify_user_payout_processed($payout_data) {
        $user = get_user_by('id', $payout_data['user_id']);
        $subject = "Payout Processed: {$payout_data['id']}";
        $message = "Your payout request has been processed successfully!\n\n";
        $message .= "Payout ID: {$payout_data['id']}\n";
        $message .= "Amount: $" . number_format($payout_data['final_amount'], 2) . "\n";
        $message .= "Method: {$payout_data['method']}\n\n";
        $message .= "You should receive your payment within 3-5 business days.";
        
        wp_mail($user->user_email, $subject, $message);
    }
    
    private function notify_user_payout_rejected($payout_data, $reason) {
        $user = get_user_by('id', $payout_data['user_id']);
        $subject = "Payout Rejected: {$payout_data['id']}";
        $message = "Your payout request has been rejected.\n\n";
        $message .= "Payout ID: {$payout_data['id']}\n";
        $message .= "Reason: {$reason}\n\n";
        $message .= "Your BetCoins have been refunded to your account.";
        
        wp_mail($user->user_email, $subject, $message);
    }
    
    // Additional methods for profile fields, etc.
    public function add_payout_profile_fields($user) {
        ?>
        <h3>Payout Information</h3>
        <table class="form-table">
            <tr>
                <th><label for="payout_paypal">PayPal Email</label></th>
                <td>
                    <input type="email" name="payout_paypal" id="payout_paypal" 
                           value="<?php echo esc_attr(get_user_meta($user->ID, 'payout_paypal', true)); ?>" 
                           class="regular-text" />
                </td>
            </tr>
            <tr>
                <th><label for="payout_bank">Bank Account Details</label></th>
                <td>
                    <textarea name="payout_bank" id="payout_bank" rows="3" cols="30"><?php 
                        echo esc_textarea(get_user_meta($user->ID, 'payout_bank', true)); 
                    ?></textarea>
                    <p class="description">Include bank name, account number, routing number, etc.</p>
                </td>
            </tr>
            <tr>
                <th><label for="payout_crypto">Crypto Wallet Address</label></th>
                <td>
                    <input type="text" name="payout_crypto" id="payout_crypto" 
                           value="<?php echo esc_attr(get_user_meta($user->ID, 'payout_crypto', true)); ?>" 
                           class="regular-text" />
                    <p class="description">Bitcoin, Ethereum, or other supported cryptocurrency address</p>
                </td>
            </tr>
        </table>
        <?php
    }
    
    public function save_payout_profile_fields($user_id) {
        if (!current_user_can('edit_user', $user_id)) {
            return false;
        }
        
        update_user_meta($user_id, 'payout_paypal', sanitize_email($_POST['payout_paypal']));
        update_user_meta($user_id, 'payout_bank', sanitize_textarea_field($_POST['payout_bank']));
        update_user_meta($user_id, 'payout_crypto', sanitize_text_field($_POST['payout_crypto']));
    }
}

// Initialize the payout system
new DollarBets_Payout_System();


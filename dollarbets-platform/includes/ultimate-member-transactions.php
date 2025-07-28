<?php
if (!defined('ABSPATH')) exit;

/**
 * Ultimate Member Transaction Tab Integration
 * Adds a transaction history tab to Ultimate Member profiles
 */

class DollarBets_UM_Transactions {
    
    public function __construct() {
        // Hook into Ultimate Member
        add_filter('um_profile_tabs', [$this, 'add_transactions_tab'], 1000);
        add_action('um_profile_content_transactions_default', [$this, 'render_transactions_content']);
        add_action('um_profile_content_add_betcoins_default', [$this, 'render_add_betcoins_content']);
        
        // Add REST API endpoints for transaction data
        add_action('rest_api_init', [$this, 'register_transaction_routes']);
        
        // Enqueue scripts and styles
        add_action('wp_enqueue_scripts', [$this, 'enqueue_transaction_scripts']);
    }
    
    /**
     * Add transactions tab to Ultimate Member profile
     */
    public function add_transactions_tab($tabs) {
        $tabs['transactions'] = [
            'name' => 'Transactions',
            'icon' => 'um-faicon-credit-card',
            'custom' => true
        ];
        
        $tabs['add_betcoins'] = [
            'name' => 'Add BetCoins',
            'icon' => 'um-faicon-plus-circle',
            'custom' => true
        ];
        
        return $tabs;
    }
    
    /**
     * Register REST API routes for transaction data
     */
    public function register_transaction_routes() {
        register_rest_route('dollarbets/v1', '/user-transactions/(?P<user_id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_user_transactions'],
            'permission_callback' => function($request) {
                $user_id = absint($request['user_id']);
                $current_user = get_current_user_id();
                
                // Allow users to view their own transactions or admins to view any
                return $current_user == $user_id || (function_exists('current_user_can') && current_user_can('manage_options'));
            }
        ]);
        
        register_rest_route('dollarbets/v1', '/request-payout', [
            'methods' => 'POST',
            'callback' => [$this, 'request_payout'],
            'permission_callback' => function() {
                return is_user_logged_in();
            }
        ]);
    }
    
    /**
     * Enqueue transaction scripts and styles
     */
    public function enqueue_transaction_scripts() {
        if (function_exists('um_is_core_page') && um_is_core_page('user')) {
            wp_enqueue_script('dollarbets-transactions', plugin_dir_url(__FILE__) . '../assets/js/transactions.js', ['jquery'], '1.0.0', true);
            wp_localize_script('dollarbets-transactions', 'dollarBetsTransactions', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'restUrl' => rest_url('dollarbets/v1/'),
                'nonce' => wp_create_nonce('wp_rest'),
                'currentUserId' => get_current_user_id()
            ]);
        }
    }
    
    /**
     * Render the transactions tab content
     */
    public function render_transactions_content($args) {
        $user_id = um_profile_id();
        $current_user_id = get_current_user_id();
        $is_own_profile = $user_id == $current_user_id;
        
        ?>
        <div class="dollarbets-transactions-wrapper">
            <div class="transactions-header">
                <h3>Transaction History</h3>
                <?php if ($is_own_profile): ?>
                <div class="payout-section">
                    <button id="request-payout-btn" class="payout-btn">
                        💰 Request Payout
                    </button>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="transactions-filters">
                <select id="transaction-type-filter">
                    <option value="all">All Transactions</option>
                    <option value="bet">Bets Placed</option>
                    <option value="win">Winnings</option>
                    <option value="purchase">Purchases</option>
                    <option value="payout">Payouts</option>
                </select>
                
                <select id="transaction-period-filter">
                    <option value="all_time">All Time</option>
                    <option value="today">Today</option>
                    <option value="week">This Week</option>
                    <option value="month">This Month</option>
                    <option value="year">This Year</option>
                </select>
                
                <button id="refresh-transactions" class="refresh-btn">🔄 Refresh</button>
            </div>
            
            <div class="transactions-summary">
                <div class="summary-card">
                    <div class="summary-label">Total Spent</div>
                    <div class="summary-value" id="total-spent">$0.00</div>
                </div>
                <div class="summary-card">
                    <div class="summary-label">Total Earned</div>
                    <div class="summary-value" id="total-earned">$0.00</div>
                </div>
                <div class="summary-card">
                    <div class="summary-label">Net Profit/Loss</div>
                    <div class="summary-value" id="net-profit">$0.00</div>
                </div>
                <div class="summary-card">
                    <div class="summary-label">Current BetCoins</div>
                    <div class="summary-value" id="current-betcoins">0</div>
                </div>
            </div>
            
            <div class="transactions-content">
                <div class="loading-spinner" style="display: none;">Loading transactions...</div>
                <div class="transactions-table"></div>
            </div>
            
            <!-- Payout Modal -->
            <div id="payout-modal" class="payout-modal" style="display: none;">
                <div class="modal-content">
                    <span class="close-modal">&times;</span>
                    <h4>Request Payout</h4>
                    <p>Convert your BetCoins to real money</p>
                    
                    <div class="payout-form">
                        <div class="form-group">
                            <label for="payout-amount">BetCoins to Cash Out:</label>
                            <input type="number" id="payout-amount" placeholder="Enter amount" min="100" step="1">
                            <small>Minimum: 100 BetCoins (Exchange rate: 100 BetCoins = $1.00)</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="payout-method">Payout Method:</label>
                            <select id="payout-method">
                                <option value="paypal">PayPal</option>
                                <option value="bank">Bank Transfer</option>
                                <option value="crypto">Cryptocurrency</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="payout-details">Account Details:</label>
                            <textarea id="payout-details" placeholder="Enter your account details (email, account number, wallet address, etc.)" rows="3"></textarea>
                        </div>
                        
                        <div class="payout-summary">
                            <div class="summary-row">
                                <span>BetCoins:</span>
                                <span id="payout-betcoins">0</span>
                            </div>
                            <div class="summary-row">
                                <span>Cash Amount:</span>
                                <span id="payout-cash">$0.00</span>
                            </div>
                            <div class="summary-row">
                                <span>Processing Fee (5%):</span>
                                <span id="payout-fee">$0.00</span>
                            </div>
                            <div class="summary-row total">
                                <span>You'll Receive:</span>
                                <span id="payout-final">$0.00</span>
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <button id="submit-payout" class="submit-btn">Submit Payout Request</button>
                            <button id="cancel-payout" class="cancel-btn">Cancel</button>
                        </div>
                    </div>
                    
                    <div id="payout-status"></div>
                </div>
            </div>
        </div>
        
        <style>
        .dollarbets-transactions-wrapper {
            max-width: 1000px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .transactions-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .transactions-header h3 {
            margin: 0;
            font-size: 24px;
            color: #333;
        }
        
        .payout-btn {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 14px;
        }
        
        .payout-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
        }
        
        .transactions-filters {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .transactions-filters select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            background: white;
            font-size: 14px;
            min-width: 150px;
        }
        
        .refresh-btn {
            background: #007cba;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            transition: background 0.3s ease;
        }
        
        .refresh-btn:hover {
            background: #005a87;
        }
        
        .transactions-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .summary-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
            border: 1px solid #eee;
        }
        
        .summary-label {
            font-size: 14px;
            color: #666;
            margin-bottom: 8px;
            font-weight: 500;
        }
        
        .summary-value {
            font-size: 24px;
            font-weight: bold;
            color: #333;
        }
        
        .summary-value.positive {
            color: #28a745;
        }
        
        .summary-value.negative {
            color: #dc3545;
        }
        
        .transactions-content {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .loading-spinner {
            text-align: center;
            padding: 40px;
            color: #666;
        }
        
        .transactions-table {
            width: 100%;
        }
        
        .transaction-row {
            display: flex;
            align-items: center;
            padding: 15px 20px;
            border-bottom: 1px solid #eee;
            transition: background 0.3s ease;
        }
        
        .transaction-row:hover {
            background: #f8f9fa;
        }
        
        .transaction-row:last-child {
            border-bottom: none;
        }
        
        .transaction-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            font-size: 18px;
        }
        
        .transaction-icon.bet {
            background: #e3f2fd;
            color: #1976d2;
        }
        
        .transaction-icon.win {
            background: #e8f5e8;
            color: #2e7d32;
        }
        
        .transaction-icon.purchase {
            background: #fff3e0;
            color: #f57c00;
        }
        
        .transaction-icon.payout {
            background: #f3e5f5;
            color: #7b1fa2;
        }
        
        .transaction-details {
            flex: 1;
        }
        
        .transaction-title {
            font-weight: 600;
            color: #333;
            margin-bottom: 4px;
        }
        
        .transaction-meta {
            font-size: 12px;
            color: #666;
        }
        
        .transaction-amount {
            text-align: right;
            font-weight: bold;
        }
        
        .transaction-amount.positive {
            color: #28a745;
        }
        
        .transaction-amount.negative {
            color: #dc3545;
        }
        
        .transaction-amount.neutral {
            color: #6c757d;
        }
        
        /* Payout Modal */
        .payout-modal {
            position: fixed;
            z-index: 10000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background-color: white;
            padding: 30px;
            border-radius: 12px;
            width: 90%;
            max-width: 500px;
            position: relative;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .close-modal {
            position: absolute;
            right: 15px;
            top: 15px;
            font-size: 24px;
            cursor: pointer;
            color: #999;
        }
        
        .close-modal:hover {
            color: #333;
        }
        
        .modal-content h4 {
            margin: 0 0 10px 0;
            font-size: 20px;
            color: #333;
        }
        
        .modal-content p {
            margin: 0 0 20px 0;
            color: #666;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #333;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            box-sizing: border-box;
        }
        
        .form-group small {
            display: block;
            margin-top: 5px;
            color: #666;
            font-size: 12px;
        }
        
        .payout-summary {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
        }
        
        .summary-row.total {
            border-top: 1px solid #ddd;
            padding-top: 8px;
            font-weight: bold;
            font-size: 16px;
        }
        
        .form-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        
        .submit-btn {
            background: #28a745;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
        }
        
        .submit-btn:hover {
            background: #218838;
        }
        
        .cancel-btn {
            background: #6c757d;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 6px;
            cursor: pointer;
        }
        
        .cancel-btn:hover {
            background: #5a6268;
        }
        
        #payout-status {
            margin-top: 15px;
            padding: 10px;
            border-radius: 6px;
            text-align: center;
            display: none;
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
        
        /* Dark mode support */
        body.dollarbets-dark-mode .dollarbets-transactions-wrapper {
            color: #fff;
        }
        
        body.dollarbets-dark-mode .transactions-header h3 {
            color: #fff;
        }
        
        body.dollarbets-dark-mode .summary-card,
        body.dollarbets-dark-mode .transactions-content {
            background: #2c2c2c;
            border-color: #444;
        }
        
        body.dollarbets-dark-mode .summary-value,
        body.dollarbets-dark-mode .transaction-title {
            color: #fff;
        }
        
        body.dollarbets-dark-mode .transaction-row:hover {
            background: #3c3c3c;
        }
        
        body.dollarbets-dark-mode .modal-content {
            background: #2c2c2c;
            color: #fff;
        }
        
        body.dollarbets-dark-mode .form-group input,
        body.dollarbets-dark-mode .form-group select,
        body.dollarbets-dark-mode .form-group textarea {
            background: #3c3c3c;
            border-color: #555;
            color: #fff;
        }
        
        /* Mobile responsive */
        @media (max-width: 768px) {
            .transactions-header {
                flex-direction: column;
                align-items: stretch;
            }
            
            .transactions-filters {
                flex-direction: column;
            }
            
            .transactions-filters select {
                min-width: auto;
            }
            
            .transactions-summary {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .transaction-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .transaction-amount {
                text-align: left;
            }
        }
        </style>
        <?php
    }
    
    /**
     * Get user transactions via REST API
     */
    public function get_user_transactions(WP_REST_Request $request) {
        $user_id = absint($request['user_id']);
        $type_filter = sanitize_text_field($request->get_param('type') ?? 'all');
        $period_filter = sanitize_text_field($request->get_param('period') ?? 'all_time');
        
        if (!$user_id) {
            return new WP_Error('invalid_user', 'Invalid user ID', ['status' => 400]);
        }
        
        $transactions = [];
        $summary = [
            'total_spent' => 0,
            'total_earned' => 0,
            'net_profit' => 0,
            'current_betcoins' => 0
        ];
        
        // Get current BetCoins balance
        if (function_exists('gamipress_get_user_points')) {
            $summary['current_betcoins'] = gamipress_get_user_points($user_id, 'betcoins');
        }
        
        // Get betting history
        $bet_history = get_user_meta($user_id, 'db_bet_history', true);
        if (is_array($bet_history)) {
            foreach ($bet_history as $bet) {
                if ($this->filter_by_period($bet['timestamp'], $period_filter)) {
                    $transactions[] = [
                        'id' => 'bet_' . $bet['prediction_id'] . '_' . strtotime($bet['timestamp']),
                        'type' => 'bet',
                        'title' => 'Bet Placed',
                        'description' => 'Bet on prediction #' . $bet['prediction_id'] . ' (' . strtoupper($bet['choice']) . ')',
                        'amount' => -$bet['amount'],
                        'betcoins' => $bet['amount'],
                        'timestamp' => $bet['timestamp'],
                        'status' => 'completed'
                    ];
                }
            }
        }
        
        // Get purchase history
        $purchase_history = get_user_meta($user_id, 'db_purchase_history', true);
        if (is_array($purchase_history)) {
            foreach ($purchase_history as $purchase) {
                if ($this->filter_by_period($purchase['timestamp'], $period_filter)) {
                    $transactions[] = [
                        'id' => 'purchase_' . $purchase['transaction_id'],
                        'type' => 'purchase',
                        'title' => 'BetCoins Purchase',
                        'description' => 'Purchased ' . number_format($purchase['betcoins']) . ' BetCoins via ' . ucfirst($purchase['gateway']),
                        'amount' => -$purchase['amount'],
                        'betcoins' => $purchase['betcoins'],
                        'timestamp' => $purchase['timestamp'],
                        'status' => $purchase['status']
                    ];
                    
                    $summary['total_spent'] += $purchase['amount'];
                }
            }
        }
        
        // Get transaction history (includes winnings and payouts)
        $transaction_history = get_user_meta($user_id, 'db_transaction_history', true);
        if (is_array($transaction_history)) {
            foreach ($transaction_history as $transaction) {
                if ($this->filter_by_period($transaction['timestamp'], $period_filter)) {
                    $amount = 0;
                    $title = '';
                    $description = '';
                    
                    switch ($transaction['type']) {
                        case 'win':
                            $title = 'Prediction Win';
                            $description = 'Won ' . number_format($transaction['amount']) . ' BetCoins';
                            $summary['total_earned'] += $transaction['amount'] * 0.01; // Convert to dollars
                            break;
                        case 'payout':
                            $title = 'Payout Request';
                            $description = 'Cashed out ' . number_format($transaction['betcoins']) . ' BetCoins';
                            $amount = $transaction['amount'];
                            $summary['total_earned'] += $amount;
                            break;
                        default:
                            $title = ucfirst($transaction['type']);
                            $description = $transaction['description'];
                            break;
                    }
                    
                    $transactions[] = [
                        'id' => 'transaction_' . $transaction['id'],
                        'type' => $transaction['type'],
                        'title' => $title,
                        'description' => $description,
                        'amount' => $amount,
                        'betcoins' => $transaction['amount'],
                        'timestamp' => $transaction['timestamp'],
                        'status' => $transaction['status'] ?? 'completed'
                    ];
                }
            }
        }
        
        // Filter by type
        if ($type_filter !== 'all') {
            $transactions = array_filter($transactions, function($transaction) use ($type_filter) {
                return $transaction['type'] === $type_filter;
            });
        }
        
        // Sort by timestamp (newest first)
        usort($transactions, function($a, $b) {
            return strtotime($b['timestamp']) - strtotime($a['timestamp']);
        });
        
        $summary['net_profit'] = $summary['total_earned'] - $summary['total_spent'];
        
        return rest_ensure_response([
            'success' => true,
            'transactions' => array_values($transactions),
            'summary' => $summary
        ]);
    }
    
    /**
     * Handle payout requests
     */
    public function request_payout(WP_REST_Request $request) {
        $body = $request->get_json_params();
        $user_id = get_current_user_id();
        $amount = absint($body['amount'] ?? 0);
        $method = sanitize_text_field($body['method'] ?? '');
        $details = sanitize_textarea_field($body['details'] ?? '');
        
        if (!$user_id) {
            return new WP_Error('not_logged_in', 'User not logged in', ['status' => 401]);
        }
        
        if ($amount < 100) {
            return new WP_Error('invalid_amount', 'Minimum payout is 100 BetCoins', ['status' => 400]);
        }
        
        if (!in_array($method, ['paypal', 'bank', 'crypto'])) {
            return new WP_Error('invalid_method', 'Invalid payout method', ['status' => 400]);
        }
        
        if (empty($details)) {
            return new WP_Error('missing_details', 'Account details are required', ['status' => 400]);
        }
        
        // Check user balance
        $current_balance = 0;
        if (function_exists('gamipress_get_user_points')) {
            $current_balance = gamipress_get_user_points($user_id, 'betcoins');
        }
        
        if ($amount > $current_balance) {
            return new WP_Error('insufficient_funds', 'Insufficient BetCoins balance', ['status' => 400]);
        }
        
        // Calculate payout amounts
        $cash_amount = $amount * 0.01; // 100 BetCoins = $1
        $fee = $cash_amount * 0.05; // 5% processing fee
        $final_amount = $cash_amount - $fee;
        
        // Deduct BetCoins from user balance
        if (function_exists('db_subtract_points_manual')) {
            db_subtract_points_manual($user_id, $amount, 'betcoins', 'Payout request');
        }
        
        // Create payout record
        $payout_id = 'payout_' . time() . '_' . $user_id;
        $payout_data = [
            'id' => $payout_id,
            'user_id' => $user_id,
            'betcoins' => $amount,
            'cash_amount' => $cash_amount,
            'fee' => $fee,
            'final_amount' => $final_amount,
            'method' => $method,
            'details' => $details,
            'status' => 'pending',
            'timestamp' => current_time('mysql'),
            'processed_date' => null
        ];
        
        // Save payout request
        $payout_history = get_user_meta($user_id, 'db_payout_history', true);
        if (!is_array($payout_history)) $payout_history = [];
        $payout_history[] = $payout_data;
        update_user_meta($user_id, 'db_payout_history', $payout_history);
        
        // Log transaction
        if (function_exists('db_log_transaction')) {
            db_log_transaction($user_id, 'payout', $final_amount, 'Payout request', [
                'payout_id' => $payout_id,
                'method' => $method,
                'betcoins' => $amount,
                'status' => 'pending'
            ]);
        }
        
        // Send notification to admin (you can implement email notification here)
        
        return rest_ensure_response([
            'success' => true,
            'payout_id' => $payout_id,
            'message' => 'Payout request submitted successfully. You will receive your payment within 3-5 business days.',
            'details' => [
                'betcoins' => $amount,
                'cash_amount' => $cash_amount,
                'fee' => $fee,
                'final_amount' => $final_amount
            ]
        ]);
    }
    
    /**
     * Render Add BetCoins tab content
     */
    public function render_add_betcoins_content() {
        $user_id = um_profile_id();
        $current_user_id = get_current_user_id();
        
        // Only allow users to view their own Add BetCoins tab
        if ($user_id != $current_user_id && !current_user_can('manage_options')) {
            echo '<p>You can only add BetCoins to your own account.</p>';
            return;
        }
        
        // Get current balance
        $current_balance = 0;
        if (function_exists('gamipress_get_user_points')) {
            $current_balance = gamipress_get_user_points($user_id, 'betcoins');
        } else {
            $current_balance = get_user_meta($user_id, '_gamipress_betcoins_points', true) ?: 0;
        }
        
        ?>
        <div class="dollarbets-add-betcoins-tab">
            <div class="current-balance-card">
                <h3>Current Balance</h3>
                <div class="balance-display">
                    <span class="balance-amount"><?php echo number_format($current_balance); ?></span>
                    <span class="balance-label">BetCoins</span>
                </div>
            </div>
            
            <div class="purchase-options">
                <h3>Purchase BetCoins</h3>
                <p>Choose an amount to add to your account:</p>
                
                <div class="betcoin-packages">
                    <div class="package" data-amount="1000" data-price="10.00">
                        <div class="package-amount">1,000 BetCoins</div>
                        <div class="package-price">$10.00</div>
                        <button class="purchase-btn" onclick="purchaseBetCoins(1000, 10.00)">Buy Now</button>
                    </div>
                    
                    <div class="package featured" data-amount="2500" data-price="20.00">
                        <div class="package-badge">Best Value</div>
                        <div class="package-amount">2,500 BetCoins</div>
                        <div class="package-price">$20.00</div>
                        <div class="package-savings">Save $5.00</div>
                        <button class="purchase-btn" onclick="purchaseBetCoins(2500, 20.00)">Buy Now</button>
                    </div>
                    
                    <div class="package" data-amount="5000" data-price="35.00">
                        <div class="package-amount">5,000 BetCoins</div>
                        <div class="package-price">$35.00</div>
                        <div class="package-savings">Save $15.00</div>
                        <button class="purchase-btn" onclick="purchaseBetCoins(5000, 35.00)">Buy Now</button>
                    </div>
                    
                    <div class="package" data-amount="10000" data-price="60.00">
                        <div class="package-amount">10,000 BetCoins</div>
                        <div class="package-price">$60.00</div>
                        <div class="package-savings">Save $40.00</div>
                        <button class="purchase-btn" onclick="purchaseBetCoins(10000, 60.00)">Buy Now</button>
                    </div>
                </div>
                
                <div class="custom-amount-section">
                    <h4>Custom Amount</h4>
                    <div class="custom-input-group">
                        <input type="number" id="custom-betcoins" placeholder="Enter BetCoins amount" min="100" step="100">
                        <span class="rate-info">Rate: 100 BetCoins = $1.00</span>
                        <button class="purchase-btn" onclick="purchaseCustomAmount()">Purchase</button>
                    </div>
                </div>
            </div>
            
            <div class="payment-methods">
                <h4>Accepted Payment Methods</h4>
                <div class="payment-icons">
                    <span class="payment-icon">💳 Credit Card</span>
                    <span class="payment-icon">🏦 PayPal</span>
                    <span class="payment-icon">🏪 Stripe</span>
                </div>
                <p class="payment-note">All transactions are secure and encrypted.</p>
            </div>
        </div>
        
        <style>
        .dollarbets-add-betcoins-tab {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .current-balance-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 15px;
            text-align: center;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        
        .current-balance-card h3 {
            margin: 0 0 15px 0;
            font-size: 18px;
            opacity: 0.9;
        }
        
        .balance-display {
            display: flex;
            align-items: baseline;
            justify-content: center;
            gap: 10px;
        }
        
        .balance-amount {
            font-size: 48px;
            font-weight: bold;
        }
        
        .balance-label {
            font-size: 18px;
            opacity: 0.8;
        }
        
        .purchase-options h3 {
            margin-bottom: 10px;
            color: #333;
        }
        
        .betcoin-packages {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        
        .package {
            background: white;
            border: 2px solid #e1e5e9;
            border-radius: 12px;
            padding: 25px;
            text-align: center;
            position: relative;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .package:hover {
            border-color: #667eea;
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        
        .package.featured {
            border-color: #28a745;
            background: linear-gradient(135deg, #f8fff9 0%, #e8f5e8 100%);
        }
        
        .package-badge {
            position: absolute;
            top: -10px;
            left: 50%;
            transform: translateX(-50%);
            background: #28a745;
            color: white;
            padding: 5px 15px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: bold;
        }
        
        .package-amount {
            font-size: 24px;
            font-weight: bold;
            color: #333;
            margin-bottom: 10px;
        }
        
        .package-price {
            font-size: 32px;
            font-weight: bold;
            color: #667eea;
            margin-bottom: 10px;
        }
        
        .package-savings {
            color: #28a745;
            font-weight: bold;
            margin-bottom: 15px;
        }
        
        .purchase-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 8px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 100%;
        }
        
        .purchase-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .custom-amount-section {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 12px;
            margin: 30px 0;
        }
        
        .custom-amount-section h4 {
            margin: 0 0 15px 0;
            color: #333;
        }
        
        .custom-input-group {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .custom-input-group input {
            flex: 1;
            min-width: 200px;
            padding: 12px;
            border: 2px solid #e1e5e9;
            border-radius: 8px;
            font-size: 16px;
        }
        
        .rate-info {
            color: #666;
            font-size: 14px;
            white-space: nowrap;
        }
        
        .payment-methods {
            background: white;
            border: 1px solid #e1e5e9;
            border-radius: 12px;
            padding: 20px;
            margin-top: 30px;
        }
        
        .payment-methods h4 {
            margin: 0 0 15px 0;
            color: #333;
        }
        
        .payment-icons {
            display: flex;
            gap: 20px;
            margin-bottom: 10px;
            flex-wrap: wrap;
        }
        
        .payment-icon {
            background: #f8f9fa;
            padding: 8px 15px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
        }
        
        .payment-note {
            color: #666;
            font-size: 14px;
            margin: 0;
        }
        
        /* Dark mode support */
        body.dark-mode .dollarbets-add-betcoins-tab .purchase-options h3,
        body.dark-mode .dollarbets-add-betcoins-tab .package-amount,
        body.dark-mode .dollarbets-add-betcoins-tab .custom-amount-section h4,
        body.dark-mode .dollarbets-add-betcoins-tab .payment-methods h4 {
            color: #fff;
        }
        
        body.dark-mode .dollarbets-add-betcoins-tab .package {
            background: #2c2c2c;
            border-color: #444;
        }
        
        body.dark-mode .dollarbets-add-betcoins-tab .custom-amount-section,
        body.dark-mode .dollarbets-add-betcoins-tab .payment-methods {
            background: #2c2c2c;
            border-color: #444;
        }
        
        body.dark-mode .dollarbets-add-betcoins-tab .custom-input-group input {
            background: #3c3c3c;
            border-color: #555;
            color: #fff;
        }
        
        /* Mobile responsive */
        @media (max-width: 768px) {
            .betcoin-packages {
                grid-template-columns: 1fr;
            }
            
            .custom-input-group {
                flex-direction: column;
                align-items: stretch;
            }
            
            .custom-input-group input {
                min-width: auto;
            }
            
            .payment-icons {
                justify-content: center;
            }
        }
        </style>
        
        <script>
        function purchaseBetCoins(amount, price) {
            // Call the payment gateway function
            if (typeof initiatePurchase === 'function') {
                initiatePurchase(amount, price);
            } else {
                alert('Payment system is not available. Please contact support.');
            }
        }
        
        function purchaseCustomAmount() {
            const input = document.getElementById('custom-betcoins');
            const amount = parseInt(input.value);
            
            if (!amount || amount < 100) {
                alert('Please enter a valid amount (minimum 100 BetCoins).');
                return;
            }
            
            const price = (amount / 100).toFixed(2);
            purchaseBetCoins(amount, parseFloat(price));
        }
        
        // Update balance after successful purchase
        document.addEventListener('dollarBetsPurchaseComplete', function(event) {
            if (event.detail.success) {
                location.reload(); // Refresh to show updated balance
            }
        });
        </script>
        <?php
    }
    
    /**
     * Filter transactions by time period
     */
    private function filter_by_period($timestamp, $period) {
        if ($period === 'all_time') {
            return true;
        }
        
        $now = current_time('timestamp');
        $transaction_time = strtotime($timestamp);
        
        switch ($period) {
            case 'today':
                return date('Y-m-d', $transaction_time) === date('Y-m-d', $now);
            case 'week':
                return $transaction_time >= strtotime('-1 week', $now);
            case 'month':
                return $transaction_time >= strtotime('-1 month', $now);
            case 'year':
                return $transaction_time >= strtotime('-1 year', $now);
            default:
                return true;
        }
    }
}

// Initialize Ultimate Member transactions integration
if (class_exists('UM')) {
    new DollarBets_UM_Transactions();
}


<?php
/**
 * Admin Interface for Custom Point System
 * Provides comprehensive management interface for the point system
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class DollarBets_Admin_Point_System {
    
    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menus']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('wp_ajax_dollarbets_admin_action', [$this, 'handle_ajax_actions']);
        add_action('admin_init', [$this, 'handle_form_submissions']);
    }
    
    /**
     * Add admin menu pages
     */
    public function add_admin_menus() {
        // Main menu
        add_menu_page(
            'DollarBets Point System',
            'DollarBets Points',
            'manage_options',
            'dollarbets-points',
            [$this, 'dashboard_page'],
            'dashicons-awards',
            30
        );
        
        // Dashboard
        add_submenu_page(
            'dollarbets-points',
            'Dashboard',
            'Dashboard',
            'manage_options',
            'dollarbets-points',
            [$this, 'dashboard_page']
        );
        
        // User Points Management
        add_submenu_page(
            'dollarbets-points',
            'User Points',
            'User Points',
            'manage_options',
            'dollarbets-user-points',
            [$this, 'user_points_page']
        );
        
        // Point Types
        add_submenu_page(
            'dollarbets-points',
            'Point Types',
            'Point Types',
            'manage_options',
            'dollarbets-point-types',
            [$this, 'point_types_page']
        );
        
        // Earning Rules
        add_submenu_page(
            'dollarbets-points',
            'Earning Rules',
            'Earning Rules',
            'manage_options',
            'dollarbets-earning-rules',
            [$this, 'earning_rules_page']
        );
        
        // Ranks
        add_submenu_page(
            'dollarbets-points',
            'Ranks',
            'Ranks',
            'manage_options',
            'dollarbets-ranks',
            [$this, 'ranks_page']
        );
        
        // Achievements
        add_submenu_page(
            'dollarbets-points',
            'Achievements',
            'Achievements',
            'manage_options',
            'dollarbets-achievements',
            [$this, 'achievements_page']
        );
        
        // Transaction History
        add_submenu_page(
            'dollarbets-points',
            'Transactions',
            'Transactions',
            'manage_options',
            'dollarbets-transactions',
            [$this, 'transactions_page']
        );
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'dollarbets-') === false) {
            return;
        }
        
        wp_enqueue_script('jquery');
        wp_enqueue_script('jquery-ui-datepicker');
        wp_enqueue_style('jquery-ui-datepicker', 'https://code.jquery.com/ui/1.12.1/themes/ui-lightness/jquery-ui.css');
        
        wp_enqueue_script(
            'dollarbets-admin',
            DOLLARBETS_URL . 'assets/js/admin-point-system.js',
            ['jquery'],
            '1.0.0',
            true
        );
        
        wp_enqueue_style(
            'dollarbets-admin',
            DOLLARBETS_URL . 'assets/css/admin-point-system.css',
            [],
            '1.0.0'
        );
        
        wp_localize_script('dollarbets-admin', 'dollarbetsAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dollarbets_admin_nonce')
        ]);
    }
    
    /**
     * Dashboard page
     */
    public function dashboard_page() {
        $point_manager = new DollarBets_Point_Manager();
        $rank_manager = new DollarBets_Rank_Manager();
        
        // Get statistics
        $betcoins_stats = $point_manager->get_point_statistics('betcoins');
        $rank_stats = $rank_manager->get_rank_statistics();
        $recent_transactions = $point_manager->get_transaction_history(0, null, 10);
        
        ?>
        <div class="wrap">
            <h1>DollarBets Point System Dashboard</h1>
            
            <div class="dollarbets-dashboard">
                <div class="dashboard-widgets">
                    <!-- Statistics Cards -->
                    <div class="dashboard-widget">
                        <h3>BetCoins Statistics</h3>
                        <div class="stats-grid">
                            <div class="stat-item">
                                <span class="stat-label">Total Users</span>
                                <span class="stat-value"><?php echo number_format($betcoins_stats->total_users ?? 0); ?></span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-label">Total Balance</span>
                                <span class="stat-value"><?php echo number_format($betcoins_stats->total_balance ?? 0); ?></span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-label">Total Earned</span>
                                <span class="stat-value"><?php echo number_format($betcoins_stats->total_earned ?? 0); ?></span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-label">Total Spent</span>
                                <span class="stat-value"><?php echo number_format($betcoins_stats->total_spent ?? 0); ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Rank Distribution -->
                    <div class="dashboard-widget">
                        <h3>Rank Distribution</h3>
                        <div class="rank-distribution">
                            <?php foreach ($rank_stats as $rank): ?>
                                <div class="rank-item">
                                    <span class="rank-icon"><?php echo esc_html($rank->rank_icon); ?></span>
                                    <span class="rank-name"><?php echo esc_html($rank->rank_name); ?></span>
                                    <span class="rank-count"><?php echo number_format($rank->user_count); ?> users</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Recent Transactions -->
                    <div class="dashboard-widget">
                        <h3>Recent Transactions</h3>
                        <div class="recent-transactions">
                            <?php if ($recent_transactions): ?>
                                <table class="wp-list-table widefat fixed striped">
                                    <thead>
                                        <tr>
                                            <th>User</th>
                                            <th>Type</th>
                                            <th>Amount</th>
                                            <th>Reason</th>
                                            <th>Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_transactions as $transaction): ?>
                                            <tr>
                                                <td>
                                                    <?php 
                                                    $user = get_userdata($transaction->user_id);
                                                    echo $user ? esc_html($user->display_name) : 'Unknown User';
                                                    ?>
                                                </td>
                                                <td>
                                                    <span class="transaction-type transaction-<?php echo esc_attr($transaction->transaction_type); ?>">
                                                        <?php echo esc_html(ucfirst($transaction->transaction_type)); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="amount-<?php echo $transaction->transaction_type === 'earn' ? 'positive' : 'negative'; ?>">
                                                        <?php echo $transaction->transaction_type === 'earn' ? '+' : '-'; ?>
                                                        <?php echo number_format($transaction->amount); ?>
                                                        <?php echo esc_html($transaction->point_type_icon); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo esc_html($transaction->reason); ?></td>
                                                <td><?php echo date('M j, Y H:i', strtotime($transaction->created_at)); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <p>No transactions found.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * User Points management page
     */
    public function user_points_page() {
        $point_manager = new DollarBets_Point_Manager();
        
        // Handle search
        $search_user = isset($_GET['search_user']) ? sanitize_text_field($_GET['search_user']) : '';
        $users = [];
        
        if ($search_user) {
            $user_query = new WP_User_Query([
                'search' => '*' . $search_user . '*',
                'search_columns' => ['user_login', 'user_email', 'display_name'],
                'number' => 20
            ]);
            $users = $user_query->get_results();
        }
        
        ?>
        <div class="wrap">
            <h1>User Points Management</h1>
            
            <!-- Search Users -->
            <div class="user-search">
                <form method="get">
                    <input type="hidden" name="page" value="dollarbets-user-points">
                    <input type="text" name="search_user" value="<?php echo esc_attr($search_user); ?>" placeholder="Search users...">
                    <button type="submit" class="button">Search</button>
                </form>
            </div>
            
            <?php if ($users): ?>
                <div class="user-points-list">
                    <?php foreach ($users as $user): ?>
                        <?php 
                        $user_points = $point_manager->get_user_all_points($user->ID);
                        $rank_manager = new DollarBets_Rank_Manager();
                        $user_rank = $rank_manager->get_user_rank($user->ID);
                        ?>
                        <div class="user-points-card">
                            <div class="user-info">
                                <h3><?php echo esc_html($user->display_name); ?></h3>
                                <p><?php echo esc_html($user->user_email); ?></p>
                                <?php if ($user_rank): ?>
                                    <p class="user-rank">
                                        <span class="rank-icon"><?php echo esc_html($user_rank->icon); ?></span>
                                        <?php echo esc_html($user_rank->name); ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                            
                            <div class="points-info">
                                <?php foreach ($user_points as $slug => $data): ?>
                                    <div class="point-type">
                                        <span class="point-icon"><?php echo esc_html($data['icon']); ?></span>
                                        <span class="point-name"><?php echo esc_html($data['name']); ?></span>
                                        <span class="point-balance"><?php echo number_format($data['balance']); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <div class="point-actions">
                                <button class="button adjust-points" data-user-id="<?php echo $user->ID; ?>">
                                    Adjust Points
                                </button>
                                <button class="button view-history" data-user-id="<?php echo $user->ID; ?>">
                                    View History
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php elseif ($search_user): ?>
                <p>No users found matching your search.</p>
            <?php else: ?>
                <p>Enter a search term to find users.</p>
            <?php endif; ?>
            
            <!-- Point Adjustment Modal -->
            <div id="point-adjustment-modal" class="dollarbets-modal" style="display: none;">
                <div class="modal-content">
                    <span class="close">&times;</span>
                    <h2>Adjust User Points</h2>
                    <form id="point-adjustment-form">
                        <input type="hidden" id="adjust-user-id" name="user_id">
                        
                        <label for="point-type">Point Type:</label>
                        <select id="point-type" name="point_type" required>
                            <option value="betcoins">BetCoins</option>
                            <option value="experience">Experience Points</option>
                        </select>
                        
                        <label for="adjustment-type">Adjustment Type:</label>
                        <select id="adjustment-type" name="adjustment_type" required>
                            <option value="add">Add Points</option>
                            <option value="deduct">Deduct Points</option>
                            <option value="set">Set Points</option>
                        </select>
                        
                        <label for="point-amount">Amount:</label>
                        <input type="number" id="point-amount" name="amount" min="0" required>
                        
                        <label for="adjustment-reason">Reason:</label>
                        <input type="text" id="adjustment-reason" name="reason" placeholder="Reason for adjustment">
                        
                        <button type="submit" class="button button-primary">Apply Adjustment</button>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Point Types management page
     */
    public function point_types_page() {
        global $wpdb;
        
        $point_types_table = $wpdb->prefix . 'dollarbets_point_types';
        $point_types = $wpdb->get_results("SELECT * FROM $point_types_table ORDER BY id ASC");
        
        ?>
        <div class="wrap">
            <h1>Point Types Management</h1>
            
            <div class="point-types-container">
                <div class="add-point-type">
                    <h2>Add New Point Type</h2>
                    <form method="post" action="">
                        <?php wp_nonce_field('dollarbets_add_point_type', 'point_type_nonce'); ?>
                        <input type="hidden" name="action" value="add_point_type">
                        
                        <table class="form-table">
                            <tr>
                                <th><label for="pt_slug">Slug</label></th>
                                <td><input type="text" id="pt_slug" name="slug" required></td>
                            </tr>
                            <tr>
                                <th><label for="pt_name">Name</label></th>
                                <td><input type="text" id="pt_name" name="name" required></td>
                            </tr>
                            <tr>
                                <th><label for="pt_description">Description</label></th>
                                <td><textarea id="pt_description" name="description"></textarea></td>
                            </tr>
                            <tr>
                                <th><label for="pt_icon">Icon</label></th>
                                <td><input type="text" id="pt_icon" name="icon" placeholder="💰"></td>
                            </tr>
                            <tr>
                                <th><label for="pt_decimal_places">Decimal Places</label></th>
                                <td><input type="number" id="pt_decimal_places" name="decimal_places" min="0" max="4" value="0"></td>
                            </tr>
                        </table>
                        
                        <button type="submit" class="button button-primary">Add Point Type</button>
                    </form>
                </div>
                
                <div class="existing-point-types">
                    <h2>Existing Point Types</h2>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Icon</th>
                                <th>Name</th>
                                <th>Slug</th>
                                <th>Description</th>
                                <th>Decimal Places</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($point_types as $type): ?>
                                <tr>
                                    <td><?php echo esc_html($type->icon); ?></td>
                                    <td><?php echo esc_html($type->name); ?></td>
                                    <td><code><?php echo esc_html($type->slug); ?></code></td>
                                    <td><?php echo esc_html($type->description); ?></td>
                                    <td><?php echo esc_html($type->decimal_places); ?></td>
                                    <td>
                                        <span class="status-<?php echo $type->is_active ? 'active' : 'inactive'; ?>">
                                            <?php echo $type->is_active ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button class="button edit-point-type" data-id="<?php echo $type->id; ?>">Edit</button>
                                        <?php if ($type->slug !== 'betcoins'): // Prevent deletion of core point type ?>
                                            <button class="button delete-point-type" data-id="<?php echo $type->id; ?>">Delete</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Earning Rules management page
     */
    public function earning_rules_page() {
        $earning_rules = new DollarBets_Earning_Rules();
        $rules = $earning_rules->get_all_rules();
        
        ?>
        <div class="wrap">
            <h1>Earning Rules Management</h1>
            
            <div class="earning-rules-container">
                <div class="add-earning-rule">
                    <h2>Add New Earning Rule</h2>
                    <form method="post" action="">
                        <?php wp_nonce_field('dollarbets_add_earning_rule', 'earning_rule_nonce'); ?>
                        <input type="hidden" name="action" value="add_earning_rule">
                        
                        <table class="form-table">
                            <tr>
                                <th><label for="er_name">Rule Name</label></th>
                                <td><input type="text" id="er_name" name="name" required></td>
                            </tr>
                            <tr>
                                <th><label for="er_description">Description</label></th>
                                <td><textarea id="er_description" name="description"></textarea></td>
                            </tr>
                            <tr>
                                <th><label for="er_trigger_action">Trigger Action</label></th>
                                <td>
                                    <select id="er_trigger_action" name="trigger_action" required>
                                        <option value="user_login">User Login</option>
                                        <option value="profile_update">Profile Update</option>
                                        <option value="profile_complete">Profile Complete</option>
                                        <option value="bet_placed">Bet Placed</option>
                                        <option value="bet_won">Bet Won</option>
                                        <option value="prediction_created">Prediction Created</option>
                                        <option value="comment_posted">Comment Posted</option>
                                        <option value="user_register">User Registration</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="er_points_awarded">Points Awarded</label></th>
                                <td><input type="number" id="er_points_awarded" name="points_awarded" min="0" step="0.01" required></td>
                            </tr>
                            <tr>
                                <th><label for="er_max_daily">Max Daily Awards</label></th>
                                <td><input type="number" id="er_max_daily" name="max_daily_awards" min="0" placeholder="Leave empty for unlimited"></td>
                            </tr>
                            <tr>
                                <th><label for="er_max_total">Max Total Awards</label></th>
                                <td><input type="number" id="er_max_total" name="max_total_awards" min="0" placeholder="Leave empty for unlimited"></td>
                            </tr>
                            <tr>
                                <th><label for="er_priority">Priority</label></th>
                                <td><input type="number" id="er_priority" name="priority" value="0"></td>
                            </tr>
                        </table>
                        
                        <button type="submit" class="button button-primary">Add Earning Rule</button>
                    </form>
                </div>
                
                <div class="existing-earning-rules">
                    <h2>Existing Earning Rules</h2>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Trigger Action</th>
                                <th>Points Awarded</th>
                                <th>Point Type</th>
                                <th>Daily Limit</th>
                                <th>Total Limit</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rules as $rule): ?>
                                <tr>
                                    <td><?php echo esc_html($rule->name); ?></td>
                                    <td><code><?php echo esc_html($rule->trigger_action); ?></code></td>
                                    <td><?php echo number_format($rule->points_awarded, 2); ?></td>
                                    <td><?php echo esc_html($rule->point_type_name); ?></td>
                                    <td><?php echo $rule->max_daily_awards ?: 'Unlimited'; ?></td>
                                    <td><?php echo $rule->max_total_awards ?: 'Unlimited'; ?></td>
                                    <td><?php echo esc_html($rule->priority); ?></td>
                                    <td>
                                        <span class="status-<?php echo $rule->is_active ? 'active' : 'inactive'; ?>">
                                            <?php echo $rule->is_active ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button class="button edit-earning-rule" data-id="<?php echo $rule->id; ?>">Edit</button>
                                        <button class="button delete-earning-rule" data-id="<?php echo $rule->id; ?>">Delete</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Ranks management page
     */
    public function ranks_page() {
        $rank_manager = new DollarBets_Rank_Manager();
        $ranks = $rank_manager->get_all_ranks();
        
        ?>
        <div class="wrap">
            <h1>Ranks Management</h1>
            
            <div class="ranks-container">
                <div class="add-rank">
                    <h2>Add New Rank</h2>
                    <form method="post" action="">
                        <?php wp_nonce_field('dollarbets_add_rank', 'rank_nonce'); ?>
                        <input type="hidden" name="action" value="add_rank">
                        
                        <table class="form-table">
                            <tr>
                                <th><label for="rank_slug">Slug</label></th>
                                <td><input type="text" id="rank_slug" name="slug" required></td>
                            </tr>
                            <tr>
                                <th><label for="rank_name">Name</label></th>
                                <td><input type="text" id="rank_name" name="name" required></td>
                            </tr>
                            <tr>
                                <th><label for="rank_description">Description</label></th>
                                <td><textarea id="rank_description" name="description"></textarea></td>
                            </tr>
                            <tr>
                                <th><label for="rank_icon">Icon</label></th>
                                <td><input type="text" id="rank_icon" name="icon" placeholder="🏆"></td>
                            </tr>
                            <tr>
                                <th><label for="rank_badge_color">Badge Color</label></th>
                                <td><input type="color" id="rank_badge_color" name="badge_color" value="#000000"></td>
                            </tr>
                            <tr>
                                <th><label for="rank_points_required">Points Required</label></th>
                                <td><input type="number" id="rank_points_required" name="points_required" min="0" required></td>
                            </tr>
                            <tr>
                                <th><label for="rank_order_position">Order Position</label></th>
                                <td><input type="number" id="rank_order_position" name="order_position" value="0"></td>
                            </tr>
                        </table>
                        
                        <button type="submit" class="button button-primary">Add Rank</button>
                    </form>
                </div>
                
                <div class="existing-ranks">
                    <h2>Existing Ranks</h2>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Icon</th>
                                <th>Name</th>
                                <th>Slug</th>
                                <th>Points Required</th>
                                <th>Order</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ranks as $rank): ?>
                                <tr>
                                    <td>
                                        <span style="color: <?php echo esc_attr($rank->badge_color); ?>">
                                            <?php echo esc_html($rank->icon); ?>
                                        </span>
                                    </td>
                                    <td><?php echo esc_html($rank->name); ?></td>
                                    <td><code><?php echo esc_html($rank->slug); ?></code></td>
                                    <td><?php echo number_format($rank->points_required); ?></td>
                                    <td><?php echo esc_html($rank->order_position); ?></td>
                                    <td>
                                        <span class="status-<?php echo $rank->is_active ? 'active' : 'inactive'; ?>">
                                            <?php echo $rank->is_active ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button class="button edit-rank" data-id="<?php echo $rank->id; ?>">Edit</button>
                                        <button class="button delete-rank" data-id="<?php echo $rank->id; ?>">Delete</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <div class="rank-actions">
                        <button class="button button-secondary" id="recalculate-ranks">
                            Recalculate All User Ranks
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Achievements management page
     */
    public function achievements_page() {
        $achievement_manager = new DollarBets_Achievement_Manager();
        $achievements = $achievement_manager->get_all_achievements(true);
        
        ?>
        <div class="wrap">
            <h1>Achievements Management</h1>
            
            <div class="achievements-container">
                <div class="add-achievement">
                    <h2>Add New Achievement</h2>
                    <form method="post" action="">
                        <?php wp_nonce_field('dollarbets_add_achievement', 'achievement_nonce'); ?>
                        <input type="hidden" name="action" value="add_achievement">
                        
                        <table class="form-table">
                            <tr>
                                <th><label for="ach_slug">Slug</label></th>
                                <td><input type="text" id="ach_slug" name="slug" required></td>
                            </tr>
                            <tr>
                                <th><label for="ach_name">Name</label></th>
                                <td><input type="text" id="ach_name" name="name" required></td>
                            </tr>
                            <tr>
                                <th><label for="ach_description">Description</label></th>
                                <td><textarea id="ach_description" name="description"></textarea></td>
                            </tr>
                            <tr>
                                <th><label for="ach_icon">Icon</label></th>
                                <td><input type="text" id="ach_icon" name="icon" placeholder="🏆"></td>
                            </tr>
                            <tr>
                                <th><label for="ach_badge_color">Badge Color</label></th>
                                <td><input type="color" id="ach_badge_color" name="badge_color" value="#000000"></td>
                            </tr>
                            <tr>
                                <th><label for="ach_points_reward">Points Reward</label></th>
                                <td><input type="number" id="ach_points_reward" name="points_reward" min="0" step="0.01" value="0"></td>
                            </tr>
                            <tr>
                                <th><label for="ach_unlock_conditions">Unlock Conditions (JSON)</label></th>
                                <td>
                                    <textarea id="ach_unlock_conditions" name="unlock_conditions" placeholder='{"bets_placed": 10}'>{}</textarea>
                                    <p class="description">Enter conditions as JSON. Examples: {"bets_placed": 10}, {"total_spent": 1000}</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="ach_is_secret">Secret Achievement</label></th>
                                <td><input type="checkbox" id="ach_is_secret" name="is_secret" value="1"></td>
                            </tr>
                            <tr>
                                <th><label for="ach_order_position">Order Position</label></th>
                                <td><input type="number" id="ach_order_position" name="order_position" value="0"></td>
                            </tr>
                        </table>
                        
                        <button type="submit" class="button button-primary">Add Achievement</button>
                    </form>
                </div>
                
                <div class="existing-achievements">
                    <h2>Existing Achievements</h2>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Icon</th>
                                <th>Name</th>
                                <th>Slug</th>
                                <th>Points Reward</th>
                                <th>Secret</th>
                                <th>Order</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($achievements as $achievement): ?>
                                <tr>
                                    <td>
                                        <span style="color: <?php echo esc_attr($achievement->badge_color); ?>">
                                            <?php echo esc_html($achievement->icon); ?>
                                        </span>
                                    </td>
                                    <td><?php echo esc_html($achievement->name); ?></td>
                                    <td><code><?php echo esc_html($achievement->slug); ?></code></td>
                                    <td><?php echo number_format($achievement->points_reward, 2); ?></td>
                                    <td><?php echo $achievement->is_secret ? 'Yes' : 'No'; ?></td>
                                    <td><?php echo esc_html($achievement->order_position); ?></td>
                                    <td>
                                        <span class="status-<?php echo $achievement->is_active ? 'active' : 'inactive'; ?>">
                                            <?php echo $achievement->is_active ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button class="button edit-achievement" data-id="<?php echo $achievement->id; ?>">Edit</button>
                                        <button class="button delete-achievement" data-id="<?php echo $achievement->id; ?>">Delete</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Transactions page
     */
    public function transactions_page() {
        global $wpdb;
        
        $transactions_table = $wpdb->prefix . 'dollarbets_point_transactions';
        $point_types_table = $wpdb->prefix . 'dollarbets_point_types';
        
        // Pagination
        $per_page = 50;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;
        
        // Filters
        $user_filter = isset($_GET['user_filter']) ? sanitize_text_field($_GET['user_filter']) : '';
        $type_filter = isset($_GET['type_filter']) ? sanitize_text_field($_GET['type_filter']) : '';
        $date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
        $date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';
        
        // Build query
        $where_conditions = ['1=1'];
        $query_params = [];
        
        if ($user_filter) {
            $user = get_user_by('login', $user_filter);
            if (!$user) {
                $user = get_user_by('email', $user_filter);
            }
            if ($user) {
                $where_conditions[] = 't.user_id = %d';
                $query_params[] = $user->ID;
            }
        }
        
        if ($type_filter) {
            $where_conditions[] = 't.transaction_type = %s';
            $query_params[] = $type_filter;
        }
        
        if ($date_from) {
            $where_conditions[] = 'DATE(t.created_at) >= %s';
            $query_params[] = $date_from;
        }
        
        if ($date_to) {
            $where_conditions[] = 'DATE(t.created_at) <= %s';
            $query_params[] = $date_to;
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        
        // Get total count
        $total_query = "SELECT COUNT(*) FROM $transactions_table t WHERE $where_clause";
        if ($query_params) {
            $total_query = $wpdb->prepare($total_query, $query_params);
        }
        $total_items = $wpdb->get_var($total_query);
        
        // Get transactions
        $query_params[] = $per_page;
        $query_params[] = $offset;
        
        $transactions_query = "
            SELECT t.*, pt.slug as point_type_slug, pt.name as point_type_name, pt.icon as point_type_icon,
                   u.display_name, u.user_login, u.user_email
            FROM $transactions_table t
            JOIN $point_types_table pt ON t.point_type_id = pt.id
            JOIN {$wpdb->users} u ON t.user_id = u.ID
            WHERE $where_clause
            ORDER BY t.created_at DESC
            LIMIT %d OFFSET %d
        ";
        
        $transactions = $wpdb->get_results($wpdb->prepare($transactions_query, $query_params));
        
        // Calculate pagination
        $total_pages = ceil($total_items / $per_page);
        
        ?>
        <div class="wrap">
            <h1>Transaction History</h1>
            
            <!-- Filters -->
            <div class="transaction-filters">
                <form method="get">
                    <input type="hidden" name="page" value="dollarbets-transactions">
                    
                    <input type="text" name="user_filter" value="<?php echo esc_attr($user_filter); ?>" placeholder="User login or email">
                    
                    <select name="type_filter">
                        <option value="">All Types</option>
                        <option value="earn" <?php selected($type_filter, 'earn'); ?>>Earn</option>
                        <option value="spend" <?php selected($type_filter, 'spend'); ?>>Spend</option>
                        <option value="adjust" <?php selected($type_filter, 'adjust'); ?>>Adjust</option>
                        <option value="purchase" <?php selected($type_filter, 'purchase'); ?>>Purchase</option>
                        <option value="refund" <?php selected($type_filter, 'refund'); ?>>Refund</option>
                    </select>
                    
                    <input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>" placeholder="From date">
                    <input type="date" name="date_to" value="<?php echo esc_attr($date_to); ?>" placeholder="To date">
                    
                    <button type="submit" class="button">Filter</button>
                    <a href="<?php echo admin_url('admin.php?page=dollarbets-transactions'); ?>" class="button">Clear</a>
                </form>
            </div>
            
            <!-- Transactions Table -->
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>User</th>
                        <th>Type</th>
                        <th>Point Type</th>
                        <th>Amount</th>
                        <th>Balance Before</th>
                        <th>Balance After</th>
                        <th>Reason</th>
                        <th>Reference</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($transactions): ?>
                        <?php foreach ($transactions as $transaction): ?>
                            <tr>
                                <td><?php echo date('M j, Y H:i', strtotime($transaction->created_at)); ?></td>
                                <td>
                                    <strong><?php echo esc_html($transaction->display_name); ?></strong><br>
                                    <small><?php echo esc_html($transaction->user_email); ?></small>
                                </td>
                                <td>
                                    <span class="transaction-type transaction-<?php echo esc_attr($transaction->transaction_type); ?>">
                                        <?php echo esc_html(ucfirst($transaction->transaction_type)); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo esc_html($transaction->point_type_icon); ?>
                                    <?php echo esc_html($transaction->point_type_name); ?>
                                </td>
                                <td>
                                    <span class="amount-<?php echo in_array($transaction->transaction_type, ['earn', 'purchase', 'refund']) ? 'positive' : 'negative'; ?>">
                                        <?php echo in_array($transaction->transaction_type, ['earn', 'purchase', 'refund']) ? '+' : '-'; ?>
                                        <?php echo number_format($transaction->amount, 2); ?>
                                    </span>
                                </td>
                                <td><?php echo number_format($transaction->balance_before, 2); ?></td>
                                <td><?php echo number_format($transaction->balance_after, 2); ?></td>
                                <td><?php echo esc_html($transaction->reason); ?></td>
                                <td>
                                    <?php if ($transaction->reference_type && $transaction->reference_id): ?>
                                        <small>
                                            <?php echo esc_html($transaction->reference_type); ?>:
                                            <?php echo esc_html($transaction->reference_id); ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9">No transactions found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="tablenav">
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php echo number_format($total_items); ?> items</span>
                        <?php
                        $page_links = paginate_links([
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total' => $total_pages,
                            'current' => $current_page
                        ]);
                        echo $page_links;
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Handle form submissions
     */
    public function handle_form_submissions() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Handle point type addition
        if (isset($_POST['action']) && $_POST['action'] === 'add_point_type' && wp_verify_nonce($_POST['point_type_nonce'], 'dollarbets_add_point_type')) {
            $this->handle_add_point_type();
        }
        
        // Handle earning rule addition
        if (isset($_POST['action']) && $_POST['action'] === 'add_earning_rule' && wp_verify_nonce($_POST['earning_rule_nonce'], 'dollarbets_add_earning_rule')) {
            $this->handle_add_earning_rule();
        }
        
        // Handle rank addition
        if (isset($_POST['action']) && $_POST['action'] === 'add_rank' && wp_verify_nonce($_POST['rank_nonce'], 'dollarbets_add_rank')) {
            $this->handle_add_rank();
        }
        
        // Handle achievement addition
        if (isset($_POST['action']) && $_POST['action'] === 'add_achievement' && wp_verify_nonce($_POST['achievement_nonce'], 'dollarbets_add_achievement')) {
            $this->handle_add_achievement();
        }
    }
    
    /**
     * Handle AJAX actions
     */
    public function handle_ajax_actions() {
        if (!wp_verify_nonce($_POST['nonce'], 'dollarbets_admin_nonce') || !current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $action = sanitize_text_field($_POST['admin_action']);
        
        switch ($action) {
            case 'adjust_points':
                $this->ajax_adjust_points();
                break;
            case 'recalculate_ranks':
                $this->ajax_recalculate_ranks();
                break;
            default:
                wp_send_json_error('Invalid action');
        }
    }
    
    /**
     * Handle point type addition
     */
    private function handle_add_point_type() {
        global $wpdb;
        
        $data = [
            'slug' => sanitize_text_field($_POST['slug']),
            'name' => sanitize_text_field($_POST['name']),
            'description' => sanitize_textarea_field($_POST['description']),
            'icon' => sanitize_text_field($_POST['icon']),
            'decimal_places' => intval($_POST['decimal_places'])
        ];
        
        $point_types_table = $wpdb->prefix . 'dollarbets_point_types';
        $result = $wpdb->insert($point_types_table, $data);
        
        if ($result) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success"><p>Point type added successfully!</p></div>';
            });
        } else {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>Failed to add point type.</p></div>';
            });
        }
    }
    
    /**
     * Handle earning rule addition
     */
    private function handle_add_earning_rule() {
        $earning_rules = new DollarBets_Earning_Rules();
        
        $data = [
            'name' => sanitize_text_field($_POST['name']),
            'description' => sanitize_textarea_field($_POST['description']),
            'trigger_action' => sanitize_text_field($_POST['trigger_action']),
            'points_awarded' => floatval($_POST['points_awarded']),
            'max_daily_awards' => !empty($_POST['max_daily_awards']) ? intval($_POST['max_daily_awards']) : null,
            'max_total_awards' => !empty($_POST['max_total_awards']) ? intval($_POST['max_total_awards']) : null,
            'priority' => intval($_POST['priority'])
        ];
        
        $result = $earning_rules->create_rule($data);
        
        if ($result) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success"><p>Earning rule added successfully!</p></div>';
            });
        } else {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>Failed to add earning rule.</p></div>';
            });
        }
    }
    
    /**
     * Handle rank addition
     */
    private function handle_add_rank() {
        $rank_manager = new DollarBets_Rank_Manager();
        
        $data = [
            'slug' => sanitize_text_field($_POST['slug']),
            'name' => sanitize_text_field($_POST['name']),
            'description' => sanitize_textarea_field($_POST['description']),
            'icon' => sanitize_text_field($_POST['icon']),
            'badge_color' => sanitize_text_field($_POST['badge_color']),
            'points_required' => floatval($_POST['points_required']),
            'order_position' => intval($_POST['order_position'])
        ];
        
        $result = $rank_manager->create_rank($data);
        
        if ($result) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success"><p>Rank added successfully!</p></div>';
            });
        } else {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>Failed to add rank.</p></div>';
            });
        }
    }
    
    /**
     * Handle achievement addition
     */
    private function handle_add_achievement() {
        $achievement_manager = new DollarBets_Achievement_Manager();
        
        $data = [
            'slug' => sanitize_text_field($_POST['slug']),
            'name' => sanitize_text_field($_POST['name']),
            'description' => sanitize_textarea_field($_POST['description']),
            'icon' => sanitize_text_field($_POST['icon']),
            'badge_color' => sanitize_text_field($_POST['badge_color']),
            'points_reward' => floatval($_POST['points_reward']),
            'unlock_conditions' => sanitize_textarea_field($_POST['unlock_conditions']),
            'is_secret' => isset($_POST['is_secret']) ? 1 : 0,
            'order_position' => intval($_POST['order_position'])
        ];
        
        $result = $achievement_manager->create_achievement($data);
        
        if ($result) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success"><p>Achievement added successfully!</p></div>';
            });
        } else {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>Failed to add achievement.</p></div>';
            });
        }
    }
    
    /**
     * AJAX: Adjust user points
     */
    private function ajax_adjust_points() {
        $user_id = intval($_POST['user_id']);
        $point_type = sanitize_text_field($_POST['point_type']);
        $adjustment_type = sanitize_text_field($_POST['adjustment_type']);
        $amount = floatval($_POST['amount']);
        $reason = sanitize_text_field($_POST['reason']);
        
        $point_manager = new DollarBets_Point_Manager();
        
        switch ($adjustment_type) {
            case 'add':
                $result = $point_manager->award_points($user_id, $amount, $point_type, $reason, get_current_user_id());
                break;
            case 'deduct':
                $result = $point_manager->deduct_points($user_id, $amount, $point_type, $reason);
                break;
            case 'set':
                $result = $point_manager->set_points($user_id, $amount, $point_type, $reason, get_current_user_id());
                break;
            default:
                wp_send_json_error('Invalid adjustment type');
                return;
        }
        
        if ($result !== false) {
            wp_send_json_success(['new_balance' => $result]);
        } else {
            wp_send_json_error('Failed to adjust points');
        }
    }
    
    /**
     * AJAX: Recalculate all user ranks
     */
    private function ajax_recalculate_ranks() {
        $rank_manager = new DollarBets_Rank_Manager();
        $updated = $rank_manager->recalculate_all_ranks();
        
        wp_send_json_success(['updated_users' => $updated]);
    }
}

// Initialize admin interface
new DollarBets_Admin_Point_System();


<?php
/**
 * Plugin Name: DollarBets Platform (Custom Point System)
 * Description: A comprehensive betting platform with custom point system, predictions, and Ultimate Member integration.
 * Version: 2.0.0
 * Author: DollarBets Team
 * Text Domain: dollarbets
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Define plugin constants
define('DOLLARBETS_VERSION', '2.0.0');
define('DOLLARBETS_PATH', plugin_dir_path(__FILE__));
define('DOLLARBETS_URL', plugin_dir_url(__FILE__));

/**
 * Main DollarBets Platform Class
 */
class DollarBets_Platform {
    
    public function __construct() {
        add_action('plugins_loaded', [$this, 'init']);
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
    }
    
    /**
     * Initialize the plugin
     */
    public function init() {
        // Load dependencies
        $this->load_dependencies();
        
        // Initialize components
        $this->init_hooks();
        
        // Load text domain
        load_plugin_textdomain('dollarbets', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
    
    /**
     * Load plugin dependencies
     */
    private function load_dependencies() {
        // Core custom point system
        require_once DOLLARBETS_PATH . 'includes/custom-point-system.php';
        require_once DOLLARBETS_PATH . 'includes/point-manager.php';
        require_once DOLLARBETS_PATH . 'includes/rank-manager.php';
        require_once DOLLARBETS_PATH . 'includes/achievement-manager.php';
        require_once DOLLARBETS_PATH . 'includes/earning-rules.php';
        
        // Admin interface
        require_once DOLLARBETS_PATH . 'includes/admin-point-system.php';
        
        // Fixed payment system
        require_once DOLLARBETS_PATH . 'includes/payment-gateway-fixed.php';
        
        // Ultimate Member integration
        require_once DOLLARBETS_PATH . 'includes/ultimate-member-integration.php';
        require_once DOLLARBETS_PATH . 'includes/ultimate-member-transactions.php';
        
        // Shortcodes (updated to use custom system)
        require_once DOLLARBETS_PATH . 'shortcodes/custom-point-display.php';
        require_once DOLLARBETS_PATH . 'shortcodes/leaderboard.php';
        require_once DOLLARBETS_PATH . 'shortcodes/user-achievements.php';
        
        // Other existing functionality
        require_once DOLLARBETS_PATH . 'includes/predictions.php';
        require_once DOLLARBETS_PATH . 'includes/betting-system.php';
        require_once DOLLARBETS_PATH . 'includes/notifications.php';
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Enqueue scripts and styles
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        
        // AJAX handlers
        add_action('wp_ajax_dollarbets_place_bet', [$this, 'handle_place_bet']);
        add_action('wp_ajax_dollarbets_get_user_stats', [$this, 'handle_get_user_stats']);
        add_action('wp_ajax_dollarbets_get_leaderboard', [$this, 'handle_get_leaderboard']);
        
        // REST API
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        
        // Custom point system hooks
        add_action('user_register', [$this, 'award_registration_points']);
        add_action('wp_login', [$this, 'award_login_points'], 10, 2);
        add_action('profile_update', [$this, 'award_profile_update_points']);
        
        // Betting hooks
        add_action('dollarbets_bet_placed', [$this, 'award_bet_points'], 10, 3);
        add_action('dollarbets_bet_won', [$this, 'award_win_points'], 10, 3);
        add_action('dollarbets_prediction_created', [$this, 'award_prediction_points'], 10, 2);
    }
    
    /**
     * Enqueue frontend scripts and styles
     */
    public function enqueue_scripts() {
        // Main frontend script
        wp_enqueue_script(
            'dollarbets-frontend',
            DOLLARBETS_URL . 'assets/js/frontend-app.js',
            ['jquery'],
            DOLLARBETS_VERSION,
            true
        );
        
        // Frontend styles
        wp_enqueue_style(
            'dollarbets-frontend',
            DOLLARBETS_URL . 'assets/css/frontend-styles.css',
            [],
            DOLLARBETS_VERSION
        );
        
        // Localize script with configuration
        wp_localize_script('dollarbets-frontend', 'dollarbetsConfig', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'restUrl' => rest_url('dollarbets/v1/'),
            'nonce' => wp_create_nonce('dollarbets_nonce'),
            'isLoggedIn' => is_user_logged_in(),
            'userId' => get_current_user_id(),
            'userBalance' => is_user_logged_in() ? db_get_user_points(get_current_user_id(), 'betcoins') : 0,
            'userRank' => is_user_logged_in() ? $this->get_user_rank_info(get_current_user_id()) : null
        ]);
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        // Only load on our admin pages
        if (strpos($hook, 'dollarbets') === false) {
            return;
        }
        
        wp_enqueue_script('jquery');
        wp_enqueue_script('jquery-ui-datepicker');
        wp_enqueue_style('jquery-ui-datepicker', 'https://code.jquery.com/ui/1.12.1/themes/ui-lightness/jquery-ui.css');
    }
    
    /**
     * Register REST API routes
     */
    public function register_rest_routes() {
        register_rest_route('dollarbets/v1', '/user-stats/(?P<user_id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_user_stats_api'],
            'permission_callback' => '__return_true'
        ]);
        
        register_rest_route('dollarbets/v1', '/leaderboard', [
            'methods' => 'GET',
            'callback' => [$this, 'get_leaderboard_api'],
            'permission_callback' => '__return_true'
        ]);
        
        register_rest_route('dollarbets/v1', '/place-bet', [
            'methods' => 'POST',
            'callback' => [$this, 'place_bet_api'],
            'permission_callback' => function() {
                return is_user_logged_in();
            }
        ]);
    }
    
    /**
     * Handle bet placement via AJAX
     */
    public function handle_place_bet() {
        check_ajax_referer('dollarbets_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error('User not logged in');
        }
        
        $prediction_id = intval($_POST['prediction_id']);
        $amount = intval($_POST['amount']);
        $choice = sanitize_text_field($_POST['choice']);
        
        $result = $this->place_bet($prediction_id, $amount, $choice);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    /**
     * Place a bet
     */
    private function place_bet($prediction_id, $amount, $choice) {
        $user_id = get_current_user_id();
        
        // Check user balance
        $current_balance = db_get_user_points($user_id, 'betcoins');
        if ($current_balance < $amount) {
            return ['success' => false, 'message' => 'Insufficient BetCoins'];
        }
        
        // Deduct points
        $new_balance = db_deduct_points($user_id, $amount, 'betcoins', 'Bet placed on prediction #' . $prediction_id);
        
        if ($new_balance === false) {
            return ['success' => false, 'message' => 'Failed to deduct points'];
        }
        
        // Store bet
        global $wpdb;
        $bets_table = $wpdb->prefix . 'dollarbets_bets';
        
        $result = $wpdb->insert($bets_table, [
            'user_id' => $user_id,
            'prediction_id' => $prediction_id,
            'amount' => $amount,
            'choice' => $choice,
            'placed_at' => current_time('mysql')
        ]);
        
        if ($result) {
            // Trigger action for earning rules
            do_action('dollarbets_bet_placed', $user_id, $prediction_id, $amount);
            
            return [
                'success' => true,
                'new_balance' => $new_balance,
                'bet_id' => $wpdb->insert_id
            ];
        } else {
            // Refund points if bet storage failed
            db_award_points($user_id, $amount, 'betcoins', 'Bet refund - storage failed');
            return ['success' => false, 'message' => 'Failed to place bet'];
        }
    }
    
    /**
     * Award points for user registration
     */
    public function award_registration_points($user_id) {
        $earning_rules = new DollarBets_Earning_Rules();
        $earning_rules->process_action('user_register', $user_id);
    }
    
    /**
     * Award points for user login
     */
    public function award_login_points($user_login, $user) {
        $earning_rules = new DollarBets_Earning_Rules();
        $earning_rules->process_action('user_login', $user->ID);
    }
    
    /**
     * Award points for profile update
     */
    public function award_profile_update_points($user_id) {
        $earning_rules = new DollarBets_Earning_Rules();
        $earning_rules->process_action('profile_update', $user_id);
    }
    
    /**
     * Award points for placing bets
     */
    public function award_bet_points($user_id, $prediction_id, $amount) {
        $earning_rules = new DollarBets_Earning_Rules();
        $earning_rules->process_action('bet_placed', $user_id, ['amount' => $amount]);
    }
    
    /**
     * Award points for winning bets
     */
    public function award_win_points($user_id, $prediction_id, $amount) {
        $earning_rules = new DollarBets_Earning_Rules();
        $earning_rules->process_action('bet_won', $user_id, ['amount' => $amount]);
    }
    
    /**
     * Award points for creating predictions
     */
    public function award_prediction_points($user_id, $prediction_id) {
        $earning_rules = new DollarBets_Earning_Rules();
        $earning_rules->process_action('prediction_created', $user_id);
    }
    
    /**
     * Get user rank information
     */
    private function get_user_rank_info($user_id) {
        $rank_manager = new DollarBets_Rank_Manager();
        return $rank_manager->get_user_rank($user_id);
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Create database tables
        $this->create_database_tables();
        
        // Create default point types
        $this->create_default_point_types();
        
        // Create default ranks
        $this->create_default_ranks();
        
        // Create default earning rules
        $this->create_default_earning_rules();
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Create database tables
     */
    private function create_database_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Point types table
        $point_types_table = $wpdb->prefix . 'dollarbets_point_types';
        $sql1 = "CREATE TABLE $point_types_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            slug varchar(50) NOT NULL UNIQUE,
            name varchar(100) NOT NULL,
            description text,
            icon varchar(10) DEFAULT '💰',
            decimal_places tinyint(1) DEFAULT 0,
            is_active tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY slug (slug)
        ) $charset_collate;";
        
        // User points table
        $user_points_table = $wpdb->prefix . 'dollarbets_user_points';
        $sql2 = "CREATE TABLE $user_points_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            point_type_id int(11) NOT NULL,
            balance decimal(15,4) DEFAULT 0,
            total_earned decimal(15,4) DEFAULT 0,
            total_spent decimal(15,4) DEFAULT 0,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_point_type (user_id, point_type_id),
            KEY user_id (user_id),
            KEY point_type_id (point_type_id)
        ) $charset_collate;";
        
        // Point transactions table
        $transactions_table = $wpdb->prefix . 'dollarbets_point_transactions';
        $sql3 = "CREATE TABLE $transactions_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            point_type_id int(11) NOT NULL,
            transaction_type enum('earn','spend','adjust','purchase','refund') NOT NULL,
            amount decimal(15,4) NOT NULL,
            balance_before decimal(15,4) NOT NULL,
            balance_after decimal(15,4) NOT NULL,
            reason text,
            reference_type varchar(50),
            reference_id varchar(100),
            admin_id bigint(20),
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY point_type_id (point_type_id),
            KEY transaction_type (transaction_type),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        // Ranks table
        $ranks_table = $wpdb->prefix . 'dollarbets_ranks';
        $sql4 = "CREATE TABLE $ranks_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            slug varchar(50) NOT NULL UNIQUE,
            name varchar(100) NOT NULL,
            description text,
            icon varchar(10) DEFAULT '🏆',
            badge_color varchar(7) DEFAULT '#000000',
            points_required decimal(15,4) DEFAULT 0,
            order_position int(11) DEFAULT 0,
            is_active tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY slug (slug),
            KEY points_required (points_required),
            KEY order_position (order_position)
        ) $charset_collate;";
        
        // User ranks table
        $user_ranks_table = $wpdb->prefix . 'dollarbets_user_ranks';
        $sql5 = "CREATE TABLE $user_ranks_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            rank_id int(11) NOT NULL,
            achieved_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_id (user_id),
            KEY rank_id (rank_id)
        ) $charset_collate;";
        
        // Achievements table
        $achievements_table = $wpdb->prefix . 'dollarbets_achievements';
        $sql6 = "CREATE TABLE $achievements_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            slug varchar(50) NOT NULL UNIQUE,
            name varchar(100) NOT NULL,
            description text,
            icon varchar(10) DEFAULT '🏆',
            badge_color varchar(7) DEFAULT '#000000',
            points_reward decimal(15,4) DEFAULT 0,
            unlock_conditions text,
            is_secret tinyint(1) DEFAULT 0,
            order_position int(11) DEFAULT 0,
            is_active tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY slug (slug),
            KEY order_position (order_position)
        ) $charset_collate;";
        
        // User achievements table
        $user_achievements_table = $wpdb->prefix . 'dollarbets_user_achievements';
        $sql7 = "CREATE TABLE $user_achievements_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            achievement_id int(11) NOT NULL,
            unlocked_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_achievement (user_id, achievement_id),
            KEY user_id (user_id),
            KEY achievement_id (achievement_id)
        ) $charset_collate;";
        
        // Earning rules table
        $earning_rules_table = $wpdb->prefix . 'dollarbets_earning_rules';
        $sql8 = "CREATE TABLE $earning_rules_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL,
            description text,
            trigger_action varchar(50) NOT NULL,
            point_type_id int(11) NOT NULL,
            points_awarded decimal(15,4) NOT NULL,
            max_daily_awards int(11),
            max_total_awards int(11),
            priority int(11) DEFAULT 0,
            is_active tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY trigger_action (trigger_action),
            KEY point_type_id (point_type_id),
            KEY priority (priority)
        ) $charset_collate;";
        
        // Bets table (existing)
        $bets_table = $wpdb->prefix . 'dollarbets_bets';
        $sql9 = "CREATE TABLE $bets_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            prediction_id int(11) NOT NULL,
            amount decimal(15,4) NOT NULL,
            choice varchar(50) NOT NULL,
            status enum('pending','won','lost','cancelled') DEFAULT 'pending',
            placed_at datetime DEFAULT CURRENT_TIMESTAMP,
            resolved_at datetime,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY prediction_id (prediction_id),
            KEY status (status)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql1);
        dbDelta($sql2);
        dbDelta($sql3);
        dbDelta($sql4);
        dbDelta($sql5);
        dbDelta($sql6);
        dbDelta($sql7);
        dbDelta($sql8);
        dbDelta($sql9);
    }
    
    /**
     * Create default point types
     */
    private function create_default_point_types() {
        global $wpdb;
        
        $point_types_table = $wpdb->prefix . 'dollarbets_point_types';
        
        $default_types = [
            [
                'slug' => 'betcoins',
                'name' => 'BetCoins',
                'description' => 'Primary currency for placing bets',
                'icon' => '💰',
                'decimal_places' => 0
            ],
            [
                'slug' => 'experience',
                'name' => 'Experience Points',
                'description' => 'Points earned through platform activities',
                'icon' => '⭐',
                'decimal_places' => 0
            ]
        ];
        
        foreach ($default_types as $type) {
            $wpdb->replace($point_types_table, $type);
        }
    }
    
    /**
     * Create default ranks
     */
    private function create_default_ranks() {
        global $wpdb;
        
        $ranks_table = $wpdb->prefix . 'dollarbets_ranks';
        
        $default_ranks = [
            ['slug' => 'rookie', 'name' => 'Rookie', 'icon' => '🥉', 'points_required' => 0, 'order_position' => 1],
            ['slug' => 'amateur', 'name' => 'Amateur', 'icon' => '🥈', 'points_required' => 100, 'order_position' => 2],
            ['slug' => 'pro', 'name' => 'Pro', 'icon' => '🥇', 'points_required' => 500, 'order_position' => 3],
            ['slug' => 'expert', 'name' => 'Expert', 'icon' => '🏆', 'points_required' => 1000, 'order_position' => 4],
            ['slug' => 'legend', 'name' => 'Legend', 'icon' => '👑', 'points_required' => 2500, 'order_position' => 5]
        ];
        
        foreach ($default_ranks as $rank) {
            $wpdb->replace($ranks_table, $rank);
        }
    }
    
    /**
     * Create default earning rules
     */
    private function create_default_earning_rules() {
        global $wpdb;
        
        $earning_rules_table = $wpdb->prefix . 'dollarbets_earning_rules';
        $point_types_table = $wpdb->prefix . 'dollarbets_point_types';
        
        // Get point type IDs
        $betcoins_id = $wpdb->get_var("SELECT id FROM $point_types_table WHERE slug = 'betcoins'");
        $experience_id = $wpdb->get_var("SELECT id FROM $point_types_table WHERE slug = 'experience'");
        
        $default_rules = [
            [
                'name' => 'Registration Bonus',
                'trigger_action' => 'user_register',
                'point_type_id' => $betcoins_id,
                'points_awarded' => 100,
                'max_total_awards' => 1
            ],
            [
                'name' => 'Daily Login',
                'trigger_action' => 'user_login',
                'point_type_id' => $experience_id,
                'points_awarded' => 5,
                'max_daily_awards' => 1
            ],
            [
                'name' => 'Profile Update',
                'trigger_action' => 'profile_update',
                'point_type_id' => $experience_id,
                'points_awarded' => 10,
                'max_total_awards' => 5
            ],
            [
                'name' => 'Bet Placed',
                'trigger_action' => 'bet_placed',
                'point_type_id' => $experience_id,
                'points_awarded' => 2
            ],
            [
                'name' => 'Bet Won',
                'trigger_action' => 'bet_won',
                'point_type_id' => $experience_id,
                'points_awarded' => 5
            ]
        ];
        
        foreach ($default_rules as $rule) {
            $wpdb->replace($earning_rules_table, $rule);
        }
    }
}

// Initialize the plugin
new DollarBets_Platform();


<?php
/**
 * Custom Point System for DollarBets Platform
 * Replaces Gamipress functionality with a custom implementation
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Main Point System Class
 */
class DollarBets_Custom_Point_System {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        add_action('init', [$this, 'init']);
        register_activation_hook(DOLLARBETS_PATH . 'dollarbets-platform.php', [$this, 'create_tables']);
    }
    
    public function init() {
        // Initialize point system components
        $this->load_components();
        
        // Add default point type if not exists
        $this->ensure_default_point_types();
        
        // Hook into WordPress actions
        add_action('user_register', [$this, 'initialize_user_points']);
    }
    
    /**
     * Load point system components
     */
    private function load_components() {
        require_once DOLLARBETS_PATH . 'includes/point-manager.php';
        require_once DOLLARBETS_PATH . 'includes/rank-manager.php';
        require_once DOLLARBETS_PATH . 'includes/achievement-manager.php';
        require_once DOLLARBETS_PATH . 'includes/earning-rules.php';
    }
    
    /**
     * Create database tables for the custom point system
     */
    public function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Point Types Table
        $point_types_table = $wpdb->prefix . 'dollarbets_point_types';
        $sql_point_types = "CREATE TABLE IF NOT EXISTS $point_types_table (
            id INT AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(50) NOT NULL UNIQUE,
            name VARCHAR(100) NOT NULL,
            description TEXT,
            icon VARCHAR(255),
            decimal_places INT DEFAULT 0,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) $charset_collate;";
        
        // User Points Table
        $user_points_table = $wpdb->prefix . 'dollarbets_user_points';
        $sql_user_points = "CREATE TABLE IF NOT EXISTS $user_points_table (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT NOT NULL,
            point_type_id INT NOT NULL,
            balance DECIMAL(15,2) DEFAULT 0.00,
            total_earned DECIMAL(15,2) DEFAULT 0.00,
            total_spent DECIMAL(15,2) DEFAULT 0.00,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_user_point_type (user_id, point_type_id),
            INDEX idx_user_id (user_id),
            INDEX idx_point_type_id (point_type_id)
        ) $charset_collate;";
        
        // Point Transactions Table
        $transactions_table = $wpdb->prefix . 'dollarbets_point_transactions';
        $sql_transactions = "CREATE TABLE IF NOT EXISTS $transactions_table (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT NOT NULL,
            point_type_id INT NOT NULL,
            transaction_type ENUM('earn', 'spend', 'adjust', 'purchase', 'refund') NOT NULL,
            amount DECIMAL(15,2) NOT NULL,
            balance_before DECIMAL(15,2) NOT NULL,
            balance_after DECIMAL(15,2) NOT NULL,
            reason VARCHAR(255),
            reference_id VARCHAR(100),
            reference_type VARCHAR(50),
            admin_user_id BIGINT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id),
            INDEX idx_point_type_id (point_type_id),
            INDEX idx_transaction_type (transaction_type),
            INDEX idx_created_at (created_at),
            INDEX idx_reference (reference_type, reference_id)
        ) $charset_collate;";
        
        // Earning Rules Table
        $earning_rules_table = $wpdb->prefix . 'dollarbets_earning_rules';
        $sql_earning_rules = "CREATE TABLE IF NOT EXISTS $earning_rules_table (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            description TEXT,
            point_type_id INT NOT NULL,
            trigger_action VARCHAR(50) NOT NULL,
            points_awarded DECIMAL(15,2) NOT NULL,
            max_daily_awards INT DEFAULT NULL,
            max_total_awards INT DEFAULT NULL,
            conditions JSON,
            is_active BOOLEAN DEFAULT TRUE,
            priority INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_trigger_action (trigger_action),
            INDEX idx_is_active (is_active),
            INDEX idx_priority (priority)
        ) $charset_collate;";
        
        // Ranks Table
        $ranks_table = $wpdb->prefix . 'dollarbets_ranks';
        $sql_ranks = "CREATE TABLE IF NOT EXISTS $ranks_table (
            id INT AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(50) NOT NULL UNIQUE,
            name VARCHAR(100) NOT NULL,
            description TEXT,
            icon VARCHAR(255),
            badge_color VARCHAR(7) DEFAULT '#000000',
            point_type_id INT NOT NULL,
            points_required DECIMAL(15,2) NOT NULL,
            order_position INT DEFAULT 0,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_points_required (points_required),
            INDEX idx_order_position (order_position),
            INDEX idx_is_active (is_active)
        ) $charset_collate;";
        
        // User Ranks Table
        $user_ranks_table = $wpdb->prefix . 'dollarbets_user_ranks';
        $sql_user_ranks = "CREATE TABLE IF NOT EXISTS $user_ranks_table (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT NOT NULL,
            rank_id INT NOT NULL,
            achieved_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            is_current BOOLEAN DEFAULT TRUE,
            INDEX idx_user_id (user_id),
            INDEX idx_rank_id (rank_id),
            INDEX idx_is_current (is_current)
        ) $charset_collate;";
        
        // Achievements Table
        $achievements_table = $wpdb->prefix . 'dollarbets_achievements';
        $sql_achievements = "CREATE TABLE IF NOT EXISTS $achievements_table (
            id INT AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(50) NOT NULL UNIQUE,
            name VARCHAR(100) NOT NULL,
            description TEXT,
            icon VARCHAR(255),
            badge_color VARCHAR(7) DEFAULT '#000000',
            point_type_id INT NOT NULL,
            points_reward DECIMAL(15,2) DEFAULT 0.00,
            unlock_conditions JSON,
            is_active BOOLEAN DEFAULT TRUE,
            is_secret BOOLEAN DEFAULT FALSE,
            order_position INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_is_active (is_active),
            INDEX idx_order_position (order_position)
        ) $charset_collate;";
        
        // User Achievements Table
        $user_achievements_table = $wpdb->prefix . 'dollarbets_user_achievements';
        $sql_user_achievements = "CREATE TABLE IF NOT EXISTS $user_achievements_table (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT NOT NULL,
            achievement_id INT NOT NULL,
            unlocked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            progress JSON,
            UNIQUE KEY unique_user_achievement (user_id, achievement_id),
            INDEX idx_user_id (user_id),
            INDEX idx_achievement_id (achievement_id),
            INDEX idx_unlocked_at (unlocked_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        dbDelta($sql_point_types);
        dbDelta($sql_user_points);
        dbDelta($sql_transactions);
        dbDelta($sql_earning_rules);
        dbDelta($sql_ranks);
        dbDelta($sql_user_ranks);
        dbDelta($sql_achievements);
        dbDelta($sql_user_achievements);
    }
    
    /**
     * Ensure default point types exist
     */
    private function ensure_default_point_types() {
        global $wpdb;
        
        $point_types_table = $wpdb->prefix . 'dollarbets_point_types';
        
        // Check if BetCoins point type exists
        $betcoins_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $point_types_table WHERE slug = %s",
            'betcoins'
        ));
        
        if (!$betcoins_exists) {
            $wpdb->insert($point_types_table, [
                'slug' => 'betcoins',
                'name' => 'BetCoins',
                'description' => 'Primary currency for placing bets on predictions',
                'icon' => '💰',
                'decimal_places' => 0,
                'is_active' => 1
            ]);
        }
        
        // Check if Experience point type exists
        $experience_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $point_types_table WHERE slug = %s",
            'experience'
        ));
        
        if (!$experience_exists) {
            $wpdb->insert($point_types_table, [
                'slug' => 'experience',
                'name' => 'Experience Points',
                'description' => 'Points earned through platform activity and achievements',
                'icon' => '⭐',
                'decimal_places' => 0,
                'is_active' => 1
            ]);
        }
        
        // Create default ranks
        $this->create_default_ranks();
        
        // Create default achievements
        $this->create_default_achievements();
        
        // Create default earning rules
        $this->create_default_earning_rules();
    }
    
    /**
     * Create default ranks
     */
    private function create_default_ranks() {
        global $wpdb;
        
        $ranks_table = $wpdb->prefix . 'dollarbets_ranks';
        $point_types_table = $wpdb->prefix . 'dollarbets_point_types';
        
        // Get BetCoins point type ID
        $betcoins_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $point_types_table WHERE slug = %s",
            'betcoins'
        ));
        
        if (!$betcoins_id) return;
        
        $default_ranks = [
            [
                'slug' => 'rookie',
                'name' => 'Rookie Predictor',
                'description' => 'Just starting your prediction journey',
                'icon' => '🥉',
                'badge_color' => '#CD7F32',
                'points_required' => 0,
                'order_position' => 1
            ],
            [
                'slug' => 'amateur',
                'name' => 'Amateur Analyst',
                'description' => 'Getting the hang of predictions',
                'icon' => '🥈',
                'badge_color' => '#C0C0C0',
                'points_required' => 1000,
                'order_position' => 2
            ],
            [
                'slug' => 'skilled',
                'name' => 'Skilled Strategist',
                'description' => 'Showing real prediction skills',
                'icon' => '🥇',
                'badge_color' => '#FFD700',
                'points_required' => 5000,
                'order_position' => 3
            ],
            [
                'slug' => 'expert',
                'name' => 'Expert Forecaster',
                'description' => 'A true prediction expert',
                'icon' => '💎',
                'badge_color' => '#00BFFF',
                'points_required' => 15000,
                'order_position' => 4
            ],
            [
                'slug' => 'master',
                'name' => 'Master Oracle',
                'description' => 'The ultimate prediction master',
                'icon' => '👑',
                'badge_color' => '#9932CC',
                'points_required' => 50000,
                'order_position' => 5
            ]
        ];
        
        foreach ($default_ranks as $rank) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $ranks_table WHERE slug = %s",
                $rank['slug']
            ));
            
            if (!$exists) {
                $rank['point_type_id'] = $betcoins_id;
                $wpdb->insert($ranks_table, $rank);
            }
        }
    }
    
    /**
     * Create default achievements
     */
    private function create_default_achievements() {
        global $wpdb;
        
        $achievements_table = $wpdb->prefix . 'dollarbets_achievements';
        $point_types_table = $wpdb->prefix . 'dollarbets_point_types';
        
        // Get BetCoins point type ID
        $betcoins_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $point_types_table WHERE slug = %s",
            'betcoins'
        ));
        
        if (!$betcoins_id) return;
        
        $default_achievements = [
            [
                'slug' => 'first_bet',
                'name' => 'First Bet',
                'description' => 'Place your first prediction bet',
                'icon' => '🎯',
                'badge_color' => '#4CAF50',
                'points_reward' => 100,
                'unlock_conditions' => json_encode(['bets_placed' => 1])
            ],
            [
                'slug' => 'ten_bets',
                'name' => 'Getting Started',
                'description' => 'Place 10 prediction bets',
                'icon' => '🔥',
                'badge_color' => '#FF9800',
                'points_reward' => 500,
                'unlock_conditions' => json_encode(['bets_placed' => 10])
            ],
            [
                'slug' => 'first_win',
                'name' => 'Lucky Winner',
                'description' => 'Win your first prediction',
                'icon' => '🍀',
                'badge_color' => '#8BC34A',
                'points_reward' => 200,
                'unlock_conditions' => json_encode(['bets_won' => 1])
            ],
            [
                'slug' => 'big_spender',
                'name' => 'Big Spender',
                'description' => 'Spend 10,000 BetCoins on predictions',
                'icon' => '💸',
                'badge_color' => '#E91E63',
                'points_reward' => 1000,
                'unlock_conditions' => json_encode(['total_spent' => 10000])
            ],
            [
                'slug' => 'daily_player',
                'name' => 'Daily Player',
                'description' => 'Place bets for 7 consecutive days',
                'icon' => '📅',
                'badge_color' => '#2196F3',
                'points_reward' => 750,
                'unlock_conditions' => json_encode(['consecutive_days' => 7])
            ]
        ];
        
        foreach ($default_achievements as $achievement) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $achievements_table WHERE slug = %s",
                $achievement['slug']
            ));
            
            if (!$exists) {
                $achievement['point_type_id'] = $betcoins_id;
                $wpdb->insert($achievements_table, $achievement);
            }
        }
    }
    
    /**
     * Create default earning rules
     */
    private function create_default_earning_rules() {
        global $wpdb;
        
        $earning_rules_table = $wpdb->prefix . 'dollarbets_earning_rules';
        $point_types_table = $wpdb->prefix . 'dollarbets_point_types';
        
        // Get BetCoins point type ID
        $betcoins_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $point_types_table WHERE slug = %s",
            'betcoins'
        ));
        
        if (!$betcoins_id) return;
        
        $default_rules = [
            [
                'name' => 'Daily Login Bonus',
                'description' => 'Earn BetCoins for logging in daily',
                'trigger_action' => 'user_login',
                'points_awarded' => 50,
                'max_daily_awards' => 1,
                'conditions' => json_encode(['min_time_between' => 86400]) // 24 hours
            ],
            [
                'name' => 'Profile Completion',
                'description' => 'Earn BetCoins for completing your profile',
                'trigger_action' => 'profile_complete',
                'points_awarded' => 500,
                'max_total_awards' => 1,
                'conditions' => json_encode(['required_fields' => ['first_name', 'last_name', 'description']])
            ],
            [
                'name' => 'Winning Bet Bonus',
                'description' => 'Earn bonus BetCoins for winning predictions',
                'trigger_action' => 'bet_won',
                'points_awarded' => 25,
                'conditions' => json_encode(['bonus_percentage' => 5])
            ]
        ];
        
        foreach ($default_rules as $rule) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $earning_rules_table WHERE name = %s",
                $rule['name']
            ));
            
            if (!$exists) {
                $rule['point_type_id'] = $betcoins_id;
                $wpdb->insert($earning_rules_table, $rule);
            }
        }
    }
    
    /**
     * Initialize points for new users
     */
    public function initialize_user_points($user_id) {
        $point_manager = new DollarBets_Point_Manager();
        
        // Give new users starting BetCoins
        $point_manager->award_points($user_id, 1000, 'betcoins', 'Welcome bonus for new user');
        
        // Initialize experience points
        $point_manager->award_points($user_id, 0, 'experience', 'Initial experience points');
        
        // Set initial rank
        $rank_manager = new DollarBets_Rank_Manager();
        $rank_manager->update_user_rank($user_id);
    }
}

// Initialize the custom point system
DollarBets_Custom_Point_System::get_instance();

/**
 * Helper functions for backward compatibility with Gamipress
 */

/**
 * Check if custom point system is available
 */
function db_custom_points_available() {
    return class_exists('DollarBets_Point_Manager');
}

/**
 * Get user points (replaces gamipress_get_user_points)
 */
function db_get_user_points($user_id, $point_type = 'betcoins') {
    if (!db_custom_points_available()) return 0;
    
    $point_manager = new DollarBets_Point_Manager();
    return $point_manager->get_user_points($user_id, $point_type);
}

/**
 * Award points to user (replaces gamipress_add_points)
 */
function db_award_points($user_id, $amount, $point_type = 'betcoins', $reason = '') {
    if (!db_custom_points_available()) return false;
    
    $point_manager = new DollarBets_Point_Manager();
    return $point_manager->award_points($user_id, $amount, $point_type, $reason);
}

/**
 * Deduct points from user
 */
function db_deduct_points($user_id, $amount, $point_type = 'betcoins', $reason = '') {
    if (!db_custom_points_available()) return false;
    
    $point_manager = new DollarBets_Point_Manager();
    return $point_manager->deduct_points($user_id, $amount, $point_type, $reason);
}

/**
 * Set user points to specific amount
 */
function db_set_user_points($user_id, $amount, $point_type = 'betcoins', $reason = '') {
    if (!db_custom_points_available()) return false;
    
    $point_manager = new DollarBets_Point_Manager();
    return $point_manager->set_points($user_id, $amount, $point_type, $reason);
}

/**
 * Manual point award function for admin use
 */
function db_award_points_manual($user_id, $amount, $point_type = 'betcoins', $reason = '') {
    return db_award_points($user_id, $amount, $point_type, $reason);
}

/**
 * Check if Gamipress is available (for backward compatibility)
 */
function db_gamipress_available() {
    // Always return false since we're replacing Gamipress
    return false;
}


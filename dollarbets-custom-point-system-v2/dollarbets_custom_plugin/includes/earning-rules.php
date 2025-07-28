<?php
/**
 * Earning Rules Engine
 * Handles automated point awarding based on user actions
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class DollarBets_Earning_Rules {
    
    private $earning_rules_table;
    private $point_types_table;
    
    public function __construct() {
        global $wpdb;
        $this->earning_rules_table = $wpdb->prefix . 'dollarbets_earning_rules';
        $this->point_types_table = $wpdb->prefix . 'dollarbets_point_types';
        
        // Hook into WordPress actions
        $this->init_hooks();
    }
    
    /**
     * Initialize action hooks
     */
    private function init_hooks() {
        // User login
        add_action('wp_login', [$this, 'handle_user_login'], 10, 2);
        
        // Profile updates
        add_action('profile_update', [$this, 'handle_profile_update']);
        add_action('um_after_user_updated', [$this, 'handle_profile_update']);
        
        // Custom actions
        add_action('dollarbets_bet_placed', [$this, 'handle_bet_placed'], 10, 3);
        add_action('dollarbets_bet_won', [$this, 'handle_bet_won'], 10, 3);
        add_action('dollarbets_prediction_created', [$this, 'handle_prediction_created'], 10, 2);
        
        // Comment actions
        add_action('comment_post', [$this, 'handle_comment_posted'], 10, 2);
        
        // Registration
        add_action('user_register', [$this, 'handle_user_registration']);
    }
    
    /**
     * Process action and award points based on applicable rules
     */
    public function process_action($user_id, $action, $data = []) {
        $applicable_rules = $this->get_applicable_rules($action);
        
        foreach ($applicable_rules as $rule) {
            if ($this->check_rule_conditions($rule, $user_id, $data)) {
                $this->award_rule_points($user_id, $rule, $data);
            }
        }
    }
    
    /**
     * Get rules applicable to a specific action
     */
    public function get_applicable_rules($action) {
        global $wpdb;
        
        $rules = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->earning_rules_table} 
             WHERE trigger_action = %s AND is_active = 1
             ORDER BY priority DESC, id ASC",
            $action
        ));
        
        return $rules;
    }
    
    /**
     * Check if rule conditions are met
     */
    public function check_rule_conditions($rule, $user_id, $data) {
        $conditions = json_decode($rule->conditions, true);
        if (!$conditions) return true; // No conditions means always applicable
        
        // Check daily limit
        if ($rule->max_daily_awards && $rule->max_daily_awards > 0) {
            $daily_awards = $this->get_daily_awards_count($user_id, $rule->id);
            if ($daily_awards >= $rule->max_daily_awards) {
                return false;
            }
        }
        
        // Check total limit
        if ($rule->max_total_awards && $rule->max_total_awards > 0) {
            $total_awards = $this->get_total_awards_count($user_id, $rule->id);
            if ($total_awards >= $rule->max_total_awards) {
                return false;
            }
        }
        
        // Check custom conditions
        foreach ($conditions as $condition => $value) {
            if (!$this->check_condition($condition, $value, $user_id, $data)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Check individual condition
     */
    private function check_condition($condition, $value, $user_id, $data) {
        switch ($condition) {
            case 'min_time_between':
                // Check minimum time between awards
                $last_award = $this->get_last_award_time($user_id, $data['rule_id'] ?? 0);
                if ($last_award && (time() - strtotime($last_award)) < $value) {
                    return false;
                }
                break;
                
            case 'required_fields':
                // Check if required profile fields are completed
                foreach ($value as $field) {
                    $field_value = get_user_meta($user_id, $field, true);
                    if (empty($field_value)) {
                        return false;
                    }
                }
                break;
                
            case 'min_bet_amount':
                // Check minimum bet amount
                if (isset($data['amount']) && $data['amount'] < $value) {
                    return false;
                }
                break;
                
            case 'specific_prediction_category':
                // Check if prediction is in specific category
                if (isset($data['category']) && $data['category'] !== $value) {
                    return false;
                }
                break;
                
            case 'bonus_percentage':
                // This is handled in award_rule_points
                break;
                
            case 'user_role':
                // Check user role
                $user = get_userdata($user_id);
                if (!$user || !in_array($value, $user->roles)) {
                    return false;
                }
                break;
                
            default:
                // Allow custom condition checking via filter
                if (!apply_filters('dollarbets_check_earning_rule_condition', true, $condition, $value, $user_id, $data)) {
                    return false;
                }
                break;
        }
        
        return true;
    }
    
    /**
     * Award points based on rule
     */
    public function award_rule_points($user_id, $rule, $data = []) {
        $point_manager = new DollarBets_Point_Manager();
        $point_type_slug = $this->get_point_type_slug($rule->point_type_id);
        
        $amount = $rule->points_awarded;
        
        // Handle percentage-based bonuses
        $conditions = json_decode($rule->conditions, true);
        if (isset($conditions['bonus_percentage']) && isset($data['base_amount'])) {
            $amount = ($data['base_amount'] * $conditions['bonus_percentage']) / 100;
        }
        
        // Award the points
        $new_balance = $point_manager->award_points(
            $user_id, 
            $amount, 
            $point_type_slug, 
            $rule->name . ' - ' . ($rule->description ?: 'Earning rule applied')
        );
        
        if ($new_balance !== false) {
            // Log the rule application
            $this->log_rule_application($user_id, $rule->id, $amount);
            
            // Trigger hook
            do_action('dollarbets_earning_rule_applied', $user_id, $rule, $amount, $data);
        }
        
        return $new_balance;
    }
    
    /**
     * Handle user login
     */
    public function handle_user_login($user_login, $user) {
        $this->process_action($user->ID, 'user_login', [
            'login_time' => current_time('timestamp'),
            'user_login' => $user_login
        ]);
        
        // Update consecutive login days
        $this->update_consecutive_login_days($user->ID);
    }
    
    /**
     * Handle profile update
     */
    public function handle_profile_update($user_id) {
        $this->process_action($user_id, 'profile_update', [
            'update_time' => current_time('timestamp')
        ]);
        
        // Check for profile completion
        $completion = $this->calculate_profile_completion($user_id);
        if ($completion >= 100) {
            $this->process_action($user_id, 'profile_complete', [
                'completion_percentage' => $completion
            ]);
        }
    }
    
    /**
     * Handle bet placed
     */
    public function handle_bet_placed($user_id, $prediction_id, $amount) {
        $this->process_action($user_id, 'bet_placed', [
            'prediction_id' => $prediction_id,
            'amount' => $amount,
            'bet_time' => current_time('timestamp')
        ]);
    }
    
    /**
     * Handle bet won
     */
    public function handle_bet_won($user_id, $prediction_id, $winnings) {
        $this->process_action($user_id, 'bet_won', [
            'prediction_id' => $prediction_id,
            'winnings' => $winnings,
            'base_amount' => $winnings, // For percentage-based bonuses
            'win_time' => current_time('timestamp')
        ]);
    }
    
    /**
     * Handle prediction created
     */
    public function handle_prediction_created($user_id, $prediction_id) {
        $this->process_action($user_id, 'prediction_created', [
            'prediction_id' => $prediction_id,
            'creation_time' => current_time('timestamp')
        ]);
    }
    
    /**
     * Handle comment posted
     */
    public function handle_comment_posted($comment_id, $approved) {
        if ($approved === 1) {
            $comment = get_comment($comment_id);
            if ($comment && $comment->user_id) {
                $this->process_action($comment->user_id, 'comment_posted', [
                    'comment_id' => $comment_id,
                    'post_id' => $comment->comment_post_ID,
                    'comment_time' => current_time('timestamp')
                ]);
            }
        }
    }
    
    /**
     * Handle user registration
     */
    public function handle_user_registration($user_id) {
        $this->process_action($user_id, 'user_register', [
            'registration_time' => current_time('timestamp')
        ]);
    }
    
    /**
     * Get daily awards count for a user and rule
     */
    private function get_daily_awards_count($user_id, $rule_id) {
        global $wpdb;
        
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}dollarbets_rule_applications 
             WHERE user_id = %d AND rule_id = %d AND DATE(created_at) = CURDATE()",
            $user_id, $rule_id
        ));
        
        return intval($count);
    }
    
    /**
     * Get total awards count for a user and rule
     */
    private function get_total_awards_count($user_id, $rule_id) {
        global $wpdb;
        
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}dollarbets_rule_applications 
             WHERE user_id = %d AND rule_id = %d",
            $user_id, $rule_id
        ));
        
        return intval($count);
    }
    
    /**
     * Get last award time for a user and rule
     */
    private function get_last_award_time($user_id, $rule_id) {
        global $wpdb;
        
        $last_time = $wpdb->get_var($wpdb->prepare(
            "SELECT created_at FROM {$wpdb->prefix}dollarbets_rule_applications 
             WHERE user_id = %d AND rule_id = %d 
             ORDER BY created_at DESC LIMIT 1",
            $user_id, $rule_id
        ));
        
        return $last_time;
    }
    
    /**
     * Log rule application
     */
    private function log_rule_application($user_id, $rule_id, $amount) {
        global $wpdb;
        
        // Create rule applications table if it doesn't exist
        $table_name = $wpdb->prefix . 'dollarbets_rule_applications';
        
        $wpdb->query("CREATE TABLE IF NOT EXISTS $table_name (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT NOT NULL,
            rule_id INT NOT NULL,
            points_awarded DECIMAL(15,2) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_rule (user_id, rule_id),
            INDEX idx_created_at (created_at)
        ) {$wpdb->get_charset_collate()}");
        
        $wpdb->insert($table_name, [
            'user_id' => $user_id,
            'rule_id' => $rule_id,
            'points_awarded' => $amount
        ]);
    }
    
    /**
     * Update consecutive login days
     */
    private function update_consecutive_login_days($user_id) {
        $last_login = get_user_meta($user_id, 'dollarbets_last_login_date', true);
        $current_date = date('Y-m-d');
        
        if ($last_login === $current_date) {
            // Already logged in today
            return;
        }
        
        $consecutive_days = get_user_meta($user_id, 'dollarbets_consecutive_login_days', true) ?: 0;
        
        if ($last_login === date('Y-m-d', strtotime('-1 day'))) {
            // Consecutive day
            $consecutive_days++;
        } else {
            // Reset streak
            $consecutive_days = 1;
        }
        
        update_user_meta($user_id, 'dollarbets_consecutive_login_days', $consecutive_days);
        update_user_meta($user_id, 'dollarbets_last_login_date', $current_date);
    }
    
    /**
     * Calculate profile completion percentage
     */
    private function calculate_profile_completion($user_id) {
        $user = get_userdata($user_id);
        if (!$user) return 0;
        
        $required_fields = [
            'first_name',
            'last_name',
            'description',
            'user_email'
        ];
        
        $completed = 0;
        $total = count($required_fields);
        
        foreach ($required_fields as $field) {
            if ($field === 'user_email') {
                if (!empty($user->user_email)) $completed++;
            } else {
                $value = get_user_meta($user_id, $field, true);
                if (!empty($value)) $completed++;
            }
        }
        
        return ($completed / $total) * 100;
    }
    
    /**
     * Get point type slug by ID
     */
    private function get_point_type_slug($point_type_id) {
        global $wpdb;
        
        static $cache = [];
        
        if (isset($cache[$point_type_id])) {
            return $cache[$point_type_id];
        }
        
        $slug = $wpdb->get_var($wpdb->prepare(
            "SELECT slug FROM {$this->point_types_table} WHERE id = %d",
            $point_type_id
        ));
        
        $cache[$point_type_id] = $slug ?: 'betcoins';
        return $cache[$point_type_id];
    }
    
    /**
     * Create a new earning rule
     */
    public function create_rule($data) {
        global $wpdb;
        
        $defaults = [
            'name' => '',
            'description' => '',
            'point_type_id' => $this->get_betcoins_point_type_id(),
            'trigger_action' => '',
            'points_awarded' => 0,
            'max_daily_awards' => null,
            'max_total_awards' => null,
            'conditions' => '{}',
            'is_active' => 1,
            'priority' => 0
        ];
        
        $data = wp_parse_args($data, $defaults);
        
        // Validate required fields
        if (empty($data['name']) || empty($data['trigger_action'])) {
            return false;
        }
        
        $result = $wpdb->insert($this->earning_rules_table, $data);
        
        if ($result) {
            return $wpdb->insert_id;
        }
        
        return false;
    }
    
    /**
     * Update an earning rule
     */
    public function update_rule($rule_id, $data) {
        global $wpdb;
        
        $result = $wpdb->update(
            $this->earning_rules_table,
            $data,
            ['id' => $rule_id],
            null,
            ['%d']
        );
        
        return $result !== false;
    }
    
    /**
     * Delete an earning rule
     */
    public function delete_rule($rule_id) {
        global $wpdb;
        
        $result = $wpdb->delete(
            $this->earning_rules_table,
            ['id' => $rule_id],
            ['%d']
        );
        
        return $result !== false;
    }
    
    /**
     * Get all earning rules
     */
    public function get_all_rules() {
        global $wpdb;
        
        $rules = $wpdb->get_results(
            "SELECT er.*, pt.slug as point_type_slug, pt.name as point_type_name
             FROM {$this->earning_rules_table} er
             JOIN {$this->point_types_table} pt ON er.point_type_id = pt.id
             ORDER BY er.priority DESC, er.id ASC"
        );
        
        return $rules;
    }
    
    /**
     * Get BetCoins point type ID
     */
    private function get_betcoins_point_type_id() {
        global $wpdb;
        
        static $betcoins_id = null;
        
        if ($betcoins_id === null) {
            $betcoins_id = $wpdb->get_var(
                "SELECT id FROM {$this->point_types_table} WHERE slug = 'betcoins'"
            );
        }
        
        return $betcoins_id;
    }
}

// Initialize earning rules engine
new DollarBets_Earning_Rules();


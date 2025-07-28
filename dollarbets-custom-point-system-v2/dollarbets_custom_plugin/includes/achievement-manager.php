<?php
/**
 * Achievement Manager Class
 * Handles user achievements and unlocking system
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class DollarBets_Achievement_Manager {
    
    private $achievements_table;
    private $user_achievements_table;
    private $point_types_table;
    
    public function __construct() {
        global $wpdb;
        $this->achievements_table = $wpdb->prefix . 'dollarbets_achievements';
        $this->user_achievements_table = $wpdb->prefix . 'dollarbets_user_achievements';
        $this->point_types_table = $wpdb->prefix . 'dollarbets_point_types';
    }
    
    /**
     * Check user achievements for a specific trigger
     */
    public function check_user_achievements($user_id, $trigger_action, $data = []) {
        global $wpdb;
        
        // Get all active achievements that haven't been unlocked by this user
        $achievements = $wpdb->get_results($wpdb->prepare(
            "SELECT a.* FROM {$this->achievements_table} a
             LEFT JOIN {$this->user_achievements_table} ua ON a.id = ua.achievement_id AND ua.user_id = %d
             WHERE a.is_active = 1 AND ua.id IS NULL",
            $user_id
        ));
        
        foreach ($achievements as $achievement) {
            if ($this->check_achievement_conditions($user_id, $achievement, $trigger_action, $data)) {
                $this->unlock_achievement($user_id, $achievement->id);
            }
        }
    }
    
    /**
     * Check if achievement conditions are met
     */
    private function check_achievement_conditions($user_id, $achievement, $trigger_action, $data) {
        $conditions = json_decode($achievement->unlock_conditions, true);
        if (!$conditions) return false;
        
        // Get user statistics
        $stats = $this->get_user_statistics($user_id);
        
        // Check each condition
        foreach ($conditions as $condition => $required_value) {
            switch ($condition) {
                case 'bets_placed':
                    if ($stats['total_bets'] < $required_value) return false;
                    break;
                    
                case 'bets_won':
                    if ($stats['bets_won'] < $required_value) return false;
                    break;
                    
                case 'total_spent':
                    if ($stats['total_spent'] < $required_value) return false;
                    break;
                    
                case 'total_earned':
                    if ($stats['total_earned'] < $required_value) return false;
                    break;
                    
                case 'consecutive_days':
                    if ($stats['consecutive_login_days'] < $required_value) return false;
                    break;
                    
                case 'current_balance':
                    if ($stats['current_balance'] < $required_value) return false;
                    break;
                    
                case 'profile_completion':
                    if ($stats['profile_completion'] < $required_value) return false;
                    break;
                    
                case 'referrals':
                    if ($stats['referrals'] < $required_value) return false;
                    break;
                    
                default:
                    // Custom condition check
                    if (!apply_filters('dollarbets_check_achievement_condition', false, $condition, $required_value, $user_id, $data)) {
                        return false;
                    }
                    break;
            }
        }
        
        return true;
    }
    
    /**
     * Unlock an achievement for a user
     */
    public function unlock_achievement($user_id, $achievement_id) {
        global $wpdb;
        
        // Check if already unlocked
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->user_achievements_table} 
             WHERE user_id = %d AND achievement_id = %d",
            $user_id, $achievement_id
        ));
        
        if ($existing) {
            return false; // Already unlocked
        }
        
        // Get achievement details
        $achievement = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->achievements_table} WHERE id = %d",
            $achievement_id
        ));
        
        if (!$achievement) {
            return false; // Achievement not found
        }
        
        // Start transaction
        $wpdb->query('START TRANSACTION');
        
        try {
            // Insert user achievement record
            $wpdb->insert(
                $this->user_achievements_table,
                [
                    'user_id' => $user_id,
                    'achievement_id' => $achievement_id,
                    'progress' => json_encode(['completed' => true])
                ],
                ['%d', '%d', '%s']
            );
            
            // Award points if specified
            if ($achievement->points_reward > 0) {
                $point_manager = new DollarBets_Point_Manager();
                $point_type_slug = $this->get_point_type_slug($achievement->point_type_id);
                
                $point_manager->award_points(
                    $user_id, 
                    $achievement->points_reward, 
                    $point_type_slug, 
                    'Achievement unlocked: ' . $achievement->name
                );
            }
            
            // Commit transaction
            $wpdb->query('COMMIT');
            
            // Trigger achievement unlocked hook
            do_action('dollarbets_achievement_unlocked', $user_id, $achievement);
            
            return true;
            
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            error_log('DollarBets Achievement Unlock Error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get user achievements
     */
    public function get_user_achievements($user_id, $include_locked = false) {
        global $wpdb;
        
        if ($include_locked) {
            // Get all achievements with unlock status
            $achievements = $wpdb->get_results($wpdb->prepare(
                "SELECT a.*, 
                        ua.unlocked_at,
                        ua.progress,
                        CASE WHEN ua.id IS NOT NULL THEN 1 ELSE 0 END as is_unlocked
                 FROM {$this->achievements_table} a
                 LEFT JOIN {$this->user_achievements_table} ua ON a.id = ua.achievement_id AND ua.user_id = %d
                 WHERE a.is_active = 1 AND (a.is_secret = 0 OR ua.id IS NOT NULL)
                 ORDER BY a.order_position ASC, a.id ASC",
                $user_id
            ));
        } else {
            // Get only unlocked achievements
            $achievements = $wpdb->get_results($wpdb->prepare(
                "SELECT a.*, ua.unlocked_at, ua.progress
                 FROM {$this->achievements_table} a
                 JOIN {$this->user_achievements_table} ua ON a.id = ua.achievement_id
                 WHERE ua.user_id = %d AND a.is_active = 1
                 ORDER BY ua.unlocked_at DESC",
                $user_id
            ));
        }
        
        return $achievements;
    }
    
    /**
     * Get achievement progress for a user
     */
    public function get_achievement_progress($user_id, $achievement_id) {
        global $wpdb;
        
        $achievement = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->achievements_table} WHERE id = %d",
            $achievement_id
        ));
        
        if (!$achievement) return null;
        
        $user_achievement = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->user_achievements_table} 
             WHERE user_id = %d AND achievement_id = %d",
            $user_id, $achievement_id
        ));
        
        if ($user_achievement) {
            // Achievement is unlocked
            return [
                'is_unlocked' => true,
                'unlocked_at' => $user_achievement->unlocked_at,
                'progress' => json_decode($user_achievement->progress, true),
                'progress_percentage' => 100
            ];
        }
        
        // Calculate current progress
        $conditions = json_decode($achievement->unlock_conditions, true);
        $stats = $this->get_user_statistics($user_id);
        
        $progress = [];
        $total_conditions = count($conditions);
        $completed_conditions = 0;
        
        foreach ($conditions as $condition => $required_value) {
            $current_value = 0;
            
            switch ($condition) {
                case 'bets_placed':
                    $current_value = $stats['total_bets'];
                    break;
                case 'bets_won':
                    $current_value = $stats['bets_won'];
                    break;
                case 'total_spent':
                    $current_value = $stats['total_spent'];
                    break;
                case 'total_earned':
                    $current_value = $stats['total_earned'];
                    break;
                case 'consecutive_days':
                    $current_value = $stats['consecutive_login_days'];
                    break;
                case 'current_balance':
                    $current_value = $stats['current_balance'];
                    break;
                case 'profile_completion':
                    $current_value = $stats['profile_completion'];
                    break;
                case 'referrals':
                    $current_value = $stats['referrals'];
                    break;
            }
            
            $progress[$condition] = [
                'current' => $current_value,
                'required' => $required_value,
                'completed' => $current_value >= $required_value
            ];
            
            if ($current_value >= $required_value) {
                $completed_conditions++;
            }
        }
        
        return [
            'is_unlocked' => false,
            'progress' => $progress,
            'progress_percentage' => ($completed_conditions / $total_conditions) * 100
        ];
    }
    
    /**
     * Get user statistics for achievement checking
     */
    private function get_user_statistics($user_id) {
        global $wpdb;
        
        static $cache = [];
        
        if (isset($cache[$user_id])) {
            return $cache[$user_id];
        }
        
        // Get point statistics
        $point_manager = new DollarBets_Point_Manager();
        $point_data = $point_manager->get_user_all_points($user_id);
        
        $betcoins_data = $point_data['betcoins'] ?? ['balance' => 0, 'total_earned' => 0, 'total_spent' => 0];
        
        // Get betting statistics
        $bets_table = $wpdb->prefix . 'dollarbets_bets';
        $predictions_table = $wpdb->prefix . 'dollarbets_predictions';
        
        $betting_stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as total_bets,
                SUM(CASE WHEN p.status = 'resolved' AND 
                    ((b.bet_type = 'yes' AND p.result = 'yes') OR 
                     (b.bet_type = 'no' AND p.result = 'no')) THEN 1 ELSE 0 END) as bets_won
             FROM $bets_table b
             LEFT JOIN $predictions_table p ON b.prediction_id = p.id
             WHERE b.user_id = %d",
            $user_id
        ));
        
        // Get login streak (simplified - you might want to implement proper tracking)
        $consecutive_days = get_user_meta($user_id, 'dollarbets_consecutive_login_days', true) ?: 0;
        
        // Get profile completion percentage
        $profile_completion = $this->calculate_profile_completion($user_id);
        
        // Get referral count
        $referrals = get_user_meta($user_id, 'dollarbets_referral_count', true) ?: 0;
        
        $stats = [
            'current_balance' => $betcoins_data['balance'],
            'total_earned' => $betcoins_data['total_earned'],
            'total_spent' => $betcoins_data['total_spent'],
            'total_bets' => $betting_stats ? $betting_stats->total_bets : 0,
            'bets_won' => $betting_stats ? $betting_stats->bets_won : 0,
            'consecutive_login_days' => $consecutive_days,
            'profile_completion' => $profile_completion,
            'referrals' => $referrals
        ];
        
        $cache[$user_id] = $stats;
        return $stats;
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
     * Get all achievements
     */
    public function get_all_achievements($include_secret = false) {
        global $wpdb;
        
        $where_clause = "WHERE is_active = 1";
        if (!$include_secret) {
            $where_clause .= " AND is_secret = 0";
        }
        
        $achievements = $wpdb->get_results(
            "SELECT * FROM {$this->achievements_table} 
             $where_clause
             ORDER BY order_position ASC, id ASC"
        );
        
        return $achievements;
    }
    
    /**
     * Create a new achievement
     */
    public function create_achievement($data) {
        global $wpdb;
        
        $defaults = [
            'slug' => '',
            'name' => '',
            'description' => '',
            'icon' => '🏆',
            'badge_color' => '#000000',
            'point_type_id' => $this->get_betcoins_point_type_id(),
            'points_reward' => 0,
            'unlock_conditions' => '{}',
            'is_active' => 1,
            'is_secret' => 0,
            'order_position' => 0
        ];
        
        $data = wp_parse_args($data, $defaults);
        
        // Validate required fields
        if (empty($data['slug']) || empty($data['name'])) {
            return false;
        }
        
        // Check if slug already exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->achievements_table} WHERE slug = %s",
            $data['slug']
        ));
        
        if ($existing) {
            return false; // Slug already exists
        }
        
        $result = $wpdb->insert($this->achievements_table, $data);
        
        if ($result) {
            return $wpdb->insert_id;
        }
        
        return false;
    }
    
    /**
     * Update an achievement
     */
    public function update_achievement($achievement_id, $data) {
        global $wpdb;
        
        $result = $wpdb->update(
            $this->achievements_table,
            $data,
            ['id' => $achievement_id],
            null,
            ['%d']
        );
        
        return $result !== false;
    }
    
    /**
     * Delete an achievement
     */
    public function delete_achievement($achievement_id) {
        global $wpdb;
        
        // Start transaction
        $wpdb->query('START TRANSACTION');
        
        try {
            // Remove all user achievement records
            $wpdb->delete(
                $this->user_achievements_table,
                ['achievement_id' => $achievement_id],
                ['%d']
            );
            
            // Delete the achievement
            $wpdb->delete(
                $this->achievements_table,
                ['id' => $achievement_id],
                ['%d']
            );
            
            // Commit transaction
            $wpdb->query('COMMIT');
            
            return true;
            
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            error_log('DollarBets Achievement Deletion Error: ' . $e->getMessage());
            return false;
        }
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


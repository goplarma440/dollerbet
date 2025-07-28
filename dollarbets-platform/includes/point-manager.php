<?php
/**
 * Point Manager Class
 * Handles all point-related operations
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class DollarBets_Point_Manager {
    
    private $point_types_table;
    private $user_points_table;
    private $transactions_table;
    
    public function __construct() {
        global $wpdb;
        $this->point_types_table = $wpdb->prefix . 'dollarbets_point_types';
        $this->user_points_table = $wpdb->prefix . 'dollarbets_user_points';
        $this->transactions_table = $wpdb->prefix . 'dollarbets_point_transactions';
    }
    
    /**
     * Get user points for a specific point type
     */
    public function get_user_points($user_id, $point_type = 'betcoins') {
        global $wpdb;
        
        $point_type_id = $this->get_point_type_id($point_type);
        if (!$point_type_id) return 0;
        
        $balance = $wpdb->get_var($wpdb->prepare(
            "SELECT balance FROM {$this->user_points_table} 
             WHERE user_id = %d AND point_type_id = %d",
            $user_id, $point_type_id
        ));
        
        return $balance ? floatval($balance) : 0;
    }
    
    /**
     * Get all point balances for a user
     */
    public function get_user_all_points($user_id) {
        global $wpdb;
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT pt.slug, pt.name, pt.icon, up.balance, up.total_earned, up.total_spent
             FROM {$this->user_points_table} up
             JOIN {$this->point_types_table} pt ON up.point_type_id = pt.id
             WHERE up.user_id = %d AND pt.is_active = 1",
            $user_id
        ));
        
        $points = [];
        foreach ($results as $result) {
            $points[$result->slug] = [
                'name' => $result->name,
                'icon' => $result->icon,
                'balance' => floatval($result->balance),
                'total_earned' => floatval($result->total_earned),
                'total_spent' => floatval($result->total_spent)
            ];
        }
        
        return $points;
    }
    
    /**
     * Award points to a user
     */
    public function award_points($user_id, $amount, $point_type = 'betcoins', $reason = '', $admin_user_id = null) {
        if ($amount <= 0) return false;
        
        global $wpdb;
        
        $point_type_id = $this->get_point_type_id($point_type);
        if (!$point_type_id) return false;
        
        // Start transaction
        $wpdb->query('START TRANSACTION');
        
        try {
            // Get current balance
            $current_balance = $this->get_user_points($user_id, $point_type);
            
            // Ensure user points record exists
            $this->ensure_user_points_record($user_id, $point_type_id);
            
            // Calculate new balance
            $new_balance = $current_balance + $amount;
            
            // Update user points
            $updated = $wpdb->update(
                $this->user_points_table,
                [
                    'balance' => $new_balance,
                    'total_earned' => $wpdb->prepare('total_earned + %f', $amount),
                    'updated_at' => current_time('mysql')
                ],
                [
                    'user_id' => $user_id,
                    'point_type_id' => $point_type_id
                ],
                ['%f', '%s', '%s'],
                ['%d', '%d']
            );
            
            if ($updated === false) {
                throw new Exception('Failed to update user points');
            }
            
            // Log transaction
            $this->log_transaction(
                $user_id, 
                $point_type_id, 
                'earn', 
                $amount, 
                $current_balance, 
                $new_balance, 
                $reason,
                null,
                null,
                $admin_user_id
            );
            
            // Commit transaction
            $wpdb->query('COMMIT');
            
            // Trigger hooks
            do_action('dollarbets_points_awarded', $user_id, $amount, $point_type, $reason);
            
            // Check for achievements and rank updates
            $this->check_achievements($user_id, 'points_earned', ['amount' => $amount, 'point_type' => $point_type]);
            $this->update_user_rank($user_id);
            
            return $new_balance;
            
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            error_log('DollarBets Point Award Error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Deduct points from a user
     */
    public function deduct_points($user_id, $amount, $point_type = 'betcoins', $reason = '', $reference_id = null, $reference_type = null) {
        if ($amount <= 0) return false;
        
        global $wpdb;
        
        $point_type_id = $this->get_point_type_id($point_type);
        if (!$point_type_id) return false;
        
        // Get current balance
        $current_balance = $this->get_user_points($user_id, $point_type);
        
        // Check if user has enough points
        if ($current_balance < $amount) {
            return false; // Insufficient funds
        }
        
        // Start transaction
        $wpdb->query('START TRANSACTION');
        
        try {
            // Calculate new balance
            $new_balance = $current_balance - $amount;
            
            // Update user points
            $updated = $wpdb->update(
                $this->user_points_table,
                [
                    'balance' => $new_balance,
                    'total_spent' => $wpdb->prepare('total_spent + %f', $amount),
                    'updated_at' => current_time('mysql')
                ],
                [
                    'user_id' => $user_id,
                    'point_type_id' => $point_type_id
                ],
                ['%f', '%s', '%s'],
                ['%d', '%d']
            );
            
            if ($updated === false) {
                throw new Exception('Failed to update user points');
            }
            
            // Log transaction
            $this->log_transaction(
                $user_id, 
                $point_type_id, 
                'spend', 
                $amount, 
                $current_balance, 
                $new_balance, 
                $reason,
                $reference_id,
                $reference_type
            );
            
            // Commit transaction
            $wpdb->query('COMMIT');
            
            // Trigger hooks
            do_action('dollarbets_points_deducted', $user_id, $amount, $point_type, $reason);
            
            return $new_balance;
            
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            error_log('DollarBets Point Deduction Error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Set user points to a specific amount
     */
    public function set_points($user_id, $amount, $point_type = 'betcoins', $reason = '', $admin_user_id = null) {
        if ($amount < 0) return false;
        
        global $wpdb;
        
        $point_type_id = $this->get_point_type_id($point_type);
        if (!$point_type_id) return false;
        
        // Get current balance
        $current_balance = $this->get_user_points($user_id, $point_type);
        
        // Start transaction
        $wpdb->query('START TRANSACTION');
        
        try {
            // Ensure user points record exists
            $this->ensure_user_points_record($user_id, $point_type_id);
            
            // Update user points
            $updated = $wpdb->update(
                $this->user_points_table,
                [
                    'balance' => $amount,
                    'updated_at' => current_time('mysql')
                ],
                [
                    'user_id' => $user_id,
                    'point_type_id' => $point_type_id
                ],
                ['%f', '%s'],
                ['%d', '%d']
            );
            
            if ($updated === false) {
                throw new Exception('Failed to update user points');
            }
            
            // Log transaction
            $this->log_transaction(
                $user_id, 
                $point_type_id, 
                'adjust', 
                $amount - $current_balance, 
                $current_balance, 
                $amount, 
                $reason,
                null,
                null,
                $admin_user_id
            );
            
            // Commit transaction
            $wpdb->query('COMMIT');
            
            // Trigger hooks
            do_action('dollarbets_points_set', $user_id, $amount, $point_type, $reason);
            
            // Update rank
            $this->update_user_rank($user_id);
            
            return $amount;
            
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            error_log('DollarBets Point Set Error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get transaction history for a user
     */
    public function get_transaction_history($user_id, $point_type = null, $limit = 50, $offset = 0) {
        global $wpdb;
        
        $where_clause = "WHERE t.user_id = %d";
        $params = [$user_id];
        
        if ($point_type) {
            $point_type_id = $this->get_point_type_id($point_type);
            if ($point_type_id) {
                $where_clause .= " AND t.point_type_id = %d";
                $params[] = $point_type_id;
            }
        }
        
        $params[] = $limit;
        $params[] = $offset;
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT t.*, pt.slug as point_type_slug, pt.name as point_type_name, pt.icon as point_type_icon
             FROM {$this->transactions_table} t
             JOIN {$this->point_types_table} pt ON t.point_type_id = pt.id
             $where_clause
             ORDER BY t.created_at DESC
             LIMIT %d OFFSET %d",
            $params
        ));
        
        return $results;
    }
    
    /**
     * Get point type ID by slug
     */
    private function get_point_type_id($point_type_slug) {
        global $wpdb;
        
        static $cache = [];
        
        if (isset($cache[$point_type_slug])) {
            return $cache[$point_type_slug];
        }
        
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->point_types_table} WHERE slug = %s AND is_active = 1",
            $point_type_slug
        ));
        
        $cache[$point_type_slug] = $id ? intval($id) : null;
        return $cache[$point_type_slug];
    }
    
    /**
     * Ensure user points record exists
     */
    private function ensure_user_points_record($user_id, $point_type_id) {
        global $wpdb;
        
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->user_points_table} 
             WHERE user_id = %d AND point_type_id = %d",
            $user_id, $point_type_id
        ));
        
        if (!$exists) {
            $wpdb->insert(
                $this->user_points_table,
                [
                    'user_id' => $user_id,
                    'point_type_id' => $point_type_id,
                    'balance' => 0,
                    'total_earned' => 0,
                    'total_spent' => 0
                ],
                ['%d', '%d', '%f', '%f', '%f']
            );
        }
    }
    
    /**
     * Log a point transaction
     */
    private function log_transaction($user_id, $point_type_id, $type, $amount, $balance_before, $balance_after, $reason = '', $reference_id = null, $reference_type = null, $admin_user_id = null) {
        global $wpdb;
        
        $wpdb->insert(
            $this->transactions_table,
            [
                'user_id' => $user_id,
                'point_type_id' => $point_type_id,
                'transaction_type' => $type,
                'amount' => $amount,
                'balance_before' => $balance_before,
                'balance_after' => $balance_after,
                'reason' => $reason,
                'reference_id' => $reference_id,
                'reference_type' => $reference_type,
                'admin_user_id' => $admin_user_id
            ],
            ['%d', '%d', '%s', '%f', '%f', '%f', '%s', '%s', '%s', '%d']
        );
    }
    
    /**
     * Check for achievements
     */
    private function check_achievements($user_id, $trigger, $data = []) {
        if (class_exists('DollarBets_Achievement_Manager')) {
            $achievement_manager = new DollarBets_Achievement_Manager();
            $achievement_manager->check_user_achievements($user_id, $trigger, $data);
        }
    }
    
    /**
     * Update user rank
     */
    private function update_user_rank($user_id) {
        if (class_exists('DollarBets_Rank_Manager')) {
            $rank_manager = new DollarBets_Rank_Manager();
            $rank_manager->update_user_rank($user_id);
        }
    }
    
    /**
     * Get leaderboard data
     */
    public function get_leaderboard($point_type = 'betcoins', $limit = 100) {
        global $wpdb;
        
        $point_type_id = $this->get_point_type_id($point_type);
        if (!$point_type_id) return [];
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT up.user_id, up.balance, up.total_earned, up.total_spent,
                    u.display_name, u.user_login,
                    COALESCE(um_first.meta_value, '') as first_name,
                    COALESCE(um_last.meta_value, '') as last_name
             FROM {$this->user_points_table} up
             JOIN {$wpdb->users} u ON up.user_id = u.ID
             LEFT JOIN {$wpdb->usermeta} um_first ON u.ID = um_first.user_id AND um_first.meta_key = 'first_name'
             LEFT JOIN {$wpdb->usermeta} um_last ON u.ID = um_last.user_id AND um_last.meta_key = 'last_name'
             WHERE up.point_type_id = %d AND up.balance > 0
             ORDER BY up.balance DESC
             LIMIT %d",
            $point_type_id, $limit
        ));
        
        return $results;
    }
    
    /**
     * Get point statistics
     */
    public function get_point_statistics($point_type = 'betcoins') {
        global $wpdb;
        
        $point_type_id = $this->get_point_type_id($point_type);
        if (!$point_type_id) return null;
        
        $stats = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                COUNT(*) as total_users,
                SUM(balance) as total_balance,
                SUM(total_earned) as total_earned,
                SUM(total_spent) as total_spent,
                AVG(balance) as average_balance,
                MAX(balance) as max_balance
             FROM {$this->user_points_table}
             WHERE point_type_id = %d",
            $point_type_id
        ));
        
        return $stats;
    }
}


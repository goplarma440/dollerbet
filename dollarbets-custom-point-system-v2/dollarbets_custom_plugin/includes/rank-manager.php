<?php
/**
 * Rank Manager Class
 * Handles user ranking system
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class DollarBets_Rank_Manager {
    
    private $ranks_table;
    private $user_ranks_table;
    private $point_types_table;
    private $user_points_table;
    
    public function __construct() {
        global $wpdb;
        $this->ranks_table = $wpdb->prefix . 'dollarbets_ranks';
        $this->user_ranks_table = $wpdb->prefix . 'dollarbets_user_ranks';
        $this->point_types_table = $wpdb->prefix . 'dollarbets_point_types';
        $this->user_points_table = $wpdb->prefix . 'dollarbets_user_points';
    }
    
    /**
     * Calculate and update user rank based on their points
     */
    public function update_user_rank($user_id) {
        global $wpdb;
        
        // Get user's BetCoins balance
        $point_manager = new DollarBets_Point_Manager();
        $user_points = $point_manager->get_user_points($user_id, 'betcoins');
        
        // Find the highest rank the user qualifies for
        $qualified_rank = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->ranks_table} 
             WHERE points_required <= %f AND is_active = 1
             ORDER BY points_required DESC 
             LIMIT 1",
            $user_points
        ));
        
        if (!$qualified_rank) {
            return false; // No rank found
        }
        
        // Check if user already has this rank
        $current_rank = $this->get_user_rank($user_id);
        
        if ($current_rank && $current_rank->rank_id == $qualified_rank->id) {
            return true; // User already has the correct rank
        }
        
        // Start transaction
        $wpdb->query('START TRANSACTION');
        
        try {
            // Mark all current ranks as not current
            $wpdb->update(
                $this->user_ranks_table,
                ['is_current' => 0],
                ['user_id' => $user_id],
                ['%d'],
                ['%d']
            );
            
            // Check if user has ever had this rank
            $existing_rank = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$this->user_ranks_table} 
                 WHERE user_id = %d AND rank_id = %d",
                $user_id, $qualified_rank->id
            ));
            
            if ($existing_rank) {
                // Update existing rank record to current
                $wpdb->update(
                    $this->user_ranks_table,
                    ['is_current' => 1],
                    ['id' => $existing_rank],
                    ['%d'],
                    ['%d']
                );
            } else {
                // Insert new rank record
                $wpdb->insert(
                    $this->user_ranks_table,
                    [
                        'user_id' => $user_id,
                        'rank_id' => $qualified_rank->id,
                        'is_current' => 1
                    ],
                    ['%d', '%d', '%d']
                );
            }
            
            // Commit transaction
            $wpdb->query('COMMIT');
            
            // Trigger rank change hook
            do_action('dollarbets_user_rank_changed', $user_id, $qualified_rank, $current_rank);
            
            return true;
            
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            error_log('DollarBets Rank Update Error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get user's current rank
     */
    public function get_user_rank($user_id) {
        global $wpdb;
        
        $rank = $wpdb->get_row($wpdb->prepare(
            "SELECT ur.*, r.slug, r.name, r.description, r.icon, r.badge_color, r.points_required
             FROM {$this->user_ranks_table} ur
             JOIN {$this->ranks_table} r ON ur.rank_id = r.id
             WHERE ur.user_id = %d AND ur.is_current = 1 AND r.is_active = 1",
            $user_id
        ));
        
        return $rank;
    }
    
    /**
     * Get all ranks
     */
    public function get_all_ranks() {
        global $wpdb;
        
        $ranks = $wpdb->get_results(
            "SELECT * FROM {$this->ranks_table} 
             WHERE is_active = 1 
             ORDER BY order_position ASC, points_required ASC"
        );
        
        return $ranks;
    }
    
    /**
     * Get rank by ID
     */
    public function get_rank_by_id($rank_id) {
        global $wpdb;
        
        $rank = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->ranks_table} WHERE id = %d AND is_active = 1",
            $rank_id
        ));
        
        return $rank;
    }
    
    /**
     * Get rank by slug
     */
    public function get_rank_by_slug($slug) {
        global $wpdb;
        
        $rank = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->ranks_table} WHERE slug = %s AND is_active = 1",
            $slug
        ));
        
        return $rank;
    }
    
    /**
     * Get next rank for user
     */
    public function get_next_rank($user_id) {
        global $wpdb;
        
        $current_rank = $this->get_user_rank($user_id);
        
        if (!$current_rank) {
            // User has no rank, get the first rank
            return $wpdb->get_row(
                "SELECT * FROM {$this->ranks_table} 
                 WHERE is_active = 1 
                 ORDER BY points_required ASC 
                 LIMIT 1"
            );
        }
        
        // Get the next rank with higher points requirement
        $next_rank = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->ranks_table} 
             WHERE points_required > %f AND is_active = 1
             ORDER BY points_required ASC 
             LIMIT 1",
            $current_rank->points_required
        ));
        
        return $next_rank;
    }
    
    /**
     * Get rank progress for user
     */
    public function get_rank_progress($user_id) {
        $point_manager = new DollarBets_Point_Manager();
        $user_points = $point_manager->get_user_points($user_id, 'betcoins');
        
        $current_rank = $this->get_user_rank($user_id);
        $next_rank = $this->get_next_rank($user_id);
        
        $progress = [
            'current_points' => $user_points,
            'current_rank' => $current_rank,
            'next_rank' => $next_rank,
            'progress_percentage' => 0,
            'points_needed' => 0
        ];
        
        if ($current_rank && $next_rank) {
            $points_range = $next_rank->points_required - $current_rank->points_required;
            $points_earned = $user_points - $current_rank->points_required;
            
            $progress['progress_percentage'] = min(100, ($points_earned / $points_range) * 100);
            $progress['points_needed'] = max(0, $next_rank->points_required - $user_points);
        } elseif ($next_rank) {
            // User has no current rank
            $progress['progress_percentage'] = min(100, ($user_points / $next_rank->points_required) * 100);
            $progress['points_needed'] = max(0, $next_rank->points_required - $user_points);
        }
        
        return $progress;
    }
    
    /**
     * Get leaderboard with ranks
     */
    public function get_leaderboard($limit = 100) {
        global $wpdb;
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                u.ID as user_id,
                u.display_name,
                u.user_login,
                COALESCE(um_first.meta_value, '') as first_name,
                COALESCE(um_last.meta_value, '') as last_name,
                up.balance as points,
                r.name as rank_name,
                r.icon as rank_icon,
                r.badge_color as rank_color,
                r.slug as rank_slug
             FROM {$wpdb->users} u
             LEFT JOIN {$this->user_points_table} up ON u.ID = up.user_id
             LEFT JOIN {$this->point_types_table} pt ON up.point_type_id = pt.id AND pt.slug = 'betcoins'
             LEFT JOIN {$this->user_ranks_table} ur ON u.ID = ur.user_id AND ur.is_current = 1
             LEFT JOIN {$this->ranks_table} r ON ur.rank_id = r.id
             LEFT JOIN {$wpdb->usermeta} um_first ON u.ID = um_first.user_id AND um_first.meta_key = 'first_name'
             LEFT JOIN {$wpdb->usermeta} um_last ON u.ID = um_last.user_id AND um_last.meta_key = 'last_name'
             WHERE up.balance IS NOT NULL AND up.balance > 0
             ORDER BY up.balance DESC
             LIMIT %d",
            $limit
        ));
        
        return $results;
    }
    
    /**
     * Get rank statistics
     */
    public function get_rank_statistics() {
        global $wpdb;
        
        $stats = $wpdb->get_results(
            "SELECT 
                r.name as rank_name,
                r.icon as rank_icon,
                r.badge_color as rank_color,
                r.points_required,
                COUNT(ur.user_id) as user_count
             FROM {$this->ranks_table} r
             LEFT JOIN {$this->user_ranks_table} ur ON r.id = ur.rank_id AND ur.is_current = 1
             WHERE r.is_active = 1
             GROUP BY r.id
             ORDER BY r.order_position ASC, r.points_required ASC"
        );
        
        return $stats;
    }
    
    /**
     * Create a new rank
     */
    public function create_rank($data) {
        global $wpdb;
        
        $defaults = [
            'slug' => '',
            'name' => '',
            'description' => '',
            'icon' => '🏆',
            'badge_color' => '#000000',
            'point_type_id' => $this->get_betcoins_point_type_id(),
            'points_required' => 0,
            'order_position' => 0,
            'is_active' => 1
        ];
        
        $data = wp_parse_args($data, $defaults);
        
        // Validate required fields
        if (empty($data['slug']) || empty($data['name'])) {
            return false;
        }
        
        // Check if slug already exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->ranks_table} WHERE slug = %s",
            $data['slug']
        ));
        
        if ($existing) {
            return false; // Slug already exists
        }
        
        $result = $wpdb->insert($this->ranks_table, $data);
        
        if ($result) {
            return $wpdb->insert_id;
        }
        
        return false;
    }
    
    /**
     * Update a rank
     */
    public function update_rank($rank_id, $data) {
        global $wpdb;
        
        $result = $wpdb->update(
            $this->ranks_table,
            $data,
            ['id' => $rank_id],
            null,
            ['%d']
        );
        
        return $result !== false;
    }
    
    /**
     * Delete a rank
     */
    public function delete_rank($rank_id) {
        global $wpdb;
        
        // Start transaction
        $wpdb->query('START TRANSACTION');
        
        try {
            // Remove all user rank assignments
            $wpdb->delete(
                $this->user_ranks_table,
                ['rank_id' => $rank_id],
                ['%d']
            );
            
            // Delete the rank
            $wpdb->delete(
                $this->ranks_table,
                ['id' => $rank_id],
                ['%d']
            );
            
            // Commit transaction
            $wpdb->query('COMMIT');
            
            return true;
            
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            error_log('DollarBets Rank Deletion Error: ' . $e->getMessage());
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
    
    /**
     * Recalculate all user ranks
     */
    public function recalculate_all_ranks() {
        global $wpdb;
        
        // Get all users with points
        $users = $wpdb->get_col(
            "SELECT DISTINCT user_id FROM {$this->user_points_table} WHERE balance > 0"
        );
        
        $updated = 0;
        foreach ($users as $user_id) {
            if ($this->update_user_rank($user_id)) {
                $updated++;
            }
        }
        
        return $updated;
    }
}


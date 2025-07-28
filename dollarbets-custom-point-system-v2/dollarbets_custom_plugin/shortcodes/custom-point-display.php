<?php
/**
 * Custom Point Display Shortcodes
 * Replaces Gamipress shortcodes with custom point system
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Display user points shortcode
 * Replaces [gamipress_points] shortcode
 */
function dollarbets_user_points_shortcode($atts) {
    $atts = shortcode_atts([
        'type' => 'betcoins',
        'user_id' => get_current_user_id(),
        'format' => 'number',
        'show_icon' => true,
        'show_label' => true,
        'class' => 'dollarbets-points-display'
    ], $atts);
    
    $user_id = intval($atts['user_id']);
    if (!$user_id) {
        return '<span class="error">Invalid user</span>';
    }
    
    // Get user points
    $balance = db_get_user_points($user_id, $atts['type']);
    
    // Get point type info
    global $wpdb;
    $point_types_table = $wpdb->prefix . 'dollarbets_point_types';
    $point_type = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $point_types_table WHERE slug = %s",
        $atts['type']
    ));
    
    if (!$point_type) {
        return '<span class="error">Invalid point type</span>';
    }
    
    // Format the number
    $formatted_balance = $atts['format'] === 'number' ? number_format($balance) : $balance;
    
    // Build output
    $output = '<span class="' . esc_attr($atts['class']) . '" data-point-type="' . esc_attr($atts['type']) . '">';
    
    if ($atts['show_icon'] && $point_type->icon) {
        $output .= '<span class="point-icon">' . esc_html($point_type->icon) . '</span> ';
    }
    
    $output .= '<span class="point-balance">' . esc_html($formatted_balance) . '</span>';
    
    if ($atts['show_label']) {
        $output .= ' <span class="point-label">' . esc_html($point_type->name) . '</span>';
    }
    
    $output .= '</span>';
    
    return $output;
}
add_shortcode('dollarbets_points', 'dollarbets_user_points_shortcode');

/**
 * Display user rank shortcode
 */
function dollarbets_user_rank_shortcode($atts) {
    $atts = shortcode_atts([
        'user_id' => get_current_user_id(),
        'show_icon' => true,
        'show_name' => true,
        'show_progress' => false,
        'class' => 'dollarbets-rank-display'
    ], $atts);
    
    $user_id = intval($atts['user_id']);
    if (!$user_id) {
        return '<span class="error">Invalid user</span>';
    }
    
    $rank_manager = new DollarBets_Rank_Manager();
    $user_rank = $rank_manager->get_user_rank($user_id);
    
    if (!$user_rank) {
        return '<span class="no-rank">No rank assigned</span>';
    }
    
    $output = '<span class="' . esc_attr($atts['class']) . '" data-rank-slug="' . esc_attr($user_rank->slug) . '">';
    
    if ($atts['show_icon'] && $user_rank->icon) {
        $output .= '<span class="rank-icon" style="color: ' . esc_attr($user_rank->badge_color) . '">' . esc_html($user_rank->icon) . '</span> ';
    }
    
    if ($atts['show_name']) {
        $output .= '<span class="rank-name">' . esc_html($user_rank->name) . '</span>';
    }
    
    if ($atts['show_progress']) {
        $next_rank = $rank_manager->get_next_rank($user_id);
        if ($next_rank) {
            $current_points = db_get_user_points($user_id, 'experience');
            $progress_percentage = min(100, ($current_points / $next_rank->points_required) * 100);
            
            $output .= '<div class="rank-progress">';
            $output .= '<div class="progress-bar">';
            $output .= '<div class="progress-fill" style="width: ' . $progress_percentage . '%"></div>';
            $output .= '</div>';
            $output .= '<small>Next: ' . esc_html($next_rank->name) . ' (' . number_format($next_rank->points_required - $current_points) . ' XP needed)</small>';
            $output .= '</div>';
        }
    }
    
    $output .= '</span>';
    
    return $output;
}
add_shortcode('dollarbets_rank', 'dollarbets_user_rank_shortcode');

/**
 * Display points leaderboard shortcode
 */
function dollarbets_points_leaderboard_shortcode($atts) {
    $atts = shortcode_atts([
        'type' => 'betcoins',
        'limit' => 10,
        'show_avatar' => true,
        'show_rank' => true,
        'current_user_highlight' => true,
        'class' => 'dollarbets-leaderboard'
    ], $atts);
    
    global $wpdb;
    
    $point_types_table = $wpdb->prefix . 'dollarbets_point_types';
    $user_points_table = $wpdb->prefix . 'dollarbets_user_points';
    
    // Get point type
    $point_type = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $point_types_table WHERE slug = %s",
        $atts['type']
    ));
    
    if (!$point_type) {
        return '<div class="error">Invalid point type</div>';
    }
    
    // Get top users
    $limit = intval($atts['limit']);
    $top_users = $wpdb->get_results($wpdb->prepare("
        SELECT up.user_id, up.balance, u.display_name, u.user_login
        FROM $user_points_table up
        JOIN {$wpdb->users} u ON up.user_id = u.ID
        WHERE up.point_type_id = %d AND up.balance > 0
        ORDER BY up.balance DESC
        LIMIT %d
    ", $point_type->id, $limit));
    
    if (!$top_users) {
        return '<div class="no-data">No leaderboard data available</div>';
    }
    
    $output = '<div class="' . esc_attr($atts['class']) . '">';
    $output .= '<h3>Top ' . esc_html($point_type->name) . ' Holders</h3>';
    $output .= '<ol class="leaderboard-list">';
    
    $current_user_id = get_current_user_id();
    $rank_manager = new DollarBets_Rank_Manager();
    
    foreach ($top_users as $index => $user) {
        $position = $index + 1;
        $is_current_user = $atts['current_user_highlight'] && $user->user_id == $current_user_id;
        
        $item_class = 'leaderboard-item';
        if ($is_current_user) {
            $item_class .= ' current-user';
        }
        if ($position <= 3) {
            $item_class .= ' top-three position-' . $position;
        }
        
        $output .= '<li class="' . $item_class . '">';
        $output .= '<span class="position">#' . $position . '</span>';
        
        if ($atts['show_avatar']) {
            $output .= '<span class="avatar">' . get_avatar($user->user_id, 32) . '</span>';
        }
        
        $output .= '<span class="user-name">' . esc_html($user->display_name) . '</span>';
        
        if ($atts['show_rank']) {
            $user_rank = $rank_manager->get_user_rank($user->user_id);
            if ($user_rank) {
                $output .= '<span class="user-rank">' . esc_html($user_rank->icon) . ' ' . esc_html($user_rank->name) . '</span>';
            }
        }
        
        $output .= '<span class="points">';
        $output .= '<span class="point-icon">' . esc_html($point_type->icon) . '</span>';
        $output .= '<span class="point-value">' . number_format($user->balance) . '</span>';
        $output .= '</span>';
        
        $output .= '</li>';
    }
    
    $output .= '</ol>';
    $output .= '</div>';
    
    return $output;
}
add_shortcode('dollarbets_leaderboard', 'dollarbets_points_leaderboard_shortcode');

/**
 * Display user statistics shortcode
 */
function dollarbets_user_stats_shortcode($atts) {
    $atts = shortcode_atts([
        'user_id' => get_current_user_id(),
        'show_points' => true,
        'show_rank' => true,
        'show_achievements' => true,
        'show_activity' => true,
        'class' => 'dollarbets-user-stats'
    ], $atts);
    
    $user_id = intval($atts['user_id']);
    if (!$user_id) {
        return '<div class="error">Invalid user</div>';
    }
    
    $user = get_userdata($user_id);
    if (!$user) {
        return '<div class="error">User not found</div>';
    }
    
    $output = '<div class="' . esc_attr($atts['class']) . '">';
    $output .= '<h3>User Statistics</h3>';
    
    // Points section
    if ($atts['show_points']) {
        $point_manager = new DollarBets_Point_Manager();
        $user_points = $point_manager->get_user_all_points($user_id);
        
        $output .= '<div class="stats-section points-section">';
        $output .= '<h4>Points</h4>';
        $output .= '<div class="points-grid">';
        
        foreach ($user_points as $slug => $data) {
            $output .= '<div class="point-item">';
            $output .= '<span class="point-icon">' . esc_html($data['icon']) . '</span>';
            $output .= '<span class="point-name">' . esc_html($data['name']) . '</span>';
            $output .= '<span class="point-balance">' . number_format($data['balance']) . '</span>';
            $output .= '</div>';
        }
        
        $output .= '</div>';
        $output .= '</div>';
    }
    
    // Rank section
    if ($atts['show_rank']) {
        $rank_manager = new DollarBets_Rank_Manager();
        $user_rank = $rank_manager->get_user_rank($user_id);
        
        $output .= '<div class="stats-section rank-section">';
        $output .= '<h4>Rank</h4>';
        
        if ($user_rank) {
            $output .= '<div class="rank-display">';
            $output .= '<span class="rank-icon" style="color: ' . esc_attr($user_rank->badge_color) . '">' . esc_html($user_rank->icon) . '</span>';
            $output .= '<span class="rank-name">' . esc_html($user_rank->name) . '</span>';
            $output .= '</div>';
            
            // Show progress to next rank
            $next_rank = $rank_manager->get_next_rank($user_id);
            if ($next_rank) {
                $current_points = db_get_user_points($user_id, 'experience');
                $progress_percentage = min(100, ($current_points / $next_rank->points_required) * 100);
                
                $output .= '<div class="rank-progress">';
                $output .= '<div class="progress-label">Progress to ' . esc_html($next_rank->name) . '</div>';
                $output .= '<div class="progress-bar">';
                $output .= '<div class="progress-fill" style="width: ' . $progress_percentage . '%"></div>';
                $output .= '</div>';
                $output .= '<div class="progress-text">' . number_format($current_points) . ' / ' . number_format($next_rank->points_required) . ' XP</div>';
                $output .= '</div>';
            }
        } else {
            $output .= '<div class="no-rank">No rank assigned</div>';
        }
        
        $output .= '</div>';
    }
    
    // Achievements section
    if ($atts['show_achievements']) {
        $achievement_manager = new DollarBets_Achievement_Manager();
        $user_achievements = $achievement_manager->get_user_achievements($user_id);
        
        $output .= '<div class="stats-section achievements-section">';
        $output .= '<h4>Achievements (' . count($user_achievements) . ')</h4>';
        
        if ($user_achievements) {
            $output .= '<div class="achievements-grid">';
            foreach (array_slice($user_achievements, 0, 6) as $achievement) {
                $output .= '<div class="achievement-item">';
                $output .= '<span class="achievement-icon" style="color: ' . esc_attr($achievement->badge_color) . '">' . esc_html($achievement->icon) . '</span>';
                $output .= '<span class="achievement-name">' . esc_html($achievement->name) . '</span>';
                $output .= '</div>';
            }
            $output .= '</div>';
            
            if (count($user_achievements) > 6) {
                $output .= '<div class="more-achievements">+' . (count($user_achievements) - 6) . ' more achievements</div>';
            }
        } else {
            $output .= '<div class="no-achievements">No achievements unlocked yet</div>';
        }
        
        $output .= '</div>';
    }
    
    // Activity section
    if ($atts['show_activity']) {
        global $wpdb;
        $transactions_table = $wpdb->prefix . 'dollarbets_point_transactions';
        
        $recent_activity = $wpdb->get_results($wpdb->prepare("
            SELECT t.*, pt.name as point_type_name, pt.icon as point_type_icon
            FROM $transactions_table t
            JOIN {$wpdb->prefix}dollarbets_point_types pt ON t.point_type_id = pt.id
            WHERE t.user_id = %d
            ORDER BY t.created_at DESC
            LIMIT 5
        ", $user_id));
        
        $output .= '<div class="stats-section activity-section">';
        $output .= '<h4>Recent Activity</h4>';
        
        if ($recent_activity) {
            $output .= '<div class="activity-list">';
            foreach ($recent_activity as $activity) {
                $output .= '<div class="activity-item">';
                $output .= '<span class="activity-icon">' . esc_html($activity->point_type_icon) . '</span>';
                $output .= '<span class="activity-description">' . esc_html($activity->reason) . '</span>';
                $output .= '<span class="activity-amount ' . ($activity->transaction_type === 'earn' ? 'positive' : 'negative') . '">';
                $output .= ($activity->transaction_type === 'earn' ? '+' : '-') . number_format($activity->amount);
                $output .= '</span>';
                $output .= '<span class="activity-date">' . human_time_diff(strtotime($activity->created_at)) . ' ago</span>';
                $output .= '</div>';
            }
            $output .= '</div>';
        } else {
            $output .= '<div class="no-activity">No recent activity</div>';
        }
        
        $output .= '</div>';
    }
    
    $output .= '</div>';
    
    return $output;
}
add_shortcode('dollarbets_user_stats', 'dollarbets_user_stats_shortcode');

/**
 * Add CSS for shortcodes
 */
function dollarbets_shortcodes_styles() {
    ?>
    <style>
    .dollarbets-points-display {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        font-weight: 500;
    }
    
    .dollarbets-rank-display {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        font-weight: 500;
    }
    
    .rank-progress {
        margin-top: 10px;
    }
    
    .progress-bar {
        width: 100%;
        height: 8px;
        background: #e0e0e0;
        border-radius: 4px;
        overflow: hidden;
        margin: 5px 0;
    }
    
    .progress-fill {
        height: 100%;
        background: linear-gradient(90deg, #007cba, #00a0d2);
        transition: width 0.3s ease;
    }
    
    .dollarbets-leaderboard {
        background: #fff;
        border: 1px solid #ddd;
        border-radius: 8px;
        padding: 20px;
        margin: 20px 0;
    }
    
    .leaderboard-list {
        list-style: none;
        padding: 0;
        margin: 0;
    }
    
    .leaderboard-item {
        display: flex;
        align-items: center;
        gap: 15px;
        padding: 10px;
        border-bottom: 1px solid #eee;
        transition: background 0.3s ease;
    }
    
    .leaderboard-item:hover {
        background: #f8f9fa;
    }
    
    .leaderboard-item.current-user {
        background: #e3f2fd;
        border-color: #2196f3;
    }
    
    .leaderboard-item.top-three .position {
        font-weight: bold;
        font-size: 18px;
    }
    
    .leaderboard-item.position-1 .position { color: #ffd700; }
    .leaderboard-item.position-2 .position { color: #c0c0c0; }
    .leaderboard-item.position-3 .position { color: #cd7f32; }
    
    .leaderboard-item .user-name {
        flex: 1;
        font-weight: 500;
    }
    
    .leaderboard-item .points {
        display: flex;
        align-items: center;
        gap: 5px;
        font-weight: bold;
        color: #007cba;
    }
    
    .dollarbets-user-stats {
        background: #fff;
        border: 1px solid #ddd;
        border-radius: 8px;
        padding: 20px;
        margin: 20px 0;
    }
    
    .stats-section {
        margin-bottom: 20px;
        padding-bottom: 20px;
        border-bottom: 1px solid #eee;
    }
    
    .stats-section:last-child {
        border-bottom: none;
        margin-bottom: 0;
    }
    
    .stats-section h4 {
        margin: 0 0 15px 0;
        color: #333;
        font-size: 16px;
    }
    
    .points-grid,
    .achievements-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 10px;
    }
    
    .point-item,
    .achievement-item {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 10px;
        background: #f8f9fa;
        border-radius: 4px;
    }
    
    .activity-list {
        space-y: 8px;
    }
    
    .activity-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 8px;
        background: #f8f9fa;
        border-radius: 4px;
        margin-bottom: 8px;
    }
    
    .activity-description {
        flex: 1;
        font-size: 14px;
    }
    
    .activity-amount.positive {
        color: #28a745;
        font-weight: 500;
    }
    
    .activity-amount.negative {
        color: #dc3545;
        font-weight: 500;
    }
    
    .activity-date {
        font-size: 12px;
        color: #666;
    }
    
    .error,
    .no-data,
    .no-rank,
    .no-achievements,
    .no-activity {
        color: #666;
        font-style: italic;
        text-align: center;
        padding: 20px;
    }
    
    /* Dark mode support */
    body.dark-mode .dollarbets-leaderboard,
    body.dark-mode .dollarbets-user-stats {
        background: #2c2c2c;
        border-color: #555;
        color: #fff;
    }
    
    body.dark-mode .stats-section {
        border-bottom-color: #555;
    }
    
    body.dark-mode .point-item,
    body.dark-mode .achievement-item,
    body.dark-mode .activity-item {
        background: #3c3c3c;
    }
    
    body.dark-mode .leaderboard-item {
        border-bottom-color: #555;
    }
    
    body.dark-mode .leaderboard-item:hover {
        background: #3c3c3c;
    }
    </style>
    <?php
}
add_action('wp_head', 'dollarbets_shortcodes_styles');


<?php
if (!defined('ABSPATH')) exit;

/**
 * Leaderboard System for DollarBets Platform
 * Handles ranking, display, and real-time updates
 */

class DollarBets_Leaderboard {
    
    public function __construct() {
        add_action('rest_api_init', [$this, 'register_leaderboard_routes']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_leaderboard_scripts']);
        
        // Update leaderboard when bets are placed or resolved
        add_action('dollarbets_bet_placed', [$this, 'update_user_stats'], 10, 3);
        add_action('dollarbets_prediction_resolved', [$this, 'update_prediction_stats'], 10, 2);
    }
    
    /**
     * Register REST API routes for leaderboard
     */
    public function register_leaderboard_routes() {
        register_rest_route('dollarbets/v1', '/leaderboard', [
            'methods' => 'GET',
            'callback' => [$this, 'get_leaderboard_data'],
            'permission_callback' => '__return_true'
        ]);
        
        register_rest_route('dollarbets/v1', '/user-stats/(?P<user_id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_user_stats'],
            'permission_callback' => '__return_true'
        ]);
        
        register_rest_route('dollarbets/v1', '/leaderboard-refresh', [
            'methods' => 'POST',
            'callback' => [$this, 'refresh_leaderboard'],
            'permission_callback' => function() {
                return function_exists('current_user_can') ? current_user_can('manage_options') : false;
            }
        ]);
    }
    
    /**
     * Enqueue leaderboard scripts and styles
     */
    public function enqueue_leaderboard_scripts() {
        wp_enqueue_script('dollarbets-leaderboard', plugin_dir_url(__FILE__) . '../assets/js/leaderboard.js', ['jquery'], '1.0.0', true);
        wp_localize_script('dollarbets-leaderboard', 'dollarBetsLeaderboard', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'restUrl' => rest_url('dollarbets/v1/'),
            'nonce' => wp_create_nonce('wp_rest')
        ]);
    }
    
    /**
     * Get leaderboard data
     */
    public function get_leaderboard_data(WP_REST_Request $request) {
        $type = sanitize_text_field($request->get_param('type') ?? 'points');
        $limit = absint($request->get_param('limit') ?? 20);
        $period = sanitize_text_field($request->get_param('period') ?? 'all_time');
        
        $leaderboard_data = $this->calculate_leaderboard($type, $limit, $period);
        
        return rest_ensure_response([
            'success' => true,
            'data' => $leaderboard_data,
            'type' => $type,
            'period' => $period,
            'last_updated' => current_time('mysql')
        ]);
    }
    
    /**
     * Get individual user stats
     */
    public function get_user_stats(WP_REST_Request $request) {
        $user_id = absint($request['user_id']);
        
        if (!$user_id) {
            return new WP_Error('invalid_user', 'Invalid user ID.', ['status' => 400]);
        }
        
        $stats = $this->get_user_statistics($user_id);
        
        return rest_ensure_response([
            'success' => true,
            'user_id' => $user_id,
            'stats' => $stats
        ]);
    }
    
    /**
     * Refresh leaderboard cache
     */
    public function refresh_leaderboard(WP_REST_Request $request) {
        delete_transient('dollarbets_leaderboard_points');
        delete_transient('dollarbets_leaderboard_wins');
        delete_transient('dollarbets_leaderboard_accuracy');
        
        return rest_ensure_response([
            'success' => true,
            'message' => 'Leaderboard cache refreshed successfully.'
        ]);
    }
    
    /**
     * Calculate leaderboard based on type and period
     */
    private function calculate_leaderboard($type = 'points', $limit = 20, $period = 'all_time') {
        $cache_key = "dollarbets_leaderboard_{$type}_{$period}_{$limit}";
        $cached_data = get_transient($cache_key);
        
        if ($cached_data !== false) {
            return $cached_data;
        }
        
        $users = get_users(['meta_key' => 'db_bet_history']);
        $leaderboard = [];
        
        foreach ($users as $user) {
            $stats = $this->get_user_statistics($user->ID, $period);
            
            if ($stats['total_bets'] > 0) { // Only include users who have placed bets
                $leaderboard[] = [
                    'user_id' => $user->ID,
                    'username' => $user->display_name,
                    'avatar' => $this->get_user_avatar($user->ID),
                    'points' => $stats['current_points'],
                    'total_bets' => $stats['total_bets'],
                    'total_wagered' => $stats['total_wagered'],
                    'total_won' => $stats['total_won'],
                    'wins' => $stats['wins'],
                    'losses' => $stats['losses'],
                    'accuracy' => $stats['accuracy'],
                    'profit_loss' => $stats['profit_loss'],
                    'rank' => 0, // Will be set after sorting
                    'badges' => $this->get_user_badges($user->ID, $stats)
                ];
            }
        }
        
        // Sort based on type
        switch ($type) {
            case 'points':
                usort($leaderboard, function($a, $b) {
                    return $b['points'] - $a['points'];
                });
                break;
            case 'wins':
                usort($leaderboard, function($a, $b) {
                    return $b['wins'] - $a['wins'];
                });
                break;
            case 'accuracy':
                usort($leaderboard, function($a, $b) {
                    return $b['accuracy'] - $a['accuracy'];
                });
                break;
            case 'profit':
                usort($leaderboard, function($a, $b) {
                    return $b['profit_loss'] - $a['profit_loss'];
                });
                break;
        }
        
        // Assign ranks and handle ties
        $current_rank = 1;
        $previous_value = null;
        
        for ($i = 0; $i < count($leaderboard); $i++) {
            $current_value = $leaderboard[$i][$type === 'profit' ? 'profit_loss' : $type];
            
            if ($previous_value !== null && $current_value !== $previous_value) {
                $current_rank = $i + 1;
            }
            
            $leaderboard[$i]['rank'] = $current_rank;
            $previous_value = $current_value;
        }
        
        // Limit results
        $leaderboard = array_slice($leaderboard, 0, $limit);
        
        // Cache for 5 minutes
        set_transient($cache_key, $leaderboard, 300);
        
        return $leaderboard;
    }
    
    /**
     * Get comprehensive user statistics
     */
    public function get_user_statistics($user_id, $period = 'all_time') {
        $bet_history = get_user_meta($user_id, 'db_bet_history', true);
        if (!is_array($bet_history)) $bet_history = [];
        
        // Filter by period if needed
        if ($period !== 'all_time') {
            $bet_history = $this->filter_by_period($bet_history, $period);
        }
        
        $stats = [
            'current_points' => gamipress_get_user_points($user_id, 'betcoins'),
            'total_bets' => count($bet_history),
            'total_wagered' => 0,
            'total_won' => 0,
            'wins' => 0,
            'losses' => 0,
            'pending' => 0,
            'accuracy' => 0,
            'profit_loss' => 0,
            'biggest_win' => 0,
            'biggest_loss' => 0,
            'win_streak' => 0,
            'current_streak' => 0,
            'favorite_category' => 'General'
        ];
        
        $resolved_bets = 0;
        $current_streak = 0;
        $max_streak = 0;
        $last_result = null;
        $categories = [];
        
        foreach ($bet_history as $bet) {
            $stats['total_wagered'] += $bet['amount'];
            
            // Check if prediction is resolved
            $prediction_result = get_post_meta($bet['prediction_id'], '_prediction_resolved', true);
            
            if ($prediction_result) {
                $resolved_bets++;
                $won = ($prediction_result === $bet['choice']);
                
                if ($won) {
                    $stats['wins']++;
                    $winnings = $bet['amount'] * 2; // 2x payout
                    $stats['total_won'] += $winnings;
                    $profit = $winnings - $bet['amount'];
                    $stats['profit_loss'] += $profit;
                    
                    if ($profit > $stats['biggest_win']) {
                        $stats['biggest_win'] = $profit;
                    }
                    
                    // Streak tracking
                    if ($last_result === true) {
                        $current_streak++;
                    } else {
                        $current_streak = 1;
                    }
                    $last_result = true;
                } else {
                    $stats['losses']++;
                    $loss = $bet['amount'];
                    $stats['profit_loss'] -= $loss;
                    
                    if ($loss > $stats['biggest_loss']) {
                        $stats['biggest_loss'] = $loss;
                    }
                    
                    // Reset streak
                    if ($current_streak > $max_streak) {
                        $max_streak = $current_streak;
                    }
                    $current_streak = 0;
                    $last_result = false;
                }
                
                if ($current_streak > $max_streak) {
                    $max_streak = $current_streak;
                }
            } else {
                $stats['pending']++;
            }
            
            // Track categories
            $prediction = get_post($bet['prediction_id']);
            if ($prediction) {
                $terms = wp_get_object_terms($prediction->ID, 'prediction_category');
                if (!empty($terms) && !is_wp_error($terms)) {
                    $category = $terms[0]->name;
                    $categories[$category] = ($categories[$category] ?? 0) + 1;
                }
            }
        }
        
        // Calculate accuracy
        if ($resolved_bets > 0) {
            $stats['accuracy'] = round(($stats['wins'] / $resolved_bets) * 100, 1);
        }
        
        $stats['win_streak'] = $max_streak;
        $stats['current_streak'] = $last_result === true ? $current_streak : 0;
        
        // Find favorite category
        if (!empty($categories)) {
            $stats['favorite_category'] = array_keys($categories, max($categories))[0];
        }
        
        return $stats;
    }
    
    /**
     * Filter bet history by time period
     */
    private function filter_by_period($bet_history, $period) {
        $now = current_time('timestamp');
        $cutoff_time = $now;
        
        switch ($period) {
            case 'today':
                $cutoff_time = strtotime('today', $now);
                break;
            case 'week':
                $cutoff_time = strtotime('-1 week', $now);
                break;
            case 'month':
                $cutoff_time = strtotime('-1 month', $now);
                break;
            case 'year':
                $cutoff_time = strtotime('-1 year', $now);
                break;
            default:
                return $bet_history; // all_time
        }
        
        return array_filter($bet_history, function($bet) use ($cutoff_time) {
            return strtotime($bet['timestamp']) >= $cutoff_time;
        });
    }
    
    /**
     * Get user avatar URL
     */
    private function get_user_avatar($user_id) {
        // Try Ultimate Member avatar first
        if (function_exists('um_get_user_avatar_url')) {
            $um_avatar = um_get_user_avatar_url($user_id, 'original');
            if ($um_avatar) {
                return $um_avatar;
            }
        }
        
        // Fallback to WordPress avatar
        return get_avatar_url($user_id, ['size' => 80]);
    }
    
    /**
     * Get user badges based on achievements
     */
    private function get_user_badges($user_id, $stats) {
        $badges = [];
        
        // Points-based badges
        if ($stats['current_points'] >= 10000) {
            $badges[] = ['name' => 'High Roller', 'icon' => '💎', 'color' => '#FFD700'];
        } elseif ($stats['current_points'] >= 5000) {
            $badges[] = ['name' => 'Big Player', 'icon' => '💰', 'color' => '#C0C0C0'];
        } elseif ($stats['current_points'] >= 1000) {
            $badges[] = ['name' => 'Active Player', 'icon' => '🎯', 'color' => '#CD7F32'];
        }
        
        // Accuracy badges
        if ($stats['accuracy'] >= 80 && $stats['total_bets'] >= 10) {
            $badges[] = ['name' => 'Sharp Shooter', 'icon' => '🎯', 'color' => '#FF6B6B'];
        } elseif ($stats['accuracy'] >= 70 && $stats['total_bets'] >= 5) {
            $badges[] = ['name' => 'Good Eye', 'icon' => '👁️', 'color' => '#4ECDC4'];
        }
        
        // Streak badges
        if ($stats['win_streak'] >= 10) {
            $badges[] = ['name' => 'Streak Master', 'icon' => '🔥', 'color' => '#FF4757'];
        } elseif ($stats['win_streak'] >= 5) {
            $badges[] = ['name' => 'Hot Streak', 'icon' => '⚡', 'color' => '#FFA502'];
        }
        
        // Volume badges
        if ($stats['total_bets'] >= 100) {
            $badges[] = ['name' => 'Veteran', 'icon' => '🏆', 'color' => '#8E44AD'];
        } elseif ($stats['total_bets'] >= 50) {
            $badges[] = ['name' => 'Regular', 'icon' => '🎪', 'color' => '#3498DB'];
        } elseif ($stats['total_bets'] >= 10) {
            $badges[] = ['name' => 'Newcomer', 'icon' => '🌟', 'color' => '#2ECC71'];
        }
        
        return $badges;
    }
    
    /**
     * Update user stats when bet is placed
     */
    public function update_user_stats($user_id, $prediction_id, $amount) {
        // Trigger leaderboard cache refresh
        delete_transient('dollarbets_leaderboard_points');
        delete_transient('dollarbets_leaderboard_wins');
        delete_transient('dollarbets_leaderboard_accuracy');
        
        // Fire action for other plugins/themes
        do_action('dollarbets_leaderboard_updated', $user_id);
    }
    
    /**
     * Update prediction stats when resolved
     */
    public function update_prediction_stats($prediction_id, $winning_choice) {
        // Clear all leaderboard caches when predictions are resolved
        delete_transient('dollarbets_leaderboard_points');
        delete_transient('dollarbets_leaderboard_wins');
        delete_transient('dollarbets_leaderboard_accuracy');
        
        // Fire action for other plugins/themes
        do_action('dollarbets_prediction_resolved_leaderboard', $prediction_id, $winning_choice);
    }
}

// Initialize leaderboard system
new DollarBets_Leaderboard();

/**
 * Leaderboard shortcode
 */
function dollarbets_leaderboard_shortcode($atts) {
    $atts = shortcode_atts([
        'type' => 'points',
        'limit' => '10',
        'period' => 'all_time',
        'title' => 'Leaderboard',
        'show_stats' => 'true',
        'auto_refresh' => 'true',
        'refresh_interval' => '30'
    ], $atts);
    
    $leaderboard_id = 'dollarbets-leaderboard-' . uniqid();
    
    ob_start();
    ?>
    <div id="<?php echo $leaderboard_id; ?>" class="dollarbets-leaderboard" 
         data-type="<?php echo esc_attr($atts['type']); ?>"
         data-limit="<?php echo esc_attr($atts['limit']); ?>"
         data-period="<?php echo esc_attr($atts['period']); ?>"
         data-auto-refresh="<?php echo esc_attr($atts['auto_refresh']); ?>"
         data-refresh-interval="<?php echo esc_attr($atts['refresh_interval']); ?>">
        
        <div class="leaderboard-header">
            <h3><?php echo esc_html($atts['title']); ?></h3>
            
            <div class="leaderboard-controls">
                <select class="leaderboard-type-select">
                    <option value="points" <?php selected($atts['type'], 'points'); ?>>Points</option>
                    <option value="wins" <?php selected($atts['type'], 'wins'); ?>>Wins</option>
                    <option value="accuracy" <?php selected($atts['type'], 'accuracy'); ?>>Accuracy</option>
                    <option value="profit" <?php selected($atts['type'], 'profit'); ?>>Profit/Loss</option>
                </select>
                
                <select class="leaderboard-period-select">
                    <option value="all_time" <?php selected($atts['period'], 'all_time'); ?>>All Time</option>
                    <option value="year" <?php selected($atts['period'], 'year'); ?>>This Year</option>
                    <option value="month" <?php selected($atts['period'], 'month'); ?>>This Month</option>
                    <option value="week" <?php selected($atts['period'], 'week'); ?>>This Week</option>
                    <option value="today" <?php selected($atts['period'], 'today'); ?>>Today</option>
                </select>
                
                <button class="leaderboard-refresh-btn" title="Refresh">🔄</button>
            </div>
        </div>
        
        <div class="leaderboard-content">
            <div class="loading-spinner" style="display: none;">Loading...</div>
            <div class="leaderboard-table"></div>
        </div>
        
        <?php if ($atts['show_stats'] === 'true'): ?>
        <div class="leaderboard-stats">
            <div class="stat-item">
                <span class="stat-label">Total Players:</span>
                <span class="stat-value" id="total-players">-</span>
            </div>
            <div class="stat-item">
                <span class="stat-label">Last Updated:</span>
                <span class="stat-value" id="last-updated">-</span>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <style>
    .dollarbets-leaderboard {
        max-width: 800px;
        margin: 0 auto;
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        overflow: hidden;
        transition: all 0.3s ease;
    }
    
    .leaderboard-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
    }
    
    .leaderboard-header h3 {
        margin: 0;
        font-size: 24px;
        font-weight: 600;
    }
    
    .leaderboard-controls {
        display: flex;
        gap: 10px;
        align-items: center;
        flex-wrap: wrap;
    }
    
    .leaderboard-type-select,
    .leaderboard-period-select {
        padding: 8px 12px;
        border: none;
        border-radius: 6px;
        background: rgba(255,255,255,0.2);
        color: white;
        font-size: 14px;
        min-width: 120px;
    }
    
    .leaderboard-type-select option,
    .leaderboard-period-select option {
        background: #333;
        color: white;
    }
    
    .leaderboard-refresh-btn {
        background: rgba(255,255,255,0.2);
        border: none;
        color: white;
        padding: 8px 12px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 16px;
        transition: background 0.3s ease;
    }
    
    .leaderboard-refresh-btn:hover {
        background: rgba(255,255,255,0.3);
    }
    
    .leaderboard-content {
        padding: 20px;
        min-height: 300px;
        position: relative;
    }
    
    .loading-spinner {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        font-size: 18px;
        color: #666;
    }
    
    .leaderboard-table {
        width: 100%;
    }
    
    .leaderboard-row {
        display: flex;
        align-items: center;
        padding: 15px 0;
        border-bottom: 1px solid #eee;
        transition: background 0.3s ease;
    }
    
    .leaderboard-row:hover {
        background: #f8f9fa;
    }
    
    .leaderboard-row:last-child {
        border-bottom: none;
    }
    
    .rank {
        font-size: 20px;
        font-weight: bold;
        color: #333;
        width: 50px;
        text-align: center;
    }
    
    .rank.rank-1 { color: #FFD700; }
    .rank.rank-2 { color: #C0C0C0; }
    .rank.rank-3 { color: #CD7F32; }
    
    .user-info {
        display: flex;
        align-items: center;
        flex: 1;
        margin-left: 15px;
    }
    
    .user-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        margin-right: 12px;
        object-fit: cover;
    }
    
    .user-details {
        flex: 1;
    }
    
    .username {
        font-weight: 600;
        color: #333;
        margin-bottom: 2px;
    }
    
    .user-badges {
        display: flex;
        gap: 4px;
        flex-wrap: wrap;
    }
    
    .badge {
        display: inline-flex;
        align-items: center;
        gap: 2px;
        padding: 2px 6px;
        border-radius: 12px;
        font-size: 10px;
        font-weight: 500;
        color: white;
    }
    
    .stats {
        display: flex;
        gap: 20px;
        align-items: center;
        font-size: 14px;
    }
    
    .stat {
        text-align: center;
    }
    
    .stat-value {
        display: block;
        font-weight: bold;
        font-size: 16px;
        color: #333;
    }
    
    .stat-label {
        display: block;
        color: #666;
        font-size: 12px;
    }
    
    .leaderboard-stats {
        background: #f8f9fa;
        padding: 15px 20px;
        display: flex;
        justify-content: space-around;
        border-top: 1px solid #eee;
    }
    
    .stat-item {
        text-align: center;
    }
    
    .stat-item .stat-label {
        display: block;
        color: #666;
        font-size: 12px;
        margin-bottom: 4px;
    }
    
    .stat-item .stat-value {
        font-weight: bold;
        color: #333;
    }
    
    /* Dark mode styles */
    body.dollarbets-dark-mode .dollarbets-leaderboard,
    .dollarbets-dark-mode .dollarbets-leaderboard {
        background: #2c2c2c;
        color: #fff;
    }
    
    body.dollarbets-dark-mode .leaderboard-content,
    .dollarbets-dark-mode .leaderboard-content {
        background: #2c2c2c;
    }
    
    body.dollarbets-dark-mode .leaderboard-row,
    .dollarbets-dark-mode .leaderboard-row {
        border-bottom-color: #444;
    }
    
    body.dollarbets-dark-mode .leaderboard-row:hover,
    .dollarbets-dark-mode .leaderboard-row:hover {
        background: #3c3c3c;
    }
    
    body.dollarbets-dark-mode .username,
    body.dollarbets-dark-mode .stat-value,
    .dollarbets-dark-mode .username,
    .dollarbets-dark-mode .stat-value {
        color: #fff;
    }
    
    body.dollarbets-dark-mode .stat-label,
    .dollarbets-dark-mode .stat-label {
        color: #ccc;
    }
    
    body.dollarbets-dark-mode .leaderboard-stats,
    .dollarbets-dark-mode .leaderboard-stats {
        background: #3c3c3c;
        border-top-color: #555;
    }
    
    body.dollarbets-dark-mode .rank,
    .dollarbets-dark-mode .rank {
        color: #fff;
    }
    
    body.dollarbets-dark-mode .rank.rank-1,
    .dollarbets-dark-mode .rank.rank-1 { color: #FFD700; }
    body.dollarbets-dark-mode .rank.rank-2,
    .dollarbets-dark-mode .rank.rank-2 { color: #C0C0C0; }
    body.dollarbets-dark-mode .rank.rank-3,
    .dollarbets-dark-mode .rank.rank-3 { color: #CD7F32; }
    
    /* Mobile responsive */
    @media (max-width: 768px) {
        .leaderboard-header {
            flex-direction: column;
            gap: 15px;
            text-align: center;
        }
        
        .leaderboard-controls {
            justify-content: center;
            width: 100%;
        }
        
        .leaderboard-type-select,
        .leaderboard-period-select {
            min-width: 100px;
            font-size: 12px;
        }
        
        .stats {
            gap: 10px;
            font-size: 12px;
        }
        
        .user-info {
            margin-left: 10px;
        }
        
        .user-avatar {
            width: 32px;
            height: 32px;
        }
        
        .leaderboard-stats {
            flex-direction: column;
            gap: 10px;
        }
    }
    
    @media (max-width: 480px) {
        .leaderboard-controls {
            flex-direction: column;
            width: 100%;
        }
        
        .leaderboard-type-select,
        .leaderboard-period-select {
            width: 100%;
            margin-bottom: 5px;
        }
        
        .stats {
            flex-direction: column;
            gap: 5px;
        }
        
        .stat {
            font-size: 11px;
        }
    }
    </style>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const leaderboard = document.getElementById('<?php echo $leaderboard_id; ?>');
        const typeSelect = leaderboard.querySelector('.leaderboard-type-select');
        const periodSelect = leaderboard.querySelector('.leaderboard-period-select');
        const refreshBtn = leaderboard.querySelector('.leaderboard-refresh-btn');
        const loadingSpinner = leaderboard.querySelector('.loading-spinner');
        const tableContainer = leaderboard.querySelector('.leaderboard-table');
        
        let refreshInterval;
        
        function loadLeaderboard() {
            loadingSpinner.style.display = 'block';
            tableContainer.innerHTML = '';
            
            const params = new URLSearchParams({
                type: typeSelect.value,
                period: periodSelect.value,
                limit: leaderboard.dataset.limit
            });
            
            fetch(`/wp-json/dollarbets/v1/leaderboard?${params}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        renderLeaderboard(data.data);
                        updateStats(data);
                    } else {
                        tableContainer.innerHTML = '<p>Error loading leaderboard data.</p>';
                    }
                })
                .catch(error => {
                    console.error('Leaderboard error:', error);
                    tableContainer.innerHTML = '<p>Error loading leaderboard data.</p>';
                })
                .finally(() => {
                    loadingSpinner.style.display = 'none';
                });
        }
        
        function renderLeaderboard(data) {
            if (data.length === 0) {
                tableContainer.innerHTML = '<p style="text-align: center; color: #666; padding: 40px;">No data available yet. Place some bets to see the leaderboard!</p>';
                return;
            }
            
            const html = data.map(user => `
                <div class="leaderboard-row">
                    <div class="rank rank-${user.rank}">#${user.rank}</div>
                    <div class="user-info">
                        <img src="${user.avatar}" alt="${user.username}" class="user-avatar">
                        <div class="user-details">
                            <div class="username">${user.username}</div>
                            <div class="user-badges">
                                ${user.badges.map(badge => `
                                    <span class="badge" style="background-color: ${badge.color}">
                                        ${badge.icon} ${badge.name}
                                    </span>
                                `).join('')}
                            </div>
                        </div>
                    </div>
                    <div class="stats">
                        <div class="stat">
                            <span class="stat-value">${user.points.toLocaleString()}</span>
                            <span class="stat-label">Points</span>
                        </div>
                        <div class="stat">
                            <span class="stat-value">${user.wins}/${user.total_bets}</span>
                            <span class="stat-label">W/L</span>
                        </div>
                        <div class="stat">
                            <span class="stat-value">${user.accuracy}%</span>
                            <span class="stat-label">Accuracy</span>
                        </div>
                        <div class="stat">
                            <span class="stat-value" style="color: ${user.profit_loss >= 0 ? '#28a745' : '#dc3545'}">
                                ${user.profit_loss >= 0 ? '+' : ''}${user.profit_loss.toLocaleString()}
                            </span>
                            <span class="stat-label">P/L</span>
                        </div>
                    </div>
                </div>
            `).join('');
            
            tableContainer.innerHTML = html;
        }
        
        function updateStats(data) {
            const totalPlayersEl = document.getElementById('total-players');
            const lastUpdatedEl = document.getElementById('last-updated');
            
            if (totalPlayersEl) {
                totalPlayersEl.textContent = data.data.length;
            }
            
            if (lastUpdatedEl) {
                const date = new Date(data.last_updated);
                lastUpdatedEl.textContent = date.toLocaleTimeString();
            }
        }
        
        function setupAutoRefresh() {
            if (leaderboard.dataset.autoRefresh === 'true') {
                const interval = parseInt(leaderboard.dataset.refreshInterval) * 1000;
                refreshInterval = setInterval(loadLeaderboard, interval);
            }
        }
        
        // Event listeners
        typeSelect.addEventListener('change', loadLeaderboard);
        periodSelect.addEventListener('change', loadLeaderboard);
        refreshBtn.addEventListener('click', loadLeaderboard);
        
        // Initial load
        loadLeaderboard();
        setupAutoRefresh();
        
        // Cleanup on page unload
        window.addEventListener('beforeunload', function() {
            if (refreshInterval) {
                clearInterval(refreshInterval);
            }
        });
    });
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('dollarbets_leaderboard', 'dollarbets_leaderboard_shortcode');

/**
 * User stats shortcode
 */
function dollarbets_user_stats_shortcode($atts) {
    $atts = shortcode_atts([
        'user_id' => get_current_user_id(),
        'show_avatar' => 'true',
        'show_badges' => 'true',
        'compact' => 'false'
    ], $atts);
    
    if (!$atts['user_id']) {
        return '<p>Please log in to view your stats.</p>';
    }
    
    $user = get_user_by('ID', $atts['user_id']);
    if (!$user) {
        return '<p>User not found.</p>';
    }
    
    $leaderboard = new DollarBets_Leaderboard();
    $stats = $leaderboard->get_user_statistics($atts['user_id']);
    $badges = $leaderboard->get_user_badges($atts['user_id'], $stats);
    
    ob_start();
    ?>
    <div class="dollarbets-user-stats <?php echo $atts['compact'] === 'true' ? 'compact' : ''; ?>">
        <?php if ($atts['show_avatar'] === 'true'): ?>
        <div class="user-header">
            <img src="<?php echo esc_url($leaderboard->get_user_avatar($atts['user_id'])); ?>" 
                 alt="<?php echo esc_attr($user->display_name); ?>" 
                 class="user-avatar">
            <div class="user-info">
                <h4><?php echo esc_html($user->display_name); ?></h4>
                <?php if ($atts['show_badges'] === 'true' && !empty($badges)): ?>
                <div class="user-badges">
                    <?php foreach ($badges as $badge): ?>
                        <span class="badge" style="background-color: <?php echo esc_attr($badge['color']); ?>">
                            <?php echo $badge['icon']; ?> <?php echo esc_html($badge['name']); ?>
                        </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo number_format($stats['current_points']); ?></div>
                <div class="stat-label">Current Points</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['total_bets']; ?></div>
                <div class="stat-label">Total Bets</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['wins']; ?>/<?php echo $stats['losses']; ?></div>
                <div class="stat-label">Wins/Losses</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['accuracy']; ?>%</div>
                <div class="stat-label">Accuracy</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-value <?php echo $stats['profit_loss'] >= 0 ? 'positive' : 'negative'; ?>">
                    <?php echo $stats['profit_loss'] >= 0 ? '+' : ''; ?><?php echo number_format($stats['profit_loss']); ?>
                </div>
                <div class="stat-label">Profit/Loss</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-value"><?php echo $stats['win_streak']; ?></div>
                <div class="stat-label">Best Streak</div>
            </div>
        </div>
    </div>
    
    <style>
    .dollarbets-user-stats {
        background: #fff;
        border-radius: 12px;
        padding: 20px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        max-width: 600px;
        margin: 0 auto;
    }
    
    .user-header {
        display: flex;
        align-items: center;
        margin-bottom: 20px;
        padding-bottom: 20px;
        border-bottom: 1px solid #eee;
    }
    
    .user-header .user-avatar {
        width: 60px;
        height: 60px;
        border-radius: 50%;
        margin-right: 15px;
        object-fit: cover;
    }
    
    .user-header h4 {
        margin: 0 0 8px 0;
        font-size: 20px;
        color: #333;
    }
    
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
        gap: 15px;
    }
    
    .stat-card {
        text-align: center;
        padding: 15px;
        background: #f8f9fa;
        border-radius: 8px;
        transition: transform 0.3s ease;
    }
    
    .stat-card:hover {
        transform: translateY(-2px);
    }
    
    .stat-value {
        font-size: 24px;
        font-weight: bold;
        color: #333;
        margin-bottom: 5px;
    }
    
    .stat-value.positive {
        color: #28a745;
    }
    
    .stat-value.negative {
        color: #dc3545;
    }
    
    .stat-label {
        font-size: 12px;
        color: #666;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .compact .stats-grid {
        grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
        gap: 10px;
    }
    
    .compact .stat-card {
        padding: 10px;
    }
    
    .compact .stat-value {
        font-size: 18px;
    }
    
    /* Dark mode */
    body.dark-mode .dollarbets-user-stats {
        background: #2c2c2c;
        color: #fff;
    }
    
    body.dark-mode .user-header {
        border-bottom-color: #555;
    }
    
    body.dark-mode .user-header h4 {
        color: #fff;
    }
    
    body.dark-mode .stat-card {
        background: #3c3c3c;
    }
    
    body.dark-mode .stat-value {
        color: #fff;
    }
    
    body.dark-mode .stat-label {
        color: #ccc;
    }
    </style>
    <?php
    return ob_get_clean();
}
add_shortcode('dollarbets_user_stats', 'dollarbets_user_stats_shortcode');


<?php
if (!defined('ABSPATH')) exit;

/**
 * Prediction Resolution System for DollarBets Platform
 * Handles automatic resolution of predictions based on closing dates
 */

class DollarBets_Prediction_Resolution {
    
    public function __construct() {
        // Hook into WordPress cron system
        add_action('init', [$this, 'schedule_resolution_check']);
        add_action('dollarbets_check_predictions', [$this, 'check_and_resolve_predictions']);
        
        // Add admin interface for manual resolution
        add_action('add_meta_boxes', [$this, 'add_resolution_meta_box']);
        add_action('save_post', [$this, 'handle_manual_resolution']);
        
        // Add REST API endpoints
        add_action('rest_api_init', [$this, 'register_resolution_routes']);
    }
    
    /**
     * Schedule the prediction resolution check
     */
    public function schedule_resolution_check() {
        if (!wp_next_scheduled('dollarbets_check_predictions')) {
            wp_schedule_event(time(), 'hourly', 'dollarbets_check_predictions');
        }
    }
    
    /**
     * Register REST API routes for prediction resolution
     */
    public function register_resolution_routes() {
        register_rest_route('dollarbets/v1', '/resolve-prediction/(?P<id>\d+)', [
            'methods' => 'POST',
            'callback' => [$this, 'resolve_prediction_api'],
            'permission_callback' => function() {
                return function_exists('current_user_can') ? current_user_can('manage_options') : false;
            }
        ]);
        
        register_rest_route('dollarbets/v1', '/prediction-stats/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_prediction_stats'],
            'permission_callback' => '__return_true'
        ]);
    }
    
    /**
     * Check and resolve predictions that have passed their closing date
     */
    public function check_and_resolve_predictions() {
        $predictions = get_posts([
            'post_type' => 'prediction',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => '_prediction_resolved',
                    'compare' => 'NOT EXISTS'
                ]
            ]
        ]);
        
        foreach ($predictions as $prediction) {
            $closing_date = get_post_meta($prediction->ID, '_prediction_ending_date', true);
            
            if ($closing_date && strtotime($closing_date) < current_time('timestamp')) {
                $this->auto_resolve_prediction($prediction->ID);
            }
        }
    }
    
    /**
     * Automatically resolve a prediction based on highest betting volume
     */
    public function auto_resolve_prediction($prediction_id) {
        $stats = $this->get_prediction_betting_stats($prediction_id);
        
        if ($stats['total_bets'] == 0) {
            // No bets placed, mark as resolved with no winner
            update_post_meta($prediction_id, '_prediction_resolved', 'no_bets');
            update_post_meta($prediction_id, '_resolution_method', 'auto_no_bets');
            update_post_meta($prediction_id, '_resolution_date', current_time('mysql'));
            return;
        }
        
        // Determine winner based on highest betting volume
        $winning_choice = $stats['yes_total'] > $stats['no_total'] ? 'yes' : 'no';
        
        // If it's a tie, randomly select winner
        if ($stats['yes_total'] == $stats['no_total']) {
            $winning_choice = rand(0, 1) ? 'yes' : 'no';
        }
        
        $this->resolve_prediction($prediction_id, $winning_choice, 'auto_volume');
    }
    
    /**
     * Resolve a prediction and award winnings
     */
    public function resolve_prediction($prediction_id, $winning_choice, $method = 'manual') {
        if (!in_array($winning_choice, ['yes', 'no'], true)) {
            return new WP_Error('invalid_choice', 'Invalid winning choice');
        }
        
        // Check if already resolved
        if (get_post_meta($prediction_id, '_prediction_resolved', true)) {
            return new WP_Error('already_resolved', 'Prediction already resolved');
        }
        
        $stats = $this->get_prediction_betting_stats($prediction_id);
        $total_pool = $stats['yes_total'] + $stats['no_total'];
        $winning_pool = $winning_choice === 'yes' ? $stats['yes_total'] : $stats['no_total'];
        $losing_pool = $winning_choice === 'yes' ? $stats['no_total'] : $stats['yes_total'];
        
        // Award winnings to winners
        $winners_awarded = 0;
        $total_winnings = 0;
        
        if ($winning_pool > 0) {
            $users = get_users(['meta_key' => 'db_bet_history']);
            
            foreach ($users as $user) {
                $history = get_user_meta($user->ID, 'db_bet_history', true);
                if (!is_array($history)) continue;
                
                foreach ($history as $bet) {
                    if ($bet['prediction_id'] == $prediction_id && $bet['choice'] == $winning_choice) {
                        // Calculate winnings: original bet + proportional share of losing pool
                        $bet_amount = $bet['amount'];
                        $bonus_share = ($losing_pool * $bet_amount) / $winning_pool;
                        $total_winnings_for_bet = $bet_amount + $bonus_share;
                        
                        // Award the winnings
                        if (function_exists('db_award_points_manual')) {
                            db_award_points_manual($user->ID, $total_winnings_for_bet, 'betcoins', 'DollarBets winnings');
                        }
                        
                        $winners_awarded++;
                        $total_winnings += $total_winnings_for_bet;
                    }
                }
            }
        }
        
        // Mark prediction as resolved
        update_post_meta($prediction_id, '_prediction_resolved', $winning_choice);
        update_post_meta($prediction_id, '_resolution_method', $method);
        update_post_meta($prediction_id, '_resolution_date', current_time('mysql'));
        update_post_meta($prediction_id, '_winners_count', $winners_awarded);
        update_post_meta($prediction_id, '_total_winnings_awarded', $total_winnings);
        
        // Fire action for other plugins/themes
        do_action('dollarbets_prediction_resolved', $prediction_id, $winning_choice);
        
        return [
            'success' => true,
            'winning_choice' => $winning_choice,
            'winners_awarded' => $winners_awarded,
            'total_winnings' => $total_winnings,
            'method' => $method
        ];
    }
    
    /**
     * Get betting statistics for a prediction
     */
    public function get_prediction_betting_stats($prediction_id) {
        $stats = [
            'yes_total' => 0,
            'no_total' => 0,
            'yes_bets' => 0,
            'no_bets' => 0,
            'total_bets' => 0,
            'unique_bettors' => 0,
            'bettors' => []
        ];
        
        $users = get_users(['meta_key' => 'db_bet_history']);
        $unique_bettors = [];
        
        foreach ($users as $user) {
            $history = get_user_meta($user->ID, 'db_bet_history', true);
            if (!is_array($history)) continue;
            
            $user_has_bet = false;
            
            foreach ($history as $bet) {
                if ($bet['prediction_id'] == $prediction_id) {
                    if ($bet['choice'] == 'yes') {
                        $stats['yes_total'] += $bet['amount'];
                        $stats['yes_bets']++;
                    } else {
                        $stats['no_total'] += $bet['amount'];
                        $stats['no_bets']++;
                    }
                    
                    $stats['total_bets']++;
                    
                    if (!$user_has_bet) {
                        $unique_bettors[] = $user->ID;
                        $user_has_bet = true;
                    }
                    
                    $stats['bettors'][] = [
                        'user_id' => $user->ID,
                        'username' => $user->display_name,
                        'choice' => $bet['choice'],
                        'amount' => $bet['amount'],
                        'timestamp' => $bet['timestamp']
                    ];
                }
            }
        }
        
        $stats['unique_bettors'] = count($unique_bettors);
        
        return $stats;
    }
    
    /**
     * Add meta box for manual resolution in admin
     */
    public function add_resolution_meta_box() {
        add_meta_box(
            'prediction_resolution',
            'Prediction Resolution',
            [$this, 'render_resolution_meta_box'],
            'prediction',
            'side',
            'high'
        );
    }
    
    /**
     * Render the resolution meta box
     */
    public function render_resolution_meta_box($post) {
        $resolved = get_post_meta($post->ID, '_prediction_resolved', true);
        $resolution_method = get_post_meta($post->ID, '_resolution_method', true);
        $resolution_date = get_post_meta($post->ID, '_resolution_date', true);
        $winners_count = get_post_meta($post->ID, '_winners_count', true);
        $total_winnings = get_post_meta($post->ID, '_total_winnings_awarded', true);
        $closing_date = get_post_meta($post->ID, '_prediction_ending_date', true);
        
        $stats = $this->get_prediction_betting_stats($post->ID);
        
        wp_nonce_field('prediction_resolution_nonce', 'prediction_resolution_nonce');
        
        echo '<div style="margin-bottom: 15px;">';
        echo '<h4>Betting Statistics</h4>';
        echo '<p><strong>Total Bets:</strong> ' . $stats['total_bets'] . '</p>';
        echo '<p><strong>Unique Bettors:</strong> ' . $stats['unique_bettors'] . '</p>';
        echo '<p><strong>YES Votes:</strong> ' . number_format($stats['yes_total']) . ' BetCoins (' . $stats['yes_bets'] . ' bets)</p>';
        echo '<p><strong>NO Votes:</strong> ' . number_format($stats['no_total']) . ' BetCoins (' . $stats['no_bets'] . ' bets)</p>';
        echo '</div>';
        
        if ($closing_date) {
            $is_closed = strtotime($closing_date) < current_time('timestamp');
            echo '<p><strong>Closing Date:</strong> ' . $closing_date . '</p>';
            echo '<p><strong>Status:</strong> ' . ($is_closed ? '<span style="color: red;">CLOSED</span>' : '<span style="color: green;">OPEN</span>') . '</p>';
        }
        
        if ($resolved) {
            echo '<div style="background: #d4edda; padding: 10px; border-radius: 4px; margin-bottom: 15px;">';
            echo '<h4 style="margin: 0 0 10px 0; color: #155724;">✅ RESOLVED</h4>';
            echo '<p><strong>Winner:</strong> ' . strtoupper($resolved) . '</p>';
            echo '<p><strong>Method:</strong> ' . ucfirst(str_replace('_', ' ', $resolution_method)) . '</p>';
            echo '<p><strong>Date:</strong> ' . $resolution_date . '</p>';
            if ($winners_count) {
                echo '<p><strong>Winners:</strong> ' . $winners_count . ' users</p>';
            }
            if ($total_winnings) {
                echo '<p><strong>Total Winnings:</strong> ' . number_format($total_winnings) . ' BetCoins</p>';
            }
            echo '</div>';
        } else {
            echo '<div style="background: #fff3cd; padding: 10px; border-radius: 4px; margin-bottom: 15px;">';
            echo '<h4 style="margin: 0 0 10px 0; color: #856404;">⏳ PENDING</h4>';
            echo '<p>This prediction has not been resolved yet.</p>';
            echo '</div>';
            
            if ($stats['total_bets'] > 0) {
                echo '<h4>Manual Resolution</h4>';
                echo '<p>Select the winning choice:</p>';
                echo '<label><input type="radio" name="manual_resolution" value="yes"> YES</label><br>';
                echo '<label><input type="radio" name="manual_resolution" value="no"> NO</label><br>';
                echo '<label><input type="radio" name="manual_resolution" value="auto"> Auto-resolve (highest volume wins)</label><br>';
                echo '<p style="font-size: 12px; color: #666;">Note: This will award winnings to all users who bet on the winning choice.</p>';
            } else {
                echo '<p style="color: #666;">No bets placed on this prediction yet.</p>';
            }
        }
    }
    
    /**
     * Handle manual resolution from admin
     */
    public function handle_manual_resolution($post_id) {
        if (!isset($_POST['prediction_resolution_nonce']) || 
            !wp_verify_nonce($_POST['prediction_resolution_nonce'], 'prediction_resolution_nonce')) {
            return;
        }
        
        if (get_post_type($post_id) !== 'prediction') {
            return;
        }
        
        if (!function_exists('current_user_can') || !current_user_can('edit_post', $post_id)) {
            return;
        }
        
        if (isset($_POST['manual_resolution']) && !empty($_POST['manual_resolution'])) {
            $choice = sanitize_text_field($_POST['manual_resolution']);
            
            if ($choice === 'auto') {
                $this->auto_resolve_prediction($post_id);
            } elseif (in_array($choice, ['yes', 'no'])) {
                $this->resolve_prediction($post_id, $choice, 'manual');
            }
        }
    }
    
    /**
     * REST API endpoint for resolving predictions
     */
    public function resolve_prediction_api(WP_REST_Request $request) {
        $prediction_id = absint($request['id']);
        $body = $request->get_json_params();
        $choice = sanitize_text_field($body['choice'] ?? '');
        
        if (!$prediction_id) {
            return new WP_Error('invalid_id', 'Invalid prediction ID', ['status' => 400]);
        }
        
        if ($choice === 'auto') {
            $this->auto_resolve_prediction($prediction_id);
            $result = ['success' => true, 'method' => 'auto'];
        } elseif (in_array($choice, ['yes', 'no'])) {
            $result = $this->resolve_prediction($prediction_id, $choice, 'api');
        } else {
            return new WP_Error('invalid_choice', 'Invalid choice', ['status' => 400]);
        }
        
        return rest_ensure_response($result);
    }
    
    /**
     * REST API endpoint for getting prediction stats
     */
    public function get_prediction_stats(WP_REST_Request $request) {
        $prediction_id = absint($request['id']);
        
        if (!$prediction_id) {
            return new WP_Error('invalid_id', 'Invalid prediction ID', ['status' => 400]);
        }
        
        $stats = $this->get_prediction_betting_stats($prediction_id);
        $resolved = get_post_meta($prediction_id, '_prediction_resolved', true);
        $closing_date = get_post_meta($prediction_id, '_prediction_ending_date', true);
        
        return rest_ensure_response([
            'success' => true,
            'stats' => $stats,
            'resolved' => $resolved,
            'closing_date' => $closing_date,
            'is_closed' => $closing_date ? strtotime($closing_date) < current_time('timestamp') : false
        ]);
    }
}

// Initialize the prediction resolution system
new DollarBets_Prediction_Resolution();

/**
 * Helper function to manually resolve a prediction (for use in other parts of the plugin)
 */
function dollarbets_resolve_prediction($prediction_id, $winning_choice) {
    $resolver = new DollarBets_Prediction_Resolution();
    return $resolver->resolve_prediction($prediction_id, $winning_choice, 'manual');
}

/**
 * Helper function to get prediction stats (for use in other parts of the plugin)
 */
function dollarbets_get_prediction_stats($prediction_id) {
    $resolver = new DollarBets_Prediction_Resolution();
    return $resolver->get_prediction_betting_stats($prediction_id);
}


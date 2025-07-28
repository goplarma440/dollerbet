add_shortcode('db_bet_history', function() {
    if ( !is_user_logged_in() ) return '<p>Please log in to view your bet history.</p>';

    $user_id = get_current_user_id();
    $history = get_user_meta($user_id, 'db_bet_history', true);
    if ( empty($history) ) return '<p>No bets placed yet.</p>';

    $output = '<table><thead><tr><th>Prediction ID</th><th>Choice</th><th>Amount</th><th>Balance</th><th>Date</th></tr></thead><tbody>';
    foreach ($history as $bet) {
        $output .= sprintf(
            '<tr><td>%d</td><td>%s</td><td>%d</td><td>%d</td><td>%s</td></tr>',
            esc_html($bet['prediction_id']),
            esc_html($bet['choice']),
            esc_html($bet['amount']),
            esc_html($bet['remaining']),
            esc_html($bet['timestamp'])
        );
    }
    $output .= '</tbody></table>';

    return $output;
});

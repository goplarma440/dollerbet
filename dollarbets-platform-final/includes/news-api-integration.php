<?php
if (!defined('ABSPATH')) exit;

/**
 * News API Integration for DollarBets Platform
 * Fetches trending news and creates draft predictions
 */

class DollarBets_News_API {
    
    private $api_key;
    private $api_endpoint = 'https://newsapi.org/v2/top-headlines';
    
    public function __construct() {
        // Add admin menu
        add_action('admin_menu', [$this, 'add_admin_menu']);
        
        // Add AJAX handlers
        add_action('wp_ajax_dollarbets_fetch_news', [$this, 'fetch_news_ajax']);
        add_action('wp_ajax_dollarbets_save_news_settings', [$this, 'save_news_settings']);
        
        // Add cron job for automatic news fetching
        add_action('dollarbets_fetch_news_cron', [$this, 'fetch_and_create_predictions']);
        
        // Schedule cron if not already scheduled
        if (!wp_next_scheduled('dollarbets_fetch_news_cron')) {
            wp_schedule_event(time(), 'hourly', 'dollarbets_fetch_news_cron');
        }
        
        $this->api_key = get_option('dollarbets_news_api_key', '');
    }
    
    /**
     * Add admin menu for News API settings
     */
    public function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=prediction',
            'News API Settings',
            'News API',
            'manage_options',
            'dollarbets-news-api',
            [$this, 'render_admin_page']
        );
    }
    
    /**
     * Render admin page for News API settings
     */
    public function render_admin_page() {
        $api_key = get_option('dollarbets_news_api_key', '');
        $auto_fetch = get_option('dollarbets_news_auto_fetch', 'no');
        $categories = get_option('dollarbets_news_categories', ['general', 'business', 'technology']);
        $country = get_option('dollarbets_news_country', 'us');
        $last_fetch = get_option('dollarbets_news_last_fetch', 'Never');
        
        ?>
        <div class="wrap">
            <h1>News API Settings</h1>
            
            <div class="notice notice-info">
                <p><strong>News API Integration:</strong> This feature fetches trending news articles and automatically creates draft predictions for betting. You need a free API key from <a href="https://newsapi.org" target="_blank">NewsAPI.org</a>.</p>
            </div>
            
            <form method="post" action="" id="news-api-form">
                <?php wp_nonce_field('dollarbets_news_settings', 'dollarbets_news_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="news_api_key">News API Key</label>
                        </th>
                        <td>
                            <input type="text" id="news_api_key" name="news_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" placeholder="Enter your NewsAPI.org API key">
                            <p class="description">Get your free API key from <a href="https://newsapi.org/register" target="_blank">NewsAPI.org</a></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="news_country">Country</label>
                        </th>
                        <td>
                            <select id="news_country" name="news_country">
                                <option value="us" <?php selected($country, 'us'); ?>>United States</option>
                                <option value="gb" <?php selected($country, 'gb'); ?>>United Kingdom</option>
                                <option value="ca" <?php selected($country, 'ca'); ?>>Canada</option>
                                <option value="au" <?php selected($country, 'au'); ?>>Australia</option>
                                <option value="de" <?php selected($country, 'de'); ?>>Germany</option>
                                <option value="fr" <?php selected($country, 'fr'); ?>>France</option>
                                <option value="jp" <?php selected($country, 'jp'); ?>>Japan</option>
                                <option value="in" <?php selected($country, 'in'); ?>>India</option>
                            </select>
                            <p class="description">Select the country for news sources</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Categories</th>
                        <td>
                            <?php
                            $available_categories = [
                                'general' => 'General',
                                'business' => 'Business',
                                'entertainment' => 'Entertainment',
                                'health' => 'Health',
                                'science' => 'Science',
                                'sports' => 'Sports',
                                'technology' => 'Technology'
                            ];
                            
                            foreach ($available_categories as $key => $label) {
                                $checked = in_array($key, $categories) ? 'checked' : '';
                                echo "<label><input type='checkbox' name='news_categories[]' value='{$key}' {$checked}> {$label}</label><br>";
                            }
                            ?>
                            <p class="description">Select categories to fetch news from</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="auto_fetch">Auto Fetch</label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" id="auto_fetch" name="auto_fetch" value="yes" <?php checked($auto_fetch, 'yes'); ?>>
                                Automatically fetch news every hour and create draft predictions
                            </label>
                        </td>
                    </tr>
                </table>
                
                <div class="news-api-actions">
                    <p class="submit">
                        <input type="submit" name="save_settings" class="button-primary" value="Save Settings">
                        <button type="button" id="test-api" class="button">Test API Connection</button>
                        <button type="button" id="fetch-news-now" class="button button-secondary">Fetch News Now</button>
                    </p>
                </div>
            </form>
            
            <div class="news-api-status">
                <h3>Status</h3>
                <p><strong>Last Fetch:</strong> <?php echo esc_html($last_fetch); ?></p>
                <p><strong>API Status:</strong> <span id="api-status">Unknown</span></p>
                <p><strong>Total Predictions Created:</strong> <?php echo $this->get_news_predictions_count(); ?></p>
            </div>
            
            <div id="news-results" style="display: none;">
                <h3>Latest News Articles</h3>
                <div id="news-articles-list"></div>
            </div>
        </div>
        
        <style>
        .news-api-actions {
            margin: 20px 0;
        }
        
        .news-api-status {
            background: #f1f1f1;
            padding: 15px;
            border-radius: 5px;
            margin-top: 20px;
        }
        
        .news-article {
            border: 1px solid #ddd;
            padding: 15px;
            margin: 10px 0;
            border-radius: 5px;
            background: #fff;
        }
        
        .news-article h4 {
            margin: 0 0 10px 0;
            color: #333;
        }
        
        .news-article .source {
            color: #666;
            font-size: 12px;
            margin-bottom: 5px;
        }
        
        .news-article .description {
            margin: 10px 0;
        }
        
        .news-article .actions {
            margin-top: 10px;
        }
        
        .news-article .actions button {
            margin-right: 10px;
        }
        
        #api-status.connected {
            color: #46b450;
            font-weight: bold;
        }
        
        #api-status.error {
            color: #dc3232;
            font-weight: bold;
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // Test API connection
            $('#test-api').click(function() {
                const apiKey = $('#news_api_key').val();
                if (!apiKey) {
                    alert('Please enter an API key first.');
                    return;
                }
                
                $(this).prop('disabled', true).text('Testing...');
                
                $.post(ajaxurl, {
                    action: 'dollarbets_test_news_api',
                    api_key: apiKey,
                    nonce: '<?php echo wp_create_nonce('dollarbets_news_test'); ?>'
                }, function(response) {
                    if (response.success) {
                        $('#api-status').text('Connected').addClass('connected').removeClass('error');
                        alert('API connection successful!');
                    } else {
                        $('#api-status').text('Error: ' + response.data).addClass('error').removeClass('connected');
                        alert('API connection failed: ' + response.data);
                    }
                }).always(function() {
                    $('#test-api').prop('disabled', false).text('Test API Connection');
                });
            });
            
            // Fetch news now
            $('#fetch-news-now').click(function() {
                const apiKey = $('#news_api_key').val();
                if (!apiKey) {
                    alert('Please enter and save an API key first.');
                    return;
                }
                
                $(this).prop('disabled', true).text('Fetching...');
                
                $.post(ajaxurl, {
                    action: 'dollarbets_fetch_news',
                    nonce: '<?php echo wp_create_nonce('dollarbets_fetch_news'); ?>'
                }, function(response) {
                    if (response.success) {
                        $('#news-results').show();
                        $('#news-articles-list').html(response.data.html);
                        alert('Successfully fetched ' + response.data.count + ' news articles and created draft predictions!');
                        location.reload(); // Refresh to show updated status
                    } else {
                        alert('Failed to fetch news: ' + response.data);
                    }
                }).always(function() {
                    $('#fetch-news-now').prop('disabled', false).text('Fetch News Now');
                });
            });
            
            // Save settings
            $('#news-api-form').submit(function(e) {
                e.preventDefault();
                
                const formData = $(this).serialize();
                
                $.post(ajaxurl, {
                    action: 'dollarbets_save_news_settings',
                    form_data: formData,
                    nonce: '<?php echo wp_create_nonce('dollarbets_save_news'); ?>'
                }, function(response) {
                    if (response.success) {
                        alert('Settings saved successfully!');
                    } else {
                        alert('Failed to save settings: ' + response.data);
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Save news API settings
     */
    public function save_news_settings() {
        if (!wp_verify_nonce($_POST['nonce'], 'dollarbets_save_news') || !current_user_can('manage_options')) {
            wp_die('Security check failed');
        }
        
        parse_str($_POST['form_data'], $form_data);
        
        if (wp_verify_nonce($form_data['dollarbets_news_nonce'], 'dollarbets_news_settings')) {
            update_option('dollarbets_news_api_key', sanitize_text_field($form_data['news_api_key']));
            update_option('dollarbets_news_country', sanitize_text_field($form_data['news_country']));
            update_option('dollarbets_news_categories', array_map('sanitize_text_field', $form_data['news_categories'] ?? []));
            update_option('dollarbets_news_auto_fetch', isset($form_data['auto_fetch']) ? 'yes' : 'no');
            
            wp_send_json_success('Settings saved successfully');
        } else {
            wp_send_json_error('Invalid nonce');
        }
    }
    
    /**
     * AJAX handler for fetching news
     */
    public function fetch_news_ajax() {
        if (!wp_verify_nonce($_POST['nonce'], 'dollarbets_fetch_news') || !current_user_can('manage_options')) {
            wp_send_json_error('Security check failed');
        }
        
        $result = $this->fetch_and_create_predictions();
        
        if ($result['success']) {
            wp_send_json_success([
                'count' => $result['count'],
                'html' => $this->render_news_articles($result['articles'])
            ]);
        } else {
            wp_send_json_error($result['message']);
        }
    }
    
    /**
     * Fetch news and create draft predictions
     */
    public function fetch_and_create_predictions() {
        if (empty($this->api_key)) {
            error_log("DollarBets News API Error: API key not configured.");
          return ['success' => false, 'message' => 'API key not configured'];     }
        
        $categories = get_option('dollarbets_news_categories', ['general']);
        $country = get_option('dollarbets_news_country', 'us');
        $all_articles = [];
        
        foreach ($categories as $category) {
            $articles = $this->fetch_news_by_category($category, $country);
            if ($articles) {
                $all_articles = array_merge($all_articles, $articles);
            }
        }
        
        if (empty($all_articles)) {
            return ['success' => false, 'message' => 'No articles fetched'];
        }
        
        // Limit to 30 articles and remove duplicates
        $all_articles = array_slice(array_unique($all_articles, SORT_REGULAR), 0, 30);
        
        $created_count = 0;
        foreach ($all_articles as $article) {
            if ($this->create_prediction_from_article($article)) {
                $created_count++;
            }
        }
        
        update_option('dollarbets_news_last_fetch', current_time('mysql'));
        
        return [
            'success' => true,
            'count' => $created_count,
            'articles' => $all_articles
        ];
    }
    
    /**
     * Fetch news by category
     */
    private function fetch_news_by_category($category, $country) {
        $url = add_query_arg([
            'apiKey' => $this->api_key,
            'category' => $category,
            'country' => $country,
            'pageSize' => 10
        ], $this->api_endpoint);
        
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'headers' => [
                'User-Agent' => 'DollarBets-WordPress-Plugin/1.0'
            ]
        ]);
        
        if (is_wp_error($response)) {
            error_log("DollarBets News API Error (wp_remote_get): " . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("DollarBets News API Error: JSON decoding failed. Response body: " . $body);
            return false;
        }
        
       if ($data["status"] !== "ok") {
            error_log("DollarBets News API Error: " . ($data["message"] ?? "Unknown error") . ". API Key: " . $this->api_key);
            return false;
        }
        
        return $data['articles'] ?? [];
    }
    
    /**
     * Create prediction from news article
     */
    private function create_prediction_from_article($article) {
        // Check if prediction already exists for this article
        $existing = get_posts([
            'post_type' => 'prediction',
            'meta_query' => [
                [
                    'key' => '_news_article_url',
                    'value' => $article['url'],
                    'compare' => '='
                ]
            ],
            'post_status' => 'any'
        ]);
        
        if (!empty($existing)) {
            return false; // Already exists
        }
        
        // Generate prediction title and content
        $title = $this->generate_prediction_title($article['title']);
        $content = $this->generate_prediction_content($article);
        
        // Create the prediction post
        $post_id = wp_insert_post([
            'post_type' => 'prediction',
            'post_status' => 'draft',
            'post_title' => $title,
            'post_content' => $content,
            'post_author' => 1 // Admin user
        ]);
        
        if (is_wp_error($post_id)) {
            return false;
        }
        
        // Add metadata
        update_post_meta($post_id, '_news_article_url', $article['url']);
        update_post_meta($post_id, '_news_article_source', $article['source']['name']);
        update_post_meta($post_id, '_news_article_published', $article['publishedAt']);
        update_post_meta($post_id, '_prediction_ending_date', date('Y-m-d H:i:s', strtotime('+7 days')));
        update_post_meta($post_id, '_prediction_type', 'news_generated');
        
        // Set category based on news category
        $category_mapping = [
            'business' => 'Business',
            'entertainment' => 'Entertainment',
            'health' => 'Health',
            'science' => 'Science',
            'sports' => 'Sports',
            'technology' => 'Technology',
            'general' => 'General'
        ];
        
        $news_category = get_option('dollarbets_news_categories', ['general'])[0];
        $prediction_category = $category_mapping[$news_category] ?? 'General';
        
        // Create or get the category term
        $term = wp_insert_term($prediction_category, 'prediction_category');
        if (!is_wp_error($term)) {
            wp_set_object_terms($post_id, $term['term_id'], 'prediction_category');
        }
        
        return true;
    }
    
    /**
     * Generate prediction title from news title
     */
    private function generate_prediction_title($news_title) {
        // Convert news title to a prediction question
        $title = trim($news_title);
        
        // Remove common news prefixes
        $title = preg_replace('/^(Breaking:|BREAKING:|News:|NEWS:)\s*/i', '', $title);
        
        // Add prediction context
        if (stripos($title, 'will') === false && stripos($title, '?') === false) {
            // Convert statement to question
            $prediction_starters = [
                'Will this news story develop further?',
                'Will this trend continue?',
                'Will this event have lasting impact?',
                'Will this situation improve?',
                'Will this announcement be successful?'
            ];
            
            $starter = $prediction_starters[array_rand($prediction_starters)];
            $title = $starter . ' - ' . $title;
        }
        
        return $title;
    }
    
    /**
     * Generate prediction content from article
     */
    private function generate_prediction_content($article) {
        $content = "<h3>News-Based Prediction</h3>\n\n";
        $content .= "<p><strong>Source:</strong> {$article['source']['name']}</p>\n";
        $content .= "<p><strong>Published:</strong> " . date('F j, Y g:i A', strtotime($article['publishedAt'])) . "</p>\n\n";
        
        if (!empty($article['description'])) {
            $content .= "<p><strong>Summary:</strong> {$article['description']}</p>\n\n";
        }
        
        $content .= "<p><strong>What do you think will happen?</strong></p>\n";
        $content .= "<p>Vote YES if you believe this story will have a positive outcome or continue to develop.</p>\n";
        $content .= "<p>Vote NO if you believe this story will not develop further or have a negative outcome.</p>\n\n";
        
        $content .= "<p><a href=\"{$article['url']}\" target=\"_blank\" rel=\"noopener\">Read the full article</a></p>\n";
        
        return $content;
    }
    
    /**
     * Render news articles HTML
     */
    private function render_news_articles($articles) {
        $html = '';
        
        foreach ($articles as $article) {
            $html .= '<div class="news-article">';
            $html .= '<div class="source">' . esc_html($article['source']['name']) . ' - ' . date('M j, Y', strtotime($article['publishedAt'])) . '</div>';
            $html .= '<h4>' . esc_html($article['title']) . '</h4>';
            
            if (!empty($article['description'])) {
                $html .= '<div class="description">' . esc_html($article['description']) . '</div>';
            }
            
            $html .= '<div class="actions">';
            $html .= '<a href="' . esc_url($article['url']) . '" target="_blank" class="button button-small">View Article</a>';
            $html .= '</div>';
            $html .= '</div>';
        }
        
        return $html;
    }
    
    /**
     * Get count of news-generated predictions
     */
    private function get_news_predictions_count() {
        $count = get_posts([
            'post_type' => 'prediction',
            'meta_query' => [
                [
                    'key' => '_prediction_type',
                    'value' => 'news_generated',
                    'compare' => '='
                ]
            ],
            'post_status' => 'any',
            'numberposts' => -1,
            'fields' => 'ids'
        ]);
        
        return count($count);
    }
}

// Initialize News API integration
new DollarBets_News_API();

// Add test API AJAX handler
add_action('wp_ajax_dollarbets_test_news_api', function() {
    if (!wp_verify_nonce($_POST['nonce'], 'dollarbets_news_test') || !current_user_can('manage_options')) {
        wp_send_json_error('Security check failed');
    }
    
    $api_key = sanitize_text_field($_POST['api_key']);
    
    $url = add_query_arg([
        'apiKey' => $api_key,
        'category' => 'general',
        'country' => 'us',
        'pageSize' => 1
    ], 'https://newsapi.org/v2/top-headlines');
    
    $response = wp_remote_get($url, ['timeout' => 15]);
    
    if (is_wp_error($response)) {
        wp_send_json_error($response->get_error_message());
    }
    
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    
    if ($data['status'] === 'ok') {
        wp_send_json_success('API connection successful');
    } else {
        wp_send_json_error($data['message'] ?? 'Unknown API error');
    }
});


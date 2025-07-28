<?php
if (!defined('ABSPATH')) exit;

/**
 * Elementor Compatibility for DollarBets Platform
 * Fixes shortcode editing issues in Elementor
 */

class DollarBets_Elementor_Compatibility {
    
    public function __construct() {
        add_action('elementor/editor/before_enqueue_scripts', [$this, 'enqueue_editor_scripts']);
        add_action('elementor/preview/enqueue_styles', [$this, 'enqueue_preview_styles']);
        add_action('wp_ajax_dollarbets_elementor_refresh', [$this, 'handle_elementor_refresh']);
        add_action('wp_ajax_nopriv_dollarbets_elementor_refresh', [$this, 'handle_elementor_refresh']);
        
        // Fix shortcode rendering in Elementor editor
        add_filter('elementor/widget/render_content', [$this, 'fix_shortcode_rendering'], 10, 2);
        
        // Prevent shortcode execution during Elementor editing
        add_action('elementor/editor/init', [$this, 'disable_shortcode_execution']);
        add_action('elementor/preview/init', [$this, 'enable_shortcode_execution']);
    }
    
    /**
     * Enqueue scripts for Elementor editor
     */
    public function enqueue_editor_scripts() {
        wp_enqueue_script(
            'dollarbets-elementor-editor',
            plugin_dir_url(__FILE__) . '../assets/js/elementor-editor.js',
            ['elementor-editor'],
            '1.0.0',
            true
        );
        
        wp_localize_script('dollarbets-elementor-editor', 'dollarBetsElementor', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dollarbets_elementor_nonce'),
            'shortcodes' => $this->get_dollarbets_shortcodes()
        ]);
    }
    
    /**
     * Enqueue styles for Elementor preview
     */
    public function enqueue_preview_styles() {
        wp_enqueue_style(
            'dollarbets-elementor-preview',
            plugin_dir_url(__FILE__) . '../assets/css/elementor-preview.css',
            [],
            '1.0.0'
        );
    }
    
    /**
     * Get list of DollarBets shortcodes
     */
    private function get_dollarbets_shortcodes() {
        return [
            'dollarbets_purchase',
            'dollarbets_purchase_button',
            'dollarbets_header',
            'dollarbets_simple_header',
            'dollarbets_predictions',
            'db_bet_history'
        ];
    }
    
    /**
     * Fix shortcode rendering in Elementor editor
     */
    public function fix_shortcode_rendering($content, $widget) {
        if ($widget->get_name() === 'shortcode') {
            $settings = $widget->get_settings_for_display();
            $shortcode = $settings['shortcode'] ?? '';
            
            // Check if it's a DollarBets shortcode
            if ($this->is_dollarbets_shortcode($shortcode)) {
                // Add wrapper for better editing experience
                $content = '<div class="dollarbets-shortcode-wrapper" data-shortcode="' . esc_attr($shortcode) . '">' . $content . '</div>';
                
                // Add edit button in Elementor editor
                if (\Elementor\Plugin::$instance->editor->is_edit_mode()) {
                    $content .= '<div class="dollarbets-shortcode-edit-overlay">
                        <button class="dollarbets-edit-shortcode" data-shortcode="' . esc_attr($shortcode) . '">
                            Edit Shortcode
                        </button>
                    </div>';
                }
            }
        }
        
        return $content;
    }
    
    /**
     * Check if shortcode is a DollarBets shortcode
     */
    private function is_dollarbets_shortcode($shortcode) {
        $dollarbets_shortcodes = $this->get_dollarbets_shortcodes();
        
        foreach ($dollarbets_shortcodes as $db_shortcode) {
            if (strpos($shortcode, $db_shortcode) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Disable shortcode execution during Elementor editing
     */
    public function disable_shortcode_execution() {
        // Store original shortcode functions
        global $shortcode_tags;
        $this->original_shortcodes = $shortcode_tags;
        
        // Replace DollarBets shortcodes with placeholders
        $dollarbets_shortcodes = $this->get_dollarbets_shortcodes();
        
        foreach ($dollarbets_shortcodes as $shortcode) {
            if (isset($shortcode_tags[$shortcode])) {
                add_shortcode($shortcode, [$this, 'shortcode_placeholder']);
            }
        }
    }
    
    /**
     * Enable shortcode execution for preview
     */
    public function enable_shortcode_execution() {
        // Restore original shortcode functions if available
        if (isset($this->original_shortcodes)) {
            global $shortcode_tags;
            $shortcode_tags = array_merge($shortcode_tags, $this->original_shortcodes);
        }
    }
    
    /**
     * Shortcode placeholder for editor
     */
    public function shortcode_placeholder($atts, $content = '', $tag = '') {
        $shortcode_display = '[' . $tag;
        
        if (!empty($atts)) {
            foreach ($atts as $key => $value) {
                $shortcode_display .= ' ' . $key . '="' . esc_attr($value) . '"';
            }
        }
        
        $shortcode_display .= ']';
        
        if (!empty($content)) {
            $shortcode_display .= $content . '[/' . $tag . ']';
        }
        
        return '<div class="dollarbets-shortcode-placeholder" data-shortcode="' . esc_attr($shortcode_display) . '">
            <div class="shortcode-icon">📊</div>
            <div class="shortcode-name">DollarBets: ' . ucfirst(str_replace(['dollarbets_', 'db_'], '', $tag)) . '</div>
            <div class="shortcode-code">' . esc_html($shortcode_display) . '</div>
            <div class="shortcode-note">This shortcode will render on the frontend</div>
        </div>';
    }
    
    /**
     * Handle AJAX refresh for Elementor
     */
    public function handle_elementor_refresh() {
        if (!wp_verify_nonce($_POST['nonce'], 'dollarbets_elementor_nonce')) {
            wp_die('Security check failed');
        }
        
        $shortcode = sanitize_text_field($_POST['shortcode']);
        
        if ($this->is_dollarbets_shortcode($shortcode)) {
            $output = do_shortcode($shortcode);
            wp_send_json_success(['content' => $output]);
        }
        
        wp_send_json_error('Invalid shortcode');
    }
}

// Initialize Elementor compatibility
if (did_action('elementor/loaded')) {
    new DollarBets_Elementor_Compatibility();
} else {
    add_action('elementor/loaded', function() {
        new DollarBets_Elementor_Compatibility();
    });
}

/**
 * Add Elementor widget category for DollarBets
 */
add_action('elementor/elements/categories_registered', function($elements_manager) {
    $elements_manager->add_category(
        'dollarbets',
        [
            'title' => 'DollarBets',
            'icon' => 'fa fa-dollar-sign',
        ]
    );
});

/**
 * Register custom Elementor widgets for better integration
 */
add_action('elementor/widgets/widgets_registered', function() {
});


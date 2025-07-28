<?php
// Exit if accessed directly
if (!defined('ABSPATH')) exit;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;

class DollarBets_Category_Widget extends Widget_Base {

    public function get_name() {
        return 'dollarbets_category_widget';
    }

    public function get_title() {
        return __('DollarBets Category Filter', 'dollarbets');
    }

    public function get_icon() {
        return 'eicon-filter';
    }

    public function get_categories() {
        return ['general'];
    }

    protected function register_controls() {
        $this->start_controls_section('content_section', [
            'label' => __('Content', 'dollarbets'),
            'tab' => Controls_Manager::TAB_CONTENT,
        ]);

        $this->add_control('title', [
            'label' => __('Widget Title', 'dollarbets'),
            'type' => Controls_Manager::TEXT,
            'default' => __('Filter Predictions', 'dollarbets'),
        ]);

        $this->end_controls_section();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        ?>
        <div class="dollarbets-widget">
            <h4><?php echo esc_html($settings['title']); ?></h4>
            <div class="category-filter">
                <?php foreach (['All', 'Football', 'Basketball', 'Elections'] as $cat): ?>
                    <button class="category-btn" data-category="<?php echo esc_attr($cat); ?>">
                        <?php echo esc_html($cat); ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }
}

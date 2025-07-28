/**
 * DollarBets Elementor Editor Compatibility
 */

(function($) {
    'use strict';
    
    // Wait for Elementor to be ready
    $(window).on('elementor:init', function() {
        
        // Add custom CSS for shortcode editing
        $('<style>')
            .prop('type', 'text/css')
            .html(`
                .dollarbets-shortcode-wrapper {
                    position: relative;
                    min-height: 50px;
                }
                
                .dollarbets-shortcode-edit-overlay {
                    position: absolute;
                    top: 0;
                    left: 0;
                    right: 0;
                    bottom: 0;
                    background: rgba(0, 0, 0, 0.1);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    opacity: 0;
                    transition: opacity 0.3s;
                    pointer-events: none;
                }
                
                .dollarbets-shortcode-wrapper:hover .dollarbets-shortcode-edit-overlay {
                    opacity: 1;
                    pointer-events: all;
                }
                
                .dollarbets-edit-shortcode {
                    background: #007cba;
                    color: white;
                    border: none;
                    padding: 8px 16px;
                    border-radius: 4px;
                    cursor: pointer;
                    font-size: 12px;
                }
                
                .dollarbets-edit-shortcode:hover {
                    background: #005a87;
                }
                
                .dollarbets-shortcode-placeholder {
                    background: #f8f9fa;
                    border: 2px dashed #007cba;
                    border-radius: 8px;
                    padding: 20px;
                    text-align: center;
                    margin: 10px 0;
                    min-height: 100px;
                    display: flex;
                    flex-direction: column;
                    justify-content: center;
                    align-items: center;
                }
                
                .shortcode-icon {
                    font-size: 24px;
                    margin-bottom: 8px;
                }
                
                .shortcode-name {
                    font-weight: bold;
                    color: #007cba;
                    margin-bottom: 4px;
                }
                
                .shortcode-code {
                    font-family: monospace;
                    background: #e9ecef;
                    padding: 4px 8px;
                    border-radius: 4px;
                    font-size: 12px;
                    margin-bottom: 4px;
                }
                
                .shortcode-note {
                    font-size: 11px;
                    color: #666;
                    font-style: italic;
                }
            `)
            .appendTo('head');
        
        // Handle shortcode edit button clicks
        $(document).on('click', '.dollarbets-edit-shortcode', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            var shortcode = $(this).data('shortcode');
            var widget = $(this).closest('.elementor-widget-shortcode');
            
            if (widget.length) {
                // Focus on the shortcode widget to open its settings
                widget.trigger('click');
                
                // Small delay to ensure the panel is open
                setTimeout(function() {
                    var shortcodeInput = $('#elementor-controls .elementor-control-shortcode textarea');
                    if (shortcodeInput.length) {
                        shortcodeInput.focus().select();
                    }
                }, 300);
            }
        });
        
        // Refresh shortcode content when settings change
        elementor.hooks.addAction('panel/open_editor/widget/shortcode', function(panel, model, view) {
            var shortcodeControl = panel.content.currentView.children.findByModel(
                panel.content.currentView.model.controls.shortcode
            );
            
            if (shortcodeControl) {
                shortcodeControl.on('input change', function() {
                    var shortcode = this.getControlValue();
                    
                    // Check if it's a DollarBets shortcode
                    if (isDollarBetsShortcode(shortcode)) {
                        refreshShortcodePreview(shortcode, view);
                    }
                });
            }
        });
        
        // Function to check if shortcode is DollarBets
        function isDollarBetsShortcode(shortcode) {
            var dollarBetsShortcodes = dollarBetsElementor.shortcodes || [];
            
            for (var i = 0; i < dollarBetsShortcodes.length; i++) {
                if (shortcode.indexOf(dollarBetsShortcodes[i]) !== -1) {
                    return true;
                }
            }
            
            return false;
        }
        
        // Function to refresh shortcode preview
        function refreshShortcodePreview(shortcode, view) {
            if (!shortcode || !view) return;
            
            $.ajax({
                url: dollarBetsElementor.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'dollarbets_elementor_refresh',
                    shortcode: shortcode,
                    nonce: dollarBetsElementor.nonce
                },
                success: function(response) {
                    if (response.success && response.data.content) {
                        var $element = view.$el.find('.elementor-shortcode');
                        if ($element.length) {
                            $element.html(response.data.content);
                        }
                    }
                },
                error: function() {
                    console.log('Failed to refresh shortcode preview');
                }
            });
        }
        
        // Improve shortcode widget behavior
        elementor.hooks.addAction('frontend/element_ready/shortcode.default', function($scope) {
            var $shortcode = $scope.find('.elementor-shortcode');
            
            if ($shortcode.length) {
                // Add loading state
                $shortcode.addClass('dollarbets-loading');
                
                // Remove loading state after content loads
                setTimeout(function() {
                    $shortcode.removeClass('dollarbets-loading');
                }, 1000);
                
                // Handle empty shortcodes
                if ($shortcode.is(':empty') || $shortcode.text().trim() === '') {
                    $shortcode.html('<div class="dollarbets-empty-shortcode">Shortcode content will appear here</div>');
                }
            }
        });
        
        // Add custom controls to shortcode widget
        elementor.hooks.addAction('panel/open_editor/widget/shortcode', function(panel, model, view) {
            // Add helper text for DollarBets shortcodes
            var $panel = panel.$el;
            var $shortcodeControl = $panel.find('.elementor-control-shortcode');
            
            if ($shortcodeControl.length && !$shortcodeControl.find('.dollarbets-shortcode-help').length) {
                var helpText = `
                    <div class="dollarbets-shortcode-help" style="margin-top: 10px; padding: 10px; background: #f0f8ff; border-left: 3px solid #007cba; font-size: 12px;">
                        <strong>DollarBets Shortcodes:</strong><br>
                        <code>[dollarbets_purchase]</code> - Purchase form<br>
                        <code>[dollarbets_header]</code> - Header with balance<br>
                        <code>[dollarbets_predictions]</code> - Predictions list<br>
                        <code>[db_bet_history]</code> - Bet history table
                    </div>
                `;
                
                $shortcodeControl.append(helpText);
            }
        });
    });
    
})(jQuery);


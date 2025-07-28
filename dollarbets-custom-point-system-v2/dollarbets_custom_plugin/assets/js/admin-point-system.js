jQuery(document).ready(function($) {
    
    // Point Adjustment Modal
    var pointModal = $('#point-adjustment-modal');
    var closeModal = $('.close');
    
    // Open point adjustment modal
    $(document).on('click', '.adjust-points', function() {
        var userId = $(this).data('user-id');
        $('#adjust-user-id').val(userId);
        pointModal.show();
    });
    
    // Close modal
    closeModal.on('click', function() {
        pointModal.hide();
    });
    
    // Close modal when clicking outside
    $(window).on('click', function(event) {
        if (event.target === pointModal[0]) {
            pointModal.hide();
        }
    });
    
    // Handle point adjustment form submission
    $('#point-adjustment-form').on('submit', function(e) {
        e.preventDefault();
        
        var formData = {
            action: 'dollarbets_admin_action',
            admin_action: 'adjust_points',
            nonce: dollarbetsAdmin.nonce,
            user_id: $('#adjust-user-id').val(),
            point_type: $('#point-type').val(),
            adjustment_type: $('#adjustment-type').val(),
            amount: $('#point-amount').val(),
            reason: $('#adjustment-reason').val()
        };
        
        // Show loading state
        $(this).addClass('loading');
        
        $.post(dollarbetsAdmin.ajaxUrl, formData, function(response) {
            if (response.success) {
                alert('Points adjusted successfully! New balance: ' + response.data.new_balance);
                pointModal.hide();
                location.reload(); // Refresh to show updated data
            } else {
                alert('Error: ' + response.data);
            }
        }).fail(function() {
            alert('Network error occurred. Please try again.');
        }).always(function() {
            $('#point-adjustment-form').removeClass('loading');
        });
    });
    
    // View user history
    $(document).on('click', '.view-history', function() {
        var userId = $(this).data('user-id');
        var url = 'admin.php?page=dollarbets-transactions&user_filter=' + userId;
        window.open(url, '_blank');
    });
    
    // Recalculate all ranks
    $('#recalculate-ranks').on('click', function() {
        if (!confirm('This will recalculate ranks for all users. This may take a while. Continue?')) {
            return;
        }
        
        var button = $(this);
        button.prop('disabled', true).text('Recalculating...');
        
        var data = {
            action: 'dollarbets_admin_action',
            admin_action: 'recalculate_ranks',
            nonce: dollarbetsAdmin.nonce
        };
        
        $.post(dollarbetsAdmin.ajaxUrl, data, function(response) {
            if (response.success) {
                alert('Ranks recalculated for ' + response.data.updated_users + ' users.');
                location.reload();
            } else {
                alert('Error: ' + response.data);
            }
        }).fail(function() {
            alert('Network error occurred. Please try again.');
        }).always(function() {
            button.prop('disabled', false).text('Recalculate All User Ranks');
        });
    });
    
    // Edit point type
    $(document).on('click', '.edit-point-type', function() {
        var id = $(this).data('id');
        // TODO: Implement edit functionality
        alert('Edit functionality will be implemented in a future update.');
    });
    
    // Delete point type
    $(document).on('click', '.delete-point-type', function() {
        var id = $(this).data('id');
        if (confirm('Are you sure you want to delete this point type? This action cannot be undone.')) {
            // TODO: Implement delete functionality
            alert('Delete functionality will be implemented in a future update.');
        }
    });
    
    // Edit earning rule
    $(document).on('click', '.edit-earning-rule', function() {
        var id = $(this).data('id');
        // TODO: Implement edit functionality
        alert('Edit functionality will be implemented in a future update.');
    });
    
    // Delete earning rule
    $(document).on('click', '.delete-earning-rule', function() {
        var id = $(this).data('id');
        if (confirm('Are you sure you want to delete this earning rule? This action cannot be undone.')) {
            // TODO: Implement delete functionality
            alert('Delete functionality will be implemented in a future update.');
        }
    });
    
    // Edit rank
    $(document).on('click', '.edit-rank', function() {
        var id = $(this).data('id');
        // TODO: Implement edit functionality
        alert('Edit functionality will be implemented in a future update.');
    });
    
    // Delete rank
    $(document).on('click', '.delete-rank', function() {
        var id = $(this).data('id');
        if (confirm('Are you sure you want to delete this rank? This action cannot be undone.')) {
            // TODO: Implement delete functionality
            alert('Delete functionality will be implemented in a future update.');
        }
    });
    
    // Edit achievement
    $(document).on('click', '.edit-achievement', function() {
        var id = $(this).data('id');
        // TODO: Implement edit functionality
        alert('Edit functionality will be implemented in a future update.');
    });
    
    // Delete achievement
    $(document).on('click', '.delete-achievement', function() {
        var id = $(this).data('id');
        if (confirm('Are you sure you want to delete this achievement? This action cannot be undone.')) {
            // TODO: Implement delete functionality
            alert('Delete functionality will be implemented in a future update.');
        }
    });
    
    // Form validation
    $('form').on('submit', function() {
        var form = $(this);
        var requiredFields = form.find('[required]');
        var isValid = true;
        
        requiredFields.each(function() {
            var field = $(this);
            if (!field.val().trim()) {
                field.css('border-color', '#dc3545');
                isValid = false;
            } else {
                field.css('border-color', '#ddd');
            }
        });
        
        if (!isValid) {
            alert('Please fill in all required fields.');
            return false;
        }
        
        return true;
    });
    
    // Auto-generate slug from name
    $('input[name="name"]').on('input', function() {
        var name = $(this).val();
        var slug = name.toLowerCase()
            .replace(/[^a-z0-9\s-]/g, '') // Remove special characters
            .replace(/\s+/g, '-') // Replace spaces with hyphens
            .replace(/-+/g, '-') // Replace multiple hyphens with single
            .replace(/^-|-$/g, ''); // Remove leading/trailing hyphens
        
        var slugField = $(this).closest('form').find('input[name="slug"]');
        if (slugField.length && !slugField.val()) {
            slugField.val(slug);
        }
    });
    
    // Date picker for transaction filters
    if ($('input[name="date_from"], input[name="date_to"]').length) {
        $('input[name="date_from"], input[name="date_to"]').datepicker({
            dateFormat: 'yy-mm-dd',
            maxDate: 0 // Today
        });
    }
    
    // Real-time search for users
    var searchTimeout;
    $('input[name="search_user"]').on('input', function() {
        var searchTerm = $(this).val();
        
        clearTimeout(searchTimeout);
        
        if (searchTerm.length >= 3) {
            searchTimeout = setTimeout(function() {
                // Auto-submit form after 500ms delay
                $('input[name="search_user"]').closest('form').submit();
            }, 500);
        }
    });
    
    // Sortable tables (if needed)
    if (typeof $.fn.tablesorter !== 'undefined') {
        $('.wp-list-table').tablesorter({
            headers: {
                // Disable sorting on action columns
                '.no-sort': { sorter: false }
            }
        });
    }
    
    // Confirmation dialogs for destructive actions
    $('.delete-point-type, .delete-earning-rule, .delete-rank, .delete-achievement').on('click', function(e) {
        if (!confirm('Are you sure you want to delete this item? This action cannot be undone.')) {
            e.preventDefault();
            return false;
        }
    });
    
    // Auto-refresh dashboard stats every 30 seconds
    if ($('.dollarbets-dashboard').length) {
        setInterval(function() {
            // Only refresh if user is still on the page
            if (document.hasFocus()) {
                location.reload();
            }
        }, 30000);
    }
    
    // Tooltip functionality for help text
    $('[data-tooltip]').on('mouseenter', function() {
        var tooltip = $('<div class="dollarbets-tooltip">' + $(this).data('tooltip') + '</div>');
        $('body').append(tooltip);
        
        var offset = $(this).offset();
        tooltip.css({
            position: 'absolute',
            top: offset.top - tooltip.outerHeight() - 5,
            left: offset.left + ($(this).outerWidth() / 2) - (tooltip.outerWidth() / 2),
            background: '#333',
            color: '#fff',
            padding: '5px 10px',
            borderRadius: '4px',
            fontSize: '12px',
            zIndex: 9999
        });
    }).on('mouseleave', function() {
        $('.dollarbets-tooltip').remove();
    });
    
    // Bulk actions (for future implementation)
    $('#bulk-action-selector-top, #bulk-action-selector-bottom').on('change', function() {
        var action = $(this).val();
        var checkboxes = $('input[name="bulk-select[]"]:checked');
        
        if (action && checkboxes.length > 0) {
            // Enable apply button
            $(this).siblings('.button').prop('disabled', false);
        } else {
            // Disable apply button
            $(this).siblings('.button').prop('disabled', true);
        }
    });
    
    // Select all checkboxes
    $('#cb-select-all-1, #cb-select-all-2').on('change', function() {
        var isChecked = $(this).prop('checked');
        $('input[name="bulk-select[]"]').prop('checked', isChecked);
    });
    
    // Individual checkbox change
    $('input[name="bulk-select[]"]').on('change', function() {
        var totalCheckboxes = $('input[name="bulk-select[]"]').length;
        var checkedCheckboxes = $('input[name="bulk-select[]"]:checked').length;
        
        // Update select all checkbox state
        if (checkedCheckboxes === 0) {
            $('#cb-select-all-1, #cb-select-all-2').prop('checked', false).prop('indeterminate', false);
        } else if (checkedCheckboxes === totalCheckboxes) {
            $('#cb-select-all-1, #cb-select-all-2').prop('checked', true).prop('indeterminate', false);
        } else {
            $('#cb-select-all-1, #cb-select-all-2').prop('checked', false).prop('indeterminate', true);
        }
    });
    
    // Export functionality (for future implementation)
    $('.export-data').on('click', function() {
        var dataType = $(this).data('type');
        alert('Export functionality for ' + dataType + ' will be implemented in a future update.');
    });
    
    // Import functionality (for future implementation)
    $('.import-data').on('click', function() {
        var dataType = $(this).data('type');
        alert('Import functionality for ' + dataType + ' will be implemented in a future update.');
    });
    
    // Advanced search toggle
    $('.advanced-search-toggle').on('click', function() {
        $('.advanced-search-fields').slideToggle();
        $(this).text($(this).text() === 'Show Advanced' ? 'Hide Advanced' : 'Show Advanced');
    });
    
    // Auto-save form data to localStorage
    $('form input, form select, form textarea').on('change', function() {
        var form = $(this).closest('form');
        var formId = form.attr('id') || 'dollarbets-form';
        var formData = form.serialize();
        localStorage.setItem('dollarbets-' + formId, formData);
    });
    
    // Restore form data from localStorage
    $('form').each(function() {
        var form = $(this);
        var formId = form.attr('id') || 'dollarbets-form';
        var savedData = localStorage.getItem('dollarbets-' + formId);
        
        if (savedData) {
            var params = new URLSearchParams(savedData);
            params.forEach(function(value, key) {
                var field = form.find('[name="' + key + '"]');
                if (field.length) {
                    if (field.is(':checkbox') || field.is(':radio')) {
                        field.filter('[value="' + value + '"]').prop('checked', true);
                    } else {
                        field.val(value);
                    }
                }
            });
        }
    });
    
    // Clear saved form data on successful submission
    $('form').on('submit', function() {
        var formId = $(this).attr('id') || 'dollarbets-form';
        localStorage.removeItem('dollarbets-' + formId);
    });
});


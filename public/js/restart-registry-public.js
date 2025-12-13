(function($) {
    'use strict';

    $(document).ready(function() {
        var $container = $('.rr-manage-registry, .rr-view-registry, .rr-create-form');
        
        if (!$container.length) return;

        var registryId = $container.data('registry-id');

        $('#rr-create-registry-form').on('submit', function(e) {
            e.preventDefault();
            
            var $form = $(this);
            var $button = $form.find('button[type="submit"]');
            var originalText = $button.text();
            
            $button.prop('disabled', true).text(restartRegistry.strings.loading);

            $.ajax({
                url: restartRegistry.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'restart_registry_create',
                    nonce: restartRegistry.nonce,
                    title: $form.find('[name="title"]').val(),
                    description: $form.find('[name="description"]').val(),
                    is_public: $form.find('[name="is_public"]').is(':checked') ? '1' : '0'
                },
                success: function(response) {
                    if (response.success) {
                        window.location.reload();
                    } else {
                        alert(response.data.message || restartRegistry.strings.error);
                        $button.prop('disabled', false).text(originalText);
                    }
                },
                error: function() {
                    alert(restartRegistry.strings.error);
                    $button.prop('disabled', false).text(originalText);
                }
            });
        });

        $('#rr-fetch-url').on('click', function() {
            var $button = $(this);
            var url = $('#rr-item-url').val();
            
            if (!url) {
                alert('Please enter a product URL first.');
                return;
            }

            var originalText = $button.text();
            $button.prop('disabled', true).text(restartRegistry.strings.loading);

            $.ajax({
                url: restartRegistry.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'restart_registry_fetch_url',
                    nonce: restartRegistry.nonce,
                    url: url
                },
                success: function(response) {
                    if (response.success) {
                        if (response.data.name) {
                            $('#rr-item-name').val(response.data.name);
                        }
                        if (response.data.price) {
                            $('#rr-item-price').val(response.data.price);
                        }
                        if (response.data.image_url) {
                            $('#rr-add-item-form').data('image-url', response.data.image_url);
                        }
                        if (response.data.is_affiliate) {
                            showNotice('This link will be converted to an affiliate link from ' + response.data.retailer, 'info');
                        }
                    } else {
                        alert(response.data.message || 'Could not fetch product details.');
                    }
                    $button.prop('disabled', false).text(originalText);
                },
                error: function() {
                    alert('Could not fetch URL. Please enter details manually.');
                    $button.prop('disabled', false).text(originalText);
                }
            });
        });

        $('#rr-add-item-form').on('submit', function(e) {
            e.preventDefault();
            
            var $form = $(this);
            var $button = $form.find('button[type="submit"]');
            var originalText = $button.text();
            
            $button.prop('disabled', true).text(restartRegistry.strings.loading);

            $.ajax({
                url: restartRegistry.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'restart_registry_add_item',
                    nonce: restartRegistry.nonce,
                    registry_id: registryId,
                    name: $form.find('[name="name"]').val(),
                    url: $form.find('[name="url"]').val(),
                    description: $form.find('[name="description"]').val(),
                    price: $form.find('[name="price"]').val(),
                    quantity: $form.find('[name="quantity"]').val(),
                    priority: $form.find('[name="priority"]').val(),
                    image_url: $form.data('image-url') || ''
                },
                success: function(response) {
                    if (response.success) {
                        var $itemsContainer = $('#rr-items-container');
                        $itemsContainer.find('.rr-no-items').remove();
                        $itemsContainer.append(response.data.html);
                        
                        $form[0].reset();
                        $form.removeData('image-url');
                        
                        updateItemCount();
                        
                        if (response.data.is_affiliate) {
                            showNotice('Item added! Link converted to affiliate link for ' + response.data.retailer, 'success');
                        } else {
                            showNotice('Item added successfully!', 'success');
                        }
                    } else {
                        alert(response.data.message || restartRegistry.strings.error);
                    }
                    $button.prop('disabled', false).text(originalText);
                },
                error: function() {
                    alert(restartRegistry.strings.error);
                    $button.prop('disabled', false).text(originalText);
                }
            });
        });

        $(document).on('click', '.rr-delete-item', function() {
            if (!confirm(restartRegistry.strings.confirmDelete)) return;
            
            var $card = $(this).closest('.rr-item-card');
            var itemId = $card.data('item-id');

            $.ajax({
                url: restartRegistry.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'restart_registry_delete_item',
                    nonce: restartRegistry.nonce,
                    item_id: itemId
                },
                success: function(response) {
                    if (response.success) {
                        $card.fadeOut(300, function() {
                            $(this).remove();
                            updateItemCount();
                            
                            if ($('#rr-items-container .rr-item-card').length === 0) {
                                $('#rr-items-container').html('<p class="rr-no-items">No items yet. Add your first item above!</p>');
                            }
                        });
                    } else {
                        alert(response.data.message || restartRegistry.strings.error);
                    }
                },
                error: function() {
                    alert(restartRegistry.strings.error);
                }
            });
        });

        $(document).on('click', '.rr-mark-purchased', function() {
            var $card = $(this).closest('.rr-item-card');
            var itemId = $card.data('item-id');
            var $button = $(this);
            
            var purchaserName = prompt('Your name (optional - leave blank for anonymous):') || '';
            
            var originalText = $button.text();
            $button.prop('disabled', true).text(restartRegistry.strings.loading);

            $.ajax({
                url: restartRegistry.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'restart_registry_mark_purchased',
                    nonce: restartRegistry.nonce,
                    item_id: itemId,
                    quantity: 1,
                    purchaser_name: purchaserName,
                    is_anonymous: purchaserName ? '0' : '1'
                },
                success: function(response) {
                    if (response.success) {
                        showNotice(response.data.message, 'success');
                        window.location.reload();
                    } else {
                        alert(response.data.message || restartRegistry.strings.error);
                        $button.prop('disabled', false).text(originalText);
                    }
                },
                error: function() {
                    alert(restartRegistry.strings.error);
                    $button.prop('disabled', false).text(originalText);
                }
            });
        });

        $('#rr-send-invite-form').on('submit', function(e) {
            e.preventDefault();
            
            var $form = $(this);
            var $button = $form.find('button[type="submit"]');
            var $email = $form.find('[name="email"]');
            var originalText = $button.text();
            
            $button.prop('disabled', true).text(restartRegistry.strings.loading);

            $.ajax({
                url: restartRegistry.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'restart_registry_send_invite',
                    nonce: restartRegistry.nonce,
                    registry_id: registryId,
                    email: $email.val()
                },
                success: function(response) {
                    if (response.success) {
                        showNotice(response.data.message, 'success');
                        $email.val('');
                    } else {
                        alert(response.data.message || restartRegistry.strings.error);
                    }
                    $button.prop('disabled', false).text(originalText);
                },
                error: function() {
                    alert(restartRegistry.strings.error);
                    $button.prop('disabled', false).text(originalText);
                }
            });
        });

        $(document).on('click', '.rr-edit-item', function() {
            var $card = $(this).closest('.rr-item-card');
            var itemId = $card.data('item-id');
            var currentName = $card.find('.rr-item-name').text();
            
            var newName = prompt('Edit item name:', currentName);
            if (newName && newName !== currentName) {
                $.ajax({
                    url: restartRegistry.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'restart_registry_update_item',
                        nonce: restartRegistry.nonce,
                        item_id: itemId,
                        name: newName
                    },
                    success: function(response) {
                        if (response.success) {
                            $card.find('.rr-item-name').text(newName);
                            showNotice('Item updated!', 'success');
                        } else {
                            alert(response.data.message || restartRegistry.strings.error);
                        }
                    },
                    error: function() {
                        alert(restartRegistry.strings.error);
                    }
                });
            }
        });

        function updateItemCount() {
            var count = $('#rr-items-container .rr-item-card').length;
            $('.rr-item-count').text('(' + count + ')');
        }

        function showNotice(message, type) {
            var $notice = $('<div class="rr-notice rr-notice-' + type + '">' + message + '</div>');
            $container.prepend($notice);
            
            setTimeout(function() {
                $notice.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 5000);
        }
    });

})(jQuery);

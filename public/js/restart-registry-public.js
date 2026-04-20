(function($) {
    'use strict';

    $(document).ready(function() {
        var $container = $('.rr-manage-registry, .rr-view-registry, .rr-create-form');
        if (!$container.length) return;

        var registryId = $container.data('registry-id');

        // ── Modal helpers ────────────────────────────────────────────────────
        function openModal(id) {
            var $modal = $(id);
            $modal.attr('aria-hidden', 'false').addClass('is-open');
            $('body').addClass('rr-modal-open');
            $modal.find('.rr-modal__close, .rr-modal-cancel').first().focus();
        }

        function closeModal(id) {
            var $modal = id ? $(id) : $('.rr-modal.is-open');
            $modal.attr('aria-hidden', 'true').removeClass('is-open');
            if (!$('.rr-modal.is-open').length) {
                $('body').removeClass('rr-modal-open');
            }
        }

        // Close on backdrop click or × button
        $(document).on('click', '.rr-modal__backdrop, .rr-modal__close, .rr-modal-cancel', function() {
            closeModal('#' + $(this).closest('.rr-modal').attr('id'));
        });

        // Close on ESC
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape') closeModal();
        });

        // ── Create registry ──────────────────────────────────────────────────
        $('#rr-create-registry-form').on('submit', function(e) {
            e.preventDefault();
            var $form   = $(this);
            var $button = $form.find('button[type="submit"]');
            $button.prop('disabled', true).text(restartRegistry.strings.loading);

            $.ajax({
                url:  restartRegistry.ajaxUrl,
                type: 'POST',
                data: {
                    action:      'restart_registry_create',
                    nonce:       restartRegistry.nonce,
                    title:       $form.find('[name="title"]').val(),
                    description: $form.find('[name="description"]').val(),
                    event_type:  $form.find('[name="event_type"]').val(),
                    event_date:  $form.find('[name="event_date"]').val(),
                    is_public:   $form.find('[name="is_public"]').is(':checked') ? '1' : '0'
                },
                success: function(response) {
                    if (response.success) {
                        window.location.href = response.data.redirect || window.location.href;
                    } else {
                        alert(response.data.message || restartRegistry.strings.error);
                        $button.prop('disabled', false).text('Create Registry');
                    }
                },
                error: function() {
                    alert(restartRegistry.strings.error);
                    $button.prop('disabled', false).text('Create Registry');
                }
            });
        });

        // ── Public / private toggle ──────────────────────────────────────────
        $('#rr-public-toggle').on('change', function() {
            var $toggle    = $(this);
            var $label     = $toggle.closest('.rr-toggle').find('.rr-toggle__label');
            var isPublic   = $toggle.is(':checked');

            $label.text(isPublic ? $label.data('on') : $label.data('off'));

            $.ajax({
                url:  restartRegistry.ajaxUrl,
                type: 'POST',
                data: {
                    action:      'restart_registry_update',
                    nonce:       restartRegistry.nonce,
                    registry_id: registryId,
                    is_public:   isPublic ? '1' : '0'
                },
                success: function(response) {
                    if (!response.success) {
                        // Revert on failure
                        $toggle.prop('checked', !isPublic);
                        $label.text(!isPublic ? $label.data('on') : $label.data('off'));
                        alert(response.data.message || restartRegistry.strings.error);
                    }
                },
                error: function() {
                    $toggle.prop('checked', !isPublic);
                    $label.text(!isPublic ? $label.data('on') : $label.data('off'));
                    alert(restartRegistry.strings.error);
                }
            });
        });

        // ── Share modal ──────────────────────────────────────────────────────
        $('#rr-share-toggle').on('click', function() {
            openModal('#rr-share-modal');
        });

        $('#rr-copy-link').on('click', function() {
            var $btn = $(this);
            navigator.clipboard.writeText($('#rr-share-url').val()).then(function() {
                $btn.text('Copied!');
                setTimeout(function() { $btn.text('Copy'); }, 2000);
            });
        });

        // ── Settings modal ───────────────────────────────────────────────────
        $('#rr-edit-registry').on('click', function() {
            openModal('#rr-settings-modal');
        });

        $('#rr-edit-registry-form').on('submit', function(e) {
            e.preventDefault();
            var $form   = $(this);
            var $button = $form.find('button[type="submit"]');
            $button.prop('disabled', true).text(restartRegistry.strings.loading);

            $.ajax({
                url:  restartRegistry.ajaxUrl,
                type: 'POST',
                data: {
                    action:      'restart_registry_update',
                    nonce:       restartRegistry.nonce,
                    registry_id: registryId,
                    title:       $form.find('[name="title"]').val(),
                    description: $form.find('[name="description"]').val(),
                    event_type:  $form.find('[name="event_type"]').val(),
                    event_date:  $form.find('[name="event_date"]').val(),
                    is_public:   $form.find('[name="is_public"]').is(':checked') ? '1' : '0'
                },
                success: function(response) {
                    if (response.success) {
                        closeModal('#rr-settings-modal');
                        showNotice(response.data.message, 'success');
                        setTimeout(function() { window.location.reload(); }, 1200);
                    } else {
                        alert(response.data.message || restartRegistry.strings.error);
                        $button.prop('disabled', false).text('Save Changes');
                    }
                },
                error: function() {
                    alert(restartRegistry.strings.error);
                    $button.prop('disabled', false).text('Save Changes');
                }
            });
        });

        // ── Add item panel toggle ────────────────────────────────────────────
        $('#rr-add-item-toggle').on('click', function() {
            $('#rr-add-item-panel').slideDown(200);
            $(this).hide();
            $('#rr-item-url').focus();
        });

        $('#rr-add-item-cancel').on('click', function() {
            $('#rr-add-item-panel').slideUp(200);
            $('#rr-add-item-toggle').show();
            $('#rr-add-item-form')[0].reset();
        });

        // ── Fetch URL details ────────────────────────────────────────────────
        $('#rr-fetch-url').on('click', function() {
            var $button = $(this);
            var url = $('#rr-item-url').val();
            if (!url) return;

            $button.prop('disabled', true).text('…');

            $.ajax({
                url:  restartRegistry.ajaxUrl,
                type: 'POST',
                data: { action: 'restart_registry_fetch_url', nonce: restartRegistry.nonce, url: url },
                success: function(response) {
                    if (response.success) {
                        if (response.data.name)      $('#rr-item-name').val(response.data.name);
                        if (response.data.price)     $('#rr-item-price').val(response.data.price);
                        if (response.data.image_url) $('#rr-item-image-url').val(response.data.image_url);
                        $('#rr-item-name').focus();
                    }
                    $button.prop('disabled', false).text('Fetch');
                },
                error: function() { $button.prop('disabled', false).text('Fetch'); }
            });
        });

        $('#rr-item-url').on('blur', function() {
            if ($(this).val() && !$('#rr-item-name').val()) {
                $('#rr-fetch-url').trigger('click');
            }
        });

        // ── Add item ─────────────────────────────────────────────────────────
        $('#rr-add-item-form').on('submit', function(e) {
            e.preventDefault();
            var $form   = $(this);
            var $button = $form.find('button[type="submit"]');
            $button.prop('disabled', true).text(restartRegistry.strings.loading);

            $.ajax({
                url:  restartRegistry.ajaxUrl,
                type: 'POST',
                data: {
                    action:      'restart_registry_add_item',
                    nonce:       restartRegistry.nonce,
                    registry_id: registryId,
                    name:        $form.find('[name="name"]').val(),
                    url:         $form.find('[name="url"]').val(),
                    description: $form.find('[name="description"]').val(),
                    price:       $form.find('[name="price"]').val(),
                    quantity:    $form.find('[name="quantity"]').val(),
                    image_url:   $form.find('[name="image_url"]').val()
                },
                success: function(response) {
                    if (response.success) {
                        var $list = $('#rr-items-container');
                        $list.find('.rr-no-items').remove();
                        if (!$list.find('.rr-item-list').length) {
                            $list.html('<ul class="rr-item-list"></ul>');
                        }
                        $list.find('.rr-item-list').append(response.data.html);
                        $form[0].reset();
                        $('#rr-add-item-panel').slideUp(200);
                        $('#rr-add-item-toggle').show();
                        updateItemCount();
                        showNotice(response.data.is_affiliate
                            ? 'Added! Affiliate link from ' + response.data.retailer + '.'
                            : 'Item added.', 'success');
                    } else {
                        alert(response.data.message || restartRegistry.strings.error);
                    }
                    $button.prop('disabled', false).text('Add');
                },
                error: function() {
                    alert(restartRegistry.strings.error);
                    $button.prop('disabled', false).text('Add');
                }
            });
        });

        // ── Delete item ──────────────────────────────────────────────────────
        $(document).on('click', '.rr-delete-item', function() {
            if (!confirm(restartRegistry.strings.confirmDelete)) return;

            var $row   = $(this).closest('.rr-item-row, .rr-item-card');
            var itemId = $row.data('item-id');

            $.ajax({
                url:  restartRegistry.ajaxUrl,
                type: 'POST',
                data: {
                    action:      'restart_registry_delete_item',
                    nonce:       restartRegistry.nonce,
                    item_id:     itemId,
                    registry_id: registryId
                },
                success: function(response) {
                    if (response.success) {
                        $row.fadeOut(250, function() {
                            $(this).remove();
                            updateItemCount();
                            if (!$('#rr-items-container .rr-item-row, #rr-items-container .rr-item-card').length) {
                                $('#rr-items-container').html('<p class="rr-no-items">No items yet — add something you need to restart.</p>');
                            }
                        });
                    } else {
                        alert(response.data.message || restartRegistry.strings.error);
                    }
                },
                error: function() { alert(restartRegistry.strings.error); }
            });
        });

        // ── Edit item (modal) ────────────────────────────────────────────────
        $(document).on('click', '.rr-edit-item', function() {
            var $row = $(this).closest('.rr-item-row, .rr-item-card');
            $('#rr-edit-item-id').val($row.data('item-id'));
            $('#rr-edit-item-name').val($row.data('name'));
            $('#rr-edit-item-url').val($row.data('url'));
            $('#rr-edit-item-price').val($row.data('price'));
            $('#rr-edit-item-quantity').val($row.data('quantity') || 1);
            $('#rr-edit-item-description').val($row.data('description'));
            $('#rr-edit-item-image-url').val($row.data('image-url'));
            openModal('#rr-item-edit-modal');
        });

        $('#rr-edit-item-form').on('submit', function(e) {
            e.preventDefault();
            var $form   = $(this);
            var $button = $form.find('button[type="submit"]');
            $button.prop('disabled', true).text(restartRegistry.strings.loading);

            $.ajax({
                url:  restartRegistry.ajaxUrl,
                type: 'POST',
                data: {
                    action:      'restart_registry_update_item',
                    nonce:       restartRegistry.nonce,
                    item_id:     $('#rr-edit-item-id').val(),
                    registry_id: registryId,
                    name:        $('#rr-edit-item-name').val(),
                    url:         $('#rr-edit-item-url').val(),
                    price:       $('#rr-edit-item-price').val(),
                    quantity:    $('#rr-edit-item-quantity').val(),
                    description: $('#rr-edit-item-description').val(),
                    image_url:   $('#rr-edit-item-image-url').val()
                },
                success: function(response) {
                    if (response.success) {
                        window.location.reload();
                    } else {
                        alert(response.data.message || restartRegistry.strings.error);
                        $button.prop('disabled', false).text(restartRegistry.strings.save || 'Save Changes');
                    }
                },
                error: function() {
                    alert(restartRegistry.strings.error);
                    $button.prop('disabled', false).text(restartRegistry.strings.save || 'Save Changes');
                }
            });
        });

        // ── Mark as purchased (guest view) ───────────────────────────────────
        $(document).on('click', '.rr-mark-purchased', function() {
            var $card   = $(this).closest('.rr-item-card');
            var itemId  = $card.data('item-id');
            var $button = $(this);
            var name    = prompt('Your name (optional):') || '';

            $button.prop('disabled', true).text(restartRegistry.strings.loading);

            $.ajax({
                url:  restartRegistry.ajaxUrl,
                type: 'POST',
                data: {
                    action:         'restart_registry_mark_purchased',
                    nonce:          restartRegistry.nonce,
                    item_id:        itemId,
                    quantity:       1,
                    purchaser_name: name,
                    is_anonymous:   name ? '0' : '1'
                },
                success: function(response) {
                    if (response.success) {
                        showNotice(response.data.message, 'success');
                        setTimeout(function() { window.location.reload(); }, 1500);
                    } else {
                        alert(response.data.message || restartRegistry.strings.error);
                        $button.prop('disabled', false).text('Mark as Purchased');
                    }
                },
                error: function() {
                    alert(restartRegistry.strings.error);
                    $button.prop('disabled', false).text('Mark as Purchased');
                }
            });
        });

        // ── Send invite ──────────────────────────────────────────────────────
        $('#rr-send-invite-form').on('submit', function(e) {
            e.preventDefault();
            var $form    = $(this);
            var $button  = $form.find('button[type="submit"]');
            var $invitee = $form.find('[name="invitee"]');
            $button.prop('disabled', true).text(restartRegistry.strings.loading);

            $.ajax({
                url:  restartRegistry.ajaxUrl,
                type: 'POST',
                data: {
                    action:      'restart_registry_send_invite',
                    nonce:       restartRegistry.nonce,
                    registry_id: registryId,
                    invitee:     $invitee.val()
                },
                success: function(response) {
                    if (response.success) {
                        showNotice(response.data.message, 'success');
                        $invitee.val('');
                    } else {
                        alert(response.data.message || restartRegistry.strings.error);
                    }
                    $button.prop('disabled', false).text('Send Invite');
                },
                error: function() {
                    alert(restartRegistry.strings.error);
                    $button.prop('disabled', false).text('Send Invite');
                }
            });
        });

        // ── Helpers ──────────────────────────────────────────────────────────
        function updateItemCount() {
            var count = $('#rr-items-container .rr-item-row, #rr-items-container .rr-item-card').length;
            $('.rr-item-count').text('(' + count + ')');
        }

        function showNotice(message, type) {
            var $notice = $('<div class="rr-notice rr-notice-' + type + '">' + message + '</div>');
            $container.prepend($notice);
            setTimeout(function() {
                $notice.fadeOut(300, function() { $(this).remove(); });
            }, 4000);
        }
    });

})(jQuery);

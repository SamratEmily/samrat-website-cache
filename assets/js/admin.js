/**
 * Samrat Website Cache - Admin JavaScript
 *
 * @package Samrat_Website_Cache
 */

(function ($) {
    'use strict';

    /**
     * Show toast notification
     */
    function showToast(message, type) {
        // Remove existing toast
        $('.samrat-cache-toast').remove();

        var iconClass = type === 'success' ? 'yes-alt' : 'warning';

        // Build DOM nodes instead of concatenating HTML to prevent XSS
        var $toast = $('<div>').addClass('samrat-cache-toast ' + type);
        $toast.append($('<span>').addClass('dashicons dashicons-' + iconClass));
        $toast.append($('<span>').text(message)); // .text() escapes the message safely

        $('body').append($toast);

        // Auto remove after 4 seconds
        setTimeout(function () {
            $toast.addClass('hiding');
            setTimeout(function () {
                $toast.remove();
            }, 300);
        }, 4000);
    }

    /**
     * Clear cache via AJAX
     */
    function clearCache($button) {
        if ($button.hasClass('loading')) {
            return;
        }

        var originalText = $button.html();
        $button.addClass('loading');
        $button.html(
            '<span class="dashicons dashicons-update" style="animation: spin 1s linear infinite;"></span> ' +
            samratCache.clearingText
        );

        // Add spin animation
        $('<style>@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }</style>').appendTo('head');

        $.ajax({
            url: samratCache.ajaxUrl,
            type: 'POST',
            data: {
                action: 'samrat_clear_cache',
                nonce: samratCache.nonce
            },
            success: function (response) {
                if (response.success) {
                    $button.removeClass('loading').addClass('success');
                    $button.html(
                        '<span class="dashicons dashicons-yes-alt"></span> ' +
                        samratCache.clearedText
                    );
                    showToast(response.data.message, 'success');

                    // Update stats if on settings page
                    updateCacheStats();

                    // Reset button after 3 seconds
                    setTimeout(function () {
                        $button.removeClass('success');
                        $button.html(originalText);
                    }, 3000);
                } else {
                    $button.removeClass('loading');
                    $button.html(originalText);
                    showToast(response.data.message || samratCache.errorText, 'error');
                }
            },
            error: function () {
                $button.removeClass('loading');
                $button.html(originalText);
                showToast(samratCache.errorText, 'error');
            }
        });
    }

    /**
     * Update cache statistics
     */
    function updateCacheStats() {
        // Update cached pages count to 0
        var statCards = $('.samrat-cache-stat-card');
        if (statCards.length > 0) {
            statCards.eq(0).find('.stat-value').text('0');
            statCards.eq(1).find('.stat-value').text('0 B');
        }
    }

    /**
     * Initialize
     */
    $(document).ready(function () {
        // Clear cache button on settings page
        $('#samrat-clear-all-cache').on('click', function (e) {
            e.preventDefault();
            clearCache($(this));
        });

        // Clear cache button on clear cache page
        $('#samrat-clear-all-cache-page').on('click', function (e) {
            e.preventDefault();
            clearCache($(this));
        });

        // Add hover effect to stat cards
        $('.samrat-cache-stat-card').on('mouseenter', function () {
            $(this).css('transform', 'translateY(-4px)');
        }).on('mouseleave', function () {
            $(this).css('transform', 'translateY(0)');
        });
    });

    /**
     * Global function for admin bar
     */
    window.samratClearCacheFromBar = function () {
        $.ajax({
            url: samratCache.ajaxUrl,
            type: 'POST',
            data: {
                action: 'samrat_clear_cache',
                nonce: samratCache.nonce
            },
            success: function (response) {
                if (response.success) {
                    showToast(response.data.message, 'success');
                } else {
                    showToast(response.data.message || 'Error clearing cache', 'error');
                }
            },
            error: function () {
                showToast('Error clearing cache', 'error');
            }
        });
    };

})(jQuery);

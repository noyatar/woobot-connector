/**
 * WooBot Admin Dashboard JavaScript
 */
(function($) {
    'use strict';

    $(document).ready(function() {

        // Copy to clipboard
        $('.woobot-copy-btn').on('click', function() {
            var targetId = $(this).data('target');
            var input = $('#' + targetId);
            var originalType = input.attr('type');
            if (originalType === 'password') input.attr('type', 'text');
            input.select();
            input[0].setSelectionRange(0, 99999);
            try { document.execCommand('copy'); } catch(e) { navigator.clipboard.writeText(input.val()); }
            if (originalType === 'password') input.attr('type', 'password');
            showMsg($(this), woobotAdmin.strings.copied, 'success');
        });

        // Toggle visibility
        $('.woobot-toggle-visibility').on('click', function() {
            var input = $('#' + $(this).data('target'));
            if (input.attr('type') === 'password') {
                input.attr('type', 'text'); $(this).text('Hide');
            } else {
                input.attr('type', 'password'); $(this).text('Show');
            }
        });

        // Save settings
        $('#woobot-save-settings').on('click', function() {
            var btn = $(this), status = $('#woobot-save-status');
            btn.prop('disabled', true);
            $.post(woobotAdmin.ajaxUrl, {
                action: 'woobot_save_settings',
                nonce: woobotAdmin.nonce,
                server_url: $('#woobot-server-url').val(),
                partner_id: $('#woobot-partner-id').val(),
                default_status: $('#woobot-default-status').val(),
                max_file_size: $('#woobot-max-file-size').val(),
                rate_limit: $('#woobot-rate-limit').val(),
                log_retention: $('#woobot-log-retention').val()
            }, function(res) {
                btn.prop('disabled', false);
                status.text(res.success ? woobotAdmin.strings.saved : woobotAdmin.strings.error)
                      .removeClass('success error').addClass(res.success ? 'success' : 'error');
                setTimeout(function() { status.text(''); }, 3000);
            }).fail(function() {
                btn.prop('disabled', false);
                status.text(woobotAdmin.strings.error).addClass('error');
            });
        });

        // Sync credits (Dashboard + Settings)
        $('#woobot-sync-credits, #woobot-sync-credits-settings').on('click', function() {
            var btn = $(this);
            var statusEl = btn.attr('id') === 'woobot-sync-credits'
                ? $('#woobot-sync-status')
                : $('#woobot-sync-status-settings');

            btn.prop('disabled', true);
            statusEl.text(woobotAdmin.strings.syncing).removeClass('success error');

            $.post(woobotAdmin.ajaxUrl, {
                action: 'woobot_sync_credits',
                nonce: woobotAdmin.nonce
            }, function(res) {
                btn.prop('disabled', false);
                if (res.success) {
                    statusEl.text(woobotAdmin.strings.syncSuccess).addClass('success');
                    // Update credit numbers on page
                    $('#woobot-listing-credits').text(res.data.listing);
                    $('#woobot-enhancement-credits').text(res.data.enhancement);
                } else {
                    statusEl.text(res.data || woobotAdmin.strings.syncFailed).addClass('error');
                }
                setTimeout(function() { statusEl.text(''); }, 4000);
            }).fail(function() {
                btn.prop('disabled', false);
                statusEl.text(woobotAdmin.strings.syncFailed).addClass('error');
            });
        });

        // Register store
        $('#woobot-register-btn').on('click', function() {
            var btn = $(this), status = $('#woobot-register-status');
            var partnerId = $('#woobot-register-partner-id').val();

            if (!partnerId) { alert('Enter a Partner ID'); return; }

            btn.prop('disabled', true);
            status.text(woobotAdmin.strings.registering).removeClass('success error');

            $.post(woobotAdmin.ajaxUrl, {
                action: 'woobot_register_store',
                nonce: woobotAdmin.nonce,
                partner_id: partnerId
            }, function(res) {
                btn.prop('disabled', false);
                if (res.success) {
                    status.text(woobotAdmin.strings.registerSuccess).addClass('success');
                    // Reload page to show new credentials
                    setTimeout(function() { location.reload(); }, 1500);
                } else {
                    status.text(res.data || woobotAdmin.strings.registerFailed).addClass('error');
                }
            }).fail(function() {
                btn.prop('disabled', false);
                status.text(woobotAdmin.strings.registerFailed).addClass('error');
            });
        });

        // Regenerate API key
        $('#woobot-regen-btn').on('click', function() {
            if (!confirm(woobotAdmin.strings.confirmRegenerate)) return;
            var btn = $(this);
            btn.prop('disabled', true);
            $.post(woobotAdmin.ajaxUrl, {
                action: 'woobot_regenerate_key', nonce: woobotAdmin.nonce
            }, function(res) {
                btn.prop('disabled', false);
                if (res.success) {
                    $('#woobot-api-key').val(res.data.key);
                    alert('API Key regenerated.');
                }
            });
        });

        // Log filters
        $('#woobot-apply-filters').on('click', function() {
            var params = new URLSearchParams(window.location.search);
            params.set('page', 'woobot-dashboard'); params.set('tab', 'logs'); params.set('log_page', '1');
            var v = { action_type: $('#woobot-filter-action').val(), log_status: $('#woobot-filter-status').val(),
                      date_from: $('#woobot-filter-from').val(), date_to: $('#woobot-filter-to').val() };
            Object.keys(v).forEach(function(k) { if(v[k]) params.set(k,v[k]); else params.delete(k); });
            window.location.search = params.toString();
        });

        // Clear / Export logs
        $('#woobot-clear-logs').on('click', function() {
            if (!confirm(woobotAdmin.strings.confirmClearLogs)) return;
            $.post(woobotAdmin.ajaxUrl, { action:'woobot_clear_logs', nonce:woobotAdmin.nonce }, function(r){if(r.success)location.reload();});
        });
        $('#woobot-export-logs').on('click', function() {
            $.post(woobotAdmin.ajaxUrl, { action:'woobot_export_logs', nonce:woobotAdmin.nonce }, function(r){
                if(r.success){var b=new Blob([r.data.csv],{type:'text/csv'});var a=document.createElement('a');
                a.href=URL.createObjectURL(b);a.download='woobot-logs-'+new Date().toISOString().slice(0,10)+'.csv';a.click();}
            });
        });
    });

    function showMsg(el, msg, type) {
        var s = $('<span class="woobot-inline-msg '+type+'">'+msg+'</span>');
        el.after(s); setTimeout(function(){s.fadeOut(300,function(){$(this).remove();});},2000);
    }
})(jQuery);

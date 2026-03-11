<?php
if (!defined('ABSPATH')) exit;

add_action('ai_aw_cron_hook', 'ai_aw_process_next_article');

function ai_aw_reschedule_cron() {
    $schedule = ai_aw_get_config('ai_aw_schedule', 'AI_AW_SCHEDULE', 'hourly');
    
    $timestamp = wp_next_scheduled('ai_aw_cron_hook');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'ai_aw_cron_hook');
    }
    
    if ($schedule !== 'none') {
        wp_schedule_event(time(), $schedule, 'ai_aw_cron_hook');
    }
}

register_deactivation_hook(WP_PLUGIN_DIR . '/ai-auto-writer/ai-auto-writer.php', 'ai_aw_deactivate');
function ai_aw_deactivate() {
    $timestamp = wp_next_scheduled('ai_aw_cron_hook');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'ai_aw_cron_hook');
    }
}

<?php
/*
Plugin Name: AI Auto Writer Complete
Description: Tự động crawl RSS/Sitemap, dùng Ollama (Local AI) viết lại bài chuẩn SEO và tự động đăng bài.
Version: 1.1
Author: Auto AI
*/

if (!defined('ABSPATH')) exit;

// 1. Tải các file cấu trúc
require_once plugin_dir_path(__FILE__) . 'includes/env-loader.php';
require_once plugin_dir_path(__FILE__) . 'admin/admin-settings.php';
require_once plugin_dir_path(__FILE__) . 'includes/crawler.php';
require_once plugin_dir_path(__FILE__) . 'includes/cron.php';

// 2. Khởi tạo Menu Quản trị
function ai_auto_writer_menu() {
    add_menu_page(
        'AI Auto Writer Settings',
        'AI Auto Writer',
        'manage_options',
        'ai-auto-writer',
        'ai_auto_writer_settings_page',
        'dashicons-robot',
        20
    );
}
add_action('admin_menu', 'ai_auto_writer_menu');

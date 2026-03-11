<?php
if (!defined('ABSPATH')) exit;

function ai_auto_writer_settings_page() {
    if (isset($_POST['ai_aw_save_settings'])) {
        update_option('ai_aw_rss_url', sanitize_text_field($_POST['rss_url']));
        update_option('ai_aw_api_model', sanitize_text_field($_POST['api_model']));
        update_option('ai_aw_api_url', esc_url_raw($_POST['api_url']));
        update_option('ai_aw_api_key', sanitize_text_field($_POST['api_key']));
        update_option('ai_aw_post_status', sanitize_text_field($_POST['post_status']));
        update_option('ai_aw_schedule', sanitize_text_field($_POST['schedule_interval']));
        
        ai_aw_reschedule_cron();
        
        echo '<div class="updated"><p>Đã lưu cài đặt và cập nhật lịch chạy!</p></div>';
    }
    
    if (isset($_POST['ai_aw_run_now'])) {
        $result = ai_aw_process_next_article();
        if ($result === true) {
            echo '<div class="updated"><p>Đã chạy thành công 1 bài viết!</p></div>';
        } else {
             echo '<div class="error"><p>Lỗi khi chạy thực thi: ' . esc_html($result) . '</p></div>';
        }
    }

    $rss_url           = ai_aw_get_config('ai_aw_rss_url', 'AI_AW_RSS_URL', '');
    $api_model         = ai_aw_get_config('ai_aw_api_model', 'AI_AW_API_MODEL', 'llama3-8b-8192');
    $api_url           = ai_aw_get_config('ai_aw_api_url', 'AI_AW_API_URL', 'https://api.groq.com/openai/v1/chat/completions');
    $api_key           = ai_aw_get_config('ai_aw_api_key', 'AI_AW_API_KEY', '');
    $post_status       = ai_aw_get_config('ai_aw_post_status', 'AI_AW_POST_STATUS', 'draft');
    $schedule_interval = ai_aw_get_config('ai_aw_schedule', 'AI_AW_SCHEDULE', 'hourly');

    ?>
    <div class="wrap">
        <h1>Cấu hình AI Auto Writer (Groq / OpenAI)</h1>
        <hr>
        <form method="post" action="">
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="rss_url">Nguồn RSS URL</label></th>
                    <td>
                        <input name="rss_url" type="url" id="rss_url" value="<?php echo esc_attr($rss_url); ?>" class="regular-text" placeholder="VD: https://vnexpress.net/rss/tin-moi-nhat.rss">
                        <p class="description">Link RSS Feed của trang web bạn muốn cào tin.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="api_url">API URL</label></th>
                    <td>
                        <input name="api_url" type="url" id="api_url" value="<?php echo esc_attr($api_url); ?>" class="regular-text" style="width: 400px;">
                        <p class="description">VD: <code>https://api.groq.com/openai/v1/chat/completions</code></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="api_model">Model AI</label></th>
                    <td>
                        <input name="api_model" type="text" id="api_model" value="<?php echo esc_attr($api_model); ?>" class="regular-text">
                        <p class="description">Groq Models VD: <code>llama3-8b-8192</code>, <code>mixtral-8x7b-32768</code>, <code>gemma2-9b-it</code></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="api_key">API Key (Tùy chọn)</label></th>
                    <td>
                        <input name="api_key" type="password" id="api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" placeholder="VD: sk-...">
                        <p class="description">Authorization: Bearer của Groq (hoặc OpenAI). VD: gsk_...</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="post_status">Trạng thái đăng bài</label></th>
                    <td>
                        <select name="post_status" id="post_status">
                            <option value="draft" <?php selected($post_status, 'draft'); ?>>Bản nháp (Draft)</option>
                            <option value="publish" <?php selected($post_status, 'publish'); ?>>Đăng ngay (Publish)</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="schedule_interval">Lịch chạy tự động (Cron)</label></th>
                    <td>
                        <select name="schedule_interval" id="schedule_interval">
                            <option value="none" <?php selected($schedule_interval, 'none'); ?>>Không chạy tự động (Chạy tay)</option>
                            <option value="hourly" <?php selected($schedule_interval, 'hourly'); ?>>Mỗi giờ 1 bài</option>
                            <option value="twicedaily" <?php selected($schedule_interval, 'twicedaily'); ?>>2 bài / ngày</option>
                            <option value="daily" <?php selected($schedule_interval, 'daily'); ?>>1 bài / ngày</option>
                        </select>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <input type="submit" name="ai_aw_save_settings" class="button button-primary" value="Lưu Tùy Chọn">
                <input type="submit" name="ai_aw_run_now" class="button button-secondary" value="🚀 Chạy Test Ngay (Cào 1 Bài)" style="margin-left:10px;">
            </p>
        </form>
        
        <hr>
        <h2>Logs & Trạng thái</h2>
        <div style="background:#fff; padding:15px; border:1px solid #ccc; max-height:200px; overflow-y:auto;">
            <p><strong>Lần chạy tự động tiếp theo:</strong> 
            <?php 
            $next_run = wp_next_scheduled('ai_aw_cron_hook');
            echo $next_run ? date('Y-m-d H:i:s', $next_run + (get_option('gmt_offset') * HOUR_IN_SECONDS)) : 'Chưa thiết lập';
            ?>
            </p>
        </div>
    </div>
    <?php
}

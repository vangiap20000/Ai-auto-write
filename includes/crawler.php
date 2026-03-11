<?php
if (!defined('ABSPATH')) exit;

function ai_aw_process_next_article()
{
    $rss_url = ai_aw_get_config('ai_aw_rss_url', 'AI_AW_RSS_URL', '');
    if (empty($rss_url)) return 'Chưa cấu hình URL mẫu RSS.';

    $rss_response = wp_remote_get($rss_url, ['timeout' => 30]);
    if (is_wp_error($rss_response)) {
        return 'Không thể kết nối đến URL RSS.';
    }

    $rss_body = wp_remote_retrieve_body($rss_response);

    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($rss_body);
    if (!$xml) {
        return 'URL không trả về định dạng XML/RSS hợp lệ.';
    }

    $items = isset($xml->channel->item) ? $xml->channel->item : $xml->item;

    if (empty($items)) return 'Không tìm thấy bài viết nào trong RSS.';

    foreach ($items as $item) {
        $source_link = (string)$item->link;
        $source_title = (string)$item->title;
        $source_desc = (string)$item->description;

        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'ai_aw_source_url' AND meta_value = %s LIMIT 1",
            $source_link
        ));

        if (!$exists) {
            $full_content = ai_aw_extract_article_content($source_link, $source_desc);
            $ai_result = ai_aw_rewrite_with_ai_api($source_title, $full_content);
            if (!$ai_result || empty($ai_result['content'])) {
                return 'Lỗi khi gọi AI Ollama hoặc kết quả trả về trống.';
            }

            $post_data = array(
                'post_title'   => $ai_result['title'] ? $ai_result['title'] : $source_title . ' (Rewrite)',
                'post_content' => $ai_result['content'] . "\n\n<em>Nguồn tham khảo: <a href=\"" . esc_url($source_link) . "\" target=\"_blank\">" . esc_url($source_link) . "</a></em>",
                'post_status'  => ai_aw_get_config('ai_aw_post_status', 'AI_AW_POST_STATUS', 'draft'),
                'post_author'  => 1
            );

            $post_id = wp_insert_post($post_data);

            if (!is_wp_error($post_id)) {
                update_post_meta($post_id, 'ai_aw_source_url', $source_link);

                if (!empty($ai_result['category_slug'])) {
                    $slug = sanitize_title($ai_result['category_slug']);
                    $categories_map = array(
                        'ai-va-tuong-lai' => 'AI & Tương lai',
                        'dien-thoai' => 'Điện thoại',
                        'may-tinh-va-linh-kien' => 'Máy tính & linh kiện',
                        'danh-gia' => 'Đánh giá'
                    );

                    $term_id = 0;
                    $cat_term = get_term_by('slug', $slug, 'category');
                    if ($cat_term) {
                        $term_id = $cat_term->term_id;
                    } elseif (isset($categories_map[$slug])) {
                        $new_term = wp_insert_term($categories_map[$slug], 'category', array('slug' => $slug));
                        if (!is_wp_error($new_term)) {
                            $term_id = $new_term['term_id'];
                        }
                    }

                    if ($term_id) {
                        wp_set_post_categories($post_id, array($term_id));
                    }
                }

                $updated_content = ai_aw_download_and_replace_media($post_data['post_content'], $post_id);
                if ($updated_content !== $post_data['post_content']) {
                    wp_update_post(array(
                        'ID' => $post_id,
                        'post_content' => $updated_content
                    ));
                }

                if (!has_post_thumbnail($post_id)) {
                    $content_to_scan = $updated_content !== $post_data['post_content'] ? $updated_content : $post_data['post_content'];
                    if (preg_match('/<img[^>]+src=[\'"]([^\'"]+)[\'"][^>]*>/i', $content_to_scan, $img_match)) {
                        $first_img_url = $img_match[1];
                        $attachment_id = attachment_url_to_postid($first_img_url);
                        if (!$attachment_id) {
                            $attachment_id = ai_aw_sideload_media_as_attachment($first_img_url, $post_id);
                        }
                        if ($attachment_id) {
                            set_post_thumbnail($post_id, $attachment_id);
                        }
                    }
                }

                return true;
            } else {
                return 'Lỗi WordPress khi Insert Post.';
            }
        }
    }

    return 'Không có bài viết mới nào chưa được cào.';
}

function ai_aw_extract_article_content($url, $fallback_desc)
{
    $response = wp_remote_get($url, ['timeout' => 20]);
    if (is_wp_error($response)) return $fallback_desc;

    $html = wp_remote_retrieve_body($response);

    $html = preg_replace('#<header(.*?)>(.*?)</header>#is', '', $html);
    $html = preg_replace('#<footer(.*?)>(.*?)</footer>#is', '', $html);
    $html = preg_replace('#<nav(.*?)>(.*?)</nav>#is', '', $html);
    $html = preg_replace('#<aside(.*?)>(.*?)</aside>#is', '', $html);
    $html = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $html);
    $html = preg_replace('#<style(.*?)>(.*?)</style>#is', '', $html);

    $html = preg_replace_callback('/<img([^>]+)>/is', function ($matches) {
        $attrs = $matches[1];

        $url = '';
        if (preg_match('/data-src=[\'"]([^\'"]+)[\'"]/i', $attrs, $m)) $url = $m[1];
        elseif (preg_match('/data-lazy-src=[\'"]([^\'"]+)[\'"]/i', $attrs, $m)) $url = $m[1];
        elseif (preg_match('/data-original=[\'"]([^\'"]+)[\'"]/i', $attrs, $m)) $url = $m[1];
        elseif (preg_match('/src=[\'"]([^\'"]+)[\'"]/i', $attrs, $m)) $url = $m[1];

        $alt = '';
        if (preg_match('/alt=[\'"]([^\'"]+)[\'"]/i', $attrs, $m)) $alt = $m[1];

        if (!empty($url) && strpos($url, 'data:image') !== 0) {
            return '<img src="' . $url . '" alt="' . esc_attr($alt) . '">';
        }
        return '';
    }, $html);

    $parsed_url = parse_url($url);
    $base_url = $parsed_url['scheme'] . '://' . $parsed_url['host'];
    $html = preg_replace('/src=["\']\/([^"\']+)["\']/is', 'src="' . $base_url . '/$1"', $html);

    $allowed_tags = '<p><br><h1><h2><h3><h4><ul><ol><li><strong><b><i><em><img><video><source><iframe><figure><figcaption><a><blockquote><picture>';
    $text = strip_tags($html, $allowed_tags);

    $text = preg_replace('/\s+/', ' ', $text);

    $text = mb_substr($text, 0, 15000);

    if (strlen($text) < 100) return strip_tags($fallback_desc);

    return $text;
}

function ai_aw_rewrite_with_ai_api($title, $content)
{
    $model = ai_aw_get_config('ai_aw_api_model', 'AI_AW_API_MODEL', 'llama3-8b-8192');
    $api_url = ai_aw_get_config('ai_aw_api_url', 'AI_AW_API_URL', 'https://api.groq.com/openai/v1/chat/completions');
    $api_key = ai_aw_get_config('ai_aw_api_key', 'AI_AW_API_KEY', '');

    $prompt = "Bạn là một chuyên gia Content SEO bài bản. Nhiệm vụ của bạn là đọc nội dung bài viết gốc và viết lại thành một bài blog hoàn chỉnh, hấp dẫn, độ dài tối đa 1000 chữ.
Bạn hãy trả về kết quả định dạng JSON chứa 3 trường:
- 'title': tiêu đề bài viết mới giật tít
- 'content': Nội dung bài viết mới
- 'category_slug': Chọn 1 trong 4 slug sau phù hợp nhất: 'ai-va-tuong-lai', 'dien-thoai', 'may-tinh-va-linh-kien', 'danh-gia'
ĐẶC BIỆT LƯU Ý CHO 'content': Nội dung bài viết mới PHẢI được định dạng bằng mã (markup) của Block WordPress (Gutenberg Blocks).
Ví dụ quy tắc định dạng Block:
- Đoạn văn: <!-- wp:paragraph --><p>Nội dung...</p><!-- /wp:paragraph -->
- Tiêu đề phụ: <!-- wp:heading --><h2>Tiêu đề...</h2><!-- /wp:heading -->
- Danh sách: <!-- wp:list --><ul><li>...</li></ul><!-- /wp:list -->
- Media (Ảnh/Video/Iframe): <!-- wp:html --> (bọc thẻ <img>, <video>, <iframe> CỦA BÀI GỐC vào đây) <!-- /wp:html -->
QUAN TRỌNG NHẤT: BẠN PHẢI BÊ NGUYÊN SI CÁC THẺ <img>, <video>, <iframe> (nếu có cung cấp đầu vào) VÀO BÀI VIẾT MỚI. KHÔNG ĐƯỢC BỎ XÓT HÌNH ẢNH NÀO. Bắt buộc bọc chúng nó bằng <!-- wp:html --> và <!-- /wp:html -->. Mất ảnh là không được.
KHÔNG giải thích, KHÔNG bọc JSON bằng markdown code block. CHỈ trả về đúng 1 đối tượng JSON hợp lệ gồm key 'title', 'content' và 'category_slug'.
Nội dung gốc cần viết lại:\nTiêu đề gốc: " . $title . "\nNội dung gốc: " . $content;

    $body = array(
        "model" => $model,
        "response_format" => array("type" => "json_object"),
        "messages" => array(
            array(
                "role" => "user",
                "content" => $prompt
            )
        )
    );

    $headers = array('Content-Type' => 'application/json');
    if (!empty($api_key)) {
        $headers['Authorization'] = 'Bearer ' . $api_key;
    }

    $response = wp_remote_post(
        $api_url,
        array(
            'timeout' => 120,
            'headers' => $headers,
            'body' => wp_json_encode($body)
        )
    );

    if (is_wp_error($response)) {
        return false;
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);

    if (isset($data['choices'][0]['message']['content'])) {
        $cleaned_json = trim($data['choices'][0]['message']['content']);
        $ai_json = json_decode($cleaned_json, true);
        if ($ai_json && isset($ai_json['title']) && isset($ai_json['content'])) {
            return $ai_json;
        }
    }

    return false;
}

function ai_aw_sideload_media($url, $post_id)
{
    if (empty($url)) return false;

    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');

    $parsed_url = parse_url($url);
    $path = isset($parsed_url['path']) ? $parsed_url['path'] : '';
    $filename = basename($path);
    if (empty($filename)) {
        $filename = 'media-' . time() . '.jpg';
    }

    $tmp = download_url($url);
    if (is_wp_error($tmp)) return false;

    $file_array = array(
        'name' => $filename,
        'tmp_name' => $tmp
    );

    $id = media_handle_sideload($file_array, $post_id);
    if (is_wp_error($id)) {
        @unlink($file_array['tmp_name']);
        return false;
    }

    return wp_get_attachment_url($id);
}

/**
 * Sideload a media URL and return the attachment ID (for use with set_post_thumbnail).
 */
function ai_aw_sideload_media_as_attachment($url, $post_id)
{
    if (empty($url)) return 0;

    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');

    $parsed_url = parse_url($url);
    $path = isset($parsed_url['path']) ? $parsed_url['path'] : '';
    $filename = basename($path);
    if (empty($filename)) {
        $filename = 'thumbnail-' . time() . '.jpg';
    }

    $tmp = download_url($url);
    if (is_wp_error($tmp)) return 0;

    $file_array = array(
        'name'     => $filename,
        'tmp_name' => $tmp
    );

    $attachment_id = media_handle_sideload($file_array, $post_id);
    if (is_wp_error($attachment_id)) {
        @unlink($file_array['tmp_name']);
        return 0;
    }

    return $attachment_id;
}

function ai_aw_download_and_replace_media($content, $post_id)
{
    return preg_replace_callback('/(<(?:img|video|source)[^>]+src=[\'"])([^\'"]+)([\'"][^>]*>)/is', function ($matches) use ($post_id) {
        $prefix = $matches[1];
        $url = html_entity_decode($matches[2]);
        $suffix = $matches[3];

        if (strpos($url, 'data:image') === 0 || strpos($url, home_url()) !== false) {
            return $matches[0];
        }

        $url = trim($url);

        $new_url = ai_aw_sideload_media($url, $post_id);
        if ($new_url) {
            return $prefix . $new_url . $suffix;
        }

        return $matches[0];
    }, $content);
}

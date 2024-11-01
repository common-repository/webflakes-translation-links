<?php
/*
Plugin Name: Webflakes Translation Links 
Plugin URI: http://www.webflakes.com/resources/bloggers/wp/plugin.zip
Description: This Wordpress plugin should be used by bloggers collaborating with www.webflakes.com. Webflakes curates lifestyle content from international bloggers and translates them from their native language into English. By installing this plugin, a link to the translated blog post will be placed at the footer of the original article automatically. For additional questions, contact bloggers@webflakes.com
Author: Webflakes
Version: 1.0.3
Author URI: http://www.webflakes.com/
*/

define("WEBFLAKES_SERVER_URL", "http://www.webflakes.com");

//register hooks
register_activation_hook(__FILE__, 'activate');
register_deactivation_hook(__FILE__, 'deactivate');

add_action('webflakes_scheduled_check', 'getTranslations');


//during activation
function activate() {
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    $sql = "CREATE TABLE IF NOT EXISTS `webflakes` (
                  `post_id` int(11) NOT NULL,
                  `date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                  `md5` varchar(255) NOT NULL,
                  `url` text NOT NULL,
                  `title` text NOT NULL,
                  PRIMARY KEY (`post_id`)
                )";
    dbDelta($sql);

    wp_schedule_event(time(), 'daily', 'webflakes_scheduled_check');

    //gather translations during activation
    getTranslations();
}

//during deactivation
function deactivate() {
    wp_clear_scheduled_hook('webflakes_scheduled_check');
}

//sync the posts table with webflakes
function getTranslations() {
    global $wpdb;
    //collect posts
    $args = array(
        'numberposts' => -1,
        'fields' => 'ids',
        'post_status' => 'publish',
    );

    $posts = get_posts($args);

    $blog_posts = array();
    foreach ($posts as $post => $postId) {
        $post_link = get_permalink($postId);
        $post_code = base64_encode(md5(utf8_encode($post_link), true));
        $blog_posts[$postId] = $post_code;
    }

    //collect pages
    $pages = get_all_page_ids($args);
    foreach ($pages as $pagePos => $pageId) {
        $post_object = get_page($pageId);
        $post_code = base64_encode(md5(utf8_encode($post_object->guid), true));
        $blog_posts[$pageId] = $post_code;
    }

    $existing = $wpdb->get_results("SELECT md5 FROM `webflakes`");

    $existing_codes = array();
    foreach ($existing as $translation) {
        if(in_array($translation->md5,$blog_posts)){
            $existing_codes[] = $translation->md5;
        }
    }
    $without_translation = array_values(array_diff($blog_posts,$existing_codes));

    //sync with webflakes
    $opts = array(
        'http' => array(
            'method' => 'POST',
            'header' => 'Content-type: application/json',
            'content' => json_encode($without_translation)
        )
    );

    //get remote data
    $blog_name = seoUrl(get_bloginfo('name'));
    $remote_data = file_get_contents(WEBFLAKES_SERVER_URL . "/api/getBacklinksData/$blog_name/" , false, stream_context_create($opts));
    $webflakes_resp = json_decode($remote_data, true);
    //here we are

    //update format option
    if (isset($webflakes_resp['format'])){
        update_option('webflakesFormat', $webflakes_resp['format']);
    }

    if (isset($webflakes_resp['data'])) {
        foreach ($without_translation as $item => $key){
            if (isset($webflakes_resp['data'][$key])){
                foreach ($webflakes_resp['data'][$key] as $url => $title) {
                    $sqlInsert = "INSERT INTO `webflakes` SET
                                        `post_id`='" . array_search($key, $blog_posts) . "' ,
                                        `md5`='" . $key . "' ,
                                        `url`='" . $url . "' ,
                                        `title`='" . addslashes(str_replace("{0}","<a target='_blank' href='$url'>$title</a>", get_option('webflakesFormat'))) . "'";
                    $wpdb->query($sqlInsert);
                }
            }
        }
    }
}

function webflakes_article_filter($content){
    global $wpdb;
    $sql = "SELECT url, title FROM `webflakes` WHERE `post_id`='" . get_the_ID() . "' ";
    $result = $wpdb->get_row($sql);
    if ($wpdb->num_rows != 0) {
        $new_content = $content."<p>".stripslashes($result->title)."</p>";
        return $new_content;
    } else {
        return $content;
    }
}

function seoUrl($string) {
    //lower case everything
    $string = strtolower($string);
    //make alphaunermic
    $string = preg_replace("/[^a-z0-9_\s-]/", "", $string);
    //Clean multiple dashes or whitespaces
    $string = preg_replace("/[\s-]+/", " ", $string);
    //Convert whitespaces and underscore to dash
    $string = preg_replace("/[\s_]/", "-", $string);
    return $string;
}

//add filter
add_filter('the_content', 'webflakes_article_filter');

?>

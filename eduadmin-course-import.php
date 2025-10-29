<?php

/*

Plugin Name: EduAdmin Course Import

Description: Imports courses and events from EduAdmin API into WordPress with ACF fields and cron support.

Author:  Aksell, OpenAI

Text Domain: eduadmin-course-import

Version: 1.10

*/

if (!defined('ABSPATH')) exit;



// === CONFIGURATION ===

$username = 'USERNAME';

$password = 'PASSWORD';

$token_file = plugin_dir_path(__FILE__) . 'eduadmin_token.json';



// === TOKEN HANDLING ===

function eduadmin_get_valid_token($username, $password, $token_file) {

    if (file_exists($token_file)) {

        $data = json_decode(file_get_contents($token_file), true);

        if (!empty($data['access_token']) && time() < $data['expires_at']) {

            return $data['access_token'];

        }

    }

    $resp = wp_remote_post('https://api.eduadmin.se/token', [

        'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],

        'body'    => [

            'username'   => $username,

            'password'   => $password,

            'grant_type' => 'password'

        ],

        'timeout' => 20,

    ]);

    if (is_wp_error($resp)) return false;

    $result = json_decode(wp_remote_retrieve_body($resp), true);

    if (empty($result['access_token'])) return false;

    $token_data = [

        'access_token' => $result['access_token'],

        'expires_at'   => time() + $result['expires_in'] - 30,

    ];

    file_put_contents($token_file, json_encode($token_data));

    return $token_data['access_token'];

}

function format_acf_datetime($dateString) {
    if (empty($dateString)) return '';

    try {
        // La PHP tolke ISO-formatet og tidssonen som ligger i strengen
        $dt = new DateTime($dateString);

        // Konverter til norsk tidssone (Europe/Oslo)
        $dt->setTimezone(new DateTimeZone('Europe/Oslo'));

        // Returnér som ACF-kompatibel DateTime
        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return '';
    }
}

function safe_update_field($field_key, $new_value, $post_id) {
    $old_value = get_field($field_key, $post_id);
    if ($old_value != $new_value) {
        update_field($field_key, $new_value, $post_id);
        return true;
    }
    return false;
}



function eduadmin_import_courses($import_all = false) {
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');

    global $username, $password, $token_file, $wpdb;

    if (!function_exists('update_field')) return;

    $token = 'Bearer ' . eduadmin_get_valid_token($username, $password, $token_file);
    if (!$token) return;

    $now = new DateTime('now', new DateTimeZone('Europe/Oslo'));
    $nowFormatted = $now->format('Y-m-d\TH:i:sP');
    $filter = urlencode("StartDate gt $nowFormatted");

    // === FASE 1: Hent events (kommende + siste 12 måneder) ===
$events_by_template = [];
$template_ids = [];

$headers = [
    'headers' => ['Authorization' => $token, 'Accept' => 'application/json']
];

$now = new DateTime('now', new DateTimeZone('Europe/Oslo'));
$one_year_ago = (clone $now)->modify('-12 months');
$nowFormatted = $now->format('Y-m-d\TH:i:sP');
$one_year_ago_fmt = $one_year_ago->format('Y-m-d\TH:i:sP');

$filter_future = urlencode("StartDate gt $nowFormatted");
$filter_recent = urlencode("StartDate lt $nowFormatted and StartDate gt $one_year_ago_fmt");

$urls = [
    "https://api.eduadmin.se/v1/odata/Events?\$expand=PriceNames&\$filter=$filter_future", // kommende
    "https://api.eduadmin.se/v1/odata/Events?\$expand=PriceNames&\$filter=$filter_recent"  // siste 12 måneder
];

foreach ($urls as $url) {
    $response = wp_remote_get($url, $headers);
    if (is_wp_error($response)) continue;

    $events_data = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($events_data['value'])) continue;

    foreach ($events_data['value'] as $ev) {
        $tpl_id = $ev['CourseTemplateId'] ?? null;
        if (!$tpl_id) continue;

        $row = [];
        foreach ($ev as $key => $val) {
            $key_lc = strtolower($key);
            if (in_array($key, ['StartDate','EndDate','ApplicationOpenDate','LastApplicationDate','Created','Modified'])) {
                $row[$key_lc] = format_acf_datetime($val ?? '');
            } else {
                $row[$key_lc] = $val;
            }
        }

        if (!empty($ev['EventId'])) $row['eventid'] = $ev['EventId'];
        if (!empty($ev['PriceNames'])) $row['pricenames'] = $ev['PriceNames'];

        $events_by_template[$tpl_id][] = $row;
        $template_ids[$tpl_id] = true;
    }
}


    if (empty($template_ids)) return;

    // === FASE 2: Hent kursmaler ===
    $id_list = implode(',', array_keys($template_ids));
    $templates_url = "https://api.eduadmin.se/v1/odata/CourseTemplates?\$filter=CourseTemplateId in ($id_list)&\$expand=CustomFields,PriceNames";
    $templates_data = json_decode(wp_remote_retrieve_body(wp_remote_get($templates_url, [
        'headers' => ['Authorization' => $token, 'Accept' => 'application/json']
    ])), true);

    if (empty($templates_data['value'])) return;

    // === IMPORTERING ===
    $imported = $updated = $events_added = $events_updated = 0;
    $query_counter = 0;

    // Helper: safe update
    $set_if_changed = function($field, $value, $post_id) {
        $current = get_field($field, $post_id);
        if ((string)$current === (string)$value) return false;
        update_field($field, $value, $post_id);
        return true;
    };

    // Helper: finn eller importer bilde (bruk guid som match)
    $find_or_import_image = function($image_url, $post_id) use ($wpdb) {
        if (empty($image_url)) return false;
        $basename = basename(parse_url($image_url, PHP_URL_PATH));
        $filename = pathinfo($basename, PATHINFO_FILENAME);

        // Finn eksisterende bilde med lik filnavn (uten -129 suffix)
        $like_query = $wpdb->esc_like($filename);
        $existing = $wpdb->get_var("
            SELECT ID FROM {$wpdb->posts}
            WHERE post_type='attachment' AND post_title LIKE '{$like_query}%'
            LIMIT 1
        ");

        if ($existing) {
            return (int)$existing;
        }

        // Ikke funnet → importer nytt
        $img_id = media_sideload_image($image_url, $post_id, null, 'id');
        if (!is_wp_error($img_id)) {
            update_post_meta($img_id, 'eduadmin_guid', $image_url);
            return (int)$img_id;
        }
        return false;
    };

    foreach ($templates_data['value'] as $tpl) {
        $template_id = $tpl['CourseTemplateId'] ?? null;
        if (!$template_id) continue;

        $title   = $tpl['CourseName'] ?? 'Untitled';
        $content = $tpl['CourseDescription'] ?? '';
        $excerpt = !empty($tpl['CourseDescriptionShort']) ? strip_tags((string)$tpl['CourseDescriptionShort']) : '';

        // Finn eksisterende kurs
        $q = new WP_Query([
            'post_type'      => 'course',
            'meta_key'       => 'coursetemplateid',
            'meta_value'     => $template_id,
            'posts_per_page' => 1
        ]);

        $post_id = 0;
        $is_new = false;

        if ($q->have_posts()) {
            $post_id = $q->posts[0]->ID;
            $existing = get_post($post_id);
            if (
                $existing->post_title !== $title ||
                $existing->post_content !== $content ||
                $existing->post_excerpt !== $excerpt
            ) {
                wp_update_post([
                    'ID'           => $post_id,
                    'post_title'   => $title,
                    'post_content' => $content,
                    'post_excerpt' => $excerpt
                ]);
                $updated++;
            }
        } else {
            $post_id = wp_insert_post([
                'post_type'    => 'course',
                'post_status'  => 'publish',
                'post_title'   => $title,
                'post_content' => $content,
                'post_excerpt' => $excerpt
            ]);
            $is_new = true;
            $imported++;
        }

        if (!$post_id || is_wp_error($post_id)) continue;

        // Oppdater kursmalfelter
        $changed = false;
        $changed |= $set_if_changed('coursetemplateid', $template_id, $post_id);
        $changed |= $set_if_changed('quote', $tpl['Quote'] ?? '', $post_id);
        $changed |= $set_if_changed('notes', $tpl['Notes'] ?? '', $post_id);
        $changed |= $set_if_changed('categoryid', $tpl['CategoryId'] ?? '', $post_id);
        $changed |= $set_if_changed('categoryname', $tpl['CategoryName'] ?? '', $post_id);

        // Bilde (unngå duplikater)
        if (!empty($tpl['ImageUrl'])) {
            $img_id = $find_or_import_image($tpl['ImageUrl'], $post_id);
            if ($img_id && get_post_thumbnail_id($post_id) != $img_id) {
                set_post_thumbnail($post_id, $img_id);
                $changed = true;
            }
        }

        // CustomFields (duration + language)
        $duration = '';
        $language = '';
        foreach ($tpl['CustomFields'] ?? [] as $cf) {
            $id = (int)($cf['CustomFieldId'] ?? 0);
            $val = trim(strip_tags($cf['CustomFieldValue'] ?? ''));
            if ($id === 8110) $duration = $val;
            if ($id === 8166) $language = $val;
        }

        if ($duration) $changed |= $set_if_changed('duration', $duration, $post_id);
        if ($language) $changed |= $set_if_changed('field_68e63bec980da', $language, $post_id);

        // Events
        $existing_events = get_field('events', $post_id) ?: [];
        $indexed  = [];
        foreach ($existing_events as $row) {
            if (!empty($row['eventid'])) $indexed[$row['eventid']] = $row;
        }

        $upcoming = [];
        foreach ($events_by_template[$template_id] ?? [] as $row) {
            $id = $row['eventid'] ?? null;
            if (!$id) continue;

            $is_new_event = !isset($indexed[$id]);
            $indexed[$id] = array_merge($indexed[$id] ?? [], $row);
            if ($is_new_event) $events_added++; else $events_updated++;

            $st = strtotime($row['startdate'] ?? '');
            if ($st && $st > time()) $upcoming[] = $st;
        }

        $changed |= $set_if_changed('events', array_values($indexed), $post_id);

        // Bestem Location ut fra events
        $events_sorted = array_values($indexed);
        usort($events_sorted, function($a, $b) {
            return strtotime($b['startdate'] ?? 0) - strtotime($a['startdate'] ?? 0);
        });

        $city_value = '';
        foreach ($events_sorted as $e) {
            $end = strtotime($e['enddate'] ?? '');
            $start = strtotime($e['startdate'] ?? '');
            if ($start > time()) { // kommende
                $city_value = $e['city'] ?? '';
                break;
            }
        }
        // Hvis ingen kommende, ta siste avholdte
        if (!$city_value && !empty($events_sorted)) {
            $city_value = $events_sorted[0]['city'] ?? '';
        }

        if ($city_value) {
            $changed |= $set_if_changed('field_68f61fd3e7a63', $city_value, $post_id);
        }

        if ($changed && !$is_new) $updated++;

        // Throttle
        $query_counter++;
        if ($query_counter % 50 === 0) usleep(200000);
    }

    // Cleanup
    $removed = eduadmin_cleanup_old_events(6);
    eduadmin_log_status("Course import finished", [
        'imported'       => $imported,
        'updated'        => $updated,
        'events_added'   => $events_added,
        'events_updated' => $events_updated,
        'events_removed' => $removed
    ]);
}






function eduadmin_cleanup_old_events($months = 6) {
    $cutoff = strtotime("-{$months} months", current_time('timestamp'));
    $removed = 0;

    $courses = get_posts([
        'post_type'      => 'course',
        'posts_per_page' => -1,
        'fields'         => 'ids'
    ]);

    foreach ($courses as $post_id) {
        $events = get_field('events', $post_id);
        if (!$events) continue;

        $new_events = [];
        foreach ($events as $ev) {
            $end = !empty($ev['enddate']) ? strtotime($ev['enddate']) : 0;
            if ($end > $cutoff) {
                $new_events[] = $ev;
            } else {
                $removed++;
            }
        }

        if (count($new_events) !== count($events)) {
            update_field('events', $new_events, $post_id);
        }
    }

    return $removed;
}

// === Logging & Status ===

function eduadmin_log_status($message, $stats = []) {
    $defaults = [
        'imported'       => 0,
        'updated'        => 0,
        'events_added'   => 0,
        'events_updated' => 0,
        'events_removed' => 0,
    ];
    $stats = array_merge($defaults, is_array($stats) ? $stats : []);

    $logs = get_option('ei_eduadmin_import_logs', []);
    if (!is_array($logs)) $logs = [];

    array_unshift($logs, [
        'time'  => current_time('mysql'),
        'msg'   => $message,
        'stats' => $stats,
    ]);

    update_option('ei_eduadmin_import_logs', array_slice($logs, 0, 50), false);
}


function eduadmin_render_status_html(){

    global $wpdb;

    $courses=$wpdb->get_var("SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type='course' AND post_status='publish'");

    $events=$wpdb->get_var("SELECT COUNT(meta_id) FROM {$wpdb->postmeta} WHERE meta_key LIKE 'events_%_eventid'");

    $now=current_time('timestamp');

    $rows=$wpdb->get_results("SELECT pm.meta_value,p.post_title FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON pm.post_id=p.ID WHERE pm.meta_key LIKE 'events_%_startdate' AND p.post_type='course' AND p.post_status='publish'");

    $next=null; foreach($rows as $r){$ts=strtotime($r->meta_value); if($ts>$now && (!$next||$ts<$next['ts']))$next=['ts'=>$ts,'name'=>$r->post_title];}

    $logs=get_option('ei_eduadmin_import_logs',[]); $last=$logs[0]??null;

    $last_manual=get_option('ei_eduadmin_last_manual_import');

    $next_cron=wp_get_scheduled_event('eduadmin_import_courses_event');

    ob_start();

    echo "<p><strong>Courses:</strong> $courses &nbsp; <strong>Events:</strong> $events</p>";

    echo "<p><strong>Next course event:</strong> ".($next?esc_html($next['name']).' – '.date_i18n(get_option('date_format'),$next['ts']):__('None','textdomain'))."</p>";

    if ($last) {
    $s = is_array($last['stats'] ?? null) ? $last['stats'] : [];
    $imported       = intval($s['imported']       ?? 0);
    $updated        = intval($s['updated']        ?? 0);
    $events_added   = intval($s['events_added']   ?? 0);
    $events_updated = intval($s['events_updated'] ?? 0);
    $events_removed = intval($s['events_removed'] ?? 0);

    echo "<p>{$last['msg']} – Courses imported: {$imported}, updated: {$updated} - "
       . "Events added: {$events_added}, updated: {$events_updated}, removed: {$events_removed}."
       . "</p><p><em>" . __('Last updated:', 'textdomain') . " "
       . date_i18n(get_option('date_format').' '.get_option('time_format'), strtotime($last['time']))
       . "</em></p>";
}

    if($last_manual) echo "<p><strong>".__('Last manual import:')."</strong> ".date_i18n(get_option('date_format').' '.get_option('time_format'),strtotime($last_manual))."</p>";

    if ($next_cron) { $fmt = get_option('date_format') . ' ' . get_option('time_format'); echo '<p><strong>' . __('Next scheduled import:', 'textdomain') . '</strong> ' .wp_date($fmt, $next_cron->timestamp) .'</p>'; }

    return ob_get_clean();

}



// === Dashboard Widget ===

add_action('wp_dashboard_setup',function(){

    wp_add_dashboard_widget('eduadmin_import_status',__('EduAdmin Course Import - Status'),'eduadmin_import_status_widget');

});

function eduadmin_import_status_widget(){

    echo '<div id="eduadmin-status-widget"><div id="eduadmin-status-content">'.eduadmin_render_status_html().'</div>';

    echo '<p><button type="button" class="button button-primary" id="eduadmin-manual-import">'.__('Run manual import now').'</button> ';

    echo '<span id="eduadmin-import-spinner" class="spinner is-active" style="display:none;"></span></p><div id="eduadmin-import-message"></div></div>';

}



// === AJAX & Scripts ===

add_action('wp_ajax_eduadmin_manual_import',function(){

    if(!current_user_can('manage_options')) wp_send_json_error('Permission denied');

    eduadmin_import_courses(false);

    update_option('ei_eduadmin_last_manual_import',current_time('mysql'));

    wp_send_json_success(['html'=>eduadmin_render_status_html()]);

});

add_action('admin_enqueue_scripts', function($hook){

    if ($hook !== 'index.php') return;



    // Last jQuery i admin

    wp_enqueue_script('jquery');



    // Et tomt "handle" å hekte inline JS på

    wp_register_script('eduadmin-js', false, ['jquery'], false, true);

    wp_enqueue_script('eduadmin-js');



    // Du kan bruke WordPress' innebygde 'ajaxurl' i admin, så vi slipper å localize

    $js = <<<'JS'

jQuery(function($){

  $(document).on('click','#eduadmin-manual-import',function(e){

    e.preventDefault();

    var $btn = $(this),

        $spinner = $('#eduadmin-import-spinner'),

        $msg = $('#eduadmin-import-message');



    $btn.prop('disabled', true);

    $spinner.show();

    $msg.empty();



    $.post(ajaxurl, { action: 'eduadmin_manual_import' }, function(r){

      $spinner.hide();

      $btn.prop('disabled', false);



      if (r && r.success) {

        $('#eduadmin-status-content').html(r.data.html);

        $msg.html('<div class="notice notice-success is-dismissible"><p>Import completed successfully.</p></div>');

      } else {

        var err = (r && r.data) ? r.data : 'Error';

        $msg.html('<div class="notice notice-error is-dismissible"><p>' + err + '</p></div>');

      }

    }).fail(function(){

      $spinner.hide();

      $btn.prop('disabled', false);

      $msg.html('<div class="notice notice-error is-dismissible"><p>AJAX request failed.</p></div>');

    });

  });

});

JS;



    wp_add_inline_script('eduadmin-js', $js);

});



function eduadmin_run_cron_import() { eduadmin_import_courses(false); }



// === CRON ===

add_filter('cron_schedules',function($s){$s['six_hours']=['interval'=>21600,'display'=>__('Every 6 Hours')];return $s;});

add_action('wp',function(){if(!wp_next_scheduled('eduadmin_import_courses_event'))wp_schedule_event(time(),'six_hours','eduadmin_import_courses_event');});

add_action('eduadmin_import_courses_event', 'eduadmin_run_cron_import');



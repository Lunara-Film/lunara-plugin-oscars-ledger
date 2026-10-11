<?php
/**
 * Real public Ledger routes rendered through the production templates.
 *
 * Run: php tests/fixtures/ledger-routes.php <route> [variant] [full]
 *
 *   ceremonies | categories | about     templates/hub-page.php index routes
 *   ceremony latest|early [full]        hub-page.php for the 98th or the 1st; "full" sets ?ledger=full
 *   category premium|generic [full]     hub-page.php for best-picture or sound; "full" sets ?history=full
 *   title poster|noposter               templates/entity-page.php
 *   name portrait|noportrait            templates/entity-page.php
 *   company                             templates/entity-page.php
 *   explorer                            tests/fixtures/editorial-surfaces.php explorer, unchanged
 *
 * Formatting, URL, visual-package and route methods are the production ones,
 * extracted from academy-awards-table.php the way tests/entity-category-labels-runtime.php
 * does. Data providers return fixture arrays in the production return shapes.
 * Every PHP warning or notice throws, and nothing may write a transient or an option.
 */
$route = (string) ($argv[1] ?? '');
$variant = (string) ($argv[2] ?? '');
$full = in_array('full', array_slice($argv, 2), true);

if ($route === 'explorer') {
    // A child process: this file's stubs are declared at compile time and would collide with the explorer runtime's own.
    $process = proc_open(array(PHP_BINARY, __DIR__ . '/editorial-surfaces.php', 'explorer'), array(1 => array('pipe', 'w'), 2 => STDERR), $pipes, null, null, array('bypass_shell' => true));
    echo stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    exit(proc_close($process));
}

set_error_handler(function ($severity, $message, $file, $line) {
    throw new RuntimeException("$message at $file:$line");
});

define('ABSPATH', __DIR__ . '/');
$root = dirname(__DIR__, 2);
define('AAT_PLUGIN_DIR', $root . '/');
define('AAT_PLUGIN_URL', 'https://example.test/wp-content/plugins/academy-awards-table/');
define('AAT_VERSION', 'fixture');
define('ARRAY_A', 'ARRAY_A');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);

function ledger_method_source($source, $name) {
    if (!preg_match('/\n\s*(?:public|private|protected) function ' . preg_quote($name, '/') . '\(/', $source, $match, PREG_OFFSET_CAPTURE)) {
        throw new RuntimeException('Missing production method: ' . $name);
    }
    $start = $match[0][1];
    $out = ''; $depth = 0; $opened = false;
    foreach (token_get_all('<?php ' . substr($source, $start)) as $token) {
        if (is_array($token)) { if ($token[0] !== T_OPEN_TAG) $out .= $token[1]; continue; }
        $out .= $token;
        if ($token === '{') { ++$depth; $opened = true; }
        if ($token === '}' && --$depth === 0 && $opened) return $out;
    }
    throw new RuntimeException('Unclosed production method: ' . $name);
}

// ---- WordPress ---------------------------------------------------------------
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function sanitize_title($value) { return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $value)), '-'); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $value)); }
function sanitize_html_class($value) { return preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $value); }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_attr($value) { return esc_html($value); }
function esc_url($value) { return esc_attr($value); }
function esc_url_raw($value) { return (string) $value; }
function __($value, $domain = '') { return $value; }
function esc_html__($value, $domain = '') { return esc_html($value); }
function esc_attr__($value, $domain = '') { return esc_attr($value); }
function esc_html_e($value, $domain = '') { echo esc_html($value); }
function esc_attr_e($value, $domain = '') { echo esc_attr($value); }
function _e($value, $domain = '') { echo $value; }
function _n($one, $many, $count, $domain = '') { return (int) $count === 1 ? $one : $many; }
function wp_kses_post($value) { return $value; }
function wp_parse_args($args, $defaults) { return array_merge($defaults, $args); }
function wp_unslash($value) { return $value; }
function wp_strip_all_tags($value) { return strip_tags((string) $value); }
function wp_list_pluck($list, $field) { return array_map(function ($row) use ($field) { return $row[$field]; }, (array) $list); }
function wp_trim_words($text, $num = 55, $more = '&hellip;') {
    $words = preg_split('/\s+/', trim((string) $text), -1, PREG_SPLIT_NO_EMPTY);
    return count($words) > $num ? implode(' ', array_slice($words, 0, $num)) . $more : implode(' ', $words);
}
function wpautop($text) {
    $paragraphs = array_filter(array_map('trim', preg_split('/\n\s*\n/', trim((string) $text))), 'strlen');
    return implode("\n", array_map(function ($p) { return '<p>' . str_replace("\n", "<br />\n", $p) . '</p>'; }, $paragraphs));
}
function number_format_i18n($value) { return number_format((float) $value); }
function absint($value) { return abs((int) $value); }
function trailingslashit($value) { return rtrim((string) $value, '/') . '/'; }
function home_url($path = '') { return 'https://example.test' . $path; }
function get_query_var($key) { return $GLOBALS['query'][$key] ?? ''; }
function get_theme_mod($key, $default = false) { return $default; }
function add_query_arg(...$args) {
    if (is_array($args[0])) { return $args[1] . '?' . http_build_query($args[0]); }
    $url = $args[2] ?? home_url('/oscars/');
    return $url . (strpos($url, '?') === false ? '?' : '&') . rawurlencode($args[0]) . '=' . rawurlencode($args[1]);
}
function remove_query_arg($key, $url = '') { return $url !== '' ? preg_replace('/\?.*/', '', $url) : home_url('/'); }
function get_header() { echo '<main id="main">'; }
function get_footer() { echo '</main>'; }
function add_action(...$args) {}
function add_filter(...$args) {}
function remove_filter(...$args) {}
function is_admin() { return false; }
function apply_filters($hook, $value, ...$args) {
    if ($hook === 'aat_hub_route_sections' || $hook === 'aat_entity_route_sections') { $GLOBALS['rendered_sections'] = $value; }
    return $value;
}
function get_transient($key) { return $GLOBALS['cache'][$key] ?? false; }
function set_transient(...$args) { throw new RuntimeException('Rendering must not write transients: ' . $args[0]); }
function delete_transient(...$args) { throw new RuntimeException('Rendering must not invalidate caches: ' . $args[0]); }
function get_option($key, $default = false) { return $default; }
function update_option(...$args) { throw new RuntimeException('Rendering must not write settings: ' . $args[0]); }
function add_option(...$args) { throw new RuntimeException('Rendering must not write settings: ' . $args[0]); }
function status_header($code) { throw new RuntimeException('The route resolved to a ' . $code . ' response'); }
function nocache_headers() { throw new RuntimeException('The route sent no-cache headers'); }
function wp_get_upload_dir() { return array('baseurl' => 'https://example.test/wp-content/uploads', 'basedir' => '/srv/uploads'); }
function wp_get_attachment_image_url($id, $size = 'medium') { return (int) $id > 0 ? 'https://img.example.test/' . (int) $id . '-' . $size . '.jpg' : false; }
function get_permalink($id) { return $GLOBALS['ledger']['reviews'][(int) $id]['url'] ?? ''; }
function get_the_title($id) { return $GLOBALS['ledger']['reviews'][(int) $id]['title'] ?? ''; }
function get_post_field($field, $id) { $review = $GLOBALS['ledger']['reviews'][(int) $id] ?? array(); return $field === 'post_excerpt' ? (string) ($review['excerpt'] ?? '') : (string) ($review['date'] ?? ''); }
function get_post_time($format, $gmt, $id) { return strtotime($GLOBALS['ledger']['reviews'][(int) $id]['date'] ?? '') ?: 0; }
function get_the_post_thumbnail_url($id, $size = 'post-thumbnail') {
    return !empty($GLOBALS['ledger']['reviews'][(int) $id]['thumb']) ? 'https://img.example.test/review-' . (int) $id . '-' . $size . '.jpg' : false;
}
function get_the_post_thumbnail($id, $size = 'post-thumbnail', $attrs = array()) {
    $url = get_the_post_thumbnail_url($id, $size);
    if (!$url) { return ''; }
    $html = '<img width="768" height="480" src="' . esc_url($url) . '" alt="' . esc_attr(get_the_title($id)) . '"';
    foreach ((array) $attrs as $key => $value) { $html .= ' ' . $key . '="' . esc_attr($value) . '"'; }
    return $html . ' />';
}

class Ledger_Routes_WPDB {
    public $prefix = 'wp_';
    public function get_var($sql) {
        $values = array(
            'SELECT COUNT(DISTINCT ceremony) FROM wp_academy_awards' => 98,
            'SELECT MIN(ceremony) FROM wp_academy_awards' => 1,
            'SELECT MAX(ceremony) FROM wp_academy_awards' => 98,
            'SELECT COUNT(*) FROM wp_academy_awards' => 12480,
            'SELECT COUNT(*) FROM wp_academy_awards WHERE winner = 1' => 2914,
            "SELECT COUNT(DISTINCT canonical_category) FROM wp_academy_awards WHERE canonical_category != ''" => count($GLOBALS['ledger']['category_classes']),
        );
        if (!array_key_exists($sql, $values)) { throw new RuntimeException('Unexpected scalar query: ' . $sql); }
        return $values[$sql];
    }
    public function prepare($sql, $value) {
        if ($sql !== 'SELECT DISTINCT ceremony FROM wp_academy_awards WHERE year = %s ORDER BY ceremony ASC') {
            throw new RuntimeException('Unexpected prepared query: ' . $sql);
        }
        return array('sql' => $sql, 'year' => $value);
    }
    public function get_col($prepared) {
        if (!is_array($prepared) || !isset($prepared['year'])) { throw new RuntimeException('Year query must be prepared'); }
        return array('2025' => array('98'), '1930' => array('3', '4'), 'unknown' => array())[$prepared['year']] ?? array();
    }
    public function get_results($sql, $output = ARRAY_A) {
        $ledger = $GLOBALS['ledger'];
        if (strpos($sql, 'GROUP BY ceremony, film, film_id') !== false) { return $ledger['index_top_titles']; }
        if (strpos($sql, 'GROUP BY ceremony ORDER BY ceremony DESC') !== false) { return $ledger['index_ceremonies']; }
        if (strpos($sql, "canonical_category = 'BEST PICTURE'") !== false) { return $ledger['index_best_pictures']; }
        if (strpos($sql, 'GROUP BY canonical_category ORDER BY MIN(class)') !== false) { return $ledger['index_categories']; }
        if (strpos($sql, 'latest_ceremony') !== false) { return $ledger['index_latest_winners']; }
        throw new RuntimeException('Unexpected query: ' . $sql);
    }
    public function __call($name, $args) { throw new RuntimeException('Unexpected $wpdb->' . $name . '()'); }
}
$wpdb = new Ledger_Routes_WPDB();

// ---- Registries ----------------------------------------------------------------
function ledger_year($ceremony) {
    $ceremony = (int) $ceremony;
    if ($ceremony <= 0) { return ''; }
    return $ceremony <= 6 ? sprintf('%d/%02d', 1926 + $ceremony, (1927 + $ceremony) % 100) : (string) (1927 + $ceremony);
}
function ledger_tt($ceremony, $slot) { return sprintf('tt%04d%03d', $ceremony, $slot); }
function ledger_title($tt) {
    static $heads = array('The Long Night', 'Salt Cathedral', 'Nocturne', 'Meridian', 'Paper Lanterns', 'Eleven Winters', 'The Quiet Engine', 'Dust', 'Kingdom of Small Hours', 'The Cartographer', 'Harbour Lights', 'A Season of Glass', 'The Orchard Gate', 'Lantern Country', 'Iron Lullaby', 'The Velvet Ledger', 'Northern Silence', 'The Last Signal', 'Clockwork Vespers', 'The Tide Keeper');
    static $tails = array('of the Ledger', 'over Kowloon', 'for the Harbour Lights', 'and the Dreaming Machine', 'at the Edge of Winter', 'Beneath the Studio Lights', 'in Three Movements', 'of Small Hours', 'and Her Daughter', 'Without a Country', 'on the Western Front', 'of the Dawn Patrol', 'After the Flood', 'in the House of Mirrors', 'of the Silver Reel');
    $tt = strtolower(trim((string) $tt));
    if (isset($GLOBALS['ledger']['title_overrides'][$tt])) { return $GLOBALS['ledger']['title_overrides'][$tt]; }
    if (!preg_match('/^tt(\d{4})(\d{3})$/', $tt, $m)) { return array(); }
    $ceremony = (int) $m[1]; $slot = (int) $m[2];
    return array(
        'title' => $heads[($ceremony * 7 + $slot) % count($heads)] . ' ' . $tails[($ceremony * 3 + $slot * 5) % count($tails)],
        'year' => (string) (1927 + $ceremony),
        'art' => $ceremony <= 10 ? 'none' : array('local', 'tmdb', 'none')[$slot % 3],
    );
}
function ledger_nm($index) { return sprintf('nm%07d', 98000 + $index); }
function ledger_person_name($index) {
    static $first = array('Ingrid', 'Tobias', 'Chiara', 'Rasmus', 'Yuki', 'Desmond', 'Marisol', 'Theodore', 'Priya', 'Augustin', 'Solveig', 'Benedict', 'Harriet', 'Olamide', 'Simone', 'Gareth', 'Emil', 'Janet', 'Frank', 'Joseph');
    static $last = array('Vasquez-Halloran', 'Adeyemi', 'Montefiore', 'Oyelaran-Whitford', 'Delacroix', 'Achterberg', 'Everingham-Kaur', 'Anand-Lindqvist', 'Castellanos', 'Ferreira-Nakagawa', 'Bergström-Nakamura', 'Marchetti', 'Okonkwo-Lindqvist', 'Fitzgerald-Ruiz', 'Llewellyn-Okafor', 'Janssen-Wettergren', 'Gaynor-Lindstrom', 'Borzage-Halloran', 'Farnham-Oyelowo', 'Whitcombe');
    return $first[$index % 20] . ' ' . $last[($index * 7 + 3) % 20];
}
function ledger_humanize($pipe) {
    $values = array_values(array_filter(array_map('trim', explode('|', (string) $pipe)), 'strlen'));
    if (count($values) <= 1) { return (string) ($values[0] ?? ''); }
    $last = array_pop($values);
    return implode(', ', $values) . ' and ' . $last;
}
function ledger_row($ceremony, $category, $film_ids, $nominees, $nominee_ids, $winner, $detail = '', $name = null, $note = '') {
    $film_ids = array_values(array_filter((array) $film_ids, 'strlen'));
    $films = array_map(function ($tt) { return ledger_title($tt)['title']; }, $film_ids);
    return array(
        'ceremony' => (int) $ceremony,
        'year' => ledger_year($ceremony),
        'class' => $GLOBALS['ledger']['category_classes'][$category] ?? 'Other',
        'canonical_category' => $category,
        'category' => $category,
        'film' => implode('|', $films),
        'film_id' => implode('|', $film_ids),
        'name' => $name === null ? ledger_humanize($nominees) : $name,
        'nominees' => (string) $nominees,
        'nominee_ids' => (string) $nominee_ids,
        'winner' => $winner ? 1 : 0,
        'detail' => (string) $detail,
        'note' => (string) $note,
        'citation' => '',
    );
}

$ledger = array();
$ledger['category_classes'] = array(
    'BEST PICTURE' => 'Title', 'INTERNATIONAL FEATURE FILM' => 'Title', 'ANIMATED FEATURE FILM' => 'Title', 'DOCUMENTARY (Feature)' => 'Title',
    'SHORT FILM (Live Action)' => 'Title', 'UNIQUE AND ARTISTIC PICTURE' => 'Title',
    'DIRECTING' => 'Directing',
    'ACTOR IN A LEADING ROLE' => 'Acting', 'ACTRESS IN A LEADING ROLE' => 'Acting', 'ACTOR IN A SUPPORTING ROLE' => 'Acting', 'ACTRESS IN A SUPPORTING ROLE' => 'Acting',
    'WRITING (Original Screenplay)' => 'Writing', 'WRITING (Adapted Screenplay)' => 'Writing', 'WRITING (Title Writing)' => 'Writing',
    'CINEMATOGRAPHY' => 'Production', 'SOUND MIXING' => 'Production', 'FILM EDITING' => 'Production', 'ART DIRECTION' => 'Production',
    'COSTUME DESIGN' => 'Production', 'VISUAL EFFECTS' => 'Production', 'ENGINEERING EFFECTS' => 'Production',
    'SOUND RECORDING AND ORIGINAL DRAMATIC SCORE' => 'Music', 'MUSIC (Scoring of a Dramatic or Comedy Picture)' => 'Music', 'MUSIC (Original Score)' => 'Music', 'MUSIC (Original Song)' => 'Music',
    'HONORARY AWARD' => 'Special',
);
$ledger['title_overrides'] = array(
    'tt0098000' => array('title' => 'The Long Night of the Ledger: A Chronicle of Everything That Was Nominated and Won', 'year' => '2025', 'art' => 'local'),
    'tt0098001' => array('title' => 'Salt Cathedral', 'year' => '2025', 'art' => 'tmdb'),
    'tt0098002' => array('title' => 'Nocturne for the Harbour Lights', 'year' => '2025', 'art' => 'local'),
    'tt0098003' => array('title' => "The Cartographer's Daughter", 'year' => '2025', 'art' => 'none'),
    'tt0098004' => array('title' => 'Kingdom of Small Hours', 'year' => '2025', 'art' => 'tmdb'),
    'tt0098005' => array('title' => 'Paper Lanterns Over Kowloon', 'year' => '2025', 'art' => 'none'),
    'tt0098006' => array('title' => 'Eleven Winters', 'year' => '2025', 'art' => 'local'),
    'tt0098007' => array('title' => 'The Quiet Engine', 'year' => '2025', 'art' => 'tmdb'),
    'tt0098008' => array('title' => 'Meridian', 'year' => '2025', 'art' => 'none'),
    'tt0098009' => array('title' => 'Dust and the Dreaming Machine', 'year' => '2025', 'art' => 'local'),
    'tt0001001' => array('title' => 'Wings of the Dawn Patrol over the Western Front and the Long Road Home', 'year' => '1927', 'art' => 'none'),
    'tt0001002' => array('title' => 'The Racket of Seventh Avenue', 'year' => '1928', 'art' => 'none'),
    'tt0001003' => array('title' => 'Seventh Heaven of the Street Angel', 'year' => '1927', 'art' => 'none'),
    'tt0001004' => array('title' => 'The Last Command', 'year' => '1928', 'art' => 'none'),
    'tt0001005' => array('title' => 'The Way of All Flesh', 'year' => '1927', 'art' => 'none'),
    'tt0001006' => array('title' => 'Sunrise: A Song of Two Humans and the Unique and Artistic Picture of the Year', 'year' => '1927', 'art' => 'none'),
    'tt0001007' => array('title' => 'The Crowd', 'year' => '1928', 'art' => 'none'),
    'tt0001008' => array('title' => 'Chang: A Drama of the Wilderness', 'year' => '1927', 'art' => 'none'),
);
$ledger['people'] = array(
    'nm0098001' => 'Harriet Okonkwo-Lindqvist', 'nm0098002' => 'Benedict Marchetti', 'nm0098003' => 'Solveig Anand-Lindqvist',
    'nm0098010' => 'Ingrid Maximiliana Vasquez-Halloran de la Fuente', 'nm0098011' => 'Tobias Adeyemi', 'nm0098012' => 'Chiara Montefiore', 'nm0098013' => 'Rasmus Oyelaran-Whitford', 'nm0098014' => 'Yuki Delacroix',
    'nm0098020' => 'Desmond Achterberg', 'nm0098021' => 'Lucan Oyelaran-Whitford', 'nm0098022' => 'Emil Janssen-Wettergren III', 'nm0098023' => 'Augustin Ferreira-Nakagawa', 'nm0098024' => 'Joseph Farnham-Oyelowo',
    'nm0098030' => 'Marisol Everingham-Kaur', 'nm0098031' => 'Janet Gaynor-Lindstrom', 'nm0098032' => 'Chiara Delacroix-Whitcombe', 'nm0098033' => 'Priya Montefiore', 'nm0098034' => 'Solveig Borzage-Halloran',
    'nm0098040' => 'Theodore Anand-Lindqvist', 'nm0098041' => 'Priya Castellanos', 'nm0098042' => 'Rasmus Marchetti', 'nm0098043' => 'Harriet Achterberg', 'nm0098044' => 'Olamide Fitzgerald-Ruiz',
    'nm0098050' => 'Gareth Llewellyn-Okafor', 'nm0098051' => 'Simone Bergström-Nakamura', 'nm0098052' => 'Olamide Fitzgerald-Ruiz', 'nm0098053' => 'Frank Castellanos', 'nm0098054' => 'Yuki Okonkwo-Lindqvist',
    'nm0098060' => 'Augustin Ferreira-Nakagawa', 'nm0098061' => 'Ingrid Adeyemi', 'nm0098062' => 'Tobias Everingham-Kaur', 'nm0098063' => 'Desmond Montefiore', 'nm0098064' => 'Emil Llewellyn-Okafor',
    'nm0098070' => 'Ada Lindqvist-Mbeki', 'nm0098071' => 'Benedict Gaynor-Lindstrom', 'nm0098072' => 'Marisol Farnham-Oyelowo', 'nm0098073' => 'Joseph Bergström-Nakamura', 'nm0098074' => 'Harriet Delacroix',
    'nm0001001' => 'Emil Janssen-Wettergren', 'nm0001002' => 'Richard Barthelmess-Whitcombe', 'nm0001003' => 'Janet Gaynor-Lindstrom', 'nm0001004' => 'Gloria Swanson-Marchetti',
    'nm0001005' => 'Louise Dresser-Oyelowo', 'nm0001006' => 'Frank Borzage-Halloran', 'nm0001007' => 'Herbert Brenon-Castellanos', 'nm0001008' => 'Joseph Farnham-Oyelowo',
    'nm0001009' => 'Roy Pomeroy-Lindqvist', 'nm0001010' => 'Ralph Hammeras-Nakamura', 'nm0001011' => 'Lucien Hubbard-Okafor', 'nm0001012' => 'Paul Bern-Whitford',
);
for ($i = 100; $i < 160; $i++) { $ledger['people'][ledger_nm($i)] = ledger_person_name($i); }
$ledger['portraits'] = array('nm0098010' => 'local', 'nm0098020' => 'tmdb', 'nm0098030' => 'local', 'nm0098040' => 'none', 'nm0098011' => 'tmdb', 'nm0098060' => 'local');
$ledger['companies'] = array('co0000500' => 'Pinewood Studio Sound Department', 'co0000501' => 'Lantern & Lindqvist Sound Services Incorporated');
$ledger['reviews'] = array(
    501 => array('title' => "The Long Night of the Ledger Is the Year's Most Complete Argument", 'url' => 'https://example.test/reviews/the-long-night-of-the-ledger/', 'date' => '2026-03-02 10:00:00', 'thumb' => true, 'excerpt' => 'A three-hour film that earns every minute, and a Best Picture win that reads as a verdict on the whole season.'),
    502 => array('title' => 'A Second Look at The Long Night of the Ledger', 'url' => 'https://example.test/reviews/the-long-night-second-look/', 'date' => '2026-01-14 09:00:00', 'thumb' => false),
    503 => array('title' => 'Nocturne for the Harbour Lights Keeps Its Promises', 'url' => 'https://example.test/reviews/nocturne-for-the-harbour-lights/', 'date' => '2025-11-20 12:00:00', 'thumb' => true),
    504 => array('title' => 'Eleven Winters, One Performance', 'url' => 'https://example.test/reviews/eleven-winters/', 'date' => '2025-12-05 08:00:00', 'thumb' => false),
    505 => array('title' => 'The Orchard Gate, Revisited', 'url' => 'https://example.test/reviews/the-orchard-gate/', 'date' => '2023-03-01 08:00:00', 'thumb' => true, 'excerpt' => 'Five years on, the adapted screenplay nomination looks like the quiet achievement of that ceremony.'),
    506 => array('title' => 'Salt Cathedral and the Weight of Light', 'url' => 'https://example.test/reviews/salt-cathedral/', 'date' => '2026-02-09 08:00:00', 'thumb' => true),
);
$ledger['review_ids'] = array('tt0098000' => array(501, 502), 'tt0098002' => array(503), 'tt0098006' => array(504), 'tt0095001' => array(505), 'tt0098001' => array(506));
$GLOBALS['ledger'] = $ledger;

// ---- The 98th: eight full races, five nominees each --------------------------------
$sound_department = 'Pinewood Studio Sound Department';
$ballot98 = array(
    'BEST PICTURE' => array(
        array('tt0098000', 'Harriet Okonkwo-Lindqvist|Benedict Marchetti|Solveig Anand-Lindqvist', 'nm0098001|nm0098002|nm0098003', '', 'Harriet Okonkwo-Lindqvist, Benedict Marchetti and Solveig Anand-Lindqvist, Producers'),
        array('tt0098001', 'Chiara Montefiore|Rasmus Oyelaran-Whitford', 'nm0098012|nm0098013', '', 'Chiara Montefiore and Rasmus Oyelaran-Whitford, Producers'),
        array('tt0098002', 'Tobias Adeyemi', 'nm0098011', '', 'Tobias Adeyemi, Producer'),
        array('tt0098003', 'Priya Castellanos|Theodore Anand-Lindqvist|Yuki Delacroix|Olamide Fitzgerald-Ruiz', 'nm0098041|nm0098040|nm0098014|nm0098044', '', 'Priya Castellanos, Theodore Anand-Lindqvist, Yuki Delacroix and Olamide Fitzgerald-Ruiz, Producers'),
        array('tt0098004', 'Simone Bergström-Nakamura', 'nm0098051', '', 'Simone Bergström-Nakamura, Producer'),
    ),
    'DIRECTING' => array(
        array('tt0098000', 'Ingrid Maximiliana Vasquez-Halloran de la Fuente', 'nm0098010'),
        array('tt0098001', 'Tobias Adeyemi', 'nm0098011'),
        array('tt0098002', 'Chiara Montefiore', 'nm0098012'),
        array('tt0098003', 'Rasmus Oyelaran-Whitford', 'nm0098013'),
        array('tt0098004', 'Yuki Delacroix', 'nm0098014'),
    ),
    'ACTOR IN A LEADING ROLE' => array(
        array('tt0098005', 'Desmond Achterberg', 'nm0098020'),
        array('tt0098000', 'Lucan Oyelaran-Whitford', 'nm0098021'),
        array('tt0098001', 'Emil Janssen-Wettergren III', 'nm0098022'),
        array('tt0098002', 'Augustin Ferreira-Nakagawa', 'nm0098023'),
        array('tt0098004', 'Joseph Farnham-Oyelowo', 'nm0098024'),
    ),
    'ACTRESS IN A LEADING ROLE' => array(
        array('tt0098006', 'Marisol Everingham-Kaur', 'nm0098030'),
        array('tt0098000', 'Janet Gaynor-Lindstrom', 'nm0098031'),
        array('tt0098003', 'Chiara Delacroix-Whitcombe', 'nm0098032'),
        array('tt0098001', 'Priya Montefiore', 'nm0098033'),
        array('tt0098005', 'Solveig Borzage-Halloran', 'nm0098034'),
    ),
    'WRITING (Original Screenplay)' => array(
        array('tt0098003', 'Theodore Anand-Lindqvist|Priya Castellanos', 'nm0098040|nm0098041', '', 'Written by Theodore Anand-Lindqvist and Priya Castellanos'),
        array('tt0098000', 'Ingrid Maximiliana Vasquez-Halloran de la Fuente', 'nm0098010', '', 'Written by Ingrid Maximiliana Vasquez-Halloran de la Fuente'),
        array('tt0098005', 'Rasmus Marchetti|Harriet Achterberg|Olamide Fitzgerald-Ruiz', 'nm0098042|nm0098043|nm0098044', '', 'Screenplay by Rasmus Marchetti, Harriet Achterberg and Olamide Fitzgerald-Ruiz'),
        array('tt0098006', 'Chiara Montefiore', 'nm0098012', '', 'Written by Chiara Montefiore'),
        array('tt0098008', 'Yuki Delacroix|Tobias Adeyemi', 'nm0098014|nm0098011', '', 'Written by Yuki Delacroix and Tobias Adeyemi'),
    ),
    'CINEMATOGRAPHY' => array(
        array('tt0098009', 'Augustin Ferreira-Nakagawa', 'nm0098060'),
        array('tt0098000', 'Ingrid Adeyemi', 'nm0098061'),
        array('tt0098001', 'Tobias Everingham-Kaur', 'nm0098062'),
        array('tt0098002', 'Desmond Montefiore', 'nm0098063'),
        array('tt0098007', 'Emil Llewellyn-Okafor', 'nm0098064'),
    ),
    'SOUND MIXING' => array(
        array('tt0098007', $sound_department . '|Gareth Llewellyn-Okafor|Simone Bergström-Nakamura|Olamide Fitzgerald-Ruiz', 'co0000500|nm0098050|nm0098051|nm0098052', '', $sound_department . ', Gareth Llewellyn-Okafor, Simone Bergström-Nakamura and Olamide Fitzgerald-Ruiz, Sound Directors'),
        array('tt0098000', 'Lantern & Lindqvist Sound Services Incorporated|Frank Castellanos|Yuki Okonkwo-Lindqvist', 'co0000501|nm0098053|nm0098054', '', 'Lantern & Lindqvist Sound Services Incorporated, Frank Castellanos and Yuki Okonkwo-Lindqvist, Sound Directors'),
        array('tt0098002', 'Gareth Llewellyn-Okafor|Frank Castellanos', 'nm0098050|nm0098053'),
        array('tt0098004', 'Simone Bergström-Nakamura|Yuki Okonkwo-Lindqvist|Olamide Fitzgerald-Ruiz', 'nm0098051|nm0098054|nm0098052'),
        array('tt0098009', 'Lantern & Lindqvist Sound Services Incorporated', 'co0000501'),
    ),
    'MUSIC (Original Song)' => array(
        array('tt0098008', 'Ada Lindqvist-Mbeki', 'nm0098070', 'Lantern Song', 'Music and Lyric by Ada Lindqvist-Mbeki'),
        array('tt0098000', 'Benedict Gaynor-Lindstrom|Marisol Farnham-Oyelowo', 'nm0098071|nm0098072', 'The Long Night (End Titles)', 'Music by Benedict Gaynor-Lindstrom; Lyric by Marisol Farnham-Oyelowo'),
        array('tt0098005', 'Joseph Bergström-Nakamura', 'nm0098073', 'Kowloon Lullaby', 'Music and Lyric by Joseph Bergström-Nakamura'),
        array('tt0098006', 'Harriet Delacroix', 'nm0098074', 'Eleven Winters, One Spring', 'Music and Lyric by Harriet Delacroix'),
        array('tt0098001', 'Ada Lindqvist-Mbeki|Benedict Gaynor-Lindstrom', 'nm0098070|nm0098071', 'Salt and Light', 'Music by Ada Lindqvist-Mbeki; Lyric by Benedict Gaynor-Lindstrom'),
    ),
);
$ballots = array(98 => array());
foreach ($ballot98 as $category => $entries) {
    foreach ($entries as $index => $entry) {
        $ballots[98][$category][] = ledger_row(98, $category, array($entry[0]), $entry[1], $entry[2], $index === 0, $entry[3] ?? '', $entry[4] ?? null);
    }
}

// ---- The 1st: sparse, no artwork, long names ----------------------------------------
$ballots[1] = array(
    'BEST PICTURE' => array(
        ledger_row(1, 'BEST PICTURE', array('tt0001001'), 'Lucien Hubbard-Okafor', 'nm0001011', true, '', 'Lucien Hubbard-Okafor, Producer'),
        ledger_row(1, 'BEST PICTURE', array('tt0001002'), 'Howard Hughes-Castellanos', '', false, '', 'The Caddo Company, Howard Hughes-Castellanos, Producer'),
        ledger_row(1, 'BEST PICTURE', array('tt0001003'), 'Fox Film Corporation of the United States of America', '', false, '', 'Fox Film Corporation of the United States of America'),
    ),
    'DIRECTING' => array(
        ledger_row(1, 'DIRECTING', array('tt0001003'), 'Frank Borzage-Halloran', 'nm0001006', true),
        ledger_row(1, 'DIRECTING', array('tt0001007'), 'Herbert Brenon-Castellanos', 'nm0001007', false),
    ),
    'ACTOR IN A LEADING ROLE' => array(
        ledger_row(1, 'ACTOR IN A LEADING ROLE', array('tt0001004', 'tt0001005'), 'Emil Janssen-Wettergren', 'nm0001001', true),
        ledger_row(1, 'ACTOR IN A LEADING ROLE', array('tt0001007'), 'Richard Barthelmess-Whitcombe', 'nm0001002', false),
    ),
    'ACTRESS IN A LEADING ROLE' => array(
        ledger_row(1, 'ACTRESS IN A LEADING ROLE', array('tt0001003', 'tt0001006'), 'Janet Gaynor-Lindstrom', 'nm0001003', false),
        ledger_row(1, 'ACTRESS IN A LEADING ROLE', array('tt0001002'), 'Gloria Swanson-Marchetti', 'nm0001004', false),
        ledger_row(1, 'ACTRESS IN A LEADING ROLE', array('tt0001008'), 'Louise Dresser-Oyelowo', 'nm0001005', false),
    ),
    'WRITING (Title Writing)' => array(
        ledger_row(1, 'WRITING (Title Writing)', array(), 'Joseph Farnham-Oyelowo', 'nm0001008', true, '', 'Joseph Farnham-Oyelowo', 'Awarded for the body of title writing in the year, not for a single film.'),
    ),
    'ENGINEERING EFFECTS' => array(
        ledger_row(1, 'ENGINEERING EFFECTS', array('tt0001001'), 'Roy Pomeroy-Lindqvist', 'nm0001009', true),
        ledger_row(1, 'ENGINEERING EFFECTS', array('tt0001008'), 'Ralph Hammeras-Nakamura', 'nm0001010', false),
    ),
    'UNIQUE AND ARTISTIC PICTURE' => array(
        ledger_row(1, 'UNIQUE AND ARTISTIC PICTURE', array('tt0001006'), 'Fox Film Corporation of the United States of America', '', true, '', 'Fox Film Corporation of the United States of America'),
        ledger_row(1, 'UNIQUE AND ARTISTIC PICTURE', array('tt0001007'), 'Metro-Goldwyn-Mayer', '', false, '', 'Metro-Goldwyn-Mayer'),
    ),
);
$ledger['ballots'] = $ballots;
$ledger['review_map'] = array();
foreach ($ballots as $ceremony => $groups) {
    foreach ($groups as $rows) {
        foreach ($rows as $row) {
            foreach (explode('|', $row['film_id']) as $tt) {
                if (!empty($ledger['review_ids'][$tt])) { $ledger['review_map'][$ceremony][$tt] = $ledger['reviews'][$ledger['review_ids'][$tt][0]]['url']; }
            }
        }
    }
}
$ledger['summaries'] = array(
    98 => array('newer_ceremony' => 0, 'older_ceremony' => 97),
    1 => array('newer_ceremony' => 2, 'older_ceremony' => 0),
);
$ledger['writeups'] = array(98 => array(
    'ceremony_number' => 98,
    'ceremony_label' => '98th Academy Awards',
    'headline' => 'The night the ledger finally caught up with the long film',
    'dek' => 'A guide to the 98th ceremony, race by race, for readers who want the argument before the list.',
    'body' => "The 98th ceremony was decided early, and the file reads that way: The Long Night of the Ledger took Picture, Director and Cinematography inside the first hour, and the rest of the evening was spent working out what that meant for the crafts.\n\nThe acting races went elsewhere. Desmond Achterberg's win for Paper Lanterns Over Kowloon was the night's one real surprise, and Marisol Everingham-Kaur's for Eleven Winters was the night's one certainty; the guide below walks both fields nominee by nominee.\n\nOriginal Song and Sound are where the ceremony's long credits live, and they are printed in full here because the record is the point: every name, every department, every film, in the order the Academy gave them.",
));

// ---- Category ledgers: every decade from the 1920s to the 2020s ---------------------
function ledger_category_ceremony($category, $ceremony, $winner_slot) {
    $people = $GLOBALS['ledger']['people'];
    $credit = function ($seed, $count, $suffix = '') use ($people) {
        $labels = array(); $ids = array();
        for ($j = 0; $j < $count; $j++) {
            $id = ledger_nm(100 + (($seed * 5 + $j * 7) % 60));
            $labels[] = $people[$id]; $ids[] = $id;
        }
        return array(implode('|', $labels), implode('|', $ids), ledger_humanize(implode('|', $labels)) . $suffix);
    };
    $nominee_count = 2 + ($ceremony % 3);
    $rows = array('winner_rows' => array(), 'nominee_rows' => array());
    for ($slot = 0; $slot <= $nominee_count; $slot++) {
        $tt = ledger_tt($ceremony, $slot);
        $is_winner = $slot === $winner_slot;
        if ($category === 'SOUND MIXING') {
            $department = $slot % 2 === 0 ? 'Pinewood Studio Sound Department|' : 'Lantern & Lindqvist Sound Services Incorporated|';
            $department_id = $slot % 2 === 0 ? 'co0000500|' : 'co0000501|';
            list($labels, $ids, $name) = $credit($ceremony + $slot, 1 + (($ceremony + $slot) % 3), ', Sound Directors');
            $row = ledger_row($ceremony, $category, array($tt), $department . $labels, $department_id . $ids, $is_winner, '', str_replace('|', ', ', $department) . $name);
        } else {
            list($labels, $ids, $name) = $credit($ceremony * 3 + $slot, 1 + (($ceremony + $slot) % 3), $ceremony >= 24 ? (strpos($labels ?? '', '|') !== false ? ', Producers' : ', Producer') : '');
            $row = ledger_row($ceremony, $category, array($tt), $labels, $ids, $is_winner, '', $name);
        }
        $rows[$is_winner ? 'winner_rows' : 'nominee_rows'][] = $row;
    }
    return array('ceremony' => $ceremony, 'year' => ledger_year($ceremony), 'winner_rows' => $rows['winner_rows'], 'nominee_rows' => $rows['nominee_rows']);
}
$ledger['category_ceremonies'] = array(
    'BEST PICTURE' => array(98, 97, 96, 90, 88, 85, 80, 77, 70, 67, 65, 60, 57, 50, 47, 45, 40, 37, 30, 27, 25, 20, 17, 15, 10, 8, 6, 2, 1),
    'SOUND MIXING' => array(98, 97, 95, 93, 90, 88, 80, 76, 70, 66, 60, 55, 50, 44, 40, 35, 30, 26, 20, 16, 10, 7, 3),
);
$ledger['category_ledgers'] = array();
foreach ($ledger['category_ceremonies'] as $category => $ceremonies) {
    foreach ($ceremonies as $ceremony) {
        $ledger['category_ledgers'][$category][$ceremony] = isset($ballots[$ceremony][$category])
            ? array('ceremony' => $ceremony, 'year' => ledger_year($ceremony),
                'winner_rows' => array_values(array_filter($ballots[$ceremony][$category], function ($r) { return !empty($r['winner']); })),
                'nominee_rows' => array_values(array_filter($ballots[$ceremony][$category], function ($r) { return empty($r['winner']); })))
            : ledger_category_ceremony($category, $ceremony, $category === 'SOUND MIXING' ? 1 : 0);
    }
}

// ---- Index rows the hub template reads through $wpdb --------------------------------
$ledger['index_ceremonies'] = array();
$ledger['index_best_pictures'] = array();
$ledger['index_top_titles'] = array();
foreach (array(98, 97, 96, 95, 94, 90, 80, 70, 60, 50, 40, 30, 20, 10, 6, 2, 1) as $ceremony) {
    $ledger['index_ceremonies'][] = array('ceremony' => (string) $ceremony, 'year_label' => ledger_year($ceremony), 'nominations' => (string) (12 + $ceremony % 7 * 5), 'wins' => (string) (3 + $ceremony % 5 * 4), 'categories' => (string) (3 + $ceremony % 5 * 4));
    $tt = $ceremony === 1 ? 'tt0001001' : ledger_tt($ceremony, 0);
    $ledger['index_best_pictures'][] = array('ceremony' => (string) $ceremony, 'film' => ledger_title($tt)['title'], 'film_id' => $tt);
    // Most-awarded title per ceremony: the 1st and the 10th have no title with two wins.
    $top = $ceremony === 1 || $ceremony === 10 ? 1 : 2 + $ceremony % 4;
    $ledger['index_top_titles'][] = array('ceremony' => (string) $ceremony, 'film' => ledger_title($tt)['title'], 'film_id' => $tt, 'wins' => (string) $top);
    $ledger['index_top_titles'][] = array('ceremony' => (string) $ceremony, 'film' => ledger_title(ledger_tt($ceremony, 2))['title'], 'film_id' => ledger_tt($ceremony, 2), 'wins' => '1');
}
$ledger['index_categories'] = array();
$ledger['index_latest_winners'] = array();
$slot = 0;
foreach ($ledger['category_classes'] as $category => $class) {
    $first = isset($ledger['category_ceremonies'][$category]) ? min($ledger['category_ceremonies'][$category]) : 1 + $slot % 40;
    $ledger['index_categories'][] = array('canonical_category' => $category, 'class_label' => $class, 'first_year' => ledger_year($first), 'last_year' => ledger_year(98), 'ceremonies' => (string) (99 - $first));
    $latest = isset($ballots[98][$category]) ? $ballots[98][$category][0] : ledger_row(90, $category, array(ledger_tt(90, $slot % 5)), ledger_person_name(120 + $slot), ledger_nm(120 + $slot), true);
    $ledger['index_latest_winners'][] = array('canonical_category' => $category, 'year' => $latest['year'], 'film' => $latest['film'], 'film_id' => $latest['film_id'], 'name' => $latest['name']);
    $slot++;
}
if ($route === 'categories' && $variant === 'past-spans') {
    foreach ($ledger['index_categories'] as &$category_record) {
        if ($category_record['canonical_category'] === 'BEST PICTURE') {
            $category_record['first_year'] = '1927/28';
            $category_record['last_year'] = '2024';
            $category_record['ceremonies'] = '97';
        } elseif ($category_record['canonical_category'] === 'HONORARY AWARD') {
            $category_record['first_year'] = '1927/28';
            $category_record['last_year'] = '2022';
            $category_record['ceremonies'] = '83';
        }
    }
    unset($category_record);
}
$ledger['slug_map'] = array();
foreach (array_keys($ledger['category_classes']) as $category) { $ledger['slug_map'][sanitize_title($category)] = $category; }

// ---- Entity datasets ----------------------------------------------------------------
$ledger['entities'] = array(
    'title:tt0098000' => array(
        $ballots[98]['BEST PICTURE'][0], $ballots[98]['DIRECTING'][0], $ballots[98]['ACTOR IN A LEADING ROLE'][1], $ballots[98]['ACTRESS IN A LEADING ROLE'][1],
        $ballots[98]['WRITING (Original Screenplay)'][1], $ballots[98]['CINEMATOGRAPHY'][1], $ballots[98]['SOUND MIXING'][1], $ballots[98]['MUSIC (Original Song)'][1],
        ledger_row(97, 'INTERNATIONAL FEATURE FILM', array('tt0098000'), 'Republic of Lantern Country', '', false, 'Republic of Lantern Country', 'Republic of Lantern Country', 'Submitted a year before its domestic release.'),
    ),
    'title:tt0001001' => array(
        $ballots[1]['BEST PICTURE'][0], $ballots[1]['ENGINEERING EFFECTS'][0],
        ledger_row(2, 'CINEMATOGRAPHY', array('tt0001001'), 'Harry Perry-Nakagawa', 'nm0098123', false, '', 'Harry Perry-Nakagawa', 'Carried over from the first awards year under the cinematography rules of the time.'),
    ),
    'name:nm0098010' => array(
        $ballots[98]['DIRECTING'][0], $ballots[98]['WRITING (Original Screenplay)'][1],
        ledger_row(95, 'DIRECTING', array('tt0095001'), 'Ingrid Maximiliana Vasquez-Halloran de la Fuente', 'nm0098010', false),
        ledger_row(95, 'WRITING (Original Screenplay)', array('tt0095001'), 'Ingrid Maximiliana Vasquez-Halloran de la Fuente|Theodore Anand-Lindqvist', 'nm0098010|nm0098040', true, '', 'Written by Ingrid Maximiliana Vasquez-Halloran de la Fuente and Theodore Anand-Lindqvist'),
        ledger_row(90, 'WRITING (Adapted Screenplay)', array('tt0090002'), 'Ingrid Maximiliana Vasquez-Halloran de la Fuente', 'nm0098010', false, '', 'Screenplay by Ingrid Maximiliana Vasquez-Halloran de la Fuente'),
        ledger_row(80, 'SHORT FILM (Live Action)', array('tt0080003'), 'Ingrid Maximiliana Vasquez-Halloran de la Fuente|Benedict Marchetti', 'nm0098010|nm0098002', false),
    ),
    'name:nm0098040' => array(
        $ballots[98]['WRITING (Original Screenplay)'][0],
        ledger_row(94, 'WRITING (Original Screenplay)', array('tt0094001'), 'Theodore Anand-Lindqvist', 'nm0098040', false, '', 'Written by Theodore Anand-Lindqvist'),
    ),
    'company:co0000501' => array(
        $ballots[98]['SOUND MIXING'][1],
        ledger_category_ceremony('SOUND MIXING', 93, 1)['winner_rows'][0],
        ledger_category_ceremony('SOUND MIXING', 85, 1)['nominee_rows'][1],
        ledger_category_ceremony('SOUND MIXING', 70, 1)['nominee_rows'][1],
        ledger_category_ceremony('SOUND MIXING', 50, 1)['nominee_rows'][1],
        ledger_category_ceremony('SOUND MIXING', 30, 1)['winner_rows'][0],
    ),
);
$ledger['editorial_refs'] = array(
    'tt0098000' => array(
        array('post_id' => 701, 'post_type' => 'lunara_oscar_pick', 'type_label' => 'Oscar Pick', 'title' => 'Best Picture pick: The Long Night of the Ledger', 'url' => 'https://example.test/oscar-picks/best-picture-98/', 'excerpt' => 'Why the long film was always going to take the night, and what it means for the crafts.', 'meta_label' => 'The Long Night of the Ledger / 2026 / Correct'),
        array('post_id' => 702, 'post_type' => 'oscar_fact', 'type_label' => 'Oscar Fact', 'title' => 'The longest title ever to win Best Picture', 'url' => 'https://example.test/oscar-facts/longest-title/', 'excerpt' => 'At 82 characters the title beats the previous record by a clear margin.', 'meta_label' => ''),
    ),
    'nm0098010' => array(
        array('post_id' => 703, 'post_type' => 'lunara_oscar_pick', 'type_label' => 'Oscar Pick', 'title' => 'Best Director pick: Ingrid Vasquez-Halloran', 'url' => 'https://example.test/oscar-picks/best-director-98/', 'excerpt' => 'The direction is the argument.', 'meta_label' => 'Ingrid Vasquez-Halloran / 2026 / Correct'),
    ),
);
$ledger['tmdb_people'] = array(
    'nm0098010' => array('name' => 'Ingrid Maximiliana Vasquez-Halloran de la Fuente', 'biography' => 'Ingrid Maximiliana Vasquez-Halloran de la Fuente is a director and screenwriter whose films move between the harbour towns of her childhood and the studio sound stages where she learned the craft. Her work is known for long takes, double-barrelled family names and a refusal to cut away from an argument before it has been settled on screen, which is why the ledger keeps returning to her.', 'known_for_department' => 'Directing', 'birthday' => '1971-04-12', 'place_of_birth' => 'Valparaíso, Chile', 'backdrop_full' => 'https://img.example.test/nm0098010-backdrop.jpg'),
    'nm0098020' => array('name' => 'Desmond Achterberg', 'biography' => 'Desmond Achterberg is an actor.', 'known_for_department' => 'Acting', 'birthday' => '1984-09-30', 'place_of_birth' => 'Rotterdam, Netherlands', 'profile_full' => 'https://img.example.test/nm0098020-profile.jpg'),
    'nm0098011' => array('name' => 'Tobias Adeyemi', 'known_for_department' => 'Directing', 'profile_full' => 'https://img.example.test/nm0098011-profile.jpg'),
    'nm0098030' => array('name' => 'Marisol Everingham-Kaur', 'biography' => 'Marisol Everingham-Kaur is an actress.', 'known_for_department' => 'Acting', 'birthday' => '1979-01-02'),
    'nm0098060' => array('name' => 'Augustin Ferreira-Nakagawa', 'known_for_department' => 'Camera'),
);
$GLOBALS['ledger'] = $ledger;

// ---- Academy_Awards_Table: production formatting, fixture data ---------------------------
$source = file_get_contents($root . '/academy-awards-table.php');
$methods = '';
foreach (array(
    'ordinal', 'format_category_display', 'format_pipe_list', 'humanize_pipe_list', 'resolve_category_slug',
    'get_category_url', 'get_ceremony_url', 'get_ceremonies_index_url', 'get_categories_index_url', 'get_about_url', 'get_entity_base_url', 'get_entity_base_slug',
    'get_entity_url', 'build_entity_url_from_id', 'build_imdb_url', 'infer_entity_type_from_id',
    'is_title_entity_id', 'is_imdb_name_entity_id', 'is_local_name_entity_id', 'is_name_entity_id', 'is_company_entity_id',
    'get_entity_rows', 'get_card_backdrop_image_url', 'get_title_visual_package', 'get_person_visual_package',
    'get_route_context', 'is_entity_request', 'is_hub_request',
) as $name) {
    $methods .= ledger_method_source($source, $name);
}
eval('class Academy_Awards_Table {' . $methods . '
    const CARD_BACKDROP_WIDTH = 400;
    public static function get_instance() { static $instance; return $instance ??= new self(); }
    private function ledger() { return $GLOBALS["ledger"]; }
    public function get_table_name() { return "wp_academy_awards"; }
    public function get_award_facts_table_name() { return "wp_aat_award_facts"; }
    public function get_award_nominees_table_name() { return "wp_aat_award_nominees"; }
    public function get_database_url() { return "https://example.test/oscars/"; }
    public function get_max_ceremony() { return 98; }
    public function get_ceremony_year($ceremony) { return ledger_year($ceremony); }
    public function get_hub_page_post($hub) { return null; }
    public function render_shortcode($atts) { return "<div class=\"aat-table-shell-fixture\"></div>"; }
    public function get_hub_page_stats() {
        return array("total_records" => 12480, "total_winners" => 2914, "total_categories" => count($this->ledger()["category_classes"]), "total_ceremonies" => 98, "min_ceremony" => 1, "max_ceremony" => 98);
    }
    public function lookup_title_label($id) { $title = ledger_title($id); return (string) ($title["title"] ?? ""); }
    public function get_entity_display_name($entity, $id) {
        $id = strtolower(trim((string) $id));
        if ($entity === "title") { return $this->lookup_title_label($id); }
        if ($entity === "name") { return (string) ($this->ledger()["people"][$id] ?? ""); }
        return (string) ($this->ledger()["companies"][$id] ?? "");
    }
    public function canonicalize_name_entity_id_for_label($id, $label) { return $id; }
    public function get_name_entity_link_by_label($label) {
        $label = trim((string) $label);
        foreach ($this->ledger()["people"] as $id => $name) {
            if (strcasecmp($name, $label) === 0) { return array("label" => $name, "id" => $id, "url" => $this->build_entity_url_from_id($id)); }
        }
        return array("label" => $label, "id" => "", "url" => "");
    }
    public function get_review_ids_for_title_id($tt, $limit = 5) { return array_slice($this->ledger()["review_ids"][strtolower(trim((string) $tt))] ?? array(), 0, max(1, (int) $limit)); }
    public function get_entity_editorial_references($entity, $id, $label = "", $limit = 6) { return array_slice($this->ledger()["editorial_refs"][strtolower(trim((string) $id))] ?? array(), 0, $limit); }
    public function get_approved_ceremony_writeup($ceremony) { return $this->ledger()["writeups"][(int) $ceremony] ?? array(); }
    public function get_poster_attachment_id_for_title($tt) { $title = ledger_title($tt); return ($title["art"] ?? "") === "local" ? (int) substr($tt, 2) : 0; }
    public function get_poster_img_html_for_title($tt, $size = "medium", $attrs = array()) {
        $title = ledger_title($tt);
        if (($title["art"] ?? "") !== "local") { return ""; }
        $attrs = array_merge(array("loading" => "lazy", "decoding" => "async", "class" => "aat-poster-img", "sizes" => "(max-width: 820px) 180px, 340px", "alt" => $title["title"] . " poster"), (array) $attrs);
        $html = "<img width=\"400\" height=\"600\" src=\"https://img.example.test/" . $tt . "-" . $size . ".jpg\"";
        foreach ($attrs as $key => $value) { $html .= " " . $key . "=\"" . esc_attr($value) . "\""; }
        return $html . " />";
    }
    public function get_title_context_for_imdb_id($tt) { $title = ledger_title($tt); return array("title" => $title["title"] ?? "", "year" => $title["year"] ?? ""); }
    public function get_tmdb_data_for_imdb_id($tt, $allow_remote = true) {
        $title = ledger_title($tt);
        if (empty($title) || $title["art"] === "none") { return array(); }
        $data = array("title" => $title["title"], "release_date" => $title["year"] . "-03-01", "runtime" => 100 + ((int) substr($tt, 2) % 70),
            "overview" => "A dossier-length synopsis for " . $title["title"] . ": the film follows its people through one ceremony season, keeps every credit on screen, and ends where the ledger begins.",
            "backdrop_full" => "https://img.example.test/" . $tt . "-backdrop.jpg",
            "credits" => array("crew" => array(array("job" => "Director", "name" => ledger_person_name((int) substr($tt, 2) % 60 + 100)))));
        if ($title["art"] === "tmdb") { $data["poster_full"] = "https://img.example.test/" . $tt . "-tmdb.jpg"; }
        return $data;
    }
    public function get_person_context_for_imdb_id($nm) { return array("name" => $this->ledger()["people"][$nm] ?? "", "latest_year" => "2025"); }
    public function resolve_profile_attachment_for_person($nm, $name = "") {
        $local = ($this->ledger()["portraits"][$nm] ?? "none") === "local";
        return array("attachment_id" => $local ? (int) substr($nm, 2) : 0, "match_strategy" => $local ? "aat-person-meta" : "", "attached_file" => "");
    }
    public function get_tmdb_person_data_for_imdb_id($nm, $allow_remote = true) { return $this->ledger()["tmdb_people"][$nm] ?? array(); }
    public function get_ceremony_summary($ceremony) {
        $groups = $this->ledger()["ballots"][(int) $ceremony] ?? array();
        $rows = $groups ? array_merge(...array_values($groups)) : array();
        $winners = array_filter($rows, function ($r) { return !empty($r["winner"]); });
        return array_merge(array("year_label" => ledger_year($ceremony), "nominations" => count($rows), "wins" => count($winners),
            "categories_count" => count($groups), "winner_categories" => count(array_unique(array_column($winners, "canonical_category"))),
            "categories" => array_keys($groups), "newer_ceremony" => 0, "older_ceremony" => 0), $this->ledger()["summaries"][(int) $ceremony] ?? array());
    }
    public function get_ceremony_ballot_ledger($ceremony) {
        $categories = array();
        foreach ($this->ledger()["ballots"][(int) $ceremony] ?? array() as $category => $rows) {
            $categories[] = array("category" => $category, "label" => $this->format_category_display($category, $ceremony), "url" => $this->get_category_url($category),
                "rows" => $rows, "row_count" => count($rows), "winner_count" => count(array_filter($rows, function ($r) { return !empty($r["winner"]); })));
        }
        return array("categories" => $categories, "review_map" => $this->ledger()["review_map"][(int) $ceremony] ?? array());
    }
    public function get_ceremony_rollup($ceremony) {
        $groups = $this->ledger()["ballots"][(int) $ceremony] ?? array();
        if (empty($groups)) { return array(); }
        $titles = array(); $winner_rows = array(); $best_picture = array(); $best_picture_nominees = array();
        foreach ($groups as $category => $rows) {
            foreach ($rows as $row) {
                $film_id = (string) (array_values(array_filter(explode("|", $row["film_id"]), "strlen"))[0] ?? "");
                $film_label = $film_id !== "" ? $this->lookup_title_label($film_id) : $row["film"];
                $entry = array("canonical_category" => $category, "category_label" => $this->format_category_display($category, $ceremony), "film" => $film_label,
                    "film_id" => $film_id, "film_url" => $film_id !== "" ? $this->build_entity_url_from_id($film_id) : "", "name" => $row["name"],
                    "nominees" => $row["nominees"], "nominee_ids" => $row["nominee_ids"], "detail" => $row["detail"], "note" => $row["note"], "year" => $row["year"]);
                if ($category === "BEST PICTURE") {
                    $best_picture_nominees[] = array("film" => $film_label, "film_id" => $film_id, "film_url" => $entry["film_url"], "winner" => $row["winner"], "year" => $row["year"]);
                }
                if ($film_id !== "") {
                    $titles[$film_id] = $titles[$film_id] ?? array("film_id" => $film_id, "film" => $film_label, "film_url" => $entry["film_url"], "nominations" => 0, "wins" => 0, "winning_categories" => array());
                    $titles[$film_id]["nominations"]++;
                    if ($row["winner"]) { $titles[$film_id]["wins"]++; $titles[$film_id]["winning_categories"][$category] = $entry["category_label"]; }
                }
                if ($row["winner"]) { $winner_rows[] = $entry; if ($category === "BEST PICTURE") { $best_picture = $entry; } }
            }
        }
        $by = function ($primary, $secondary) { return function ($a, $b) use ($primary, $secondary) { return array($b[$primary], $b[$secondary], $a["film"]) <=> array($a[$primary], $a[$secondary], $b["film"]); }; };
        $nominated = array_values($titles); usort($nominated, $by("nominations", "wins"));
        $winning = array_values(array_filter($titles, function ($t) { return $t["wins"] > 0; })); usort($winning, $by("wins", "nominations"));
        return array("ceremony" => (int) $ceremony, "year" => ledger_year($ceremony), "categories_total" => count($groups),
            "winner_categories" => count(array_unique(array_column($winner_rows, "canonical_category"))),
            "has_full_winners" => count(array_unique(array_column($winner_rows, "canonical_category"))) >= count($groups),
            "best_picture" => $best_picture, "best_picture_nominees" => $best_picture_nominees, "winner_rows" => $winner_rows,
            "most_nominated" => $nominated[0] ?? array(), "most_wins" => $winning[0] ?? array(), "winning_titles_count" => count($winning), "top_titles" => array_slice($winning, 0, 5));
    }
    public function get_ceremony_title_highlights($ceremony, $limit = 18) {
        $buckets = array(array(), array(), array()); $seen = array();
        foreach ($this->ledger()["ballots"][(int) $ceremony] ?? array() as $category => $rows) {
            foreach ($rows as $row) {
                $film_id = (string) (array_values(array_filter(explode("|", $row["film_id"]), "strlen"))[0] ?? "");
                if ($film_id === "" || isset($seen[$film_id])) { continue; }
                $seen[$film_id] = true;
                $buckets[$category === "BEST PICTURE" ? 0 : ($row["winner"] ? 1 : 2)][] = array("film_id" => $film_id, "film" => $this->lookup_title_label($film_id), "winner" => $row["winner"], "canonical_category" => $category);
            }
        }
        return array_slice(array_merge(...$buckets), 0, max(0, (int) $limit));
    }
    private function category_ceremonies($category) { return $this->ledger()["category_ledgers"][$category] ?? array(); }
    public function get_category_summary($category) {
        $ceremonies = $this->category_ceremonies($category);
        $nominations = 0; $wins = 0;
        foreach ($ceremonies as $data) { $nominations += count($data["winner_rows"]) + count($data["nominee_rows"]); $wins += count($data["winner_rows"]); }
        return array("nominations" => $nominations, "wins" => $wins, "ceremonies" => count($ceremonies), "first_ceremony" => $ceremonies ? min(array_keys($ceremonies)) : 0, "last_ceremony" => $ceremonies ? max(array_keys($ceremonies)) : 0);
    }
    public function get_category_latest_winner($category) {
        $ceremonies = $this->category_ceremonies($category);
        if (empty($ceremonies)) { return array(); }
        $row = $ceremonies[max(array_keys($ceremonies))]["winner_rows"][0];
        $film_id = (string) (array_values(array_filter(explode("|", $row["film_id"]), "strlen"))[0] ?? "");
        return array("ceremony" => $row["ceremony"], "year" => $row["year"], "canonical_category" => $category, "film" => $row["film"], "film_id" => $film_id,
            "film_url" => $film_id !== "" ? $this->build_entity_url_from_id($film_id) : "", "name" => $row["name"], "nominees" => $row["nominees"], "nominee_ids" => $row["nominee_ids"], "detail" => $row["detail"], "note" => $row["note"]);
    }
    public function get_category_decade_ledger($category) {
        $decades = array(); $counts = array(); $totals = array("ceremonies" => 0, "winners" => 0, "nominees" => 0);
        foreach ($this->category_ceremonies($category) as $ceremony => $data) {
            $start = (int) floor((int) ledger_year($ceremony) / 10) * 10; $key = $start . "s";
            $decades[$key] = $decades[$key] ?? array("key" => $key, "label" => $key, "start" => $start, "ceremonies" => array());
            $decades[$key]["ceremonies"][] = $data;
            $counts[$key] = ($counts[$key] ?? 0) + 1; $totals["ceremonies"]++; $totals["winners"] += count($data["winner_rows"]); $totals["nominees"] += count($data["nominee_rows"]);
        }
        uasort($decades, function ($a, $b) { return $b["start"] <=> $a["start"]; });
        return array("decades" => $decades, "decade_order" => array_keys($decades), "decade_counts" => $counts, "totals" => $totals, "review_map" => array());
    }
    public function get_category_title_highlights($category, $limit = 18) {
        $out = array(); $seen = array();
        foreach ($this->category_ceremonies($category) as $ceremony => $data) {
            foreach (array_merge($data["winner_rows"], $data["nominee_rows"]) as $row) {
                $film_id = (string) (array_values(array_filter(explode("|", $row["film_id"]), "strlen"))[0] ?? "");
                if ($film_id === "" || isset($seen[$film_id])) { continue; }
                $seen[$film_id] = true;
                $out[] = array("film_id" => $film_id, "film" => $this->lookup_title_label($film_id), "winner" => $row["winner"], "ceremony" => $ceremony, "year" => $row["year"]);
                if (count($out) >= max(0, (int) $limit)) { return $out; }
            }
        }
        return $out;
    }
}');

// ---- Render ------------------------------------------------------------------------
$GLOBALS['cache'] = array('aat_category_slug_map_v1' => $ledger['slug_map']);
foreach ($ledger['entities'] as $key => $rows) {
    list($kind, $id) = explode(':', $key);
    $GLOBALS['cache']['aat_entity_rows_v2_' . md5($kind . ':' . $id)] = $rows;
}
$_GET = array();
switch ($route) {
    case 'ceremonies': case 'categories': case 'about':
        $GLOBALS['query'] = array('aat_hub' => $route, 'aat_hub_id' => '');
        $template = 'hub-page.php';
        break;
    case 'ceremony':
        $GLOBALS['query'] = array('aat_hub' => 'ceremony', 'aat_hub_id' => $variant === 'early' ? '1' : '98');
        if ($full) { $_GET['ledger'] = 'full'; }
        $template = 'hub-page.php';
        break;
    case 'category':
        $GLOBALS['query'] = array('aat_hub' => 'category', 'aat_hub_id' => $variant === 'generic' ? 'sound' : 'best-picture');
        if ($full) { $_GET['history'] = 'full'; }
        $template = 'hub-page.php';
        break;
    case 'title':
        $GLOBALS['query'] = array('aat_entity' => 'title', 'aat_entity_id' => $variant === 'noposter' ? 'tt0001001' : 'tt0098000');
        $template = 'entity-page.php';
        break;
    case 'name':
        $GLOBALS['query'] = array('aat_entity' => 'name', 'aat_entity_id' => $variant === 'noportrait' ? 'nm0098040' : 'nm0098010');
        $template = 'entity-page.php';
        break;
    case 'company':
        $GLOBALS['query'] = array('aat_entity' => 'company', 'aat_entity_id' => 'co0000501');
        $template = 'entity-page.php';
        break;
    case 'table':
        $GLOBALS['query'] = array();
        $GLOBALS['table_atts'] = array_merge(array('layout' => 'embedded'), (array) json_decode(base64_decode($variant), true));
        require $root . '/includes/class-aat-explorer.php';
        $template = 'table-display.php';
        break;
    default:
        fwrite(STDERR, "Usage: php tests/fixtures/ledger-routes.php <ceremonies|categories|about|ceremony|category|title|name|company|explorer> [variant] [full]\n");
        exit(2);
}
require $root . '/includes/class-aat-ledger-motion.php';
$before = $GLOBALS['cache'];
function ledger_render($template) { $atts = $GLOBALS['table_atts'] ?? array(); include $template; }
ledger_render($root . '/templates/' . $template);
if ($before !== $GLOBALS['cache']) { throw new RuntimeException('Rendering changed the warm cache'); }
if (empty($GLOBALS['rendered_sections']) && in_array($route, array('ceremony', 'category', 'title', 'name', 'company'), true)) { throw new RuntimeException('The route section composer did not run'); }

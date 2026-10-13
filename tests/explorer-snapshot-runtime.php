<?php
/** Real snapshot publisher and API cache behavior with an isolated upload directory. */
define('ABSPATH', __DIR__ . '/');
define('WP_CONTENT_DIR', __DIR__ . '/missing-content');
define('AAT_VERSION', 'fixture-build');
define('ARRAY_A', 'ARRAY_A');
$folder = sys_get_temp_dir() . '/lunara-explorer-test-' . bin2hex(random_bytes(8));
mkdir($folder);
$GLOBALS['upload'] = array('basedir' => $folder, 'baseurl' => 'https://cdn.example.test/custom-site-uploads', 'error' => false);
$GLOBALS['options'] = array('blog_public' => '1', 'aat_dataset_stamp' => 'fixture-data', 'stylesheet' => 'theme', 'home' => 'https://example.test', 'siteurl' => 'https://example.test');
$GLOBALS['actions'] = $GLOBALS['events'] = $GLOBALS['cache'] = array();
$GLOBALS['logged'] = false;
$_COOKIE = array();
$_SERVER['REQUEST_METHOD'] = 'GET';
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['options'][$key] = $value; return true; }
function wp_generate_uuid4() { return bin2hex(random_bytes(16)); }
function wp_upload_dir($time = null, $create = true) { return $GLOBALS['upload']; }
function wp_mkdir_p($path) { return is_dir($path) || mkdir($path, 0777, true); }
function is_user_logged_in() { return $GLOBALS['logged']; }
function maybe_unserialize($value) { return $value !== '' && $value[0] === 'a' ? unserialize($value) : $value; }
function wp_json_encode($value, $flags = 0) {
    if (!empty($GLOBALS['mid_render_edit']) && isset($value['snapshot'])) { $GLOBALS['mid_render_edit'] = false; update_option('aat_explorer_snapshot_revision', 'concurrent-edit'); }
    return json_encode($value, $flags);
}
function add_action($name, $callback, $priority = 10, $count = 1) { $GLOBALS['actions'][$name][] = $callback; }
function wp_next_scheduled($name) { return $GLOBALS['events'][$name] ?? false; }
function wp_schedule_single_event($at, $name) { $GLOBALS['events'][$name] = $at; }
function get_post_type($id) { return $id === 1 ? 'review' : 'irrelevant'; }
function get_transient($key) { return $GLOBALS['cache'][$key] ?? false; }
function set_transient($key, $value, $ttl) { $GLOBALS['cache'][$key] = $value; }
function wp_cache_get($key, $group = '') { return get_transient($group . $key); }
function wp_cache_set($key, $value, $group = '', $ttl = 0) { set_transient($group . $key, $value, $ttl); }
function is_wp_error($value) { return false; }
class AAT_Page_Store { const WARM_COOKIE = 'wp_aat_store_warm'; public static function mode() { return 'on'; } }
class AAT_Explorer { public static function base_url() { return 'https://example.test/oscars/explore/'; } }
class Academy_Awards_Table {
    public static function get_instance() { return new self(); }
    public function get_label_rules_state() { return 'labels'; }
    public function get_dataset_stamp() { return get_option('aat_dataset_stamp'); }
}
class Snapshot_DB {
    public $options = 'custom_options';
    public function prepare($sql, $args) { return $sql; }
    public function get_results($sql, $format) {
        $rows = array();
        foreach ($GLOBALS['options'] as $key => $value) { if (is_scalar($value)) { $rows[] = array('option_name' => $key, 'option_value' => (string) $value); } }
        return $rows;
    }
}
$GLOBALS['wpdb'] = new Snapshot_DB();
require dirname(__DIR__) . '/includes/class-aat-read-api.php';
require dirname(__DIR__) . '/includes/class-aat-explorer-store.php';
$checks = 0;
function check($value, $message) { global $checks; $checks++; if (!$value) { throw new RuntimeException($message); } }
function local_file($url) { return str_replace($GLOBALS['upload']['baseurl'], $GLOBALS['upload']['basedir'], $url); }
function remove_test_dir($path, $root) {
    if (strpos((string) realpath($path), $root) !== 0 || is_link($path)) { throw new RuntimeException('Unsafe fixture cleanup'); }
    foreach (new DirectoryIterator($path) as $entry) {
        if ($entry->isDot()) { continue; }
        if ($entry->isDir()) { remove_test_dir($entry->getPathname(), $root); } else { unlink($entry->getPathname()); }
    }
    rmdir($path);
}
try {
    AAT_Explorer_Store::init();
    $gen = AAT_Explorer_Store::generation();
    check(strlen($gen) === 24, 'A valid current dataset has a generation fence.');
    $payload = array('url' => AAT_Explorer::base_url(), 'title' => 'Oscar Ledger', 'regions' => array_fill_keys(array('filters', 'chips', 'debrief', 'status', 'results', 'pager'), '<p>Complete &amp; escaped records</p>'));
    $url = AAT_Explorer_Store::publish($payload, $gen, true);
    check(strpos($url, $GLOBALS['upload']['baseurl'] . '/lunara-ledger/explorer/') === 0, 'Custom uploads and public CDN URL are honored.');
    $json = json_decode(file_get_contents(local_file($url)), true);
    check($json['regions'] === $payload['regions'] && $json['url'] === $payload['url'], 'Every PHP-rendered region survives static publication exactly.');
    check($json['snapshot']['generation'] === $gen, 'Snapshot carries the pinned generation.');
    $pointer = get_option(AAT_Explorer_Store::POINTER);
    $manifest = json_decode(file_get_contents(local_file($pointer['url'])), true);
    check($manifest['entries'][$payload['url']]['url'] === $url, 'Only a fully written snapshot is advertised.');
    check(AAT_Explorer_Store::publish($payload, $gen, true) === $url, 'Unchanged fresh content reuses its immutable URL.');
    check(count(glob(dirname(local_file($url)) . '/*.tmp')) === 0, 'Publication leaves no partial files.');
    check(AAT_Explorer_Store::publish($payload, $gen, false) === '' && get_option(AAT_Explorer_Store::POINTER) === $pointer, 'Failed/empty-data renders never replace a good manifest.');
    check(AAT_Explorer_Store::publish(array('url' => $payload['url']), $gen, true) === '', 'An incomplete fragment is rejected.');
    $lock = fopen(dirname(local_file($url)) . '/.publish.lock', 'c'); flock($lock, LOCK_EX);
    check(AAT_Explorer_Store::publish($payload, $gen, true) === '', 'A concurrent publisher fails open to the dynamic renderer.');
    flock($lock, LOCK_UN); fclose($lock);
    $GLOBALS['logged'] = true;
    check(AAT_Explorer_Store::publish($payload, $gen, true) === '', 'Authenticated responses cannot become public snapshots.');
    $GLOBALS['logged'] = false;
    $_SERVER['HTTP_X_WP_NONCE'] = 'fixture';
    check(!AAT_Explorer_Store::eligible(), 'Nonce-bearing requests bypass the public store.');
    unset($_SERVER['HTTP_X_WP_NONCE']);
    $GLOBALS['mid_render_edit'] = true;
    $changed = $payload; $changed['title'] = 'Changed during render';
    check(AAT_Explorer_Store::publish($changed, $gen, true) === '' && get_option(AAT_Explorer_Store::POINTER) === $pointer, 'A concurrent source change cannot publish under the old generation.');
    check(AAT_Explorer_Store::descriptor($gen) === array(), 'A new SSR response never advertises a manifest from its retired pre-render generation.');
    $next = AAT_Explorer_Store::generation();
    check($next !== $gen && AAT_Explorer_Store::descriptor($next) === array(), 'Retired generations are not advertised.');
    check(AAT_Explorer_Store::publish($payload, $gen, true) === '', 'A stale generation is rejected before a write.');
    $GLOBALS['upload']['error'] = 'Storage unavailable';
    check(AAT_Explorer_Store::publish($payload, $next, true) === '', 'Unavailable storage preserves the dynamic path.');
    check(AAT_Explorer_Store::report()['storage_backoff'] && !AAT_Explorer_Store::eligible(), 'Storage failures back off instead of queueing repeated warm work.');
    unset($GLOBALS['cache']['aat_explorer_snapshot_backoff']);
    $GLOBALS['upload']['error'] = false;
    $GLOBALS['options']['aat_bundled_import_state'] = serialize(array('status' => 'swapped'));
    check(AAT_Explorer_Store::generation() === '', 'No snapshot is built while an import is incomplete.');
    unset($GLOBALS['options']['aat_bundled_import_state']);
    $api = new ReflectionMethod('AAT_Read_API', 'cached'); $api->setAccessible(true);
    $builds = 0; $build = function () use (&$builds) { return ++$builds; };
    check($api->invoke(null, array('fixture'), false, $build) === 1 && $api->invoke(null, array('fixture'), false, $build) === 1, 'Repeated API queries reuse their cached canonical answer.');
    foreach ($GLOBALS['actions']['aat_reporting_tables_rebuilt'] as $callback) { call_user_func($callback); }
    check($api->invoke(null, array('fixture'), false, $build) === 2, 'A manual reporting rebuild invalidates API data even when the import stamp is unchanged.');
    check(count($GLOBALS['events']) === 1, 'A burst of invalidations queues only one bounded warmer.');
    $before = AAT_Explorer_Store::generation();
    AAT_Explorer_Store::meta_changed(1, 1, '_thumbnail_id', 33);
    check(AAT_Explorer_Store::generation() !== $before, 'Editorial artwork updates retire rendered fragments.');
    $before = AAT_Explorer_Store::generation();
    AAT_Explorer_Store::meta_changed(1, 1, '_edit_lock', 33);
    check(AAT_Explorer_Store::generation() === $before, 'Editor locks do not retire public data.');
    $before = AAT_Explorer_Store::generation();
    AAT_Explorer_Store::option_changed('permalink_structure');
    check(AAT_Explorer_Store::generation() !== $before, 'URL/settings changes retire rendered fragments.');
    $generation = AAT_Explorer_Store::generation();
    $large = $payload; $large['regions']['results'] = str_repeat('x', AAT_Explorer_Store::MAX_BYTES + 1);
    check(AAT_Explorer_Store::publish($large, $generation, true) === '', 'An oversized response bypasses static publication.');
    $foreign = $payload; $foreign['url'] = 'https://foreign.test/oscars/explore/';
    check(AAT_Explorer_Store::publish($foreign, $generation, true) === '', 'A foreign route cannot become an Explorer snapshot.');
    $fresh = AAT_Explorer_Store::publish($payload, $generation, true);
    check($fresh !== '' && AAT_Explorer_Store::report()['ready'], 'The current source publishes a fresh ready manifest after recovery.');
    $old = dirname(local_file($fresh)) . '/' . str_repeat('b', 64) . '.json';
    file_put_contents($old, '{}'); touch($old, time() - 90000);
    AAT_Explorer_Store::collect_garbage();
    check(!is_file($old) && is_file(local_file($fresh)), 'Garbage collection removes expired JSON while retaining current immutable files.');
    $old_pointer = get_option(AAT_Explorer_Store::POINTER); $old_pointer['created_at'] = time() - 301; update_option(AAT_Explorer_Store::POINTER, $old_pointer);
    check(AAT_Explorer_Store::descriptor($generation) === array(), 'Expired manifests are not advertised to a new page.');
    echo "Explorer snapshot runtime passed: $checks checks.\n";
} finally { remove_test_dir($folder, realpath($folder)); }

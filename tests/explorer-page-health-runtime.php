<?php
/** The real Explorer page model must not turn a partial API failure into a snapshot. */
ob_start(); require __DIR__ . '/ledger-explorer-media-runtime.php'; ob_end_clean();
function get_transient($key) { return false; }
function get_option($key, $default = false) { return $key === 'aat_explorer_snapshots' ? 'off' : $default; }
function selected($actual, $expected, $echo = false) { return $actual === $expected ? ' selected' : ''; }
function checked($actual, $expected, $echo = false) { return $actual === $expected ? ' checked' : ''; }
function get_query_var($key) { return false; }
class AAT_Read_API { const NS = 'lunara-ledger/v1'; }
class WP_Error {}
function is_wp_error($value) { return $value instanceof WP_Error; }
class WP_REST_Request {
    public $route;
    public function __construct($method, $route) { $this->route = $route; }
    public function set_param($key, $value) {}
}
class Health_Response {
    private $data;
    public function __construct($data) { $this->data = $data; }
    public function is_error() { return is_wp_error($this->data); }
    public function as_error() { return $this->data; }
    public function get_data() { return $this->data; }
}
function rest_do_request($request) {
    if (strpos($request->route, '/entities/') !== false) {
        $data = $GLOBALS['entity_failed'] ? new WP_Error() : array('id' => 'nm0000001', 'name' => 'Canonical person', 'kind' => 'person', 'url' => 'https://example.test/person/', 'imdb_url' => 'https://imdb.com/name/nm0000001/', 'nominations' => 1, 'wins' => 1, 'ceremonies' => 1);
    } elseif (substr($request->route, -7) === '/status') {
        $data = array('dataset' => array('nominations' => 100));
    } elseif (substr($request->route, -strlen('/nominations')) === '/nominations') {
        $data = array('items' => array(), 'total' => 0, 'page' => 1, 'pages' => 1);
    } else { $data = array(); }
    return new Health_Response($data);
}
function health_page($failed) {
    $GLOBALS['entity_failed'] = $failed;
    $state = array('q' => '', 'ceremony' => 0, 'decade' => 0, 'class' => '', 'category' => '', 'winner' => false, 'entity' => array('nm0000001'), 'by' => '', 'sort' => 'newest', 'pg' => 1);
    foreach (array('state' => $state, 'page' => null, 'page_generation' => null) as $key => $value) {
        $property = new ReflectionProperty('AAT_Explorer', $key); $property->setAccessible(true); $property->setValue(null, $value);
    }
    return AAT_Explorer::page();
}
$failed = health_page(true);
if ($failed['cacheable'] !== false || $failed['regions']['debrief'] !== '') { throw new RuntimeException('A failed identity response must remain non-cacheable even when counts/list reads succeeded.'); }
$healthy = health_page(false);
if ($healthy['cacheable'] !== true || strpos($healthy['regions']['debrief'], 'Canonical person') === false) { throw new RuntimeException('A healthy identity and legitimate empty filtered list remain cacheable with the full Debrief.'); }
echo "Explorer page health runtime passed: failed identity rejected; complete identity retained.\n";

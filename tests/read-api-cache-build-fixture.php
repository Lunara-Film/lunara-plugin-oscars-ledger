<?php
/** Run the production API in separate builds against the same warm cache. */
define('ABSPATH', __DIR__ . '/');
define('AAT_VERSION', $argv[1] ?? 'fixture-build');
$GLOBALS['encoded_key'] = array();
$GLOBALS['requested_key'] = '';
function get_option($key, $default = false) { return $key === 'aat_explorer_snapshot_revision' ? 'same-repair-revision' : $default; }
function wp_json_encode($value) { $GLOBALS['encoded_key'] = $value; return json_encode($value); }
function get_transient($key) {
    $GLOBALS['requested_key'] = $key;
    return array('plugin_version' => 'retired-build', 'dataset' => array('nominations' => 123));
}
function is_wp_error($value) { return false; }
class WP_REST_Request {}
class Fixture_Response {
    public $data;
    public function __construct($data) { $this->data = $data; }
    public function header($name, $value) {}
}
function rest_ensure_response($data) { return new Fixture_Response($data); }
class Academy_Awards_Table {
    public static function get_instance() { return new self(); }
    public function get_label_rules_state() { return 'same-label-rules'; }
    public function get_dataset_stamp() { return 'same-dataset'; }
}
require dirname(__DIR__) . '/includes/class-aat-read-api.php';
$response = AAT_Read_API::route_status(new WP_REST_Request());
echo json_encode(array(
    'key' => $GLOBALS['requested_key'],
    'schema_in_key' => in_array(AAT_Read_API::RESPONSE_SCHEMA, $GLOBALS['encoded_key'], true),
    'data' => $response->data,
));

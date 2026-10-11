<?php
/**
 * 2.8.13 speed pass two: cheaper misses, and pages that are safe to cache.
 *
 * Run: php tests/speed-pass-two-contract.php
 */
$root = dirname(__DIR__);
$plugin = file_get_contents($root . '/academy-awards-table.php');
$entity = file_get_contents($root . '/templates/entity-page.php');
$hub = file_get_contents($root . '/templates/hub-page.php');
$fail = array();
$check = function ($ok, $msg) use (&$fail) { if (!$ok) { $fail[] = $msg; } };

// Links never echo the visitor's own query string into a cacheable page.
$check(preg_match('/(add|remove)_query_arg\(\s*\'[a-z]+\'(\s*,\s*\'[a-z]+\')?\s*\)/', $hub . $entity) === 0, 'Every add_query_arg/remove_query_arg in the Oscars templates has a canonical base URL.');
$check(strpos($hub, "add_query_arg('ledger', 'full', \$aat->get_ceremony_url(\$ceremony))") !== false, 'The full-ballot link is built from the ceremony URL.');

// Ceremonies past the last one on record are 404s.
$check(strpos($hub, '$ceremony > $aat_max_ceremony') !== false, 'A ceremony number beyond the maximum is a 404.');

// The category ledger no longer builds a review map nobody reads.
$fl = substr($plugin, strpos($plugin, 'public function get_category_decade_ledger('), 12000);
$check(strpos($fl, 'get_review_permalink_map_for_title_ids') === false, 'The category ledger does no review lookups.');

// Review cards resolve images only for the cards that are shown.
$fc = substr($hub, strpos($hub, '$aat_build_hub_review_cards = function'), 6000);
$check(strpos($fc, 'array_slice($cards, 0, $limit)') < strpos($fc, 'get_title_visual_package'), 'Review-card images are built after the cut to the limit.');

// Read-API answers survive deploys that do not change their shape.
$probe = function ($build) use ($root) {
    $process = proc_open(array(PHP_BINARY, $root . '/tests/read-api-cache-build-fixture.php', $build), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Could not start the API cache fixture.'); }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
    if (proc_close($process) !== 0) { throw new RuntimeException('API cache fixture failed: ' . $error); }
    $data = json_decode($output, true);
    if (!is_array($data)) { throw new RuntimeException('API cache fixture returned invalid JSON.'); }
    return $data;
};
$before = $probe('fixture-before-deploy');
$after = $probe('fixture-after-deploy');
$check($before['key'] !== '' && $before['key'] === $after['key'], 'Unchanged API answers keep the same cache key across different running builds.');
$check($before['schema_in_key'] && $after['schema_in_key'], 'The API response schema participates in the actual cache-key serialization.');
$check($before['data']['plugin_version'] === 'fixture-before-deploy' && $after['data']['plugin_version'] === 'fixture-after-deploy', '/status replaces a cached old build with the running plugin version.');
$check($before['data']['dataset']['nominations'] === 123 && $after['data']['dataset']['nominations'] === 123, 'Version reporting preserves the cached dataset without a rebuild.');

if ($fail) { fwrite(STDERR, implode("\n", $fail) . "\n"); exit(1); }
echo "Speed pass two contract OK.\n";

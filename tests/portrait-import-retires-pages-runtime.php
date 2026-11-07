<?php
/**
 * 2.8.28: a portrait import reaches the page at once.
 *
 * Run: php tests/portrait-import-retires-pages-runtime.php
 *
 * The three portrait importers (manual batch, adoption of an existing
 * attachment, TMDB profile) end in retire_person_portrait_caches(). Before
 * 2.8.28 they only deleted the resolver's transient, so a stored name,
 * ceremony or category page kept the old portrait, or its plate, for up to
 * twelve hours (AAT_Page_Store::MAX_AGE) or until an unrelated bump.
 *
 * Exercised against the real helper and the real page store with the
 * store's own stubs: the transient goes, the store's generation moves (so
 * every stored page is retired), and a warm run is queued.
 */
require __DIR__ . '/page-store-stubs.inc'; // loads the real AAT_Page_Store with the store's own stubs

$GLOBALS['deleted_transients'] = array();
function delete_transient($key) { $GLOBALS['deleted_transients'][] = $key; return true; }

$source = file_get_contents(dirname(__DIR__) . '/academy-awards-table.php');
if (!preg_match('/\n    private function retire_person_portrait_caches\(/', $source, $m, PREG_OFFSET_CAPTURE)) { fwrite(STDERR, "Missing retire_person_portrait_caches()\n"); exit(1); }
$method = ''; $depth = 0; $opened = false;
foreach (token_get_all('<?php ' . substr($source, $m[0][1])) as $token) {
    if (is_array($token)) { if ($token[0] !== T_OPEN_TAG) $method .= $token[1]; continue; }
    $method .= $token;
    if ($token === '{') { ++$depth; $opened = true; }
    if ($token === '}' && --$depth === 0 && $opened) break;
}
eval('class Portrait_Importer {' . $method . ' public function retire($id) { return $this->retire_person_portrait_caches($id); } }');

$checks = 0;
function pr_assert($ok, $message) { global $checks; ++$checks; if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }

$importer = new Portrait_Importer();
$before = AAT_Page_Store::generation();
$importer->retire(' NM0000759 ');
pr_assert($GLOBALS['deleted_transients'] === array('aat_person_profile_attachment_v2_nm0000759'), 'The resolver\'s transient for the person goes (id normalised).');
pr_assert(AAT_Page_Store::generation() !== $before, 'The page store\'s generation moves, so every stored page built with the old portrait is retired.');
pr_assert(!empty($GLOBALS['scheduled']['aat_page_store_warm']) || !empty($GLOBALS['options']['aat_page_store_warm_queue']), 'A warm run is queued for the retired pages.');

$GLOBALS['deleted_transients'] = array();
$after = AAT_Page_Store::generation();
$importer->retire('');
pr_assert($GLOBALS['deleted_transients'] === array() && AAT_Page_Store::generation() === $after, 'An empty person id retires nothing.');

// Every importer ends in the helper: the three methods that write a verified portrait.
foreach (array('import_manual_person_profile_image', 'adopt_existing_person_portrait_attachment', 'import_tmdb_person_profile_portrait') as $name) {
    if (!preg_match('/\n    private function ' . $name . '\(/', $source, $mm, PREG_OFFSET_CAPTURE)) { fwrite(STDERR, "Missing $name()\n"); exit(1); }
    $body = ''; $depth = 0; $opened = false;
    foreach (token_get_all('<?php ' . substr($source, $mm[0][1])) as $token) {
        if (is_array($token)) { if ($token[0] !== T_OPEN_TAG) $body .= $token[1]; continue; }
        $body .= $token;
        if ($token === '{') { ++$depth; $opened = true; }
        if ($token === '}' && --$depth === 0 && $opened) break;
    }
    pr_assert(strpos($body, '$this->retire_person_portrait_caches($person_id)') !== false && strpos($body, "delete_transient('aat_person_profile_attachment_v2_'") === false, "$name() retires through the helper, not a bare transient delete.");
}

echo "portrait-import-retires-pages-runtime: $checks checks passed.\n";

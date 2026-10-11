<?php
/** Actual template output: recorded category spans and scoped no-JS table links. */
$checks = 0;
function verify_route_semantics($condition, $message) {
    global $checks;
    $checks++;
    if (!$condition) { throw new RuntimeException($message); }
}
function render_route_semantics($route, $variant = '') {
    $process = proc_open(array(PHP_BINARY, __DIR__ . '/fixtures/ledger-routes.php', $route, $variant), array(
        1 => array('pipe', 'w'), 2 => array('pipe', 'w'),
    ), $pipes, null, null, array('bypass_shell' => true));
    if (!is_resource($process)) { throw new RuntimeException('Could not render route fixture'); }
    $html = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process);
    verify_route_semantics($exit === 0 && $errors === '', 'Real template failed: ' . $errors);
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<!doctype html><meta charset="utf-8">' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return new DOMXPath($document);
}
function category_cards_semantics($xpath) {
    $cards = array();
    foreach ($xpath->query('//article[contains(concat(" ", normalize-space(@class), " "), " aat-hub-card ")]') as $card) {
        $link = $xpath->query('.//h2/a', $card)->item(0);
        if (!$link) { throw new RuntimeException('A category lost its named destination'); }
        $cards[$link->getAttribute('href')] = array('name' => trim($link->textContent), 'text' => trim($card->textContent));
    }
    return $cards;
}
$current = category_cards_semantics(render_route_semantics('categories'));
$past = category_cards_semantics(render_route_semantics('categories', 'past-spans'));
verify_route_semantics(count($current) > 10, 'Category fixture must exercise the full category index');
verify_route_semantics(array_keys($current) === array_keys($past), 'Partial recent records must retain every category destination');
foreach ($current as $url => $card) {
    verify_route_semantics($card['name'] === $past[$url]['name'], 'Partial recent records must preserve category names');
    verify_route_semantics(strpos($past[$url]['text'], 'No longer awarded') === false, 'Record absence must not assert award retirement');
}
foreach (array('best-picture' => array('1927/28', '2024', '97 ceremonies'), 'honorary-award' => array('1927/28', '2022', '83 ceremonies')) as $slug => $facts) {
    $card = $past['https://example.test/oscars/category/' . $slug . '/'] ?? null;
    verify_route_semantics($card !== null, 'Expected category card is present: ' . $slug);
    verify_route_semantics(strpos($card['text'], 'Recorded ' . $facts[0] . ' to ' . $facts[1]) !== false, 'Both exact recorded year labels survive: ' . $slug);
    verify_route_semantics(strpos($card['text'], $facts[2]) !== false, 'Recorded ceremony count survives: ' . $slug);
}
$cases = array(
    array('unfiltered', array(), array(array())),
    array('ceremony', array('ceremony' => '98'), array(array('ceremony' => '98'))),
    array('category', array('category' => 'SOUND MIXING'), array(array('category' => 'sound-mixing'))),
    array('combined', array('ceremony' => '98', 'category' => 'MUSIC (Original Song)', 'class' => 'Music', 'winners_only' => 'true'), array(array('ceremony' => '98', 'category' => 'music-original-song', 'winner' => '1'))),
    array('class and winners', array('class' => 'Production', 'winners_only' => '1'), array(array('class' => 'Production', 'winner' => '1'))),
    array('year only', array('year' => '2025', 'class' => 'Acting'), array(array('ceremony' => '98', 'class' => 'Acting'))),
    array('shared year', array('year' => '1930', 'category' => 'BEST PICTURE'), array(array('ceremony' => '3', 'category' => 'best-picture'), array('ceremony' => '4', 'category' => 'best-picture'))),
    array('matching year and ceremony', array('year' => '2025', 'ceremony' => '98'), array(array('ceremony' => '98'))),
    array('unmatched year', array('year' => 'unknown'), array()),
    array('conflicting year and ceremony', array('year' => '2025', 'ceremony' => '97'), array()),
);
foreach ($cases as $case) {
    list($label, $atts, $expected) = $case;
    $xpath = render_route_semantics('table', base64_encode(json_encode($atts)));
    $links = $xpath->query('//noscript//a');
    verify_route_semantics($links->length === count($expected), $label . ': no-JS destinations must preserve the exact ceremony scope');
    foreach ($links as $index => $link) {
        $url = $link->getAttribute('href');
        verify_route_semantics(parse_url($url, PHP_URL_PATH) === '/oscars/explore/', $label . ': use the canonical Explorer route');
        $actual = array();
        parse_str((string) parse_url($url, PHP_URL_QUERY), $actual);
        ksort($actual); ksort($expected[$index]);
        verify_route_semantics($actual === $expected[$index], $label . ': URL filters changed: ' . json_encode($actual));
    }
    if (!$expected) {
        verify_route_semantics(strpos($xpath->query('//noscript')->item(0)->textContent, 'No matching ceremony') !== false, $label . ': an unavailable year must not silently broaden to every record');
    }
}
echo 'Editorial route semantics: ' . $checks . " checks passed.\n";

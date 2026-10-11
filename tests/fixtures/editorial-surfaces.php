<?php
/** Real production renderers with the existing deterministic WP/data stubs. */
$mode = $argv[1] ?? 'explorer';
ob_start();
if ($mode === 'motion') {
    require dirname(__DIR__) . '/ledger-motion-runtime.php';
    ob_end_clean();
    echo '<main class="aat-container">' . $linked . $linked_arc . '</main>';
    exit;
}
require dirname(__DIR__) . '/ledger-explorer-media-runtime.php';
ob_end_clean();
function selected($actual, $expected, $echo = false) { return (string) $actual === (string) $expected ? ' selected' : ''; }
function checked($actual, $expected, $echo = false) { return $actual === $expected ? ' checked' : ''; }
$state['by'] = '';
$state['sort'] = '';
$facets = array('category' => array(array('value' => 'sound', 'label' => 'Sound Recording and Original Dramatic Score', 'nominations' => 127)));
$filters = $call('render_filters', $state, $facets);
echo '<main class="lle"><section class="lle-hero"><div class="lle-hero__inner"><h1 class="lle-title">Oscar Ledger Explorer</h1><form class="lle-search"><div class="lle-combobox"><label for="lle-q" class="lle-sr">Search the ledger</label><input id="lle-q" class="lle-search__input" type="search"><button class="lle-search__go">Search</button></div></form></div></section>';
echo '<div class="lle-bar">' . $filters . '</div><div class="lle-body">' . $debrief;
echo '<div class="lle-results">' . $acting . $picture . $team . $fallback . $focused . $plate . '</div>';
echo '<div class="lle-groups">' . $groups . '</div></div></main>';

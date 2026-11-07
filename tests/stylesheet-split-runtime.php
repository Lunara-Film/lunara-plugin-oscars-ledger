<?php
/**
 * The 2.8.29 stylesheet split, measured on the real files and the production route map.
 *
 * Four sheets replace the one 203 KB stylesheet: ledger-base.css (every route), ledger-hub.css (the hub
 * routes), ledger-profile.css (title, name and company) and ledger-table.css (the sortable ledger and the
 * trackers). This test proves the properties the split depends on:
 *   - every rule lives in exactly one sheet, and the base sheet carries the variables and keyframes;
 *   - while the superseded academy-awards-table.css still ships (one release, for pages the edge cached
 *     before the deploy), the four sheets together hold exactly its rules;
 *   - every selector in a kind sheet is anchored: it carries a class that only that kind's templates
 *     print and no script adds, so the rule cannot be needed on a route that does not load the sheet;
 *   - route_stylesheets() hands every route the base sheet first and only files that exist.
 */
$root = dirname(__DIR__);
$checks = 0;
function verify($value, $message) {
    ++$GLOBALS['checks'];
    if (!$value) { fwrite(STDERR, "Stylesheet split: $message\n"); exit(1); }
}
/** Leaf rules of a stylesheet: prelude, enclosing conditional chain and body, comments stripped. */
function split_rules($css) {
    $css = preg_replace('~/\*.*?\*/~s', '', (string) $css);
    $rules = array(); $chain = array(); $buffer = ''; $length = strlen($css);
    for ($i = 0; $i < $length; $i++) {
        $char = $css[$i];
        if ($char === '{') {
            $prelude = trim(preg_replace('/\s+/', ' ', $buffer)); $buffer = '';
            $name = $prelude !== '' && $prelude[0] === '@' ? strtolower(strtok(substr($prelude, 1), " (")) : '';
            if (in_array($name, array('media', 'supports', 'layer', 'container'), true)) { $chain[] = $prelude; continue; }
            $depth = 1; $end = $i + 1;
            while ($end < $length && $depth > 0) { if ($css[$end] === '{') $depth++; elseif ($css[$end] === '}') $depth--; $end++; }
            $body = trim(preg_replace('/\s+/', ' ', substr($css, $i + 1, $end - $i - 2)));
            $rules[] = array('prelude' => $prelude, 'chain' => $chain, 'key' => $prelude . '|' . implode('|', $chain) . '|' . $body);
            $i = $end - 1;
        } elseif ($char === '}') {
            array_pop($chain); $buffer = '';
        } else {
            $buffer .= $char;
        }
    }
    return $rules;
}
function has_prelude($rules, $prelude) {
    foreach ($rules as $rule) { if ($rule['prelude'] === $prelude) return true; }
    return false;
}

// ---- One rule, one sheet ----------------------------------------------------------------------------------
$sheets = array('ledger-base.css', 'ledger-hub.css', 'ledger-profile.css', 'ledger-table.css');
$rules = array(); $owner = array();
foreach ($sheets as $sheet) {
    $css = file_get_contents($root . '/assets/css/' . $sheet);
    verify(is_string($css) && $css !== '', "$sheet is readable");
    verify(substr_count($css, '{') === substr_count($css, '}'), "$sheet has balanced braces");
    $rules[$sheet] = split_rules($css);
    verify(count($rules[$sheet]) > 20, "$sheet holds rules");
    foreach ($rules[$sheet] as $rule) {
        $twin = isset($owner[$rule['key']]) ? $owner[$rule['key']] : '';
        verify($twin === '', "$sheet repeats a rule $twin carries: {$rule['prelude']}");
        $owner[$rule['key']] = $sheet;
    }
}
verify(has_prelude($rules['ledger-base.css'], ':root'), 'the base sheet carries the variables');
verify(has_prelude($rules['ledger-base.css'], '@keyframes aat-spin'), 'the base sheet carries the keyframes');
foreach (array('ledger-hub.css', 'ledger-profile.css', 'ledger-table.css') as $sheet) {
    verify(!has_prelude($rules[$sheet], ':root'), "$sheet does not redefine the variables");
}

// ---- The superseded sheet, while it ships ----------------------------------------------------------------
$superseded = $root . '/assets/css/academy-awards-table.css';
if (file_exists($superseded)) {
    // The superseded sheet carried one rule twice (an exact copy); the split keeps the later copy, which the cascade let win.
    $before = array_values(array_unique(array_map(static function ($rule) { return $rule['key']; }, split_rules(file_get_contents($superseded)))));
    $after = array_keys($owner);
    sort($before); sort($after);
    verify($before === $after, 'the four sheets together hold exactly the rules of the superseded sheet');
}

// ---- Anchors: a kind sheet only holds rules its own routes can match -------------------------------------
$templates = array(
    'ledger-hub.css' => array('templates/hub-page.php'),
    'ledger-profile.css' => array('templates/entity-page.php', 'includes/class-aat-ledger-motion.php'),
    'ledger-table.css' => array('templates/table-display.php', 'templates/tracker-v2.php', 'templates/ballot.php'),
    'explorer' => array('templates/explorer-page.php'),
);
$printed = array();
foreach ($templates as $kind => $files) {
    $printed[$kind] = implode("\n", array_map(static function ($file) use ($root) { return (string) file_get_contents($root . '/' . $file); }, $files));
}
$scripts = '';
foreach (glob($root . '/assets/js/*.js') as $file) { if (basename($file) !== 'admin.js') $scripts .= file_get_contents($file) . "\n"; }
$script_adds = static function ($class) use ($scripts) {
    return preg_match('/(class=|classList\.(?:add|toggle)|addClass|toggleClass|className\s*\+?=)[^;\n]*\b' . preg_quote($class, '/') . '\b/', $scripts) === 1;
};
$printed_by = static function ($class) use ($printed) {
    $kinds = array();
    foreach ($printed as $kind => $text) { if (strpos($text, $class) !== false) $kinds[] = $kind; }
    return $kinds;
};
$anchored = 0;
foreach (array('ledger-hub.css', 'ledger-profile.css', 'ledger-table.css') as $sheet) {
    foreach ($rules[$sheet] as $rule) {
        if ($rule['prelude'] === '' || $rule['prelude'][0] === '@') continue;
        foreach (preg_split('/,(?![^(]*\))/', $rule['prelude']) as $selector) {
            $selector = trim($selector);
            if ($sheet === 'ledger-table.css' && preg_match('/(^|[\s,>+~(])(table\.)?dataTable|\.dt-|dtr-|\.dataTables_/', $selector)) { ++$anchored; continue; }
            preg_match_all('/\.(aat-[a-z0-9-]+)/', $selector, $matches);
            $anchor = false;
            foreach ($matches[1] as $class) {
                if ($printed_by($class) === array($sheet) && !$script_adds($class)) { $anchor = true; break; }
            }
            verify($anchor, "$sheet: '$selector' carries no class that only its own templates print; it belongs in ledger-base.css");
            ++$anchored;
        }
    }
}
verify($anchored > 500, 'the kind sheets carry anchored selectors');

// ---- The production route map -----------------------------------------------------------------------------
$source = file_get_contents($root . '/academy-awards-table.php');
preg_match('/    public function route_stylesheets\(/', $source, $match, PREG_OFFSET_CAPTURE);
verify((bool) $match, 'route_stylesheets() exists');
$method = ''; $depth = 0; $opened = false;
foreach (token_get_all('<?php ' . substr($source, $match[0][1])) as $token) {
    if (is_array($token)) { if ($token[0] !== T_OPEN_TAG) $method .= $token[1]; continue; }
    $method .= $token;
    if ($token === '{') { ++$depth; $opened = true; }
    if ($token === '}' && --$depth === 0 && $opened) break;
}
eval('class StylesheetSplitFixture {' . $method . '}');
$fixture = new StylesheetSplitFixture();
foreach (array(
    array(false, false, false, array('aat-styles')),
    array(true, false, false, array('aat-styles', 'aat-hub-styles')),
    array(false, true, false, array('aat-styles', 'aat-profile-styles')),
    array(false, false, true, array('aat-styles', 'aat-table-styles')),
    array(true, false, true, array('aat-styles', 'aat-hub-styles', 'aat-table-styles')),
) as $case) {
    $map = $fixture->route_stylesheets($case[0], $case[1], $case[2]);
    verify(array_keys($map) === $case[3], 'route_stylesheets(' . json_encode(array_slice($case, 0, 3)) . ') hands out ' . implode(', ', $case[3]));
    foreach ($map as $handle => $path) {
        verify(is_file($root . '/' . $path), "$handle points at a file that ships: $path");
        verify(strpos($path, 'academy-awards-table.css') === false, "$handle does not point at the superseded sheet");
    }
}
echo "Stylesheet split passed: $checks checks.\n";

<?php
/**
 * Poster <img> markup (2.8.21): every poster the plugin prints tells the
 * browser how wide it renders, and offers widths a phone can actually use.
 *
 * Run: php tests/poster-img-sizes-runtime.php
 *
 * Measured on lunarafilm.com, 2026-10-10: posters render 72-180px wide on
 * phones and 86-340px on desktop, but carried WordPress's default
 * `sizes="(max-width: 768px) 100vw, 768px"` and a srcset of 200/683/768/780w.
 * A phone asked for the 780w file (128 KB) for a 96px card; Safari and
 * Firefox ignore the `auto` WordPress prepends, so lazy posters got it too.
 *
 * The contract, exercised against the real get_poster_img_html_for_title()
 * with WordPress's image functions stubbed to WordPress's own behavior:
 *   1. A poster with no caller-supplied `sizes` gets the plugin default, which
 *      never claims more than 180px on phones or 340px on desktop. Lazy
 *      posters keep WordPress's `auto, ` prefix in front of it.
 *   2. A caller's `sizes` wins over the default.
 *   3. When Jetpack's image CDN is present, the srcset gains 320/400/480/600w
 *      candidates (CDN `w=` resizes of the original) between the 200w and
 *      683w WordPress registers; a candidate within 50px of an existing one,
 *      or wider than the original, is not added.
 *   4. Without the CDN the srcset is left exactly as WordPress built it.
 *   5. The srcset filter is attached only for the poster call: an image
 *      rendered afterwards gets no extra candidates.
 */
$root = dirname(__DIR__);
$source = file_get_contents($root . '/academy-awards-table.php');

function method_source($source, $name) {
    $start = strpos($source, '    public function ' . $name . '(');
    if ($start === false) throw new RuntimeException('Missing method ' . $name);
    $tokens = token_get_all('<?php ' . substr($source, $start));
    $out = ''; $depth = 0; $opened = false;
    foreach ($tokens as $token) {
        if (is_array($token)) { if ($token[0] !== T_OPEN_TAG) $out .= $token[1]; continue; }
        $out .= $token;
        if ($token === '{') { ++$depth; $opened = true; }
        if ($token === '}' && --$depth === 0 && $opened) return $out;
    }
    throw new RuntimeException('Unclosed method ' . $name);
}
function const_source($source, $name) {
    if (!preg_match('/^\s*const ' . preg_quote($name, '/') . '\s*=.*?;\s*$/m', $source, $m)) throw new RuntimeException('Missing const ' . $name);
    return $m[0] . "\n";
}

$checks = 0;
function check($condition, $message) { global $checks; ++$checks; if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }

// ---- WordPress surface, kept to what the method touches -------------------------------
$GLOBALS['filters'] = array();
function add_filter($hook, $cb, $priority = 10, $args = 1) { $GLOBALS['filters'][$hook][$priority][] = array($cb, $args); return true; }
function remove_filter($hook, $cb, $priority = 10) {
    if (empty($GLOBALS['filters'][$hook][$priority])) return false;
    foreach ($GLOBALS['filters'][$hook][$priority] as $i => $entry) { if ($entry[0] === $cb) { unset($GLOBALS['filters'][$hook][$priority][$i]); return true; } }
    return false;
}
function apply_filters($hook, $value) {
    $args = func_get_args(); array_shift($args);
    if (empty($GLOBALS['filters'][$hook])) return $value;
    ksort($GLOBALS['filters'][$hook]);
    foreach ($GLOBALS['filters'][$hook] as $entries) foreach ($entries as $entry) { $args[0] = $value; $value = call_user_func_array($entry[0], array_slice($args, 0, $entry[1])); }
    return $value;
}
function esc_attr($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function trailingslashit($v) { return rtrim($v, '/') . '/'; }
function wp_get_upload_dir() { return array('baseurl' => 'https://example.test/wp-content/uploads'); }

// The poster: a 780x1170 upload with WordPress's registered sizes, as on the live site.
$GLOBALS['poster_meta'] = array('width' => 780, 'height' => 1170, 'file' => '2026/03/tt0000001-poster.jpg');
$GLOBALS['poster_sources'] = array(
    780 => array('url' => 'https://example.test/wp-content/uploads/2026/03/tt0000001-poster.jpg', 'descriptor' => 'w', 'value' => 780),
    200 => array('url' => 'https://example.test/wp-content/uploads/2026/03/tt0000001-poster-200x300.jpg', 'descriptor' => 'w', 'value' => 200),
    683 => array('url' => 'https://example.test/wp-content/uploads/2026/03/tt0000001-poster-683x1024.jpg', 'descriptor' => 'w', 'value' => 683),
    768 => array('url' => 'https://example.test/wp-content/uploads/2026/03/tt0000001-poster-768x1152.jpg', 'descriptor' => 'w', 'value' => 768),
);
// wp_get_attachment_image, reduced to the parts that matter here: it runs the
// srcset through the filter WordPress runs it through, and, as WordPress does
// for lazy images, puts `auto, ` in front of a sizes value that lacks it.
function wp_get_attachment_image($aid, $size = 'thumbnail', $icon = false, $attr = array()) {
    $src = 'https://example.test/wp-content/uploads/2026/03/tt0000001-poster-200x300.jpg';
    $sources = apply_filters('wp_calculate_image_srcset', $GLOBALS['poster_sources'], array(200, 300), $src, $GLOBALS['poster_meta'], $aid);
    $srcset = array();
    foreach ($sources as $s) $srcset[] = $s['url'] . ' ' . $s['value'] . $s['descriptor'];
    $attr = array_merge(array('src' => $src, 'srcset' => implode(', ', $srcset)), $attr);
    if (isset($attr['loading']) && $attr['loading'] === 'lazy' && isset($attr['sizes']) && strpos($attr['sizes'], 'auto') !== 0) $attr['sizes'] = 'auto, ' . $attr['sizes'];
    $html = '<img width="200" height="300"';
    foreach ($attr as $k => $v) $html .= ' ' . $k . '="' . esc_attr($v) . '"';
    return $html . ' />';
}

$members = const_source($source, 'POSTER_SIZES_DEFAULT') . const_source($source, 'POSTER_SRCSET_WIDTHS') . const_source($source, 'CARD_BACKDROP_WIDTH')
    . method_source($source, 'get_poster_img_html_for_title') . method_source($source, 'add_poster_srcset_widths') . method_source($source, 'get_card_backdrop_image_url');
eval('class PosterRuntime {' . $members . '
public function get_poster_attachment_id_for_title($tt) { return $tt === "tt0000001" ? 41 : 0; }
public function lookup_title_label($tt) { return "Example Film"; }
}');
$plugin = new PosterRuntime();

function attr($html, $name) { return preg_match('/\s' . $name . '="([^"]*)"/', $html, $m) ? html_entity_decode($m[1], ENT_QUOTES) : null; }
function srcset_widths($html) { preg_match_all('/\s(\d+)w(?:,|$)/', attr($html, 'srcset') ?? '', $m); $w = array_map('intval', $m[1]); sort($w); return $w; }

// ---- 1. The default sizes, phone and desktop, with auto kept for lazy posters ----------
$html = $plugin->get_poster_img_html_for_title('tt0000001');
check($html !== '', 'A mapped poster renders.');
$sizes = attr($html, 'sizes');
check(is_string($sizes) && strpos($sizes, 'auto, ') === 0, 'A lazy poster keeps WordPress\'s auto prefix (got "' . $sizes . '").');
check(preg_match('/\(max-width:\s*(\d+)px\)\s*(\d+)px,\s*(\d+)px$/', $sizes, $m) === 1, 'The default sizes is a phone/desktop pair (got "' . $sizes . '").');
check((int) $m[1] >= 768 && (int) $m[1] <= 1024, 'The phone breakpoint sits where the stacked layouts switch (got ' . $m[1] . 'px).');
check((int) $m[2] <= 180 && (int) $m[2] >= 96, 'Phones are told at most 180px, never a viewport width (got ' . $m[2] . 'px).');
check((int) $m[3] <= 340 && (int) $m[3] >= 200, 'Desktop is told at most 340px, never 768px (got ' . $m[3] . 'px).');
check(strpos($sizes, '100vw') === false, 'No poster claims the viewport width.');
$eager = $plugin->get_poster_img_html_for_title('tt0000001', 'medium', array('loading' => 'eager', 'fetchpriority' => 'high'));
check(strpos((string) attr($eager, 'sizes'), 'auto') === false, 'An eager poster carries the default sizes without auto (got "' . attr($eager, 'sizes') . '").');
check(attr($eager, 'sizes') === PosterRuntime::POSTER_SIZES_DEFAULT, 'An eager poster carries exactly the default.');

// ---- 2. A caller's sizes wins ------------------------------------------------------------------
$custom = $plugin->get_poster_img_html_for_title('tt0000001', 'medium', array('sizes' => '(max-width: 720px) 132px, 160px'));
check(attr($custom, 'sizes') === 'auto, (max-width: 720px) 132px, 160px', 'The caller\'s sizes replaces the default (got "' . attr($custom, 'sizes') . '").');

// ---- 3 & 4. srcset candidates with and without the CDN --------------------------------------------
check(srcset_widths($html) === array(200, 683, 768, 780), 'Without the CDN the srcset is WordPress\'s own (got ' . implode('/', srcset_widths($html)) . ').');
if (!function_exists('jetpack_photon_url')) { // declared conditionally so it exists only from here on (PHP hoists unconditional functions)
    function jetpack_photon_url($url, $args = array(), $scheme = null) { return 'https://i0.wp.com/' . preg_replace('#^https?://#', '', $url) . '?w=' . (int) $args['w']; }
}
$cdn = $plugin->get_poster_img_html_for_title('tt0000001');
check(srcset_widths($cdn) === array(200, 320, 400, 480, 600, 683, 768, 780), 'With the CDN the srcset gains 320/400/480/600w (got ' . implode('/', srcset_widths($cdn)) . ').');
check(strpos(attr($cdn, 'srcset'), 'https://i0.wp.com/example.test/wp-content/uploads/2026/03/tt0000001-poster.jpg?w=400 400w') !== false, 'Added candidates are CDN resizes of the original file.');
// A 500px-wide original: 480 is within 50px of the 500w full-size candidate and 600 exceeds it; neither is added.
$GLOBALS['poster_meta'] = array('width' => 500, 'height' => 750, 'file' => '2026/03/tt0000001-poster.jpg');
$GLOBALS['poster_sources'] = array(
    500 => array('url' => 'https://example.test/wp-content/uploads/2026/03/tt0000001-poster.jpg', 'descriptor' => 'w', 'value' => 500),
    200 => array('url' => 'https://example.test/wp-content/uploads/2026/03/tt0000001-poster-200x300.jpg', 'descriptor' => 'w', 'value' => 200),
);
$small = $plugin->get_poster_img_html_for_title('tt0000001');
check(srcset_widths($small) === array(200, 320, 400, 500), 'A 500px original gains only 320/400w (got ' . implode('/', srcset_widths($small)) . ').');

// ---- 5. The filter does not leak ------------------------------------------------------------------
$after = wp_get_attachment_image(99, 'medium', false, array('loading' => 'lazy'));
check(srcset_widths($after) === array(200, 500), 'An image rendered after the poster call gets no extra candidates (got ' . implode('/', srcset_widths($after)) . ').');
check(empty(array_filter($GLOBALS['filters']['wp_calculate_image_srcset'] ?? array())), 'No poster srcset filter stays registered.');

// ---- The callers that know their layout say so -------------------------------------------------------
$hub = file_get_contents($root . '/templates/hub-page.php');
check(preg_match('/\$aat->get_title_visual_package\(\s*\$dossier_bp_ids\[0\],\s*\'medium_large\',\s*false,\s*array\((?:(?!\)\s*\);).)*?\'sizes\'\s*=>\s*\'[^\']*px\'/s', $hub) === 1, 'The ceremony dossier poster (eager, the LCP image on ceremony pages) passes its rendered widths as sizes.');
$tracker = file_get_contents($root . '/templates/tracker-v2.php');
check(preg_match('/get_poster_img_html_for_title\(\$eid,\s*array\(72,\s*108\),\s*array\([^)]*\'sizes\'\s*=>\s*\'72px\'/', $tracker) === 1, 'The tracker\'s 72px poster says 72px.');

// ---- 6. Card backdrops: the poster behind a card's wash is fetched at CARD_BACKDROP_WIDTH ------------
// Hub pages paint the poster as each card's CSS background (eager, 41 cards on a ceremony page) at the <img>'s size.
check(PosterRuntime::CARD_BACKDROP_WIDTH >= 320 && PosterRuntime::CARD_BACKDROP_WIDTH <= 500, 'The card backdrop width is a phone-sized poster, not the 768px the <img> was requested in.');
$w = PosterRuntime::CARD_BACKDROP_WIDTH;
check($plugin->get_card_backdrop_image_url('https://i0.wp.com/example.test/wp-content/uploads/2026/03/tt0000001-poster.jpg?fit=768%2C1152&quality=82&ssl=1') === 'https://i0.wp.com/example.test/wp-content/uploads/2026/03/tt0000001-poster.jpg?w=' . $w . '&ssl=1', 'A CDN poster URL is re-asked for at the backdrop width, its fit= dropped.');
check($plugin->get_card_backdrop_image_url('https://image.tmdb.org/t/p/w780/abc.jpg') === 'https://image.tmdb.org/t/p/w500/abc.jpg', 'A TMDB poster steps down to the smallest TMDB size at or above the backdrop width (got ' . $plugin->get_card_backdrop_image_url('https://image.tmdb.org/t/p/w780/abc.jpg') . ').');
check($plugin->get_card_backdrop_image_url('https://image.tmdb.org/t/p/original/abc.jpg', 300) === 'https://image.tmdb.org/t/p/w342/abc.jpg', 'A TMDB original steps down the same way.');
check($plugin->get_card_backdrop_image_url('https://example.test/wp-content/uploads/2026/03/tt0000001-poster-768x1152.jpg') === 'https://i0.wp.com/example.test/wp-content/uploads/2026/03/tt0000001-poster-768x1152.jpg?w=' . $w, 'A plain upload URL goes through the CDN at the backdrop width.');
check($plugin->get_card_backdrop_image_url('https://elsewhere.test/poster.jpg') === 'https://elsewhere.test/poster.jpg', 'A URL from anywhere else is left alone.');
check($plugin->get_card_backdrop_image_url('') === '', 'An empty URL stays empty.');
// The hub and landing templates no longer paint a poster behind its own card, so no template closure
// calls the resize helper; the helper keeps the checks above for any future caller.

echo "poster-img-sizes-runtime: $checks checks passed.\n";

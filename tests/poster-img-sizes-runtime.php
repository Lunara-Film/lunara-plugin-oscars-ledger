<?php
/**
 * Poster and picture <img> markup (2.8.21, 2.8.28): every picture the plugin
 * prints tells the browser the width its frame paints, offers widths a phone
 * can use, and never asks the CDN for more than the original holds.
 *
 * Run: php tests/poster-img-sizes-runtime.php
 *
 * Measured on lunarafilm.com, 2026-10-10: posters render 72-180px wide on
 * phones and 86-340px on desktop, but carried WordPress's default
 * `sizes="(max-width: 768px) 100vw, 768px"` and a srcset of 200/683/768/780w.
 * A phone asked for the 780w file (128 KB) for a 96px card. 2026-11-07: the
 * index posters a template rewrote from lazy to eager kept WordPress's
 * "auto, " and fetched 780-3158px files for an 84px column; the Winner
 * Circle printed 16 URL-only photos (1.2 MB) for 104-168px boxes.
 *
 * The contract, exercised against the real builders with WordPress's image
 * functions stubbed to WordPress's own behavior:
 *   1. A poster with no caller-supplied `sizes` gets the plugin default, the
 *      entity hero's measured frame: never a viewport width. Lazy posters
 *      keep WordPress's `auto, ` prefix; eager posters carry no auto.
 *   2. A caller's `sizes` wins over the default; poster_sizes_for() hands
 *      every template slot a measured value, none of them a bare 100vw.
 *   3. When Jetpack's image CDN is present, the srcset gains 320/400/480/600w
 *      candidates (CDN `w=` resizes of the original) between the 200w and
 *      683w WordPress registers; a candidate within 50px of an existing one,
 *      or wider than the original, is not added.
 *   4. Without the CDN the srcset is left exactly as WordPress built it.
 *   5. The srcset filter is attached only for the builder call: an image
 *      rendered afterwards gets no extra candidates.
 *   6. Crop candidates for a fixed-ratio frame stop at the widest crop the
 *      original holds, the native crop joins only in place of removed widths,
 *      and a picture the CDN cannot resize keeps WordPress's own srcset.
 *   7. get_attachment_img_html() prints a library picture with those crops,
 *      eager without auto and lazy with it.
 *   8. Card backdrops / URL-only pictures are asked for at the frame's width.
 */
$root = dirname(__DIR__);
$source = file_get_contents($root . '/academy-awards-table.php');

function method_source($source, $name) {
    if (!preg_match('/\n    (?:public|private|protected) function ' . preg_quote($name, '/') . '\(/', $source, $m, PREG_OFFSET_CAPTURE)) throw new RuntimeException('Missing method ' . $name);
    $tokens = token_get_all('<?php ' . substr($source, $m[0][1]));
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
    // The constant's statement, from `const NAME` to the `;` that closes it (strings are single tokens, so a `;` inside one does not end it).
    $start = strpos($source, '    const ' . $name . ' ');
    if ($start === false) throw new RuntimeException('Missing const ' . $name);
    $out = ''; $depth = 0;
    foreach (token_get_all('<?php ' . substr($source, $start)) as $token) {
        if (is_array($token)) { if ($token[0] !== T_OPEN_TAG) $out .= $token[1]; continue; }
        $out .= $token;
        if ($token === '(' || $token === '[') ++$depth;
        if ($token === ')' || $token === ']') --$depth;
        if ($token === ';' && $depth === 0) return $out . "\n";
    }
    throw new RuntimeException('Unclosed const ' . $name);
}

$checks = 0;
function check($condition, $message) { global $checks; ++$checks; if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }

// ---- WordPress surface, kept to what the methods touch -------------------------------
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
// Other library pictures, by attachment id: a 16:9 still, a 2:3 portrait, a tiny poster, and one without measurements.
$GLOBALS['attachment_meta'] = array(
    41 => &$GLOBALS['poster_meta'],
    51 => array('width' => 768, 'height' => 432, 'file' => 'stills/one-battle.webp'),
    52 => array('width' => 666, 'height' => 1000, 'file' => '2026/06/nm0000759-manual-profile.jpg'),
    53 => array('width' => 200, 'height' => 300, 'file' => '2026/07/small.jpg'),
    54 => array('file' => '2026/07/unmeasured.jpg'),
);
function wp_get_attachment_metadata($aid) { return $GLOBALS['attachment_meta'][$aid] ?? false; }
// wp_get_attachment_image, reduced to the parts that matter here: it runs the
// srcset through the filter WordPress runs it through unless the caller gave
// one, lets a caller's src win, and, as WordPress does for lazy images, puts
// `auto, ` in front of a sizes value that lacks it.
function wp_get_attachment_image($aid, $size = 'thumbnail', $icon = false, $attr = array()) {
    $src = 'https://example.test/wp-content/uploads/2026/03/tt0000001-poster-200x300.jpg';
    if (empty($attr['srcset'])) {
        $sources = apply_filters('wp_calculate_image_srcset', $GLOBALS['poster_sources'], array(200, 300), $src, $GLOBALS['poster_meta'], $aid);
        $srcset = array();
        foreach ($sources as $s) $srcset[] = $s['url'] . ' ' . $s['value'] . $s['descriptor'];
        $attr['srcset'] = implode(', ', $srcset);
    }
    $attr = array_merge(array('src' => $src), $attr);
    if (isset($attr['loading']) && $attr['loading'] === 'lazy' && isset($attr['sizes']) && strpos($attr['sizes'], 'auto') !== 0) $attr['sizes'] = 'auto, ' . $attr['sizes'];
    $html = '<img width="200" height="300"';
    foreach ($attr as $k => $v) $html .= ' ' . $k . '="' . esc_attr($v) . '"';
    return $html . ' />';
}

$members = const_source($source, 'POSTER_SIZES_DEFAULT') . const_source($source, 'POSTER_SIZES') . const_source($source, 'POSTER_SRCSET_WIDTHS') . const_source($source, 'CARD_BACKDROP_WIDTH')
    . method_source($source, 'get_poster_img_html_for_title') . method_source($source, 'add_poster_srcset_widths') . method_source($source, 'get_card_backdrop_image_url')
    . method_source($source, 'image_cdn_callback') . method_source($source, 'poster_sizes_for') . method_source($source, 'get_attachment_crop_srcset') . method_source($source, 'get_attachment_img_html');
eval('class PosterRuntime {' . $members . '
public function get_poster_attachment_id_for_title($tt) { return $tt === "tt0000001" ? 41 : 0; }
public function lookup_title_label($tt) { return "Example Film"; }
}');
$plugin = new PosterRuntime();

function attr($html, $name) { return preg_match('/\s' . $name . '="([^"]*)"/', $html, $m) ? html_entity_decode($m[1], ENT_QUOTES) : null; }
function srcset_widths($html) { preg_match_all('/\s(\d+)w(?:,|$)/', attr($html, 'srcset') ?? '', $m); $w = array_map('intval', $m[1]); sort($w); return $w; }
function sizes_lengths($sizes) {
    // Every length in a sizes list, media conditions stripped: "(max-width: 540px) 132px, 168px" -> ["132px", "168px"].
    $out = array();
    foreach (preg_split('/,(?![^(]*\))/', (string) $sizes) as $entry) { // commas inside min()/calc() do not split
        $entry = trim($entry);
        if ($entry === '' || $entry === 'auto') continue;
        $out[] = trim(preg_replace('/^\([^)]*\)\s*/', '', $entry));
    }
    return $out;
}

// ---- 1. The default sizes, with auto kept for lazy posters and dropped for eager ones ----
$html = $plugin->get_poster_img_html_for_title('tt0000001');
check($html !== '', 'A mapped poster renders.');
$sizes = attr($html, 'sizes');
check(is_string($sizes) && strpos($sizes, 'auto, ') === 0, 'A lazy poster keeps WordPress\'s auto prefix (got "' . $sizes . '").');
check(substr($sizes, 6) === PosterRuntime::POSTER_SIZES_DEFAULT, 'A lazy poster carries the default behind the auto.');
check(strpos($sizes, '100vw') === false && strpos(PosterRuntime::POSTER_SIZES_DEFAULT, '100vw') === false, 'No poster claims the viewport width.');
foreach (sizes_lengths(PosterRuntime::POSTER_SIZES_DEFAULT) as $length) {
    check(preg_match('/^(?:\d+px|min\(\d+vw, \d+px\))$/', $length) === 1, 'Each default length is a pixel width or a capped viewport share (got "' . $length . '").');
}
check(preg_match('/(\d+)px$/', PosterRuntime::POSTER_SIZES_DEFAULT, $m) === 1 && (int) $m[1] <= 340 && (int) $m[1] >= 200, 'Desktop is told at most 340px, the measured hero frame, never 768px (got ' . ($m[1] ?? '?') . 'px).');
$eager = $plugin->get_poster_img_html_for_title('tt0000001', 'medium', array('loading' => 'eager', 'fetchpriority' => 'high'));
check(strpos((string) attr($eager, 'sizes'), 'auto') === false, 'An eager poster carries the default sizes without auto (got "' . attr($eager, 'sizes') . '").');
check(attr($eager, 'sizes') === PosterRuntime::POSTER_SIZES_DEFAULT, 'An eager poster carries exactly the default.');
check(attr($eager, 'loading') === 'eager' && attr($eager, 'fetchpriority') === 'high', 'The caller\'s loading and fetchpriority reach the tag.');

// ---- 2. A caller's sizes wins; every slot has a measured value ---------------------------------
$custom = $plugin->get_poster_img_html_for_title('tt0000001', 'medium', array('sizes' => '(max-width: 720px) 132px, 160px'));
check(attr($custom, 'sizes') === 'auto, (max-width: 720px) 132px, 160px', 'The caller\'s sizes replaces the default (got "' . attr($custom, 'sizes') . '").');
check(count(PosterRuntime::POSTER_SIZES) >= 12, 'The slot map covers the template slots.');
foreach (PosterRuntime::POSTER_SIZES as $slot => $value) {
    check($plugin->poster_sizes_for($slot) === $value, 'poster_sizes_for() returns the slot\'s value for ' . $slot . '.');
    check($value !== '' && strpos($value, '100vw,') === false && preg_match('/(?:^|\s)100vw$/', $value) === 0, 'Slot ' . $slot . ' never claims a bare viewport width (got "' . $value . '").');
    foreach (sizes_lengths($value) as $length) {
        check(preg_match('/^(?:\d+px|min\(\d+vw, \d+px\)|calc\(100vw - \d+px\))$/', $length) === 1, 'Slot ' . $slot . ' length "' . $length . '" is a pixel width, a capped viewport share or the card\'s own viewport share.');
    }
    check(preg_match('/(\d+)px\)?$/', $value, $m) === 1 && (int) $m[1] <= 340, 'Slot ' . $slot . ' tells desktop at most the hero\'s 340px (got "' . $value . '").');
}
check($plugin->poster_sizes_for('no-such-slot') === PosterRuntime::POSTER_SIZES_DEFAULT, 'An unknown slot falls back to the default.');
foreach (array('index' => 84, 'card' => 168, 'era' => 56, 'winner' => 168, 'hero' => 340, 'related' => 280) as $slot => $widest) {
    check(preg_match('/(\d+)px\)?$/', $plugin->poster_sizes_for($slot), $m) === 1 && (int) $m[1] === $widest, 'Slot ' . $slot . ' ends at its measured desktop width ' . $widest . 'px.');
}

// ---- 3 & 4. srcset candidates with and without the CDN --------------------------------------------
check(srcset_widths($html) === array(200, 683, 768, 780), 'Without the CDN the srcset is WordPress\'s own (got ' . implode('/', srcset_widths($html)) . ').');
$no_cdn_crop = $plugin->get_attachment_crop_srcset(51, 2, 3, array(104, 132, 168, 208, 264, 336), 336);
check($no_cdn_crop === array('src' => '', 'srcset' => ''), 'Without the CDN there are no crop candidates.');
$no_cdn_pic = $plugin->get_attachment_img_html(51, 'medium_large', array('class' => 'aat-winner-circle-photo', 'sizes' => '168px'), array(2, 3), array(104, 132, 168, 208, 264, 336), 336);
check(srcset_widths($no_cdn_pic) === array(200, 683, 768, 780) && strpos(attr($no_cdn_pic, 'src'), 'resize=') === false, 'Without the CDN a library picture keeps WordPress\'s own srcset and src.');
if (!function_exists('jetpack_photon_url')) { // declared conditionally so it exists only from here on (PHP hoists unconditional functions)
    function jetpack_photon_url($url, $args = array(), $scheme = null) {
        $base = 'https://i0.wp.com/' . preg_replace('#^https?://#', '', $url);
        if (isset($args['resize'])) return $base . '?resize=' . $args['resize'];
        return $base . '?w=' . (int) $args['w'];
    }
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

// ---- 6. Crop candidates stop at the source ---------------------------------------------------------
$winner_widths = array(104, 132, 168, 208, 264, 336);
$still = $plugin->get_attachment_crop_srcset(51, 2, 3, $winner_widths, 336);
// A 768x432 still cropped 2:3 can be at most 288px wide (432 * 2/3); 288 is not a tenth wider than 264, so it does not join.
check(preg_match_all('/ (\d+)w/', $still['srcset'], $m) === 5 && array_map('intval', $m[1]) === array(104, 132, 168, 208, 264), 'A 16:9 still in a 2:3 box stops at the crop its height allows (got "' . $still['srcset'] . '").');
check(strpos($still['srcset'], 'https://i0.wp.com/example.test/wp-content/uploads/stills/one-battle.webp?resize=168,252 168w') !== false, 'Each candidate is a crop of the original at the frame ratio.');
check($still['src'] === 'https://i0.wp.com/example.test/wp-content/uploads/stills/one-battle.webp?resize=264,396', 'The src is the candidate nearest the fallback width that survived the cap.');
$portrait = $plugin->get_attachment_crop_srcset(52, 2, 3, $winner_widths, 336);
check(preg_match_all('/ (\d+)w/', $portrait['srcset'], $m) === 6 && array_map('intval', $m[1]) === $winner_widths, 'A 2:3 portrait keeps every width up to its own (got "' . $portrait['srcset'] . '").');
check($portrait['src'] === 'https://i0.wp.com/example.test/wp-content/uploads/2026/06/nm0000759-manual-profile.jpg?resize=336,504', 'The src is the fallback width when it survived.');
$tiny = $plugin->get_attachment_crop_srcset(53, 2, 3, $winner_widths, 336);
check(preg_match_all('/ (\d+)w/', $tiny['srcset'], $m) === 4 && array_map('intval', $m[1]) === array(104, 132, 168, 200), 'A 200px original keeps the widths below it and adds its own width in place of the removed ones.');
check($plugin->get_attachment_crop_srcset(54, 2, 3, $winner_widths, 336) === array('src' => '', 'srcset' => ''), 'A picture without measurements offers no crops (the CDN would scale a guess up).');
check($plugin->get_attachment_crop_srcset(53, 2, 3, array(336, 504), 336) === array('src' => '', 'srcset' => ''), 'Fewer than two surviving candidates means no crop list.');
check($plugin->get_attachment_crop_srcset(0, 2, 3, $winner_widths) === array('src' => '', 'srcset' => '') && $plugin->get_attachment_crop_srcset(51, 0, 3, $winner_widths) === array('src' => '', 'srcset' => ''), 'No attachment or no frame means no crops.');

// ---- 7. The picture builder ---------------------------------------------------------------------------
$pic = $plugin->get_attachment_img_html(51, 'medium_large', array('class' => 'aat-winner-circle-photo', 'alt' => 'One Battle after Another', 'sizes' => '(max-width: 540px) 132px, (max-width: 720px) 104px, 168px'), array(2, 3), $winner_widths, 336);
check(attr($pic, 'class') === 'aat-winner-circle-photo' && attr($pic, 'alt') === 'One Battle after Another', 'The builder keeps the caller\'s class and alt.');
check(attr($pic, 'loading') === 'lazy' && attr($pic, 'decoding') === 'async', 'A picture is lazy and async by default.');
check(attr($pic, 'sizes') === 'auto, (max-width: 540px) 132px, (max-width: 720px) 104px, 168px', 'A lazy picture keeps auto in front of the measured sizes.');
check(srcset_widths($pic) === array(104, 132, 168, 208, 264) && attr($pic, 'src') === $still['src'], 'The picture carries the frame crops and their fallback src.');
$hero = $plugin->get_attachment_img_html(52, 'medium_large', array('class' => 'aat-entity-portrait', 'loading' => 'eager', 'fetchpriority' => 'high', 'sizes' => PosterRuntime::POSTER_SIZES['hero']));
check(attr($hero, 'loading') === 'eager' && attr($hero, 'fetchpriority') === 'high' && attr($hero, 'sizes') === PosterRuntime::POSTER_SIZES['hero'], 'An eager portrait carries the hero sizes with no auto.');
check(srcset_widths($hero) === array(200, 320, 400, 500), 'Without a frame the portrait carries WordPress\'s widths plus the CDN widths.');
check($plugin->get_attachment_img_html(0) === '', 'No attachment, no tag.');
check(empty(array_filter($GLOBALS['filters']['wp_calculate_image_srcset'] ?? array())), 'The builder leaves no srcset filter registered either.');

// ---- 8. URL-only pictures and card backdrops are asked for at the frame's width ----------------------
check(PosterRuntime::CARD_BACKDROP_WIDTH >= 320 && PosterRuntime::CARD_BACKDROP_WIDTH <= 500, 'The card backdrop width is a phone-sized poster, not the 768px the <img> was requested in.');
$w = PosterRuntime::CARD_BACKDROP_WIDTH;
check($plugin->get_card_backdrop_image_url('https://i0.wp.com/example.test/wp-content/uploads/2026/03/tt0000001-poster.jpg?fit=768%2C1152&quality=82&ssl=1') === 'https://i0.wp.com/example.test/wp-content/uploads/2026/03/tt0000001-poster.jpg?w=' . $w . '&ssl=1', 'A CDN poster URL is re-asked for at the backdrop width, its fit= dropped.');
check($plugin->get_card_backdrop_image_url('https://i0.wp.com/example.test/wp-content/uploads/2026/03/tt0000001-poster.jpg?fit=768%2C1152', 168) === 'https://i0.wp.com/example.test/wp-content/uploads/2026/03/tt0000001-poster.jpg?w=168&ssl=1', 'An index poster with only a URL is asked for at twice its 84px column.');
check($plugin->get_card_backdrop_image_url('https://image.tmdb.org/t/p/w780/abc.jpg') === 'https://image.tmdb.org/t/p/w500/abc.jpg', 'A TMDB poster steps down to the smallest TMDB size at or above the backdrop width (got ' . $plugin->get_card_backdrop_image_url('https://image.tmdb.org/t/p/w780/abc.jpg') . ').');
check($plugin->get_card_backdrop_image_url('https://image.tmdb.org/t/p/original/abc.jpg', 300) === 'https://image.tmdb.org/t/p/w342/abc.jpg', 'A TMDB original steps down the same way.');
check($plugin->get_card_backdrop_image_url('https://image.tmdb.org/t/p/w500/abc.jpg', 112) === 'https://image.tmdb.org/t/p/w185/abc.jpg', 'A TMDB poster for a 56px era chapter steps down to w185.');
check($plugin->get_card_backdrop_image_url('https://example.test/wp-content/uploads/2026/03/tt0000001-poster-768x1152.jpg') === 'https://i0.wp.com/example.test/wp-content/uploads/2026/03/tt0000001-poster-768x1152.jpg?w=' . $w, 'A plain upload URL goes through the CDN at the backdrop width.');
check($plugin->get_card_backdrop_image_url('https://elsewhere.test/poster.jpg') === 'https://elsewhere.test/poster.jpg', 'A URL from anywhere else is left alone.');
check($plugin->get_card_backdrop_image_url('') === '', 'An empty URL stays empty.');

echo "poster-img-sizes-runtime: $checks checks passed.\n";

<?php
/**
 * Optional static transport for the Explorer's existing escaped JSON fragments.
 * The award tables, read API and PHP renderer remain authoritative. A missing,
 * expired or unavailable upload always falls back to the ordinary fragment URL.
 * JSON files are immutable; no gzip/CDN/server configuration is assumed here.
 */
if (!defined('ABSPATH')) { exit; }

final class AAT_Explorer_Store {
    const SCHEMA = 1;
    const MAX_AGE = 300;
    const MAX_ENTRIES = 128;
    const MAX_BYTES = 1048576;
    const POINTER = 'aat_explorer_snapshot_manifest';
    const REVISION = 'aat_explorer_snapshot_revision';
    const HOOK = 'aat_explorer_snapshot_warm';

    public static function init() {
        foreach (array('aat_after_data_import', 'aat_ledger_swapped', 'aat_reporting_tables_rebuilt', 'customize_save_after') as $hook) {
            add_action($hook, array(__CLASS__, 'invalidate'), 100);
        }
        add_action('updated_option', array(__CLASS__, 'option_changed'), 100, 1);
        add_action('transition_post_status', array(__CLASS__, 'post_changed'), 100, 3);
        add_action('before_delete_post', array(__CLASS__, 'post_deleted'), 100);
        foreach (array('added_post_meta', 'updated_post_meta', 'deleted_post_meta') as $hook) {
            add_action($hook, array(__CLASS__, 'meta_changed'), 100, 4);
        }
        add_action(self::HOOK, array(__CLASS__, 'warm'));
        add_action('aat_page_store_daily', array(__CLASS__, 'collect_garbage'));
    }

    public static function invalidate() {
        // A unique token avoids two concurrent edits losing a counter increment.
        update_option(self::REVISION, wp_generate_uuid4(), false);
        self::schedule();
    }
    public static function option_changed($name) {
        if (in_array($name, array('home', 'siteurl', 'permalink_structure', 'WPLANG', 'blogname', 'blogdescription', 'timezone_string'), true)) { self::invalidate(); }
    }
    public static function post_changed($new, $old, $post) {
        if (is_object($post) && ($new === 'publish' || $old === 'publish') && in_array($post->post_type, array('review', 'movie', 'person', 'attachment'), true)) { self::invalidate(); }
    }
    public static function post_deleted($id) {
        if (in_array(get_post_type($id), array('review', 'movie', 'person', 'attachment'), true)) { self::invalidate(); }
    }
    public static function meta_changed($meta_id, $post_id, $key, $value = null) {
        if (in_array(get_post_type($post_id), array('review', 'movie', 'person', 'attachment'), true) && $key !== '_edit_lock' && $key !== '_edit_last') { self::invalidate(); }
    }
    private static function schedule() {
        if (get_transient('aat_explorer_snapshot_backoff') !== false) { return; }
        if (!wp_next_scheduled(self::HOOK)) { wp_schedule_single_event(time() + 60, self::HOOK); }
    }

    /** Read the generation fence directly: a request-local option cache must not
     * conceal a concurrent import/edit while a fragment is being rendered. */
    public static function generation() {
        global $wpdb;
        if (!isset($wpdb->options)) { return ''; }
        $names = array('home', 'siteurl', 'stylesheet', 'blogname', 'blog_public', 'aat_dataset_stamp', 'aat_label_rules_version', 'aat_page_store_generation', self::REVISION, 'aat_bundled_import_state');
        $sql = $wpdb->prepare('SELECT option_name, option_value FROM ' . $wpdb->options . ' WHERE option_name IN (' . implode(',', array_fill(0, count($names), '%s')) . ')', $names);
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)) { return ''; }
        $values = array_fill_keys($names, '');
        foreach ($rows as $row) { $values[$row['option_name']] = (string) $row['option_value']; }
        if ($values['blog_public'] !== '1' || $values['aat_dataset_stamp'] === '') { return ''; }
        $import = maybe_unserialize($values['aat_bundled_import_state']);
        if (is_array($import) && !empty($import['status']) && !in_array($import['status'], array('completed', 'failed', 'cancelled', 'swap_failed'), true)) { return ''; }
        unset($values['aat_bundled_import_state']);
        $style = WP_CONTENT_DIR . '/themes/' . $values['stylesheet'] . '/style.css';
        clearstatcache(true, $style);
        $values['theme_mtime'] = is_file($style) ? (string) filemtime($style) : '';
        $values['plugin'] = AAT_VERSION;
        $values['schema'] = self::SCHEMA;
        $values['api_schema'] = AAT_Read_API::RESPONSE_SCHEMA;
        return substr(hash('sha256', wp_json_encode($values)), 0, 24);
    }

    public static function eligible() {
        if (get_transient('aat_explorer_snapshot_backoff') !== false) { return false; }
        if (get_option('aat_explorer_snapshots', 'on') === 'off' || AAT_Page_Store::mode() !== 'on' || is_user_logged_in()) { return false; }
        if (!empty($_SERVER['HTTP_X_WP_NONCE']) || !empty($_SERVER['HTTP_AUTHORIZATION'])) { return false; }
        foreach (array_keys((array) $_COOKIE) as $name) {
            if ($name !== AAT_Page_Store::WARM_COOKIE && preg_match('/^(wordpress_|wp-postpass_|wp-settings|comment_author_|woocommerce_)/', $name)) { return false; }
        }
        return !isset($_SERVER['REQUEST_METHOD']) || in_array($_SERVER['REQUEST_METHOD'], array('GET', 'HEAD'), true);
    }

    private static function location($generation) {
        $upload = wp_upload_dir(null, false);
        if (!empty($upload['error']) || empty($upload['basedir']) || empty($upload['baseurl']) || !preg_match('#^https?://#i', $upload['baseurl'])) { return null; }
        $suffix = '/lunara-ledger/explorer/' . $generation;
        return array('dir' => rtrim($upload['basedir'], '/\\') . $suffix, 'url' => rtrim($upload['baseurl'], '/') . $suffix);
    }

    /** Only current, bounded, public fragments are advertised to the browser. */
    public static function descriptor($generation) {
        if ($generation === '' || !self::eligible()) { return array(); }
        if ($generation !== self::generation()) { self::schedule(); return array(); }
        $pointer = get_option(self::POINTER, array());
        if (!is_array($pointer) || ($pointer['generation'] ?? '') !== $generation || ($pointer['created_at'] ?? 0) < time() - self::MAX_AGE || empty($pointer['url'])) {
            self::schedule();
            return array();
        }
        return array('generation' => $generation, 'url' => $pointer['url'], 'max_age' => self::MAX_AGE);
    }

    /** Write to a sibling temp file, validate full bytes, then rename atomically.
     * Filesystem/object-storage adapters that cannot do this simply get no cache. */
    private static function write_json($path, $json) {
        if (is_file($path)) { return hash_file('sha256', $path) === hash('sha256', $json); }
        $temp = $path . '.' . str_replace('-', '', wp_generate_uuid4()) . '.tmp';
        $written = @file_put_contents($temp, $json, LOCK_EX);
        if ($written !== strlen($json) || @hash_file('sha256', $temp) !== hash('sha256', $json)) { @unlink($temp); return false; }
        if (!@rename($temp, $path)) { @unlink($temp); return false; }
        return true;
    }

    /** Public PHP renderer output only. Never accepts an untrusted POST payload. */
    public static function publish($payload, $generation, $healthy) {
        if (get_transient('aat_explorer_snapshot_backoff') !== false) { return ''; }
        if (!$healthy || $generation === '' || !self::eligible() || $generation !== self::generation()) { return ''; }
        if (!is_array($payload) || empty($payload['url']) || !is_string($payload['title'] ?? null) || !isset($payload['regions'])) { return ''; }
        foreach (array('filters', 'chips', 'debrief', 'status', 'results', 'pager') as $region) {
            if (!is_string($payload['regions'][$region] ?? null)) { return ''; }
        }
        $base = AAT_Explorer::base_url();
        if (strtok($payload['url'], '?') !== $base) { return ''; }
        $where = self::location($generation);
        if (!$where || (!is_dir($where['dir']) && !wp_mkdir_p($where['dir']))) { set_transient('aat_explorer_snapshot_backoff', true, 900); return ''; }
        $lock = @fopen($where['dir'] . '/.publish.lock', 'c');
        if (!$lock) { set_transient('aat_explorer_snapshot_backoff', true, 900); return ''; }
        if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return ''; }
        try {
            // The lock serializes publishers; the fresh DB fence detects edits.
            if ($generation !== self::generation()) { return ''; }
            $manifest_path = $where['dir'] . '/current.json';
            $manifest = is_file($manifest_path) ? json_decode((string) @file_get_contents($manifest_path), true) : array();
            $entries = is_array($manifest['entries'] ?? null) ? $manifest['entries'] : array();
            foreach ($entries as $url => $entry) {
                if (($entry['created_at'] ?? 0) < time() - self::MAX_AGE) { unset($entries[$url]); }
            }
            if (!isset($entries[$payload['url']]) && count($entries) >= self::MAX_ENTRIES) { return ''; }
            $content_hash = hash('sha256', wp_json_encode($payload));
            if (isset($entries[$payload['url']]) && ($entries[$payload['url']]['content_hash'] ?? '') === $content_hash) { return $entries[$payload['url']]['url']; }
            $payload['snapshot'] = array('schema' => self::SCHEMA, 'generation' => $generation, 'created_at' => time());
            $json = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($json) || strlen($json) > self::MAX_BYTES) { return ''; }
            $name = hash('sha256', $json) . '.json';
            if (!self::write_json($where['dir'] . '/' . $name, $json)) { return ''; }
            $public = $where['url'] . '/' . $name;
            $entries[$payload['url']] = array('url' => $public, 'created_at' => time(), 'content_hash' => $content_hash);
            $manifest = array('schema' => self::SCHEMA, 'generation' => $generation, 'created_at' => time(), 'entries' => $entries);
            $manifest_json = wp_json_encode($manifest, JSON_UNESCAPED_SLASHES);
            if (!is_string($manifest_json)) { return ''; }
            $manifest_name = 'manifest-' . hash('sha256', $manifest_json) . '.json';
            if (!self::write_json($where['dir'] . '/' . $manifest_name, $manifest_json)) { return ''; }
            if ($generation !== self::generation()) { return ''; }
            // current.json is private bookkeeping; browsers only get hash URLs.
            $temp = $manifest_path . '.next';
            if (@file_put_contents($temp, $manifest_json, LOCK_EX) !== strlen($manifest_json) || !@rename($temp, $manifest_path)) { @unlink($temp); return ''; }
            update_option(self::POINTER, array('generation' => $generation, 'created_at' => time(), 'entries' => count($entries), 'url' => $where['url'] . '/' . $manifest_name), false);
            return $public;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public static function report() {
        $generation = self::generation();
        $pointer = get_option(self::POINTER, array());
        $current = is_array($pointer) && ($pointer['generation'] ?? '') === $generation && $generation !== '';
        return array('enabled' => get_option('aat_explorer_snapshots', 'on') !== 'off' && AAT_Page_Store::mode() === 'on', 'generation' => $generation, 'ready' => $current && ($pointer['created_at'] ?? 0) >= time() - self::MAX_AGE, 'entries' => $current ? (int) ($pointer['entries'] ?? 0) : 0, 'max_age' => self::MAX_AGE, 'storage_backoff' => get_transient('aat_explorer_snapshot_backoff') !== false);
    }

    /** Delete only our own expired immutable JSON files. Retain a full day so
     * cached manifests and open tabs can fall back without a deploy purge. */
    public static function collect_garbage() {
        $upload = wp_upload_dir(null, false);
        if (!empty($upload['error']) || empty($upload['basedir'])) { return; }
        $base = realpath(rtrim($upload['basedir'], '/\\') . '/lunara-ledger/explorer');
        if (!$base) { return; }
        foreach ((array) glob($base . '/*', GLOB_ONLYDIR) as $dir) {
            if (!preg_match('/^[a-f0-9]{24}$/', basename($dir)) || is_link($dir) || realpath(dirname($dir)) !== $base) { continue; }
            $lock = @fopen($dir . '/.publish.lock', 'c');
            if (!$lock) { continue; }
            if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); continue; }
            foreach ((array) glob($dir . '/*.json') as $file) {
                if (!is_link($file) && is_file($file) && filemtime($file) < time() - 86400) { @unlink($file); }
            }
            flock($lock, LOCK_UN); fclose($lock);
        }
    }

    /** Six useful first-page states, not a crawl of every filter combination. */
    public static function warm() {
        if (get_option('aat_explorer_snapshots', 'on') === 'off' || AAT_Page_Store::mode() !== 'on' || self::generation() === '') { return; }
        $base = AAT_Explorer::base_url();
        $deadline = microtime(true) + 25;
        foreach (array(array(), array('winner' => 1), array('by' => 'film'), array('by' => 'person'), array('by' => 'company'), array('by' => 'category')) as $state) {
            if (microtime(true) >= $deadline) { break; }
            $state['fragment'] = '1';
            $response = wp_remote_get(add_query_arg($state, $base), array('timeout' => min(8, max(1, $deadline - microtime(true))), 'redirection' => 0, 'cookies' => array(AAT_Page_Store::WARM_COOKIE => '1'), 'headers' => array('Accept' => 'application/json', 'Cache-Control' => 'no-cache')));
            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) { break; }
        }
    }
}

<?php
/**
 * Academy Awards Table - Hub Page Template
 *
 * Routes:
 *   /{base}/ceremonies/
 *   /{base}/categories/
 *   /{base}/about/
 *   /{base}/ceremony/{N}/
 *   /{base}/category/{slug}/
 */

if (!defined('ABSPATH')) {
    exit;
}

$aat = Academy_Awards_Table::get_instance();
$hub = sanitize_text_field(get_query_var('aat_hub'));
$hub_id = sanitize_text_field(get_query_var('aat_hub_id'));

global $wpdb;
$table_name = $wpdb->prefix . 'academy_awards';

// Common dynamic scope values are supplied by projection-aware helpers.
$hub_stats = $aat->get_hub_page_stats();
$total_records    = intval($hub_stats['total_records'] ?? 0);
$total_winners    = intval($hub_stats['total_winners'] ?? 0);
$total_categories = intval($hub_stats['total_categories'] ?? 0);
$total_ceremonies = intval($hub_stats['total_ceremonies'] ?? 0);
$min_ceremony     = intval($hub_stats['min_ceremony'] ?? 0);
$max_ceremony     = intval($hub_stats['max_ceremony'] ?? 0);
$span = '';
if ($min_ceremony > 0 && $max_ceremony > 0) {
    $first_year = $aat->get_ceremony_year($min_ceremony);
    $last_year = $aat->get_ceremony_year($max_ceremony);
    if ($first_year && $last_year) {
        $span = $first_year . "\u{2013}" . $last_year;
    }
}

// Helper: mark 404 and show friendly page
$mark_404 = function() {
    global $wp_query;
    if (is_object($wp_query)) {
        $wp_query->set_404();
    }
    status_header(404);
    nocache_headers();
};

$db_url = $aat->get_database_url();
$table_view_requested = ( isset($_GET['view']) && sanitize_key(wp_unslash($_GET['view'])) === 'table' );

$aat_pipe_display = function($value) {
    $parts = array_values(array_filter(array_map('trim', explode('|', (string) $value)), 'strlen'));
    return implode(' | ', $parts);
};

// Pipe-delimited source fields (film, detail) read as prose: "7th Heaven, Street Angel and Sunrise".
$aat_film_display = function($value) use ($aat) {
    return method_exists($aat, 'format_pipe_list')
        ? $aat->format_pipe_list($value)
        : trim((string) $value);
};

$aat_clean_nominee_label = function($value) {
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    $patterns = array(
        '/^Written by\s+/i',
        '/^Music and Lyric by\s+/i',
        '/^Music by\s+/i',
        '/^Lyric by\s+/i',
        '/^Produced by\s+/i',
        '/^Directed by\s+/i',
    );

    return trim((string) preg_replace($patterns, '', $value));
};

$aat_credit_people_key = function($value) use ($aat_clean_nominee_label) {
    $value = $aat_clean_nominee_label($value);
    if ($value === '') {
        return '';
    }
    if (function_exists('remove_accents')) {
        $value = remove_accents($value);
    }
    $value = strtolower(str_replace('&', ' and ', $value));
    $value = preg_replace('/\band\b/', ' ', $value);
    return trim((string) preg_replace('/[^a-z0-9]+/', ' ', (string) $value));
};

$aat_join_meta = function($parts) {
    $parts = array_values(array_filter(array_map('trim', (array) $parts), 'strlen'));
    if (empty($parts)) {
        return '';
    }

    $out = array();
    foreach ($parts as $part) {
        $out[] = '<span>' . esc_html($part) . '</span>';
    }

    return implode('<span class="aat-meta-sep" aria-hidden="true">&middot;</span>', $out);
};

$aat_winner_primary = function($entry) use ($aat_pipe_display, $aat_film_display, $aat_clean_nominee_label) {
    $category = strtoupper(trim((string) ($entry['canonical_category'] ?? '')));
    $film = $aat_film_display($entry['film'] ?? '');
    $detail = $aat_film_display($entry['detail'] ?? '');
    $name = $aat_clean_nominee_label($entry['name'] ?? '');
    $nominees = $aat_clean_nominee_label($aat_pipe_display($entry['nominees'] ?? ''));

    if (in_array($category, array('BEST PICTURE', 'ANIMATED FEATURE FILM', 'DOCUMENTARY (Feature)', 'INTERNATIONAL FEATURE FILM', 'SHORT FILM (Animated)', 'SHORT FILM (Live Action)'), true) && $film !== '') {
        return $film;
    }

    if ($category === 'MUSIC (ORIGINAL SONG)' && $detail !== '') {
        return $detail;
    }

    if ((strpos($category, 'WRITING (') === 0 || strpos($category, 'MUSIC (ORIGINAL SCORE') === 0) && $film !== '') {
        return $film;
    }

    if ((strpos($category, 'WRITING (') === 0 || strpos($category, 'MUSIC (ORIGINAL ') === 0) && $nominees !== '') {
        return $nominees;
    }

    if ($name !== '') {
        return $name;
    }

    if ($nominees !== '') {
        return $nominees;
    }

    return $film;
};

$aat_winner_secondary = function($entry) use ($aat_pipe_display, $aat_film_display, $aat_winner_primary, $aat_clean_nominee_label) {
    $primary = $aat_winner_primary($entry);
    $film = $aat_film_display($entry['film'] ?? '');
    $detail = $aat_film_display($entry['detail'] ?? '');
    $nominees = $aat_clean_nominee_label($aat_pipe_display($entry['nominees'] ?? ''));

    if ($film !== '' && $film !== $primary) {
        return $film;
    }

    if ($detail !== '' && $detail !== $primary) {
        return $detail;
    }

    if ($nominees !== '' && $nominees !== $primary) {
        return $nominees;
    }

    return '';
};

$aat_build_entity_url = function($id) use ($aat) {
    $id = strtolower(trim((string) $id));
    return $id !== '' ? $aat->get_entity_url($id) : '';
};

$aat_entity_section_url = function($url, $section = 'oscar-history') {
    $url = trim((string) $url);
    $section = sanitize_title(trim((string) $section, "# \t\n\r\0\x0B"));

    if ($url === '') {
        return '';
    }

    if ($section === '') {
        return $url;
    }

    return preg_replace('/#.*/', '', $url) . '#' . $section;
};

$aat_normalize_comparable_name = function($value) {
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    if (function_exists('remove_accents')) {
        $value = remove_accents($value);
    }

    $value = strtolower($value);
    $value = str_replace('&', ' and ', $value);
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value);
    $value = preg_replace('/\s+/', ' ', (string) $value);

    return trim((string) $value);
};

$aat_is_department_credit_label = function($value) use ($aat_normalize_comparable_name) {
    $normalized = $aat_normalize_comparable_name($value);
    if ($normalized === '') {
        return false;
    }

    return (bool) preg_match('/\b(?:studio sound department|sound department|sound dept|sound recording department)\b/', $normalized);
};

$aat_entity_url_kind = function($url) {
    $url = trim((string) $url);
    if ($url === '') {
        return '';
    }

    $path = (string) parse_url($url, PHP_URL_PATH);
    if (preg_match('~/oscars/company/~i', $path)) {
        return 'company';
    }
    if (preg_match('~/oscars/name/~i', $path)) {
        return 'person';
    }
    if (preg_match('~/oscars/title/~i', $path)) {
        return 'film';
    }

    return '';
};

$aat_person_history_action_meta = function($history_url, $category) use ($aat_entity_url_kind) {
    $category = strtoupper(trim((string) $category));

    if ($aat_entity_url_kind($history_url) === 'company') {
        return array(
            'label' => __('Company History', 'academy-awards-table'),
            'kind'  => 'company-history',
        );
    }

    return array(
        'label' => ($category === 'BEST PICTURE') ? __('Producer History', 'academy-awards-table') : __('Person History', 'academy-awards-table'),
        'kind'  => ($category === 'BEST PICTURE') ? 'producer-history' : 'person-history',
    );
};

$aat_resolve_entry_name_link = function($entry) use ($aat_build_entity_url, $aat_clean_nominee_label, $aat_normalize_comparable_name, $aat_is_department_credit_label) {
    $explicit_name = $aat_clean_nominee_label($entry['name'] ?? '');
    $nominee_value = trim((string) ($entry['nominees'] ?? ''));
    $nominee_ids = trim((string) ($entry['nominee_ids'] ?? ''));
    $nominee_parts = array_values(array_filter(array_map(function($part) use ($aat_clean_nominee_label) {
        return $aat_clean_nominee_label($part);
    }, explode('|', $nominee_value)), 'strlen'));
    $id_parts = array_values(array_filter(array_map('trim', explode('|', $nominee_ids)), 'strlen'));

    if ($aat_is_department_credit_label($explicit_name) || (count($nominee_parts) === 1 && $aat_is_department_credit_label($nominee_parts[0]))) {
        return array(
            'label' => $explicit_name !== '' ? $explicit_name : (string) ($nominee_parts[0] ?? ''),
            'url' => '',
        );
    }

    if ($explicit_name === '' && count($nominee_parts) === 1 && count($id_parts) === 1) {
        return array(
            'label' => (string) $nominee_parts[0],
            'url' => $aat_build_entity_url($id_parts[0]),
        );
    }

    if ($explicit_name !== '' && count($nominee_parts) === count($id_parts) && !empty($id_parts)) {
        $target = $aat_normalize_comparable_name($explicit_name);
        $matches = array();

        foreach ($nominee_parts as $index => $nominee_part) {
            if ($aat_normalize_comparable_name($nominee_part) === $target && isset($id_parts[$index])) {
                $matches[] = $id_parts[$index];
            }
        }

        if (count($matches) === 1) {
            return array(
                'label' => $explicit_name,
                'url' => $aat_build_entity_url($matches[0]),
            );
        }
    }

    if ($explicit_name !== '' && count($id_parts) === 1) {
        return array(
            'label' => $explicit_name,
            'url' => $aat_build_entity_url($id_parts[0]),
        );
    }

    return array(
        'label' => $explicit_name,
        'url' => '',
    );
};

$aat_enrich_winner_entry_links = function($entry) use ($aat, $aat_film_display, $aat_winner_primary, $aat_winner_secondary, $aat_resolve_entry_name_link, $aat_build_entity_url, $aat_entity_section_url) {
    $entry = is_array($entry) ? $entry : array();
    $category = trim((string) ($entry['canonical_category'] ?? ''));
    $film = $aat_film_display($entry['film'] ?? '');
    $film_id = strtolower(trim((string) ($entry['film_id'] ?? '')));
    $film_url = '';

    if ($film_id !== '') {
        $film_url = $aat_build_entity_url($film_id);
    }
    if ($film_url === '' && !empty($entry['film_url'])) {
        $film_url = (string) $entry['film_url'];
    }

    $name_link = $aat_resolve_entry_name_link($entry);
    $primary_label = $aat_winner_primary($entry);
    $secondary_label = $aat_winner_secondary($entry);
    $primary_url = '';
    $secondary_url = '';

    if ($film !== '' && $primary_label === $film && $film_url !== '') {
        $primary_url = $film_url;
    } elseif (!empty($name_link['label']) && $primary_label === $name_link['label'] && !empty($name_link['url'])) {
        $primary_url = (string) $name_link['url'];
    }

    if ($film !== '' && $secondary_label === $film && $film_url !== '') {
        $secondary_url = $film_url;
    } elseif (!empty($name_link['label']) && $secondary_label === $name_link['label'] && !empty($name_link['url'])) {
        $secondary_url = (string) $name_link['url'];
    }

    $person_url = !empty($name_link['url']) ? (string) $name_link['url'] : '';

    $entry['category_url'] = $category !== '' ? $aat->get_category_url($category) : '';
    $entry['film_url'] = $film_url;
    $entry['film_history_url'] = $film_url !== '' ? $aat_entity_section_url($film_url, 'oscar-history') : '';
    $entry['person_url'] = $person_url;
    $entry['person_history_url'] = $person_url !== '' ? $aat_entity_section_url($person_url, 'oscar-history') : '';
    $entry['person_label'] = !empty($name_link['label']) ? (string) $name_link['label'] : '';
    $entry['primary_label'] = $primary_label;
    $entry['primary_url'] = $primary_url;
    $entry['secondary_label'] = $secondary_label;
    $entry['secondary_url'] = $secondary_url;

    return $entry;
};

$aat_build_person_link_items = function($entry) use ($aat, $aat_clean_nominee_label, $aat_build_entity_url, $aat_is_department_credit_label) {
    $entry = is_array($entry) ? $entry : array();
    $split_credit_labels = function($value) use ($aat_clean_nominee_label) {
        $value = trim((string) $value);
        if ($value === '') {
            return array();
        }

        $parts = strpos($value, '|') !== false
            ? explode('|', $value)
            : preg_split('/\s*(?:,|\s+and\s+)\s*/i', $value);

        return array_values(array_filter(array_map(function($part) use ($aat_clean_nominee_label) {
            return $aat_clean_nominee_label($part);
        }, (array) $parts), 'strlen'));
    };

    $labels = $split_credit_labels($entry['nominees'] ?? '');
    $ids = array_values(array_filter(array_map('trim', explode('|', (string) ($entry['nominee_ids'] ?? ''))), 'strlen'));

    if (empty($labels) && !empty($entry['person_label'])) {
        $labels[] = trim((string) $entry['person_label']);
    } elseif (empty($labels) && !empty($entry['name'])) {
        $labels = $split_credit_labels($entry['name']);
    }

    $items = array();
    $seen = array();

    foreach ($labels as $index => $label) {
        $label = trim((string) $label);
        if ($label === '') {
            continue;
        }

        if ($aat_is_department_credit_label($label)) {
            continue;
        }

        $entity_id = isset($ids[$index]) ? strtolower(trim((string) $ids[$index])) : '';
        $url = '';

        if ($entity_id !== '' && preg_match('/^(nm\d{7,9}|lnm-[a-z0-9-]+)$/i', $entity_id)) {
            $url = $aat_build_entity_url($entity_id);
        }

        if ($url === '' && method_exists($aat, 'get_name_entity_link_by_label')) {
            $resolved = $aat->get_name_entity_link_by_label($label);
            if (!empty($resolved['url'])) {
                $url = (string) $resolved['url'];
                if (!empty($resolved['label'])) {
                    $label = (string) $resolved['label'];
                }
            }
        }

        if ($url === '') {
            continue;
        }

        $fingerprint = strtolower($url . '|' . $label);
        if (isset($seen[$fingerprint])) {
            continue;
        }

        $seen[$fingerprint] = true;
        $items[] = array(
            'label' => $label,
            'url'   => $url,
        );
    }

    return $items;
};

$aat_render_hub_text_link = function($label, $url = '', $class = '') use ($aat_is_department_credit_label) {
    $label = trim((string) $label);
    if ($label === '') {
        return '';
    }

    $class_attr = trim((string) $class);
    if ($url !== '') {
        return '<a class="' . esc_attr($class_attr) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }

    if ($aat_is_department_credit_label($label)) {
        $class_attr = trim($class_attr . ' aat-department-credit-label');
    }

    return '<span class="' . esc_attr($class_attr) . '">' . esc_html($label) . '</span>';
};

$aat_render_pipe_links = function($value_list, $id_list = '', $class = 'aat-hub-inline-link') use ($aat, $aat_build_entity_url, $aat_clean_nominee_label, $aat_is_department_credit_label) {
    $raw_value_list = (string) $value_list;
    $values = array_values(array_filter(array_map(function($part) use ($aat_clean_nominee_label) {
        return $aat_clean_nominee_label($part);
    }, explode('|', $raw_value_list)), 'strlen'));
    $ids = array_values(array_filter(array_map('trim', explode('|', (string) $id_list)), 'strlen'));

    if (empty($ids) && strpos($raw_value_list, '|') === false && strpos((string) $class, 'aat-ballot-main-link') === false) {
        $split_values = array_values(array_filter(array_map(function($part) use ($aat_clean_nominee_label) {
            return $aat_clean_nominee_label($part);
        }, (array) preg_split('/\s*(?:,|\s+and\s+)\s*/i', $raw_value_list)), 'strlen'));
        if (count($split_values) > 1) {
            $values = $split_values;
        }
    }

    if (empty($values)) {
        return '';
    }

    $out = array();
    foreach ($values as $index => $value) {
        $is_department_credit = $aat_is_department_credit_label($value);
        $url = (!$is_department_credit && isset($ids[$index])) ? $aat_build_entity_url($ids[$index]) : '';
        if (!$is_department_credit && $url === '' && method_exists($aat, 'get_name_entity_link_by_label')) {
            $resolved = $aat->get_name_entity_link_by_label($value);
            if (!empty($resolved['url'])) {
                $url = (string) $resolved['url'];
                if (!empty($resolved['label'])) {
                    $value = (string) $resolved['label'];
                }
            }
        }
        if ($url !== '') {
            $out[] = '<a class="' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html($value) . '</a>';
        } else {
            $span_class = trim((string) $class . ($is_department_credit ? ' aat-department-credit-label' : ''));
            $out[] = '<span class="' . esc_attr($span_class) . '">' . esc_html($value) . '</span>';
        }
    }

    return implode('<span class="aat-meta-sep" aria-hidden="true">&middot;</span>', $out);
};

$aat_build_winner_actions = function($entry, $category_url = '', $ceremony_url = '') use ($aat_person_history_action_meta) {
    $entry = is_array($entry) ? $entry : array();
    $category = strtoupper(trim((string) ($entry['canonical_category'] ?? '')));
    $film_url = trim((string) ($entry['film_url'] ?? ''));
    $person_url = trim((string) ($entry['person_url'] ?? ''));
    $person_label = trim((string) ($entry['person_label'] ?? ''));
    $primary_url = trim((string) ($entry['primary_url'] ?? ''));
    $secondary_url = trim((string) ($entry['secondary_url'] ?? ''));
    $film_history_url = trim((string) ($entry['film_history_url'] ?? ''));
    $person_history_url = trim((string) ($entry['person_history_url'] ?? ''));
    $primary_label = trim((string) ($entry['primary_label'] ?? ''));
    $secondary_label = trim((string) ($entry['secondary_label'] ?? ''));
    $category_url = trim((string) $category_url);
    $ceremony_url = trim((string) $ceremony_url);

    $visible_urls = array_values(array_unique(array_filter(array_map('trim', array(
        $category_url,
        $primary_url,
        $secondary_url,
    )))));

    $resolve_label = function($url, $value_label = '') use ($category, $category_url, $ceremony_url, $film_url, $person_url, $person_label, $primary_url, $secondary_url) {
        $url = trim((string) $url);
        $value_label = trim((string) $value_label);

        if ($url === '') {
            return array('label' => '', 'kind' => '');
        }

        if ($category_url !== '' && $url === $category_url) {
            return array('label' => __('Category', 'academy-awards-table'), 'kind' => 'category');
        }

        if ($ceremony_url !== '' && $url === $ceremony_url) {
            return array('label' => __('Ceremony', 'academy-awards-table'), 'kind' => 'ceremony');
        }

        if ($film_url !== '' && $url === $film_url) {
            return array('label' => __('Film', 'academy-awards-table'), 'kind' => 'film');
        }

        if ($person_url !== '' && $url === $person_url) {
            if ($category === 'BEST PICTURE') {
                return array('label' => __('Producer', 'academy-awards-table'), 'kind' => 'producer');
            }

            if ($primary_url === $film_url && $secondary_url === $person_url) {
                return array('label' => __('Winner', 'academy-awards-table'), 'kind' => 'winner');
            }

            if ($value_label !== '' && $person_label !== '' && $value_label === $person_label) {
                return array('label' => __('Person', 'academy-awards-table'), 'kind' => 'person');
            }

            return array('label' => __('Winner', 'academy-awards-table'), 'kind' => 'winner');
        }

        return array('label' => __('Profile', 'academy-awards-table'), 'kind' => 'profile');
    };

    $actions = array();
    $add_action = function($label, $url, $kind = '') use (&$actions, $visible_urls) {
        $label = trim((string) $label);
        $url = trim((string) $url);
        $kind = trim((string) $kind);
        if ($label === '' || $url === '') {
            return;
        }

        if (in_array($url, $visible_urls, true)) {
            return;
        }

        foreach ($actions as $existing_action) {
            if ((string) $existing_action['url'] === $url) {
                return;
            }
        }

        $actions[] = array(
            'label' => $label,
            'url'   => $url,
            'kind'  => $kind,
        );
    };

    $primary_action = $resolve_label($primary_url, $primary_label);
    $secondary_action = $resolve_label($secondary_url, $secondary_label);
    $category_action = $resolve_label($category_url);
    $ceremony_action = $resolve_label($ceremony_url);

    $add_action($primary_action['label'], $primary_url, $primary_action['kind']);
    $add_action($secondary_action['label'], $secondary_url, $secondary_action['kind']);
    $add_action($category_action['label'], $category_url, $category_action['kind']);
    $add_action($ceremony_action['label'], $ceremony_url, $ceremony_action['kind']);

    if ($film_history_url !== '' && $film_history_url !== $film_url) {
        $add_action(__('Film History', 'academy-awards-table'), $film_history_url, 'film-history');
    }

    if ($person_history_url !== '' && $person_history_url !== $person_url) {
        $person_history_meta = $aat_person_history_action_meta($person_history_url, $category);
        $add_action($person_history_meta['label'], $person_history_url, $person_history_meta['kind']);
    }

    return $actions;
};

$aat_get_visual_package = function($film_id, $size = 'medium_large') use ($aat) {
    static $visual_cache = array();

    $film_id = strtolower(trim((string) $film_id));
    $size = trim((string) $size);
    if ($film_id === '' || !preg_match('/^tt\d+$/', $film_id)) {
        return array();
    }

    $cache_key = $film_id . '|' . $size;
    if (isset($visual_cache[$cache_key])) {
        return $visual_cache[$cache_key];
    }

    $visual_cache[$cache_key] = method_exists($aat, 'get_title_visual_package') ? (array) $aat->get_title_visual_package($film_id, $size) : array();

    return $visual_cache[$cache_key];
};

/**
 * Portrait package for the first person credited on a winner row.
 * Person-led categories (acting, directing, writing) have no film poster of their own
 * to show beside the name, and every card in a grid needs the same media box.
 */
$aat_get_person_visual = function($entry, $size = 'medium_large') use ($aat) {
    static $person_cache = array();

    if (!method_exists($aat, 'get_person_visual_package')) {
        return array();
    }

    foreach (explode('|', (string) ($entry['nominee_ids'] ?? '')) as $candidate) {
        $candidate = strtolower(trim($candidate));
        if (!preg_match('/^nm\d+$/', $candidate)) {
            continue;
        }

        $cache_key = $candidate . '|' . $size;
        if (!isset($person_cache[$cache_key])) {
            $person_cache[$cache_key] = (array) $aat->get_person_visual_package($candidate, $size, false);
        }
        if (!empty($person_cache[$cache_key]['portrait_url'])) {
            return $person_cache[$cache_key];
        }
    }

    return array();
};

$aat_extract_title_ids = function($entry) {
    $title_ids = array();

    foreach (explode('|', (string) ($entry['film_id'] ?? '')) as $candidate_title_id) {
        $candidate_title_id = strtolower(trim((string) $candidate_title_id));
        if ($candidate_title_id === '' || !preg_match('/^tt\d+$/', $candidate_title_id)) {
            continue;
        }

        $title_ids[$candidate_title_id] = true;
    }

    return array_keys($title_ids);
};

$aat_get_curated_winner_photo_map = function() {
    $map = array();

    if (function_exists('lunara_get_oscars_winner_photo_map')) {
        $map = (array) lunara_get_oscars_winner_photo_map();
    }

    if (empty($map)) {
        $map = array(
            'BEST PICTURE'                  => 30253,
            'DIRECTING'                     => 30249,
            'ACTOR IN A LEADING ROLE'       => 30247,
            'ACTRESS IN A LEADING ROLE'     => 30251,
            'ACTOR IN A SUPPORTING ROLE'    => 30255,
            'ACTRESS IN A SUPPORTING ROLE'  => 30256,
            'WRITING (Original Screenplay)' => 30252,
            'WRITING (Adapted Screenplay)'  => 28520,
            'CINEMATOGRAPHY'                => 30248,
            'FILM EDITING'                  => 30250,
            'MUSIC (Original Score)'        => 30254,
        );
    }

    $normalized = array();
    foreach ($map as $category => $attachment_id) {
        $normalized[strtoupper(trim((string) $category))] = absint($attachment_id);
    }

    return $normalized;
};

$aat_get_curated_winner_visual = function($entry, $size = 'medium_large') use ($aat_get_curated_winner_photo_map) {
    $category = strtoupper(trim((string) ($entry['canonical_category'] ?? '')));
    if ($category === '') {
        return array();
    }

    $map = $aat_get_curated_winner_photo_map();
    $attachment_id = isset($map[$category]) ? absint($map[$category]) : 0;
    if ($attachment_id <= 0) {
        return array();
    }

    $photo_url = wp_get_attachment_image_url($attachment_id, $size);
    if (!$photo_url) {
        return array();
    }

    $photo_alt = '';
    if ($category === 'BEST PICTURE') {
        $photo_alt = trim((string) ($entry['film'] ?? ''));
    }
    if ($photo_alt === '') {
        $photo_alt = trim((string) ($entry['name'] ?? ''));
    }
    if ($photo_alt === '') {
        $photo_alt = trim((string) ($entry['film'] ?? ''));
    }
    if ($photo_alt === '') {
        $photo_alt = trim((string) ($entry['category_label'] ?? $category));
    }

    return array(
        'poster_url'   => $photo_url,
        'backdrop_url' => $photo_url,
        'poster_html'  => sprintf(
            '<img class="aat-winner-circle-photo" src="%s" alt="%s" loading="lazy" decoding="async" />',
            esc_url($photo_url),
            esc_attr($photo_alt)
        ),
    );
};

$aat_get_related_review_limit = function() {
    $limit = function_exists('get_theme_mod') ? get_theme_mod('lunara_oscars_related_reviews_count', 6) : 6;
    return max(2, min(8, absint($limit)));
};

$aat_get_related_review_treatment = function() {
    $allowed = array('standard-grid', 'compact-rail', 'feature-strip');
    $treatment = function_exists('get_theme_mod') ? sanitize_key((string) get_theme_mod('lunara_oscars_related_reviews_treatment', 'standard-grid')) : 'standard-grid';
    return in_array($treatment, $allowed, true) ? $treatment : 'standard-grid';
};

$aat_related_review_treatment_class = 'aat-related-treatment-' . $aat_get_related_review_treatment();

$aat_build_hub_review_cards = function($title_entries, $limit = 6) use ($aat) {
    $cards = array();
    $seen_reviews = array();

    foreach ((array) $title_entries as $entry) {
        $film_id = strtolower(trim((string) ($entry['film_id'] ?? '')));
        if (!preg_match('/^tt\d+$/', $film_id)) {
            continue;
        }

        $review_ids = $aat->get_review_ids_for_title_id($film_id, 1);
        if (empty($review_ids[0])) {
            continue;
        }

        $review_id = intval($review_ids[0]);
        if ($review_id <= 0 || isset($seen_reviews[$review_id])) {
            continue;
        }

        $review_url = get_permalink($review_id);
        if (!is_string($review_url) || $review_url === '') {
            continue;
        }

        $film_label = trim((string) ($entry['film'] ?? ''));
        if ($film_label === '') {
            $film_label = $aat->lookup_title_label($film_id);
        }

        $sort_date = get_post_time('U', true, $review_id);
        if (!$sort_date) {
            $sort_date = 0;
        }

        $cards[] = array(
            'review_id' => $review_id,
            'review_url' => $review_url,
            'review_title' => get_the_title($review_id),
            'film_id' => $film_id,
            'film_label' => $film_label,
            'film_url' => $aat->get_entity_url($film_id),
            'film_year' => trim((string) ($entry['year'] ?? '')),
            'sort_date' => intval($sort_date),
        );

        $seen_reviews[$review_id] = true;
    }

    usort($cards, function($a, $b) {
        $cmp = intval($b['sort_date'] ?? 0) <=> intval($a['sort_date'] ?? 0);
        if ($cmp !== 0) {
            return $cmp;
        }

        return strcasecmp((string) ($a['review_title'] ?? ''), (string) ($b['review_title'] ?? ''));
    });

    if ($limit > 0) {
        $cards = array_slice($cards, 0, $limit);
    }

    // 2.8.13: images are resolved only for the cards that are shown (it used to be
    // every reviewed title, up to 18, before the cut to $limit).
    foreach ($cards as $i => $card) {
        $film_id = $card['film_id'];
        $film_label = $card['film_label'];
        $visual = method_exists($aat, 'get_title_visual_package') ? $aat->get_title_visual_package($film_id, 'medium_large') : array();
        $visual_media_html = '';
        if (!empty($visual['poster_html'])) {
            $visual_media_html = (string) $visual['poster_html'];
        } elseif (!empty($visual['poster_url'])) {
            $visual_media_html = '<img class="aat-related-review-image" src="' . esc_url($visual['poster_url']) . '" alt="' . esc_attr(sprintf(__('%s poster', 'academy-awards-table'), $film_label)) . '" loading="lazy" decoding="async" />';
        } elseif (!empty($visual['backdrop_url'])) {
            $visual_media_html = '<img class="aat-related-review-image" src="' . esc_url($visual['backdrop_url']) . '" alt="' . esc_attr($film_label) . '" loading="lazy" decoding="async" />';
        }
        $cards[$i]['review_thumb'] = get_the_post_thumbnail($card['review_id'], 'medium_large', array(
            'class' => 'aat-related-review-image',
            'loading' => 'lazy',
            'decoding' => 'async',
            'sizes' => '(max-width: 720px) 100vw, 360px',
        ));
        $cards[$i]['fallback_html'] = $visual_media_html;
    }

    return $cards;
};

$aat_build_title_spotlight = function($film_id, $fallback_label = '', $meta_lines = array(), $badge_label = '') use ($aat, $db_url) {
    $film_id = strtolower(trim((string) $film_id));
    if (!preg_match('/^tt\d+$/', $film_id)) {
        return array();
    }

    $visual = method_exists($aat, 'get_title_visual_package') ? $aat->get_title_visual_package($film_id, 'medium_large') : array();
    $film_label = trim((string) $fallback_label);
    if ($film_label === '') {
        $film_label = $aat->lookup_title_label($film_id);
    }
    if ($film_label === '' && !empty($visual['title'])) {
        $film_label = (string) $visual['title'];
    }
    if ($film_label === '') {
        $film_label = strtoupper($film_id);
    }

    $film_url = $aat->get_entity_url($film_id);
    $meta_lines = array_values(array_filter(array_map('trim', (array) $meta_lines), 'strlen'));

    return array(
        'film_id' => $film_id,
        'film_label' => $film_label,
        'film_url' => $film_url ? $film_url : $db_url,
        'poster_html' => !empty($visual['poster_html']) ? $visual['poster_html'] : '',
        'poster_url' => !empty($visual['poster_url']) ? $visual['poster_url'] : '',
        'fallback_html' => !empty($visual['fallback_html']) ? $visual['fallback_html'] : '',
        'backdrop_url' => !empty($visual['backdrop_url']) ? $visual['backdrop_url'] : '',
        'meta_lines' => $meta_lines,
        'badge_label' => trim((string) $badge_label),
    );
};

// Optional: if the site owner created WordPress pages for these hubs (as recommended),
// pull their editor content in as the intro copy so they can control tone/voice.
$wp_hub_page = null;
$wp_hub_content = '';
if (in_array($hub, array('ceremonies','categories','about'), true)) {
    $wp_hub_page = $aat->get_hub_page_post($hub);
    if ($wp_hub_page instanceof WP_Post) {
        // The hub already is the Oscar Ledger. A database block or ledger
        // shortcode left in the page's own content would render the whole ledger
        // landing inside this header (nested frames, cards off the right edge on
        // phones), so the intro keeps only the editor's own copy.
        $wp_hub_content = trim((string) preg_replace(
            array(
                '#<!--\s*wp:academy-awards/database\b.*?(?:/-->|<!--\s*/wp:academy-awards/database\s*-->)#s',
                '/\[(?:academy_awards|lunara_awards_tracker)\b[^\]]*\](?:.*?\[\/(?:academy_awards|lunara_awards_tracker)\])?/s',
            ),
            '',
            (string) $wp_hub_page->post_content
        ));
    }
}

get_header();
?>

<div class="aat-container aat-hub-page">

    <?php
        // The trail names the index this page belongs to and the page itself,
        // in words rather than route tokens: "Categories › Best Picture".
        $aat_crumb_parent = ucfirst($hub);
        $aat_crumb_parent_url = '';
        $aat_crumb_current = '';
        if ($hub === 'ceremony') {
            $aat_crumb_parent = __('Ceremonies', 'academy-awards-table');
            $aat_crumb_parent_url = $aat->get_ceremonies_index_url();
            $aat_crumb_current = intval($hub_id) > 0
                ? sprintf(/* translators: %s: ordinal ceremony number */ __('%s Academy Awards', 'academy-awards-table'), $aat->ordinal(intval($hub_id)))
                : (string) $hub_id;
        } elseif ($hub === 'category') {
            $aat_crumb_parent = __('Categories', 'academy-awards-table');
            $aat_crumb_parent_url = $aat->get_categories_index_url();
            $aat_crumb_canonical = $aat->resolve_category_slug($hub_id);
            $aat_crumb_current = !empty($aat_crumb_canonical) ? (string) $aat->format_category_display($aat_crumb_canonical) : (string) $hub_id;
            if ($aat_crumb_current !== '' && strtoupper($aat_crumb_current) === $aat_crumb_current) {
                $aat_crumb_current = str_replace(array(' And ', ' Of ', ' In ', ' For '), array(' and ', ' of ', ' in ', ' for '), ucwords(strtolower($aat_crumb_current)));
            }
        }
    ?>
    <p class="aat-hub-breadcrumbs">
        <a href="<?php echo esc_url($db_url); ?>"><?php echo esc_html__('Oscar Ledger', 'academy-awards-table'); ?></a>
        <span class="aat-footer-sep">&rsaquo;</span>
        <?php if ($aat_crumb_parent_url !== '') : ?>
            <a href="<?php echo esc_url($aat_crumb_parent_url); ?>"><?php echo esc_html($aat_crumb_parent); ?></a>
        <?php else : ?>
            <?php echo esc_html($aat_crumb_parent); ?>
        <?php endif; ?>
        <?php if ($aat_crumb_current !== '') : ?>
            <span class="aat-footer-sep">&rsaquo;</span>
            <span aria-current="page"><?php echo esc_html($aat_crumb_current); ?></span>
        <?php endif; ?>
    </p>

    <?php
        // Resolve a small index poster for a tt ID — local library first,
        // cached TMDB URL second, never a remote lookup during render.
        $aat_index_poster_html = function ($tt, $eager = false) use ($aat) {
            $tt = strtolower(trim((string) $tt));
            if ($tt === '' || !method_exists($aat, 'get_title_visual_package')) {
                return '';
            }
            $visual = $aat->get_title_visual_package($tt, 'medium', false);
            if (!empty($visual['poster_html'])) {
                $poster_html = (string) $visual['poster_html'];
                return $eager ? str_replace('loading="lazy"', 'loading="eager"', $poster_html) : $poster_html;
            }
            if (!empty($visual['poster_url'])) {
                return '<img src="' . esc_url((string) $visual['poster_url']) . '" alt="" loading="' . ($eager ? 'eager' : 'lazy') . '" decoding="async" />';
            }
            return '';
        };

        // CEREMONIES INDEX
        if ($hub === 'ceremonies') :
            $rows = $wpdb->get_results(
                "SELECT ceremony, MIN(year) AS year_label, COUNT(*) AS nominations, SUM(winner) AS wins, COUNT(DISTINCT canonical_category) AS categories FROM $table_name GROUP BY ceremony ORDER BY ceremony DESC",
                ARRAY_A
            );

            // One grouped read maps every ceremony to its most awarded title; a
            // title with a single win is not called out.
            $aat_index_top_map = array();
            $aat_index_top_rows = $wpdb->get_results(
                "SELECT ceremony, film, film_id, COUNT(*) AS wins FROM $table_name WHERE winner = 1 AND film != '' GROUP BY ceremony, film, film_id ORDER BY ceremony DESC, wins DESC, film ASC",
                ARRAY_A
            );
            if (is_array($aat_index_top_rows)) {
                foreach ($aat_index_top_rows as $top_row) {
                    $top_cer = intval($top_row['ceremony'] ?? 0);
                    if ($top_cer <= 0 || isset($aat_index_top_map[$top_cer]) || intval($top_row['wins'] ?? 0) < 2) continue;
                    $top_ids = $aat_extract_title_ids(array('film_id' => (string) ($top_row['film_id'] ?? '')));
                    $aat_index_top_map[$top_cer] = array(
                        'film' => $aat_film_display((string) ($top_row['film'] ?? '')),
                        'url'  => !empty($top_ids) ? $aat->get_entity_url((string) $top_ids[0]) : '',
                        'wins' => intval($top_row['wins'] ?? 0),
                    );
                }
            }

            // One batched query maps every ceremony to its Best Picture winner;
            // posters resolve local-only (no remote lookups on render).
            $aat_index_bp_map = array();
            $aat_index_bp_rows = $wpdb->get_results(
                "SELECT ceremony, film, film_id FROM $table_name WHERE winner = 1 AND canonical_category = 'BEST PICTURE'",
                ARRAY_A
            );
            if (is_array($aat_index_bp_rows)) {
                foreach ($aat_index_bp_rows as $bp_row) {
                    $bp_cer = intval($bp_row['ceremony'] ?? 0);
                    if ($bp_cer <= 0 || isset($aat_index_bp_map[$bp_cer])) continue;
                    $bp_ids = $aat_extract_title_ids(array('film_id' => (string) ($bp_row['film_id'] ?? '')));
                    $aat_index_bp_map[$bp_cer] = array(
                        'tt'   => !empty($bp_ids) ? (string) $bp_ids[0] : '',
                        'film' => trim((string) ($bp_row['film'] ?? '')),
                    );
                }
            }
    ?>
        <div class="aat-hub-header">
            <h1 class="aat-hub-title"><?php echo esc_html__('Ceremonies', 'academy-awards-table'); ?></h1>
            <p class="aat-hub-subtitle"><?php
                if ($total_ceremonies > 0 && $min_ceremony > 0 && $max_ceremony > 0) {
                    echo esc_html(sprintf(
                        /* translators: 1: ceremony count, 2: first ceremony ordinal, 3: first year, 4: latest ceremony ordinal, 5: latest year */
                        __('%1$s ceremonies, from the %2$s in %3$s to the %4$s in %5$s. Every nomination and every winner, newest first.', 'academy-awards-table'),
                        number_format_i18n($total_ceremonies),
                        $aat->ordinal($min_ceremony),
                        (string) $aat->get_ceremony_year($min_ceremony),
                        $aat->ordinal($max_ceremony),
                        (string) $aat->get_ceremony_year($max_ceremony)
                    ));
                } else {
                    echo esc_html__('Every Academy Awards ceremony in the Lunara Oscar Ledger, newest first.', 'academy-awards-table');
                }
            ?></p>

            <?php if ($wp_hub_page instanceof WP_Post && $wp_hub_content !== '') : ?>
                <div class="aat-hub-wp-content">
                    <?php echo apply_filters('the_content', $wp_hub_content); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the_content output. ?>
                </div>
            <?php endif; ?>

            <div class="aat-hub-actions">
                <a class="aat-btn aat-btn-secondary" href="<?php echo esc_url($aat->get_categories_index_url()); ?>"><?php echo esc_html__('Browse Categories', 'academy-awards-table'); ?></a>
                <a class="aat-btn aat-btn-primary" href="<?php echo esc_url($db_url); ?>"><?php echo esc_html__('Open Full Ledger', 'academy-awards-table'); ?></a>
            </div>
        </div>

        <?php // Each card carries the ceremony's own counts and its two headline facts; the global totals live on the About page. ?>
        <div class="aat-hub-grid aat-hub-page-index-grid">
            <?php if (!empty($rows)) : $aat_index_card_position = 0; foreach ($rows as $r) :
                $c = intval($r['ceremony'] ?? 0);
                if ($c <= 0) continue;
                $aat_index_card_position++;
                $year_label = (string) ($r['year_label'] ?? '');
                $url = $aat->get_ceremony_url($c);
                $bp = isset($aat_index_bp_map[$c]) ? $aat_index_bp_map[$c] : array();
                $top = isset($aat_index_top_map[$c]) ? $aat_index_top_map[$c] : array();
                $index_poster = !empty($bp['tt']) ? $aat_index_poster_html($bp['tt'], $aat_index_card_position <= 6) : '';
                $index_facts = array();
                if ($year_label !== '') {
                    $index_facts[] = $year_label;
                }
                if (intval($r['nominations'] ?? 0) > 0) {
                    $index_facts[] = sprintf(_n('%s nomination', '%s nominations', intval($r['nominations']), 'academy-awards-table'), number_format_i18n(intval($r['nominations'])));
                }
                if (intval($r['categories'] ?? 0) > 0) {
                    $index_facts[] = sprintf(_n('%s category', '%s categories', intval($r['categories']), 'academy-awards-table'), number_format_i18n(intval($r['categories'])));
                }
            ?>
                <article class="aat-hub-card<?php echo $index_poster !== '' ? ' has-index-poster' : ''; ?>">
                    <?php if ($index_poster !== '') : ?>
                        <div class="aat-index-poster" aria-hidden="true"><?php echo $index_poster; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
                    <?php endif; ?>
                    <h2 class="aat-hub-card-title">
                        <?php echo $aat_render_hub_text_link($aat->ordinal($c) . ' ' . __('Academy Awards', 'academy-awards-table'), $url, 'aat-hub-card-link aat-hub-inline-link-title'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </h2>
                    <?php if (!empty($index_facts)) : ?>
                        <p class="aat-hub-card-meta"><?php echo $aat_join_meta($index_facts); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
                    <?php endif; ?>
                    <?php if (!empty($bp['film'])) : ?>
                        <p class="aat-index-winnerline"><span class="aat-index-winnerline-label"><?php echo esc_html__('Best Picture', 'academy-awards-table'); ?></span> <span class="aat-index-winnerline-title"><?php echo esc_html($aat_film_display($bp['film'])); ?></span></p>
                    <?php endif; ?>
                    <?php if (!empty($top['film'])) : ?>
                        <p class="aat-index-winnerline"><span class="aat-index-winnerline-label"><?php echo esc_html__('Most wins', 'academy-awards-table'); ?></span> <span class="aat-index-winnerline-title"><?php echo $aat_render_hub_text_link($top['film'], (string) $top['url'], 'aat-hub-inline-link'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <?php echo esc_html(sprintf(/* translators: %s: win count */ __('(%s)', 'academy-awards-table'), number_format_i18n(intval($top['wins'])))); ?></span></p>
                    <?php endif; ?>
                    <p class="aat-hub-card-action"><?php echo $aat_render_hub_text_link(__('Open ceremony', 'academy-awards-table'), $url, 'aat-hub-inline-link'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
                </article>
            <?php endforeach; endif; ?>
        </div>

    <?php
        // CATEGORIES INDEX
        elseif ($hub === 'categories') :
            $cats = $wpdb->get_results(
                "SELECT canonical_category, MIN(class) AS class_label, MIN(year) AS first_year, MAX(year) AS last_year, COUNT(DISTINCT ceremony) AS ceremonies FROM $table_name WHERE canonical_category != '' GROUP BY canonical_category ORDER BY MIN(class) ASC, canonical_category ASC",
                ARRAY_A
            );
            $grouped = array();
            $aat_index_cat_facts = array();
            if (is_array($cats)) {
                foreach ($cats as $r) {
                    $cat = (string) ($r['canonical_category'] ?? '');
                    if ($cat === '') continue;
                    $cls = (string) ($r['class_label'] ?? '');
                    if ($cls === '') $cls = 'Other';
                    if (!isset($grouped[$cls])) $grouped[$cls] = array();
                    $grouped[$cls][] = $cat;
                    $aat_index_cat_facts[$cat] = array(
                        'first_year' => trim((string) ($r['first_year'] ?? '')),
                        'last_year'  => trim((string) ($r['last_year'] ?? '')),
                        'ceremonies' => intval($r['ceremonies'] ?? 0),
                    );
                }
            }
            ksort($grouped);
            // Groups read in the order a reader looks for them, under names that say what they hold.
            $aat_index_class_names = array(
                'Title'      => __('Film awards', 'academy-awards-table'),
                'Acting'     => __('Acting', 'academy-awards-table'),
                'Directing'  => __('Directing', 'academy-awards-table'),
                'Writing'    => __('Writing', 'academy-awards-table'),
                'Music'      => __('Music', 'academy-awards-table'),
                'Production' => __('Craft and design', 'academy-awards-table'),
                'SciTech'    => __('Scientific and Technical', 'academy-awards-table'),
                'Special'    => __('Special awards', 'academy-awards-table'),
            );
            $aat_index_grouped_ordered = array();
            foreach (array_keys($aat_index_class_names) as $aat_index_class_key) {
                if (isset($grouped[$aat_index_class_key])) {
                    $aat_index_grouped_ordered[$aat_index_class_key] = $grouped[$aat_index_class_key];
                }
            }
            foreach ($grouped as $aat_index_class_key => $aat_index_class_list) {
                if (!isset($aat_index_grouped_ordered[$aat_index_class_key])) {
                    $aat_index_grouped_ordered[$aat_index_class_key] = $aat_index_class_list;
                }
            }
            $grouped = $aat_index_grouped_ordered;
            $aat_index_latest_year = $max_ceremony > 0 ? intval($aat->get_ceremony_year($max_ceremony)) : 0;
            $aat_index_person_classes = array('Acting', 'Directing', 'Writing');

            // One batched query maps every canonical category to its most
            // recent winner (film + poster tt + year) — no per-card queries.
            $aat_index_cat_map = array();
            $aat_index_cat_rows = $wpdb->get_results(
                "SELECT t.canonical_category, t.year, t.film, t.film_id, t.name
                 FROM $table_name t
                 INNER JOIN (
                     SELECT canonical_category, MAX(ceremony) AS latest_ceremony
                     FROM $table_name
                     WHERE winner = 1 AND canonical_category != ''
                     GROUP BY canonical_category
                 ) latest
                    ON latest.canonical_category = t.canonical_category
                   AND latest.latest_ceremony = t.ceremony
                 WHERE t.winner = 1
                 ORDER BY t.canonical_category ASC, t.film ASC",
                ARRAY_A
            );
            if (is_array($aat_index_cat_rows)) {
                foreach ($aat_index_cat_rows as $cw_row) {
                    $cw_cat = trim((string) ($cw_row['canonical_category'] ?? ''));
                    if ($cw_cat === '' || isset($aat_index_cat_map[$cw_cat])) continue;
                    $cw_ids = $aat_extract_title_ids(array('film_id' => (string) ($cw_row['film_id'] ?? '')));
                    $aat_index_cat_map[$cw_cat] = array(
                        'tt'   => !empty($cw_ids) ? (string) $cw_ids[0] : '',
                        'film' => trim((string) ($cw_row['film'] ?? '')),
                        'name' => trim((string) ($cw_row['name'] ?? '')),
                        'year' => trim((string) ($cw_row['year'] ?? '')),
                    );
                }
            }
    ?>
        <div class="aat-hub-header">
            <h1 class="aat-hub-title"><?php echo esc_html__('Categories', 'academy-awards-table'); ?></h1>
            <p class="aat-hub-subtitle"><?php echo esc_html(sprintf(
                /* translators: 1: category count, 2: group count */
                _n('%1$s category in %2$s groups, with every winner and the full history of each race.', '%1$s categories in %2$s groups, with every winner and the full history of each race.', $total_categories, 'academy-awards-table'),
                number_format_i18n($total_categories),
                number_format_i18n(count($grouped))
            )); ?></p>

            <?php if ($wp_hub_page instanceof WP_Post && $wp_hub_content !== '') : ?>
                <div class="aat-hub-wp-content">
                    <?php echo apply_filters('the_content', $wp_hub_content); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the_content output. ?>
                </div>
            <?php endif; ?>

            <div class="aat-hub-actions">
                <a class="aat-btn aat-btn-secondary" href="<?php echo esc_url($aat->get_ceremonies_index_url()); ?>"><?php echo esc_html__('Browse Ceremonies', 'academy-awards-table'); ?></a>
                <a class="aat-btn aat-btn-primary" href="<?php echo esc_url($db_url); ?>"><?php echo esc_html__('Open Full Ledger', 'academy-awards-table'); ?></a>
            </div>
        </div>

        <?php // Each card carries the category's own span and latest winner; the global totals live on the About page. ?>
        <?php $aat_index_card_position = 0; ?>
        <?php foreach ($grouped as $cls => $list) : ?>
            <div class="aat-hub-section">
                <h2><?php echo esc_html(isset($aat_index_class_names[$cls]) ? $aat_index_class_names[$cls] : $cls); ?> <span class="aat-hub-section-count"><?php echo esc_html(sprintf(_n('%s category', '%s categories', count($list), 'academy-awards-table'), number_format_i18n(count($list)))); ?></span></h2>
                <div class="aat-hub-grid aat-hub-page-index-grid">
                    <?php foreach ($list as $cat) :
                        $aat_index_card_position++;
                        $url = $aat->get_category_url($cat);
                        $label = $aat->format_category_display($cat);
                        $cw = isset($aat_index_cat_map[$cat]) ? $aat_index_cat_map[$cat] : array();
                        $facts = isset($aat_index_cat_facts[$cat]) ? $aat_index_cat_facts[$cat] : array();
                        $index_poster = !empty($cw['tt']) ? $aat_index_poster_html($cw['tt'], $aat_index_card_position <= 6) : '';
                        // The Academy's own name is shown when the display label differs from it.
                        $academy_name = '';
                        if ($label !== $cat && strtoupper($cat) === $cat) {
                            $academy_name = str_replace(array(' And ', ' Of ', ' In ', ' For ', ' A '), array(' and ', ' of ', ' in ', ' for ', ' a '), ucwords(strtolower($cat)));
                        } elseif ($label !== $cat) {
                            $academy_name = $cat;
                        }
                        $index_facts = array();
                        $first_year = (string) ($facts['first_year'] ?? '');
                        $last_year = (string) ($facts['last_year'] ?? '');
                        if ($first_year !== '' && $last_year !== '') {
                            if ($aat_index_latest_year > 0 && intval($last_year) > 0 && intval($last_year) < $aat_index_latest_year) {
                                $index_facts[] = sprintf(/* translators: 1: first year, 2: last year */ __('Awarded %1$s to %2$s', 'academy-awards-table'), $first_year, $last_year);
                                $index_facts[] = __('No longer awarded', 'academy-awards-table');
                            } else {
                                $index_facts[] = sprintf(/* translators: 1: first year, 2: last year */ __('Awarded since %1$s', 'academy-awards-table'), $first_year, $last_year);
                            }
                        }
                        if (intval($facts['ceremonies'] ?? 0) > 0) {
                            $index_facts[] = sprintf(_n('%s ceremony', '%s ceremonies', intval($facts['ceremonies']), 'academy-awards-table'), number_format_i18n(intval($facts['ceremonies'])));
                        }
                        $latest_line = '';
                        $latest_name = trim((string) ($cw['name'] ?? ''));
                        $latest_film = trim((string) ($cw['film'] ?? ''));
                        if (in_array($cls, $aat_index_person_classes, true) && $latest_name !== '') {
                            $latest_line = $latest_name;
                            if ($latest_film !== '' && $latest_film !== $latest_name) {
                                $latest_line .= ', ' . $aat_film_display($latest_film);
                            }
                        } elseif ($latest_film !== '') {
                            $latest_line = $aat_film_display($latest_film);
                        } elseif ($latest_name !== '') {
                            $latest_line = $latest_name;
                        }
                        if ($latest_line !== '' && !empty($cw['year'])) {
                            $latest_line .= ' (' . $cw['year'] . ')';
                        }
                    ?>
                        <article class="aat-hub-card<?php echo $index_poster !== '' ? ' has-index-poster' : ''; ?>">
                            <?php if ($index_poster !== '') : ?>
                                <div class="aat-index-poster" aria-hidden="true"><?php echo $index_poster; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
                            <?php endif; ?>
                            <h2 class="aat-hub-card-title"><?php echo $aat_render_hub_text_link($label, $url, 'aat-hub-card-link aat-hub-inline-link-title'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h2>
                            <?php if ($academy_name !== '') : ?>
                                <p class="aat-hub-card-meta"><?php echo esc_html(sprintf(/* translators: %s: the Academy's own category name */ __('Academy name: %s', 'academy-awards-table'), $academy_name)); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($index_facts)) : ?>
                                <p class="aat-hub-card-meta"><?php echo $aat_join_meta($index_facts); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
                            <?php endif; ?>
                            <?php if ($latest_line !== '') : ?>
                                <p class="aat-index-winnerline"><span class="aat-index-winnerline-label"><?php echo esc_html__('Latest winner', 'academy-awards-table'); ?></span> <span class="aat-index-winnerline-title"><?php echo esc_html($latest_line); ?></span></p>
                            <?php endif; ?>
                            <p class="aat-hub-card-action"><?php echo $aat_render_hub_text_link(__('Open category', 'academy-awards-table'), $url, 'aat-hub-inline-link'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>

    <?php
        // ABOUT
        elseif ($hub === 'about') :
    ?>
        <div class="aat-hub-header">
            <h1 class="aat-hub-title"><?php echo esc_html__('About the Oscar Ledger', 'academy-awards-table'); ?></h1>
            <p class="aat-hub-subtitle"><?php echo esc_html__('A bespoke, normalized Academy Awards dataset compiled for Lunara Film by Dalton Johnson.', 'academy-awards-table'); ?></p>

            <?php if ($wp_hub_page instanceof WP_Post && $wp_hub_content !== '') : ?>
                <div class="aat-hub-wp-content">
                    <?php echo apply_filters('the_content', $wp_hub_content); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the_content output. ?>
                </div>
            <?php endif; ?>

            <div class="aat-hub-actions">
                <a class="aat-btn aat-btn-primary" href="<?php echo esc_url($db_url); ?>"><?php echo esc_html__('Open Full Ledger', 'academy-awards-table'); ?></a>
            </div>
        </div>

        <div class="aat-stats-bar aat-entity-stats">
            <div class="aat-stat"><span class="aat-stat-number"><?php echo esc_html(number_format_i18n($total_records)); ?></span><span class="aat-stat-label"><?php echo esc_html__('Nominations', 'academy-awards-table'); ?></span></div>
            <div class="aat-stat"><span class="aat-stat-number"><?php echo esc_html(number_format_i18n($total_winners)); ?></span><span class="aat-stat-label"><?php echo esc_html__('Wins', 'academy-awards-table'); ?></span></div>
            <div class="aat-stat"><span class="aat-stat-number"><?php echo esc_html(number_format_i18n($total_categories)); ?></span><span class="aat-stat-label"><?php echo esc_html__('Categories', 'academy-awards-table'); ?></span></div>
            <div class="aat-stat"><span class="aat-stat-number"><?php echo esc_html(number_format_i18n($total_ceremonies)); ?></span><span class="aat-stat-label"><?php echo esc_html__('Ceremonies', 'academy-awards-table'); ?></span></div>
        </div>

        <div class="aat-hub-section">
            <h2><?php echo esc_html__('Scope', 'academy-awards-table'); ?></h2>
            <p class="aat-hub-copy">
                <?php echo esc_html__('This ledger spans the full history of the Academy Awards as represented in our dataset.', 'academy-awards-table'); ?>
                <?php if ($span) : ?>
                    <?php echo esc_html(sprintf(__('Coverage: %s.', 'academy-awards-table'), $span)); ?>
                <?php endif; ?>
            </p>
        </div>

        <div class="aat-hub-section">
            <h2><?php echo esc_html__('Method', 'academy-awards-table'); ?></h2>
            <p class="aat-hub-copy">
                <?php echo esc_html__('We treat Oscar history as structured data: categories are normalized, nominee and title credits are linked, and each record is curated to support search, filtering, and internal discovery.', 'academy-awards-table'); ?>
            </p>
            <p class="aat-hub-copy">
                <?php echo esc_html__('Primary factual sourcing: Academy of Motion Picture Arts and Sciences. This dataset is independently structured, compiled, and maintained by Lunara Film.', 'academy-awards-table'); ?>
            </p>
        </div>

        <div class="aat-hub-section">
            <h2><?php echo esc_html__('Explore', 'academy-awards-table'); ?></h2>
            <div class="aat-hub-chips">
                <a class="aat-hub-chip" href="<?php echo esc_url($aat->get_ceremonies_index_url()); ?>"><?php echo esc_html__('Ceremonies', 'academy-awards-table'); ?></a>
                <a class="aat-hub-chip" href="<?php echo esc_url($aat->get_categories_index_url()); ?>"><?php echo esc_html__('Categories', 'academy-awards-table'); ?></a>
            </div>
        </div>

    <?php
        // CEREMONY PAGE
        elseif ($hub === 'ceremony') :
            $ceremony = intval($hub_id);
            $aat_max_ceremony = intval($aat->get_max_ceremony());
            // 2.8.13: a ceremony beyond the last one on record is a 404, not an empty
            // (and endlessly re-rendered) 200 page.
            if ($ceremony <= 0 || ($aat_max_ceremony > 0 && $ceremony > $aat_max_ceremony)) {
                $mark_404();
            }
            $ceremony_summary = $aat->get_ceremony_summary($ceremony);
            $year_label = (string) ($ceremony_summary['year_label'] ?? '');
            $noms = intval($ceremony_summary['nominations'] ?? 0);
            $wins = intval($ceremony_summary['wins'] ?? 0);
            $cats_count = intval($ceremony_summary['categories_count'] ?? 0);
            $cats = !empty($ceremony_summary['categories']) && is_array($ceremony_summary['categories']) ? $ceremony_summary['categories'] : array();
            $ceremony_rollup = method_exists($aat, 'get_ceremony_rollup') ? $aat->get_ceremony_rollup($ceremony) : array();
            $is_latest_ceremony = ($ceremony === intval($aat->get_max_ceremony()));
            $newer_ceremony = intval($ceremony_summary['newer_ceremony'] ?? 0);
            $older_ceremony = intval($ceremony_summary['older_ceremony'] ?? 0);
            $ceremony_ballot_ledger = method_exists($aat, 'get_ceremony_ballot_ledger') ? $aat->get_ceremony_ballot_ledger($ceremony) : array();
            $ceremony_ballot_groups = !empty($ceremony_ballot_ledger['categories']) && is_array($ceremony_ballot_ledger['categories']) ? $ceremony_ballot_ledger['categories'] : array();
            $ceremony_review_map = !empty($ceremony_ballot_ledger['review_map']) && is_array($ceremony_ballot_ledger['review_map']) ? $ceremony_ballot_ledger['review_map'] : array();
            $ceremony_ballot_full_requested = isset($_GET['ledger']) && sanitize_key(wp_unslash($_GET['ledger'])) === 'full';
            // 2.8.13: built from the canonical URL so cached pages never echo a
            // visitor's own query arguments (utm_*, fbclid, cache busters).
            $ceremony_ballot_full_url = add_query_arg('ledger', 'full', $aat->get_ceremony_url($ceremony));
            $ceremony_ballot_fast_url = $aat->get_ceremony_url($ceremony);
            $ceremony_spotlight = array();
            $best_picture = !empty($ceremony_rollup['best_picture']) ? $ceremony_rollup['best_picture'] : array();
            $best_picture_nominees = !empty($ceremony_rollup['best_picture_nominees']) ? $ceremony_rollup['best_picture_nominees'] : array();
            $most_wins = !empty($ceremony_rollup['most_wins']) ? $ceremony_rollup['most_wins'] : array();
            $most_nominated = !empty($ceremony_rollup['most_nominated']) ? $ceremony_rollup['most_nominated'] : array();
            $ceremony_title_label = sprintf(
                /* translators: %s: ceremony ordinal */
                __('%s Academy Awards', 'academy-awards-table'),
                $aat->ordinal($ceremony)
            );
            $ceremony_dossier_label = sprintf(
                /* translators: %s: ceremony ordinal */
                __('%s Dossier', 'academy-awards-table'),
                $aat->ordinal($ceremony)
            );
            $best_picture_label = !empty($best_picture['film']) ? (string) $best_picture['film'] : __('Pending', 'academy-awards-table');
            $most_wins_label = !empty($most_wins['film']) ? $aat_pipe_display((string) $most_wins['film']) : __('Pending', 'academy-awards-table');
            // 2.8.18: "28 of 28", never "44/28". Winners outnumber categories (ties,
            // shared awards), so the record counts categories decided, and the
            // number of winners is said separately in words.
            $aat_decided = intval($ceremony_summary['winner_categories'] ?? 0);
            if ($aat_decided <= 0) {
                $aat_decided = intval($ceremony_rollup['winner_categories'] ?? 0);
            }
            if ($cats_count <= 0) {
                $cats_count = intval($ceremony_rollup['categories_total'] ?? 0);
            }
            $aat_decided = min($aat_decided, $cats_count);
            $winner_record_label = ($cats_count > 0 && $aat_decided >= $cats_count)
                /* translators: %s: number of categories */
                ? sprintf(__('All %s', 'academy-awards-table'), number_format_i18n($cats_count))
                /* translators: 1: categories decided, 2: total categories */
                : sprintf(__('%1$s of %2$s', 'academy-awards-table'), number_format_i18n($aat_decided), number_format_i18n($cats_count));
            $winner_record_copy = $wins > 0
                /* translators: 1: winners named, 2: categories */
                ? sprintf(_n('%1$s winner named across %2$s category.', '%1$s winners named across %2$s categories.', $cats_count, 'academy-awards-table'), number_format_i18n($wins), number_format_i18n($cats_count))
                : __('Awaiting winner data.', 'academy-awards-table');
            $ceremony_major_race_order = array(
                'BEST PICTURE',
                'DIRECTING',
                'ACTOR IN A LEADING ROLE',
                'ACTRESS IN A LEADING ROLE',
            );
            $ceremony_major_race_rank = array_flip($ceremony_major_race_order);
            $ceremony_major_race_groups = array();
            foreach ($ceremony_ballot_groups as $major_race_candidate) {
                $major_race_category = strtoupper(trim((string) ($major_race_candidate['category'] ?? '')));
                if ($major_race_category === '' || !isset($ceremony_major_race_rank[$major_race_category])) {
                    continue;
                }

                $major_race_rows = !empty($major_race_candidate['rows']) && is_array($major_race_candidate['rows']) ? $major_race_candidate['rows'] : array();
                if (empty($major_race_rows)) {
                    continue;
                }

                $major_race_candidate['major_rank'] = intval($ceremony_major_race_rank[$major_race_category]);
                $ceremony_major_race_groups[] = $major_race_candidate;
            }
            if (!empty($ceremony_major_race_groups)) {
                usort($ceremony_major_race_groups, function($left, $right) {
                    return intval($left['major_rank'] ?? 999) <=> intval($right['major_rank'] ?? 999);
                });
            }

            $ceremony_major_briefing_cards = array();
            $ceremony_major_nominee_total = 0;
            $ceremony_major_completed_count = 0;
            $ceremony_major_reviewed_titles = array();
            $ceremony_major_category_links = array();

            foreach ($ceremony_major_race_groups as $briefing_group) {
                $briefing_category = trim((string) ($briefing_group['category'] ?? ''));
                $briefing_category_key = strtoupper($briefing_category);
                $briefing_label = trim((string) ($briefing_group['label'] ?? $aat->format_category_display($briefing_category, $ceremony)));
                $briefing_url = trim((string) ($briefing_group['url'] ?? ($briefing_category !== '' ? $aat->get_category_url($briefing_category) : '')));
                $briefing_rows = !empty($briefing_group['rows']) && is_array($briefing_group['rows']) ? $briefing_group['rows'] : array();
                $briefing_winner_rows = array_values(array_filter($briefing_rows, function($candidate_row) {
                    return !empty($candidate_row['winner']);
                }));
                $briefing_winner_row = !empty($briefing_winner_rows[0]) ? $aat_enrich_winner_entry_links($briefing_winner_rows[0]) : array();
                $briefing_winner_label = trim((string) ($briefing_winner_row['primary_label'] ?? (!empty($briefing_winner_row) ? $aat_winner_primary($briefing_winner_row) : '')));
                $briefing_winner_url = !empty($briefing_winner_row['primary_url']) ? (string) $briefing_winner_row['primary_url'] : '';
                $briefing_secondary_label = trim((string) ($briefing_winner_row['secondary_label'] ?? (!empty($briefing_winner_row) ? $aat_winner_secondary($briefing_winner_row) : '')));
                $briefing_review_count = 0;

                foreach ($briefing_rows as $briefing_row) {
                    foreach ($aat_extract_title_ids($briefing_row) as $briefing_title_id) {
                        if (empty($ceremony_review_map[$briefing_title_id]) || isset($ceremony_major_reviewed_titles[$briefing_title_id])) {
                            continue;
                        }

                        $briefing_review_count++;
                        $ceremony_major_reviewed_titles[$briefing_title_id] = true;
                    }
                }

                $ceremony_major_nominee_total += count($briefing_rows);
                if (!empty($briefing_winner_rows)) {
                    $ceremony_major_completed_count++;
                }

                if ($briefing_url !== '') {
                    $ceremony_major_category_links[$briefing_category_key] = array(
                        'label' => $briefing_label,
                        'url'   => $briefing_url,
                    );
                }

                $briefing_winner_title_ids = !empty($briefing_winner_row) ? $aat_extract_title_ids($briefing_winner_row) : array();

                $ceremony_major_briefing_cards[] = array(
                    'category'        => $briefing_category,
                    'category_key'    => $briefing_category_key,
                    'label'           => $briefing_label,
                    'url'             => $briefing_url,
                    'field_count'     => count($briefing_rows),
                    'winner_label'    => $briefing_winner_label,
                    'winner_url'      => $briefing_winner_url,
                    'winner_title_id' => !empty($briefing_winner_title_ids) ? (string) $briefing_winner_title_ids[0] : '',
                    'secondary_label' => $briefing_secondary_label,
                    'review_count'    => $briefing_review_count,
                    'is_complete'     => !empty($briefing_winner_rows),
                );
            }

            $ceremony_major_review_total = count($ceremony_major_reviewed_titles);
            $best_picture_display_label = !empty($best_picture['film']) ? $aat_pipe_display((string) $best_picture['film']) : '';
            $most_wins_display_label = !empty($most_wins['film']) ? $aat_pipe_display((string) $most_wins['film']) : '';
            $ceremony_thesis_headline = __('The annual file starts with the major races.', 'academy-awards-table');
            if ($best_picture_display_label !== '' && $most_wins_display_label !== '' && strcasecmp($best_picture_display_label, $most_wins_display_label) !== 0) {
                $ceremony_thesis_headline = sprintf(
                    /* translators: 1: Best Picture winner, 2: most-winning title */
                    __('%1$s took Picture; %2$s controlled the board.', 'academy-awards-table'),
                    $best_picture_display_label,
                    $most_wins_display_label
                );
            } elseif ($best_picture_display_label !== '') {
                $ceremony_thesis_headline = sprintf(
                    /* translators: 1: ceremony year, 2: Best Picture winner */
                    __('The %s ceremony centers on %s.', 'academy-awards-table'),
                    (string) $year_label,
                    $best_picture_display_label
                );
            }

            $ceremony_thesis_copy = sprintf(
                /* translators: 1: completed races, 2: total major races, 3: nominee count, 4: total winner count, 5: category count */
                __('This dossier opens with the four races readers check first: Picture, Director, Actor, and Actress. %1$s of %2$s major races have winners recorded, with %3$s major-field records feeding into the full ceremony ledger below: %4$s winners across %5$s categories.', 'academy-awards-table'),
                number_format_i18n($ceremony_major_completed_count),
                number_format_i18n(count($ceremony_major_race_order)),
                number_format_i18n($ceremony_major_nominee_total),
                number_format_i18n($wins),
                number_format_i18n($cats_count)
            );

            $ceremony_critical_links = array(
                array(
                    'label' => __('Full Ballot', 'academy-awards-table'),
                    'url'   => $ceremony_ballot_full_url,
                    'kind'  => 'ceremony',
                ),
                array(
                    'label' => __('Winner Circle', 'academy-awards-table'),
                    'url'   => '#ceremony-winner-circle',
                    'kind'  => 'winner',
                ),
                array(
                    'label' => __('All Ceremonies', 'academy-awards-table'),
                    'url'   => $aat->get_ceremonies_index_url(),
                    'kind'  => 'ceremony',
                ),
            );

            foreach ($ceremony_major_race_order as $critical_category_key) {
                if (empty($ceremony_major_category_links[$critical_category_key])) {
                    continue;
                }

                $ceremony_critical_links[] = array(
                    'label' => $ceremony_major_category_links[$critical_category_key]['label'],
                    'url'   => $ceremony_major_category_links[$critical_category_key]['url'],
                    'kind'  => 'category',
                );
            }
    ?>
        <div class="aat-ceremony-dossier">
<?php // Route section composer (2.7.82): every top-level ceremony section is captured into $aat_sections and re-emitted through aat_hub_route_sections. Default output is byte-identical; the filter is inert until a consumer hooks it. ?>
<?php $aat_sections = array(); $aat_route_context = $aat->get_route_context(); ob_start(); ?>
            <?php
                // Best Picture winner poster for the dossier lead. Local-first
                // (allow_remote = false) so it never blocks the hero on a cold
                // cache; falls back to the premium plate when no poster is
                // imported yet, so the hero is never an empty slot.
                $dossier_poster_html = '';
                if (!empty($best_picture) && is_callable($aat_extract_title_ids) && method_exists($aat, 'get_title_visual_package')) {
                    $dossier_bp_ids = $aat_extract_title_ids($best_picture);
                    if (!empty($dossier_bp_ids)) {
                        $dossier_visual = (array) $aat->get_title_visual_package(
                            $dossier_bp_ids[0],
                            'medium_large',
                            false,
                            array(
                                'loading'       => 'eager',
                                'fetchpriority' => 'high',
                                // 2.8.21: the frame renders ~147px wide on phones, 180px on desktop. This is the LCP image on ceremony pages.
                                'sizes'         => '(max-width: 820px) 150px, 180px',
                            )
                        );
                        if (!empty($dossier_visual['poster_html'])) {
                            $dossier_poster_html = $dossier_visual['poster_html'];
                        } elseif (!empty($dossier_visual['poster_url'])) {
                            $dossier_poster_html = '<img class="aat-entity-poster" src="' . esc_url($dossier_visual['poster_url']) . '" alt="' . esc_attr($best_picture_label . ' poster') . '" loading="eager" fetchpriority="high" decoding="async" />';
                        } elseif (!empty($dossier_visual['fallback_html'])) {
                            $dossier_poster_html = $dossier_visual['fallback_html'];
                        }
                    }
                }
            ?>
            <section class="aat-ceremony-dossier-hero<?php echo $dossier_poster_html !== '' ? ' has-dossier-poster' : ''; ?>">
                <div class="aat-ceremony-dossier-lead">
                    <?php if ($dossier_poster_html !== '') : ?>
                    <div class="aat-ceremony-dossier-poster">
                        <div class="aat-ceremony-dossier-poster-frame"><?php echo $dossier_poster_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
                        <span class="aat-ceremony-dossier-poster-tag"><?php echo esc_html__('Best Picture', 'academy-awards-table'); ?><?php if (!empty($best_picture['film'])) : ?><span class="aat-meta-sep" aria-hidden="true">&middot;</span><?php echo esc_html($best_picture_display_label); ?><?php endif; ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="aat-ceremony-dossier-copy">
                        <h1 class="aat-hub-title"><?php echo esc_html($ceremony_title_label); ?></h1>
                        <p class="aat-hub-subtitle">
                            <?php echo esc_html(sprintf(
                                /* translators: %s: ceremony year */
                                __('The %s file: winners first, ballot trails on demand, and the films that shaped the ceremony.', 'academy-awards-table'),
                                (string) $year_label
                            )); ?>
                        </p>
                    </div>
                </div>
                <?php // The band states each count once. Best Picture is named under its poster; the card appears only when no artwork carries that caption. ?>
                <div class="aat-ceremony-command-band" aria-label="<?php esc_attr_e('Ceremony ledger summary', 'academy-awards-table'); ?>">
                    <div class="aat-ceremony-command-card is-primary">
                        <span><?php echo esc_html__('Year', 'academy-awards-table'); ?></span>
                        <strong><?php echo esc_html($year_label !== '' ? $year_label : '-'); ?></strong>
                    </div>
                    <?php if ($dossier_poster_html === '') : ?>
                    <div class="aat-ceremony-command-card">
                        <span><?php echo esc_html__('Best Picture', 'academy-awards-table'); ?></span>
                        <strong><?php echo esc_html($best_picture_label); ?></strong>
                    </div>
                    <?php endif; ?>
                    <div class="aat-ceremony-command-card">
                        <span><?php echo esc_html__('Nominations', 'academy-awards-table'); ?></span>
                        <strong><?php echo esc_html(number_format_i18n($noms)); ?></strong>
                    </div>
                    <div class="aat-ceremony-command-card">
                        <span><?php echo esc_html__('Categories Decided', 'academy-awards-table'); ?></span>
                        <strong><?php echo esc_html($winner_record_label); ?></strong>
                    </div>
                    <div class="aat-ceremony-command-card">
                        <span><?php echo esc_html__('Top Winner', 'academy-awards-table'); ?></span>
                        <strong><?php echo esc_html($most_wins_label); ?></strong>
                    </div>
                </div>
                <div class="aat-hub-actions aat-ceremony-dossier-actions">
                    <a class="aat-btn aat-btn-primary" href="<?php echo esc_url($ceremony_ballot_full_url); ?>"><?php echo esc_html__('Full Ballot', 'academy-awards-table'); ?></a>
                    <a class="aat-btn aat-btn-secondary" href="<?php echo esc_url($db_url); ?>"><?php echo esc_html__('Open Full Ledger', 'academy-awards-table'); ?></a>
                </div>
            </section>
<?php $aat_sections['dossier-hero'] = ob_get_clean(); ob_start(); ?>

        <?php if ($newer_ceremony > 0 || $older_ceremony > 0) : ?>
            <nav class="aat-ceremony-neighbor-nav<?php echo $older_ceremony > 0 ? '' : ' has-no-older'; ?><?php echo $newer_ceremony > 0 ? '' : ' has-no-newer'; ?>" aria-label="<?php echo esc_attr__('Ceremony navigation', 'academy-awards-table'); ?>">
                <div class="aat-ceremony-neighbor-slot is-older">
                    <?php if ($older_ceremony > 0) : ?>
                        <a class="aat-ceremony-neighbor-card" href="<?php echo esc_url($aat->get_ceremony_url($older_ceremony)); ?>">
                            <span class="aat-ceremony-neighbor-kicker"><?php echo esc_html__('Earlier Ceremony', 'academy-awards-table'); ?></span>
                            <strong><?php echo esc_html($aat->ordinal($older_ceremony)); ?> <?php echo esc_html__('Academy Awards', 'academy-awards-table'); ?></strong>
                            <span><?php echo esc_html($aat->get_ceremony_year($older_ceremony)); ?></span>
                        </a>
                    <?php endif; ?>
                </div>
                <a class="aat-ceremony-neighbor-index" href="<?php echo esc_url($aat->get_ceremonies_index_url()); ?>">
                    <span><?php echo esc_html__('All Ceremonies', 'academy-awards-table'); ?></span>
                    <strong><?php echo esc_html(number_format_i18n($total_ceremonies)); ?></strong>
                </a>
                <div class="aat-ceremony-neighbor-slot is-newer">
                    <?php if ($newer_ceremony > 0) : ?>
                        <a class="aat-ceremony-neighbor-card" href="<?php echo esc_url($aat->get_ceremony_url($newer_ceremony)); ?>">
                            <span class="aat-ceremony-neighbor-kicker"><?php echo esc_html__('Later Ceremony', 'academy-awards-table'); ?></span>
                            <strong><?php echo esc_html($aat->ordinal($newer_ceremony)); ?> <?php echo esc_html__('Academy Awards', 'academy-awards-table'); ?></strong>
                            <span><?php echo esc_html($aat->get_ceremony_year($newer_ceremony)); ?></span>
                        </a>
                    <?php endif; ?>
                </div>
            </nav>
        <?php endif; ?>
<?php $aat_sections['neighbor-nav'] = ob_get_clean(); ob_start(); ?>

        <?php
            $ceremony_editorial_writeup = method_exists($aat, 'get_approved_ceremony_writeup') ? $aat->get_approved_ceremony_writeup($ceremony) : array();
            $ceremony_editorial_body = trim((string) ($ceremony_editorial_writeup['body'] ?? ''));
            $ceremony_editorial_headline = trim((string) ($ceremony_editorial_writeup['headline'] ?? ''));
            $ceremony_editorial_dek = trim((string) ($ceremony_editorial_writeup['dek'] ?? ''));
        ?>
        <?php if ($ceremony_editorial_body !== '') : ?>
            <section class="aat-hub-section aat-ceremony-editorial-writeup" aria-label="<?php echo esc_attr__('Ceremony editorial guide', 'academy-awards-table'); ?>">
                <div class="aat-ceremony-editorial-heading">
                    <h2><?php echo esc_html($ceremony_editorial_headline !== '' ? $ceremony_editorial_headline : (string) ($ceremony_editorial_writeup['ceremony_label'] ?? $ceremony_title_label)); ?></h2>
                    <?php if ($ceremony_editorial_dek !== '') : ?>
                        <p class="aat-ceremony-guide-meta"><?php echo esc_html($ceremony_editorial_dek); ?></p>
                    <?php endif; ?>
                </div>
                <div class="aat-ceremony-editorial-body aat-ceremony-guide-copy">
                    <?php echo wp_kses_post(wpautop(esc_html($ceremony_editorial_body))); ?>
                </div>
            </section>
        <?php endif; ?>
<?php $aat_sections['editorial-writeup'] = ob_get_clean(); ob_start(); ?>

        <?php // The major races are introduced once, in their own section below; the thesis slot stays in the composer order. ?>
<?php $aat_sections['thesis'] = ob_get_clean(); ob_start(); ?>

        <?php if (!empty($ceremony_rollup)) : ?>
            <section class="aat-hub-section aat-ceremony-marquee" aria-label="<?php echo esc_attr__('Ceremony at a glance', 'academy-awards-table'); ?>">
                <div class="aat-ceremony-marquee-copy">
                    <h2><?php echo esc_html__('Ceremony at a glance', 'academy-awards-table'); ?></h2>
                    <p class="aat-hub-copy">
                        <?php if (!empty($best_picture['film'])) : ?>
                            <?php
                                $best_picture_link = $aat_render_hub_text_link(
                                    (string) $best_picture['film'],
                                    !empty($best_picture['film_url']) ? (string) $best_picture['film_url'] : '',
                                    'aat-hub-inline-link'
                                );
                            ?>
                            <?php echo wp_kses_post(sprintf(__('Best Picture went to %s.', 'academy-awards-table'), $best_picture_link)); ?>
                        <?php endif; ?>
                        <?php if (!empty($most_wins['film']) && !empty($most_wins['wins'])) : ?>
                            <?php
                                $most_wins_link = $aat_render_hub_text_link(
                                    (string) $most_wins['film'],
                                    !empty($most_wins['film_url']) ? (string) $most_wins['film_url'] : '',
                                    'aat-hub-inline-link'
                                );
                            ?>
                            <?php echo ' '; ?>
                            <?php echo wp_kses_post(sprintf(
                                /* translators: 1: most-winning title link, 2: win count phrase */
                                __('The winning leader is %1$s with %2$s.', 'academy-awards-table'),
                                $most_wins_link,
                                sprintf(_n('%s win', '%s wins', intval($most_wins['wins']), 'academy-awards-table'), number_format_i18n(intval($most_wins['wins'])))
                            )); ?>
                        <?php endif; ?>
                        <?php if (!empty($most_nominated['film']) && !empty($most_nominated['nominations'])) : ?>
                            <?php
                                $most_nominated_link = $aat_render_hub_text_link(
                                    (string) $most_nominated['film'],
                                    !empty($most_nominated['film_url']) ? (string) $most_nominated['film_url'] : '',
                                    'aat-hub-inline-link'
                                );
                            ?>
                            <?php echo ' '; ?>
                            <?php echo wp_kses_post(sprintf(
                                /* translators: 1: most-nominated title link, 2: nomination count phrase */
                                __('The most nominated title is %1$s with %2$s.', 'academy-awards-table'),
                                $most_nominated_link,
                                sprintf(_n('%s nomination', '%s nominations', intval($most_nominated['nominations']), 'academy-awards-table'), number_format_i18n(intval($most_nominated['nominations'])))
                            )); ?>
                        <?php endif; ?>
                        <?php echo ' ' . esc_html($winner_record_copy); ?>
                    </p>
                </div>
                <div class="aat-ceremony-marquee-stack">
                    <?php if (!empty($ceremony_rollup['top_titles'])) : ?>
                        <h3 class="aat-ceremony-leaders-title"><?php echo esc_html__('Most awarded titles', 'academy-awards-table'); ?></h3>
                        <ol class="aat-hub-chip-stack aat-ceremony-leaders">
                            <?php foreach ($ceremony_rollup['top_titles'] as $title_entry) : ?>
                                <li class="aat-hub-chip aat-hub-chip-rich">
                                    <strong><?php echo $aat_render_hub_text_link((string) $title_entry['film'], !empty($title_entry['film_url']) ? (string) $title_entry['film_url'] : $db_url, 'aat-hub-inline-link aat-hub-inline-link-title'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></strong>
                                    <?php
                                        $chip_wins = intval($title_entry['wins']);
                                        $chip_noms = intval($title_entry['nominations']);
                                    ?>
                                    <span><?php echo esc_html(sprintf(_n('%s win', '%s wins', $chip_wins, 'academy-awards-table'), number_format_i18n($chip_wins))); ?><span class="aat-meta-sep" aria-hidden="true">&middot;</span><?php echo esc_html(sprintf(_n('%s nomination', '%s nominations', $chip_noms, 'academy-awards-table'), number_format_i18n($chip_noms))); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>
<?php $aat_sections['marquee'] = ob_get_clean(); ob_start(); ?>

        <?php if (!empty($best_picture_nominees)) : ?>
            <div class="aat-hub-section aat-best-picture-nominees-section">
                <h2><?php echo esc_html__('Best Picture Nominees', 'academy-awards-table'); ?></h2>
                <div class="aat-filmography-grid aat-hub-film-grid aat-best-picture-grid">
                    <?php foreach ($best_picture_nominees as $entry) :
                        $fid = strtolower(trim((string) ($entry['film_id'] ?? '')));
                        $film_label = trim((string) ($entry['film'] ?? ''));
                        if ($film_label === '' && $fid !== '') {
                            $film_label = $aat->lookup_title_label($fid);
                        }
                        if ($film_label === '') {
                            continue;
                        }
                        $film_url = !empty($entry['film_url']) ? (string) $entry['film_url'] : ($fid !== '' ? $aat->get_entity_url($fid) : '');
                        $visual = $fid !== '' && method_exists($aat, 'get_title_visual_package') ? $aat->get_title_visual_package($fid, 'medium_large') : array();
                        $entry = $aat_enrich_winner_entry_links($entry);
                    ?>
                        <article class="aat-filmography-card aat-hub-film-card aat-best-picture-card<?php echo !empty($entry['winner']) ? ' is-winner' : ''; ?>">
                            <a class="aat-filmography-link" href="<?php echo esc_url($film_url ? $film_url : $db_url); ?>" aria-label="<?php echo esc_attr($film_label); ?>">
                                <div class="aat-filmography-poster-wrap">
                                    <?php if (!empty($visual['poster_html'])) : ?>
                                        <?php echo $visual['poster_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    <?php elseif (!empty($visual['poster_url'])) : ?>
                                        <img class="aat-filmography-poster" src="<?php echo esc_url($visual['poster_url']); ?>" alt="<?php echo esc_attr($film_label); ?> poster" loading="lazy" decoding="async" />
                                    <?php elseif (!empty($visual['card_fallback_html'])) : ?>
                                        <?php echo $visual['card_fallback_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    <?php else : ?>
                                        <div class="aat-filmography-poster-placeholder"><span><?php echo esc_html($film_label); ?></span></div>
                                    <?php endif; ?>
                                    <?php if (!empty($entry['winner'])) : ?><span class="aat-winner-badge aat-card-badge"><?php echo esc_html__('Winner', 'academy-awards-table'); ?></span><?php endif; ?>
                                </div>
                            </a>
                            <h3 class="aat-filmography-title"><?php echo $aat_render_hub_text_link($film_label, $film_url ? $film_url : $db_url, 'aat-hub-inline-link aat-hub-inline-link-title'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h3>
                            <?php if (!empty($entry['person_label'])) : ?>
                                <p class="aat-filmography-meta">
                                    <?php echo $aat_render_hub_text_link((string) $entry['person_label'], !empty($entry['person_url']) ? (string) $entry['person_url'] : '', 'aat-hub-inline-link'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                </p>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
<?php $aat_sections['best-picture-nominees'] = ob_get_clean(); ob_start(); ?>
        <?php // The hero band carries nominations, decided categories and the leader once; the separate stats bar is retired. ?>
<?php $aat_sections['stats-bar'] = ob_get_clean(); ob_start(); ?>

        <?php if (!empty($ceremony_major_race_groups)) : ?>
            <section class="aat-hub-section aat-ceremony-major-races" aria-label="<?php echo esc_attr__('Major Oscar races', 'academy-awards-table'); ?>">
                <div class="aat-section-head">
                    <h2 class="aat-section-title"><?php echo esc_html__('The major races', 'academy-awards-table'); ?></h2>
                    <p class="aat-section-description"><?php echo esc_html($ceremony_thesis_headline); ?> <?php echo esc_html(sprintf(
                        /* translators: 1: completed major races, 2: total major races, 3: nominee count */
                        __('Picture, Director, Actor and Actress: %1$s of %2$s decided, %3$s nominees in all.', 'academy-awards-table'),
                        number_format_i18n($ceremony_major_completed_count),
                        number_format_i18n(count($ceremony_major_race_order)),
                        number_format_i18n($ceremony_major_nominee_total)
                    )); ?></p>
                </div>
                <div class="aat-major-race-grid">
                    <?php foreach ($ceremony_major_race_groups as $major_group) :
                        $major_category = trim((string) ($major_group['category'] ?? ''));
                        $major_category_key = strtoupper($major_category);
                        $major_label = trim((string) ($major_group['label'] ?? $aat->format_category_display($major_category, $ceremony)));
                        $major_url = trim((string) ($major_group['url'] ?? ($major_category !== '' ? $aat->get_category_url($major_category) : '')));
                        $major_rows = !empty($major_group['rows']) && is_array($major_group['rows']) ? $major_group['rows'] : array();
                        $winner_rows = array_values(array_filter($major_rows, function($candidate_row) {
                            return !empty($candidate_row['winner']);
                        }));
                        $nominee_rows = array_values(array_filter($major_rows, function($candidate_row) {
                            return empty($candidate_row['winner']);
                        }));
                        $feature_row = !empty($winner_rows[0]) ? $winner_rows[0] : (!empty($major_rows[0]) ? $major_rows[0] : array());
                        if (empty($feature_row)) {
                            continue;
                        }

                        $feature_row = $aat_enrich_winner_entry_links($feature_row);
                        $feature_primary = trim((string) ($feature_row['primary_label'] ?? $aat_winner_primary($feature_row)));
                        $feature_secondary = trim((string) ($feature_row['secondary_label'] ?? $aat_winner_secondary($feature_row)));
                        $feature_title_id = '';
                        foreach (explode('|', (string) ($feature_row['film_id'] ?? '')) as $feature_candidate_title_id) {
                            $feature_candidate_title_id = strtolower(trim((string) $feature_candidate_title_id));
                            if (preg_match('/^tt\d+$/', $feature_candidate_title_id)) {
                                $feature_title_id = $feature_candidate_title_id;
                                break;
                            }
                        }
                        $feature_review_url = ($feature_title_id !== '' && !empty($ceremony_review_map[$feature_title_id])) ? (string) $ceremony_review_map[$feature_title_id] : '';
                        $feature_visual = $feature_title_id !== '' ? $aat_get_visual_package($feature_title_id, 'medium_large') : array();
                        $feature_film_history_url = !empty($feature_row['film_history_url']) ? (string) $feature_row['film_history_url'] : '';
                        $feature_person_history_url = !empty($feature_row['person_history_url']) ? (string) $feature_row['person_history_url'] : '';
                        $feature_person_history_meta = $aat_person_history_action_meta($feature_person_history_url, $major_category_key);
                        $feature_person_history_label = $feature_person_history_meta['label'];
                        $feature_person_history_kind = $feature_person_history_meta['kind'];
                        $major_card_classes = array('aat-major-race-card');
                        if ($major_category_key === 'BEST PICTURE') {
                            $major_card_classes[] = 'is-best-picture';
                        }
                    ?>
                        <article class="<?php echo esc_attr(implode(' ', $major_card_classes)); ?>">
                            <div class="aat-major-race-top">
                                <?php echo $aat_render_hub_text_link($major_label, $major_url, 'aat-major-race-category aat-hub-inline-link'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <span class="<?php echo !empty($winner_rows) ? 'aat-winner-badge' : 'aat-nominee-badge'; ?>"><?php echo esc_html(!empty($winner_rows) ? __('Winner', 'academy-awards-table') : __('Pending', 'academy-awards-table')); ?></span>
                            </div>
                            <div class="aat-major-race-feature<?php echo empty($feature_visual['poster_url']) ? ' is-text-only' : ''; ?>">
                                <?php if (!empty($feature_visual['poster_url'])) : ?>
                                    <a class="aat-major-race-media" href="<?php echo esc_url(!empty($feature_row['primary_url']) ? (string) $feature_row['primary_url'] : (!empty($feature_row['film_url']) ? (string) $feature_row['film_url'] : $major_url)); ?>">
                                        <?php if (!empty($feature_visual['poster_html'])) : ?>
                                            <?php echo $feature_visual['poster_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                        <?php else : ?>
                                            <img class="aat-major-race-poster" src="<?php echo esc_url($feature_visual['poster_url']); ?>" alt="<?php echo esc_attr($feature_primary); ?>" loading="lazy" decoding="async" />
                                        <?php endif; ?>
                                    </a>
                                <?php endif; ?>
                                <div class="aat-major-race-copy">
                                    <?php if ($feature_primary !== '') : ?>
                                        <h3 class="aat-major-race-title">
                                            <?php echo $aat_render_hub_text_link($feature_primary, !empty($feature_row['primary_url']) ? (string) $feature_row['primary_url'] : '', 'aat-hub-inline-link aat-hub-inline-link-title'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                        </h3>
                                    <?php endif; ?>
                                    <?php if ($feature_secondary !== '') : ?>
                                        <p class="aat-major-race-meta">
                                            <?php echo $aat_render_hub_text_link($feature_secondary, !empty($feature_row['secondary_url']) ? (string) $feature_row['secondary_url'] : '', 'aat-hub-inline-link'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if (!empty($nominee_rows)) : ?>
                                <div class="aat-major-race-nominees">
                                    <ul class="aat-major-race-nominee-list" aria-label="<?php echo esc_attr(sprintf(/* translators: %s: category label */ __('Other %s nominees', 'academy-awards-table'), $major_label)); ?>">
                                        <?php foreach ($nominee_rows as $major_nominee_row) :
                                            $major_nominee_row = $aat_enrich_winner_entry_links($major_nominee_row);
                                            $nominee_primary = trim((string) ($major_nominee_row['primary_label'] ?? $aat_winner_primary($major_nominee_row)));
                                            $nominee_secondary = trim((string) ($major_nominee_row['secondary_label'] ?? $aat_winner_secondary($major_nominee_row)));
                                            if ($nominee_primary === '') {
                                                continue;
                                            }
                                        ?>
                                            <li>
                                                <span class="aat-major-race-nominee-primary"><?php echo $aat_render_hub_text_link($nominee_primary, !empty($major_nominee_row['primary_url']) ? (string) $major_nominee_row['primary_url'] : '', 'aat-hub-inline-link'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                                                <?php if ($nominee_secondary !== '') : ?>
                                                    <span class="aat-major-race-nominee-secondary"><?php echo $aat_render_hub_text_link($nominee_secondary, !empty($major_nominee_row['secondary_url']) ? (string) $major_nominee_row['secondary_url'] : '', 'aat-hub-inline-link'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                            <div class="aat-major-race-actions">
                                <?php if ($major_url !== '') : ?>
                                    <a class="aat-hub-card-action aat-winner-circle-action is-kind-category" href="<?php echo esc_url($major_url); ?>"><?php echo esc_html__('Category File', 'academy-awards-table'); ?></a>
                                <?php endif; ?>
                                <a class="aat-hub-card-action aat-winner-circle-action is-kind-ceremony" href="<?php echo esc_url($ceremony_ballot_full_url . '#ceremony-category-' . sanitize_title($major_category)); ?>"><?php echo esc_html__('Full Race Ballot', 'academy-awards-table'); ?></a>
                                <?php if ($feature_film_history_url !== '') : ?>
                                    <a class="aat-hub-card-action aat-winner-circle-action is-kind-film-history" href="<?php echo esc_url($feature_film_history_url); ?>"><?php echo esc_html__('Film History', 'academy-awards-table'); ?></a>
                                <?php endif; ?>
                                <?php if ($feature_person_history_url !== '') : ?>
                                    <a class="aat-hub-card-action aat-winner-circle-action is-kind-<?php echo esc_attr($feature_person_history_kind); ?>" href="<?php echo esc_url($feature_person_history_url); ?>"><?php echo esc_html($feature_person_history_label); ?></a>
                                <?php endif; ?>
                                <?php if ($feature_review_url !== '') : ?>
                                    <a class="aat-hub-card-action aat-winner-circle-action is-kind-review" href="<?php echo esc_url($feature_review_url); ?>"><?php echo esc_html__('Review', 'academy-awards-table'); ?></a>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
<?php $aat_sections['major-races'] = ob_get_clean(); ob_start(); ?>

        <?php if (!empty($ceremony_ballot_groups)) : ?>
            <section class="aat-hub-section aat-ceremony-ballot-ledger">
                <div class="aat-section-head">
                    <h2 class="aat-section-title"><?php echo esc_html__('Complete Ceremony Ledger', 'academy-awards-table'); ?></h2>
                    <p class="aat-section-description">
                        <?php
                            echo esc_html(
                                $ceremony_ballot_full_requested
                                    ? __('Every winner and nominee in every category, with film, person and review links.', 'academy-awards-table')
                                    : __('The winner in every category. Open the full ballot to see every nominee.', 'academy-awards-table')
                            );
                        ?>
                    </p>
                    <p class="aat-ceremony-ledger-mode">
                        <?php if ($ceremony_ballot_full_requested) : ?>
                            <a class="aat-hub-card-action aat-winner-circle-action is-kind-ceremony" href="<?php echo esc_url($ceremony_ballot_fast_url); ?>"><?php echo esc_html__('Use Fast View', 'academy-awards-table'); ?></a>
                        <?php else : ?>
                            <a class="aat-hub-card-action aat-winner-circle-action is-kind-ceremony" href="<?php echo esc_url($ceremony_ballot_full_url); ?>"><?php echo esc_html__('Open Full Ballot', 'academy-awards-table'); ?></a>
                        <?php endif; ?>
                    </p>
                </div>
                <p class="aat-ceremony-ballot-summary">
                    <span><?php echo esc_html(sprintf(_n('%s category', '%s categories', count($ceremony_ballot_groups), 'academy-awards-table'), number_format_i18n(count($ceremony_ballot_groups)))); ?></span><span class="aat-meta-sep" aria-hidden="true">&middot;</span><span><?php echo esc_html(sprintf(_n('%s record', '%s records', $noms, 'academy-awards-table'), number_format_i18n($noms))); ?></span><span class="aat-meta-sep" aria-hidden="true">&middot;</span><span><?php echo esc_html(sprintf(_n('%s winner', '%s winners', $wins, 'academy-awards-table'), number_format_i18n($wins))); ?></span>
                </p>
                <div class="aat-ceremony-ballot-groups">
                    <?php foreach ($ceremony_ballot_groups as $ballot_group) :
                        $group_category = trim((string) ($ballot_group['category'] ?? ''));
                        $group_label = trim((string) ($ballot_group['label'] ?? $aat->format_category_display($group_category, $ceremony)));
                        $group_url = trim((string) ($ballot_group['url'] ?? ($group_category !== '' ? $aat->get_category_url($group_category) : '')));
                        $group_rows = !empty($ballot_group['rows']) && is_array($ballot_group['rows']) ? $ballot_group['rows'] : array();
                        $group_winner_count = intval($ballot_group['winner_count'] ?? 0);
                        $group_nominee_count = max(0, count($group_rows) - $group_winner_count);
                        $rendered_group_rows = $ceremony_ballot_full_requested ? $group_rows : array_values(array_filter($group_rows, function($candidate_row) {
                            return !empty($candidate_row['winner']);
                        }));
                        if ($group_category === '' || empty($group_rows)) {
                            continue;
                        }
                    ?>
                        <section class="aat-ceremony-ballot-group" id="ceremony-category-<?php echo esc_attr(sanitize_title($group_category)); ?>">
                            <header class="aat-ceremony-ballot-group-head">
                                <div>
                                    <h3>
                                        <?php echo $aat_render_hub_text_link($group_label, $group_url, 'aat-hub-inline-link aat-hub-inline-link-title'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    </h3>
                                </div>
                                <p class="aat-ceremony-ballot-group-count">
                                    <?php echo esc_html(sprintf(_n('%s winner', '%s winners', $group_winner_count, 'academy-awards-table'), number_format_i18n($group_winner_count))); ?><span class="aat-meta-sep" aria-hidden="true">&middot;</span><?php echo esc_html(sprintf(_n('%s record', '%s records', count($group_rows), 'academy-awards-table'), number_format_i18n(count($group_rows)))); ?>
                                </p>
                            </header>
                            <?php if (!$ceremony_ballot_full_requested && $group_nominee_count > 0) : ?>
                                <p class="aat-ceremony-nominee-summary">
                                    <a class="aat-hub-inline-link" href="<?php echo esc_url($ceremony_ballot_full_url . '#ceremony-category-' . sanitize_title($group_category)); ?>"><?php echo esc_html(sprintf(
                                        /* translators: %d: number of non-winning nominee rows held back in fast ceremony mode */
                                        _n('%d more nominee in the full ballot', '%d more nominees in the full ballot', $group_nominee_count, 'academy-awards-table'),
                                        $group_nominee_count
                                    )); ?></a>
                                </p>
                            <?php endif; ?>
                            <div class="aat-ceremony-ballot-rows">
                                <?php foreach ($rendered_group_rows as $ballot_row) :
                                    $ballot_row = $aat_enrich_winner_entry_links($ballot_row);
                                    $is_row_winner = !empty($ballot_row['winner']);
                                    $row_film_html = $aat_render_pipe_links($ballot_row['film'] ?? '', $ballot_row['film_id'] ?? '', 'aat-hub-inline-link aat-ballot-main-link');
                                    $row_credit_source = trim((string) ($ballot_row['nominees'] ?? ''));
                                    if ($row_credit_source === '') {
                                        $row_credit_source = trim((string) ($ballot_row['name'] ?? ''));
                                    }
                                    $row_credit_html = $aat_render_pipe_links($row_credit_source, $ballot_row['nominee_ids'] ?? '', 'aat-hub-inline-link');
                                    $row_film_plain = $aat_pipe_display($ballot_row['film'] ?? '');
                                    $row_credit_plain = $aat_clean_nominee_label($aat_pipe_display($row_credit_source));
                                    $row_primary_html = $row_film_html !== '' ? $row_film_html : $row_credit_html;
                                    $show_credit = $row_credit_html !== '' && $aat_normalize_comparable_name($row_credit_plain) !== $aat_normalize_comparable_name($row_film_plain);
                                    $row_primary_title_id = '';
                                    foreach (explode('|', (string) ($ballot_row['film_id'] ?? '')) as $row_title_id) {
                                        $row_title_id = strtolower(trim((string) $row_title_id));
                                        if (preg_match('/^tt\d+$/', $row_title_id)) {
                                            $row_primary_title_id = $row_title_id;
                                            break;
                                        }
                                    }
                                    $row_film_url = !empty($ballot_row['film_url']) ? (string) $ballot_row['film_url'] : ($row_primary_title_id !== '' ? $aat_build_entity_url($row_primary_title_id) : '');
                                    $row_film_history_url = !empty($ballot_row['film_history_url']) ? (string) $ballot_row['film_history_url'] : ($row_film_url !== '' ? $aat_entity_section_url($row_film_url, 'oscar-history') : '');
                                    $row_person_history_url = !empty($ballot_row['person_history_url']) ? (string) $ballot_row['person_history_url'] : '';
                                    $row_person_history_meta = $aat_person_history_action_meta($row_person_history_url, $ballot_row['canonical_category'] ?? '');
                                    $row_person_history_label = $row_person_history_meta['label'];
                                    $row_person_history_kind = $row_person_history_meta['kind'];
                                    $row_review_url = ($row_primary_title_id !== '' && !empty($ceremony_review_map[$row_primary_title_id])) ? (string) $ceremony_review_map[$row_primary_title_id] : '';
                                ?>
                                    <article class="aat-ceremony-ballot-row<?php echo $is_row_winner ? ' is-winner' : ''; ?>">
                                        <div class="aat-ceremony-ballot-status">
                                            <span class="<?php echo $is_row_winner ? 'aat-winner-badge' : 'aat-nominee-badge'; ?>"><?php echo esc_html($is_row_winner ? __('Winner', 'academy-awards-table') : __('Nominee', 'academy-awards-table')); ?></span>
                                        </div>
                                        <div class="aat-ceremony-ballot-copy">
                                            <?php if ($row_primary_html !== '') : ?>
                                                <h4><?php echo wp_kses_post($row_primary_html); ?></h4>
                                            <?php endif; ?>
                                            <?php if ($show_credit) : ?>
                                                <p class="aat-ballot-credit"><strong><?php echo esc_html__('Credit', 'academy-awards-table'); ?>:</strong> <?php echo wp_kses_post($row_credit_html); ?></p>
                                            <?php endif; ?>
                                            <?php if (!empty($ballot_row['detail'])) : ?>
                                                <p class="aat-ballot-detail"><?php echo esc_html($aat_film_display($ballot_row['detail'])); ?></p>
                                            <?php endif; ?>
                                            <?php if (!empty($ballot_row['note'])) : ?>
                                                <p class="aat-ballot-note"><?php echo esc_html((string) $ballot_row['note']); ?></p>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($row_film_history_url !== '' || $row_person_history_url !== '' || $row_review_url !== '') : ?>
                                            <div class="aat-ceremony-ballot-actions">
                                                <?php if ($row_film_history_url !== '') : ?>
                                                    <a class="aat-hub-card-action aat-winner-circle-action is-kind-film-history" href="<?php echo esc_url($row_film_history_url); ?>"><?php echo esc_html__('Film History', 'academy-awards-table'); ?></a>
                                                <?php endif; ?>
                                                <?php if ($row_person_history_url !== '') : ?>
                                                    <a class="aat-hub-card-action aat-winner-circle-action is-kind-<?php echo esc_attr($row_person_history_kind); ?>" href="<?php echo esc_url($row_person_history_url); ?>"><?php echo esc_html($row_person_history_label); ?></a>
                                                <?php endif; ?>
                                                <?php if ($row_review_url !== '') : ?>
                                                    <a class="aat-hub-card-action aat-winner-circle-action is-kind-review" href="<?php echo esc_url($row_review_url); ?>"><?php echo esc_html__('Review', 'academy-awards-table'); ?></a>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
<?php $aat_sections['ballot-ledger'] = ob_get_clean(); ob_start(); ?>

        <?php $ceremony_titles = method_exists($aat, 'get_ceremony_title_highlights') ? $aat->get_ceremony_title_highlights($ceremony, 18) : array(); ?>
        <?php $ceremony_review_cards = !empty($ceremony_titles) ? $aat_build_hub_review_cards($ceremony_titles, $aat_get_related_review_limit()) : array(); ?>
        <?php
            // The nominees grid above already shows the Best Picture field; the highlights add the other titles.
            $ceremony_shown_title_ids = array();
            foreach ($best_picture_nominees as $shown_entry) {
                $shown_id = strtolower(trim((string) ($shown_entry['film_id'] ?? '')));
                if ($shown_id !== '') {
                    $ceremony_shown_title_ids[$shown_id] = true;
                }
            }
            $ceremony_highlight_titles = array_values(array_filter($ceremony_titles, function($highlight_entry) use ($ceremony_shown_title_ids) {
                $highlight_id = strtolower(trim((string) ($highlight_entry['film_id'] ?? '')));
                return $highlight_id === '' || empty($ceremony_shown_title_ids[$highlight_id]);
            }));
        ?>
        <?php if (!empty($ceremony_highlight_titles)) : ?>
            <div class="aat-hub-section aat-ceremony-gallery-section">
                <h2><?php echo esc_html__('Ceremony Highlights', 'academy-awards-table'); ?></h2>
                <div class="aat-filmography-grid aat-hub-film-grid">
                    <?php foreach ($ceremony_highlight_titles as $entry) :
                        $fid = strtolower(trim((string) ($entry['film_id'] ?? '')));
                        if (!$fid) { continue; }
                        $visual = method_exists($aat, 'get_title_visual_package') ? $aat->get_title_visual_package($fid, 'medium_large') : array();
                        $film_label = !empty($entry['film']) ? (string) $entry['film'] : $aat->lookup_title_label($fid);
                        $film_url = $aat->get_entity_url($fid);
                        $entry = $aat_enrich_winner_entry_links($entry);
                    ?>
                        <article class="aat-filmography-card aat-hub-film-card<?php echo !empty($entry['winner']) ? ' is-winner' : ''; ?>">
                            <a class="aat-filmography-link" href="<?php echo esc_url($film_url ? $film_url : $db_url); ?>" aria-label="<?php echo esc_attr($film_label); ?>">
                                <div class="aat-filmography-poster-wrap">
                                    <?php if (!empty($visual['poster_html'])) : ?>
                                        <?php echo $visual['poster_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    <?php elseif (!empty($visual['poster_url'])) : ?>
                                        <img class="aat-filmography-poster" src="<?php echo esc_url($visual['poster_url']); ?>" alt="<?php echo esc_attr($film_label); ?> poster" loading="lazy" decoding="async" />
                                    <?php elseif (!empty($visual['card_fallback_html'])) : ?>
                                        <?php echo $visual['card_fallback_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    <?php else : ?>
                                        <div class="aat-filmography-poster-placeholder"><span><?php echo esc_html($film_label); ?></span></div>
                                    <?php endif; ?>
                                    <?php if (!empty($entry['winner'])) : ?><span class="aat-winner-badge aat-card-badge"><?php echo esc_html__('Winner', 'academy-awards-table'); ?></span><?php endif; ?>
                                </div>
                            </a>
                            <h3 class="aat-filmography-title"><?php echo $aat_render_hub_text_link($film_label, $film_url ? $film_url : $db_url, 'aat-hub-inline-link aat-hub-inline-link-title'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h3>
                            <p class="aat-filmography-meta">
                                <?php echo $aat_render_hub_text_link($aat->format_category_display($entry['canonical_category'] ?? '', intval($entry['ceremony'] ?? $ceremony)), !empty($entry['category_url']) ? (string) $entry['category_url'] : '', 'aat-hub-inline-link'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <?php if (!empty($entry['person_label'])) : ?>
                                    <span class="aat-meta-sep" aria-hidden="true">&middot;</span>
                                    <?php echo $aat_render_hub_text_link((string) $entry['person_label'], !empty($entry['person_url']) ? (string) $entry['person_url'] : '', 'aat-hub-inline-link'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <?php endif; ?>
                            </p>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
<?php $aat_sections['gallery'] = ob_get_clean(); ob_start(); ?>

        <?php if (!empty($ceremony_review_cards)) : ?>
            <div class="aat-hub-section aat-related-reviews-section <?php echo esc_attr($aat_related_review_treatment_class); ?>">
                <h2><?php echo esc_html__('On Lunara', 'academy-awards-table'); ?></h2>
                <p class="aat-hub-copy"><?php echo esc_html__('Lunara Film reviews of films from this ceremony.', 'academy-awards-table'); ?></p>
                <div class="aat-related-reviews-grid <?php echo esc_attr($aat_related_review_treatment_class); ?>">
                    <?php foreach ($ceremony_review_cards as $card) : ?>
                        <?php
                            $aat_related_review_has_media = !empty($card['review_thumb']) || !empty($card['fallback_html']);
                            $aat_related_review_classes = array('aat-related-review-card', $aat_related_review_has_media ? 'has-media' : 'has-no-media');
                        ?>
                        <article class="<?php echo esc_attr(implode(' ', $aat_related_review_classes)); ?>">
                            <?php if ($aat_related_review_has_media) : ?>
                            <a class="aat-related-review-media" href="<?php echo esc_url($card['review_url']); ?>">
                                <?php if (!empty($card['review_thumb'])) : ?>
                                    <?php echo $card['review_thumb']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <?php elseif (!empty($card['fallback_html'])) : ?>
                                    <?php echo $card['fallback_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <?php endif; ?>
                            </a>
                            <?php endif; ?>
                            <div class="aat-related-review-body">
                                <h3 class="aat-related-review-title"><a href="<?php echo esc_url($card['review_url']); ?>"><?php echo esc_html($card['review_title']); ?></a></h3>
                                <p class="aat-related-review-meta">
                                    <?php if (!empty($card['film_url'])) : ?>
                                        <a href="<?php echo esc_url($card['film_url']); ?>"><?php echo esc_html($card['film_label']); ?></a>
                                    <?php else : ?>
                                        <?php echo esc_html($card['film_label']); ?>
                                    <?php endif; ?>
                                    <?php if (!empty($card['film_year'])) : ?>
                                        <span class="aat-meta-sep" aria-hidden="true">&middot;</span><span><?php echo esc_html($card['film_year']); ?></span>
                                    <?php endif; ?>
                                </p>
                                <div class="aat-related-review-actions">
                                    <a class="aat-btn aat-btn-secondary" href="<?php echo esc_url($card['review_url']); ?>"><?php echo esc_html__('Read the review', 'academy-awards-table'); ?></a>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
<?php $aat_sections['related-reviews'] = ob_get_clean(); ob_start(); ?>

        <?php if (!empty($cats)) : ?>
            <div class="aat-hub-section">
                <h2><?php echo esc_html__('Categories in this ceremony', 'academy-awards-table'); ?></h2>
                <div class="aat-hub-chips">
                    <?php foreach ($cats as $cat) :
                        $url = $aat->get_category_url($cat);
                        $label = $aat->format_category_display($cat, $ceremony);
                    ?>
                        <a class="aat-hub-chip" href="<?php echo esc_url($url); ?>"><?php echo esc_html($label); ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
<?php $aat_sections['category-chips'] = ob_get_clean(); ob_start(); ?>

        <?php if (!empty($ceremony_rollup['winner_rows'])) : ?>
            <div class="aat-hub-section aat-winner-circle-section<?php echo $is_latest_ceremony ? ' is-hero-latest' : ''; ?>" id="ceremony-winner-circle">
                <h2><?php echo esc_html__('Winner Circle', 'academy-awards-table'); ?></h2>
                <div class="aat-winner-circle-grid<?php echo $is_latest_ceremony ? ' is-hero-latest' : ''; ?>">
                    <?php foreach ($ceremony_rollup['winner_rows'] as $winner_entry) :
                        $winner_entry = $aat_enrich_winner_entry_links($winner_entry);
                        $primary_label = $winner_entry['primary_label'] ?? $aat_winner_primary($winner_entry);
                        $secondary_label = $winner_entry['secondary_label'] ?? $aat_winner_secondary($winner_entry);
                        $category_url = $winner_entry['category_url'] ?? $aat->get_category_url($winner_entry['canonical_category'] ?? '');
                        $winner_visual = $is_latest_ceremony ? $aat_get_curated_winner_visual($winner_entry, 'medium_large') : array();
                        if (empty($winner_visual['poster_html']) && empty($winner_visual['poster_url']) && !empty($winner_entry['film_id'])) {
                            $winner_visual = $aat_get_visual_package((string) $winner_entry['film_id'], 'medium_large');
                        }

                        // Person-led categories show the winner's portrait; film-led cards keep the poster.
                        // Either way every card gets one media box, so the grid stays uniform.
                        $winner_is_person = !empty($winner_entry['primary_url']) && strpos((string) $winner_entry['primary_url'], '/oscars/name/') !== false;
                        $winner_portrait = ($winner_is_person || (empty($winner_visual['poster_html']) && empty($winner_visual['poster_url'])))
                            ? $aat_get_person_visual($winner_entry, 'medium_large')
                            : array();
                        $winner_media_html = '';
                        $winner_media_is_portrait = false;
                        if (!empty($winner_portrait['portrait_url'])) {
                            $winner_media_html = '<img class="aat-winner-circle-photo" src="' . esc_url($winner_portrait['portrait_url']) . '" alt="' . esc_attr($primary_label) . '" loading="lazy" decoding="async" />';
                            $winner_media_is_portrait = true;
                        } elseif (!empty($winner_visual['poster_html'])) {
                            $winner_media_html = (string) $winner_visual['poster_html'];
                        } elseif (!empty($winner_visual['poster_url'])) {
                            $winner_media_html = '<img class="aat-winner-circle-photo" src="' . esc_url($winner_visual['poster_url']) . '" alt="' . esc_attr($primary_label) . '" loading="lazy" decoding="async" />';
                        }
                        $winner_media_url = '';
                        if (!empty($winner_entry['primary_url'])) {
                            $winner_media_url = (string) $winner_entry['primary_url'];
                        } elseif (!empty($winner_entry['film_url'])) {
                            $winner_media_url = (string) $winner_entry['film_url'];
                        } elseif (!empty($winner_entry['secondary_url'])) {
                            $winner_media_url = (string) $winner_entry['secondary_url'];
                        } elseif (!empty($category_url)) {
                            $winner_media_url = (string) $category_url;
                        }
                        $winner_actions = $aat_build_winner_actions($winner_entry, (string) $category_url);
                    ?>
                        <article class="aat-winner-circle-card<?php echo $is_latest_ceremony ? ' is-hero-latest' : ''; ?><?php echo $winner_media_html !== '' ? ' has-hero-media' : ' has-no-media'; ?>">
                            <div class="aat-winner-circle-top">
                                <?php if ($category_url) : ?>
                                    <a class="aat-winner-circle-category" href="<?php echo esc_url($category_url); ?>"><?php echo esc_html($winner_entry['category_label']); ?></a>
                                <?php else : ?>
                                    <span class="aat-winner-circle-category"><?php echo esc_html($winner_entry['category_label']); ?></span>
                                <?php endif; ?>
                            </div>
                            <?php
                                // Honorary and Scientific & Technical awards have no film and no portrait.
                                // They get the same box as a plaque, so the grid never gaps.
                                $winner_media_class = 'aat-winner-circle-media';
                                if ($winner_media_html !== '') {
                                    $winner_media_class .= $winner_media_is_portrait ? ' is-portrait' : ' is-poster';
                                } else {
                                    $winner_media_class .= ' is-plaque';
                                }
                            ?>
                            <?php if ($winner_media_html !== '') : ?>
                                <?php if ($winner_media_url !== '') : ?>
                                    <a class="<?php echo esc_attr($winner_media_class); ?>" href="<?php echo esc_url($winner_media_url); ?>">
                                        <?php echo $winner_media_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    </a>
                                <?php else : ?>
                                    <div class="<?php echo esc_attr($winner_media_class); ?>">
                                        <?php echo $winner_media_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    </div>
                                <?php endif; ?>
                            <?php else : ?>
                                <div class="<?php echo esc_attr($winner_media_class); ?>">
                                    <span class="aat-winner-circle-plaque-mark" aria-hidden="true">&#9733;</span>
                                    <span class="aat-winner-circle-plaque-label"><?php echo esc_html($winner_entry['category_label']); ?></span>
                                </div>
                            <?php endif; ?>
                            <h3 class="aat-winner-circle-title">
                                <?php if (!empty($winner_entry['primary_url'])) : ?>
                                    <a href="<?php echo esc_url($winner_entry['primary_url']); ?>"><?php echo esc_html($primary_label); ?></a>
                                <?php else : ?>
                                    <?php echo esc_html($primary_label); ?>
                                <?php endif; ?>
                            </h3>
                            <?php if ($secondary_label !== '') : ?>
                                <p class="aat-winner-circle-meta">
                                    <?php if (!empty($winner_entry['secondary_url'])) : ?>
                                        <a href="<?php echo esc_url($winner_entry['secondary_url']); ?>"><?php echo esc_html($secondary_label); ?></a>
                                    <?php else : ?>
                                        <?php echo esc_html($secondary_label); ?>
                                    <?php endif; ?>
                                </p>
                            <?php endif; ?>
                                <?php if (!empty($winner_actions)) : ?>
                                    <div class="aat-winner-circle-actions">
                                        <?php foreach ($winner_actions as $winner_action) : ?>
                                            <a class="aat-hub-card-action aat-winner-circle-action<?php echo !empty($winner_action['kind']) ? ' is-kind-' . sanitize_html_class((string) $winner_action['kind']) : ''; ?>" href="<?php echo esc_url($winner_action['url']); ?>"><?php echo esc_html($winner_action['label']); ?></a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

<?php $aat_sections['winner-circle'] = ob_get_clean(); ob_start(); ?>
        <?php
            $table_view_url = add_query_arg('view', 'table', $aat->get_ceremony_url($ceremony));
            $poster_view_url = $aat->get_ceremony_url($ceremony);
        ?>
        <div class="aat-hub-section aat-explorer-callout">
            <div class="aat-explorer-shell">
                <div class="aat-explorer-copy">
                    <h2><?php echo esc_html__('Sortable ledger', 'academy-awards-table'); ?></h2>
                    <p class="aat-hub-copy"><?php echo esc_html__('Every nomination from this ceremony as a sortable table, for research that needs the raw record beside the posters and winners above.', 'academy-awards-table'); ?></p>
                </div>
                <div class="aat-hub-actions aat-view-toggle">
                    <a class="aat-btn aat-btn-secondary<?php echo !$table_view_requested ? ' is-active' : ''; ?>" href="<?php echo esc_url($poster_view_url); ?>"><?php echo esc_html__('Poster View', 'academy-awards-table'); ?></a>
                    <a class="aat-btn aat-btn-primary<?php echo $table_view_requested ? ' is-active' : ''; ?>" href="<?php echo esc_url($table_view_url); ?>"><?php echo esc_html__('Data Explorer', 'academy-awards-table'); ?></a>
                </div>
            </div>
        </div>
<?php $aat_sections['explorer-callout'] = ob_get_clean(); ob_start(); ?>

        <?php if ($table_view_requested) : ?>
            <div class="aat-hub-section aat-table-shell">
                <?php
                    echo $aat->render_shortcode(array(
                        'ceremony' => (string) $ceremony,
                        'layout' => 'embedded',
                    ));
                ?>
            </div>
        <?php endif; ?>
<?php $aat_sections['table-shell'] = ob_get_clean(); echo implode('', apply_filters('aat_hub_route_sections', $aat_sections, $aat_route_context)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sections are template-rendered markup captured above. ?>
        </div>

    <?php
        // CATEGORY PAGE
        elseif ($hub === 'category') :
            $canonical = $aat->resolve_category_slug($hub_id);
            if (empty($canonical)) {
                $mark_404();
            }

            $label = $aat->format_category_display($canonical);
            $category_heading_label = $label;
            if ($category_heading_label !== '' && strtoupper($category_heading_label) === $category_heading_label) {
                $category_heading_label = ucwords(strtolower($category_heading_label));
                $category_heading_label = str_replace(
                    array(' And ', ' Of ', ' In ', ' For '),
                    array(' and ', ' of ', ' in ', ' for '),
                    $category_heading_label
                );
            }
            $category_summary = method_exists($aat, 'get_category_summary') ? $aat->get_category_summary($canonical) : array();
            $noms = intval($category_summary['nominations'] ?? 0);
            $wins = intval($category_summary['wins'] ?? 0);
            $cers = intval($category_summary['ceremonies'] ?? 0);
            $first_cer = intval($category_summary['first_ceremony'] ?? 0);
            $last_cer = intval($category_summary['last_ceremony'] ?? 0);
            $first_year = $first_cer ? $aat->get_ceremony_year($first_cer) : '';
            $last_year = $last_cer ? $aat->get_ceremony_year($last_cer) : '';
            $latest_winner = method_exists($aat, 'get_category_latest_winner') ? $aat->get_category_latest_winner($canonical) : array();
            if (!empty($latest_winner)) {
                $latest_winner = $aat_enrich_winner_entry_links($latest_winner);
            }
            $category_spotlight = array();
            $category_key = strtoupper(trim((string) $canonical));
            $premium_category_profiles = array(
                'BEST PICTURE' => array(
                    'class' => 'aat-best-picture-dossier',
                    'title' => __('Best Picture Dossier', 'academy-awards-table'),
                    'subtitle' => __('The Academy Awards top category, built as a living historical file: every winner, every ceremony trail, and the films that keep pulling readers deeper into the ledger.', 'academy-awards-table'),
                    'summary_label' => __('Best Picture ledger summary', 'academy-awards-table'),
                    'history_title' => __('Best Picture Through the Eras', 'academy-awards-table'),
                ),
                'DIRECTING' => array(
                    'class' => 'aat-directing-dossier',
                    'title' => __('Best Director Dossier', 'academy-awards-table'),
                    'subtitle' => __('The director file: winners, nominated films, and the authorship trails that shaped Oscar history from studio craft to modern auteur campaigns.', 'academy-awards-table'),
                ),
                'ACTOR IN A LEADING ROLE' => array(
                    'class' => 'aat-acting-dossier aat-actor-dossier',
                    'title' => __('Best Actor Dossier', 'academy-awards-table'),
                    'subtitle' => __('A performance ledger for leading men: every winning turn, every ceremony trail, and the films that made the race feel larger than a list of names.', 'academy-awards-table'),
                ),
                'ACTRESS IN A LEADING ROLE' => array(
                    'class' => 'aat-acting-dossier aat-actress-dossier',
                    'title' => __('Best Actress Dossier', 'academy-awards-table'),
                    'subtitle' => __('A performance ledger for leading women: the winning turns, career pivots, star-making races, and ceremony trails that carry the category.', 'academy-awards-table'),
                ),
                'ACTOR IN A SUPPORTING ROLE' => array(
                    'class' => 'aat-acting-dossier aat-supporting-actor-dossier',
                    'title' => __('Best Supporting Actor Dossier', 'academy-awards-table'),
                    'subtitle' => __('The supporting actor file: character turns, scene-stealing campaigns, and every Oscar trail behind the category.', 'academy-awards-table'),
                ),
                'ACTRESS IN A SUPPORTING ROLE' => array(
                    'class' => 'aat-acting-dossier aat-supporting-actress-dossier',
                    'title' => __('Best Supporting Actress Dossier', 'academy-awards-table'),
                    'subtitle' => __('The supporting actress file: breakthrough performances, veteran coronations, and the ceremony trails that give the category its shape.', 'academy-awards-table'),
                ),
                'WRITING (ORIGINAL SCREENPLAY)' => array(
                    'class' => 'aat-writing-dossier',
                    'title' => __('Original Screenplay Dossier', 'academy-awards-table'),
                    'subtitle' => __('The original writing ledger: Academy-winning ideas, scripts, and narrative turns tracked through every ceremony trail.', 'academy-awards-table'),
                ),
                'WRITING (ADAPTED SCREENPLAY)' => array(
                    'class' => 'aat-writing-dossier',
                    'title' => __('Adapted Screenplay Dossier', 'academy-awards-table'),
                    'subtitle' => __('The adaptation file: books, plays, articles, lives, sequels, and source material transformed into Oscar-winning screen stories.', 'academy-awards-table'),
                ),
                'CINEMATOGRAPHY' => array(
                    'class' => 'aat-craft-dossier aat-cinematography-dossier',
                    'title' => __('Cinematography Dossier', 'academy-awards-table'),
                    'subtitle' => __('The image-making ledger: winning visual languages, photographed worlds, and the films that defined how Oscar saw the movies.', 'academy-awards-table'),
                ),
                'FILM EDITING' => array(
                    'class' => 'aat-craft-dossier aat-editing-dossier',
                    'title' => __('Film Editing Dossier', 'academy-awards-table'),
                    'subtitle' => __('The cutting-room file: pacing, structure, momentum, and the invisible craft behind the Academy winning edits.', 'academy-awards-table'),
                ),
                'COSTUME DESIGN' => array(
                    'class' => 'aat-craft-dossier aat-costume-dossier',
                    'title' => __('Costume Design Dossier', 'academy-awards-table'),
                    'subtitle' => __('The costume file: period worlds, character silhouettes, and the design campaigns that turned fabric into Oscar history.', 'academy-awards-table'),
                ),
                'VISUAL EFFECTS' => array(
                    'class' => 'aat-craft-dossier aat-effects-dossier',
                    'title' => __('Visual Effects Dossier', 'academy-awards-table'),
                    'subtitle' => __('The effects ledger: spectacle, invention, technical leaps, and the films that expanded what Academy voters could see on screen.', 'academy-awards-table'),
                ),
                'MUSIC (ORIGINAL SCORE)' => array(
                    'class' => 'aat-music-dossier',
                    'title' => __('Original Score Dossier', 'academy-awards-table'),
                    'subtitle' => __('The score file: composers, musical signatures, and the Academy-winning sounds that gave films their emotional architecture.', 'academy-awards-table'),
                ),
                'MUSIC (ORIGINAL SONG)' => array(
                    'class' => 'aat-music-dossier',
                    'title' => __('Original Song Dossier', 'academy-awards-table'),
                    'subtitle' => __('The song ledger: movie music as campaign engine, cultural memory, and Oscar-night signature.', 'academy-awards-table'),
                ),
                'INTERNATIONAL FEATURE FILM' => array(
                    'class' => 'aat-feature-dossier aat-international-dossier',
                    'title' => __('International Feature Dossier', 'academy-awards-table'),
                    'subtitle' => __('The international feature file: countries, auteurs, breakthrough films, and the Academy trails that widened Oscar history beyond Hollywood.', 'academy-awards-table'),
                ),
                'ANIMATED FEATURE FILM' => array(
                    'class' => 'aat-feature-dossier aat-animation-dossier',
                    'title' => __('Animated Feature Dossier', 'academy-awards-table'),
                    'subtitle' => __('The animation file: studio eras, hand-drawn legacies, digital dominance, and every Oscar trail behind the feature race.', 'academy-awards-table'),
                ),
                'DOCUMENTARY (FEATURE)' => array(
                    'class' => 'aat-feature-dossier aat-documentary-dossier',
                    'title' => __('Documentary Feature Dossier', 'academy-awards-table'),
                    'subtitle' => __('The documentary feature file: nonfiction filmmaking, public memory, and the Oscar races that turned reportage into cinema history.', 'academy-awards-table'),
                ),
            );
            $premium_category_profile = isset($premium_category_profiles[$category_key]) ? $premium_category_profiles[$category_key] : array();
            $is_premium_category_dossier = !empty($premium_category_profile);
            if ($is_premium_category_dossier) {
                $premium_category_profile = array_merge(array(
                    'class' => '',
                    'title' => sprintf(
                        /* translators: %s: Oscar category display label. */
                        __('%s Dossier', 'academy-awards-table'),
                        $label
                    ),
                    'subtitle' => sprintf(
                        /* translators: %s: Oscar category display label. */
                        __('A premium category file for %s: every winner, every ceremony trail, and the routes that keep the ledger explorable.', 'academy-awards-table'),
                        $label
                    ),
                    'summary_label' => sprintf(
                        /* translators: %s: Oscar category display label. */
                        __('%s ledger summary', 'academy-awards-table'),
                        $label
                    ),
                    'history_title' => sprintf(
                        /* translators: %s: Oscar category display label. */
                        __('%s Through the Eras', 'academy-awards-table'),
                        $label
                    ),
                ), $premium_category_profile);
            }
            $category_span_label = ($first_year && $last_year) ? ($first_year . '-' . $last_year) : '';
            $latest_ceremony_url = $last_cer ? $aat->get_ceremony_url($last_cer) : '';
            $latest_winner_label = '';
            if (!empty($latest_winner)) {
                $latest_winner_label = !empty($latest_winner['primary_label']) ? (string) $latest_winner['primary_label'] : (!empty($latest_winner['film']) ? (string) $latest_winner['film'] : (string) ($latest_winner['name'] ?? ''));
            }
    ?>
        <?php $category_hero_poster = !empty($latest_winner['film_id']) ? $aat_index_poster_html((string) $latest_winner['film_id']) : ''; ?>
        <div class="aat-category-dossier aat-inner-route-system<?php echo $is_premium_category_dossier ? ' aat-premium-category-dossier ' . esc_attr((string) $premium_category_profile['class']) : ' aat-generic-category-dossier'; ?>">
<?php // Route section composer (2.7.82): every top-level category section is captured into $aat_sections and re-emitted through aat_hub_route_sections. Default output is byte-identical; the filter is inert until a consumer hooks it. ?>
<?php $aat_sections = array(); $aat_route_context = $aat->get_route_context(); ob_start(); ?>
        <?php if ($is_premium_category_dossier) : ?>
            <section class="aat-dossier-hero">
                <div class="aat-dossier-hero-copy">
                    <h1 class="aat-hub-title"><?php echo esc_html((string) $premium_category_profile['title']); ?></h1>
                    <p class="aat-hub-subtitle"><?php echo esc_html((string) $premium_category_profile['subtitle']); ?></p>
                </div>
                <div class="aat-dossier-command-band" aria-label="<?php echo esc_attr((string) $premium_category_profile['summary_label']); ?>">
                    <div class="aat-dossier-command-card is-latest<?php echo $category_hero_poster !== '' ? ' has-hero-poster' : ''; ?>">
                        <span><?php echo esc_html__('Latest Winner', 'academy-awards-table'); ?></span>
                        <strong><?php echo esc_html($latest_winner_label !== '' ? $latest_winner_label : __('Pending', 'academy-awards-table')); ?></strong>
                        <?php if ($category_hero_poster !== '') : ?>
                            <div class="aat-category-hero-poster" aria-hidden="true"><?php echo $category_hero_poster; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="aat-dossier-command-card">
                        <span><?php echo esc_html__('Span', 'academy-awards-table'); ?></span>
                        <strong><?php echo esc_html($category_span_label !== '' ? $category_span_label : '-'); ?></strong>
                    </div>
                    <div class="aat-dossier-command-card">
                        <span><?php echo esc_html__('Nominations', 'academy-awards-table'); ?></span>
                        <strong><?php echo esc_html(number_format_i18n($noms)); ?></strong>
                    </div>
                    <div class="aat-dossier-command-card">
                        <span><?php echo esc_html__('Ceremonies', 'academy-awards-table'); ?></span>
                        <strong><?php echo esc_html(number_format_i18n($cers)); ?></strong>
                    </div>
                </div>
                <div class="aat-hub-actions aat-dossier-actions">
                    <a class="aat-btn aat-btn-secondary" href="<?php echo esc_url($aat->get_categories_index_url()); ?>"><?php echo esc_html__('All Categories', 'academy-awards-table'); ?></a>
                    <a class="aat-btn aat-btn-secondary" href="<?php echo esc_url($aat->get_ceremonies_index_url()); ?>"><?php echo esc_html__('Ceremonies', 'academy-awards-table'); ?></a>
                    <?php if ($latest_ceremony_url !== '') : ?>
                        <a class="aat-btn aat-btn-secondary" href="<?php echo esc_url($latest_ceremony_url); ?>"><?php echo esc_html__('Latest Ceremony', 'academy-awards-table'); ?></a>
                    <?php endif; ?>
                    <a class="aat-btn aat-btn-primary" href="<?php echo esc_url($db_url); ?>"><?php echo esc_html__('Open Full Ledger', 'academy-awards-table'); ?></a>
                </div>
            </section>
        <?php else : ?>
            <section class="aat-dossier-hero aat-generic-category-hero">
                <div class="aat-dossier-hero-copy">
                    <h1 class="aat-hub-title"><?php echo esc_html($category_heading_label); ?></h1>
                    <p class="aat-hub-subtitle"><?php echo esc_html(sprintf(
                        /* translators: %s: Oscar category display label. */
                        __('A working %s dossier with winners, craft credits, ceremony trails, and nominee history kept close to the surface.', 'academy-awards-table'),
                        $category_heading_label
                    )); ?></p>
                </div>
                <div class="aat-dossier-command-band" aria-label="<?php echo esc_attr(sprintf(__('%s category summary', 'academy-awards-table'), $category_heading_label)); ?>">
                    <div class="aat-dossier-command-card is-latest<?php echo $category_hero_poster !== '' ? ' has-hero-poster' : ''; ?>">
                        <span><?php echo esc_html__('Latest Winner', 'academy-awards-table'); ?></span>
                        <strong><?php echo esc_html($latest_winner_label !== '' ? $latest_winner_label : __('Pending', 'academy-awards-table')); ?></strong>
                        <?php if ($category_hero_poster !== '') : ?>
                            <div class="aat-category-hero-poster" aria-hidden="true"><?php echo $category_hero_poster; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="aat-dossier-command-card">
                        <span><?php echo esc_html__('Span', 'academy-awards-table'); ?></span>
                        <strong><?php echo esc_html($category_span_label !== '' ? $category_span_label : '-'); ?></strong>
                    </div>
                    <div class="aat-dossier-command-card">
                        <span><?php echo esc_html__('Nominations', 'academy-awards-table'); ?></span>
                        <strong><?php echo esc_html(number_format_i18n($noms)); ?></strong>
                    </div>
                    <div class="aat-dossier-command-card">
                        <span><?php echo esc_html__('Ceremonies', 'academy-awards-table'); ?></span>
                        <strong><?php echo esc_html(number_format_i18n($cers)); ?></strong>
                    </div>
                </div>
                <div class="aat-hub-actions aat-dossier-actions">
                    <a class="aat-btn aat-btn-secondary" href="<?php echo esc_url($aat->get_categories_index_url()); ?>"><?php echo esc_html__('All Categories', 'academy-awards-table'); ?></a>
                    <a class="aat-btn aat-btn-secondary" href="<?php echo esc_url($aat->get_ceremonies_index_url()); ?>"><?php echo esc_html__('Ceremonies', 'academy-awards-table'); ?></a>
                    <?php if ($latest_ceremony_url !== '') : ?>
                        <a class="aat-btn aat-btn-secondary" href="<?php echo esc_url($latest_ceremony_url); ?>"><?php echo esc_html__('Latest Ceremony', 'academy-awards-table'); ?></a>
                    <?php endif; ?>
                    <a class="aat-btn aat-btn-primary" href="<?php echo esc_url($db_url); ?>"><?php echo esc_html__('Open Full Ledger', 'academy-awards-table'); ?></a>
                </div>
            </section>
        <?php endif; ?>
<?php $aat_sections['hero'] = ob_get_clean(); ob_start(); ?>

        <?php if (!empty($latest_winner)) : ?>
            <?php
                $category_spotlight_meta = array();
                if (!empty($latest_winner['year'])) {
                    $category_spotlight_meta[] = (string) $latest_winner['year'];
                }
                if (!empty($latest_winner['name']) && !empty($latest_winner['film']) && $latest_winner['name'] !== $latest_winner['film']) {
                    $category_spotlight_meta[] = (string) $latest_winner['name'];
                } elseif (!empty($latest_winner['detail'])) {
                    $category_spotlight_meta[] = $aat_film_display($latest_winner['detail']);
                }
                if (!empty($latest_winner['film_id'])) {
                    $category_spotlight = $aat_build_title_spotlight((string) $latest_winner['film_id'], (string) ($latest_winner['film'] ?? ''), $category_spotlight_meta, __('Latest winner', 'academy-awards-table'));
                }
            ?>
            <section class="aat-hub-section aat-category-latest-winner" aria-label="<?php echo esc_attr__('Latest winner', 'academy-awards-table'); ?>">
                <div class="aat-ceremony-marquee-copy">
                    <?php $latest_winner_headline = !empty($latest_winner['primary_label']) ? (string) $latest_winner['primary_label'] : (!empty($latest_winner['film']) ? (string) $latest_winner['film'] : (string) ($latest_winner['name'] ?? '')); ?>
                    <h2><?php echo $aat_render_hub_text_link($latest_winner_headline, !empty($latest_winner['primary_url']) ? (string) $latest_winner['primary_url'] : '', 'aat-hub-inline-link aat-hub-inline-link-title'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h2>
                    <p class="aat-hub-copy">
                        <?php echo esc_html(sprintf(__('Most recent winning record: %1$s ceremony (%2$s).', 'academy-awards-table'), $aat->ordinal(intval($latest_winner['ceremony'] ?? 0)), (string) ($latest_winner['year'] ?? ''))); ?>
                        <?php if (!empty($latest_winner['secondary_label'])) : ?>
                            <?php echo ' '; ?>
                            <?php echo $aat_render_hub_text_link((string) $latest_winner['secondary_label'], !empty($latest_winner['secondary_url']) ? (string) $latest_winner['secondary_url'] : '', 'aat-hub-inline-link'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <?php elseif (!empty($latest_winner['detail'])) : ?>
                            <?php echo esc_html(' ' . $aat_film_display($latest_winner['detail'])); ?>
                        <?php elseif (!empty($latest_winner['name']) && !empty($latest_winner['film']) && $latest_winner['name'] !== $latest_winner['film']) : ?>
                            <?php echo ' '; ?>
                            <?php echo $aat_render_hub_text_link((string) $latest_winner['name'], !empty($latest_winner['person_url']) ? (string) $latest_winner['person_url'] : '', 'aat-hub-inline-link'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="aat-ceremony-marquee-stack">
                    <?php if (!empty($category_spotlight)) : ?>
                        <?php // The poster is the artwork; no second copy of it washes the card behind it. ?>
                        <article class="aat-hub-spotlight-card">
                            <a class="aat-hub-spotlight-media-link" href="<?php echo esc_url($category_spotlight['film_url']); ?>">
                                <div class="aat-hub-spotlight-media">
                                    <?php if (!empty($category_spotlight['poster_html'])) : ?>
                                        <?php echo $category_spotlight['poster_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    <?php elseif (!empty($category_spotlight['poster_url'])) : ?>
                                        <img class="aat-hub-spotlight-poster" src="<?php echo esc_url($category_spotlight['poster_url']); ?>" alt="<?php echo esc_attr($category_spotlight['film_label']); ?> poster" loading="lazy" decoding="async" />
                                    <?php elseif (!empty($category_spotlight['fallback_html'])) : ?>
                                        <?php echo $category_spotlight['fallback_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    <?php else : ?>
                                        <div class="aat-filmography-poster-placeholder"><span><?php echo esc_html($category_spotlight['film_label']); ?></span></div>
                                    <?php endif; ?>
                                    <?php if (!empty($category_spotlight['badge_label'])) : ?><span class="aat-winner-badge aat-card-badge"><?php echo esc_html($category_spotlight['badge_label']); ?></span><?php endif; ?>
                                </div>
                            </a>
                            <div class="aat-hub-spotlight-body">
                                <?php // The section heading already names the film when it is the winner itself; the card then carries only the facts. ?>
                                <?php if ((string) $category_spotlight['film_label'] !== $latest_winner_headline) : ?>
                                    <h3 class="aat-hub-spotlight-title"><?php echo $aat_render_hub_text_link($category_spotlight['film_label'], $category_spotlight['film_url'], 'aat-hub-inline-link aat-hub-inline-link-title'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h3>
                                <?php endif; ?>
                                <?php if (!empty($category_spotlight['meta_lines'])) : ?>
                                    <p class="aat-hub-spotlight-meta"><?php echo $aat_join_meta($category_spotlight['meta_lines']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php elseif (!empty($latest_winner['film_url'])) : ?>
                        <a class="aat-hub-chip aat-hub-chip-rich" href="<?php echo esc_url($latest_winner['film_url']); ?>">
                            <strong><?php echo esc_html__('Open Film Page', 'academy-awards-table'); ?></strong>
                            <span><?php echo esc_html($latest_winner['film']); ?></span>
                        </a>
                    <?php endif; ?>
                    <div class="aat-hub-chip-stack">
                        <?php if (!empty($latest_winner['person_url']) && !empty($latest_winner['person_label'])) : ?>
                            <a class="aat-hub-chip aat-hub-chip-rich" href="<?php echo esc_url($latest_winner['person_url']); ?>">
                                <strong><?php echo esc_html__('Open Winner', 'academy-awards-table'); ?></strong>
                                <span><?php echo esc_html($latest_winner['person_label']); ?></span>
                            </a>
                        <?php endif; ?>
                        <a class="aat-hub-chip aat-hub-chip-rich" href="<?php echo esc_url($aat->get_ceremony_url(intval($latest_winner['ceremony'] ?? 0))); ?>">
                            <strong><?php echo esc_html__('Open Ceremony', 'academy-awards-table'); ?></strong>
                            <span><?php echo esc_html($aat->ordinal(intval($latest_winner['ceremony'] ?? 0))); ?> <?php echo esc_html__('Academy Awards', 'academy-awards-table'); ?></span>
                        </a>
                    </div>
                </div>
            </section>
        <?php endif; ?>
<?php $aat_sections['latest-winner'] = ob_get_clean(); ob_start(); ?>
        <?php // The hero summary band now carries every count once (latest winner, span, nominations, ceremonies); the separate stats bar is retired so the figures are not printed twice. ?>
<?php $aat_sections['stats-bar'] = ob_get_clean(); ob_start(); ?>

        <?php
        // Round 2 Step 2 (2026-05-24): Category History — decade-grouped ledger
        // with collapsible nominee trails. Replaces the legacy Year-by-Year Winners
        // section (which is still preserved below behind an if(false) guard for
        // easy revert).
        $category_decade_ledger = method_exists($aat, 'get_category_decade_ledger') ? $aat->get_category_decade_ledger($canonical) : array();
        $category_decade_buckets = !empty($category_decade_ledger['decades']) && is_array($category_decade_ledger['decades']) ? $category_decade_ledger['decades'] : array();
        $category_decade_counts  = !empty($category_decade_ledger['decade_counts']) && is_array($category_decade_ledger['decade_counts']) ? $category_decade_ledger['decade_counts'] : array();
        $category_decade_totals  = !empty($category_decade_ledger['totals']) && is_array($category_decade_ledger['totals']) ? $category_decade_ledger['totals'] : array('ceremonies' => 0, 'winners' => 0, 'nominees' => 0);
        $category_history_full_requested = isset($_GET['history']) && sanitize_key(wp_unslash($_GET['history'])) === 'full';
        $category_history_recent_trail_limit = (int) apply_filters('aat_category_history_recent_trail_limit', 12, $canonical);
        $category_history_recent_trail_limit = max(0, $category_history_recent_trail_limit);
        $category_history_rendered_ceremonies = 0;
        $category_history_full_url = add_query_arg('history', 'full', $aat->get_category_url($canonical));
        $category_history_fast_url = $aat->get_category_url($canonical);
        ?>
        <?php if (!empty($category_decade_buckets)) : ?>
            <div class="aat-hub-section aat-category-history<?php echo $is_premium_category_dossier ? ' aat-era-browser' : ''; ?>">
                <div class="aat-section-head">
                    <h2 class="aat-section-title"><?php echo esc_html($is_premium_category_dossier ? (string) $premium_category_profile['history_title'] : __('Category History', 'academy-awards-table')); ?></h2>
                    <p class="aat-section-description"><?php echo esc_html(sprintf(
                        /* translators: 1: ceremony count, 2: winner count, 3: nominee count */
                        $category_history_full_requested
                            ? __('%1$s ceremonies, %2$s winners, %3$s other nominees across the full Academy Awards span. Full nominee trails are loaded for every ceremony.', 'academy-awards-table')
                            : __('%1$s ceremonies, %2$s winners, %3$s other nominees across the full Academy Awards span. Fast view shows every winner and keeps older nominee trails linked through each ceremony ledger.', 'academy-awards-table'),
                        number_format_i18n((int) $category_decade_totals['ceremonies']),
                        number_format_i18n((int) $category_decade_totals['winners']),
                        number_format_i18n((int) $category_decade_totals['nominees'])
                    )); ?></p>
                    <div class="aat-category-history-mode">
                        <?php if ($category_history_full_requested) : ?>
                            <a class="aat-hub-card-action aat-winner-circle-action is-kind-ceremony" href="<?php echo esc_url($category_history_fast_url); ?>"><?php echo esc_html__('Use Fast View', 'academy-awards-table'); ?></a>
                        <?php else : ?>
                            <a class="aat-hub-card-action aat-winner-circle-action is-kind-ceremony" href="<?php echo esc_url($category_history_full_url); ?>"><?php echo esc_html__('Open Full Nominee Trails', 'academy-awards-table'); ?></a>
                        <?php endif; ?>
                    </div>
                </div>

                <nav class="aat-decade-nav" aria-label="<?php esc_attr_e('Jump to decade', 'academy-awards-table'); ?>">
                    <?php foreach ($category_decade_buckets as $decade_key => $decade_bucket) :
                        $decade_count = isset($category_decade_counts[$decade_key]) ? (int) $category_decade_counts[$decade_key] : count($decade_bucket['ceremonies']);
                    ?>
                        <a class="aat-decade-pill" href="#aat-decade-<?php echo esc_attr($decade_key); ?>">
                            <span class="aat-decade-pill-label"><?php echo esc_html($decade_bucket['label']); ?></span>
                            <span class="aat-decade-pill-count" aria-label="<?php echo esc_attr(sprintf(_n('%d ceremony', '%d ceremonies', $decade_count, 'academy-awards-table'), $decade_count)); ?>"><?php echo esc_html(number_format_i18n($decade_count)); ?></span>
                        </a>
                    <?php endforeach; ?>
                </nav>

                <div class="aat-decade-groups">
                    <?php foreach ($category_decade_buckets as $decade_key => $decade_bucket) : ?>
                        <section class="aat-decade-group<?php echo $is_premium_category_dossier ? ' aat-era-chapter' : ''; ?>" id="aat-decade-<?php echo esc_attr($decade_key); ?>">
                            <?php
                            $era_spotlight = array();
                            if ($is_premium_category_dossier && !empty($decade_bucket['ceremonies']) && is_array($decade_bucket['ceremonies'])) {
                                foreach ($decade_bucket['ceremonies'] as $era_ceremony_data) {
                                    $era_winners = !empty($era_ceremony_data['winner_rows']) && is_array($era_ceremony_data['winner_rows']) ? $era_ceremony_data['winner_rows'] : array();
                                    foreach ($era_winners as $era_winner) {
                                        $era_film_id = strtolower(trim((string) ($era_winner['film_id'] ?? '')));
                                        if ($era_film_id === '') {
                                            continue;
                                        }
                                        $era_visual = method_exists($aat, 'get_title_visual_package') ? $aat->get_title_visual_package($era_film_id, 'medium') : array();
                                        if (empty($era_visual['poster_html']) && empty($era_visual['poster_url']) && empty($era_visual['backdrop_url'])) {
                                            continue;
                                        }
                                        $era_spotlight = array(
                                            'entry'  => $aat_enrich_winner_entry_links($era_winner),
                                            'visual' => $era_visual,
                                        );
                                        break 2;
                                    }
                                }
                            }
                            ?>
                            <?php
                            // The decade opens with its heading and, when a winner of the decade has saved
                            // artwork, one small poster beside it: the chapter's picture, not a second card.
                            $era_film_label = '';
                            $era_film_url = '';
                            if (!empty($era_spotlight)) {
                                $era_entry = $era_spotlight['entry'];
                                $era_visual = $era_spotlight['visual'];
                                $era_film_label = !empty($era_entry['film']) ? (string) $era_entry['film'] : (string) ($era_entry['primary_label'] ?? '');
                                $era_film_url = !empty($era_entry['film_url']) ? (string) $era_entry['film_url'] : (!empty($era_entry['primary_url']) ? (string) $era_entry['primary_url'] : '');
                            }
                            ?>
                            <header class="aat-decade-head<?php echo !empty($era_spotlight) ? ' has-era-poster' : ''; ?>">
                                <?php if (!empty($era_spotlight)) : ?>
                                    <a class="aat-era-chapter-media" href="<?php echo esc_url($era_film_url !== '' ? $era_film_url : $db_url); ?>" aria-label="<?php echo esc_attr($era_film_label); ?>">
                                        <?php if (!empty($era_visual['poster_html'])) : ?>
                                            <?php echo $era_visual['poster_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                        <?php elseif (!empty($era_visual['poster_url'])) : ?>
                                            <img src="<?php echo esc_url($era_visual['poster_url']); ?>" alt="<?php echo esc_attr($era_film_label); ?> poster" loading="lazy" decoding="async" />
                                        <?php endif; ?>
                                    </a>
                                <?php endif; ?>
                                <h3 class="aat-decade-heading"><?php echo esc_html($decade_bucket['label']); ?></h3>
                            </header>

                            <div class="aat-decade-ceremonies">
                                <?php foreach ($decade_bucket['ceremonies'] as $ceremony_data) :
                                    $cer          = isset($ceremony_data['ceremony']) ? (int) $ceremony_data['ceremony'] : 0;
                                    $cer_year     = isset($ceremony_data['year']) ? trim((string) $ceremony_data['year']) : '';
                                    $cer_url      = ($cer > 0 && method_exists($aat, 'get_ceremony_url')) ? $aat->get_ceremony_url($cer) : '';
                                    $winner_rows  = !empty($ceremony_data['winner_rows']) && is_array($ceremony_data['winner_rows']) ? $ceremony_data['winner_rows'] : array();
                                    $nominee_rows = !empty($ceremony_data['nominee_rows']) && is_array($ceremony_data['nominee_rows']) ? $ceremony_data['nominee_rows'] : array();
                                    $category_history_rendered_ceremonies++;
                                    $render_full_nominee_trail = $category_history_full_requested || $category_history_rendered_ceremonies <= $category_history_recent_trail_limit;
                                    $render_compact_actions = !$category_history_full_requested && !$render_full_nominee_trail;
                                ?>
                                    <article class="aat-category-ceremony-row aat-ledger-card<?php echo $is_premium_category_dossier ? ' is-premium-ledger-card' : ''; ?>">
                                        <header class="aat-category-ceremony-meta">
                                            <?php // The year leads each row; it is the progression the reader scans. The ordinal links to the ceremony. ?>
                                            <?php if ($cer_year !== '') : ?>
                                                <span class="aat-category-ceremony-year"><?php echo esc_html($cer_year); ?></span>
                                            <?php endif; ?>
                                            <?php if ($cer_url !== '' && $cer > 0) : ?>
                                                <a class="aat-entity-link aat-timeline-link" href="<?php echo esc_url($cer_url); ?>"><?php echo esc_html($aat->ordinal($cer)); ?> <?php esc_html_e('Ceremony', 'academy-awards-table'); ?></a>
                                            <?php elseif ($cer > 0) : ?>
                                                <span class="aat-timeline-link"><?php echo esc_html($aat->ordinal($cer)); ?> <?php esc_html_e('Ceremony', 'academy-awards-table'); ?></span>
                                            <?php endif; ?>
                                        </header>

                                        <?php foreach ($winner_rows as $winner_row) :
                                            $winner_row = $aat_enrich_winner_entry_links($winner_row);
                                            $winner_row['category_label'] = $aat->format_category_display($canonical, intval($winner_row['ceremony'] ?? $cer ?? 0));
                                            $winner_actions  = $aat_build_winner_actions($winner_row, (string) ($winner_row['category_url'] ?? ''), $cer_url);
                                            $winner_people   = $aat_build_person_link_items($winner_row);
                                            if ($render_compact_actions && !empty($winner_actions)) {
                                                $winner_actions = array_values(array_filter($winner_actions, function($winner_action) {
                                                    $kind = isset($winner_action['kind']) ? sanitize_key((string) $winner_action['kind']) : '';
                                                    return $kind === 'ceremony';
                                                }));
                                            }
                                            $primary_label   = trim((string) ($winner_row['primary_label'] ?? ''));
                                            $secondary_label = trim((string) ($winner_row['secondary_label'] ?? ''));
                                            $winner_category = strtoupper(trim((string) ($winner_row['canonical_category'] ?? '')));
                                            $winner_credit_line = (strpos($winner_category, 'WRITING (') === 0 || strpos($winner_category, 'MUSIC (ORIGINAL ') === 0)
                                                ? trim((string) ($winner_row['name'] ?? ''))
                                                : '';
                                            $winner_credit_key = $aat_credit_people_key($winner_credit_line);
                                            $winner_credit_is_score = strpos($winner_category, 'MUSIC (ORIGINAL SCORE') === 0;
                                            $winner_credit_duplicates_people = $winner_credit_is_score && (
                                                $winner_credit_key === $aat_credit_people_key($primary_label)
                                                || $winner_credit_key === $aat_credit_people_key($secondary_label)
                                            );
                                            $show_winner_credit_line = $winner_credit_line !== ''
                                                && $winner_credit_line !== $primary_label
                                                && $winner_credit_line !== $secondary_label
                                                && !$winner_credit_duplicates_people;
                                        ?>
                                            <div class="aat-category-history-winner">
                                                <span class="aat-winner-badge"><?php esc_html_e('Winner', 'academy-awards-table'); ?></span>
                                                <?php if ($primary_label !== '') : ?>
                                                    <h4 class="aat-category-history-title"><?php echo $aat_render_hub_text_link($primary_label, !empty($winner_row['primary_url']) ? (string) $winner_row['primary_url'] : '', 'aat-hub-inline-link aat-hub-inline-link-title'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></h4>
                                                <?php endif; ?>
                                                <?php if ($secondary_label !== '') : ?>
                                                    <p class="aat-category-history-meta"><?php echo $aat_render_hub_text_link($secondary_label, !empty($winner_row['secondary_url']) ? (string) $winner_row['secondary_url'] : '', 'aat-hub-inline-link'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
                                                <?php endif; ?>
                                                <?php if ($show_winner_credit_line) : ?>
                                                    <p class="aat-category-history-detail"><?php echo esc_html($winner_credit_line); ?></p>
                                                <?php endif; ?>
                                                <?php if (!empty($winner_row['detail']) && $winner_row['detail'] !== $primary_label && $winner_row['detail'] !== $secondary_label) : ?>
                                                    <p class="aat-category-history-detail"><?php echo esc_html($aat_film_display($winner_row['detail'])); ?></p>
                                                <?php endif; ?>
                                                <?php if (!empty($winner_people) && (count($winner_people) > 1 || empty($winner_row['primary_url']))) : ?>
                                                    <div class="aat-category-person-strip" aria-label="<?php echo esc_attr__('Linked craft credits', 'academy-awards-table'); ?>">
                                                        <span class="aat-category-person-strip-label"><?php echo esc_html__('Craft Credits', 'academy-awards-table'); ?></span>
                                                        <?php foreach ($winner_people as $person_item) : ?>
                                                            <a class="aat-category-person-chip" href="<?php echo esc_url($person_item['url']); ?>"><?php echo esc_html($person_item['label']); ?></a>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if (!empty($winner_actions)) : ?>
                                                    <div class="aat-category-history-actions">
                                                        <?php foreach ($winner_actions as $winner_action) : ?>
                                                            <a class="aat-hub-card-action aat-winner-circle-action<?php echo !empty($winner_action['kind']) ? ' is-kind-' . sanitize_html_class((string) $winner_action['kind']) : ''; ?>" href="<?php echo esc_url($winner_action['url']); ?>"><?php echo esc_html($winner_action['label']); ?></a>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>

                                        <?php if (!empty($nominee_rows) && $render_full_nominee_trail) : ?>
                                            <details class="aat-nominee-trail">
                                                <summary class="aat-nominee-trail-summary">
                                                    <?php echo esc_html(sprintf(
                                                        /* translators: %d: number of other nominees in this ceremony for this category */
                                                        _n('%d other nominee', '%d other nominees', count($nominee_rows), 'academy-awards-table'),
                                                        count($nominee_rows)
                                                    )); ?>
                                                </summary>
                                                <ul class="aat-nominee-trail-list">
                                                    <?php foreach ($nominee_rows as $nominee_row) :
                                                        $nominee_row = $aat_enrich_winner_entry_links($nominee_row);
                                                        $nominee_row['category_label'] = $aat->format_category_display($canonical, intval($nominee_row['ceremony'] ?? $cer ?? 0));
                                                        $nominee_primary   = trim((string) ($nominee_row['primary_label'] ?? ''));
                                                        $nominee_secondary = trim((string) ($nominee_row['secondary_label'] ?? ''));
                                                        $nominee_people    = $aat_build_person_link_items($nominee_row);
                                                        $nominee_deep_links = array();
                                                        if (!empty($nominee_row['film_history_url'])) {
                                                            $nominee_deep_links[] = array(
                                                                'label' => __('Film History', 'academy-awards-table'),
                                                                'url' => (string) $nominee_row['film_history_url'],
                                                                'kind' => 'film-history',
                                                            );
                                                        }
                                                        if (!empty($nominee_row['person_history_url'])) {
                                                            $nominee_history_url = (string) $nominee_row['person_history_url'];
                                                            $nominee_history_meta = $aat_person_history_action_meta($nominee_history_url, $nominee_row['canonical_category'] ?? '');
                                                            $nominee_deep_links[] = array(
                                                                'label' => $nominee_history_meta['label'],
                                                                'url' => $nominee_history_url,
                                                                'kind' => $nominee_history_meta['kind'],
                                                            );
                                                        }
                                                    ?>
                                                        <li class="aat-nominee-trail-item">
                                                            <span class="aat-nominee-badge"><?php esc_html_e('Nominee', 'academy-awards-table'); ?></span>
                                                            <?php if ($nominee_primary !== '') : ?>
                                                                <span class="aat-nominee-primary"><?php echo $aat_render_hub_text_link($nominee_primary, !empty($nominee_row['primary_url']) ? (string) $nominee_row['primary_url'] : '', 'aat-hub-inline-link'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                                                            <?php endif; ?>
                                                            <?php if ($nominee_secondary !== '') : ?>
                                                                <span class="aat-nominee-secondary"><?php echo $aat_render_hub_text_link($nominee_secondary, !empty($nominee_row['secondary_url']) ? (string) $nominee_row['secondary_url'] : '', 'aat-hub-inline-link'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                                                            <?php endif; ?>
                                                            <?php if (!empty($nominee_row['detail'])) : ?>
                                                                <span class="aat-nominee-detail"><?php echo esc_html($aat_film_display($nominee_row['detail'])); ?></span>
                                                            <?php endif; ?>
                                                            <?php if (!empty($nominee_people) && (count($nominee_people) > 1 || empty($nominee_row['primary_url']))) : ?>
                                                                <span class="aat-category-person-strip is-compact" aria-label="<?php echo esc_attr__('Linked nominee credits', 'academy-awards-table'); ?>">
                                                                    <span class="aat-category-person-strip-label"><?php echo esc_html__('Credits', 'academy-awards-table'); ?></span>
                                                                    <?php foreach ($nominee_people as $person_item) : ?>
                                                                        <a class="aat-category-person-chip" href="<?php echo esc_url($person_item['url']); ?>"><?php echo esc_html($person_item['label']); ?></a>
                                                                    <?php endforeach; ?>
                                                                </span>
                                                            <?php endif; ?>
                                                            <?php if (!empty($nominee_deep_links)) : ?>
                                                                <span class="aat-nominee-trail-actions">
                                                                    <?php foreach ($nominee_deep_links as $nominee_deep_link) : ?>
                                                                        <a class="aat-winner-circle-action<?php echo !empty($nominee_deep_link['kind']) ? ' is-kind-' . sanitize_html_class((string) $nominee_deep_link['kind']) : ''; ?>" href="<?php echo esc_url($nominee_deep_link['url']); ?>"><?php echo esc_html($nominee_deep_link['label']); ?></a>
                                                                    <?php endforeach; ?>
                                                                </span>
                                                            <?php endif; ?>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </details>
                                        <?php elseif (!empty($nominee_rows)) : ?>
                                            <div class="aat-nominee-trail aat-nominee-trail-compact">
                                                <span class="aat-nominee-trail-count">
                                                    <?php echo esc_html(sprintf(
                                                        /* translators: %d: number of other nominees in this ceremony for this category */
                                                        _n('%d other nominee', '%d other nominees', count($nominee_rows), 'academy-awards-table'),
                                                        count($nominee_rows)
                                                    )); ?>
                                                </span>
                                                <?php if ($cer_url !== '' && !$render_compact_actions) : ?>
                                                    <a class="aat-winner-circle-action is-kind-ceremony" href="<?php echo esc_url($cer_url); ?>"><?php echo esc_html__('Open Ceremony Ledger', 'academy-awards-table'); ?></a>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
<?php $aat_sections['history'] = ob_get_clean(); ob_start(); ?>

        <?php $category_highlight_limit = $category_history_full_requested ? 18 : (int) apply_filters('aat_category_title_highlights_fast_limit', 9, $canonical); ?>
        <?php $category_highlight_limit = max(6, min(18, $category_highlight_limit)); ?>
        <?php $category_titles = method_exists($aat, 'get_category_title_highlights') ? $aat->get_category_title_highlights($canonical, $category_highlight_limit) : array(); ?>
        <?php $category_review_cards = !empty($category_titles) ? $aat_build_hub_review_cards($category_titles, $aat_get_related_review_limit()) : array(); ?>
        <?php if (!empty($category_titles)) : ?>
            <div class="aat-hub-section aat-category-gallery-section">
                <h2><?php echo esc_html__('Category Highlights', 'academy-awards-table'); ?></h2>
                <div class="aat-filmography-grid aat-hub-film-grid">
                    <?php foreach ($category_titles as $entry) :
                        $fid = strtolower(trim((string) ($entry['film_id'] ?? '')));
                        if (!$fid) { continue; }
                        $visual = method_exists($aat, 'get_title_visual_package') ? $aat->get_title_visual_package($fid, 'medium') : array();
                        $film_label = !empty($entry['film']) ? (string) $entry['film'] : $aat->lookup_title_label($fid);
                        $film_url = $aat->get_entity_url($fid);
                        $entry = $aat_enrich_winner_entry_links($entry);
                    ?>
                        <article class="aat-filmography-card aat-hub-film-card<?php echo !empty($entry['winner']) ? ' is-winner' : ''; ?>">
                            <a class="aat-filmography-link" href="<?php echo esc_url($film_url ? $film_url : $db_url); ?>">
                                <div class="aat-filmography-poster-wrap">
                                    <?php if (!empty($visual['poster_html'])) : ?>
                                        <?php echo $visual['poster_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    <?php elseif (!empty($visual['poster_url'])) : ?>
                                        <img class="aat-filmography-poster" src="<?php echo esc_url($visual['poster_url']); ?>" alt="<?php echo esc_attr($film_label); ?> poster" loading="lazy" decoding="async" />
                                    <?php elseif (!empty($visual['card_fallback_html'])) : ?>
                                        <?php echo $visual['card_fallback_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    <?php else : ?>
                                        <div class="aat-filmography-poster-placeholder"><span><?php echo esc_html($film_label); ?></span></div>
                                    <?php endif; ?>
                                    <?php if (!empty($entry['winner'])) : ?><span class="aat-winner-badge aat-card-badge"><?php echo esc_html__('Winner', 'academy-awards-table'); ?></span><?php endif; ?>
                                </div>
                                <h3 class="aat-filmography-title"><?php echo esc_html($film_label); ?></h3>
                                <p class="aat-filmography-meta">
                                    <?php if (!empty($entry['year'])) : ?><span><?php echo esc_html($entry['year']); ?></span><?php endif; ?>
                                    <?php if (!empty($entry['person_label'])) : ?>
                                        <?php if (!empty($entry['year'])) : ?><span class="aat-meta-sep" aria-hidden="true">&middot;</span><?php endif; ?>
                                        <?php echo $aat_render_hub_text_link((string) $entry['person_label'], !empty($entry['person_url']) ? (string) $entry['person_url'] : '', 'aat-hub-inline-link'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    <?php endif; ?>
                                </p>
                            </a>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
<?php $aat_sections['gallery'] = ob_get_clean(); ob_start(); ?>

        <?php if (!empty($category_review_cards)) : ?>
            <div class="aat-hub-section aat-related-reviews-section <?php echo esc_attr($aat_related_review_treatment_class); ?>">
                <h2><?php echo esc_html__('On Lunara', 'academy-awards-table'); ?></h2>
                <p class="aat-hub-copy"><?php echo esc_html__('Lunara Film reviews of films from this category.', 'academy-awards-table'); ?></p>
                <div class="aat-related-reviews-grid <?php echo esc_attr($aat_related_review_treatment_class); ?>">
                    <?php foreach ($category_review_cards as $card) : ?>
                        <?php
                            $aat_related_review_has_media = !empty($card['review_thumb']) || !empty($card['fallback_html']);
                            $aat_related_review_classes = array('aat-related-review-card', $aat_related_review_has_media ? 'has-media' : 'has-no-media');
                        ?>
                        <article class="<?php echo esc_attr(implode(' ', $aat_related_review_classes)); ?>">
                            <?php if ($aat_related_review_has_media) : ?>
                            <a class="aat-related-review-media" href="<?php echo esc_url($card['review_url']); ?>">
                                <?php if (!empty($card['review_thumb'])) : ?>
                                    <?php echo $card['review_thumb']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <?php elseif (!empty($card['fallback_html'])) : ?>
                                    <?php echo $card['fallback_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <?php endif; ?>
                            </a>
                            <?php endif; ?>
                            <div class="aat-related-review-body">
                                <h3 class="aat-related-review-title"><a href="<?php echo esc_url($card['review_url']); ?>"><?php echo esc_html($card['review_title']); ?></a></h3>
                                <p class="aat-related-review-meta">
                                    <?php if (!empty($card['film_url'])) : ?>
                                        <a href="<?php echo esc_url($card['film_url']); ?>"><?php echo esc_html($card['film_label']); ?></a>
                                    <?php else : ?>
                                        <?php echo esc_html($card['film_label']); ?>
                                    <?php endif; ?>
                                    <?php if (!empty($card['film_year'])) : ?>
                                        <span class="aat-meta-sep" aria-hidden="true">&middot;</span><span><?php echo esc_html($card['film_year']); ?></span>
                                    <?php endif; ?>
                                </p>
                                <div class="aat-related-review-actions">
                                    <a class="aat-btn aat-btn-secondary" href="<?php echo esc_url($card['review_url']); ?>"><?php echo esc_html__('Read the review', 'academy-awards-table'); ?></a>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

<?php $aat_sections['related-reviews'] = ob_get_clean(); ob_start(); ?>
        <?php
            $table_view_url = add_query_arg('view', 'table', $aat->get_category_url($canonical));
            $poster_view_url = $aat->get_category_url($canonical);
        ?>
        <div class="aat-hub-section aat-explorer-callout">
            <div class="aat-explorer-shell">
                <div class="aat-explorer-copy">
                    <h2><?php echo esc_html__('Sortable ledger', 'academy-awards-table'); ?></h2>
                    <p class="aat-hub-copy"><?php echo esc_html__('Every nomination in this category as a sortable table, for research that needs the raw record beside the posters and winners above.', 'academy-awards-table'); ?></p>
                </div>
                <div class="aat-hub-actions aat-view-toggle">
                    <a class="aat-btn aat-btn-secondary<?php echo !$table_view_requested ? ' is-active' : ''; ?>" href="<?php echo esc_url($poster_view_url); ?>"><?php echo esc_html__('Poster View', 'academy-awards-table'); ?></a>
                    <a class="aat-btn aat-btn-primary<?php echo $table_view_requested ? ' is-active' : ''; ?>" href="<?php echo esc_url($table_view_url); ?>"><?php echo esc_html__('Data Explorer', 'academy-awards-table'); ?></a>
                </div>
            </div>
        </div>
<?php $aat_sections['explorer-callout'] = ob_get_clean(); ob_start(); ?>

        <?php if ($table_view_requested) : ?>
            <div class="aat-hub-section aat-table-shell">
                <?php
                    echo $aat->render_shortcode(array(
                        'category' => $canonical,
                        'layout' => 'embedded',
                    ));
                ?>
            </div>
        <?php endif; ?>
<?php $aat_sections['table-shell'] = ob_get_clean(); echo implode('', apply_filters('aat_hub_route_sections', $aat_sections, $aat_route_context)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sections are template-rendered markup captured above. ?>
        </div>

    <?php
        // Unknown hub
        else :
            $mark_404();
    ?>
        <div class="aat-hub-header">
            <h1 class="aat-hub-title"><?php echo esc_html__('Not Found', 'academy-awards-table'); ?></h1>
            <p class="aat-hub-subtitle"><?php echo esc_html__('This page does not exist in the Lunara Oscar Ledger.', 'academy-awards-table'); ?></p>
            <div class="aat-hub-actions">
                <a class="aat-btn aat-btn-primary" href="<?php echo esc_url($db_url); ?>"><?php echo esc_html__('Open Full Ledger', 'academy-awards-table'); ?></a>
            </div>
        </div>
    <?php endif; ?>

</div>

<?php
get_footer();
?>

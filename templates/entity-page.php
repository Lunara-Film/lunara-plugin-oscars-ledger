<?php
/**
 * Academy Awards Table - Entity Page Template
 * Richer Film / Person / Company pages for Lunara Film
 */

if (!defined('ABSPATH')) {
    exit;
}

$aat = Academy_Awards_Table::get_instance();

$entity = sanitize_text_field(get_query_var('aat_entity'));
$id = sanitize_text_field(get_query_var('aat_entity_id'));

$rows = $aat->get_entity_rows($entity, $id);
$label = sanitize_text_field($aat->get_entity_display_name($entity, $id));
$label = trim((string) $label);

if (empty($rows)) {
    global $wp_query;
    if (is_object($wp_query)) {
        $wp_query->set_404();
    }
    status_header(404);
    nocache_headers();
}

$ordinal = function($n) {
    $n = intval($n);
    if ($n <= 0) return '';
    $s = array('th', 'st', 'nd', 'rd');
    $v = $n % 100;
    return $n . ($s[($v - 20) % 10] ?? $s[$v] ?? $s[0]);
};

$format_category = function($cat, $ceremony = 0) use ($aat) {
    $cat = trim((string) $cat);
    if ($cat === '') return '';
    $map = array(
        'ACTOR IN A LEADING ROLE' => 'Best Actor',
        'ACTRESS IN A LEADING ROLE' => 'Best Actress',
        'ACTOR IN A SUPPORTING ROLE' => 'Best Supporting Actor',
        'ACTRESS IN A SUPPORTING ROLE' => 'Best Supporting Actress',
        'BEST PICTURE' => 'Best Picture',
        'DIRECTING' => 'Best Director',
        'WRITING (ORIGINAL SCREENPLAY)' => 'Original Screenplay',
        'WRITING (ADAPTED SCREENPLAY)' => 'Adapted Screenplay',
    );
    $display = $aat->format_category_display($cat, $ceremony);
    return $map[$cat] ?? ($display !== $cat ? $display : ucwords(strtolower($cat)));
};

$build_entity_url = function($id) use ($aat) {
    $id = trim((string) $id);
    return $id !== '' ? esc_url($aat->build_entity_url_from_id($id)) : '';
};

$build_imdb_url = function($id) use ($aat) {
    $id = trim((string) $id);
    return $id !== '' ? $aat->build_imdb_url($id) : '';
};

$build_ceremony_url = function($ceremony) use ($aat) {
    $ceremony = intval($ceremony);
    if ($ceremony <= 0) {
        return '';
    }

    return $aat->get_ceremony_url($ceremony);
};

$pipe_separator_html = '<span class="aat-sep"> &middot; </span>';

$render_linked_pipe = function($value_list, $id_list) use ($aat, $build_entity_url, $pipe_separator_html) {
    $values = array_values(array_filter(array_map('trim', explode('|', (string) $value_list)), 'strlen'));
    $ids = array_values(array_filter(array_map('trim', explode('|', (string) $id_list)), 'strlen'));

    if (empty($values)) {
        return '<span class="aat-no-film">&mdash;</span>';
    }

    if (!empty($ids) && count($ids) === count($values)) {
        $out = array();
        foreach ($values as $i => $value) {
            $resolved_id = (string) ($ids[$i] ?? '');
            if (method_exists($aat, 'canonicalize_name_entity_id_for_label')) {
                $resolved_id = $aat->canonicalize_name_entity_id_for_label($resolved_id, $value);
            }
            $url = $build_entity_url($resolved_id);
            if ($url) {
                $out[] = '<a class="aat-entity-link" href="' . esc_url($url) . '">' . esc_html($value) . '</a>';
            } else {
                $out[] = '<span class="aat-entity-text">' . esc_html($value) . '</span>';
            }
        }

        return implode($pipe_separator_html, $out);
    }

    return implode($pipe_separator_html, array_map(function($value) {
        return '<span class="aat-entity-text">' . esc_html($value) . '</span>';
    }, $values));
};

$get_primary_pipe_value = function($value_list) {
    $values = array_values(array_filter(array_map('trim', explode('|', (string) $value_list)), 'strlen'));

    return !empty($values[0]) ? (string) $values[0] : '';
};

$title_visual_cache = array();
$title_label_cache = array();
$get_title_label = function($title_id) use ($aat, &$title_label_cache) {
    $title_id = strtolower(trim((string) $title_id));
    if ($title_id === '' || !preg_match('/^tt\d+$/', $title_id)) {
        return '';
    }

    if (!array_key_exists($title_id, $title_label_cache)) {
        $label = method_exists($aat, 'lookup_title_label') ? $aat->lookup_title_label($title_id) : '';
        if ($label === '' && method_exists($aat, 'get_entity_display_name')) {
            $label = (string) $aat->get_entity_display_name('title', $title_id);
        }
        $title_label_cache[$title_id] = trim((string) $label);
    }

    return $title_label_cache[$title_id];
};

$normalize_comparable_name = function($value) {
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

$resolve_title_nominee_display = function($row) use ($normalize_comparable_name, $aat) {
    $explicit_name = trim((string) ($row['name'] ?? ''));
    $nominee_value = trim((string) ($row['nominees'] ?? ''));
    $nominee_ids = trim((string) ($row['nominee_ids'] ?? ''));
    $nominee_parts = array_values(array_filter(array_map('trim', explode('|', $nominee_value)), 'strlen'));
    $id_parts = array_values(array_filter(array_map('trim', explode('|', $nominee_ids)), 'strlen'));
    $single_nominee_id = count($id_parts) === 1 ? strtolower((string) $id_parts[0]) : '';

    if ($explicit_name === '') {
        return array(
            'label' => $nominee_value,
            'ids' => $nominee_ids,
            'is_plural' => strpos($nominee_value, '|') !== false,
        );
    }

    $matched_ids = $nominee_ids;

    if (!empty($nominee_parts) && count($nominee_parts) === count($id_parts) && count($id_parts) > 1) {
        $target = $normalize_comparable_name($explicit_name);
        $matches = array();

        foreach ($nominee_parts as $index => $nominee_part) {
            if ($normalize_comparable_name($nominee_part) === $target && isset($id_parts[$index])) {
                $matches[] = $id_parts[$index];
            }
        }

        if (count($matches) === 1) {
            $matched_ids = $matches[0];
        } else {
            return array(
                'label' => implode('|', $nominee_parts),
                'ids' => implode('|', $id_parts),
                'is_plural' => true,
            );
        }
    }

    if ($explicit_name !== '' && $single_nominee_id !== '' && preg_match('/^(nm|co)\d+$/', $single_nominee_id)) {
        $entity_type = strpos($single_nominee_id, 'co') === 0 ? 'company' : 'name';
        $preferred_label = trim((string) $aat->get_entity_display_name($entity_type, $single_nominee_id));
        if ($preferred_label !== '') {
            $explicit_name = $preferred_label;
        }
    }

    return array(
        'label' => $explicit_name,
        'ids' => $matched_ids,
        'is_plural' => false,
    );
};

$total_nominations = is_array($rows) ? count($rows) : 0;
$total_wins = 0;
$categories_set = array();
$ceremonies_set = array();
$ceremony_year_map = array();
$distinct_films = array();
$timeline = array();
$category_rollups = array();
$category_latest_ceremonies = array();
$ceremony_rollups = array();
$latest_year = '';
$latest_ceremony = 0;

if (is_array($rows)) {
    foreach ($rows as $r) {
        $cer = intval($r['ceremony'] ?? 0);
        $winner = (!empty($r['winner']) && (int) $r['winner'] === 1);
        if ($winner) $total_wins++;

        $cat = (string) ($r['canonical_category'] ?? $r['category'] ?? '');
        if ($cat !== '') {
            $categories_set[$cat] = true;
            if (!isset($category_rollups[$cat])) {
                $category_rollups[$cat] = array(
                    'category' => $cat,
                    'label' => $format_category($cat, $cer),
                    'url' => $aat->get_category_url($cat),
                    'nominations' => 0,
                    'wins' => 0,
                );
                $category_latest_ceremonies[$cat] = $cer;
            }
            // A category's summary follows its own latest award, not an unrelated modern credit.
            if ($cer > $category_latest_ceremonies[$cat]) {
                $category_latest_ceremonies[$cat] = $cer;
                $category_rollups[$cat]['label'] = $format_category($cat, $cer);
            }
            $category_rollups[$cat]['nominations']++;
            if ($winner) {
                $category_rollups[$cat]['wins']++;
            }
        }

        $year = (string) ($r['year'] ?? '');
        if ($cer > 0) {
            $ceremonies_set[$cer] = true;
            $ceremony_year_map[$cer] = $year;
            if (!isset($ceremony_rollups[$cer])) {
                $ceremony_rollups[$cer] = array(
                    'ceremony' => $cer,
                    'year' => $year,
                    'url' => $build_ceremony_url($cer),
                    'nominations' => 0,
                    'wins' => 0,
                );
            }
            $ceremony_rollups[$cer]['nominations']++;
            if ($winner) {
                $ceremony_rollups[$cer]['wins']++;
            }
            if ($cer > $latest_ceremony) {
                $latest_ceremony = $cer;
                $latest_year = $year;
            }

            if (!isset($timeline[$cer])) {
                $timeline[$cer] = array(
                    'year' => $year,
                    'rows' => array(),
                );
            }
            $timeline[$cer]['rows'][] = $r;
        }

        if ($entity !== 'title') {
            $film_ids = array_filter(array_map('trim', explode('|', (string) ($r['film_id'] ?? ''))), 'strlen');
            foreach ($film_ids as $fid) {
                if (!isset($distinct_films[$fid])) {
                    $distinct_films[$fid] = array(
                        'nominations' => 0,
                        'wins' => 0,
                        'categories' => array(),
                        'ceremonies' => array(),
                        'latest_ceremony' => 0,
                        'latest_year' => '',
                    );
                }

                $distinct_films[$fid]['nominations']++;
                if ($winner) {
                    $distinct_films[$fid]['wins']++;
                }
                if ($cat !== '') {
                    $distinct_films[$fid]['categories'][$cat] = true;
                }
                if ($cer > 0) {
                    $distinct_films[$fid]['ceremonies'][$cer] = true;
                    if ($cer > intval($distinct_films[$fid]['latest_ceremony'] ?? 0)) {
                        $distinct_films[$fid]['latest_ceremony'] = $cer;
                        $distinct_films[$fid]['latest_year'] = $year;
                    }
                }
            }
        }
    }
}

krsort($timeline, SORT_NUMERIC);

$category_rollups = array_values($category_rollups);
usort($category_rollups, function($left, $right) {
    $left_wins = intval($left['wins'] ?? 0);
    $right_wins = intval($right['wins'] ?? 0);
    if ($left_wins !== $right_wins) {
        return $right_wins <=> $left_wins;
    }

    $left_nominations = intval($left['nominations'] ?? 0);
    $right_nominations = intval($right['nominations'] ?? 0);
    if ($left_nominations !== $right_nominations) {
        return $right_nominations <=> $left_nominations;
    }

    return strcmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
});

$ceremony_rollups = array_values($ceremony_rollups);
usort($ceremony_rollups, function($left, $right) {
    return intval($right['ceremony'] ?? 0) <=> intval($left['ceremony'] ?? 0);
});

if (!empty($distinct_films)) {
    uasort($distinct_films, function($left, $right) {
        $left_wins = intval($left['wins'] ?? 0);
        $right_wins = intval($right['wins'] ?? 0);
        if ($left_wins !== $right_wins) {
            return $right_wins <=> $left_wins;
        }

        $left_nominations = intval($left['nominations'] ?? 0);
        $right_nominations = intval($right['nominations'] ?? 0);
        if ($left_nominations !== $right_nominations) {
            return $right_nominations <=> $left_nominations;
        }

        return intval($right['latest_ceremony'] ?? 0) <=> intval($left['latest_ceremony'] ?? 0);
    });
}

$total_categories = count($categories_set);
$total_ceremonies = count($ceremonies_set);
$span = '';
if (!empty($ceremony_year_map)) {
    $years = array_values(array_filter($ceremony_year_map, 'strlen'));
    sort($years);
    if (!empty($years)) {
        $first_year = reset($years);
        $last_year = end($years);
        $span = $first_year === $last_year ? $first_year : ($first_year . '-' . $last_year);
    }
}

if ($span !== '') {
    $span = str_replace(array('-', '&#8211;'), '&ndash;', $span);
}

$years = array_values(array_filter($ceremony_year_map, 'strlen'));
if (!empty($years)) {
    sort($years);
    $first_year = reset($years);
    $last_year = end($years);
    $span = $first_year === $last_year ? $first_year : ($first_year . '&ndash;' . $last_year);
}

$latest_result = array();
if ($latest_ceremony > 0 && !empty($timeline[$latest_ceremony]['rows'])) {
    $latest_rows = $timeline[$latest_ceremony]['rows'];
    $latest_wins = 0;
    $latest_categories = array();
    $latest_title_id = '';
    foreach ($latest_rows as $latest_row) {
        if (!empty($latest_row['winner']) && (int) $latest_row['winner'] === 1) {
            $latest_wins++;
        }
        $latest_cat = (string) ($latest_row['canonical_category'] ?? $latest_row['category'] ?? '');
        if ($latest_cat !== '') {
            $latest_categories[$latest_cat] = array(
                'label' => $format_category($latest_cat, $latest_ceremony),
                'url' => $aat->get_category_url($latest_cat),
            );
        }

        if ($latest_title_id === '') {
            $latest_title_id = $get_primary_pipe_value($latest_row['film_id'] ?? '');
        }
    }

    $latest_result = array(
        'ceremony' => $latest_ceremony,
        'year' => (string) $latest_year,
        'nominations' => count($latest_rows),
        'wins' => $latest_wins,
        'categories' => array_values($latest_categories),
        'title_id' => $latest_title_id,
    );
}

$imdb_url = $build_imdb_url($id);
$search_url = home_url('/?s=' . rawurlencode($label ? $label : $id));
$database_url = home_url('/oscars/');
$type_label = $entity === 'title' ? 'Film' : ($entity === 'company' ? 'Company' : 'Person');
$profile_file_label = $entity === 'title' ? __('Title Profile File', 'academy-awards-table') : ($entity === 'company' ? __('Company Profile File', 'academy-awards-table') : __('Person Profile File', 'academy-awards-table'));
$profile_file_class = $entity === 'title' ? 'is-title-file' : ($entity === 'company' ? 'is-company-file' : 'is-person-file');
$profile_subject_label = $label ? $label : strtoupper($id);
$summary = '';
$visual = array();
$entity_anchor_title = '';
$entity_anchor_stats = array();
$company_film_id = '';
if ($entity === 'title' && method_exists($aat, 'get_title_visual_package')) {
    $visual = $aat->get_title_visual_package($id, 'medium_large');
} elseif ($entity === 'name' && method_exists($aat, 'get_person_visual_package')) {
    $visual = $aat->get_person_visual_package($id, 'medium_large');
} elseif ($entity === 'company' && !empty($distinct_films)) {
    // A company has no artwork of its own: the identity plate carries the name, and
    // the leading film of its record (most awarded, then most nominated) is named below it.
    $company_film_ids = array_keys($distinct_films);
    $company_film_id = trim((string) $company_film_ids[0]);
    if ($company_film_id !== '') {
        $entity_anchor_title = $get_title_label($company_film_id);
        if ($entity_anchor_title === '') {
            $entity_anchor_title = strtoupper($company_film_id);
        }
        $entity_anchor_stats = is_array($distinct_films[$company_film_id] ?? null) ? $distinct_films[$company_film_id] : array();
        if (method_exists($aat, 'get_title_visual_package')) {
            $visual = $aat->get_title_visual_package($company_film_id, 'medium');
        }
    }
}
$tmdb = !empty($visual['tmdb']) ? $visual['tmdb'] : array();
$hero_classes = array('aat-entity-hero');
$aat_person_visual_state = $entity === 'name' ? sanitize_html_class((string) ($visual['visual_state'] ?? 'no-portrait')) : '';
$aat_person_visual_source = $entity === 'name' ? sanitize_html_class((string) ($visual['visual_source'] ?? 'none')) : '';
if ($entity === 'name') {
    $aat_person_visual_state = $aat_person_visual_state !== '' ? $aat_person_visual_state : 'no-portrait';
    $aat_person_visual_source = $aat_person_visual_source !== '' ? $aat_person_visual_source : 'none';
    $hero_classes[] = 'has-person-visual-state-' . $aat_person_visual_state;
    $hero_classes[] = 'has-person-visual-source-' . $aat_person_visual_source;
    if ($aat_person_visual_state === 'no-portrait') {
        $hero_classes[] = 'is-person-visual-fallback';
    }
}
// Artwork appears once, as the identity image; no surface repeats it as a background wash.
$aat_company_plate_html = '';
if ($entity === 'company') {
    $aat_company_plate_label = $label ? $label : strtoupper($id);
    $aat_company_plate_mark = class_exists('AAT_Ledger_Media') ? AAT_Ledger_Media::initials($aat_company_plate_label) : strtoupper(substr($aat_company_plate_label, 0, 1));
    $aat_company_plate_html = '<div class="aat-entity-poster-fallback is-company-plate"><div class="aat-fallback-inner"><div class="aat-fallback-kicker">' . esc_html__('Company', 'academy-awards-table') . '</div><div class="aat-fallback-title">' . esc_html($aat_company_plate_mark) . '</div><div class="aat-fallback-meta">' . esc_html($aat_company_plate_label) . '</div></div></div>';
}
if ($entity === 'company' && $aat_company_plate_html === '') {
    $hero_classes[] = 'is-meta-only';
} elseif ($entity === 'title' && empty($visual['poster_html']) && empty($visual['poster_url']) && empty($visual['fallback_html'])) {
    $hero_classes[] = 'is-no-poster';
} elseif ($entity === 'name' && empty($visual['portrait_url']) && empty($visual['fallback_html'])) {
    $hero_classes[] = 'is-no-poster';
}
$aat_entity_subtitle_span = '';
if ($entity === 'title' && !empty($visual['release_year'])) {
    $aat_entity_subtitle_span = (string) $visual['release_year'];
} elseif ($span !== '') {
    $aat_entity_subtitle_span = $span;
}
if ($total_nominations > 0 && $latest_ceremony > 0) {
    // The command band carries the counts; this line says where the record stands.
    $aat_latest_label = $ordinal($latest_ceremony) . ' Academy Awards';
    $aat_latest_ceremony_url = $build_ceremony_url($latest_ceremony);
    $aat_latest_link = $aat_latest_ceremony_url
        ? '<a class="aat-entity-link aat-timeline-link" href="' . esc_url($aat_latest_ceremony_url) . '">' . esc_html($aat_latest_label) . '</a>'
        : esc_html($aat_latest_label);
    if ((string) $latest_year !== '') {
        $aat_latest_link .= ' (' . esc_html($latest_year) . ')';
    }
    if ($total_ceremonies > 1) {
        $summary = sprintf(
            /* translators: 1: number of ceremonies, 2: linked ceremony label. */
            __('Nominated at %1$s ceremonies, most recently the %2$s.', 'academy-awards-table'),
            number_format_i18n($total_ceremonies),
            $aat_latest_link
        );
    } else {
        /* translators: %s: linked ceremony label. */
        $summary = sprintf(__('Nominated at the %s.', 'academy-awards-table'), $aat_latest_link);
    }
}

$aat_review_ids = array();
if ($entity === 'title') {
    $aat_review_ids = $aat->get_review_ids_for_title_id($id, 3);
}

$aat_editorial_refs = method_exists($aat, 'get_entity_editorial_references')
    ? $aat->get_entity_editorial_references($entity, $id, $label, 6)
    : array();

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

$aat_related_reviews = array();
if ($entity !== 'title' && !empty($distinct_films)) {
    foreach (array_keys($distinct_films) as $related_film_id) {
        $related_film_id = trim((string) $related_film_id);
        if ($related_film_id === '') {
            continue;
        }

        $related_review_ids = $aat->get_review_ids_for_title_id($related_film_id, 1);
        if (empty($related_review_ids[0])) {
            continue;
        }

        $related_review_id = (int) $related_review_ids[0];
        $related_review_url = get_permalink($related_review_id);
        if (!$related_review_url) {
            continue;
        }

        $related_film_label = $get_title_label($related_film_id);
        if ($related_film_label === '') {
            $related_film_label = strtoupper($related_film_id);
        }

        $related_review_thumb = get_the_post_thumbnail_url($related_review_id, 'medium_large');
        $related_film_visual = method_exists($aat, 'get_title_visual_package') ? $aat->get_title_visual_package($related_film_id, 'medium') : array();
        // The card's media box is a wide still; the film's backdrop fits it, a poster is the last resort.
        $related_visual_media_html = '';
        if (!empty($related_film_visual['backdrop_url'])) {
            $related_visual_media_html = '<img class="aat-related-review-image" src="' . esc_url($related_film_visual['backdrop_url']) . '" alt="' . esc_attr($related_film_label) . '" loading="lazy" decoding="async" />';
        } elseif (!empty($related_film_visual['poster_html'])) {
            $related_visual_media_html = (string) $related_film_visual['poster_html'];
        } elseif (!empty($related_film_visual['poster_url'])) {
            $related_visual_media_html = '<img class="aat-related-review-image" src="' . esc_url($related_film_visual['poster_url']) . '" alt="' . esc_attr(sprintf(__('%s poster', 'academy-awards-table'), $related_film_label)) . '" loading="lazy" decoding="async" />';
        }
        // Only a written excerpt is shown; the template never builds one from the content.
        $related_review_excerpt = trim((string) get_post_field('post_excerpt', $related_review_id));
        $related_review_excerpt = $related_review_excerpt !== '' ? wp_trim_words(strip_tags($related_review_excerpt), 32) : '';
        $related_release_year = '';
        if (!empty($related_film_visual['release_year'])) {
            $related_release_year = (string) $related_film_visual['release_year'];
        } elseif (!empty($related_film_visual['tmdb']['release_date'])) {
            $related_release_year = substr((string) $related_film_visual['tmdb']['release_date'], 0, 4);
        }

        $aat_related_reviews[] = array(
            'review_id' => $related_review_id,
            'review_url' => $related_review_url,
            'review_title' => get_the_title($related_review_id),
            'review_thumb' => $related_review_thumb,
            'film_id' => $related_film_id,
            'film_label' => $related_film_label,
            'film_url' => $build_entity_url($related_film_id),
            'film_year' => $related_release_year,
            'fallback_html' => $related_visual_media_html,
            'excerpt' => $related_review_excerpt,
            'sort_date' => get_post_field('post_date', $related_review_id),
        );
    }

    if (!empty($aat_related_reviews)) {
        usort($aat_related_reviews, function($left, $right) {
            return strcmp((string) ($right['sort_date'] ?? ''), (string) ($left['sort_date'] ?? ''));
        });
        $aat_related_reviews = array_slice($aat_related_reviews, 0, $aat_get_related_review_limit());
    }
}

get_header();
?>
<div class="aat-container aat-entity-page aat-profile-file <?php echo esc_attr($profile_file_class); ?>">
<?php // Route section composer (2.7.82): every top-level entity section is captured into $aat_sections and re-emitted through aat_entity_route_sections. Default output is byte-identical; the filter is inert until a consumer hooks it. ?>
<?php $aat_sections = array(); $aat_route_context = $aat->get_route_context(); ob_start(); ?>
    <nav class="aat-breadcrumbs" aria-label="Breadcrumb">
        <a href="<?php echo esc_url(home_url('/')); ?>">Home</a>
        <span class="aat-sep">/</span>
        <a href="<?php echo esc_url($database_url); ?>">Oscars</a>
        <span class="aat-sep">/</span>
        <span><?php echo esc_html($type_label); ?></span>
        <span class="aat-sep">/</span>
        <span aria-current="page"><?php echo esc_html($profile_subject_label); ?></span>
    </nav>
<?php $aat_sections['breadcrumbs'] = ob_get_clean(); ob_start(); ?>

    <section class="<?php echo esc_attr(implode(' ', $hero_classes)); ?>">
        <?php if (in_array($entity, array('title', 'name', 'company'), true)) : ?>
            <?php $aat_poster_html = ($entity === 'title' && !empty($visual['poster_html'])) ? $visual['poster_html'] : ''; ?>
            <?php $aat_poster_url = ($entity === 'title' && !empty($visual['poster_url'])) ? (string) $visual['poster_url'] : ''; ?>
            <?php $aat_person_portrait_url = ($entity === 'name' && !empty($visual['portrait_url'])) ? $visual['portrait_url'] : ''; ?>
            <?php $aat_fallback_html = $entity === 'company' ? $aat_company_plate_html : (!empty($visual['fallback_html']) ? $visual['fallback_html'] : ''); ?>
            <?php if (!empty($aat_poster_html) || $aat_poster_url !== '' || !empty($aat_person_portrait_url) || !empty($aat_fallback_html)) : ?>
                <?php
                $aat_visual_wrap_classes = array('aat-entity-poster-wrap');
                if ($entity === 'name') {
                    $aat_visual_wrap_classes[] = 'is-person';
                    $aat_visual_wrap_classes[] = 'has-person-visual-state-' . $aat_person_visual_state;
                    $aat_visual_wrap_classes[] = 'has-person-visual-source-' . $aat_person_visual_source;
                    if (empty($aat_person_portrait_url)) {
                        $aat_visual_wrap_classes[] = 'is-person-fallback';
                    }
                }
                if ($entity === 'company') {
                    $aat_visual_wrap_classes[] = 'is-company';
                }
                ?>
                <div class="<?php echo esc_attr(implode(' ', $aat_visual_wrap_classes)); ?>">
                    <?php if (!empty($aat_poster_html)) : ?>
                        <?php echo $aat_poster_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php elseif ($aat_poster_url !== '') : ?>
                        <img class="aat-entity-poster" src="<?php echo esc_url($aat_poster_url); ?>" alt="<?php echo esc_attr($label ? $label : strtoupper($id)); ?> poster" loading="eager" decoding="async" fetchpriority="high" />
                    <?php elseif (!empty($aat_person_portrait_url)) : ?>
                        <img class="aat-entity-portrait" src="<?php echo esc_url($aat_person_portrait_url); ?>" alt="<?php echo esc_attr($label ? $label : strtoupper($id)); ?> portrait" loading="eager" decoding="async" fetchpriority="high" />
                    <?php elseif (!empty($aat_fallback_html)) : ?>
                        <?php echo $aat_fallback_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="aat-entity-hero-copy">
            <h1 class="aat-entity-title"><?php echo esc_html($profile_subject_label); ?></h1>
            <p class="aat-entity-subtitle"><?php echo esc_html($type_label); ?><?php if ($aat_entity_subtitle_span !== '') : ?><span class="aat-meta-sep">&middot;</span><?php echo wp_kses_post($aat_entity_subtitle_span); ?><?php endif; ?></p>
            <?php if ($summary) : ?>
                <p class="aat-entity-summary"><?php echo wp_kses_post($summary); ?></p>
            <?php endif; ?>
            <?php if ($entity === 'title' && (!empty($visual['director']) || !empty($visual['runtime']) || !empty($visual['overview']))) : ?>
                <?php if (!empty($visual['director']) || !empty($visual['runtime'])) : ?>
                    <div class="aat-entity-meta-line">
                        <?php if (!empty($visual['director'])) : ?><span><?php echo esc_html(sprintf(__('Directed by %s', 'academy-awards-table'), $visual['director'])); ?></span><?php endif; ?>
                        <?php if (!empty($visual['runtime'])) : ?><span><?php echo esc_html($visual['runtime']); ?> min</span><?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($visual['overview'])) : ?><p class="aat-entity-overview"><?php echo esc_html($visual['overview']); ?></p><?php endif; ?>
            <?php elseif ($entity === 'name' && !empty($tmdb)) : ?>
                <?php $person_meta_bits = array_values(array_filter((array) ($visual['meta_bits'] ?? array()), 'strlen')); ?>
                <?php if (!empty($person_meta_bits)) : ?>
                    <div class="aat-entity-meta-line">
                        <?php foreach ($person_meta_bits as $person_meta_bit) : ?>
                            <span><?php echo esc_html($person_meta_bit); ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($visual['biography'])) : ?><p class="aat-entity-overview"><?php echo esc_html(wp_trim_words((string) $visual['biography'], 55)); ?></p><?php endif; ?>
            <?php elseif ($entity === 'company' && $entity_anchor_title !== '') : ?>
                <?php $company_anchor_url = $company_film_id !== '' ? $build_entity_url($company_film_id) : ''; ?>
                <div class="aat-entity-meta-line">
                    <span><?php echo esc_html(intval($entity_anchor_stats['wins'] ?? 0) > 0 ? __('Most awarded film:', 'academy-awards-table') : __('Most nominated film:', 'academy-awards-table')); ?> <?php if ($company_anchor_url) : ?><a class="aat-entity-link" href="<?php echo esc_url($company_anchor_url); ?>"><?php echo esc_html($entity_anchor_title); ?></a><?php else : ?><?php echo esc_html($entity_anchor_title); ?><?php endif; ?><?php if (!empty($visual['release_year'])) : ?> (<?php echo esc_html($visual['release_year']); ?>)<?php endif; ?></span>
                </div>
            <?php endif; ?>

            <div class="aat-profile-command-band" aria-label="<?php esc_attr_e('Oscar profile summary', 'academy-awards-table'); ?>">
                <div class="aat-profile-command-card">
                    <span><?php echo esc_html__('Nominations', 'academy-awards-table'); ?></span>
                    <strong><?php echo esc_html(number_format_i18n($total_nominations)); ?></strong>
                </div>
                <div class="aat-profile-command-card">
                    <span><?php echo esc_html__('Wins', 'academy-awards-table'); ?></span>
                    <strong><?php echo esc_html(number_format_i18n($total_wins)); ?></strong>
                </div>
                <div class="aat-profile-command-card">
                    <span><?php echo esc_html__('Span', 'academy-awards-table'); ?></span>
                    <strong><?php echo $span ? wp_kses_post($span) : esc_html__('Pending', 'academy-awards-table'); ?></strong>
                </div>
                <div class="aat-profile-command-card">
                    <span><?php echo esc_html($entity === 'title' ? __('Categories', 'academy-awards-table') : __('Films', 'academy-awards-table')); ?></span>
                    <strong><?php echo esc_html(number_format_i18n($entity === 'title' ? $total_categories : count($distinct_films))); ?></strong>
                </div>
            </div>

            <div class="aat-entity-actions">
                <a class="aat-btn aat-btn-primary" href="#oscar-history"><?php echo esc_html__('Oscar history', 'academy-awards-table'); ?></a>
                <?php if ($entity !== 'title' && !empty($distinct_films)) : ?>
                    <a class="aat-btn aat-btn-secondary" href="#nominated-films"><?php echo esc_html__('Nominated films', 'academy-awards-table'); ?></a>
                <?php endif; ?>
                <?php if (!empty($imdb_url)) : ?>
                    <a class="aat-btn aat-btn-secondary" href="<?php echo esc_url($imdb_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__('IMDb page', 'academy-awards-table'); ?></a>
                <?php endif; ?>
            </div>
        </div>
    </section>
<?php $aat_sections['hero'] = ob_get_clean(); ob_start(); ?>

    <?php if (!empty($latest_result)) : ?>
        <?php
        $latest_status_classes = array('aat-entity-status-banner');
        if (!empty($latest_result['wins'])) { $latest_status_classes[] = 'is-winner'; }
        $latest_ceremony_url = $build_ceremony_url(intval($latest_result['ceremony'] ?? 0));
        $aat_latest_wins = intval($latest_result['wins'] ?? 0);
        $aat_latest_nominations = intval($latest_result['nominations'] ?? 0);
        // The films behind the latest result, for people and companies; the first one carries the poster.
        $aat_latest_films = array();
        if ($entity !== 'title' && !empty($timeline[$latest_ceremony]['rows'])) {
            foreach ($timeline[$latest_ceremony]['rows'] as $aat_latest_row) {
                $aat_latest_row_id = $get_primary_pipe_value($aat_latest_row['film_id'] ?? '');
                if ($aat_latest_row_id === '' || isset($aat_latest_films[$aat_latest_row_id])) { continue; }
                $aat_latest_row_label = $get_title_label($aat_latest_row_id);
                if ($aat_latest_row_label === '') { $aat_latest_row_label = $get_primary_pipe_value($aat_latest_row['film'] ?? ''); }
                if ($aat_latest_row_label === '') { continue; }
                $aat_latest_films[$aat_latest_row_id] = $aat_latest_row_label;
            }
        }
        $aat_latest_poster_html = '';
        $aat_latest_first_id = '';
        if (!empty($aat_latest_films)) {
            reset($aat_latest_films);
            $aat_latest_first_id = (string) key($aat_latest_films);
        }
        if ($aat_latest_first_id !== '' && method_exists($aat, 'get_poster_img_html_for_title')) {
            $aat_latest_poster_html = (string) $aat->get_poster_img_html_for_title($aat_latest_first_id, 'medium', array('class' => 'aat-entity-status-poster', 'sizes' => '96px'));
        }
        if ($aat_latest_poster_html !== '') { $latest_status_classes[] = 'has-status-media'; }
        ?>
        <section class="<?php echo esc_attr(implode(' ', $latest_status_classes)); ?>" aria-label="<?php echo esc_attr__('Latest Oscar result', 'academy-awards-table'); ?>">
            <?php if ($aat_latest_poster_html !== '') : ?>
                <?php $aat_latest_poster_url = $build_entity_url($aat_latest_first_id); ?>
                <div class="aat-entity-status-media">
                    <?php if ($aat_latest_poster_url) : ?>
                        <a href="<?php echo esc_url($aat_latest_poster_url); ?>" aria-label="<?php echo esc_attr(reset($aat_latest_films)); ?>"><?php echo $aat_latest_poster_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
                    <?php else : ?>
                        <?php echo $aat_latest_poster_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <div class="aat-entity-status-copy">
                <p class="aat-entity-status-kicker"><?php echo esc_html__('Latest result', 'academy-awards-table'); ?></p>
                <h2 class="aat-entity-status-title">
                    <?php
                    if ($aat_latest_wins > 0 && $aat_latest_wins < $aat_latest_nominations) {
                        echo esc_html(sprintf(
                            /* translators: 1: wins phrase, 2: nominations phrase. */
                            __('%1$s from %2$s', 'academy-awards-table'),
                            sprintf(_n('%s win', '%s wins', $aat_latest_wins, 'academy-awards-table'), number_format_i18n($aat_latest_wins)),
                            sprintf(_n('%s nomination', '%s nominations', $aat_latest_nominations, 'academy-awards-table'), number_format_i18n($aat_latest_nominations))
                        ));
                    } elseif ($aat_latest_wins > 0) {
                        echo esc_html(sprintf(_n('%s win', '%s wins', $aat_latest_wins, 'academy-awards-table'), number_format_i18n($aat_latest_wins)));
                    } else {
                        echo esc_html(sprintf(_n('%s nomination', '%s nominations', $aat_latest_nominations, 'academy-awards-table'), number_format_i18n($aat_latest_nominations)));
                    }
                    ?>
                </h2>
                <p class="aat-entity-status-summary">
                    <?php
                    $aat_latest_where = $ordinal(intval($latest_result['ceremony'])) . ' Academy Awards';
                    $aat_latest_where = $latest_ceremony_url
                        ? '<a class="aat-entity-link aat-timeline-link" href="' . esc_url($latest_ceremony_url) . '">' . esc_html($aat_latest_where) . '</a>'
                        : esc_html($aat_latest_where);
                    if ((string) ($latest_result['year'] ?? '') !== '') {
                        $aat_latest_where .= ' (' . esc_html($latest_result['year']) . ')';
                    }
                    if (!empty($aat_latest_films)) {
                        $aat_latest_film_links = array();
                        foreach ($aat_latest_films as $aat_latest_film_id => $aat_latest_film_label) {
                            $aat_latest_film_url = $build_entity_url((string) $aat_latest_film_id);
                            $aat_latest_film_links[] = $aat_latest_film_url
                                ? '<a class="aat-entity-link" href="' . esc_url($aat_latest_film_url) . '">' . esc_html($aat_latest_film_label) . '</a>'
                                : esc_html($aat_latest_film_label);
                        }
                        /* translators: 1: linked ceremony label, 2: linked film titles. */
                        echo wp_kses_post(sprintf(__('At the %1$s, for %2$s.', 'academy-awards-table'), $aat_latest_where, implode(', ', $aat_latest_film_links)));
                    } else {
                        /* translators: %s: linked ceremony label. */
                        echo wp_kses_post(sprintf(__('At the %s.', 'academy-awards-table'), $aat_latest_where));
                    }
                    ?>
                </p>
            </div>
            <?php if (!empty($latest_result['categories'])) : ?>
                <div class="aat-entity-status-tags">
                    <?php foreach (array_slice($latest_result['categories'], 0, 6) as $result_category) : ?>
                        <?php if (!empty($result_category['url'])) : ?>
                            <a class="aat-entity-status-tag aat-hub-link" href="<?php echo esc_url($result_category['url']); ?>"><?php echo esc_html($result_category['label'] ?? ''); ?></a>
                        <?php else : ?>
                            <span class="aat-entity-status-tag"><?php echo esc_html($result_category['label'] ?? ''); ?></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
<?php $aat_sections['latest-result'] = ob_get_clean(); ob_start(); ?>

    <div class="aat-stats-bar aat-entity-stats">
        <div class="aat-stat"><span class="aat-stat-number"><?php echo esc_html(number_format_i18n($total_nominations)); ?></span><span class="aat-stat-label">Nominations</span></div>
        <div class="aat-stat"><span class="aat-stat-number"><?php echo esc_html(number_format_i18n($total_wins)); ?></span><span class="aat-stat-label">Wins</span></div>
        <div class="aat-stat"><span class="aat-stat-number"><?php echo esc_html(number_format_i18n($total_categories)); ?></span><span class="aat-stat-label">Categories</span></div>
        <div class="aat-stat"><span class="aat-stat-number"><?php echo esc_html(number_format_i18n($total_ceremonies)); ?></span><span class="aat-stat-label">Ceremonies</span></div>
        <?php if ($entity !== 'title') : ?>
            <div class="aat-stat"><span class="aat-stat-number"><?php echo esc_html(number_format_i18n(count($distinct_films))); ?></span><span class="aat-stat-label">Films</span></div>
        <?php endif; ?>
        <?php if ($span) : ?>
            <div class="aat-stat"><span class="aat-stat-number"><?php echo wp_kses_post($span); ?></span><span class="aat-stat-label">Span</span></div>
        <?php endif; ?>
        <?php if ($latest_year) : ?>
            <div class="aat-stat">
                <span class="aat-stat-number">
                    <?php if ($latest_ceremony > 0 && $build_ceremony_url($latest_ceremony)) : ?>
                        <a class="aat-entity-link aat-timeline-link" href="<?php echo esc_url($build_ceremony_url($latest_ceremony)); ?>"><?php echo esc_html($latest_year); ?></a>
                    <?php else : ?>
                        <?php echo esc_html($latest_year); ?>
                    <?php endif; ?>
                </span>
                <span class="aat-stat-label">Most Recent</span>
            </div>
        <?php endif; ?>
    </div>
<?php $aat_sections['stats-bar'] = ob_get_clean(); ob_start(); ?>

    <?php if ($entity === 'title' && !empty($aat_review_ids)) : ?>
        <?php
        $aat_primary_review_id = (int) $aat_review_ids[0];
        $aat_review_url = get_permalink($aat_primary_review_id);
        $aat_review_title = get_the_title($aat_primary_review_id);
        $aat_review_thumb = get_the_post_thumbnail_url($aat_primary_review_id, 'medium');
        // Only a written excerpt is shown; the template never builds one from the content.
        $aat_review_excerpt = trim((string) get_post_field('post_excerpt', $aat_primary_review_id));
        $aat_review_excerpt = $aat_review_excerpt !== '' ? wp_trim_words(strip_tags($aat_review_excerpt), 32) : '';
        // The review's image is shown only when it is not the identity poster already on the page.
        $aat_review_thumb_id = function_exists('get_post_thumbnail_id') ? (int) get_post_thumbnail_id($aat_primary_review_id) : 0;
        $aat_title_poster_id = method_exists($aat, 'get_poster_attachment_id_for_title') ? (int) $aat->get_poster_attachment_id_for_title($id) : 0;
        if ($aat_review_thumb_id > 0 && $aat_review_thumb_id === $aat_title_poster_id) {
            $aat_review_thumb = '';
        }
        ?>
        <section class="aat-lunara-review-module<?php echo !empty($aat_review_thumb) ? ' has-review-media' : ''; ?>" aria-label="Lunara Film review">
            <div class="aat-lunara-review-inner">
                <?php if (!empty($aat_review_thumb)) : ?>
                    <a class="aat-lunara-review-poster" href="<?php echo esc_url($aat_review_url); ?>">
                        <img src="<?php echo esc_url($aat_review_thumb); ?>" alt="<?php echo esc_attr($aat_review_title); ?>" loading="lazy" decoding="async" />
                    </a>
                <?php endif; ?>
                <div class="aat-lunara-review-content">
                    <div class="aat-lunara-review-kicker"><?php echo esc_html__('Lunara Film review', 'academy-awards-table'); ?></div>
                    <h2 class="aat-lunara-review-title"><a href="<?php echo esc_url($aat_review_url); ?>"><?php echo esc_html($aat_review_title); ?></a></h2>
                    <?php if ($aat_review_excerpt !== '') : ?>
                        <p class="aat-lunara-review-excerpt"><?php echo esc_html($aat_review_excerpt); ?></p>
                    <?php endif; ?>
                    <div class="aat-lunara-review-actions">
                        <a class="aat-btn aat-btn-primary" href="<?php echo esc_url($aat_review_url); ?>"><?php echo esc_html__('Read the review', 'academy-awards-table'); ?></a>
                        <a class="aat-hub-inline-link" href="<?php echo esc_url(home_url('/reviews/')); ?>"><?php echo esc_html__('All Lunara Film reviews', 'academy-awards-table'); ?></a>
                    </div>
                    <?php if (count($aat_review_ids) > 1) : ?>
                        <div class="aat-lunara-review-more">
                            <span class="aat-lunara-review-more-label">Also on Lunara Film:</span>
                            <?php
                            $more_links = array();
                            foreach (array_slice($aat_review_ids, 1) as $rid) {
                                $rid = (int) $rid;
                                $more_links[] = '<a href="' . esc_url(get_permalink($rid)) . '">' . esc_html(get_the_title($rid)) . '</a>';
                            }
                            echo implode($pipe_separator_html, $more_links); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                            ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>
<?php $aat_sections['review-module'] = ob_get_clean(); ob_start(); ?>

        <section id="oscar-history" class="aat-entity-section aat-entity-timeline">
        <div class="aat-section-head">
            <h2 class="aat-section-title">Oscar History</h2>
            <p class="aat-section-description"><?php echo esc_html(sprintf(__('Every nomination and win on record for %s, by ceremony.', 'academy-awards-table'), $profile_subject_label)); ?></p>
        </div>

        <?php if (empty($rows)) : ?>
            <div class="aat-no-results">
                <h3>No records found</h3>
                <p>This profile has not yet been matched to a verified Oscar record in the ledger.</p>
            </div>
        <?php else : ?>
            <div class="aat-timeline-list">
                <?php foreach ($timeline as $cer => $group) : ?>
                    <?php $ceremony_url = $build_ceremony_url($cer); ?>
                    <section class="aat-timeline-card" aria-labelledby="aat-timeline-<?php echo (int) $cer; ?>">
                        <div class="aat-timeline-meta">
                            <h3 class="aat-timeline-ceremony" id="aat-timeline-<?php echo (int) $cer; ?>">
                                <?php if ($ceremony_url) : ?>
                                    <a class="aat-entity-link aat-timeline-link" href="<?php echo esc_url($ceremony_url); ?>"><?php echo esc_html($ordinal($cer)); ?> Academy Awards</a>
                                <?php else : ?>
                                    <?php echo esc_html($ordinal($cer)); ?> Academy Awards
                                <?php endif; ?>
                            </h3>
                            <?php if ((string) $group['year'] !== '') : ?>
                                <div class="aat-timeline-year"><?php echo esc_html($group['year']); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="aat-timeline-body">
                            <?php foreach ($group['rows'] as $r) :
                                $cat = (string) ($r['canonical_category'] ?? $r['category'] ?? '');
                                $cat_url = $aat->get_category_url($cat);
                                $cat_label = $format_category($cat, $cer);
                                $is_winner = (!empty($r['winner']) && (int) $r['winner'] === 1);
                                $row_title_id = $entity === 'title' ? $id : $get_primary_pipe_value($r['film_id'] ?? '');
                                // People and companies see the film beside each credit; a title page is already the film.
                                $row_poster_html = ($entity !== 'title' && $row_title_id !== '' && method_exists($aat, 'get_poster_img_html_for_title'))
                                    ? (string) $aat->get_poster_img_html_for_title($row_title_id, 'medium', array('class' => 'aat-history-poster', 'sizes' => '56px'))
                                    : '';
                                $history_classes = array('aat-history-item');
                                if ($is_winner) {
                                    $history_classes[] = 'is-winner';
                                }
                                if ($row_poster_html !== '') {
                                    $history_classes[] = 'has-history-media';
                                }
                            ?>
                                <article class="<?php echo esc_attr(implode(' ', $history_classes)); ?>">
                                    <?php if ($row_poster_html !== '') : ?>
                                        <div class="aat-history-media"><?php echo $row_poster_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
                                    <?php endif; ?>
                                    <div class="aat-history-main">
                                        <div class="aat-history-category">
                                            <?php if ($cat_url) : ?>
                                                <a class="aat-hub-link" href="<?php echo esc_url($cat_url); ?>"><span class="aat-category-pill"><?php echo esc_html($cat_label); ?></span></a>
                                            <?php else : ?>
                                                <span class="aat-category-pill"><?php echo esc_html($cat_label); ?></span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="aat-history-detail">
                                            <?php if ($entity === 'title') : ?>
                                                <?php $nominee_display = $resolve_title_nominee_display($r); ?>
                                                <?php if (!empty($nominee_display['label'])) : ?>
                                                    <div class="aat-history-line"><strong>Nominee<?php echo !empty($nominee_display['is_plural']) ? 's' : ''; ?>:</strong> <?php echo $render_linked_pipe($nominee_display['label'], $nominee_display['ids']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
                                                <?php else : ?>
                                                    <div class="aat-history-line aat-history-line-muted">Nominee data is still being verified for this entry.</div>
                                                <?php endif; ?>
                                            <?php else : ?>
                                                <div class="aat-history-line"><strong>Film:</strong> <?php echo $render_linked_pipe($r['film'] ?? '', $r['film_id'] ?? ''); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
                                            <?php endif; ?>

                                            <?php if (!empty($r['detail'])) : ?>
                                                <div class="aat-history-line"><strong>Detail:</strong> <?php echo esc_html(method_exists($aat, 'format_pipe_list') ? $aat->format_pipe_list($r['detail']) : (string) $r['detail']); ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($r['citation'])) : ?>
                                                <div class="aat-history-line"><strong>Citation:</strong> <?php echo esc_html((string) $r['citation']); ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($r['note'])) : ?>
                                                <div class="aat-history-line"><strong>Note:</strong> <?php echo esc_html((string) $r['note']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="aat-history-status">
                                        <?php if ($is_winner) : ?>
                                            <span class="aat-winner-badge">Winner</span>
                                        <?php else : ?>
                                            <span class="aat-nominee-badge">Nominee</span>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
<?php $aat_sections['oscar-history'] = ob_get_clean(); ob_start(); ?>


    <?php if ($entity !== 'title' && !empty($distinct_films)) : ?>
        <?php
            $filmography_full_requested = isset($_GET['filmography']) && sanitize_key(wp_unslash($_GET['filmography'])) === 'full';
            $filmography_fast_limit = (int) apply_filters('aat_entity_filmography_fast_limit', 12, $entity, $id);
            $filmography_fast_limit = max(1, $filmography_fast_limit);
            $filmography_ids = array_keys($distinct_films);
            $filmography_total = count($filmography_ids);
            $filmography_render_ids = $filmography_full_requested ? $filmography_ids : array_slice($filmography_ids, 0, $filmography_fast_limit);
            $filmography_hidden_count = max(0, $filmography_total - count($filmography_render_ids));
            $filmography_full_url = add_query_arg('filmography', 'full', $aat->get_entity_url($id));
            $filmography_fast_url = $aat->get_entity_url($id);
        ?>
        <section id="nominated-films" class="aat-entity-section aat-filmography-section">
            <div class="aat-section-head">
                <h2 class="aat-section-title">Nominated Films</h2>
                <p class="aat-section-description">
                    <?php if ($filmography_hidden_count > 0) : ?>
                        <?php echo esc_html(sprintf(
                            /* translators: 1: profile name, 2: films shown, 3: films in total. */
                            __('Films with nominations credited to %1$s, most awarded first. Showing %2$s of %3$s.', 'academy-awards-table'),
                            $profile_subject_label,
                            number_format_i18n(count($filmography_render_ids)),
                            number_format_i18n($filmography_total)
                        )); ?>
                    <?php else : ?>
                        <?php echo esc_html(sprintf(
                            /* translators: 1: profile name, 2: number of films. */
                            _n('%2$s film with nominations credited to %1$s.', '%2$s films with nominations credited to %1$s, most awarded first.', $filmography_total, 'academy-awards-table'),
                            $profile_subject_label,
                            number_format_i18n($filmography_total)
                        )); ?>
                    <?php endif; ?>
                </p>
                <?php if ($filmography_total > $filmography_fast_limit) : ?>
                    <p class="aat-entity-mode-link">
                        <?php if ($filmography_full_requested) : ?>
                            <a class="aat-winner-circle-action is-kind-film-history" href="<?php echo esc_url($filmography_fast_url); ?>"><?php echo esc_html(sprintf(__('Show the first %s films', 'academy-awards-table'), number_format_i18n($filmography_fast_limit))); ?></a>
                        <?php else : ?>
                            <a class="aat-winner-circle-action is-kind-film-history" href="<?php echo esc_url($filmography_full_url); ?>"><?php echo esc_html(sprintf(__('Show all %s films', 'academy-awards-table'), number_format_i18n($filmography_total))); ?></a>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
            </div>
            <div class="aat-filmography-grid">
                <?php foreach ($filmography_render_ids as $fid) :
                    $fid = trim((string) $fid);
                    if (!$fid) { continue; }
                    $film_stats = is_array($distinct_films[$fid] ?? null) ? $distinct_films[$fid] : array();
                    $film_nominations = intval($film_stats['nominations'] ?? 0);
                    $film_wins = intval($film_stats['wins'] ?? 0);
                    $film_category_count = !empty($film_stats['categories']) && is_array($film_stats['categories']) ? count($film_stats['categories']) : 0;
                    $film_ceremony_count = !empty($film_stats['ceremonies']) && is_array($film_stats['ceremonies']) ? count($film_stats['ceremonies']) : 0;
                    $film_label = $get_title_label($fid);
                    if (!$film_label) { $film_label = strtoupper($fid); }
                    $poster_html = $aat->get_poster_img_html_for_title($fid, 'medium', array('class' => 'aat-filmography-poster', 'sizes' => '(max-width: 720px) 132px, 160px'));
                    $tmdb_item = method_exists($aat, 'get_tmdb_data_for_imdb_id') ? $aat->get_tmdb_data_for_imdb_id($fid, false) : array();
                    $film_url = $build_entity_url($fid);
                    $film_year = !empty($tmdb_item['release_date']) ? substr((string) $tmdb_item['release_date'], 0, 4) : '';
                ?>
                    <article class="aat-filmography-card">
                        <a class="aat-filmography-link" href="<?php echo esc_url($film_url ? $film_url : $database_url); ?>" aria-label="<?php echo esc_attr($film_year !== '' ? sprintf('%1$s (%2$s)', $film_label, $film_year) : $film_label); ?>">
                            <div class="aat-filmography-poster-wrap">
                                <?php if ($poster_html) : ?>
                                    <?php echo $poster_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <?php elseif (!empty($tmdb_item['poster_full'])) : ?>
                                    <img class="aat-filmography-poster" src="<?php echo esc_url($tmdb_item['poster_full']); ?>" alt="<?php echo esc_attr($film_label); ?> poster" loading="lazy" decoding="async" />
                                <?php else : ?>
                                    <?php $film_visual = method_exists($aat, 'get_title_visual_package') ? $aat->get_title_visual_package($fid, 'medium') : array(); ?>
                                    <?php if (!empty($film_visual['card_fallback_html'])) : ?>
                                        <?php echo $film_visual['card_fallback_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                    <?php else : ?>
                                        <div class="aat-filmography-poster-placeholder"><span><?php echo esc_html($film_label); ?></span></div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </a>
                        <h3 class="aat-filmography-title"><a href="<?php echo esc_url($film_url ? $film_url : $database_url); ?>"><?php echo esc_html($film_label); ?></a></h3>
                        <?php if ($film_year !== '') : ?><p class="aat-filmography-meta"><?php echo esc_html($film_year); ?></p><?php endif; ?>
                            <?php if ($film_nominations > 0) : ?>
                                <div class="aat-filmography-trail" aria-label="<?php echo esc_attr(sprintf(__('%s Oscar trail summary', 'academy-awards-table'), $film_label)); ?>">
                                    <span><?php echo esc_html(sprintf(_n('%s nomination', '%s nominations', $film_nominations, 'academy-awards-table'), number_format_i18n($film_nominations))); ?></span>
                                    <?php if ($film_wins > 0) : ?>
                                        <span><?php echo esc_html(sprintf(_n('%s win', '%s wins', $film_wins, 'academy-awards-table'), number_format_i18n($film_wins))); ?></span>
                                    <?php endif; ?>
                                    <?php if ($film_category_count > 0) : ?>
                                        <span><?php echo esc_html(sprintf(_n('%s category', '%s categories', $film_category_count, 'academy-awards-table'), number_format_i18n($film_category_count))); ?></span>
                                    <?php endif; ?>
                                    <?php if ($film_ceremony_count > 1) : ?>
                                        <span><?php echo esc_html(sprintf(_n('%s ceremony', '%s ceremonies', $film_ceremony_count, 'academy-awards-table'), number_format_i18n($film_ceremony_count))); ?></span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php if ($filmography_hidden_count > 0) : ?>
                <div class="aat-entity-compact-notice">
                    <span><?php echo esc_html(sprintf(_n('%s more film is in the full list.', '%s more films are in the full list.', $filmography_hidden_count, 'academy-awards-table'), number_format_i18n($filmography_hidden_count))); ?></span>
                    <a class="aat-winner-circle-action is-kind-film-history" href="<?php echo esc_url($filmography_full_url); ?>"><?php echo esc_html(sprintf(__('Show all %s films', 'academy-awards-table'), number_format_i18n($filmography_total))); ?></a>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
<?php $aat_sections['filmography'] = ob_get_clean(); ob_start(); ?>

    <?php if ($entity !== 'title' && !empty($aat_related_reviews)) : ?>
        <section id="on-lunara" class="aat-entity-section aat-related-reviews-section <?php echo esc_attr($aat_related_review_treatment_class); ?>">
            <div class="aat-section-head">
                <h2 class="aat-section-title">On Lunara Film</h2>
                <p class="aat-section-description"><?php echo esc_html__('Lunara Film reviews of films on this profile.', 'academy-awards-table'); ?></p>
            </div>
            <div class="aat-related-reviews-grid <?php echo esc_attr($aat_related_review_treatment_class); ?>">
                <?php foreach ($aat_related_reviews as $related_review) : ?>
                    <?php
                        $aat_related_review_has_media = !empty($related_review['review_thumb']) || !empty($related_review['fallback_html']);
                        $aat_related_review_classes = array('aat-related-review-card', $aat_related_review_has_media ? 'has-media' : 'has-no-media');
                    ?>
                    <article class="<?php echo esc_attr(implode(' ', $aat_related_review_classes)); ?>">
                        <?php if ($aat_related_review_has_media) : ?>
                        <a class="aat-related-review-media" href="<?php echo esc_url($related_review['review_url']); ?>">
                            <?php if (!empty($related_review['review_thumb'])) : ?>
                                <img class="aat-related-review-image" src="<?php echo esc_url($related_review['review_thumb']); ?>" alt="<?php echo esc_attr($related_review['review_title']); ?>" loading="lazy" decoding="async" />
                            <?php elseif (!empty($related_review['fallback_html'])) : ?>
                                <?php echo $related_review['fallback_html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            <?php endif; ?>
                        </a>
                        <?php endif; ?>
                        <div class="aat-related-review-body">
                            <div class="aat-related-review-kicker"><?php echo esc_html__('Lunara Film review', 'academy-awards-table'); ?></div>
                            <h3 class="aat-related-review-title"><a href="<?php echo esc_url($related_review['review_url']); ?>"><?php echo esc_html($related_review['review_title']); ?></a></h3>
                            <p class="aat-related-review-meta">
                                <?php if (!empty($related_review['film_url'])) : ?>
                                    <a href="<?php echo esc_url($related_review['film_url']); ?>"><?php echo esc_html($related_review['film_label']); ?></a>
                                <?php else : ?>
                                    <span><?php echo esc_html($related_review['film_label']); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($related_review['film_year'])) : ?>
                                    <span class="aat-sep"> &middot; </span><span><?php echo esc_html($related_review['film_year']); ?></span>
                                <?php endif; ?>
                            </p>
                            <?php if (!empty($related_review['excerpt'])) : ?>
                                <p class="aat-related-review-excerpt"><?php echo esc_html($related_review['excerpt']); ?></p>
                            <?php endif; ?>
                            <div class="aat-related-review-actions">
                                <a class="aat-btn aat-btn-secondary" href="<?php echo esc_url($related_review['review_url']); ?>"><?php echo esc_html__('Read the review', 'academy-awards-table'); ?></a>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
<?php $aat_sections['related-reviews'] = ob_get_clean(); ob_start(); ?>
<?php
// 2.8.8 Ledger Motion: the Nomination Ring (films) and the Career Arc (people).
if (class_exists('AAT_Ledger_Motion') && !empty($rows)) {
    echo AAT_Ledger_Motion::render($entity, $rows, array( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderer escapes every value.
        'label' => $profile_subject_label,
        'image_url' => ($entity === 'title' && !empty($visual['poster_url'])) ? (string) $visual['poster_url'] : '',
        'format_category' => $format_category,
        // 2.8.9: each spoke opens its race on the ceremony's full ballot.
        'race_url' => function($ceremony, $category) use ($build_ceremony_url) {
            $url = $build_ceremony_url($ceremony);
            return $url !== '' ? add_query_arg('ledger', 'full', $url) . '#ceremony-category-' . sanitize_title((string) $category) : '';
        },
    ));
}
?>
<?php $aat_sections['ledger-motion'] = ob_get_clean(); ob_start(); ?>

    <?php if (!empty($category_rollups) || !empty($ceremony_rollups) || !empty($aat_editorial_refs)) : ?>
        <section id="ledger-crossroads" class="aat-entity-section aat-entity-crossroads" aria-label="<?php echo esc_attr__('Ledger Crossroads', 'academy-awards-table'); ?>">
            <div class="aat-section-head">
                <h2 class="aat-section-title">Ledger Crossroads</h2>
                <p class="aat-section-description"><?php echo esc_html__('Category and ceremony pages this profile appears on, with related Lunara Film writing.', 'academy-awards-table'); ?></p>
            </div>

            <div class="aat-crossroads-grid">
                <?php if (!empty($category_rollups)) : ?>
                    <article class="aat-crossroads-card">
                        <h3><?php echo esc_html__('Categories', 'academy-awards-table'); ?></h3>
                        <div class="aat-crossroads-list">
                            <?php foreach (array_slice($category_rollups, 0, 8) as $category_rollup) : ?>
                                <?php
                                $rollup_nominations = intval($category_rollup['nominations'] ?? 0);
                                $rollup_wins = intval($category_rollup['wins'] ?? 0);
                                $rollup_meta = sprintf(_n('%s nomination', '%s nominations', $rollup_nominations, 'academy-awards-table'), number_format_i18n($rollup_nominations));
                                if ($rollup_wins > 0) {
                                    $rollup_meta .= ' / ' . sprintf(_n('%s win', '%s wins', $rollup_wins, 'academy-awards-table'), number_format_i18n($rollup_wins));
                                }
                                $rollup_url = (string) ($category_rollup['url'] ?? '');
                                ?>
                                <?php if ($rollup_url) : ?>
                                    <a class="aat-crossroad-pill" href="<?php echo esc_url($rollup_url); ?>">
                                        <span class="aat-crossroad-pill-title"><?php echo esc_html($category_rollup['label'] ?? ''); ?></span>
                                        <span class="aat-crossroad-pill-meta"><?php echo esc_html($rollup_meta); ?></span>
                                    </a>
                                <?php else : ?>
                                    <span class="aat-crossroad-pill">
                                        <span class="aat-crossroad-pill-title"><?php echo esc_html($category_rollup['label'] ?? ''); ?></span>
                                        <span class="aat-crossroad-pill-meta"><?php echo esc_html($rollup_meta); ?></span>
                                    </span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </article>
                <?php endif; ?>

                <?php if (!empty($ceremony_rollups)) : ?>
                    <article class="aat-crossroads-card">
                        <h3><?php echo esc_html__('Ceremonies', 'academy-awards-table'); ?></h3>
                        <div class="aat-crossroads-list">
                            <?php foreach (array_slice($ceremony_rollups, 0, 8) as $ceremony_rollup) : ?>
                                <?php
                                $rollup_ceremony = intval($ceremony_rollup['ceremony'] ?? 0);
                                $rollup_nominations = intval($ceremony_rollup['nominations'] ?? 0);
                                $rollup_wins = intval($ceremony_rollup['wins'] ?? 0);
                                $rollup_meta = sprintf(_n('%s nomination', '%s nominations', $rollup_nominations, 'academy-awards-table'), number_format_i18n($rollup_nominations));
                                if ($rollup_wins > 0) {
                                    $rollup_meta .= ' / ' . sprintf(_n('%s win', '%s wins', $rollup_wins, 'academy-awards-table'), number_format_i18n($rollup_wins));
                                }
                                $rollup_title = trim($ordinal($rollup_ceremony) . ' Academy Awards');
                                $rollup_year = trim((string) ($ceremony_rollup['year'] ?? ''));
                                if ($rollup_year !== '') {
                                    $rollup_title .= ' (' . $rollup_year . ')';
                                }
                                $rollup_url = (string) ($ceremony_rollup['url'] ?? '');
                                ?>
                                <?php if ($rollup_url) : ?>
                                    <a class="aat-crossroad-pill" href="<?php echo esc_url($rollup_url); ?>">
                                        <span class="aat-crossroad-pill-title"><?php echo esc_html($rollup_title); ?></span>
                                        <span class="aat-crossroad-pill-meta"><?php echo esc_html($rollup_meta); ?></span>
                                    </a>
                                <?php else : ?>
                                    <span class="aat-crossroad-pill">
                                        <span class="aat-crossroad-pill-title"><?php echo esc_html($rollup_title); ?></span>
                                        <span class="aat-crossroad-pill-meta"><?php echo esc_html($rollup_meta); ?></span>
                                    </span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </article>
                <?php endif; ?>

                <?php if (!empty($aat_editorial_refs)) : ?>
                    <article class="aat-crossroads-card aat-crossroads-card-editorial">
                        <h3><?php echo esc_html__('Editorial', 'academy-awards-table'); ?></h3>
                        <div class="aat-editorial-ref-list">
                            <?php foreach ($aat_editorial_refs as $editorial_ref) : ?>
                                <?php if (empty($editorial_ref['url']) || empty($editorial_ref['title'])) { continue; } ?>
                                <a class="aat-editorial-ref-card" href="<?php echo esc_url($editorial_ref['url']); ?>">
                                    <span class="aat-editorial-ref-type"><?php echo esc_html($editorial_ref['type_label'] ?? ''); ?></span>
                                    <strong><?php echo esc_html($editorial_ref['title']); ?></strong>
                                    <?php if (!empty($editorial_ref['excerpt'])) : ?>
                                        <span class="aat-editorial-ref-excerpt"><?php echo esc_html($editorial_ref['excerpt']); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($editorial_ref['meta_label'])) : ?>
                                        <span class="aat-editorial-ref-meta"><?php echo esc_html($editorial_ref['meta_label']); ?></span>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </article>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>
<?php $aat_sections['crossroads'] = ob_get_clean(); ob_start(); ?>

    <div class="aat-footer">
        <p>Data sourced from the Academy of Motion Picture Arts and Sciences. Structured dataset compiled and maintained by Lunara Film.</p>
        <p>Profiles are generated directly from the Lunara Film Oscars dataset. New nominations and winners appear automatically after each annual import.</p>
    </div>
<?php $aat_sections['footer'] = ob_get_clean(); echo implode('', apply_filters('aat_entity_route_sections', $aat_sections, $aat_route_context)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sections are template-rendered markup captured above. ?>
</div>
<?php get_footer();

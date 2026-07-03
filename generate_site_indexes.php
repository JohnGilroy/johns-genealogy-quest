<?php

declare(strict_types=1);

namespace JGQ\WebsiteGenerator;

use RuntimeException;

/**
 * Standalone website index and kiosk-manifest generator.
 *
 * This script is owned by the johns-genealogy-quest repository and deliberately
 * has no dependency on Genealogy Studio or the retired biography-production
 * repository. All paths are resolved from this file's repository root.
 *
 * Writes:
 *   bios/index.html
 *   spotlights/index.html
 *   data/site_manifest.json
 *   kiosk-manifest.json
 */

$generatorWarnings = [];

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function warn(string $code, string $message, array $context = []): void
{
    global $generatorWarnings;
    $generatorWarnings[] = [
        'code' => $code,
        'message' => $message,
        'context' => $context,
    ];
}

function generator_fail(string $code, string $message, array $context = []): never
{
    $detail = $context ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
    throw new RuntimeException($code . ': ' . $message . $detail);
}

$siteRoot = rtrim(str_replace('\\', '/', __DIR__), '/');
if ($siteRoot === '' || !is_dir($siteRoot)) {
    generator_fail('SITE_ROOT_MISSING', 'The website repository root could not be resolved.', [
        'siteRoot' => $siteRoot,
    ]);
}

$manifestPath = $siteRoot . '/data/site_manifest.json';
if (!is_file($manifestPath)) {
    generator_fail('MANIFEST_MISSING', 'The website manifest was not found.', [
        'manifestPath' => $manifestPath,
    ]);
}

$raw = file_get_contents($manifestPath);
if ($raw === false) {
    generator_fail('MANIFEST_READ_FAILED', 'The website manifest could not be read.', [
        'manifestPath' => $manifestPath,
    ]);
}

$manifest = json_decode($raw, true);
if (!is_array($manifest)) {
    generator_fail('MANIFEST_BAD_JSON', 'The website manifest JSON could not be parsed.', [
        'manifestPath' => $manifestPath,
        'json_error' => json_last_error_msg(),
    ]);
}

// The website repository's exported spotlight registry is now the source of truth.
$registryPath = $siteRoot . '/data/spotlights_registry.json';
$registry = read_json_file($registryPath);
if (!$registry) {
    generator_fail('REGISTRY_MISSING_OR_INVALID', 'The spotlight registry is missing or invalid.', [
        'registryPath' => $registryPath,
    ]);
}

$catalogue = (array)($registry['catalogue'] ?? []);

$baseHref = (string)($manifest['site']['base_href'] ?? '/johns-genealogy-quest/');
if ($baseHref === '' || $baseHref[0] !== '/') $baseHref = '/johns-genealogy-quest/';
if (substr($baseHref, -1) !== '/') $baseHref .= '/';

$now = gmdate('Y-m-d');

$spots = (array)($manifest['spotlights'] ?? []);

// -------------------------------------------------------------
// Rebuild spotlights from registry + filesystem scan (source-of-truth is registry + output dirs)
// -------------------------------------------------------------
$spots = rebuild_spotlights_from_registry($registry, $siteRoot, $spots);
$spots = apply_spotlight_enrichment($spots, $siteRoot);
$manifest['spotlights'] = $spots;

// The current website architecture publishes long-form content under stories/.
$stories = discover_story_previews($siteRoot);
$manifest['stories'] = $stories;
unset($manifest['bios']);
$manifest['updated_at'] = gmdate('c');

@file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

// -------------------------------------------------------------
// Derive kiosk-manifest.json from site_manifest.json
// -------------------------------------------------------------
$kioskOk = write_kiosk_manifest_from_site_manifest($siteRoot, $manifest);


// Sort spotlights by type then title
usort($spots, function ($a, $b) {
    $at = is_array($a) ? (string)($a['type'] ?? '') : '';
    $bt = is_array($b) ? (string)($b['type'] ?? '') : '';
    $c = strcasecmp($at, $bt);
    if ($c !== 0) return $c;
    $al = is_array($a) ? (string)($a['title'] ?? '') : '';
    $bl = is_array($b) ? (string)($b['title'] ?? '') : '';
    return strcasecmp($al, $bl);
});

function read_json_file(string $path): array
{
    if (!is_file($path)) return [];
    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

function safe_rel_href(string $href): string
{
    $h = trim($href);
    // keep relative only; block protocol
    if ($h === '') return '';
    if (preg_match('~^[a-z]+:~i', $h)) return '';
    return $h;
}

/**
 * Extract a <meta name="..."> content value from an HTML file.
 * We keep it simple and fast: regex within <head> (or whole file if needed).
 */
function html_meta_content_from_file(string $path, string $metaName): string
{
    if (!is_file($path)) return '';
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') return '';

    // Narrow to <head> if present (reduces accidental matches)
    if (preg_match('~<head\b[^>]*>(.*?)</head>~is', $raw, $m)) {
        $raw = $m[1];
    }

    $name = preg_quote($metaName, '~');

    // Match either order: name="X" content="Y" OR content="Y" name="X"
    $re1 = '~<meta\b[^>]*\bname\s*=\s*["\']' . $name . '["\'][^>]*\bcontent\s*=\s*["\']([^"\']*)["\'][^>]*>~is';
    $re2 = '~<meta\b[^>]*\bcontent\s*=\s*["\']([^"\']*)["\'][^>]*\bname\s*=\s*["\']' . $name . '["\'][^>]*>~is';

    if (preg_match($re1, $raw, $m)) return trim(html_entity_decode($m[1], ENT_QUOTES));
    if (preg_match($re2, $raw, $m)) return trim(html_entity_decode($m[1], ENT_QUOTES));

    return '';
}

// --- NEW HELPERS (add near your other helpers, above render_site_shell) ---

function read_partial(string $absPath): string
{
    if (!is_file($absPath)) return '';
    $raw = @file_get_contents($absPath);
    return is_string($raw) ? $raw : '';
}

function render_site_head_fragment(string $frag, string $baseHref, string $pageTitle, array $cssHrefs = []): string
{
    $links = '';
    foreach ($cssHrefs as $href) {
        $href = trim((string)$href);
        if ($href === '') continue;
        $links .= '<link rel="stylesheet" href="' . h($href) . '">' . "\n";
    }

    $out = $frag;
    $out = str_replace('{{PAGE_TITLE}}', h($pageTitle), $out);
    $out = str_replace('{{BASE_HREF}}', h($baseHref), $out);
    $out = str_replace('{{PAGE_CSS_LINKS_HTML}}', rtrim($links), $out);

    // Ensure it is wrapped in <head>…</head>
    $trim = ltrim($out);
    if (!preg_match('~^<head\b~i', $trim)) {
        $out = "<head>\n" . rtrim($out) . "\n</head>";
    }

    return $out;
}


function mark_active_nav(string $html, string $activeNav): string
{
    // activeNav: 'home'|'bios'|'spotlights'
    $href = match ($activeNav) {
        'bios'      => 'bios/index.html',
        'spotlights' => 'spotlights/index.html',
        default     => 'index.html',
    };

    // Add class="is-active" to the first <a ... href="..."> match that doesn’t already have is-active.
    $re = '~<a\b([^>]*?)\bhref\s*=\s*([\'"])' . preg_quote($href, '~') . '\\2([^>]*?)>~i';

    return preg_replace_callback($re, function ($m) {
        $before = $m[1] ?? '';
        $after  = $m[3] ?? '';

        $attrs = $before . $after;

        if (preg_match('~\bclass\s*=\s*([\'"])(.*?)\1~i', $attrs)) {
            // If there is a class attribute, append is-active if missing
            return preg_replace_callback(
                '~\bclass\s*=\s*([\'"])(.*?)\1~i',
                function ($cm) {
                    $q = $cm[1];
                    $classes = $cm[2];
                    if (preg_match('~\bis-active\b~', $classes)) return $cm[0];
                    return 'class=' . $q . trim($classes . ' is-active') . $q;
                },
                $m[0],
                1
            );
        }

        // No class attr: inject one before the closing bracket
        return preg_replace(
            '~<a\b~i',
            '<a class="is-active"',
            $m[0],
            1
        );
    }, $html, 1) ?? $html;
}

/**
 * Merge generator-produced enrichment into the manifest spotlights list.
 * Enrichment file(s) contain rows keyed by (catalog_key, division_key).
 */
function apply_spotlight_enrichment(array $spots, string $siteRoot): array
{
    $enrich = [];

    // Longevity: winners & grouping keys
    $lonPath = rtrim($siteRoot, '/') . '/spotlights/longevity/longevity_published.json';
    if (is_file($lonPath)) {
        $raw = @file_get_contents($lonPath);
        $arr = $raw ? json_decode($raw, true) : null;
        if (is_array($arr)) {
            foreach ($arr as $row) {
                if (!is_array($row)) continue;
                $ck = (string)($row['catalog_key'] ?? '');
                $dk = (string)($row['division_key'] ?? '');
                if ($ck === '' || $dk === '') continue;
                $enrich[$ck . '|' . $dk] = $row;
            }
        } else {
            warn('SPOTLIGHTS_ENRICH_BAD_JSON', 'Longevity enrichment JSON invalid.', ['path' => $lonPath]);
        }
    }

    if (!$enrich) return $spots;

    foreach ($spots as &$sp) {
        if (!is_array($sp)) continue;
        $ck = (string)($sp['catalog_key'] ?? $sp['catalogue_key'] ?? '');
        $dk = (string)($sp['division_key'] ?? $sp['division'] ?? '');
        if ($ck === '' || $dk === '') continue;

        $k = $ck . '|' . $dk;
        if (!isset($enrich[$k])) continue;

        // Merge but don't clobber core routing fields.
        $row = $enrich[$k];
        unset($row['href'], $row['title'], $row['description']);
        foreach ($row as $rk => $rv) {
            $sp[$rk] = $rv;
        }
        // Only Longevity should override link_title from winner info.
        if ($ck === 'longevity' && isset($sp['winner_link_title']) && trim((string)$sp['winner_link_title']) !== '') {
            $sp['link_title'] = (string)$sp['winner_link_title'];
        }
    }
    unset($sp);

    return $spots;
}

// --- REPLACE YOUR EXISTING render_site_shell() WITH THIS ---

function render_site_shell(
    string $baseHref,
    string $pageTitle,
    string $activeNav,
    string $innerHtml,
    string $updatedDate,
    array $cssHrefs = []
): string {
    $partialsDir = rtrim($GLOBALS['siteRoot'], '/') . '/spotlights/partials';

    $headPartial = read_partial($partialsDir . '/site_head.html');

    $cssList = $cssHrefs;

    $headHtml = render_site_head_fragment($headPartial, $baseHref, $pageTitle, $cssList);

    $headerPartial = read_partial($partialsDir . '/site_header.html');
    $footerPartial = read_partial($partialsDir . '/site_footer.html');

    // Head: prefer partial if present, otherwise fall back to the old inline head.
    $headHtml = render_site_head_fragment($headPartial, $baseHref, $pageTitle, $cssList);

    // Header/footer: must exist; if not, fall back to minimal safe markup
    $headerHtml = (trim($headerPartial) !== '') ? $headerPartial : '<header class="site-header"><div class="site-header-inner"><a href="index.html" class="site-title">John&#39;s Genealogy Quest</a></div></header>';
    $footerHtml = (trim($footerPartial) !== '') ? $footerPartial : '';

    // Mark active nav in both header and footer (footer has buttons/links too) 
    $headerHtml = mark_active_nav($headerHtml, $activeNav);
    $footerHtml = mark_active_nav($footerHtml, $activeNav);

    // Optional: if you want “Updated:” surfaced somewhere, you can inject into innerHtml or footer later.
    // For now keep behaviour unchanged — just standardise chrome.

    return '<!doctype html>
<html lang="en">
' . $headHtml . '
<body>
' . $headerHtml . '
<main class="page-main">
  <div class="page-inner">' . $innerHtml . '</div>
</main>
' . $footerHtml . '
</body>
</html>';
}

function card_link_row(?string $wikitreeUrl): string
{
    if (!$wikitreeUrl) return '';
    $u = trim($wikitreeUrl);
    if ($u === '') return '';
    return '<div class="card-actions">'
        . '<a class="jwg-btn jwg-btn-wikitree" href="' . h($u) . '" target="_blank" rel="noopener">WikiTree</a>'
        . '</div>';
}

function render_bios_index(array $bios, array $branches): string
{
    // Index branches by id
    $branchById = [];
    foreach ($branches as $br) {
        if (!is_array($br)) continue;
        $id = (string)($br['id'] ?? '');
        if ($id === '') continue;
        $branchById[$id] = [
            'id' => $id,
            'name' => (string)($br['name'] ?? $id),
            'description' => (string)($br['description'] ?? ''),
        ];
    }

    // Group bios by branch_id (default group if missing)
    $groups = []; // branch_id => [bio, bio...]
    foreach ($bios as $b) {
        if (!is_array($b)) continue;
        $href = (string)($b['href'] ?? '');
        $name = (string)($b['name'] ?? '');
        if ($href === '' || $name === '') continue;

        $bid = trim((string)($b['branch_id'] ?? ''));
        if ($bid === '') $bid = '__unassigned__';
        $groups[$bid][] = $b;
    }

    // Sort groups by branch display name (unassigned last)
    uksort($groups, function ($a, $b) use ($branchById) {
        if ($a === '__unassigned__') return 1;
        if ($b === '__unassigned__') return -1;

        $an = (string)($branchById[$a]['name'] ?? $a);
        $bn = (string)($branchById[$b]['name'] ?? $b);
        return strcasecmp($an, $bn);
    });

    // Sort bios within each group by name
    foreach ($groups as $bid => &$items) {
        usort($items, function ($x, $y) {
            $xn = is_array($x) ? (string)($x['name'] ?? '') : '';
            $yn = is_array($y) ? (string)($y['name'] ?? '') : '';
            return strcasecmp($xn, $yn);
        });
    }
    unset($items);

    // Render cards per branch group using <details> (collapsible, no JS)
    $out = '<header class="page-header">
  <h1>Biographies</h1>
  <p class="jwg-text-muted">Family biographies generated from the research database (living individuals redacted).</p>
</header>';

    if (!$groups) {
        $out .= '<p class="jwg-text-muted">No biographies listed yet.</p>';
        return $out;
    }

    foreach ($groups as $bid => $items) {

        if ($bid === '__unassigned__') {
            $bName = 'Other biographies';
            $bDesc = 'Not yet assigned to a branch.';
        } else {
            $bName = (string)($branchById[$bid]['name'] ?? $bid);
            $bDesc = (string)($branchById[$bid]['description'] ?? '');
        }

        // Open the first branch by default, others collapsed
        static $first = true;
        $openAttr = $first ? ' open' : '';
        $first = false;

        $out .= '<details class="branch-group"' . $openAttr . '>'
            . '<summary class="branch-summary">'
            .   '<span class="branch-title">' . h($bName) . '</span>'
            .   ($bDesc !== '' ? '<span class="branch-desc">' . h($bDesc) . '</span>' : '')
            .   '<span class="branch-count">' . count($items) . '</span>'
            . '</summary>';

        $out .= '<section class="cards-grid">';

        foreach ($items as $b) {
            $href = (string)($b['href'] ?? '');
            $name = (string)($b['name'] ?? '');

            $lifespan = (string)($b['lifespan'] ?? '');
            $county   = (string)($b['birth_county'] ?? '');
            $country  = (string)($b['country'] ?? '');
            $rel      = (string)($b['relationship'] ?? '');
            $wtUrl    = trim((string)($b['wikitree_url'] ?? ''));

            $badgeHtml = '';
            if ($country !== '') {
                $code = strtolower(trim($country));
                switch ($code) {
                    case 'scotland':
                        $file = 'flags/scotland.svg';
                        break;
                    case 'england':
                        $file = 'flags/england.svg';
                        break;
                    case 'wales':
                        $file = 'flags/wales.svg';
                        break;
                    case 'ireland':
                        $file = 'flags/nireland.svg';
                        break;
                    default:
                        $file = '';
                }
                if ($file !== '') {
                    $badgeHtml = '<span class="badge badge-flag">'
                        . '<img src="assets/img/' . h($file) . '" alt="' . h($country) . ' flag">'
                        . '</span>';
                }
            }


            $metaBits = [];
            if ($lifespan !== '') $metaBits[] = $lifespan;
            if ($county   !== '') $metaBits[] = $county;

            $metaLine = $metaBits ? implode(' · ', array_map(static fn($value): string => h((string) $value), $metaBits)) : '';

            $wtBtn = '';
            if ($wtUrl !== '') {
                $wtBtn = '<a class="jwg-btn jwg-btn-wikitree" href="'
                    . h($wtUrl)
                    . '" target="_blank" rel="noopener">WikiTree</a>';
            }

            $out .= '<article class="bio-card">'
                // row 1
                .  '<div class="bio-card-row bio-card-row-1">'
                .    '<div class="bio-card-main">'
                .      '<h2 class="bio-card-title"><a href="' . h($href) . '">' . h($name) . '</a></h2>'
                .    ($metaLine !== '' ? '<span class="bio-card-meta">' . $metaLine . '</span>' : '')
                .    '</div>'
                .    '<div class="bio-card-side">'
                .      $badgeHtml
                .      ($wtBtn !== '' ? $wtBtn : '')
                .    '</div>'
                .  '</div>'
                // row 2
                .  '<div class="bio-card-row bio-card-row-2">'
                .    ($rel !== '' ? '<div class="bio-card-rel">' . h($rel) . '</div>' : '<div class="bio-card-rel jwg-text-muted">Relationship not recorded yet.</div>')
                .  '</div>'
                . '</article>';
        }

        $out .= '</section></details>';
    }

    return $out;
}

function bp_inline_svg_icon(string $iconPath, string $class = 'spotlight-icon', string $label = ''): string
{
    static $cache = [];
    $iconPath = trim($iconPath);
    if ($iconPath === '') {
        return '';
    }

    // Very small safety: disallow traversal and non-svg
    if (str_contains($iconPath, '..') || !str_ends_with(strtolower($iconPath), '.svg')) {
        return '';
    }

    // Resolve to filesystem path. Assumes $iconPath is relative to your public web root.
    // If your icons live under /public/assets/... this will work.
    $fullPath = rtrim($GLOBALS['siteRoot'], '/') . '/' . ltrim($iconPath, '/');

    if ($fullPath === '' || !is_file($fullPath)) {
        return '';
    }

    // Cache by full path + class (class affects output)
    $cacheKey = $fullPath . '|' . $class . '|' . $label;
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $svg = @file_get_contents($fullPath);
    if (!is_string($svg) || $svg === '') {
        return $cache[$cacheKey] = '';
    }

    // Remove XML/doctype (keeps markup clean for inline injection)
    $svg = preg_replace('/<\?xml[^>]*\?>/i', '', $svg) ?? $svg;
    $svg = preg_replace('/<!doctype[^>]*>/i', '', $svg) ?? $svg;

    // Ensure we have an <svg ...> root
    if (!preg_match('/<svg\b[^>]*>/i', $svg)) {
        return $cache[$cacheKey] = '';
    }

    // Strip width/height attributes so CSS controls size
    $svg = preg_replace('/\s(width|height)="[^"]*"/i', '', $svg) ?? $svg;

    // Add/merge class on <svg ...>
    $svg = preg_replace_callback(
        '/<svg\b([^>]*)>/i',
        function ($m) use ($class, $label) {
            $attrs = $m[1] ?? '';

            // class
            if (preg_match('/\bclass="([^"]*)"/i', $attrs, $cm)) {
                $existing = trim($cm[1]);
                $merged   = trim($existing . ' ' . $class);
                $attrs    = preg_replace('/\bclass="[^"]*"/i', 'class="' . htmlspecialchars($merged, ENT_QUOTES) . '"', $attrs) ?? $attrs;
            } else {
                $attrs .= ' class="' . htmlspecialchars($class, ENT_QUOTES) . '"';
            }

            // accessibility
            if ($label !== '') {
                // Decorative off; labelled on
                if (!preg_match('/\baria-label="/i', $attrs)) {
                    $attrs .= ' aria-label="' . htmlspecialchars($label, ENT_QUOTES) . '"';
                }
                if (!preg_match('/\brole="/i', $attrs)) {
                    $attrs .= ' role="img"';
                }
            } else {
                if (!preg_match('/\baria-hidden="/i', $attrs)) {
                    $attrs .= ' aria-hidden="true"';
                }
            }

            // prevent focus in some browsers
            if (!preg_match('/\bfocusable="/i', $attrs)) {
                $attrs .= ' focusable="false"';
            }
            return '<svg' . $attrs . '>';
        },
        $svg,
        1
    ) ?? $svg;

    // Trim outer whitespace
    $svg = trim($svg);

    return $cache[$cacheKey] = $svg;
}

/**
 * Scan spotlights from registry divisions output rules.
 *
 * - Looks at each catalogue entry and its divisions[]
 * - For each division with output.dir and output.pattern, glob files
 * - Produces a list of spotlight items suitable for site_manifest['spotlights']
 * - Preserves existing titles/metadata where possible by matching href
 *
 * @param array $registry full registry array (with ['catalogue'])
 * @param string $siteRoot filesystem root of johns-genealogy-quest
 * @param array $existingSpotlights existing manifest spotlights list
 * @return array rebuilt spotlight list
 */
function rebuild_spotlights_from_registry(array $registry, string $siteRoot, array $existingSpotlights): array
{

    $catalogue = (array)($registry['catalogue'] ?? []);
    if (!$catalogue) return [];

    // index existing by href for metadata carry-over
    $existingByHref = [];
    foreach ($existingSpotlights as $sp) {
        if (!is_array($sp)) continue;
        $href = trim((string)($sp['href'] ?? ''));
        if ($href === '') continue;
        $existingByHref[$href] = $sp;
    }

    $out = [];
    foreach ($catalogue as $cat) {
        if (!is_array($cat)) continue;
        $catKey = trim((string)($cat['key'] ?? ''));
        if ($catKey === '') continue;

        $divisions = (array)($cat['divisions'] ?? []);
        if (!$divisions) continue; // nothing to scan

        foreach ($divisions as $div) {
            if (!is_array($div)) continue;
            $divKey = trim((string)($div['key'] ?? ''));
            if ($divKey === '') continue;

            $output = (array)($div['output'] ?? []);
            $dir    = trim((string)($output['dir'] ?? ''));
            $pattern = trim((string)($output['pattern'] ?? ''));
            $description = $div['description'];

            if ($dir === '' || $pattern === '') continue;

            $fsDir = rtrim($siteRoot, '/') . '/' . ltrim($dir, '/');
            if (!is_dir($fsDir)) continue;

            $glob = $fsDir . '/' . $pattern;
            $files = glob($glob) ?: [];
            foreach ($files as $fullPath) {
                if (!is_string($fullPath) || $fullPath === '' || !is_file($fullPath)) continue;

                // href relative to site root
                $rel = str_replace('\\', '/', substr($fullPath, strlen(rtrim($siteRoot, '/')) + 1));
                $href = ltrim($rel, '/');

                // Carry forward existing metadata if present
                $prev = $existingByHref[$href] ?? [];

                // Derive a reasonable fallback title from filename
                $title = (string)($prev['title'] ?? '');
                if ($title === '') {
                    $base = basename($href);
                    $base = preg_replace('/\.html?$/i', '', $base) ?? $base;
                    $base = str_replace(['_', '-'], ' ', $base);
                    $base = preg_replace('/\s+/', ' ', $base) ?? $base;
                    $title = trim($base);
                    if ($title !== '') $title = ucwords($title);
                }

                $item = [
                    'type'        => (string)($prev['type'] ?? 'spotlight'),
                    'catalog_key' => (string)($prev['catalog_key'] ?? $prev['catalogue_key'] ?? $catKey),
                    'division_key' => (string)($prev['division_key'] ?? $prev['division'] ?? $divKey),
                    'title'       => $title,
                    'description' => $description,
                    'href'        => $href,
                ];

                $linkTitle = html_meta_content_from_file($fullPath, 'jwg:link_title');
                if ($linkTitle !== '') {
                    $item['link_title'] = $linkTitle;
                }


                // Preserve optional fields (metric/name/wikitree_url/etc) if they exist
                foreach (['metric', 'name', 'wikitree_url', 'label', 'link_title'] as $k) {
                    if (isset($prev[$k]) && !isset($item[$k])) $item[$k] = $prev[$k];
                }

                $out[] = $item;
            }
        }
    }

    // Stable sorting: catalogue_key, division_key, title
    usort($out, function ($a, $b) {
        $ak = is_array($a) ? (string)($a['catalog_key'] ?? '') : '';
        $bk = is_array($b) ? (string)($b['catalog_key'] ?? '') : '';
        $c = strcasecmp($ak, $bk);
        if ($c !== 0) return $c;

        $ad = is_array($a) ? (string)($a['division_key'] ?? '') : '';
        $bd = is_array($b) ? (string)($b['division_key'] ?? '') : '';
        $c = strcasecmp($ad, $bd);
        if ($c !== 0) return $c;

        $at = is_array($a) ? (string)($a['title'] ?? '') : '';
        $bt = is_array($b) ? (string)($b['title'] ?? '') : '';
        return strcasecmp($at, $bt);
    });

    return $out;
}

/**
 * Discover current story preview pages.
 *
 * Only stories/<slug>/preview.html is eligible. Redirect shims, indexes,
 * PDFs and other supporting files are deliberately excluded.
 */
function discover_story_previews(string $siteRoot): array
{
    $files = glob(rtrim($siteRoot, '/') . '/stories/*/preview.html') ?: [];
    $stories = [];

    foreach ($files as $file) {
        if (!is_file($file)) continue;

        $relative = ltrim(str_replace('\\', '/', substr($file, strlen(rtrim($siteRoot, '/')))), '/');
        if ($relative === '') continue;

        $html = @file_get_contents($file);
        if ($html === false) {
            warn('STORY_READ_FAILED', 'Story preview could not be read.', ['file' => $file]);
            continue;
        }

        $title = '';
        if (preg_match('~<h1\\b[^>]*>(.*?)</h1>~is', $html, $m)) {
            $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        if ($title === '' && preg_match('~<title\\b[^>]*>(.*?)</title>~is', $html, $m)) {
            $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $title = preg_replace('~\\s+[–—-]\\s+.*$~u', '', $title) ?: $title;
        }
        if ($title === '') {
            $title = ucwords(str_replace(['-', '_'], ' ', basename(dirname($file))));
        }

        $stories[] = [
            'href' => $relative,
            'title' => $title,
            'kiosk_label' => $title,
        ];
    }

    usort($stories, static fn(array $a, array $b): int =>
        strcasecmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''))
    );

    return $stories;
}

/**
 * Build a kiosk manifest derived from site_manifest.
 *
 * This is intentionally conservative:
 * - It preserves kiosk-manifest "shape" if an existing file is present.
 * - If existing kiosk manifest is an object with 'pages', we replace pages.
 * - If existing kiosk manifest is a plain array, we replace it with the pages array.
 *
 * Pages are produced as a list of href strings, in the order:
 *   current spotlights then current story preview pages.
 */
function write_kiosk_manifest_from_site_manifest(string $siteRoot, array $manifest): bool
{
    $kioskPath = rtrim($siteRoot, '/') . '/kiosk-manifest.json';

    $spots = (array)($manifest['spotlights'] ?? []);
    $stories = (array)($manifest['stories'] ?? []);

    $pages  = [];
    $titles = []; // [href => label]

    foreach ($spots as $sp) {
        if (!is_array($sp)) continue;

        $href = trim((string)($sp['href'] ?? ''));
        if ($href === '') continue;
        if (!is_file(rtrim($siteRoot, '/') . '/' . ltrim($href, '/'))) {
            warn('KIOSK_PAGE_MISSING', 'Spotlight omitted because its HTML file does not exist.', ['href' => $href]);
            continue;
        }

        $pages[] = $href;

        // Prefer an explicit kiosk label if you add one later, else link_title/title.
        $label = trim((string)($sp['kiosk_label'] ?? $sp['link_title'] ?? $sp['title'] ?? ''));
        if ($label !== '') {
            $titles[$href] = $label;
        }
    }

    foreach ($stories as $story) {
        if (!is_array($story)) continue;

        $href = trim((string)($story['href'] ?? ''));
        if ($href === '') continue;
        if (!is_file(rtrim($siteRoot, '/') . '/' . ltrim($href, '/'))) {
            warn('KIOSK_PAGE_MISSING', 'Story omitted because its HTML file does not exist.', ['href' => $href]);
            continue;
        }

        $pages[] = $href;

        $label = trim((string)($story['kiosk_label'] ?? $story['link_title'] ?? $story['title'] ?? ''));
        if ($label !== '') {
            $titles[$href] = $label;
        }
    }

    // De-dup pages while preserving order (also prune empty hrefs)
    $seen = [];
    $pages = array_values(array_filter($pages, function ($h) use (&$seen) {
        $h = trim((string)$h);
        if ($h === '') return false;
        if (isset($seen[$h])) return false;
        $seen[$h] = true;
        return true;
    }));

    // Randomise kiosk page order (stable until manifest regenerated)
    // Fisher–Yates shuffle using random_int() for good randomness
    for ($i = count($pages) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        if ($i !== $j) {
            [$pages[$i], $pages[$j]] = [$pages[$j], $pages[$i]];
        }
    }

    // Keep titles aligned to pages (remove labels for pages we didn't include)
    $pageSet = array_fill_keys($pages, true);
    foreach (array_keys($titles) as $href) {
        if (!isset($pageSet[$href])) unset($titles[$href]);
    }

    $existing = null;
    if (is_file($kioskPath)) {
        $raw = @file_get_contents($kioskPath);
        $existing = $raw ? json_decode($raw, true) : null;
    }

    // Always prefer object form going forward (needed for titles).
    // If an existing object exists, preserve unknown keys (future settings) but overwrite pages/titles.
    if (is_array($existing) && !array_is_list($existing)) {
        $existing['schema_version'] = (string)($existing['schema_version'] ?? 'kiosk-manifest-2');
        $existing['pages']  = $pages;
        $existing['titles'] = $titles;
        $payload = $existing;
    } else {
        $payload = [
            'schema_version' => 'kiosk-manifest-2',
            'pages'  => $pages,
            'titles' => $titles,
        ];
    }

    @file_put_contents($kioskPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return true;
}



function render_spotlights_index(array $publishedSpotlights, array $catalogue): string
{
    // Index published spotlights by catalogue key AND (optionally) division key
    $pubByKey = [];
    $pubByDiv = [];
    foreach ($publishedSpotlights as $sp) {
        if (!is_array($sp)) continue;

        $catKey = (string)($sp['catalog_key'] ?? $sp['catalogue_key'] ?? '');
        if ($catKey !== '') {
            $pubByKey[$catKey][] = $sp;
        }

        // Newer manifests may include a division key
        $divKey = (string)($sp['division_key'] ?? $sp['division'] ?? '');
        if ($catKey !== '' && $divKey !== '') {
            $pubByDiv[$catKey . ':' . $divKey][] = $sp;
        }
    }

    // Sort catalogue by sort then title
    usort($catalogue, function ($a, $b) {
        $sa = is_array($a) ? (int)($a['sort'] ?? 0) : 0;
        $sb = is_array($b) ? (int)($b['sort'] ?? 0) : 0;
        if ($sa !== $sb) return $sa <=> $sb;
        $at = is_array($a) ? (string)($a['title'] ?? '') : '';
        $bt = is_array($b) ? (string)($b['title'] ?? '') : '';
        return strcasecmp($at, $bt);
    });

    $out = '<header class="page-header">
  <h1>Spotlights</h1>
  <p class="jwg-text-muted">Curated highlights exploring the people, places, events, and everyday details that shaped our ancestors’ lives — with the occasional curious fact thrown in for fun.</p>
</header>';

    if (!$catalogue) {
        // Fallback: show any published items without catalogue
        if (!$publishedSpotlights) {
            $out .= '<p class="jwg-text-muted">No spotlights listed yet.</p>';
            return $out;
        }

        $out .= '<section class="cards-grid">';
        foreach ($publishedSpotlights as $sp) {
            if (!is_array($sp)) continue;
            $href = safe_rel_href((string)($sp['href'] ?? ''));
            $title = (string)($sp['title'] ?? $sp['label'] ?? '');
            if ($href === '' || $title === '') continue;

            $metric = (string)($sp['metric'] ?? '');
            $name   = (string)($sp['name'] ?? '');
            $wtUrl  = trim((string)($sp['wikitree_url'] ?? ''));

            $out .= '<article class="jwg-card">'
                .  '<h2 class="jwg-card-title"><a href="' . h($href) . '">' . h($title) . '</a></h2>'
                .  ($metric !== '' ? '<p><strong>' . h($metric) . '</strong></p>' : '')
                .  ($name !== '' ? '<p class="jwg-text-muted">' . h($name) . '</p>' : '')
                .  ($wtUrl !== '' ? '<div class="card-actions"><a class="jwg-btn jwg-btn-wikitree" href="' . h($wtUrl) . '" target="_blank" rel="noopener">WikiTree</a></div>' : '')
                .  '</article>';
        }
        $out .= '</section>';
        return $out;
    }

    // Render each catalogue entry using a registry-driven renderer.
    foreach ($catalogue as $cat) {
        if (!is_array($cat)) continue;
        $out .= render_spotlights_catalogue_entry($cat, $pubByKey, $pubByDiv);
    }

    return $out;
}

/**
 * Resolve which renderer function to use for a catalogue entry.
 * Registry can provide: "index_renderer": "render_index_card_press_media"
 * Fallback: map by key, else default.
 */
function resolve_spotlights_index_renderer(array $cat): string
{
    $r = trim((string)($cat['index_renderer'] ?? ''));
    if ($r !== '') return $r;

    $key = (string)($cat['key'] ?? '');
    $map = [
        'press_media' => 'render_index_card_press_media',
        'longevity'   => 'render_index_card_longevity',
        'origins'     => 'render_index_card_origins',
        'zodiac'      => 'render_index_card_zodiac',
        'plantagenet' => 'render_index_card_plantagenet',
    ];

    return $map[$key] ?? 'render_index_card_default';
}

/**
 * Dispatch: render one catalogue entry by calling the selected renderer.
 * Uses an allow-list so a typo (or unexpected value) doesn't call arbitrary PHP.
 */
function render_spotlights_catalogue_entry(array $cat, array $pubByKey, array $pubByDiv): string
{
    $allowed = [
        'render_index_card_default'     => true,
        'render_index_card_press_media' => true,
        'render_index_card_longevity'   => true,
        'render_index_card_origins'     => true,
        'render_index_card_zodiac'      => true,
        'render_index_card_plantagenet' => true,
    ];

    $fn = resolve_spotlights_index_renderer($cat);
    $qualifiedFn = __NAMESPACE__ . '\\' . $fn;
    if (!isset($allowed[$fn]) || !function_exists($qualifiedFn)) {
        warn('SPOTLIGHTS_INDEX_BAD_RENDERER', 'Spotlights index renderer not found/allowed.', [
            'renderer' => $fn,
            'catalogue_key' => (string)($cat['key'] ?? ''),
        ]);
        $fn = 'render_index_card_default';
        $qualifiedFn = __NAMESPACE__ . '\\' . $fn;
    }

    $html = $qualifiedFn($cat, $pubByKey, $pubByDiv);

    $rendererClass = 'spot-cat--' . str_replace('_', '-', (string)($cat['index_renderer'] ?? ''));
    if ($rendererClass !== 'spot-cat--') {
        $html = str_replace(
            'class="spot-cat-group ',
            'class="spot-cat-group ' . h($rendererClass) . ' ',
            $html
        );
    }

    return $html;
}

/**
 * Default catalogue renderer: uses the existing layout and link lists.
 * Other renderers can be customised later without touching the index builder.
 */
function render_index_card_default(array $cat, array $pubByKey, array $pubByDiv): string
{
    $key   = (string)($cat['key'] ?? '');
    $title = (string)($cat['title'] ?? $key);
    if ($key === '' || $title === '') return '';

    $desc   = (string)($cat['description'] ?? '');
    $status = (string)($cat['status'] ?? 'planned');

    $iconPath  = (string)($cat['icon'] ?? '');
    $divisions = $cat['divisions'] ?? [];
    if (!is_array($divisions)) $divisions = [];

    $items = (array)($pubByKey[$key] ?? []);

    // Sort published items under this catalogue entry (fallback list)
    usort($items, function ($a, $b) {
        $at = is_array($a) ? (string)($a['title'] ?? '') : '';
        $bt = is_array($b) ? (string)($b['title'] ?? '') : '';
        return strcasecmp($at, $bt);
    });

    // Sort divisions by sort then title (if present)
    if ($divisions) {
        usort($divisions, function ($a, $b) {
            $sa = is_array($a) ? (int)($a['sort'] ?? 0) : 0;
            $sb = is_array($b) ? (int)($b['sort'] ?? 0) : 0;
            if ($sa !== $sb) return $sa <=> $sb;
            $at = is_array($a) ? (string)($a['title'] ?? '') : '';
            $bt = is_array($b) ? (string)($b['title'] ?? '') : '';
            return strcasecmp($at, $bt);
        });
    }

    // Badge: show count of published pages for this catalogue (across divisions if present)
    $pubCount = 0;
    if ($divisions) {
        foreach ($divisions as $div) {
            if (!is_array($div)) continue;
            $divKey = (string)($div['key'] ?? '');
            if ($divKey === '') continue;
            $pubCount += count((array)($pubByDiv[$key . ':' . $divKey] ?? []));
        }

        // Back-compat: if manifest has no division_key, items live only in $items
        if ($pubCount === 0 && $items) {
            $pubCount = count($items);
        }
    } else {
        $pubCount = count($items);
    }

    $badgeParts = [];
    if ($status !== '' && $status !== 'implemented') {
        $badgeParts[] = ucfirst($status);
    }
    if ($pubCount > 0) {
        $badgeParts[] = $pubCount . ' item' . ($pubCount === 1 ? '' : 's');
    }

    $badge = $badgeParts
        ? '<span class="badge badge-country">' . h(implode(' · ', $badgeParts)) . '</span>'
        : '';

    // Optional teaser image (generic hook; specialised renderers can use differently)
    $teaserImg = trim((string)($cat['index_teaser_image'] ?? ''));
    $teaserAlt = (string)($cat['index_teaser_alt'] ?? '');
    $teaserHtml = '';
    if ($teaserImg !== '') {
        $teaserHtml = '<div class="spot-cat-teaser">'
            . '<img class="spot-cat-teaser-img" src="' . h(safe_rel_href($teaserImg)) . '" alt="' . h($teaserAlt) . '">'
            . '</div>';
    }

    // --------- OPEN CARD WRAPPERS ---------
    $out  = '<section class="spot-cat-group spot-cat--' . h($key) . '" data-status="' . h($status) . '">'
        .  '<div class="spot-cat-grid">';

    // Icon column
    $out .= '<div class="spot-cat-icon-col">';
    if ($iconPath !== '') {
        $out .= '<img class="spot-cat-icon" src="' . h(safe_rel_href($iconPath)) . '" alt="">';
    } else {
        $out .= '<span class="spot-cat-icon-spacer" aria-hidden="true"></span>';
    }
    $out .= '</div>';

    // Main column (title/desc + divisions/list)
    $out .= '<div class="spot-cat-main">'
        .    '<div class="spot-cat-title-row">'
        .      '<h2 class="spot-cat-title">' . h($title) . '</h2>'
        .      '<div class="spot-cat-badge">' . $badge . '</div>'
        .    '</div>'
        .    ($desc !== '' ? '<p class="spot-cat-desc">' . h($desc) . '</p>' : '')
        .    $teaserHtml;

    // --------- CONTENT ---------
    if ($divisions) {

        $out .= '<div class="spot-cat-items spot-cat-divisions">';

        foreach ($divisions as $div) {
            if (!is_array($div)) continue;

            $divKey   = (string)($div['key'] ?? '');
            $divTitle = (string)($div['title'] ?? '');
            if ($divKey === '' || $divTitle === '') continue;

            $divItems = (array)($pubByDiv[$key . ':' . $divKey] ?? []);

            // Back-compat: if no division_key exists in manifest yet, show all items under first division only
            if (!$divItems && empty($pubByDiv) && $divKey === (string)($divisions[0]['key'] ?? '')) {
                $divItems = $items;
            }

            usort($divItems, function ($a, $b) {
                $at = is_array($a) ? (string)($a['title'] ?? '') : '';
                $bt = is_array($b) ? (string)($b['title'] ?? '') : '';
                return strcasecmp($at, $bt);
            });

            $out .= '<article class="spot-div-card">'
                .    '<div class="spot-div-head">'
                .      '<h3 class="spot-div-title">' . h($divTitle) . '</h3>'
                .    '</div>';

            if (!$divItems) {
                $out .= '<div class="jwg-text-muted spot-div-coming">Coming soon.</div>';
            } else {
                $out .= '<ul class="spotlight-link-list">';
                foreach ($divItems as $it) {
                    if (!is_array($it)) continue;

                    $itTitle = trim((string)($it['link_title'] ?? $it['title'] ?? ''));
                    $itHref  = safe_rel_href((string)($it['href'] ?? ''));
                    if ($itTitle === '' || $itHref === '') continue;

                    $out .= '<li class="spotlight-link-item">'
                        .    '<a class="spotlight-link" href="' . h($itHref) . '">' . h($itTitle) . '</a>'
                        .  '</li>';
                }
                $out .= '</ul>';
            }

            $out .= '</article>';
        }

        $out .= '</div>'; // .spot-cat-divisions

    } else {
        // No divisions defined yet: show either published items under this catalogue, or coming soon.
        if (!$items) {
            $out .= '<div class="jwg-text-muted spot-cat-coming">Coming soon.</div>';
        } else {
            $out .= '<div class="spot-cat-items">'
                .    '<ul class="spotlight-link-list">';
            foreach ($items as $it) {
                if (!is_array($it)) continue;

                $itTitle = trim((string)($it['link_title'] ?? $it['title'] ?? ''));
                $itHref  = safe_rel_href((string)($it['href'] ?? ''));
                if ($itTitle === '' || $itHref === '') continue;

                $out .= '<li class="spotlight-link-item">'
                    .    '<a class="spotlight-link" href="' . h($itHref) . '">' . h($itTitle) . '</a>'
                    .  '</li>';
            }
            $out .= '</ul></div>';
        }
    }

    // Close wrappers
    $out .= '</div>';  // .spot-cat-main
    $out .= '</div>';  // .spot-cat-grid
    $out .= '</section>';

    return $out;
}

function render_index_card_press_media(array $cat, array $pubByKey, array $pubByDiv): string
{
    $key   = (string)($cat['key'] ?? 'press_media');
    $title = (string)($cat['title'] ?? 'In The News');
    $desc  = (string)($cat['description'] ?? '');
    $status = (string)($cat['status'] ?? 'planned');
    $iconPath  = (string)($cat['icon'] ?? '');

    $divisions = $cat['divisions'] ?? [];
    if (!is_array($divisions) || !$divisions) {
        return render_index_card_default($cat, $pubByKey, $pubByDiv);
    }

    // Build a lookup: mediaID -> href (from published manifest items)
    $items = (array)($pubByKey[$key] ?? []);
    $hrefByMedia = [];
    foreach ($items as $it) {
        if (!is_array($it)) continue;
        $href = safe_rel_href((string)($it['href'] ?? ''));
        if ($href === '') continue;

        $mediaID = (int)($it['mediaID'] ?? 0);
        if ($mediaID <= 0) {
            // fallback: extract from filename press_media_1234.html
            $mediaID = press_media_id_from_href($href);
        }
        if ($mediaID > 0) $hrefByMedia[$mediaID] = $href;
    }

    // Count implemented pages that have hrefs
    $pubCount = 0;
    foreach ($divisions as $div) {
        if (!is_array($div)) continue;
        $pages = $div['pages'] ?? [];
        if (!is_array($pages)) continue;
        foreach ($pages as $p) {
            if (!is_array($p)) continue;
            $mid = (int)($p['mediaID'] ?? 0);
            if ($mid > 0 && isset($hrefByMedia[$mid])) $pubCount++;
        }
    }

    $badgeParts = [];
    if ($status !== '' && $status !== 'implemented') $badgeParts[] = ucfirst($status);
    if ($pubCount > 0) $badgeParts[] = $pubCount . ' article' . ($pubCount === 1 ? '' : 's');
    $badge = $badgeParts ? '<span class="badge badge-country">' . h(implode(' · ', $badgeParts)) . '</span>' : '';

    $out  = '<section class="spot-cat-group spot-cat--' . h($key) . '" data-status="' . h($status) . '">'
        .  '<div class="spot-cat-grid">';

    // Icon column
    $out .= '<div class="spot-cat-icon-col">';
    if ($iconPath !== '') {
        $out .= '<img class="spot-cat-icon" src="' . h(safe_rel_href($iconPath)) . '" alt="">';
    } else {
        $out .= '<span class="spot-cat-icon-spacer" aria-hidden="true"></span>';
    }
    $out .= '</div>';

    // Main
    $out .= '<div class="spot-cat-main">'
        .    '<div class="spot-cat-title-row">'
        .      '<h2 class="spot-cat-title">' . h($title) . '</h2>'
        .      '<div class="spot-cat-badge">' . $badge . '</div>'
        .    '</div>'
        .    ($desc !== '' ? '<p class="spot-cat-desc">' . h($desc) . '</p>' : '');

    // Render each division as a compact “news list”
    foreach ($divisions as $div) {
        if (!is_array($div)) continue;
        $divTitle = trim((string)($div['title'] ?? ''));
        $pages = $div['pages'] ?? [];
        if (!is_array($pages)) $pages = [];

        if ($divTitle !== '') {
            $out .= '<div class="press-div-title">' . h($divTitle) . '</div>';
        }

        // sort pages by sort then headline
        usort($pages, function($a, $b) {
            $sa = is_array($a) ? (int)($a['sort'] ?? 0) : 0;
            $sb = is_array($b) ? (int)($b['sort'] ?? 0) : 0;
            if ($sa !== $sb) return $sa <=> $sb;
            $ah = is_array($a) ? (string)($a['headline'] ?? '') : '';
            $bh = is_array($b) ? (string)($b['headline'] ?? '') : '';
            return strcasecmp($ah, $bh);
        });

        $out .= '<div class="press-list">';
        foreach ($pages as $p) {
            if (!is_array($p)) continue;
            if (($p['status'] ?? '') === 'planned') continue;

            $mid = (int)($p['mediaID'] ?? 0);
            if ($mid <= 0) continue;

            $href = $hrefByMedia[$mid] ?? '';
            $headline = trim((string)($p['headline'] ?? ''));
            $standfirst = trim((string)($p['standfirst'] ?? ''));
            $masthead = trim((string)($p['masthead'] ?? ''));

            // fallback if headline missing
            if ($headline === '') $headline = 'Article ' . $mid;

            $chips = '';
            $prots = $p['protagonists'] ?? [];
            if (is_array($prots) && $prots) {
                $chipParts = [];
                foreach ($prots as $prot) {
                    if (!is_array($prot)) continue;
                    $labels = $prot['labels'] ?? [];
                    $john = is_array($labels) ? trim((string)($labels['john'] ?? '')) : '';
                    $chris = is_array($labels) ? trim((string)($labels['chris'] ?? '')) : '';
                    if ($john !== '') $chipParts[] = h($john);
                    if ($chris !== '') $chipParts[] = h($chris);
                }
                if ($chipParts) {
                    $chips = '<div class="press-chips">';
                    foreach ($chipParts as $txt) {
                        $chips .= '<span class="press-chip">' . $txt . '</span>';
                    }
                    $chips .= '</div>';
                }
            }

            $out .= '<article class="press-item">';

            if ($masthead !== '') {
                $out .= '<div class="press-thumb">'
                    .  '<img src="' . h(safe_rel_href($masthead)) . '" alt="">'
                    .  '</div>';
            } else {
                $out .= '<div class="press-thumb press-thumb--empty" aria-hidden="true"></div>';
            }

            $out .= '<div class="press-body">';

            if ($href !== '') {
                $out .= '<a class="press-headline" href="' . h($href) . '">' . h($headline) . '</a>';
            } else {
                $out .= '<div class="press-headline press-headline--disabled">' . h($headline) . '</div>';
            }

            if ($standfirst !== '') {
                $out .= '<div class="press-standfirst">' . h($standfirst) . '</div>';
            }

            $out .= $chips;

            $out .= '</div>'; // body
            $out .= '</article>';
        }
        $out .= '</div>'; // list
    }

    $out .= '</div>'; // main
    $out .= '</div>'; // grid
    $out .= '</section>';

    return $out;
}

function press_media_id_from_href(string $href): int
{
    $path = parse_url($href, PHP_URL_PATH);
    if (!$path) $path = $href;
    $base = basename($path);
    if (preg_match('/press_media_(\d+)\.html$/', $base, $m)) {
        return (int)$m[1];
    }
    return 0;
}


function render_index_card_longevity(array $cat, array $pubByKey, array $pubByDiv): string
{
    // Custom, viewer-friendly index layout for Longevity:
    // group by line and show Female/Male winners per line, using name + lifespan as link text.

    $key   = (string)($cat['key'] ?? 'longevity');
    $title = (string)($cat['title'] ?? 'Longevity');
    if ($key === '') $key = 'longevity';

    $desc   = (string)($cat['description'] ?? '');
    $status = (string)($cat['status'] ?? 'planned');
    $iconPath  = (string)($cat['icon'] ?? '');

    $divisions = $cat['divisions'] ?? [];
    if (!is_array($divisions) || !$divisions) {
        return render_index_card_default($cat, $pubByKey, $pubByDiv);
    }

    $items = (array)($pubByKey[$key] ?? []);

    // Index published longevity items by (line_key, sex) with safe fallbacks.
    $byLine = []; // e.g. $byLine['gilroy']['F'] = item
    $overall = null;

    foreach ($items as $it) {
        if (!is_array($it)) continue;
        $divKey = (string)($it['division_key'] ?? '');

        // Prefer explicit fields added by the generator.
        $lineKey = trim((string)($it['line_key'] ?? ''));
        $sex     = strtoupper(trim((string)($it['sex'] ?? '')));

        // Fallback: parse division_key like "gilroy_f", "wilson_m", "overall"
        if ($lineKey === '' && $divKey !== '') {
            if ($divKey === 'overall') {
                $lineKey = 'overall';
            } else {
                $parts = explode('_', $divKey);
                if (count($parts) >= 2) {
                    $sexGuess = strtoupper((string)array_pop($parts));
                    $lineGuess = strtolower(implode('_', $parts));
                    if (($sexGuess === 'F' || $sexGuess === 'M') && $lineGuess !== '') {
                        $lineKey = $lineGuess;
                        $sex = $sexGuess;
                    }
                }
            }
        }

        if ($lineKey === 'overall') {
            $overall = $it;
            continue;
        }

        if ($lineKey !== '' && ($sex === 'F' || $sex === 'M')) {
            $byLine[$lineKey][$sex] = $it;
        }
    }

    // Helper: pick the most user-friendly link text.
    $bestLinkText = function (array $it): string {
        $t = trim((string)($it['winner_link_title'] ?? ''));
        if ($t !== '') return $t;

        $name = trim((string)($it['winner_name'] ?? $it['name'] ?? ''));
        $life = trim((string)($it['winner_lifespan'] ?? $it['lifespan'] ?? ''));
        if ($name !== '' && $life !== '') return $name . ' (' . $life . ')';
        if ($name !== '') return $name;

        $t = trim((string)($it['link_title'] ?? ''));
        if ($t !== '') return $t;

        return trim((string)($it['title'] ?? 'View'));
    };

    // Map line_key -> display label from the registry divisions (so titles stay human-friendly).
    $lineLabels = [];
    foreach ($divisions as $div) {
        if (!is_array($div)) continue;
        $dkey = (string)($div['key'] ?? '');
        $dtitle = (string)($div['title'] ?? '');
        if ($dkey === '' || $dtitle === '') continue;

        if ($dkey === 'overall') {
            $lineLabels['overall'] = $dtitle;
            continue;
        }

        // If your division keys are exactly like "gilroy_f", extract the line key.
        $parts = explode('_', $dkey);
        if (count($parts) >= 2) {
            $sexGuess = strtoupper((string)array_pop($parts));
            $lineGuess = strtolower(implode('_', $parts));
            if (($sexGuess === 'F' || $sexGuess === 'M') && $lineGuess !== '') {
                // Prefer the shared portion of the title (you can tweak later if needed)
                $lineLabels[$lineGuess] = $lineLabels[$lineGuess] ?? preg_replace('/\s*-\s*(Male|Female)\s*$/i', '', $dtitle);
            }
        }
    }

    // Badge: number of available views (overall + per-sex per-line)
    $pubCount = ($overall ? 1 : 0);
    foreach ($byLine as $line => $pair) {
        if (isset($pair['F'])) $pubCount++;
        if (isset($pair['M'])) $pubCount++;
    }

    $badgeParts = [];
    if ($status !== '' && $status !== 'implemented') $badgeParts[] = ucfirst($status);
    if ($pubCount > 0) $badgeParts[] = $pubCount . ' view' . ($pubCount === 1 ? '' : 's');
    $badge = $badgeParts ? '<span class="badge badge-country">' . h(implode(' · ', $badgeParts)) . '</span>' : '';

    $out  = '<section class="spot-cat-group spot-cat--' . h($key) . '" data-status="' . h($status) . '">'
        .  '<div class="spot-cat-grid">';

    // Icon column
    $out .= '<div class="spot-cat-icon-col">';
    if ($iconPath !== '') {
        $out .= '<img class="spot-cat-icon" src="' . h(safe_rel_href($iconPath)) . '" alt="">';
    } else {
        $out .= '<span class="spot-cat-icon-spacer" aria-hidden="true"></span>';
    }
    $out .= '</div>';

    // Main column
    $out .= '<div class="spot-cat-main">'
        .    '<div class="spot-cat-title-row">'
        .      '<h2 class="spot-cat-title">' . h($title) . '</h2>'
        .      '<div class="spot-cat-badge">' . $badge . '</div>'
        .    '</div>'
        .    ($desc !== '' ? '<p class="spot-cat-desc">' . h($desc) . '</p>' : '');



    // Overall winner tile (if present)
    $out .= '<div class="spot-cat-items spot-cat-tiles spot-cat-tiles--longevity">';

    if ($overall && is_array($overall)) {
        $href = safe_rel_href((string)($overall['href'] ?? ''));
        $personTxt = $bestLinkText($overall);

        $sexSym = '';
        $sexKey = strtoupper(trim((string)($overall['sex'] ?? '')));
        if ($sexKey === 'F') $sexSym = '♀';
        if ($sexKey === 'M') $sexSym = '♂';

        $pageTitle = trim((string)($overall['title'] ?? 'Longest Lived Overall'));
        if ($pageTitle === '') $pageTitle = 'Longest Lived Overall';

        $out .= '<div class="spot-line-group">'
            .    '<div class="spot-line-title">' . h($pageTitle) . '</div>';

        if ($personTxt !== '' && $href !== '') {
            $out .= '<a class="spot-tile-name" href="' . h($href) . '">'
                .  ($sexSym !== '' ? '<span class="spot-line-sex" aria-hidden="true">' . h($sexSym) . '</span>' : '')
                .  '<span class="spot-line-winner">' . h($personTxt) . '</span>'
                .  '</a>';
        } elseif ($personTxt !== '') {
            $out .= '<div class="spot-tile-name">' . h($personTxt) . '</div>';
        }

        $out .= '</div>';
    }

    // Grouped lines: each line renders a “row” tile with Female/Male links.
    ksort($byLine);
    foreach ($byLine as $lineKey => $pair) {
        $label = $lineLabels[$lineKey] ?? ucfirst($lineKey);
        $out .= '<div class="spot-line-group">'
            .    '<div class="spot-line-title">' . h($label) . '</div>'
            .    '<div class="spot-line-links">';

        foreach (['F' => '♀', 'M' => '♂'] as $sex => $sexLabel) {

            $it = $pair[$sex] ?? null;
            if (!$it || !is_array($it)) {
                $out .= '<span class="spot-line-link spot-line-link--disabled" aria-disabled="true">'
                    .  h($sexLabel) . ' <span class="jwg-text-muted">Coming soon</span>'
                    .  '</span>';
                continue;
            }

            $href = safe_rel_href((string)($it['href'] ?? ''));
            $txt  = $bestLinkText($it);
            if ($href === '' || $txt === '') {
                $out .= '<span class="spot-line-link spot-line-link--disabled" aria-disabled="true">'
                    .  h($sexLabel) . ' <span class="jwg-text-muted">Coming soon</span>'
                    .  '</span>';
                continue;
            }

            $out .= '<a class="spot-line-link" href="' . h($href) . '">'
                .  '<span class="spot-line-sex">' . h($sexLabel) . '</span> '
                .  '<span class="spot-line-winner">' . h($txt) . '</span>'
                .  '</a>';
        }

        $out .= '</div></div>';
    }

    $out .= '</div>'; // tiles wrapper
    $out .= '</div>'; // main
    $out .= '</div>'; // grid
    $out .= '</section>';

    return $out;
}

function render_index_card_origins(array $cat, array $pubByKey, array $pubByDiv): string
{
    $key   = (string)($cat['key'] ?? 'origins');
    $title = (string)($cat['title'] ?? 'Origins');
    $desc  = (string)($cat['description'] ?? '');
    $status = (string)($cat['status'] ?? 'planned');
    $iconPath  = (string)($cat['icon'] ?? '');

    $divisions = $cat['divisions'] ?? [];
    if (!is_array($divisions) || !$divisions) {
        return render_index_card_default($cat, $pubByKey, $pubByDiv);
    }

    // Find a shared map thumbnail (prefer the division output.map).
    $map = '';
    foreach ($divisions as $div) {
        if (!is_array($div)) continue;
        $out = $div['output'] ?? [];
        if (is_array($out)) {
            $m = trim((string)($out['map'] ?? ''));
            if ($m !== '') { $map = $m; break; }
        }
    }
    $mapAlt = 'UK map';

    // Map published items by division_key so we can link each division.
    $items = (array)($pubByKey[$key] ?? []);
    $hrefByDiv = [];
    foreach ($items as $it) {
        if (!is_array($it)) continue;
        $dk = (string)($it['division_key'] ?? '');
        $href = safe_rel_href((string)($it['href'] ?? ''));
        if ($dk !== '' && $href !== '') $hrefByDiv[$dk] = $href;
    }

    // Sort divisions by sort then title
    usort($divisions, function($a, $b) {
        $sa = is_array($a) ? (int)($a['sort'] ?? 0) : 0;
        $sb = is_array($b) ? (int)($b['sort'] ?? 0) : 0;
        if ($sa !== $sb) return $sa <=> $sb;
        $at = is_array($a) ? (string)($a['title'] ?? '') : '';
        $bt = is_array($b) ? (string)($b['title'] ?? '') : '';
        return strcasecmp($at, $bt);
    });

    $pubCount = 0;
    foreach ($divisions as $div) {
        if (!is_array($div)) continue;
        $dk = (string)($div['key'] ?? '');
        if ($dk !== '' && isset($hrefByDiv[$dk])) $pubCount++;
    }

    $badgeParts = [];
    if ($status !== '' && $status !== 'implemented') $badgeParts[] = ucfirst($status);
    if ($pubCount > 0) $badgeParts[] = $pubCount . ' view' . ($pubCount === 1 ? '' : 's');
    $badge = $badgeParts ? '<span class="badge badge-country">' . h(implode(' · ', $badgeParts)) . '</span>' : '';

    $out  = '<section class="spot-cat-group spot-cat--' . h($key) . '" data-status="' . h($status) . '">'
        .  '<div class="spot-cat-grid">';

    $out .= '<div class="spot-cat-icon-col">';
    if ($iconPath !== '') {
        $out .= '<img class="spot-cat-icon" src="' . h(safe_rel_href($iconPath)) . '" alt="">';
    } else {
        $out .= '<span class="spot-cat-icon-spacer" aria-hidden="true"></span>';
    }
    $out .= '</div>';

    $out .= '<div class="spot-cat-main">'
        .    '<div class="spot-cat-title-row">'
        .      '<h2 class="spot-cat-title">' . h($title) . '</h2>'
        .      '<div class="spot-cat-badge">' . $badge . '</div>'
        .    '</div>'
        .    ($desc !== '' ? '<p class="spot-cat-desc">' . h($desc) . '</p>' : '');

    $out .= '<div class="origins-index">';

    if ($map !== '') {
        $out .= '<div class="origins-index-map">'
            .  '<img src="' . h(safe_rel_href($map)) . '" alt="' . h($mapAlt) . '">'
            .  '</div>';
    }

    $out .= '<div class="origins-index-list">';
    foreach ($divisions as $div) {
        if (!is_array($div)) continue;
        $dk = (string)($div['key'] ?? '');
        $t  = trim((string)($div['title'] ?? ''));
        if ($dk === '' || $t === '') continue;

        $href = $hrefByDiv[$dk] ?? '';
        $dsc  = trim((string)($div['description'] ?? ''));

        $out .= '<div class="origins-index-item">';
        if ($href !== '') {
            $out .= '<a class="origins-index-link" href="' . h($href) . '">' . h($t) . '</a>';
        } else {
            $out .= '<div class="origins-index-link origins-index-link--disabled">' . h($t) . '</div>';
        }
        if ($dsc !== '') {
            $out .= '<div class="origins-index-desc">' . h($dsc) . '</div>';
        }
        $out .= '</div>';
    }
    $out .= '</div>'; // list
    $out .= '</div>'; // origins-index

    $out .= '</div></div></section>';

    return $out;
}

function render_index_card_zodiac(array $cat, array $pubByKey, array $pubByDiv): string
{
    $key   = (string)($cat['key'] ?? 'zodiac');
    $title = (string)($cat['title'] ?? 'Star Signs');
    $desc  = (string)($cat['description'] ?? '');
    $status = (string)($cat['status'] ?? 'planned');
    $iconPath  = (string)($cat['icon'] ?? '');

    $divisions = $cat['divisions'] ?? [];
    if (!is_array($divisions) || !$divisions) {
        return render_index_card_default($cat, $pubByKey, $pubByDiv);
    }

    // Pick a shared hero image if present on any division (your registry has it on all three).
    $wheel = '';
    $wheelAlt = 'Zodiac Wheel';
    foreach ($divisions as $div) {
        if (!is_array($div)) continue;
        $img = trim((string)($div['hero_image'] ?? ''));
        if ($img !== '') { $wheel = $img; }
        $alt = trim((string)($div['hero_alt'] ?? ''));
        if ($alt !== '') { $wheelAlt = $alt; }
        if ($wheel !== '') break;
    }

    // Map published items by division_key so we can link each division.
    $items = (array)($pubByKey[$key] ?? []);
    $hrefByDiv = [];
    foreach ($items as $it) {
        if (!is_array($it)) continue;
        $dk = (string)($it['division_key'] ?? '');
        $href = safe_rel_href((string)($it['href'] ?? ''));
        if ($dk !== '' && $href !== '') $hrefByDiv[$dk] = $href;
    }

    // Sort divisions by sort then title
    usort($divisions, function($a, $b) {
        $sa = is_array($a) ? (int)($a['sort'] ?? 0) : 0;
        $sb = is_array($b) ? (int)($b['sort'] ?? 0) : 0;
        if ($sa !== $sb) return $sa <=> $sb;
        $at = is_array($a) ? (string)($a['title'] ?? '') : '';
        $bt = is_array($b) ? (string)($b['title'] ?? '') : '';
        return strcasecmp($at, $bt);
    });

    // Badge: number of available views
    $pubCount = 0;
    foreach ($divisions as $div) {
        if (!is_array($div)) continue;
        $dk = (string)($div['key'] ?? '');
        if ($dk !== '' && isset($hrefByDiv[$dk])) $pubCount++;
    }

    $badgeParts = [];
    if ($status !== '' && $status !== 'implemented') $badgeParts[] = ucfirst($status);
    if ($pubCount > 0) $badgeParts[] = $pubCount . ' view' . ($pubCount === 1 ? '' : 's');
    $badge = $badgeParts ? '<span class="badge badge-country">' . h(implode(' · ', $badgeParts)) . '</span>' : '';

    $out  = '<section class="spot-cat-group spot-cat--' . h($key) . '" data-status="' . h($status) . '">'
        .  '<div class="spot-cat-grid">';

    // Icon column
    $out .= '<div class="spot-cat-icon-col">';
    if ($iconPath !== '') {
        $out .= '<img class="spot-cat-icon" src="' . h(safe_rel_href($iconPath)) . '" alt="">';
    } else {
        $out .= '<span class="spot-cat-icon-spacer" aria-hidden="true"></span>';
    }
    $out .= '</div>';

    // Main column
    $out .= '<div class="spot-cat-main">'
        .    '<div class="spot-cat-title-row">'
        .      '<h2 class="spot-cat-title">' . h($title) . '</h2>'
        .      '<div class="spot-cat-badge">' . $badge . '</div>'
        .    '</div>'
        .    ($desc !== '' ? '<p class="spot-cat-desc">' . h($desc) . '</p>' : '');

    $out .= '<div class="zodiac-index">';
    if ($wheel !== '') {
        $out .= '<div class="zodiac-index-wheel">'
            .  '<img src="' . h(safe_rel_href($wheel)) . '" alt="' . h($wheelAlt) . '">'
            .  '</div>';
    }

    $out .= '<div class="zodiac-index-list">';
    foreach ($divisions as $div) {
        if (!is_array($div)) continue;
        $dk = (string)($div['key'] ?? '');
        $t  = trim((string)($div['title'] ?? ''));
        if ($dk === '' || $t === '') continue;

        $href = $hrefByDiv[$dk] ?? '';
        $dsc  = trim((string)($div['description'] ?? ''));

        $out .= '<div class="zodiac-index-item">';
        if ($href !== '') {
            $out .= '<a class="zodiac-index-link" href="' . h($href) . '">' . h($t) . '</a>';
        } else {
            $out .= '<div class="zodiac-index-link zodiac-index-link--disabled">' . h($t) . '</div>';
        }
        if ($dsc !== '') {
            $out .= '<div class="zodiac-index-desc">' . h($dsc) . '</div>';
        }
        $out .= '</div>';
    }
    $out .= '</div>'; // list
    $out .= '</div>'; // zodiac-index

    $out .= '</div>'; // main
    $out .= '</div>'; // grid
    $out .= '</section>';

    return $out;
}

function render_index_card_plantagenet(array $cat, array $pubByKey, array $pubByDiv): string
{
    $key   = (string)($cat['key'] ?? 'plantagenet');
    $title = (string)($cat['title'] ?? 'The Royal Connection');
    $desc  = (string)($cat['description'] ?? '');
    $status = (string)($cat['status'] ?? 'planned');
    $iconPath  = (string)($cat['icon'] ?? '');

    $divisions = $cat['divisions'] ?? [];
    if (!is_array($divisions) || !$divisions) {
        return render_index_card_default($cat, $pubByKey, $pubByDiv);
    }

    // Single division expected
    $div = is_array($divisions[0] ?? null) ? $divisions[0] : null;
    if (!$div) return render_index_card_default($cat, $pubByKey, $pubByDiv);

    $divKey   = (string)($div['key'] ?? '');
    $divTitle = trim((string)($div['title'] ?? 'View'));
    $divDesc  = trim((string)($div['description'] ?? ''));

    // Link from manifest (rebuilt from registry + filesystem scan)
    $href = '';
    $items = (array)($pubByKey[$key] ?? []);
    foreach ($items as $it) {
        if (!is_array($it)) continue;
        if ((string)($it['division_key'] ?? '') !== $divKey) continue;
        $href = safe_rel_href((string)($it['href'] ?? ''));
        if ($href !== '') break;
    }

    // Portrait only (discard chart image)
    $img = trim((string)($div['hero_image'] ?? ''));
    $imgAlt = trim((string)($div['hero_alt'] ?? ''));
    if ($imgAlt === '') $imgAlt = 'Edward III of England';

    $badgeParts = [];
    if ($status !== '' && $status !== 'implemented') $badgeParts[] = ucfirst($status);
    if ($href !== '') $badgeParts[] = '1 view';
    $badge = $badgeParts ? '<span class="badge badge-country">' . h(implode(' · ', $badgeParts)) . '</span>' : '';

    $out  = '<section class="spot-cat-group spot-cat--' . h($key) . '" data-status="' . h($status) . '">'
        .  '<div class="spot-cat-grid">';

    $out .= '<div class="spot-cat-icon-col">';
    if ($iconPath !== '') {
        $out .= '<img class="spot-cat-icon" src="' . h(safe_rel_href($iconPath)) . '" alt="">';
    } else {
        $out .= '<span class="spot-cat-icon-spacer" aria-hidden="true"></span>';
    }
    $out .= '</div>';

    $out .= '<div class="spot-cat-main">'
        .    '<div class="spot-cat-title-row">'
        .      '<h2 class="spot-cat-title">' . h($title) . '</h2>'   // NOT linked (as desired)
        .      '<div class="spot-cat-badge">' . $badge . '</div>'
        .    '</div>'
        .    ($desc !== '' ? '<p class="spot-cat-desc">' . h($desc) . '</p>' : '');

    $out .= '<div class="plantagenet-index">';

    if ($img !== '') {
        $out .= '<div class="plantagenet-index-media">'
            .  '<img src="' . h(safe_rel_href($img)) . '" alt="' . h($imgAlt) . '">'
            .  '</div>';
    }

    $out .= '<div class="plantagenet-index-body">';

    // Division headline as the link (consistent with other spotlights)
    if ($href !== '') {
        $out .= '<a class="plantagenet-index-link" href="' . h($href) . '">' . h($divTitle) . '</a>';
    } else {
        $out .= '<div class="plantagenet-index-link plantagenet-index-link--disabled">' . h($divTitle) . '</div>';
    }

    if ($divDesc !== '') {
        $out .= '<div class="plantagenet-index-desc">' . h($divDesc) . '</div>';
    }

    $out .= '</div>'; // body
    $out .= '</div>'; // plantagenet-index

    $out .= '</div></div></section>';

    return $out;
}



// The Stories index is maintained by the current story publishing workflow.
// This generator deliberately leaves stories/index.html untouched.
$spotsHtml = render_site_shell(
    $baseHref,
    'Spotlights – John’s Genealogy Quest',
    'spotlights',
    render_spotlights_index($spots, $catalogue),
    $now,
    [
        'assets/css/jwg-spotlight.css',
        'assets/css/jwg-spotlights-index.css'
    ]
);

$spotsIndexPath = $siteRoot . '/spotlights/index.html';
@mkdir(dirname($spotsIndexPath), 0777, true);
$okSpots = file_put_contents($spotsIndexPath, $spotsHtml) !== false;

echo "<h3>Website index and kiosk generation</h3>";
echo "<ul>";
echo "<li>Manifest: <code>" . h($manifestPath) . "</code></li>";
echo "<li>Stories discovered: " . count($stories) . "</li>";
echo "<li>Stories index: preserved (not generated by this script)</li>";
echo "<li>Spotlights index: <code>" . h($spotsIndexPath) . "</code> — " . ($okSpots ? "OK" : "FAILED") . "</li>";
echo "<li>Kiosk manifest: <code>" . h($siteRoot . '/kiosk-manifest.json') . "</code> — " . (!empty($kioskOk) ? "OK" : "FAILED") . "</li>";

if ($generatorWarnings) {
    echo "<li>Warnings: " . count($generatorWarnings) . "</li>";
}

echo "</ul>";

if ($generatorWarnings) {
    echo "<h4>Warnings</h4><pre>" . h(print_r($generatorWarnings, true)) . "</pre>";
}

if (!$okSpots || !$kioskOk) {
    echo "<pre>" . h(print_r(error_get_last(), true)) . "</pre>";
}

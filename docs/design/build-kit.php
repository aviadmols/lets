<?php

/**
 * Builds the storefront UI kit — the sheet a designer works from.
 *
 * The kit does NOT contain a copy of the component CSS. It contains MARKERS,
 * and this script inlines the canonical stylesheets at build time. That is the
 * whole point: a kit with its own copy of the CSS is a kit that silently drifts
 * from the shop, and a designer redlining a stale sheet costs more than no
 * sheet at all. Re-run this after any change to the three stylesheets.
 *
 *   <php84> docs/design/build-kit.php
 *
 * Two outputs from one template:
 *   storefront-kit.html           standalone document (open it in a browser)
 *   storefront-kit.artifact.html  body-only, for publishing as an Artifact
 *   html/<CODE>-<slug>.html       ONE element per file, each one standalone
 *
 * The per-element files are EXTRACTED from the same template, never typed a
 * second time. There is one source of truth for the markup and one for the
 * styling, and both of them are the shop's.
 *
 * @package LETS
 */

// === CONSTANTS ===

const ROOT = __DIR__ . '/../..';
const TEMPLATE = __DIR__ . '/kit.template.html';
const OUT_STANDALONE = __DIR__ . '/storefront-kit.html';
const OUT_ARTIFACT = __DIR__ . '/storefront-kit.artifact.html';
const OUT_ELEMENTS = __DIR__ . '/html';
const MARKER = '/<!--CSS:([^>]+?)-->/';

/**
 * The members-club page is a WHOLE PAGE, not a component: loyalty.css declares
 * its tokens on `:root` and styles `html, body`, because in production it is
 * served on its own URL (App Proxy) or inside an iframe on WooCommerce.
 *
 * Inlining it into the catalogue as-is would restyle the catalogue. So on the
 * SHEET it is wrapped in `@scope (.k-lc-frame){…}`, which confines every rule
 * to the specimen and drops the `:root` / `html, body` rules (neither is a
 * descendant of the scope root). The tokens those rules would have set are
 * re-declared on `.k-lc-frame` in the sheet's own chrome.
 *
 * The per-element file gets the stylesheet VERBATIM — there it IS the page.
 */
const MARKER_SCOPED = '/<!--CSS-SCOPED:([^|>]+?)\|([^>]+?)-->/';
const TITLE_TAG = '/<title>(.*?)<\/title>\s*/s';
const FALLBACK_TITLE = 'LETS — Storefront UI Kit';

/** Which canonical stylesheet each family of elements needs. */
const SHEETS = [
    'LA' => 'public/account/lets-account.css',
    'PPU' => 'plugins/lets-payplus-woocommerce/assets/css/lets-ppu.css',
    'PP' => 'plugins/lets-payplus-woocommerce/assets/css/lets.css',
    'LC' => 'public/css/loyalty.css',
];

/**
 * Plates that need more than their family's stylesheet. The thank-you upsell
 * is drawn by lets-ppu.css but MOUNTED in a container lets.css owns, and the
 * container's own margin is part of how the card sits in the page.
 */
const PLATE_SHEETS = [
    'PPU-06' => [
        'plugins/lets-payplus-woocommerce/assets/css/lets-ppu.css',
        'plugins/lets-payplus-woocommerce/assets/css/lets.css',
    ],
];

/** A readable filename per plate — the code alone tells a designer nothing. */
const SLUGS = [
    'LA-01' => 'full-page',
    'LA-02' => 'navigation',
    'LA-03' => 'hero-and-stats',
    'LA-04' => 'subscription-card',
    'LA-05' => 'subscription-states',
    'LA-06' => 'timeline',
    'LA-07' => 'loyalty-card',
    'LA-08' => 'support-and-banners',
    'LA-09' => 'primitives',
    'LA-10' => 'system-states',
    'LA-11' => 'woocommerce-screens',
    'LA-12' => 'hebrew-rtl-dark',
    'LA-13' => 'hebrew-account-page',
    'PPU-01' => 'upsell-card',
    'PPU-02' => 'upsell-media-side',
    'PPU-03' => 'upsell-dark-outline',
    'PPU-04' => 'upsell-states',
    'PPU-06' => 'hebrew-thankyou-upsell',
    'PP-01' => 'deposit-widget',
    'PP-02' => 'subscription-choice',
    'PP-03' => 'upsell-legacy',
    'LC-01' => 'loyalty-club-page',
    'LC-02' => 'referral-program',
    'LC-03' => 'loyalty-states',
];

/**
 * The only styling a per-element file adds on top of the shop's own CSS.
 * Every rule here exists because the element is being shown OUT of the shop,
 * and each one says so.
 */
const HOST_CSS = <<<'CSS'
/* --- host page only: this is the sheet the element is pinned to, never
       part of the component. Delete it when you copy the markup out. --- */
html { -webkit-text-size-adjust: 100%; }
body {
    margin: 0 auto;
    padding: 40px clamp(16px, 4vw, 48px);
    /* A card shown edge to edge on a 2000px screen is not the card the shopper
       sees. Full-page specimens opt out with data-wide. */
    max-inline-size: 940px;
    background: #ffffff;
    color: #14161a;
    /* The account CSS carries no font-family on purpose — it inherits the
       merchant's theme. This stands in for that theme. */
    font-family: "Segoe UI", "Noto Sans Hebrew", Arial, system-ui, sans-serif;
    line-height: 1.55;
}
body[data-wide] { max-inline-size: 1480px; }
body[data-ground="dark"] { background: #0d0f12; color: #f3f4f6; }
body[data-ground="paper"] { background: #f4f5f7; }
/* Shown without WooCommerce's navigation beside it, so the shell is one column. */
.k-onecol { grid-template-columns: minmax(0, 1fr) !important; }
/* A toast is position:fixed in the shop; pinned here so it stays on the page. */
.k-pin .la-toast { position: static; transform: none; display: inline-block; }

/* --- specimen labels. On a plate that is a matrix of variants the label is
       half the information, so unlike the catalogue's captions these ship. --- */
.k-group {
    margin: 0 0 18px;
    padding-block-end: 8px;
    border-block-end: 1px solid rgba(128, 128, 128, 0.28);
    font-size: 0.8125rem;
    font-weight: 700;
    letter-spacing: 0.04em;
}
.k-gallery {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 28px 24px;
    align-items: start;
    margin-block-end: 44px;
}
.k-gallery:last-child { margin-block-end: 0; }
.k-item { min-inline-size: 0; }
.k-item--wide { grid-column: 1 / -1; }
.k-label {
    display: block;
    margin-block-end: 10px;
    font-family: ui-monospace, Consolas, monospace;
    font-size: 0.6875rem;
    letter-spacing: 0.06em;
    color: #8b929c;
}

/* --- tab scaffolding. In the shop each tab is its own page load; the sheet
       keeps them in one file so the screens can be browsed. Nothing here
       ships — .k-tab wrappers are the catalogue's, not the product's. --- */
.k-tab[hidden] { display: none; }
CSS;

/**
 * The palette block LoyaltyPagePresenter renders server-side into the club
 * page's <head>. Reproduced for the standalone file so the exported page has
 * a merchant's brand on it rather than the stylesheet's fallback purple.
 */
const LC_HOST_CSS = <<<'CSS'
/* --- host page only: this is the block the SERVER renders into the club
       page, from the merchant's appearance settings. --- */
:root {
    --lets-accent: #0f766e;
    --lets-accent-fg: #ffffff;
    --lets-radius: 16px;
}
.lc-tier[data-tier="0"] { --lets-tier: #b08968; }
.lc-tier[data-tier="1"] { --lets-tier: #9aa3ad; }
.lc-tier[data-tier="2"] { --lets-tier: #d4af37; }
.lc-progress__fill { inline-size: 62%; }

/* --- specimen labels (catalogue, not product) --- */
.k-group {
    margin: 0 0 18px;
    padding-block-end: 8px;
    border-block-end: 1px solid var(--lets-line);
    font-size: 15px;
    font-weight: 700;
}
.k-gallery { display: grid; gap: 32px; margin-block-end: 40px; }
.k-label {
    display: block;
    margin-block-end: 10px;
    font-family: ui-monospace, Consolas, monospace;
    font-size: 11px;
    letter-spacing: 0.06em;
    color: var(--lets-muted);
}
CSS;

/** Browsing affordance for the sheet and for the exported files alike. */
const HOST_JS = <<<'JS'
/* The shop serves each account tab as its own page load. The sheet keeps them
   all in one document so the screens can be browsed — this is catalogue
   scaffolding and ships with nothing. */
document.querySelectorAll('.lets-shell').forEach(function (shell) {
    var panels = shell.querySelectorAll('.k-tab');
    var links = shell.querySelectorAll('.la-nav a[href^="#tab-"]');
    if (panels.length === 0) { return; }

    function open(link) {
        var id = link.getAttribute('href').slice(1);
        panels.forEach(function (panel) { panel.hidden = panel.id !== id; });
        links.forEach(function (other) {
            other.parentNode.classList.toggle('is-active', other === link);
        });
    }

    links.forEach(function (link) {
        link.addEventListener('click', function (event) {
            event.preventDefault();
            open(link);
        });
    });
});
JS;

$template = @file_get_contents(TEMPLATE);

if (false === $template) {
    fwrite(STDERR, "cannot read " . TEMPLATE . "\n");
    exit(1);
}

/* --- inline every canonical stylesheet ---------------------------------- */

$missing = [];

$template = preg_replace_callback(MARKER_SCOPED, static function (array $m) use (&$missing): string {
    $relative = trim($m[1]);
    $scope = trim($m[2]);
    $path = ROOT . '/' . $relative;

    if (! is_file($path)) {
        $missing[] = $relative;

        return '';
    }

    return "\n/* ============================================================\n"
        . "   {$relative} — inlined verbatim, confined to {$scope} so a\n"
        . "   whole-page stylesheet cannot restyle the catalogue around it.\n"
        . "   ============================================================ */\n"
        . "@scope ({$scope}) {\n"
        . file_get_contents($path)
        . "\n}\n";
}, $template);

$body = preg_replace_callback(MARKER, static function (array $m) use (&$missing): string {
    $relative = trim($m[1]);
    $path = ROOT . '/' . $relative;

    if (! is_file($path)) {
        $missing[] = $relative;

        return '';
    }

    return "\n/* ============================================================\n"
        . "   {$relative} — inlined verbatim by docs/design/build-kit.php\n"
        . "   ============================================================ */\n"
        . file_get_contents($path);
}, $template);

if ($missing) {
    fwrite(STDERR, "missing stylesheet(s): " . implode(', ', $missing) . "\n");
    exit(1);
}

/* --- split the title out for the standalone document's <head> ----------- */

$title = FALLBACK_TITLE;

if (preg_match(TITLE_TAG, $body, $m)) {
    $title = trim($m[1]);
}

$standaloneBody = preg_replace(TITLE_TAG, '', $body, 1);

$standalone = "<!doctype html>\n"
    . "<html lang=\"he\">\n"
    . "<head>\n"
    . "<meta charset=\"utf-8\">\n"
    . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
    . '<title>' . $title . "</title>\n"
    . "</head>\n"
    . "<body>\n"
    . $standaloneBody
    . "\n</body>\n</html>\n";

file_put_contents(OUT_STANDALONE, $standalone);
file_put_contents(OUT_ARTIFACT, $body);

/* --- one standalone file per element ------------------------------------ */

$written = emitElements($body);

printf(
    "built:\n  %s (%d KB)\n  %s (%d KB)\n  html/ (%d elements)\n",
    basename(OUT_STANDALONE),
    (int) round(strlen($standalone) / 1024),
    basename(OUT_ARTIFACT),
    (int) round(strlen($body) / 1024),
    count($written)
);

/**
 * Cuts every specimen out of the built sheet and writes it as its own page.
 *
 * @param  string $html the built sheet
 * @return array<int, array{code: string, file: string, title: string}>
 */
function emitElements(string $html): array
{
    if (! is_dir(OUT_ELEMENTS)) {
        mkdir(OUT_ELEMENTS, 0o777, true);
    }

    $doc = Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $written = [];

    foreach ($doc->querySelectorAll('.k-plate') as $plate) {
        $code = (string) $plate->getAttribute('id');
        $stage = $plate->querySelector('.k-stage');

        if (null === $stage || ! isset(SLUGS[$code])) {
            continue;   // a prose-only plate has nothing to cut out
        }

        $titleNode = $plate->querySelector('.k-plate__title');
        $title = null === $titleNode ? $code : trim($titleNode->textContent);

        $written[] = [
            'code' => $code,
            'title' => $title,
            'file' => writeElement($doc, $code, $title, $stage),
        ];
    }

    writeElementIndex($written);

    return $written;
}

/**
 * @param  Dom\HTMLDocument $doc
 * @param  string           $code  e.g. "LA-04"
 * @param  string           $title the plate's Hebrew title
 * @param  Dom\Element      $stage the specimen canvas
 * @return string the filename written
 */
function writeElement(Dom\HTMLDocument $doc, string $code, string $title, Dom\Element $stage): string
{
    $stage = $stage->cloneNode(true);

    /* The captions and the flex wrappers belong to the catalogue, not to the
       component. Strip the captions, then unwrap any layout div the sheet put
       around a specimen so the file opens with the component at its root. */
    foreach ($stage->querySelectorAll('.k-cap') as $caption) {
        $caption->remove();
    }

    for ($pass = 0; $pass < 3; $pass++) {
        foreach (iterator_to_array($stage->childNodes) as $child) {
            if (! $child instanceof Dom\Element || 'div' !== strtolower($child->tagName)) {
                continue;
            }

            $class = (string) $child->getAttribute('class');

            /* A wrapper is a bare layout div the catalogue added: either it
               carries a k-col class, or it has a width/flex style and NO class
               at all. A component root also carries an inline style (its brand
               tokens) — it is told apart by having a class. */
            $isWrapper = str_contains($class, 'k-col')
                || str_contains($class, 'k-lc-frame')
                || ('' === $class && $child->hasAttribute('style'));

            if (! $isWrapper) {
                continue;
            }

            foreach (iterator_to_array($child->childNodes) as $grandChild) {
                $stage->insertBefore($grandChild, $child);
            }
            $child->remove();
        }
    }

    $markup = '';
    foreach ($stage->childNodes as $child) {
        $markup .= $doc->saveHtml($child);
    }
    $markup = formatMarkup($markup);

    $family = explode('-', $code)[0];
    $sheets = PLATE_SHEETS[$code] ?? [SHEETS[$family] ?? SHEETS['LA']];

    $css = '';
    foreach ($sheets as $sheet) {
        $css .= "\n/* ===== {$sheet} ===== */\n" . file_get_contents(ROOT . '/' . $sheet);
    }

    $dir = $stage->getAttribute('dir') ?: 'ltr';
    $ground = $stage->getAttribute('data-ground');
    $pin = str_contains($markup, 'la-toast') ? ' k-pin' : '';
    /* Whole-screen specimens need the room; a single card does not. */
    $wide = in_array($code, ['LA-01', 'LA-11', 'LA-12', 'LA-13'], true) ? ' data-wide' : '';

    $page = "<!doctype html>\n"
        . '<html lang="he" dir="' . $dir . "\">\n"
        . "<head>\n"
        . "<meta charset=\"utf-8\">\n"
        . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
        . '<title>' . $code . ' — ' . htmlspecialchars($title, ENT_QUOTES) . "</title>\n"
        . "\n<!-- ===== the shop's own stylesheet(s), inlined verbatim ===== -->\n"
        . "<style>" . $css . "\n</style>\n"
        . "\n<style>\n" . ('LC' === $family ? LC_HOST_CSS : HOST_CSS) . "\n</style>\n"
        . "</head>\n"
        /* The club page IS the body in production — it takes no host chrome. */
        . ('LC' === $family
            ? "<body>\n\n"
            : '<body' . ($ground ? ' data-ground="' . $ground . '"' : '') . $wide . ' class="lets-el' . $pin . "\">\n\n")
        . trim($markup) . "\n\n"
        . (str_contains($markup, 'k-tab') ? "<script>\n" . HOST_JS . "\n</script>\n\n" : '')
        . "</body>\n</html>\n";

    $file = $code . '-' . SLUGS[$code] . '.html';
    file_put_contents(OUT_ELEMENTS . '/' . $file, $page);

    return $file;
}

/**
 * Re-indents serialised markup so the file a designer opens is readable.
 *
 * Deliberately dumb: the specimens are plain, well-formed elements with no
 * <pre>, no comments and no scripts, so a depth counter is enough and a real
 * formatter would be a dependency for nothing.
 *
 * @param  string $html
 * @return string
 */
function formatMarkup(string $html): string
{
    $flat = trim(preg_replace('/>\s+</', '><', $html));
    $parts = preg_split('/(<[^>]+>)/', $flat, -1, PREG_SPLIT_DELIM_CAPTURE);
    $void = '/^<(img|input|br|hr|meta|link|source|area|col|embed|track|wbr)\b/i';

    $parts = array_values(array_filter($parts, static fn ($p) => '' !== trim($p)));
    $out = '';
    $depth = 0;
    $count = count($parts);

    for ($i = 0; $i < $count; $i++) {
        $part = $parts[$i];

        if ('<' !== $part[0]) {
            $out .= str_repeat('    ', $depth) . trim($part) . "\n";
            continue;
        }

        $closing = str_starts_with($part, '</');
        $selfClosing = str_ends_with($part, '/>') || preg_match($void, $part);

        /* An element whose whole content is one run of text stays on one line.
           Breaking it would insert whitespace an inline element renders. */
        if (! $closing && ! $selfClosing
            && isset($parts[$i + 2])
            && '<' !== $parts[$i + 1][0]
            && str_starts_with($parts[$i + 2], '</')) {
            $out .= str_repeat('    ', $depth) . $part . trim($parts[$i + 1]) . $parts[$i + 2] . "\n";
            $i += 2;
            continue;
        }

        if ($closing) {
            $depth = max(0, $depth - 1);
        }

        $out .= str_repeat('    ', $depth) . $part . "\n";

        if (! $closing && ! $selfClosing) {
            $depth++;
        }
    }

    return rtrim($out);
}

/**
 * @param array<int, array{code: string, file: string, title: string}> $written
 */
function writeElementIndex(array $written): void
{
    $rows = '';

    foreach ($written as $row) {
        $rows .= '<li><a href="' . $row['file'] . '"><code>' . $row['code'] . '</code>'
            . '<span>' . htmlspecialchars($row['title'], ENT_QUOTES) . '</span>'
            . '<em>' . $row['file'] . "</em></a></li>\n";
    }

    $page = <<<HTML
<!doctype html>
<html lang="he" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>LETS — כל אלמנט בקובץ נפרד</title>
<style>
body {
    margin: 0;
    padding: 48px clamp(16px, 4vw, 48px) 96px;
    background: #eceff3;
    color: #14161a;
    font-family: "Segoe UI", "Noto Sans Hebrew", Arial, system-ui, sans-serif;
    line-height: 1.6;
}
h1 { margin: 0 0 8px; font-size: 1.75rem; font-weight: 800; letter-spacing: -0.03em; }
p { margin: 0 0 32px; max-inline-size: 70ch; color: #565e6b; }
p code, li code { font-family: ui-monospace, Consolas, monospace; direction: ltr; }
ul { list-style: none; margin: 0; padding: 0; display: grid; gap: 1px; max-inline-size: 900px; }
a {
    display: grid;
    grid-template-columns: 74px minmax(0, 1fr) auto;
    gap: 16px;
    align-items: baseline;
    padding: 12px 16px;
    background: #fff;
    box-shadow: 0 0 0 1px #e6e9ed;
    color: inherit;
    text-decoration: none;
}
a:hover { box-shadow: 0 0 0 1px #2e4a63; color: #2e4a63; }
a:focus-visible { outline: 2px solid #2e4a63; outline-offset: -2px; }
li code { font-size: 0.6875rem; font-weight: 700; letter-spacing: 0.1em; color: #2e4a63; }
em { font-family: ui-monospace, Consolas, monospace; font-size: 0.6875rem; font-style: normal; color: #8b929c; direction: ltr; }
</style>
</head>
<body>
<h1>כל אלמנט — בקובץ נפרד</h1>
<p>כל קובץ כאן עומד בפני עצמו: ה‑CSS האמיתי של החנות משובץ בתוכו, וה‑markup ב‑<code>&lt;body&gt;</code> הוא בדיוק מה שהלקוח מקבל. אפשר לפתוח כל אחד בדפדפן, לערוך, ולהעתיק החוצה. הקבצים נוצרים אוטומטית מ‑<code>docs/design/kit.template.html</code> — לא לערוך אותם ידנית, השינוי יימחק בבנייה הבאה. הגיליון המלא עם ההסברים: <code>../storefront-kit.html</code>.</p>
<ul>
{$rows}</ul>
</body>
</html>

HTML;

    file_put_contents(OUT_ELEMENTS . '/index.html', $page);
}

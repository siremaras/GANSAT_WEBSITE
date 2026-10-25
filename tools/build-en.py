#!/usr/bin/env python3
"""
Gansat Teknoloji — generates the English pages in /en/ from the Turkish pages.

The Turkish pages already carry every English text in data-en="..." attributes
(the same texts the old in-page EN toggle used). This script turns each Turkish
page into a static English page:

  * every element with data-en gets that English text as its content
  * <html lang="en">, English <title>/meta/Open Graph/Twitter tags, hreflang
  * asset paths rewritten for the en/ sub-folder (../style.css, ../logo.webp ...)
  * English JSON-LD (Organization / Product / FAQPage / BreadcrumbList)
  * datasheet buttons point to the English PDFs
  * alt / aria-label / title texts translated

Run from the repository root after changing a Turkish page:

    python3 tools/build-en.py

This folder (tools/) does not need to be uploaded to the web server.
"""
import html
import json
import os
import re

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SITE = 'https://www.gansat.com.tr/'
PAGES = ['index.html', '20w-sspa.html', '50w-sspa.html', '100w-sspa.html', '200w-sspa.html', '400w-sspa.html']

PRODUCTS = {
    '20': ('+43 dBm', 'portable and mobile satellite uplink'),
    '50': ('+47 dBm', 'VSAT and mid-range satellite uplink'),
    '100': ('+50 dBm', 'VSAT, SNG and fixed satellite uplink'),
    '200': ('+53.2 dBm', 'high-power fixed satellite uplink'),
    '400': ('+56 dBm', 'teleport and high-power satellite uplink'),
}

HOME_META = dict(
    title='Gansat Teknoloji | GaN Ku-Band BUC / SSPA Manufacturer in Türkiye',
    desc="Gansat Teknoloji — Türkiye's first domestic SSPA and BUC manufacturer. "
         "GaN-based 20W–400W Ku-Band BUC/SSPA models, designed and built in Tuzla, Istanbul.",
    kw='SSPA manufacturer, BUC manufacturer, Ku-Band BUC, GaN SSPA, Block Up Converter, '
       'satellite uplink, VSAT BUC, Ku-Band amplifier, Türkiye, Gansat Teknoloji',
    img='og-gansat-en.jpg',
    alt='Gansat Teknoloji — GaN Ku-Band BUC / SSPA solutions, 20W–400W',
)

# attribute texts that have no data-en counterpart
ATTR_TEXT = [
    ('alt="GANSAT Teknoloji logosu"', 'alt="GANSAT Teknoloji logo"'),
    ('alt="Gansat Teknoloji üretim tesisi – sokak görünümü"', 'alt="Gansat Teknoloji production facility – street view"'),
    ('alt="Gansat Teknoloji tesisi ve uydu antenleri"', 'alt="Gansat Teknoloji facility and satellite antennas"'),
    ('aria-label="Menü"', 'aria-label="Menu"'),
    ('aria-label="Kapat"', 'aria-label="Close"'),
    ('title="Gansat Teknoloji konumu – Google Haritalar"', 'title="Gansat Teknoloji location – Google Maps"'),
]
ATTR_RE = [
    (r'(alt="GANSAT-\d+WKU \d+W Ku-Band GaN SSPA) – görsel (\d)"', r'\1 – image \2"'),
    (r'aria-label="(\d+W SSPA) – detaylar"', r'aria-label="\1 – details"'),
]


def apply_data_en(s):
    """Replace the content of every element that has data-en (what the old setLang('en') did)."""
    # attribute values may themselves contain markup (data-tr="...<br>..."), so match quoted values explicitly
    start_tag = re.compile(r'<([a-zA-Z][a-zA-Z0-9]*)((?:\s+[^\s=>/"]+(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>"\']+))?)*)\s*/?>')
    starts = []
    for m in start_tag.finditer(s):
        a = re.search(r'\sdata-en="([^"]*)"', m.group(2))
        if a:
            starts.append((m, a.group(1)))
    for m, value in reversed(starts):   # inner elements first; an outer data-en overwrites them, as in the DOM
        tag = m.group(1).lower()
        depth, pos = 1, m.end()
        pat = re.compile(r'<(/?)' + tag + r'\b[^>]*?(/?)>', re.I)
        while depth:
            t = pat.search(s, pos)
            if not t:
                raise ValueError(f'unclosed <{tag}> near: {s[m.start():m.start()+80]}')
            if t.group(1):
                depth -= 1
            elif not t.group(2):
                depth += 1
            pos = t.end()
        close_start = s.rfind('</', 0, pos)
        s = s[:m.end()] + value + s[close_start:]
    return s


def rewrite_paths(s):
    """Point assets one folder up; keep links between pages inside en/."""
    def fix(m):
        attr, val = m.group(1), m.group(2)
        if re.match(r'^(https?:|//|#|mailto:|tel:|data:|javascript:)', val):
            return m.group(0)
        path = val.split('#')[0]
        if path in PAGES or path == '':
            return m.group(0)
        return f'{attr}="../{val}"'
    return re.sub(r'\b(src|href|action|data-pdf-tr|data-pdf-en)="([^"]*)"', fix, s)


def meta_block(page, s, nl):
    w = None if page == 'index.html' else page.split('w-')[0]
    tr_url = SITE if not w else SITE + page
    en_url = SITE + 'en/' if not w else SITE + 'en/' + page
    if w:
        dbm, use = PRODUCTS[w]
        title = html.unescape(re.search(r'<title>(.*?)</title>', s).group(1))
        desc = f'GANSAT-{w}WKU {w}W Ku-Band GaN BUC / SSPA: {dbm}, 13.75–14.75 GHz. Domestically manufactured in Türkiye for {use}.'
        kw = (f'{w}W BUC, {w}W SSPA, GANSAT-{w}WKU, GANSAT-{w}WEXTKU, Ku-Band BUC, GaN SSPA, '
              'Block Up Converter, satellite uplink, Gansat Teknoloji')
        img, alt, typ = f'og-{w}w.jpg', f'GANSAT-{w}WKU {w}W Ku-Band GaN SSPA / BUC', 'product'
    else:
        title, desc, kw, img, alt = (HOME_META[k] for k in ('title', 'desc', 'kw', 'img', 'alt'))
        typ = 'website'
    e = lambda x: html.escape(x, quote=True).replace('&#x27;', "'")
    lines = [
        f'    <meta name="description" content="{e(desc)}">',
        f'    <meta name="keywords" content="{e(kw)}">',
        '    <meta name="author" content="Gansat Teknoloji">',
        '    <meta name="robots" content="index, follow, max-image-preview:large">',
        '    <meta name="theme-color" content="#19375F">',
        f'    <link rel="canonical" href="{en_url}">',
        f'    <link rel="alternate" hreflang="tr" href="{tr_url}">',
        f'    <link rel="alternate" hreflang="en" href="{en_url}">',
        f'    <link rel="alternate" hreflang="x-default" href="{en_url}">',
        '',
        '    <!-- Open Graph (LinkedIn, WhatsApp, Facebook) -->',
        f'    <meta property="og:type" content="{typ}">',
        '    <meta property="og:site_name" content="Gansat Teknoloji">',
        f'    <meta property="og:title" content="{e(title)}">',
        f'    <meta property="og:description" content="{e(desc)}">',
        f'    <meta property="og:url" content="{en_url}">',
        f'    <meta property="og:image" content="{SITE}{img}">',
        '    <meta property="og:image:type" content="image/jpeg">',
        '    <meta property="og:image:width" content="1200">',
        '    <meta property="og:image:height" content="630">',
        f'    <meta property="og:image:alt" content="{e(alt)}">',
        '    <meta property="og:locale" content="en_US">',
        '    <meta property="og:locale:alternate" content="tr_TR">',
        '',
        '    <!-- Twitter / X Card -->',
        '    <meta name="twitter:card" content="summary_large_image">',
        f'    <meta name="twitter:title" content="{e(title)}">',
        f'    <meta name="twitter:description" content="{e(desc)}">',
        f'    <meta name="twitter:image" content="{SITE}{img}">',
        f'    <meta name="twitter:image:alt" content="{e(alt)}">',
    ]
    # drop the Turkish meta block (everything from description to the last twitter tag)
    start = s.index('    <meta name="description"')
    end_m = list(re.finditer(r'<meta name="twitter:image:alt"[^>]*>\r?\n', s))[-1]
    s = s[:start] + nl.join(lines) + nl + s[end_m.end():]
    s = re.sub(r'<title>.*?</title>', f'<title>{e(title)}</title>', s, count=1)
    return s, en_url


def visible_faq(s):
    out = []
    for item in re.findall(r'<div class="faq-item">(.*?)</div>\s*</div>', s, flags=re.S):
        q = re.search(r'class="faq-toggle-text"[^>]*>(.*?)</span>', item, flags=re.S)
        a = re.search(r'<div class="faq-answer"[^>]*>\s*<p[^>]*>(.*?)</p>', item, flags=re.S)
        if q and a:
            clean = lambda x: html.unescape(re.sub(r'<[^>]+>', '', x)).strip()
            out.append((clean(q.group(1)), clean(a.group(1))))
    return out


def json_ld(page, s, en_url, nl):
    w = None if page == 'index.html' else page.split('w-')[0]
    faq = visible_faq(s)

    def fix(obj):
        t = obj.get('@type')
        if t == 'Organization':
            obj['description'] = ("Türkiye's first domestic SSPA and BUC manufacturer. "
                                  "GaN-based Ku-Band BUC/SSPA models from 20W to 400W.")
        elif t == 'Product':
            obj['url'] = en_url
        elif t == 'FAQPage':
            obj['mainEntity'] = [{'@type': 'Question', 'name': q,
                                  'acceptedAnswer': {'@type': 'Answer', 'text': a}} for q, a in faq]
        elif t == 'BreadcrumbList':
            obj['itemListElement'] = [
                {'@type': 'ListItem', 'position': 1, 'name': 'Home', 'item': SITE + 'en/'},
                {'@type': 'ListItem', 'position': 2, 'name': 'Products', 'item': SITE + 'en/#cozumler'},
                {'@type': 'ListItem', 'position': 3, 'name': f'{w}W SSPA', 'item': en_url}]
        return obj

    def repl(m):
        ind, data = m.group(1), json.loads(m.group(2))
        if '@graph' in data:
            data['@graph'] = [fix(o) for o in data['@graph']]
        else:
            data = fix(data)
        txt = json.dumps(data, ensure_ascii=False, indent=2)
        txt = nl.join(ind + '    ' + l if l else l for l in txt.split('\n'))
        return f'{ind}<script type="application/ld+json">{nl}{txt}{nl}{ind}</script>'
    return re.sub(r'([ \t]*)<script type="application/ld\+json">\s*(.*?)\s*</script>', repl, s, flags=re.S)


def build(page):
    with open(os.path.join(ROOT, page), encoding='utf-8', newline='') as f:
        s = f.read()
    nl = '\r\n' if '\r\n' in s else '\n'
    s = s.replace('<html lang="tr">', '<html lang="en">', 1)
    s = apply_data_en(s)
    s, en_url = meta_block(page, s, nl)
    s = json_ld(page, s, en_url, nl)
    for a, b in ATTR_TEXT:
        s = s.replace(a, b)
    for a, b in ATTR_RE:
        s = re.sub(a, b, s)
    # language toggle: EN is this page, TR goes back to the Turkish page
    tr_href = '../' if page == 'index.html' else '../' + page
    s = re.sub(r'<a class="lang-btn active" id="btn-tr"[^>]*>TR</a>',
               f'<a class="lang-btn" id="btn-tr" href="{tr_href}" lang="tr" hreflang="tr">TR</a>', s)
    s = re.sub(r'<a class="lang-btn" id="btn-en"[^>]*>EN</a>',
               '<a class="lang-btn active" id="btn-en" href="#" aria-current="page" lang="en" hreflang="en">EN</a>', s)
    # datasheet buttons default to the English PDF
    s = re.sub(r'href="(datasheets/gansat-\d+wku-datasheet-)tr\.pdf"', r'href="\1en.pdf"', s)
    s = rewrite_paths(s)
    os.makedirs(os.path.join(ROOT, 'en'), exist_ok=True)
    with open(os.path.join(ROOT, 'en', page), 'w', encoding='utf-8', newline='') as f:
        f.write(s)
    return s


if __name__ == '__main__':
    for p in PAGES:
        build(p)
        print('en/' + p)

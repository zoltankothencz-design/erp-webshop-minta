#!/usr/bin/env python3
"""Statikus demo-pillanatkép a futó helyi webshopból (GitHub Pages-hez).

Előfeltétel: a webshop fut (`.\\scripts\\dev.ps1 start`), az adatbázisban vannak termékek.
Használat:   python scripts/build-static-demo.py
Kimenet:     docs/demo/shop/  (a docs/demo/index.html kézzel írt kezdőlap, ezt nem bántja)

A pillanatkép csak olvasható: a kosár a böngészőben (localStorage) működik, a rendelés
nem kerül sehová (a demo-shim.js hamis rendelésszámot ad). Az admin felület és a szimulált
ERP képernyőképen szerepel, ezek nem futnak a Pages-en.
"""
import json
import re
import shutil
import sys
import urllib.request
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
LIVE = 'http://127.0.0.1:8080'
OUT = ROOT / 'docs' / 'demo' / 'shop'
PUBLIC_BASE = 'https://zoltankothencz-design.github.io/erp-webshop-minta/demo/shop'

BANNER = (
    '<div style="background:#fff4d6;border-bottom:1px solid #e6c875;padding:.5rem 1rem;'
    'font:14px/1.4 system-ui,sans-serif;text-align:center">'
    'Statikus pillanatkép a mintaprojektből. A kosár a böngészőben működik, '
    'a rendelés nem kerül sehová. '
    '<a href="{back}">&larr; Demó-kezdőlap</a></div>'
)


def fetch(path: str) -> bytes:
    with urllib.request.urlopen(LIVE + path, timeout=20) as r:
        return r.read()


def text(path: str) -> str:
    return fetch(path).decode('utf-8')


def rel_link(url: str) -> str:
    """Abszolút (/...) útvonal -> a pillanatkép gyökeréhez viszonyított útvonal."""
    m = re.fullmatch(r'/\?category=([a-z0-9-]+)', url)
    if m:
        return f'category/{m.group(1)}/'
    m = re.fullmatch(r'/product/([a-z0-9-]+)', url)
    if m:
        return f'product/{m.group(1)}/'
    if url == '/cart':
        return 'cart/'
    if url == '/':
        return ''
    return url.lstrip('/')


def localize(html: str, depth: int, out_name: str) -> str:
    prefix = '../' * depth

    def sub(m):
        attr, url = m.group(1), m.group(2).replace('&amp;', '&')
        target = rel_link(url)
        return f'{attr}="{prefix}{target}"' if (prefix or target) else f'{attr}="./"'

    html = re.sub(r'\b(href|src|action)="(/(?!/)[^"]*)"', sub, html)
    back = '../' * (depth + 1) + 'index.html'
    html = html.replace('<body>', '<body>\n' + BANNER.format(back=back), 1)
    html = html.replace('</body>', f'<script src="{prefix}demo-shim.js"></script>\n</body>', 1)
    leftovers = re.findall(r'(?:href|src|action)="/(?!/)[^"]*"', html)
    if leftovers:
        sys.exit(f'{out_name}: átírás nélkül maradt abszolút hivatkozás: {leftovers[:3]}')
    return html


def write(rel: str, content) -> None:
    p = OUT / rel
    p.parent.mkdir(parents=True, exist_ok=True)
    if isinstance(content, bytes):
        p.write_bytes(content)
    else:
        p.write_text(content, encoding='utf-8', newline='\n')


def main() -> None:
    if OUT.exists():
        shutil.rmtree(OUT)
    OUT.mkdir(parents=True)

    home = text('/')
    categories = sorted(set(re.findall(r'href="/\?category=([a-z0-9-]+)"', home)))
    products = sorted(set(re.findall(r'href="/product/([a-z0-9-]+)"', home)))
    if not products:
        sys.exit('Nincs termék a főoldalon. Futtasd: dev.ps1 console job erp-products')

    write('index.html', localize(home, 0, 'index'))
    for c in categories:
        write(f'category/{c}/index.html', localize(text(f'/?category={c}'), 2, c))
    for s in products:
        write(f'product/{s}/index.html', localize(text(f'/product/{s}'), 2, s))
    write('cart/index.html', localize(text('/cart'), 1, 'cart'))

    # termék-API pillanatkép (a shim ebből szolgálja ki a kosár oldalt)
    api = json.loads(text('/api/products'))
    write('api/products.json', json.dumps(api, ensure_ascii=False))

    # feedek: a belső cím helyett a publikus
    for name in ('google', 'marketplace'):
        xml = text(f'/feed/{name}.xml').replace(LIVE, PUBLIC_BASE)
        write(f'feed/{name}.xml', xml)

    # statikus fájlok; a shop.js-ben egy abszolút útvonalat javítunk
    shutil.copytree(ROOT / 'public' / 'assets', OUT / 'assets')
    js_path = OUT / 'assets' / 'js' / 'shop.js'
    js = js_path.read_text(encoding='utf-8')
    old = "a.href = '/product/' + encodeURIComponent(p.slug);"
    new = "a.href = (window.DEMO_BASE || '/') + 'product/' + encodeURIComponent(p.slug) + '/';"
    if old not in js:
        sys.exit('shop.js változott: a hivatkozás-javítást frissíteni kell')
    js_path.write_text(js.replace(old, new), encoding='utf-8', newline='\n')

    shutil.copy(ROOT / 'scripts' / 'demo-shim.js', OUT / 'demo-shim.js')
    print(f'Kész: {len(products)} termék, {len(categories)} kategória -> {OUT}')


if __name__ == '__main__':
    main()

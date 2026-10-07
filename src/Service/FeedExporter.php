<?php
declare(strict_types=1);

namespace App\Service;

use App\Config;
use App\Repository\ProductRepository;

/**
 * Termék-feedek. A hirdetési és összehasonlító rendszerek (Google Merchant Center, árösszehasonlítók, marketplace-ek)
 * ezt az XML-t húzzák le rendszeresen. Az XMLWriter gondoskodik a karakterek (&, <, ") helyes kiescape-eléséről.
 */
final class FeedExporter
{
    public function __construct(private ProductRepository $products = new ProductRepository())
    {
    }

    /** Google Merchant Center RSS 2.0 formátum (g: névtér). */
    public function googleMerchant(): string
    {
        $base = rtrim((string) Config::get('APP_URL', ''), '/');
        $w = $this->writer();
        $w->startElement('rss');
        $w->writeAttribute('version', '2.0');
        $w->writeAttribute('xmlns:g', 'http://base.google.com/ns/1.0');
        $w->startElement('channel');
        $w->writeElement('title', 'Mintabolt');
        $w->writeElement('link', $base . '/');
        $w->writeElement('description', 'Mintabolt termékfeed');
        foreach ($this->publicProducts() as $p) {
            $w->startElement('item');
            $w->writeElement('g:id', $p['sku']);
            $w->writeElement('g:title', mb_substr($p['name'], 0, 150));
            $w->writeElement('g:description', mb_substr($p['description'] !== '' ? $p['description'] : $p['name'], 0, 5000));
            $w->writeElement('g:link', $base . '/product/' . rawurlencode($p['slug']));
            $w->writeElement('g:image_link', $base . $p['image']);
            $w->writeElement('g:availability', $p['available'] ? 'in_stock' : 'out_of_stock');
            $w->writeElement('g:price', $p['price'] . ' HUF');
            $w->writeElement('g:condition', 'new');
            if ($p['brand']) {
                $w->writeElement('g:brand', $p['brand']);
            }
            if ($p['ean']) {
                $w->writeElement('g:gtin', $p['ean']);
            }
            $w->endElement(); // item
        }
        $w->endElement(); // channel
        $w->endElement(); // rss
        $w->endDocument();
        return $w->outputMemory();
    }

    /** Általános, egyszerű XML marketplace-ekhez. Az egyes marketplace-ek saját formátumot várnak: azt az ő dokumentációjuk alapján kell megírni. */
    public function marketplaceXml(): string
    {
        $base = rtrim((string) Config::get('APP_URL', ''), '/');
        $w = $this->writer();
        $w->startElement('products');
        $w->writeAttribute('generated', date('c'));
        foreach ($this->publicProducts() as $p) {
            $w->startElement('product');
            $w->writeElement('sku', $p['sku']);
            $w->writeElement('name', $p['name']);
            $w->writeElement('brand', (string) $p['brand']);
            $w->writeElement('category', (string) $p['category']);
            $w->writeElement('ean', (string) $p['ean']);
            $w->writeElement('price_gross', (string) $p['price']);
            $w->writeElement('currency', 'HUF');
            $w->writeElement('stock', (string) $p['max_qty']);
            $w->writeElement('url', $base . '/product/' . rawurlencode($p['slug']));
            $w->endElement();
        }
        $w->endElement();
        $w->endDocument();
        return $w->outputMemory();
    }

    /** A feedbe csak a publikus alak kerül (az AI-leírás is csak jóváhagyva), plusz az EAN. */
    private function publicProducts(): array
    {
        $out = [];
        foreach ($this->products->search(null, null, null, 500) as $row) {
            $pub = $this->products->toPublic($row);
            $pub['ean'] = preg_match('/^\d{8,14}$/', (string) $row['ean']) === 1 ? $row['ean'] : null;
            $out[] = $pub;
        }
        return $out;
    }

    private function writer(): \XMLWriter
    {
        $w = new \XMLWriter();
        $w->openMemory();
        $w->setIndent(true);
        $w->startDocument('1.0', 'UTF-8');
        return $w;
    }
}

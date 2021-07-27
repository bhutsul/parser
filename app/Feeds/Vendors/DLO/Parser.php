<?php

namespace App\Feeds\Vendors\DLO;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Parser\HtmlParser;
use App\Feeds\Utils\ParserCrawler;
use App\Helpers\FeedHelper;
use App\Helpers\StringHelper;


class Parser extends HtmlParser
{
    public const DIMENSIONS_REGEXES = [
        'DWH' => '/(\d+[\.]?\d*)"\(\d+[\.]?\d*\scm\)\s[a-zA-Z]\sx\s*(\d+[\.]?\d*)"\(\d+[\.]?\d*\scm\)\s[a-zA-Z]\sx\s*(\d+[\.]?\d*)"\(\d+[\.]?\d*\scm\)\s[a-zA-Z]/i',
        'HWD' => '/(\d+[\.]?\d*)"[a-zA-Z]\sx\s*(\d+[\.]?\d*)"[a-zA-Z]\sx\s*(\d+[\.]?\d*)"[a-zA-Z]/i',
        'HWD_DESC' => '/(\d+[\.]?\d*)"\sx\s*(\d+[\.]?\d*)"\sx\s*(\d+[\.]?\d*)/i',
    ];

    private array $product_info = [];

    public function beforeParse(): void
    {
        $product_id = $this->getAttr('input[name="product_id"]', 'value');
        if ($product_id) {
            $this->product_info = $this->executeProduct($product_id);
        }

        if ($this->exists( '#tab-description')) {
            $this->product_info['description'] = $this->getHtml( '#tab-description');

            if (preg_match(self::DIMENSIONS_REGEXES['HWD_DESC'], $this->product_info['description'])) {
                $dims = FeedHelper::getDimsRegexp($this->product_info['description'], [self::DIMENSIONS_REGEXES['HWD_DESC']], 2, 1, 3);
            }

            if ($this->exists( '#tab-description li')) {
                $this->product_info['short_description'] = $this->getContent('#tab-description li');

                if (!isset($dims)) {
                    foreach ($this->product_info['short_description'] as $li_value) {
                        if (preg_match(self::DIMENSIONS_REGEXES['DWH'], $li_value)) {
                            $dims = FeedHelper::getDimsRegexp($li_value, [self::DIMENSIONS_REGEXES['DWH']], 2, 3, 1);
                        }
                        else if (preg_match(self::DIMENSIONS_REGEXES['HWD'], $li_value)) {
                            $dims = FeedHelper::getDimsRegexp($li_value, [self::DIMENSIONS_REGEXES['HWD']], 2, 1, 3);
                        }
                    }
                }
            }

            if (isset($dims)) {
                $this->product_info['depth']  = $dims['z'];
                $this->product_info['height'] = $dims['y'];
                $this->product_info['width']  = $dims['x'];
            }
        }
    }

    public function isGroup(): bool
    {
        if ($this->checkIfSameChild()) {
            return false;
        }

        return $this->exists('[name*="attribute"]') ;
    }

    public function getProduct(): string
    {
        return $this->product_info['name'] ?? $this->getText('h1.productView-title');
    }

    public function getDescription(): string
    {
        return isset($this->product_info['description'])
            ? preg_replace('/<ul\b[^>]*>(.*?)<\/ul>/i', '', $this->product_info['description'])
            : '';
    }

    public function getShortDescription(): array
    {
        return $this->product_info['short_description'] ?? [];
    }

    public function getImages(): array
    {
        return $this->getAttrs('a.productView-thumbnail-link', 'data-image-gallery-new-image-url');
    }

    public function getDimX(): ?float
    {
        return $this->product_info['width'] ?? null;
    }

    public function getDimY(): ?float
    {
        return $this->product_info['height'] ?? null;
    }


    public function getDimZ(): ?float
    {
        return $this->product_info['depth'] ?? null;
    }

    public function getMpn(): string
    {
        return $this->product_info['sku'] ?? $this->getText('.sku-section span');
    }

    public function getBrand(): ?string
    {
        return $this->getAttr('.productView', 'data-product-brand');
    }

    public function getCostToUs(): float
    {
        $money = $this->product_info['price'] ?? $this->getAttr( 'meta[ itemprop="price"]', 'content' );

        return StringHelper::getMoney($money);
    }

    public function getAvail(): ?int
    {
        $availability = $this->getAttr( 'meta[ itemprop="availability"]', 'content' );

        return $availability === 'https://schema.org/InStock' || $availability === 'InStock'
            ? self::DEFAULT_AVAIL_NUMBER
            : 0;
    }

    public function getAttributes(): ?array
    {
        $attributes = [];

        if ($this->exists('table.productView-table tr')) {
            $this->filter('table.productView-table tr')
                ->each(function (ParserCrawler $c) use (&$attributes) {
                    $key   = $c->filter('td')->getNode(0)->textContent;
                    $value = $c->filter('td')->getNode(1)->textContent;
                    $attributes[$key] = $value;
                });
        }

        if (!$attributes) {
            return null;
        }

        return $attributes;
    }

    public function getChildProducts( FeedItem $parent_fi ): array
    {
        $child = [];
        if (isset($this->product_info['variants']) && $this->product_info['variants']) {
            foreach ($this->product_info['variants'] as $variant) {
                $images = $variant['image_url']
                    ? [$variant['image_url']]
                    : $this->getImages();

                $fi = clone $parent_fi;

                $fi->setMpn($variant['sku'] ?? '');
                $fi->setUpc($this->productUpc($variant));
                $fi->setProduct($this->getChildProductName($variant['option_values']));
                $fi->setImages($images);
                $fi->setCostToUs(StringHelper::getMoney($variant['price']));
                $fi->setRAvail($variant['inventory_level']);
                $fi->setDimZ($variant['depth']);
                $fi->setDimY($variant['height']);
                $fi->setDimX($variant['width']);
                $fi->setWeight($variant['weight']);

                $child[] = $fi;
            }
        }

        return $child;
    }

    /**
     * validate upc
     * @param array $product_data
     * @return string|null
     */
    private function productUpc(array $product_data): ?string
    {
        if ($product_data['upc'] === 'N/A') {
            return null;
        }

        return $product_data['upc'];
    }

    /**
     * method for all info of product
     * @param string|array $product_id
     * @return array
     * @throws \JsonException
     */
    private function executeProduct(string|array $product_id): array
    {
        $url = 'https://delasco-live.ae-admin.com/api/product/execute';
        $params['productId'] = $product_id;

        $data = $this->getVendor()->getDownloader()->get($url, $params);

        return json_decode($data, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array $option_values
     * @return string
     */
    private function getChildProductName(array $option_values): string
    {
        if (!$option_values) {
            return '';
        }

        $option_value = array_shift($option_values);

        return $option_value['option_display_name'] . ': ' . $option_value['label'];
    }

    /**
     * method for bad request with options(bug, product has select but does not have options)
     * @return bool
     */
    private function checkIfSameChild(): bool
    {
        if (isset($this->product_info['variants']) && $this->product_info['variants']) {
            return $this->product_info['sku'] === $this->product_info['variants'][0]['sku'];
        }

        return false;
    }
}

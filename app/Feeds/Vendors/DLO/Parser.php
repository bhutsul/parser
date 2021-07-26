<?php

namespace App\Feeds\Vendors\DLO;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Parser\HtmlParser;
use App\Feeds\Utils\Link;
use App\Feeds\Utils\ParserCrawler;
use App\Helpers\StringHelper;


class Parser extends HtmlParser
{
    private array $product_info = [];

    public function beforeParse(): void
    {
        $product_id = $this->getAttr('input[name="product_id"]', 'value');
        if ($product_id) {
            $this->product_info = $this->executeProduct($product_id);
        }
    }

    public function isGroup(): bool
    {
        return $this->exists('[name*="attribute"]');
    }

    public function getProduct(): string
    {
        return $this->product_info['name'] ?? $this->getText('h1.productView-title');
    }

    public function getDescription(): string
    {
        return $this->getHtml( '#tab-description' );
    }

    public function getImages(): array
    {
        return $this->getAttrs('a.productView-thumbnail-link', 'data-image-gallery-new-image-url');
    }

    public function getMpn(): string
    {
        return $this->product_info['sku'] ?? $this->getText('.sku-section span');
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
                $fi->setDimx($variant['width']);
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
            return '';
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
}

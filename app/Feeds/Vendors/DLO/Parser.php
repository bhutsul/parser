<?php

namespace App\Feeds\Vendors\DLO;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Parser\HtmlParser;
use App\Feeds\Utils\Link;
use App\Feeds\Utils\ParserCrawler;
use App\Helpers\StringHelper;


class Parser extends HtmlParser
{
    public function isGroup(): bool
    {
        return $this->exists('.product_options');
    }

    public function getProduct(): string
    {
        return $this->getText('h1.productView-title');
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
        return $this->getText('.sku-section span');
    }

    public function getCostToUs(): float
    {
        return StringHelper::getMoney($this->getAttr( 'meta[ itemprop="price"]', 'content' ));
    }

    public function getAvail(): ?int
    {
        $availability = $this->getAttr( 'meta[ itemprop="availability"]', 'content' );
        return $availability === 'https://schema.org/InStock' || $availability === 'InStock'
            ? self::DEFAULT_AVAIL_NUMBER
            : 0;
    }

    public function getChildProducts( FeedItem $parent_fi ): array
    {
        $child = [];

        if ($this->hasSelectAttribute()) {
            $links = [];

            $attribute_name = $this->filter('select')->attr('name');
            $product_id = $this->getAttr('input[name="product_id"]', 'value');
            $action = $this->getAttr('input[name="action"]', 'value');

            $this->filter('option')
                ->each(function (ParserCrawler $c) use (&$links, $attribute_name, $product_id, $action) {
                    $variant_url = 'https://www.delasco.com/remote/v1/product-attributes/' . $product_id;

                    if ($attribute_value = $c->attr('value')) {
                        $params = [
                            'action' => $action,
                            'product_id' => $product_id,
                        ];
                        $params[$attribute_name] = $attribute_value;
                        $params['qty'] = 1;

                        $links[] = new Link($variant_url, 'POST', $params, 'multipart/form-data');
                    }
                });

            $links = array_chunk($links, 10);

            foreach ($links as $links_chunk) {
                foreach ($this->getVendor()->getDownloader()->fetch($links_chunk, true) as $data) {
                    $product_data = json_decode($data['data'], true, 512, JSON_THROW_ON_ERROR);
                    $fi = clone $parent_fi;

                    $fi->setMpn($product_data['data']['sku'] ?? $product_data['data']['v3_variant_id'] ?? '');
                    $fi->setUpc($product_data['data']['upc']);
                    $fi->setProduct($this->getProduct());
                    $fi->setImages($this->getArrayOfImage($product_data) ?? $this->getImages());
                    $fi->setCostToUs(StringHelper::getMoney($product_data['data']['price']['without_tax']['value'] ?? 0));
                    $fi->setRAvail($product_data['data']['instock'] ? self::DEFAULT_AVAIL_NUMBER : 1);

                    $child[] = $fi;
                }
            }
        }


        return $child;
    }

    /**
     * a lot of products do not have select
     * @return bool
     */
    private function hasSelectAttribute():bool
    {
        return (bool)$this->getAttr('select.form-select--small', 'name');
    }

    /**
     * response return one image of new product
     * @param array $product_data
     * @return array|null
     */
    private function getArrayOfImage(array $product_data)
    {
        if (!isset($product_data['data']['image']['data'])) {
            return null;
        }

        $pattern = '/{:size}/';

        return [
            preg_replace($pattern, '500x500', $product_data['data']['image']['data'])
        ];
    }
}

<?php

namespace App\Feeds\Vendors\OLH;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Parser\HtmlParser;
use App\Helpers\FeedHelper;
use App\Helpers\StringHelper;


class Parser extends HtmlParser
{
    private null|array $product_info;
    public const WEIGHT_REGEX = '/(\d+[\.]?\d*)[\s]?oz[\.]?/i';

    /**
     * @throws \JsonException
     */
    public function beforeParse(): void
    {
        if ( $this->exists( '#wix-warmup-data' ) ) {
            $warmup_data = json_decode($this->getText('#wix-warmup-data'), true, 512, JSON_THROW_ON_ERROR);

            if ( isset( $warmup_data['appsWarmupData'] ) && count( $warmup_data['appsWarmupData'] ) ) {
                $product = $warmup_data['appsWarmupData'][array_key_first( $warmup_data['appsWarmupData'] )];

                $this->product_info = $product[array_key_first( $product )]['catalog']['product'] ?? null;
                $this->product_info['name'] = $this->getAttr( 'meta[property="og:title"]', 'content' );
            }
        }
    }

    public function isGroup(): bool
    {
        return isset( $this->product_info['options'] ) && count( $this->product_info['options'] );
    }

    public function getProduct(): string
    {
        return $this->product_info['name'] ?? '';
    }

    public function getMpn(): string
    {
        return isset( $this->product_info['sku'] ) && $this->product_info['sku'] ? $this->product_info['sku'] : $this->product_info['id'] ?? '';
    }

    public function getDescription(): string
    {
        return $this->product_info['description'] ?? $this->getAttr( 'meta[property="og:description"]', 'content' );
    }

    public function getImages(): array
    {
        $image = $this->getAttr( '#get-image-item-id', 'href' ) ?: $this->getAttr( 'meta[property="og:image"]', 'content' );

        return $image ? [$image] : [];
    }

    public function getWeight(): ?float
    {
        if ( !isset( $this->product_info['name'] )
            || !preg_match( self::WEIGHT_REGEX, $this->product_info['name'], $matches )
        ) {
            return null;
        }

        return FeedHelper::convertLbsFromOz( StringHelper::getFloat( $matches[1] ) );
    }

    public function getCostToUs(): float
    {
        return StringHelper::getMoney(
            $this->product_info['price'] ?? $this->getAttr( 'meta[property="product:price:amount"]', 'content' )
        );
    }

    public function getAvail(): ?int
    {
        return isset( $this->product_info['inventory']['status'] ) && $this->product_info['inventory']['status'] === 'in_stock'
            ? self::DEFAULT_AVAIL_NUMBER
            : 0;
    }

    public function getChildProducts(FeedItem $parent_fi): array
    {
        $child = [];

        $options = $this->product_info['options'][array_key_first( $this->product_info['options'] )]['selections'];

        foreach ($options as $key => $option) {
            $fi = clone $parent_fi;

            $price = count( $options ) === count( $this->product_info['productItems'] )
                ? StringHelper::getFloat( $this->product_info['productItems'][$key]['price'] )
                : $this->getCostToUs();

            $matches = [];
            if ( preg_match( self::WEIGHT_REGEX, $option['value'], $matches ) ) {
                $weight = FeedHelper::convertLbsFromOz( StringHelper::getFloat( $matches[1] ) );
            }

            $fi->setProduct( $option['value'] );
            $fi->setCostToUs( $price );
            $fi->setRAvail( $this->getAvail() );
            $fi->setWeight( $weight ?? $this->getWeight() );
            $fi->setMpn( $option['value'] . '-' . $option['id'] );

            $child[] = $fi;
        }

        return $child;
    }
}

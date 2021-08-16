<?php

namespace App\Feeds\Vendors\ZCU;

use App\Feeds\Parser\HtmlParser;
use App\Feeds\Utils\ParserCrawler;
use App\Helpers\FeedHelper;
use App\Helpers\StringHelper;


class Parser extends HtmlParser
{
    public const DIMS_REGEX = '/(\d+[\.]?\d*)[\',",”]?(?:[a-z]{4,4})?[\s]?[x,X][\s]?(\d+[\.]?\d*)[\',",”]?[\s]?[x,X]?[\s]?(:?\d+[\.]?\d*)?/i';

    private null|array $product_info;
    /**
     * @throws \JsonException
     */
    public function beforeParse(): void
    {
        for ( $i = 0; $i <= 10; $i ++ ) {
            if ( $this->exists( '#wix-warmup-data' ) ) {
                $warmup_data = json_decode( $this->getText('#wix-warmup-data'), true );

                if ( isset( $warmup_data['appsWarmupData'] ) && count( $warmup_data['appsWarmupData'] ) ) {
                    $product = $warmup_data['appsWarmupData'][array_key_first($warmup_data['appsWarmupData'])];

                    $this->product_info = $product[array_key_first( $product )]['catalog']['product'] ?? null;

                    $short_and_attributes = FeedHelper::getShortsAndAttributesInList(
                        $this->getHtml('div[data-hook="info-section-description"]')
                    );

                    if ( $this->exists( 'section[data-hook="description-wrapper"] ul li' ) ) {
                        $short_and_attributes = FeedHelper::getShortsAndAttributesInList(
                            $this->product_info['description'],
                            $short_and_attributes['short_description'],
                            $short_and_attributes['attributes'] ?: [],
                        );

                        $this->product_info['description'] = FeedHelper::cleanProductDescription(
                            preg_replace( '/<ul\b[^>]*>(.*?)<\/ul>/i', '', $this->product_info['description'] )
                        );
                    }

                    if ($short_and_attributes['attributes']) {
                        foreach ( $short_and_attributes['attributes'] as $key => $attribute ) {
                            if ( false !== stripos( $key, 'size' ) ) {
                                $this->product_info['dims'] = FeedHelper::getDimsRegexp(
                                    str_replace('”', "", $attribute),
                                    [self::DIMS_REGEX],
                                    3,
                                    2,
                                    1
                                );

                                continue;
                            }

                            $this->product_info['attributes'][$key] = $attribute;
                        }
                    }

                    if ($short_and_attributes['short_description']) {
                        foreach ( $short_and_attributes['short_description'] as $description ) {
                            if ( false !== stripos( $description, 'measures approximately' ) ) {
                                $this->product_info['dims'] = FeedHelper::getDimsRegexp(
                                    str_replace('”', "", $description),
                                    [self::DIMS_REGEX],
                                    3,
                                    2,
                                    1
                                );

                                continue;
                            }

                            $this->product_info['short_desc'][] = $description;
                        }
                    }

                    break;
                }

                $this->node = new ParserCrawler( $this->getVendor()->getDownloader()->get( $this->getUri() )->getData() );
            }
        }

    }

    public function getProduct(): string
    {
        return $this->product_info['name'] ?? $this->getAttr( 'meta[property="og:title"]', 'content' );
    }

    public function getMpn(): string
    {
        return isset( $this->product_info['sku'] )
                && $this->product_info['sku']
                    ? $this->product_info['sku']
                    : $this->product_info['id'] ?? '';
    }

    public function getDescription(): string
    {
        return $this->product_info['description'] ?? $this->getAttr( 'meta[property="og:description"]', 'content' );
    }

    public function getShortDescription(): array
    {
        return $this->product_info['short_desc'] ?? [];
    }

    public function getAttributes(): ?array
    {
        return $this->product_info['attributes'] ?? null;
    }

    public function getDimX(): ?float
    {
        return $this->product_info['dims']['x'] ?? null;
    }

    public function getDimY(): ?float
    {
        return $this->product_info['dims']['y'] ?? null;
    }

    public function getDimZ(): ?float
    {
        return $this->product_info['dims']['z'] ?? null;
    }

    public function getImages(): array
    {
        return $this->getAttrs( 'div[data-hook="main-media-image-wrapper"] div', 'href' );
    }

    public function getCostToUs(): float
    {
        return StringHelper::getMoney(
            $this->product_info['price'] ?? $this->getText( 'span[data-hook="formatted-primary-price"]' )
        );
    }

    public function getAvail(): ?int
    {
        return isset( $this->product_info['inventory']['status'] )
                && $this->product_info['inventory']['status'] === 'in_stock'
                    ? $this->product_info['inventory']['quantity']
                    : 0;
    }
}

<?php

namespace App\Feeds\Vendors\SWC;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Parser\HtmlParser;
use App\Feeds\Utils\ParserCrawler;
use App\Helpers\FeedHelper;
use App\Helpers\StringHelper;


class Parser extends HtmlParser
{
    private array $product_info;

    private function combinations( array $arrays, int $i = 0 )
    {
        if ( !isset( $arrays[$i] ) ) {
            return [];
        }

        if ( $i === count( $arrays ) - 1 ) {
            return $arrays[$i];
        }

        // get combinations from subsequent arrays
        $tmp = $this->combinations( $arrays, $i + 1 );

        $result = [];

        // concat each array from tmp with each element from $arrays[$i]
        foreach ( $arrays[$i] as $v ) {
            foreach ( $tmp as $t ) {
                $result[] = is_array( $t ) ? array_merge( [$v], $t ) : [$v, $t];
            }
        }

        return $result;
    }

    private function pushDescription(): void
    {
        if ( $this->exists( '#ProductDetail_ProductDetails_div' ) ) {
            $this->product_info['description'] = $this->getHtml( '#ProductDetail_ProductDetails_div' );
        }
    }

    private function pushVideos(): void
    {
        if ( $this->exists( '#ProductDetail_ProductDetails_div .video_container iframe' ) ) {
            $this->product_info['videos'][] = [
                'name' => $this->getProduct(),
                'provider' => 'youtube',
                'video' => $this->getAttr( '#ProductDetail_ProductDetails_div .video_container iframe', 'src' )
            ];
        }
    }

    private function pushFiles(): void
    {
        if ( $this->exists( '#ProductDetail_ProductDetails_div a' ) ) {
            $this->filter( '#ProductDetail_ProductDetails_div a' )
                ->each( function ( ParserCrawler $c ) {
                    if ( false !== stripos( $c->attr( 'href' ), 'pdf' ) ) {
                        $this->product_info['files'][] = [
                            'name' => $c->text() ?: $this->getProduct(),
                            'link' => $c->attr( 'href' )
                        ];
                    }
                });
        }
    }

    private function pushShortsAndAttributesAndDims(): void
    {
        if ( $this->exists( '#ProductDetail_TechSpecs_div' ) ) {
            $shorts_and_attributes = FeedHelper::getShortsAndAttributesInList(
                $this->getHtml( '#ProductDetail_TechSpecs_div' )
            );
        }

        if ( $this->exists( '#ProductDetail_ProductDetails_div2' ) ) {
            $shorts_and_attributes = FeedHelper::getShortsAndAttributesInList(
                $this->getHtml( '#ProductDetail_ProductDetails_div2 ul' ),
                $shorts_and_attributes['short_description'] ?? [],
                $shorts_and_attributes['attributes'] ?? [],
            );
        }

        if ( !isset( $shorts_and_attributes ) ) {
            return;
        }

        //validate

        $this->product_info['shorts'] = $shorts_and_attributes['short_description'];

        $this->product_info['attributes'] = $shorts_and_attributes['attributes'];
    }


    public function beforeParse(): void
    {
        $this->pushDescription();

        $this->pushFiles();

        $this->pushVideos();

        $this->pushShortsAndAttributesAndDims();
    }

    public function isGroup(): bool
    {
        return $this->exists( '#options_table' );
    }

    public function getProduct(): string
    {
        return trim( $this->getAttr( 'meta[property="og:title"]', 'content' ) );
    }

    public function getMpn(): string
    {
        return $this->getAttr( 'meta[itemprop="sku"]', 'content' );
    }

    public function getDescription(): string
    {
        if ( !isset( $this->product_info['description'] ) ) {
            return '';
        }

        return FeedHelper::cleanProductDescription( $this->product_info['description'] );
    }

    public function getShortDescription(): array
    {
        if ( !isset( $this->product_info['shorts'] ) ) {
            return [];
        }

        return FeedHelper::cleanShortDescription( $this->product_info['shorts'] );
    }

    public function getImages(): array
    {
        return $this->getAttrs( '#altviews a', 'href' );
    }

    public function getAttributes(): ?array
    {
        return $this->product_info['attributes'] ?? null;
    }

    public function getCostToUs(): float
    {
        return StringHelper::getMoney( $this->getAttr( 'meta[itemprop="price"]', 'content' ) );
    }

    public function getListPrice(): ?float
    {
        if ( !$this->exists( '[itemprop="offers"] .product_listprice' ) ) {
            return null;
        }

        return StringHelper::getMoney( $this->getText( '[itemprop="offers"] .product_listprice b' ) );
    }

    public function getAvail(): ?int
    {
        return $this->getAttr( 'meta[itemprop="availability"]', 'content' ) === 'InStock'
                    ? self::DEFAULT_AVAIL_NUMBER
                    : 0;
    }

//    public function getDimX(): ?float
//    {
//        return $this->product_info['dims']['x'] ?? null;
//    }
//
//    public function getDimY(): ?float
//    {
//        return $this->product_info['dims']['y'] ?? null;
//    }
//
//    public function getDimZ(): ?float
//    {
//        return $this->product_info['dims']['z'] ?? null;
//    }
//
//    public function getWeight(): ?float
//    {
//        return $this->product_info['weight'] ?? null;
//    }

//    public function getShippingDimX(): ?float
//    {
//        return $this->product_info['shipping_dims']['x'] ?? null;
//    }
//
//    public function getShippingDimY(): ?float
//    {
//        return $this->product_info['shipping_dims']['y'] ?? null;
//    }
//
//    public function getShippingDimZ(): ?float
//    {
//        return $this->product_info['shipping_dims']['z'] ?? null;
//    }
//
//    public function getShippingWeight(): ?float
//    {
//        return $this->product_info['shipping_weight'] ?? null;
//    }
//
    public function getVideos(): array
    {
        return $this->product_info['videos'] ?? [];
    }

    public function getProductFiles(): array
    {
        return $this->product_info['files'] ?? [];
    }

    /**
     * @throws \JsonException
     */
    public function getChildProducts( FeedItem $parent_fi ): array
    {
        $child = [];


        return $child;
    }
}

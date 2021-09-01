<?php

namespace App\Feeds\Vendors\TSL;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Parser\HtmlParser;
use App\Feeds\Utils\ParserCrawler;
use App\Helpers\FeedHelper;
use App\Helpers\StringHelper;

class Parser extends HtmlParser
{
    public const NOT_VALID_PARTS_OF_DESC = [

    ];

    private array $product_info;

    private function parseFilesAndVideos(): void
    {
        if ($this->exists('#tab-templates')) {
            $this->filter( '#tab-templates a' )->each( function ( ParserCrawler $c ) {
                if ( false !== stripos( $c->attr( 'href' ), 'pdf' ) ) {
                    $this->product_info['files'][] = [
                        'name' => trim(str_replace("Download", '', $c->text())),
                        'link' => $c->attr( 'href' )
                    ];
                }
            });

            $this->filter( '#tab-templates iframe' )->each( function ( ParserCrawler $iframe ) {
                $this->pushVideoFromIframe($iframe);
            });
        }

        if ($this->exists('#tab-video')) {
            $this->filter( '#tab-video iframe' )->each( function ( ParserCrawler $iframe ) {
                $this->pushVideoFromIframe($iframe);
            });
        }
    }

    private function pushVideoFromIframe(ParserCrawler $iframe): void
    {
        $this->product_info[ 'videos' ][] = [
            'name' => $this->getProduct(),
            'provider' => 'youtube',
            'video' => $iframe->attr( 'src' ),
        ];
    }

    public function beforeParse(): void
    {
        $this->parseFilesAndVideos();
        if ($this->exists('#tab-description')) {
            $short_desc_attr = FeedHelper::getShortsAndAttributesInDescription($this->getHtml('#tab-description'));
            $this->product_info['short_description'] = $short_desc_attr['short_description'];
            $this->product_info['description'] = $short_desc_attr['description'];
            $this->product_info['attributes'] = $short_desc_attr['attributes'];
        }
    }

//    public function isGroup(): bool
//    {
//        return $this->exists( '.variations_form' );
//    }

    public function getProduct(): string
    {
        return $this->getText( 'h1[itemprop="name"]' );
    }

    public function getBrand(): ?string
    {
        return $this->getText( 'span[itemprop="brand"]' );
    }

    public function getMpn(): string
    {
        return $this->getText( 'span[itemprop="sku"]' );
    }

    public function getDescription(): string
    {
        return $this->product_info['description'] ?? '';
    }

    public function getShortDescription(): array
    {
        return $this->product_info['short_description'] ?? [];
    }

    public function getImages(): array
    {
        return array_map( static fn( $url ) => "https://www.taylorsecurity.com/$url", $this->getAttrs('#altImagesViewer a', 'href') );
    }

    public function getVideos(): array
    {
        return $this->product_info['videos'] ?? [];
    }

    public function getProductFiles(): array
    {
        return $this->product_info['files'] ?? [];
    }

    public function getAttributes(): ?array
    {
        return $this->product_info[ 'attributes' ] ?? null;
    }

    public function getCostToUs(): float
    {
        return StringHelper::getMoney( $this->getText( '#CT_ItemDetailsBottom_2_lblPrice' ) );
    }

    public function getAvail(): ?int
    {
        return $this->getAttr( 'span[property="og:availability"]', 'content' ) === 'InStock' ? self::DEFAULT_AVAIL_NUMBER : 0;
    }

    public function getCategories(): array
    {
        return array_values( array_slice( $this->getContent( '.breadcrumbs a' ), 2, -1 ) );
    }

//    public function getChildProducts( FeedItem $parent_fi ): array
//    {
//        $child = [];
//
//        $variations = $this->getAttr( '.variations_form', 'data-product_variations' );
//        $variations = json_decode( $variations, true, 512, JSON_THROW_ON_ERROR );
//
//        foreach ( $variations as $variation ) {
//            $fi = clone $parent_fi;
//
//            $product_name = '';
//
//            foreach ( $variation[ 'attributes' ] as $key => $combination ) {
//                $attribute_key = explode( '_', $key );
//                $product_name .= ucfirst( $attribute_key[ array_key_last( $attribute_key ) ] ) . ': ' . $combination;
//
//                $product_name .= $key !== array_key_last( $variation[ 'attributes' ] ) ? '. ' : '.';
//            }
//
//            $sku = $variation[ 'sku' ] ? $variation[ 'sku' ] . '-' . $variation[ 'variation_id' ] : $variation[ 'variation_id' ];
//
//            $fi->setProduct( $product_name );
//            $fi->setMpn( $sku );
//            $fi->setImages( $variation[ 'image' ][ 'url' ] && false === stripos( $variation[ 'image' ][ 'url' ], 'wp-content' ) ? [ $this->removeSecondHttps( $variation[ 'image' ][ 'url' ] ) ] : $this->getImages() );
//            $fi->setCostToUs( $this->subtractPercent( StringHelper::getMoney( $variation[ 'display_price' ] ) ) );
//            $fi->setNewMapPrice( StringHelper::getMoney( $variation[ 'display_price' ] ) );
//            $fi->setRAvail( $variation[ 'is_in_stock' ] ? self::DEFAULT_AVAIL_NUMBER : 0 );
//            $fi->setDimZ( $variation[ 'dimensions' ][ 'width' ] ?: $this->getDimZ() );
//            $fi->setDimY( $variation[ 'dimensions' ][ 'height' ] ?: $this->getDimY() );
//            $fi->setDimX( $variation[ 'dimensions' ][ 'length' ] ?: $this->getDimX() );
//            $fi->setWeight( $variation[ 'weight' ] ? FeedHelper::convertLbsFromOz( $variation[ 'weight' ] ) : $this->getWeight() );
//
//            $child[] = $fi;
//        }
//
//        return $child;
//    }
}

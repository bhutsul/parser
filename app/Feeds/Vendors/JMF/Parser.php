<?php

namespace App\Feeds\Vendors\JMF;

use App\Feeds\Parser\HtmlParser;
use App\Feeds\Utils\ParserCrawler;
use App\Helpers\StringHelper;

class Parser extends HtmlParser
{
    private array $product_info;

    public const NOT_VALID_PARTS_OF_DESC_REGEXES = [
        '/<div\b[^>]+\bclass=[\'\"]prod-faq[\'\"][^>]*>(.*?)<\/div>/s',
    ];

    private function formattedImages( array $images ): array
    {
        return array_values(
            array_unique(
                array_filter(
                    array_map( static fn( $image ) => $image[ 'full' ], $images ),
                    static fn( $image ) => $image !== null && false === stripos( $image, 'placeholder' )
                )
            ),
        );
    }

    private function formattedVideos( array $videos ): array
    {
        return array_values(
            array_filter(
                array_map( fn( $video ) => [
                    'name' => $this->getProduct(),
                    'provider' => 'youtube',
                    'video' => $video[ 'videoUrl' ],
                ], $videos ),
                static fn( $video ) => $video[ 'video' ] !== null
            )
        );
    }

    private function pushDataFromMagentoScripts(): void
    {
        preg_match_all( '/<script type="text\/x-magento-init">\s*({.*?})\s*</s', $this->node->html(), $matches );

        if ( isset( $matches[ 1 ] ) ) {
            foreach ( $matches[ 1 ] as $script ) {
                $json = json_decode( $script, true, 512, JSON_THROW_ON_ERROR );

                if ( isset( $json[ '[data-gallery-role=gallery-placeholder]' ][ 'mage/gallery/gallery' ] ) ) {
                    $this->product_info[ 'images' ] = $this->formattedImages( $json[ '[data-gallery-role=gallery-placeholder]' ][ 'mage/gallery/gallery' ][ 'data' ] );
                }
                else if ( isset( $json[ '[data-gallery-role=gallery-placeholder]' ][ 'Magento_ProductVideo/js/fotorama-add-video-events' ] ) ) {
                    $this->product_info[ 'videos' ] = $this->formattedVideos( $json[ '[data-gallery-role=gallery-placeholder]' ][ 'Magento_ProductVideo/js/fotorama-add-video-events' ][ 'videoData' ] );
                }
            }
        }
    }

    private function pushFiles(): void
    {
        $this->filter( '.mp-attachment-tab a' )->each( function ( ParserCrawler $a ) {
            $this->product_info[ 'files' ][] = [
                'name' => $a->text(),
                'link' => $a->attr( 'href' ),
            ];
        } );
    }

    private function pushDimsAndAttributes(): void
    {
        if ( $this->exists( '.attributes-wrapper table' ) ) {
            $this->filter( '.attributes-wrapper table tr' )->each( function ( ParserCrawler $tr ) {
                $key = $tr->getText( 'th' );
                $value = $tr->getText( 'td' );

                $next_element = $tr->nextAll()->first();
                if ( $next_element->exists( 'th' ) && empty( $next_element->getText( 'th' ) ) ) {
                    $value .= ' ' . $next_element->getText( 'td' );
                }

                if ( !empty( $key ) ) {
                    $this->product_info[ 'attributes' ][ $key ] = $value;
                }
            } );
        }
    }

    private function pushShorts(): void
    {
        $this->filter( '.product-attr ul.allcaps li' )->each( function ( ParserCrawler $li ) {
            $this->product_info[ 'short_description' ][] = $li->text();
        } );
    }

    public function beforeParse(): void
    {
        $this->product_info = [
            'name' => $this->getText( '[itemprop="name"]' ),
            'price' => StringHelper::getFloat( $this->getAttr( '[itemprop="price"]', 'content' ) ),
            'sku' => $this->getAttr( '[data-bv-show="rating_summary"]', 'data-bv-product-id' ),
            'categories' => $this->getContent( '.items .category a' ),
            'description' => preg_replace( self::NOT_VALID_PARTS_OF_DESC_REGEXES, '', $this->getHtml( '#description .description' ) ),
        ];

        $this->pushDataFromMagentoScripts();

        $this->pushFiles();

        $this->pushDimsAndAttributes();

        $this->pushShorts();
    }

    public function getProduct(): string
    {
        return $this->product_info[ 'name' ] ?? '';
    }

    public function getCostToUs(): float
    {
        return $this->product_info[ 'price' ] ?? 0;
    }

    public function getMpn(): string
    {
        return $this->product_info[ 'sku' ] ?? '';
    }

    public function getImages(): array
    {
        return $this->product_info[ 'images' ] ?? [];
    }

    public function getVideos(): array
    {
        return $this->product_info[ 'videos' ] ?? [];
    }

    public function getProductFiles(): array
    {
        return $this->product_info[ 'files' ] ?? [];
    }

    public function getShortDescription(): array
    {
        return $this->product_info[ 'short_description' ] ?? [];
    }

    public function getDescription(): string
    {
        return !empty( $this->product_info[ 'description' ] ) ? $this->product_info[ 'description' ] : $this->getProduct();
    }

    public function getAttributes(): ?array
    {
        return !empty( $this->product_info[ 'attributes' ] ) ? $this->product_info[ 'attributes' ] : null;
    }

    public function getAvail(): ?int
    {
        return self::DEFAULT_AVAIL_NUMBER;
    }
}

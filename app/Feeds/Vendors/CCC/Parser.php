<?php

namespace App\Feeds\Vendors\CCC;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Parser\HtmlParser;
use App\Feeds\Utils\ParserCrawler;
use App\Helpers\FeedHelper;
use App\Helpers\StringHelper;

class Parser extends HtmlParser
{
    public const NOT_VALID_PARTS_OF_DESC = [
        'check out',
        'looking for inspiration'
    ];

    private array $product_info;

    private function parseAttributesAndDims(): void
    {
        if ( $this->exists( '#tab-additional_information table' ) ) {
            $this->filter( '#tab-additional_information table tr' )
                ->each( function ( ParserCrawler $c ) {
                    $key = $c->getText( 'th' );
                    $value = $c->getText( 'td' );

                    if ( false !== stripos( $key, 'weight' ) ) {
                        $this->product_info[ 'weight' ] = FeedHelper::convertLbsFromOz( StringHelper::getFloat( $value ) );
                    }
                    else if ( ( false !== stripos( $key, 'dimensions' ) ) ) {
                        $this->product_info[ 'dims' ] = FeedHelper::getDimsInString( $value, '×' );
                    }
                    else if ( false === stripos( $key, 'N/A' ) ) {
                        $this->product_info[ 'attributes' ][ $key ] = $value;
                    }
                } );
        }
    }

    private function subtractPercent( $price ): int|float
    {
        return $price - ( $price * ( 15 / 100 ) );
    }

    private function removeSecondHttps( string $image ): string
    {
        if ( substr_count( $image, 'https://' ) === 2 ) {
            $href = explode( 'https://', $image );
            $image = 'https://' . $href[ 1 ] . $href[ 2 ];
        }

        return $image;
    }

    private function parseDescription(): string
    {
        $description = '';

        $this->filter( '#tab-description p' )->each( function ( ParserCrawler $c ) use ( &$description ) {
            if ( $c->text() ) {
                $not_valid = false;
                foreach ( self::NOT_VALID_PARTS_OF_DESC as $text ) {
                    if ( false !== stripos( $c->text(), $text ) ) {
                        $not_valid = true;
                    }
                }

                if ( $not_valid === false ) {
                    $description .= '<p>' . $c->text() . '</p>';
                }
            }
        } );

        return $description;
    }

    public function beforeParse(): void
    {
        if ( $this->exists( '.yoast-schema-graph--footer' ) ) {
            $json = json_decode( $this->getText( '.yoast-schema-graph--footer' ), true, 512, JSON_THROW_ON_ERROR );

            if ( isset( $json[ '@graph' ] ) ) {
                $product_key = array_search( 'Product', array_column( $json[ '@graph' ], '@type' ), true );
                $this->product_info = $json[ '@graph' ][ $product_key ];

                $offer_key = array_search( 'Offer', array_column( $this->product_info[ 'offers' ], '@type' ), true );
                $this->product_info[ 'offer' ] = $this->product_info[ 'offers' ][ $offer_key ];

                $this->parseAttributesAndDims();
            }
        }
    }

    public function isGroup(): bool
    {
        return $this->exists( '.variations_form' );
    }

    public function getProduct(): string
    {
        return $this->product_info[ 'name' ] ?? $this->getText( 'h2.product_title' );
    }

    public function getMpn(): string
    {
        return $this->product_info[ 'sku' ] ?? $this->getText( 'span.sku_wrapper .sku' );
    }

    public function getDescription(): string
    {
        return !$this->exists( '#tab-description p' ) ? $this->getHtml( '#tab-description' ) : $this->parseDescription();
    }

    public function getImages(): array
    {
        return array_map( fn( $image ) => $this->removeSecondHttps( $image ),
            $this->getAttrs( '[data-large_image]', 'data-large_image' )
        );
    }

    public function getAttributes(): ?array
    {
        return $this->product_info[ 'attributes' ] ?? null;
    }

    public function getCostToUs(): float
    {
        return isset( $this->product_info[ 'offer' ][ 'price' ] ) ? StringHelper::getMoney( $this->subtractPercent( $this->product_info[ 'offer' ][ 'price' ] ) ) : 0;
    }

    public function getMinimumPrice(): ?float
    {
        return isset( $this->product_info[ 'offer' ][ 'price' ] ) ? StringHelper::getMoney( $this->product_info[ 'offer' ][ 'price' ] ) : null;
    }

    public function getAvail(): ?int
    {
        return isset( $this->product_info[ 'offer' ] ) && $this->product_info[ 'offer' ][ 'availability' ] === 'http://schema.org/InStock' ? self::DEFAULT_AVAIL_NUMBER : 0;
    }

    public function getCategories(): array
    {
        return array_slice( $this->getContent( '.posted_in a' ), 0, 5 );
    }

    public function getDimX(): ?float
    {
        return $this->product_info[ 'dims' ][ 'x' ] ?? null;
    }

    public function getDimY(): ?float
    {
        return $this->product_info[ 'dims' ][ 'y' ] ?? null;
    }

    public function getDimZ(): ?float
    {
        return $this->product_info[ 'dims' ][ 'z' ] ?? null;
    }

    public function getWeight(): ?float
    {
        return $this->product_info[ 'weight' ] ?? null;
    }

    public function getChildProducts( FeedItem $parent_fi ): array
    {
        $child = [];

        $variations = $this->getAttr( '.variations_form', 'data-product_variations' );
        $variations = json_decode( $variations, true, 512, JSON_THROW_ON_ERROR );

        foreach ( $variations as $variation ) {
            $fi = clone $parent_fi;

            $product_name = '';

            foreach ( $variation[ 'attributes' ] as $key => $combination ) {
                $attribute_key = explode( '_', $key );
                $product_name .= ucfirst( $attribute_key[ array_key_last( $attribute_key ) ] ) . ': ' . $combination;

                $product_name .= $key !== array_key_last( $variation[ 'attributes' ] ) ? '. ' : '.';
            }

            $sku = $variation[ 'sku' ] ? $variation[ 'sku' ] . '-' . $variation[ 'variation_id' ] : $variation[ 'variation_id' ];

            $fi->setProduct( $product_name );
            $fi->setMpn( $sku );
            $fi->setImages( $variation[ 'image' ][ 'url' ] ? [ $this->removeSecondHttps( $variation[ 'image' ][ 'url' ] ) ] : $this->getImages() );
            $fi->setCostToUs( $this->subtractPercent( StringHelper::getMoney( $variation[ 'display_price' ] ) ) );
            $fi->setNewMapPrice( StringHelper::getMoney( $variation[ 'display_price' ] ) );
            $fi->setRAvail( $variation[ 'is_in_stock' ] ? self::DEFAULT_AVAIL_NUMBER : 0 );
            $fi->setDimZ( $variation[ 'dimensions' ][ 'width' ] ?: $this->getDimZ() );
            $fi->setDimY( $variation[ 'dimensions' ][ 'height' ] ?: $this->getDimY() );
            $fi->setDimX( $variation[ 'dimensions' ][ 'length' ] ?: $this->getDimX() );
            $fi->setWeight( $variation[ 'weight' ] ? FeedHelper::convertLbsFromOz( $variation[ 'weight' ] ) : $this->getWeight() );

            $child[] = $fi;
        }

        return $child;
    }
}

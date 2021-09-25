<?php

namespace App\Feeds\Vendors\IZB;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Parser\HtmlParser;
use App\Feeds\Utils\ParserCrawler;
use App\Helpers\FeedHelper;
use App\Helpers\StringHelper;

class Parser extends HtmlParser
{
    private array $product_info;

    public const NOT_VALID_PARTS_OF_DESC_REGEXES = [
        '/<p><strong\b[^>]*>UPC.*?<\/strong><\/p>/si',
        '/(<a\b[^>]*>|<\/a>)/si',
        '/(Best On|Heat Level|Best With|www.BestNaturalBBQ.com|Tastings.com)[:]?/si',
        '/In cases of extreme addiction contact us.*?Bacon Salt./si',
    ];

    private function parseDescriptionShortAndAttributes(): void
    {
        $description = preg_replace( self::NOT_VALID_PARTS_OF_DESC_REGEXES, '', $this->getHtml( '#tab-description' ) );

        if ( $this->exists( '#tab-ywtm_11087' ) ) {
            $description .= '<p>Ingredients</p>';
            $description .= '<p>' . $this->getText( '#tab-ywtm_11087 p' ) . '</p>';
        }

        $additional_info = FeedHelper::getShortsAndAttributesInDescription( $description, [ '/(<[u|o]l>)?(\s+)?(?<content_list><li>.*?<\/li>)(\s+)?<\/[u|o]l>/is' ] );

        $this->product_info[ 'attributes' ] = $additional_info[ 'attributes' ];
        $this->product_info[ 'short_description' ] = $additional_info[ 'short_description' ];
        $this->product_info[ 'description' ] = $additional_info[ 'description' ];
    }

    private function parseDimsAndAttributes(): void
    {
        if ( $this->exists( '#tab-additional_information table' ) ) {
            $this->filter( '#tab-additional_information table tr' )
                ->each( function ( ParserCrawler $c ) {
                    $key = $c->getText( 'th' );
                    $value = $c->getText( 'td' );

                    if ( ( false !== stripos( $key, 'weight' ) ) ) {
                        $this->product_info[ 'weight' ] = StringHelper::getFloat( $value );
                    }
                    else if ( ( false !== stripos( $key, 'dimensions' ) ) ) {
                        $this->product_info[ 'dims' ] = FeedHelper::getDimsInString( $value, '×' );
                    }
                    else {
                        $this->product_info[ 'attributes' ][ $key ] = $value;
                    }
                } );
        }
    }

    private function productNotValid()
    {
        return false !== stripos( $this->getProduct(), 'gift card' );
    }

    public function beforeParse(): void
    {
        preg_match( '/<script type="application\/ld\+json">\s*({.*?})\s*<\/script>/s', $this->node->html(), $matches );
        if ( isset( $matches[ 1 ] ) ) {
            $json = json_decode( $matches[ 1 ], true, 512, JSON_THROW_ON_ERROR );

            if ( isset( $json[ '@graph' ] ) ) {
                $product_key = array_search( 'Product', array_column( $json[ '@graph' ], '@type' ), true );
                $this->product_info = $json[ '@graph' ][ $product_key ];

                $offer_key = array_search( 'Offer', array_column( $this->product_info[ 'offers' ], '@type' ), true );
                $this->product_info[ 'offer' ] = $this->product_info[ 'offers' ][ $offer_key ];

                $this->parseDimsAndAttributes();
                $this->parseDescriptionShortAndAttributes();
            }
        }
    }

    public function afterParse( FeedItem $fi ): void
    {
        if ( $this->productNotValid() ) {
            $fi->setIsGroup( false );
            $fi->setCostToUs( 0 );
            $fi->setRAvail( 0 );
            $fi->setMpn( '' );
            $fi->setImages( [] );
        }
    }

    public function getProduct(): string
    {
        return $this->product_info[ 'name' ] ?? '';
    }

    public function isGroup(): bool
    {
        if ( $this->productNotValid() ) {
            return false;
        }

        return $this->exists( '.variations_form' );
    }

    public function getMpn(): string
    {
        return $this->product_info[ 'sku' ] ?? '';
    }

    public function getBrand(): ?string
    {
        return $this->product_info[ 'brand' ] ?? null;
    }

    public function getDescription(): string
    {
        return $this->product_info[ 'description' ] ?? $this->getProduct();
    }

    public function getShortDescription(): array
    {
        return $this->product_info[ 'short_description' ] ?? [];
    }

    public function getImages(): array
    {
        return $this->getAttrs( '[data-large_image]', 'data-large_image' );
    }

    public function getAttributes(): ?array
    {
        return $this->product_info[ 'attributes' ] ?? null;
    }

    public function getCostToUs(): float
    {
        return isset( $this->product_info[ 'offer' ][ 'price' ] ) ? StringHelper::getMoney( $this->product_info[ 'offer' ][ 'price' ] ) : 0;
    }

    public function getAvail(): ?int
    {
        return StringHelper::getFloat( $this->getText( 'p.in-stock' ), 0 );
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
            }

            $sku = $variation[ 'sku' ] ? $variation[ 'sku' ] . '-' . $variation[ 'variation_id' ] : $variation[ 'variation_id' ];

            $fi->setProduct( $product_name );
            $fi->setMpn( $sku );
            $fi->setImages( in_array( $variation[ 'image' ][ 'url' ], $fi->images, true ) && count( $fi->images ) > 1 ? $fi->images : [ $variation[ 'image' ][ 'url' ] ] );
            $fi->setCostToUs( StringHelper::getMoney( $variation[ 'display_price' ] ) );
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

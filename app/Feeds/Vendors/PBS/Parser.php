<?php

namespace App\Feeds\Vendors\PBS;

use App\Feeds\Parser\HtmlParser;
use App\Helpers\StringHelper;

class Parser extends HtmlParser
{
    private array $product_info;

    public const NOT_VALID_PARTS_OF_DESC = [
        'No Credit Needed',
        'Click Banner',
    ];
    public const REQUEST_PAYLOAD = '1cc57b09497262e8bab57ecc8dd9af46-0!722a387c~attempt1*7|1|7|https://app.ecwid.com/|BF7357332C74F21A3FF282EDEECCAE15|_|getOriginalProduct|4d|I|Z|1|2|3|4|3|5|6|7|0|%s|0|';
    public const SHIPPING_WEIGHT_KEY = 'Overall Gross Weight:';

    private function descriptionIsValid( string $text ): bool
    {
        foreach ( self::NOT_VALID_PARTS_OF_DESC as $str ) {
            if ( false !== stripos( $text, $str ) ) {
                return false;
            }
        }

        return true;
    }

    public function beforeParse(): void
    {
        preg_match( '/p(\d+)/', $this->getUri(), $payload_match );
        if ( isset( $payload_match[ 1 ] ) ) {
            $this->getVendor()->getDownloader()->setHeader( 'Content-Type', 'text/x-gwt-rpc; charset=UTF-8' );
            $test = $this->getVendor()->getDownloader()->post( 'https://app.ecwid.com/rpc?ownerid=47528127&customerlang=en&version=2021-37865-g11365a50609', [
                sprintf( self::REQUEST_PAYLOAD, $payload_match[ 1 ] ),
            ], 'request_payload' );
        }
        preg_match( '/<script type="application\/ld\+json">\s*({.*?})\s*<\/script>/s', $this->node->html(), $matches );
        if ( isset( $matches[ 1 ] ) ) {
            $this->product_info = json_decode( $matches[ 1 ], true, 512, JSON_THROW_ON_ERROR );
            $this->product_info[ 'description' ] = '';

            $description = preg_replace( [ '/<\w+.*?>/', '/<\/\w+>/' ], "\n", $this->getHtml( '#productDescription' ) );
            $parts_of_description = explode( "\n", StringHelper::normalizeSpaceInString( $description ) );

            if ( $parts_of_description ) {
                foreach ( $parts_of_description as $key_desc => $text ) {
                    if ( $text && $this->descriptionIsValid( $text ) ) {
                        if ( isset( $parts_of_description[ $key_desc - 1 ] ) && $parts_of_description[ $key_desc - 1 ] === self::SHIPPING_WEIGHT_KEY ) {
                            continue;
                        }

                        if ( str_contains( $text, ':' ) ) {
                            [ $key, $value ] = explode( ':', $text, 2 );

                            if ( empty( $value ) ) {
                                if ( isset( $parts_of_description[ $key_desc + 1 ] ) && false !== stripos( $key, self::SHIPPING_WEIGHT_KEY ) ) {
                                    $this->product_info[ 'shipping_weight' ] = StringHelper::getFloat( $parts_of_description[ $key_desc + 1 ] );
                                    continue;
                                }

                                $this->product_info[ 'description' ] .= '<p>' . $text . '</p>';
                            }
                            else {
                                if ( false !== stripos( $key, self::SHIPPING_WEIGHT_KEY  ) ) {
                                    $this->product_info[ 'shipping_weight' ] = StringHelper::getFloat( $value );
                                    continue;
                                }

                                $this->product_info[ 'attributes' ][ trim( $key ) ] = trim( StringHelper::normalizeSpaceInString( $value ) );
                            }
                        }
                        else {
                            $this->product_info[ 'description' ] .= '<p>' . $text . '</p>';
                        }
                    }
                }
            }
        }
    }

    public function getProduct(): string
    {
        return $this->product_info[ 'name' ] ?? '';
    }

    public function getMpn(): string
    {
        return $this->product_info[ 'sku' ] ?? '';
    }

    public function getAvail(): ?int
    {
        return isset( $this->product_info[ 'availability' ] ) && $this->product_info[ 'availability' ] === 'http://schema.org/InStock' ? self::DEFAULT_AVAIL_NUMBER : 0;
    }

    public function getDescription(): string
    {
        return $this->product_info[ 'description' ] ?? $this->getProduct();
    }

    public function getImages(): array
    {
        if ( !isset( $this->product_info[ 'image' ] ) ) {
            return [];
        }
        return is_array( $this->product_info[ 'image' ] ) ? $this->product_info[ 'image' ] : [ $this->product_info[ 'image' ] ];
    }

    public function getAttributes(): ?array
    {
        return $this->product_info[ 'attributes' ] ?? null;
    }

    public function getCostToUs(): float
    {
        return StringHelper::getMoney( $this->getAttr( '[itemprop="price"]', 'content' ) );
    }

    public function getCategories(): array
    {
        return array_values( array_slice( $this->getContent( '.ec-breadcrumbs a' ), 2, -1 ) );
    }

    public function getWeight(): ?float
    {
        return $this->product_info[ 'weight' ] ?? null;
    }

    public function getShippingWeight(): ?float
    {
        return $this->product_info[ 'shipping_weight' ] ?? null;
    }
}

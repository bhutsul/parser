<?php

namespace App\Feeds\Vendors\WTL;

use App\Feeds\Parser\HtmlParser;
use App\Feeds\Utils\ParserCrawler;
use App\Helpers\FeedHelper;
use App\Helpers\HttpHelper;
use App\Helpers\StringHelper;

class Parser extends HtmlParser
{
    public const NOT_VALID_PARTS_OF_DESC = [
        'Features',
        'WARNING',
        'www.P65Warnings.ca.gov',
        'Item Dimensions:',
        'Item Dimensions',
        'Carton Dimensions:',
        'Assembled Dimensions:',
    ];
    public const DIMS_REGEX = '/(\d+[\.]?\d*)[\',",″][a-z, A-Z]{1,1}/u';
    public const WEIGHT_REGEX = '/(\d+[.]?\d*)[\s]?lbs|lb/u';

    private array $product_info;

    private function dimsFromString( string $text, int $x_index = 0, int $y_index = 1, int $z_index = 2 ): array
    {
        $dims = [
            'x' => null,
            'y' => null,
            'z' => null
        ];
        if ( preg_match( self::WEIGHT_REGEX, $text, $matches ) && isset( $matches[ 1 ] ) ) {
            $weight = StringHelper::getFloat( $matches[ 1 ] );
        }
        if ( preg_match_all( self::DIMS_REGEX, $text, $matches ) && isset( $matches[ 1 ] ) ) {
            $dims[ 'x' ] = isset( $matches[ 1 ][ $x_index ] ) ? StringHelper::getFloat( $matches[ 1 ][ $x_index ] ) : null;
            $dims[ 'y' ] = isset( $matches[ 1 ][ $y_index ] ) ? StringHelper::getFloat( $matches[ 1 ][ $y_index ] ) : null;
            $dims[ 'z' ] = isset( $matches[ 1 ][ $z_index ] ) ? StringHelper::getFloat( $matches[ 1 ][ $z_index ] ) : null;
        }

        return [
            'dims' => $dims,
            'weight' => $weight ?? null,
        ];
    }

    private function pushAttr(string $text): void
    {
        $text = trim( strip_tags( $text ) );
        [ $key, $value ] = explode( ':', $text, 2 );
        $this->product_info[ 'attributes' ][ trim( $key ) ] = trim( StringHelper::normalizeSpaceInString( $value ) );
    }

    public function beforeParse(): void
    {
        $this->getVendor()->getDownloader()->setUserAgent( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/93.0.4577.63 Safari/537.36' );
        $cookies = HttpHelper::sucuri( $this->getVendor()->getDownloader()->get( $this->getUri() )->getData() );
        if ( isset( $cookies[ 0 ], $cookies[ 1 ] ) ) {
            $this->getVendor()->getDownloader()->setCookie( $cookies[ 0 ], $cookies[ 1 ] );
        }
        $this->node = new ParserCrawler( $this->getVendor()->getDownloader()->get( $this->getUri() )->getData() );
        if ( $this->exists( '.views-field-field-description' ) ) {
            $short_info = FeedHelper::getShortsAndAttributesInList( $this->getHtml( '.views-field-field-description' ) );

            $this->product_info[ 'attributes' ] = $short_info[ 'attributes' ];
            $this->product_info[ 'short_description' ] = $short_info[ 'short_description' ] ?: null;
            $this->product_info[ 'description' ] = '';

            $this->filter( '.views-field-field-description p' )->each( function ( ParserCrawler $c ) {
                $description = $c->text();
                if ( $description ) {
                    $not_valid = false;
                    foreach ( self::NOT_VALID_PARTS_OF_DESC as $text ) {
                        if ( false !== stripos( $description, $text ) ) {
                            $not_valid = true;
                        }
                    }

                    if (
                        false !== stripos( $description, 'Item Dimensions' )
                        && false !== stripos( $description, 'Item Dimensions' )
                    ) {
                        $dims = $this->dimsFromString( $description, 1, 2, 0 );
                        $this->product_info[ 'weight' ] = $dims[ 'weight' ];
                        $this->product_info[ 'dims' ] = $dims[ 'dims' ];
                    }
                    else if ( false !== stripos( $description, 'assembled dimensions' ) ) {
//                        if ( !$c->exists( 'br' ) ) {
//                            $dims = $this->dimsFromString( $description, 0, 2, 1 );
//                            $this->product_info[ 'weight' ] = $dims[ 'weight' ];
//                            $this->product_info[ 'dims' ] = $dims[ 'dims' ];
//                        }
//                        else {
                            $parts_of_attr = explode( '<br>', $c->html() );

                            foreach ( $parts_of_attr as $part_of_attr ) {
                                if ( !$part_of_attr ) {
                                    continue;
                                }
                                if ( false !== stripos( $part_of_attr, 'Carton Dimensions' ) ) {
                                    $dims = $this->dimsFromString( $part_of_attr );
                                    $this->product_info[ 'shipping_weight' ] = $dims[ 'weight' ];
                                    $this->product_info[ 'shipping_dims' ] = $dims[ 'dims' ];
                                }
                                else {
                                    $this->pushAttr($part_of_attr);
                                }
                            }
//                        }
                    }
                    else if ( false !== stripos( $description, 'Carton Dimensions' ) ) {
                        $dims = $this->dimsFromString( $description );
                        $this->product_info[ 'shipping_weight' ] = $dims[ 'weight' ];
                        $this->product_info[ 'shipping_dims' ] = $dims[ 'dims' ];
                    }
                    else if ( str_starts_with( $description, 'Table' ) ) {
                        $this->pushAttr($description);
                    }

                    if ( $not_valid === false ) {
                        $this->product_info[ 'description' ] .= '<p>' . $description . '</p>';
                    }
                }
            } );

            $this->filter( '.views-field-field-description iframe' )->each( function ( ParserCrawler $iframe ) {
                $this->product_info[ 'videos' ][] = [
                    'name' => $this->getProduct(),
                    'provider' => 'youtube',
                    'video' => $iframe->attr( 'src' ),
                ];
            } );
        }
    }

    public function getProduct(): string
    {
        return $this->getText( 'h1.page-title' );
    }

    public function getMpn(): string
    {
        return $this->getText( '.views-field-field-itemno .field-content' );
    }

    public function getShortDescription(): array
    {
        return $this->product_info[ 'short_description' ] ?? [];
    }

    public function getDescription(): string
    {
        return $this->product_info[ 'description' ] ?? '';
    }

    public function getImages(): array
    {
        return $this->getSrcImages( '.gallery-slide img' );
    }

    public function getVideos(): array
    {
        return $this->product_info[ 'videos' ] ?? [];
    }

    public function getAttributes(): ?array
    {
        return $this->product_info[ 'attributes' ] ?? null;
    }

    public function getCostToUs(): float
    {
        return StringHelper::getMoney( $this->product_info[ 'price' ] ?? 0 );
    }

    public function getAvail(): ?int
    {
        return self::DEFAULT_AVAIL_NUMBER;
    }

    public function getCategories(): array
    {
        return array_values( array_slice( $this->getContent( '.breadcrumbs a' ), 2, -1 ) );
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

    public function getShippingDimX(): ?float
    {
        return $this->product_info[ 'shipping_dims' ][ 'x' ] ?? null;
    }

    public function getShippingDimY(): ?float
    {
        return $this->product_info[ 'shipping_dims' ][ 'y' ] ?? null;
    }

    public function getShippingDimZ(): ?float
    {
        return $this->product_info[ 'shipping_dims' ][ 'z' ] ?? null;
    }

    public function getShippingWeight(): ?float
    {
        return $this->product_info[ 'shipping_weight' ] ?? null;
    }
}

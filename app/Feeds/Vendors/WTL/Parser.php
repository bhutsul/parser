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
        'Shipping Dimensions',
    ];
    public const DIMS_REGEXES = [
        'shipping_weight' => '/(\d+[.]?\d*)[\s]?lbs|lb/u',
        'WDH' => '/(\d+[\.]?\d*)[^\w\s]?[\s]?W[\s]?[x,X][\s]?(\d+[\.]?\d*)[^\w\s]?[\s]?D[\s]?[x,X]?[\s]?(\d+[\.]?\d*)[^\w\s]?[\s]?H/ui',
        'LWH' => '/(\d+[\.]?\d*)[^\w\s]?[\s]?L[^\w\s]?[\s]?[x,X][\s]?(\d+[\.]?\d*)[^\w\s]?[\s]?W[^\w\s]?[\s]?[x,X]?[\s]?(\d+[\.]?\d*)[^\w\s]?[\s]?H/ui',
        'LWD' => '/(\d+[\.]?\d*)[^\w\s]?[\s]?L[^\w\s]?[\s]?[x,X][\s]?(\d+[\.]?\d*)[^\w\s]?[\s]?W[^\w\s]?[\s]?[x,X]?[\s]?(\d+[\.]?\d*)[^\w\s]?[\s]?D/ui',
        'WLH' => '/(\d+[\.]?\d*)[^\w\s]?[\s]?W[^\w\s]?[\s]?[x,X][\s]?(\d+[\.]?\d*)[^\w\s]?[\s]?L[^\w\s]?[\s]?[x,X]?[\s]?(\d+[\.]?\d*)[^\w\s]?[\s]?H/ui',
        'WHH' => '/(\d+[\.]?\d*)[^\w\s]?[\s]?W[^\w\s]?[\s]?[x,X][\s]?(\d+[\.]?\d*)[^\w\s]?[\s]?H[^\w\s]?[\s]?[x,X]?[\s]?(\d+[\.]?\d*)[^\w\s]?[\s]?H/ui',
        'DIH' => '/(\d+[\.]?\d*)[^\w\s]?[\s]?dia[.]?[\s]?[x,X][\s]?(\d+[\.]?\d*)[^\w\s]?[\s]?H/ui',
        'XXX' => '/(\d+[\.]?\d*)[\s]?[x,X][\s]?(\d+[\.]?\d*)[\s]?[x,X]?[\s]?(\d+[\.]?\d*)/ui',
        'LW' => '/(\d+[\.]?\d*)[^\w\s]?[\s]?L[\s]?[x,X][\s]?(\d+[\.]?\d*)[^\w\s]?[\s]?W/ui',
    ];
    public const WEIGHT_REGEX = '/(\d+[.]?\d*)[\s]?lbs|lb/u';

    private array $product_info;

    private function dimsFromString( string $text ): array
    {
        $text = StringHelper::removeSpaces( $text );

        if ( preg_match( self::WEIGHT_REGEX, $text, $matches ) && isset( $matches[ 1 ] ) ) {
            $weight = StringHelper::getFloat( $matches[ 1 ] );
            $text = preg_replace( '/[,]?[\s]?weight:[\s]?' . $matches[ 0 ] . '[.]?/i', '', $text );
        }

        if ( preg_match( self::DIMS_REGEXES[ 'WDH' ], $text ) ) {
            $dims = FeedHelper::getDimsRegexp( $text, [ self::DIMS_REGEXES[ 'WDH' ] ], 1, 3, 2 );
        }
        else if ( preg_match( self::DIMS_REGEXES[ 'LWH' ], $text ) ) {
            $dims = FeedHelper::getDimsRegexp( $text, [ self::DIMS_REGEXES[ 'LWH' ] ], 1, 3, 2 );
        }
        else if ( preg_match( self::DIMS_REGEXES[ 'LWD' ], $text ) ) {
            $dims = FeedHelper::getDimsRegexp( $text, [ self::DIMS_REGEXES[ 'LWD' ] ] );
        }
        else if ( preg_match( self::DIMS_REGEXES[ 'WLH' ], $text ) ) {
            $dims = FeedHelper::getDimsRegexp( $text, [ self::DIMS_REGEXES[ 'WLH' ] ], 1, 3, 2 );
        }
        else if ( preg_match( self::DIMS_REGEXES[ 'DIH' ], $text ) ) {
            $dims = FeedHelper::getDimsRegexp( $text, [ self::DIMS_REGEXES[ 'DIH' ] ] );
        }
        else if ( preg_match( self::DIMS_REGEXES[ 'XXX' ], $text ) ) {
            $dims = FeedHelper::getDimsRegexp( $text, [ self::DIMS_REGEXES[ 'XXX' ] ] );
        }
        else if ( preg_match( self::DIMS_REGEXES[ 'LW' ], $text ) ) {
            $dims = FeedHelper::getDimsRegexp( $text, [ self::DIMS_REGEXES[ 'LW' ] ], 2, 3, 1 );
        }
        else if ( preg_match( self::DIMS_REGEXES[ 'WHH' ], $text ) ) {
            $dims = FeedHelper::getDimsRegexp( $text, [ self::DIMS_REGEXES[ 'WHH' ] ] );
        }
        else {
            $this->product_info[ 'description' ] .= '<p>' . $text . '</p>';
        }

        return [
            'dims' => $dims ?? null,
            'weight' => $weight ?? null,
        ];
    }

    private function replaceNode(): void
    {
        $this->getVendor()->getDownloader()->setUserAgent( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/93.0.4577.63 Safari/537.36' );
        $cookies = HttpHelper::sucuri( $this->getVendor()->getDownloader()->get( $this->getUri() )->getData() );
        if ( isset( $cookies[ 0 ], $cookies[ 1 ] ) ) {
            $this->getVendor()->getDownloader()->setCookie( $cookies[ 0 ], $cookies[ 1 ] );
        }
        $this->node = new ParserCrawler( $this->getVendor()->getDownloader()->get( $this->getUri() )->getData() );
    }

    public function beforeParse(): void
    {
        $this->replaceNode();

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
                        || false !== stripos( $description, 'Item Dimensions' )
                        || false !== stripos( $description, 'assembled dimensions' )
                    ) {
                        if (
                            false === stripos( $description, 'Provided dimensions ' )
                            && false === stripos( $description, 'dimensions may be rounded' )
                            && (
                                $description === 'Assembled Dimensions: '
                                || $c->exists( 'br' )
                                || false !== stripos( $description, 'Head Unit' )
                            )
                        ) {
                            if ( preg_match( '/(Carton Dimensions\s*.*s[.]?)/ui', $description, $shipping_matches ) && isset( $shipping_matches[ 1 ] ) ) {
                                $description = str_replace( $shipping_matches[ 1 ], '', $description );
                                $dims = $this->dimsFromString( $shipping_matches[ 1 ] );
                                $this->product_info[ 'shipping_weight' ] = $dims[ 'weight' ];
                                $this->product_info[ 'shipping_dims' ] = $dims[ 'dims' ];
                            }
                            $not_valid = false;
                        }
                        else {
                            $dims = $this->dimsFromString( $description );
                            $this->product_info[ 'weight' ] = $dims[ 'weight' ];
                            $this->product_info[ 'dims' ] = $dims[ 'dims' ];
                        }
                    }
                    else if (
                        false !== stripos( $description, 'Carton Dimensions' )
                        || false !== stripos( $description, 'Shipping Dimensions' )
                    ) {
                        $dims = $this->dimsFromString( $description );
                        $this->product_info[ 'shipping_weight' ] = $dims[ 'weight' ];
                        $this->product_info[ 'shipping_dims' ] = $dims[ 'dims' ];
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
                    'video' => false === stripos( $iframe->attr( 'src' ), 'https' ) ? 'https://' . ltrim( $iframe->attr( 'src' ), '//' ) : $iframe->attr( 'src' ),
                ];
            } );

            $this->filter( '.views-field-field-description object embed' )->each( function ( ParserCrawler $iframe ) {
                $this->product_info[ 'videos' ][] = [
                    'name' => $this->getProduct(),
                    'provider' => 'youtube',
                    'video' => $iframe->attr( 'mce_src' ),
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
        return ltrim( $this->getText( '.views-field-field-itemno .field-content' ), 'Item #: ' );
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
        return $this->getAttrs( '.gallery-slide a', 'href' );
    }

    public function getVideos(): array
    {
        return $this->product_info[ 'videos' ] ?? [];
    }

    public function getAttributes(): ?array
    {
        return isset( $this->product_info[ 'attributes' ] ) && $this->product_info[ 'attributes' ] ? $this->product_info[ 'attributes' ] : null;
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

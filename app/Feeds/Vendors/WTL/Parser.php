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
    public const WEIGHT_REGEX = '/(\d+[.]?\d*)[\s]?lbs|lb/u';
    public const DIMS_REGEXES = [
        'XYZ' => [
            '/(\d+[\.]?\d*)[^\w\s]?[\s]?L[^\w\s]?[\s]?[x,X][\s]?(\d+[\.]?\d*)[^\w\s]?[\s]?W[^\w\s]?[\s]?[x,X]?[\s]?(\d+[\.]?\d*)[^\w\s]?[\s]?D/ui',
            '/(\d+[\.]?\d*)[^\w\s]?[\s]?W[^\w\s]?[\s]?[x,X][\s]?(\d+[\.]?\d*)[^\w\s]?[\s]?H[^\w\s]?[\s]?[x,X]?[\s]?(\d+[\.]?\d*)[^\w\s]?[\s]?H/ui',
            '/(\d+[\.]?\d*)[\s]?[x,X][\s]?(\d+[\.]?\d*)[\s]?[x,X]?[\s]?(\d+[\.]?\d*)/ui',
            '/(\d+[\.]?\d*)[^\w\s]?[\s]?dia[.]?[\s]?[x,X][\s]?(\d+[\.]?\d*)[^\w\s]?[\s]?H/ui',
        ],
        'XZY' => [
            '/(\d+[\.]?\d*)[^\w\s]?[\s]?W[\s]?[x,X][\s]?(\d+[\.]?\d*)[^\w\s]?[\s]?D[\s]?[x,X]?[\s]?(\d+[\.]?\d*)[^\w\s]?[\s]?H/ui',
            '/(\d+[\.]?\d*)[^\w\s]?[\s]?L[^\w\s]?[\s]?[x,X][\s]?(\d+[\.]?\d*)[^\w\s]?[\s]?W[^\w\s]?[\s]?[x,X]?[\s]?(\d+[\.]?\d*)[^\w\s]?[\s]?H/ui',
            '/(\d+[\.]?\d*)[^\w\s]?[\s]?W[^\w\s]?[\s]?[x,X][\s]?(\d+[\.]?\d*)[^\w\s]?[\s]?L[^\w\s]?[\s]?[x,X]?[\s]?(\d+[\.]?\d*)[^\w\s]?[\s]?H/ui',
        ],
        'YZX' => [
            '/(\d+[\.]?\d*)[^\w\s]?[\s]?L[\s]?[x,X][\s]?(\d+[\.]?\d*)[^\w\s]?[\s]?W/ui',
        ],
    ];
    public const DIMS_KEYS = [
        'ITEM' => [
            'Item Dimensions',
            'Item Dimensions',
            'assembled dimensions',
        ],
        'SHIPPING' => [
            'Carton Dimensions',
            'Shipping Dimensions',
        ],
    ];


    private array $product_info;

    private function dimsFromString( string $text ): array
    {
        $text = StringHelper::removeSpaces( $text );

        if ( preg_match( self::WEIGHT_REGEX, $text, $matches ) && isset( $matches[ 1 ] ) ) {
            $weight = StringHelper::getFloat( $matches[ 1 ] );
            $text = preg_replace( '/[,]?[\s]?weight:[\s]?' . $matches[ 0 ] . '[.]?/i', '', $text );
        }

        foreach ( self::DIMS_REGEXES as $key => $regexes ) {
            foreach ( $regexes as $regex ) {
                if ( preg_match( $regex, $text ) ) {
                    $dims = match ( $key ) {
                        'XYZ' => FeedHelper::getDimsRegexp( $text, [ $regex ] ),
                        'XZY' => FeedHelper::getDimsRegexp( $text, [ $regex ], 1, 3, 2 ),
                        'YZX' => FeedHelper::getDimsRegexp( $text, [ $regex ], 2, 3, 1 ),
                    };
                    break 2;
                }
            }
        }

        if ( !isset( $dims ) ) {
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

    public function pushShippingDims( string $text ): void
    {
        $dims = $this->dimsFromString( $text );
        $this->product_info[ 'shipping_weight' ] = $dims[ 'weight' ];
        $this->product_info[ 'shipping_dims' ] = $dims[ 'dims' ];
    }

    private function pushDims( string $description ): void
    {
        $dims = $this->dimsFromString( $description );
        $this->product_info[ 'weight' ] = $dims[ 'weight' ];
        $this->product_info[ 'dims' ] = $dims[ 'dims' ];
    }

    private function dimsNotValid( ParserCrawler $description ): bool
    {
        return false === stripos( $description->text(), 'Provided dimensions ' )
            && false === stripos( $description->text(), 'dimensions may be rounded' )
            && (
                $description->text() === 'Assembled Dimensions: '
                || $description->exists( 'br' )
                || false !== stripos( $description->text(), 'Head Unit' )
            );
    }

    private function replaceAndGetShippingFromNotValidItemDims( string &$description ): void
    {
        if ( preg_match( '/(Carton Dimensions\s*.*s[.]?)/ui', $description, $shipping_matches ) && isset( $shipping_matches[ 1 ] ) ) {
            $description = str_replace( $shipping_matches[ 1 ], '', $description );
            $this->pushShippingDims( $shipping_matches[ 1 ] );
        }
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

                    foreach ( self::DIMS_KEYS as $key => $texts ) {
                        foreach ( $texts as $text ) {
                            if ( false !== stripos( $description, $text ) ) {
                                $not_valid = true;

                                switch ( $key ) {
                                    case 'SHIPPING':
                                        $this->pushShippingDims( $description );
                                        break;
                                    case 'ITEM':
                                        if ( $this->dimsNotValid( $c ) ) {
                                            $this->replaceAndGetShippingFromNotValidItemDims( $description );
                                            $not_valid = false;
                                        }
                                        else {
                                            $this->pushDims( $description );
                                        }
                                        break;
                                }

                                break 2;
                            }
                        }
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

<?php

namespace App\Feeds\Vendors\PBS;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Parser\HtmlParser;
use App\Feeds\Utils\ParserCrawler;
use App\Helpers\FeedHelper;
use App\Helpers\StringHelper;

class Parser extends HtmlParser
{
    private array $product_info;

    public const NOT_VALID_PARTS_OF_DESC = [
        'No Credit Needed',
        'Click Banner',
    ];
    public const NOT_VALID_ATTRIBUTE_VALUES = [
        'Dimensions',
        'End Table',
        'Overall Dimension',
    ];
    public const NOT_VALID_PRODUCTS = [
        'Not Shippable',
        'No Shipping',
        'not available',
    ];
    public const REQUEST_PAYLOAD = '1cc57b09497262e8bab57ecc8dd9af46-0!722a387c~attempt1*7|1|7|https://app.ecwid.com/|BF7357332C74F21A3FF282EDEECCAE15|_|getOriginalProduct|4d|I|Z|1|2|3|4|3|5|6|7|0|%s|0|';
    public const SHIPPING_WEIGHT_KEY = 'Overall Gross Weight';
    public const WEIGHT_KEY = 'Overall Net Weight';

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
        for ( $i = 0; $i <= 2; $i++ ) {
            preg_match( '/<script type="application\/ld\+json">\s*({.*?})\s*<\/script>/s', $this->node->html(), $matches );
            if ( isset( $matches[ 1 ] ) ) {
                $this->product_info = json_decode( $matches[ 1 ], true, 512, JSON_THROW_ON_ERROR );
                $this->product_info[ 'description' ] = '';

                $description = preg_replace( [ '/<\w+.*?>/', '/<\/\w+>/' ], "\n", $this->getHtml( '#productDescription' ) );
                $parts_of_description = explode( "\n", StringHelper::normalizeSpaceInString( $description ) );

                if ( $parts_of_description ) {
                    if ( in_array( 'Right Arm Facing Bump Chaise:', $parts_of_description ) ) {
                        $this->product_info[ 'description' ] = "<p>" . implode( '</p><p>', $parts_of_description ) . "</p>";
                    }
                    else {
                        $parts_of_description_length = count( $parts_of_description );
                        for ( $key_desc = 0; $key_desc < $parts_of_description_length; $key_desc++ ) {
                            $text = $parts_of_description[ $key_desc ];
                            if ( $text && $this->descriptionIsValid( $text ) ) {
                                if ( str_contains( $text, ':' ) ) {
                                    [ $key, $value ] = explode( ':', $text, 2 );

                                    if ( empty( trim( $value ) ) ) {
                                        if ( isset( $parts_of_description[ $key_desc + 1 ] ) ) {
                                            if ( false !== stripos( $key, self::SHIPPING_WEIGHT_KEY ) ) {
                                                $this->product_info[ 'shipping_weight' ] = StringHelper::getFloat( $parts_of_description[ $key_desc + 1 ] );
                                                $key_desc++;
                                                continue;
                                            }

                                            if ( false !== stripos( $key, self::WEIGHT_KEY ) ) {
                                                $this->product_info[ 'weight' ] = StringHelper::getFloat( $parts_of_description[ $key_desc + 1 ] );
                                                $key_desc++;
                                                continue;
                                            }

                                            if ( $key === 'Overall Dimension' ) {
                                                $this->product_info[ 'dims' ] = FeedHelper::getDimsInString( $parts_of_description[ $key_desc + 1 ], 'x', 0, 2, 1 );
                                                $key_desc++;
                                                continue;
                                            }
                                        }

                                        foreach ( self::NOT_VALID_ATTRIBUTE_VALUES as $str ) {
                                            if ( false !== stripos( $key, $str ) ) {
                                                continue 2;
                                            }
                                        }

                                        $this->product_info[ 'description' ] .= '<p>' . $text . '</p>';
                                    }
                                    else {
                                        switch ( $key ) {
                                            case 'Height':
                                                $this->product_info[ 'dims' ][ 'y' ] = StringHelper::getFloat( $value );
                                                break;
                                            case 'Width':
                                                $this->product_info[ 'dims' ][ 'x' ] = StringHelper::getFloat( $value );
                                                break;
                                            case 'Depth':
                                                $this->product_info[ 'dims' ][ 'z' ] = StringHelper::getFloat( $value );
                                                break;
                                            case 'Weight':
                                                $this->product_info[ 'weight' ] = StringHelper::getFloat( $value );
                                                break;
                                            case 'Dimensions':
                                            case 'Overall Dimension':
                                                $this->product_info[ 'dims' ] = FeedHelper::getDimsInString( $value, 'x', 0, 2, 1 );
                                                break;
                                            case self::SHIPPING_WEIGHT_KEY:
                                                $this->product_info[ 'shipping_weight' ] = StringHelper::getFloat( $value );
                                                break;
                                            default:
                                                $this->product_info[ 'attributes' ][ trim( $key ) ] = trim( StringHelper::normalizeSpaceInString( $value ) );
                                                break;
                                        }
                                    }
                                }
                                else {
                                    $this->product_info[ 'description' ] .= '<p>' . $text . '</p>';
                                }
                            }
                        }
                    }
                }

                break;
            }

            $this->node = new ParserCrawler( $this->getVendor()->getDownloader()->get( $this->getUri() )->getData() );
        }
    }

    public function afterParse( FeedItem $fi ): void
    {
        preg_match( '/p(\d+)/', $this->getUri(), $payload_match );
        if ( isset( $payload_match[ 1 ] ) ) {
            $version_href = $this->getAttr( '[as="script"]', 'href' );
            $version_regex = '/' . date( 'Y' ) . '\/(.*?)\//';
            $owner_href = $this->getAttr( '[property="og:image"]', 'content' );
            $owner_regex = '/images\/(.*?)\//';

            preg_match( $version_regex, $version_href, $version_match );
            preg_match( $owner_regex, $owner_href, $owner_match );

            if ( isset( $version_match[ 1 ], $owner_match[ 1 ] ) ) {
                $this->getVendor()->getDownloader()->setHeader( 'Content-Type', 'text/x-gwt-rpc; charset=UTF-8' );

                $link = 'https://app.ecwid.com/rpc?';
                $link .= 'ownerid=' . $owner_match[ 1 ];
                $link .= '&customerlang=en&';
                $link .= 'version=' . $version_match[ 1 ];

                $data = $this->getVendor()->getDownloader()->post( $link, [
                    sprintf( self::REQUEST_PAYLOAD, $payload_match[ 1 ] ),
                ], 'raw_data' )->getData();

                foreach ( self::NOT_VALID_PRODUCTS as $str ) {
                    if ( false !== stripos( $data, $str ) ) {
                        $fi->setCostToUs( 0 );
                        $fi->setRAvail( 0 );
                        $fi->setMpn( '' );
                        $fi->setImages( [] );
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
        return isset( $this->product_info[ 'offers' ][ 'availability' ] ) && $this->product_info[ 'offers' ][ 'availability' ] === 'http://schema.org/InStock' ? self::DEFAULT_AVAIL_NUMBER : 0;
    }

    public function getDescription(): string
    {
        return !empty( $this->product_info[ 'description' ] ) ? $this->product_info[ 'description' ] : $this->getProduct();
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

    public function getShippingWeight(): ?float
    {
        return $this->product_info[ 'shipping_weight' ] ?? null;
    }
}

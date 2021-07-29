<?php

namespace App\Feeds\Vendors\ULE;

use App\Feeds\Parser\HtmlParser;
use App\Feeds\Utils\ParserCrawler;
use App\Helpers\FeedHelper;
use App\Helpers\StringHelper;

class Parser extends HtmlParser
{
    public const DIMENSIONS_REGEXES = [
        'WDH' => '/(\d+[\.]?\d*)\sx\s*(\d+[\.]?\d*)\sx\s*(\d+[\.]?\d*)["]?/i',
        'WH' => '/(\d+[\/]?\d*)[",\']?\sx\s*(\d+[\/]?\d*)[",\']?/i',
    ];

    private array $product_info = [];

    /**
     *product dims pattern
     */
    private function pushDimsToProduct(): void
    {
        if ( !isset( $this->product_info[ 'name' ] ) ) {
            return;
        }

        if ( preg_match( self::DIMENSIONS_REGEXES['WDH'], $this->product_info[ 'name' ] ) ) {
            $dims = ['desc' => $this->product_info[ 'name' ], 'regex' => [self::DIMENSIONS_REGEXES['WDH']], 'x' => 1, 'y' => 3, 'z' => 2];
        }
        else if ( preg_match( self::DIMENSIONS_REGEXES['WH'], $this->product_info[ 'name' ] ) ) {
            $dims = ['desc' => $this->product_info[ 'name' ], 'regex' => [self::DIMENSIONS_REGEXES['WH']], 'x' => 1, 'y' => 2, 'z' => 3];
        }

        if ( !isset( $dims ) ) {
            return;
        }

        $dims = FeedHelper::getDimsRegexp( $dims['desc'], $dims['regex'], $dims['x'], $dims['y'], $dims['z'] );

        $this->product_info['depth']  = $dims['z'];
        $this->product_info['height'] = $dims['y'];
        $this->product_info['width']  = $dims['x'];
    }

    /**
     * @return void
     */
    private function pushProductAttributeValues(): void
    {
        if ( !$this->exists( 'td#tdChart' ) ) {
            return;
        }

        $table = $this->filter( 'td#tdChart table tr' );

        $table_header  = $table->first();
        $next_elements = $table_header->nextAll();
        $prices_values = $next_elements->first();
        $table_values  = $prices_values->nextAll()->filter( 'td.ChartCopyItemW10H18' );

        $table_values->each( function ( ParserCrawler $c ) use ( &$attributes, $table_header, $table , $table_values) {
            for ( $i = 1; $i <= $table_values->count(); $i++ ) {
                $key = $table_header->filter( 'td' )->getNode( $i );
                $value = $c->filter( 'td' )->getNode( $i );

                if ( isset( $key ) && isset( $value ) ) {
                    if ( isset( $key->firstChild ) ) {
                        //weight
//                        if ( $key->firstChild->attributes['a']->value == 1585 ) {
//                            $this->product_info['weight'] = $value->textContent;
//
//                            continue;
//                        }
//
//                        if ( in_array( $key->firstChild->attributes['a']->value, [1582] ) ) {
//                            continue;
//                        }

                        $this->product_info['attributes'][$key->textContent] = $value->textContent;
                    }
                }
            }
        });
    }

    public function beforeParse(): void
    {
        preg_match( '/<script type="application\/ld\+json">\s*(\{.*?\})\s*<\/script>/s', $this->node->html(), $matches );
        if ( isset( $matches[1] ) ) {
            $product_info = $matches[1];

            $this->product_info = json_decode( $product_info, true, 512, JSON_THROW_ON_ERROR );

            $this->pushDimsToProduct();
            $this->pushProductAttributeValues();
        }
    }

    public function getProduct(): string
    {
        return $this->product_info[ 'name' ] ?? '';
    }

    public function getShortDescription(): array
    {
        if ( !isset( $this->product_info[ 'description' ] ) ) {
            return [];
        }

        $shorts = explode( '.', $this->product_info[ 'description' ] );

        if (!$shorts) {
            return [];
        }

        return $shorts;
    }

    public function getDescription(): string
    {
        return $this->getHtml( '#productInfoContainer' );
    }

    public function getCostToUs(): float
    {
        if ( !isset( $this->product_info['offers']['priceSpecification']['price'] ) ) {
            return 0;
        }

        return StringHelper::getMoney( $this->product_info['offers']['priceSpecification'][ 'price' ] );
    }

    public function getMpn(): string
    {
        return $this->product_info[ 'sku' ] ?? '';
    }

    public function getAvail(): ?int
    {
        if ( !isset( $this->product_info['offers']['availability'] ) ) {
            return 0;
        }

        return in_array( $this->product_info['offers']['availability'], [
            'https://schema.org/InStock',
            'http://schema.org/InStock',
            'InStock'
        ] ) ? self::DEFAULT_AVAIL_NUMBER : 0;
    }

    public function getDimX(): ?float
    {
        return $this->product_info['width'] ?? null;
    }

    public function getDimY(): ?float
    {
        return $this->product_info['height'] ?? null;
    }

    public function getDimZ(): ?float
    {
        return $this->product_info['depth'] ?? null;
    }

    public function getWeight(): ?float
    {
        return isset( $this->product_info['weight'] )
            ? StringHelper::getFloat( $this->product_info['weight'] )
            : null;
    }

    public function getImages(): array
    {
        if ( !isset( $this->product_info[ 'sku' ] ) ) {
            return [];
        }

        $url = 'https://www.uline.com/api/ImagePopUp';
        $params['number'] = $this->product_info[ 'sku' ];

        $data = $this->getVendor()->getDownloader()->get($url, $params);

        $data = json_decode( $data, true, 512, JSON_THROW_ON_ERROR );

        return array_map( static fn( $image ) => $image['ZoomImageURL'], $data['Images'] );
    }

    public function getAttributes(): ?array
    {
        return $this->product_info['attributes'] ?? null;
    }

    public function getCategories(): array
    {
        return array_values( array_slice( $this->getContent( 'ul#breadCrumbs li a' ), 2, -1 ) );
    }
}

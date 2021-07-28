<?php

namespace App\Feeds\Vendors\ULI;

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
            $dims = ['desc' => $this->product_info[ 'name' ], 'regex' => [self::DIMENSIONS_REGEXES['DWH']], 'x' => 1, 'y' => 3, 'z' => 2];
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
        if ( !$this->exists( 'table#tdChart' ) ) {
            return;
        }

        $table = $this->filter( 'table#tdChart tr' );

        $table_header  = $table->first();
        $next_elements = $table_header->nextAll();
        $prices_values = $next_elements->first();
        $table_values  = $prices_values->nextAll();

        $table_values->each( function ( ParserCrawler $c ) use ( &$attributes, $table_header, $table ) {
            // -- < -- because i ignore last el add to cart of table
            for ( $i = 1; $i < $table->count(); $i++ ) {
                $key = $table_header->filter( 'td' )->getNode( $i );
                $value = $c->filter( 'td' )->getNode( $i );

                if ( isset( $key ) && isset( $value ) ) {
                    if ( stripos( $key->textContent, 'MODEL' )
                        || stripos( $key->textContent, 'DIMENSIONS' )
                        || stripos( $key->textContent, 'COLOR' )
                        || stripos( $key->textContent, 'HEIGHT' )
                    ) {
                        continue;
                    }
                    $this->product_info['attributes'][$key->textContent] = $value->textContent;
                }
            }
        });
    }

    public function beforeParse(): void
    {
        preg_match( '/application\/ld\+json">(.*)<\//', $this->node->html(), $matches );
        if ( isset( $matches[ 1 ] ) ) {
            $product_info = $matches[ 1 ];

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
        if ( !isset( $this->product_info[ 'price' ] ) ) {
            return 0;
        }

        return StringHelper::getMoney( $this->product_info[ 'price' ] );
    }

    public function getMpn(): string
    {
        return $this->product_info[ 'sku' ] ?? '';
    }

    public function getAvail(): ?int
    {
        if ( !isset( $this->product_info[ 'availability' ] ) ) {
            return 0;
        }

        return in_array( $this->product_info[ 'availability' ], [
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
}

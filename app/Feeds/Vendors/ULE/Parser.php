<?php

namespace App\Feeds\Vendors\ULE;

use App\Feeds\Parser\HtmlParser;
use App\Feeds\Utils\ParserCrawler;
use App\Helpers\FeedHelper;
use App\Helpers\StringHelper;

class Parser extends HtmlParser
{
    public const DIMENSIONS_REGEX = '/(\d{1,3}+[[\S,\s]\d{1,3}+\/\d{1,3}]?)[\',"]?[[\s,\S]x[\s,\S]]?(\d{1,3}+[[\S,\s]\d{1,3}+\/\d{1,3}]?)[\',"]?[\s,\S]?[x]?[\s,\S]?(:?\d{1,3}+[[\S,\s]\d{1,3}+\/\d{1,3}]?)?[\',"]?/i';

    private array $product_info = [];

    /**
     *product dims pattern
     * @param string $description
     * @param int $x
     * @param int $y
     * @param int $z
     */
    private function pushDimsToProduct( string $description, int $x = 1, int $y = 2, int $z = 3 ): void
    {
        $dims = FeedHelper::getDimsRegexp( $description, [self::DIMENSIONS_REGEX], $x, $y, $z );

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
        $table_values  = $prices_values->nextAll();

        $table_values->each( function ( ParserCrawler $c ) use ( &$attributes, $table_header, $table) {
            for ( $i = 1; $i <= $table->count(); $i++ ) {
                $key = $table_header->filter( 'td' )->getNode( $i );
                $value = $c->filter( 'td' )->getNode( $i );

                if ( isset( $key ) && isset( $value ) ) {
                    if ( isset( $class_name ) && $value->attributes['className']->value != 'ChartCopyItemW10H18') {
                        continue;
                    }
                    if ( isset( $key->firstChild ) ) {
                        if ( preg_match( '/WT./i', $key->textContent)
                            || preg_match( '/LBS.\/<br>ROLL/i', $key->textContent)
                        ) {
                            $this->product_info['weight'] = $value->textContent;

                            continue;
                        }

                        if ( preg_match( '/L\sx\sW\sx\sH/i', $key->textContent ) ) {
                            $this->pushDimsToProduct( $value->textContent, 2, 3, 1 );

                            continue;
                        }
                        elseif ( preg_match( '/W\sx\sD\sx\sH/i', $key->textContent ) ) {
                            $this->pushDimsToProduct( $value->textContent, 1, 3, 2 );

                            continue;
                        }


                        $this->product_info['attributes'][$key->textContent] = $value->textContent;
                    }
                }
            }
        });
    }

    private function pushFiles()
    {
        $this->filter( '.Instructions' )
            ->each( function ( ParserCrawler $c ) use ( &$attributes ) {
                $item = $c->filter( 'a' )->getNode( 0 );
                if ( isset( $item ) ) {
                    $this->product_info['files'][$item->attributes['href']->value] = [
                        'name' => $item->textContent,
                        'link' => $item->attributes['href']->value,
                    ];
                }
            });
    }

    public function beforeParse(): void
    {
        preg_match( '/<script type="application\/ld\+json">\s*(\{.*?\})\s*<\/script>/s', $this->node->html(), $matches );
        if ( isset( $matches[1] ) ) {
            $product_info = $matches[1];

            $this->product_info = json_decode( $product_info, true, 512, JSON_THROW_ON_ERROR );

            $this->pushProductAttributeValues();
            $this->pushFiles();
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

    public function getProductFiles(): array
    {
        return isset($this->product_info['files']) ? array_values( $this->product_info['files'] ) : [];
    }
}

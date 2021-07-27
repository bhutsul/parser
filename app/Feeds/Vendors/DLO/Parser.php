<?php

namespace App\Feeds\Vendors\DLO;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Parser\HtmlParser;
use App\Feeds\Utils\ParserCrawler;
use App\Helpers\FeedHelper;
use App\Helpers\StringHelper;
use Symfony\Component\DomCrawler\Crawler;


class Parser extends HtmlParser
{
    public const DIMENSIONS_REGEXES = [
        'DWH' => '/(\d+[\.]?\d*)"\(\d+[\.]?\d*\scm\)\s[a-zA-Z]\sx\s*(\d+[\.]?\d*)"\(\d+[\.]?\d*\scm\)\s[a-zA-Z]\sx\s*(\d+[\.]?\d*)"\(\d+[\.]?\d*\scm\)\s[a-zA-Z]/i',
        'HWD' => '/(\d+[\.]?\d*)"[a-zA-Z]\sx\s*(\d+[\.]?\d*)"[a-zA-Z]\sx\s*(\d+[\.]?\d*)"[a-zA-Z]/i',
        'HWD_DESC' => '/Dimensions: (\d+[\.]?\d*)"\sx\s*(\d+[\.]?\d*)"\sx\s*(\d+[\.]?\d*)"/i',
        'WEIGHT' => '/(\d+[\.]?\d*)\slbs[.\s]?[\(\d]?+[\.]?[\d*\skg\)]?/i',
        'WH' => '/(\d+[\/]?\d*)"\sx\s*(\d+[\/]?\d*)"/i',
        'WLD' => '/([\d+[\/]?\d*]?\s\d+[\/]?\d*)"\s[a-zA-Z]\sx\s*([\d+[\/]?\d*]?\s\d+[\/]?\d*)"\s[a-zA-Z]\sX\s*([\d+[\/]?\d*]?\s\d+[\/]?\d*)"\s[a-zA-Z]/i',
    ];

    private array $product_info = [];
    private ?ParserCrawler $description = null;

    /**
     * validate upc
     * @param array $product_data
     * @return string|null
     */
    private function productUpc( array $product_data ): ?string
    {
        if ( $product_data['upc'] === 'N/A' ) {
            return null;
        }

        return $product_data['upc'];
    }

    /**
     * method for all info of product
     * @param string|array $product_id
     * @return array
     * @throws \JsonException
     */
    private function executeProduct( string|array $product_id ): array
    {
        $url = 'https://delasco-live.ae-admin.com/api/product/execute';
        $params['productId'] = $product_id;

        $data = $this->getVendor()->getDownloader()->get($url, $params);

        return json_decode( $data, true, 512, JSON_THROW_ON_ERROR );
    }

    /**
     * @param array $option_values
     * @return string
     */
    private function getChildProductName( array $option_values ): string
    {
        if ( !$option_values ) {
            return '';
        }

        $option_value = array_shift( $option_values );

        return $option_value['option_display_name'] . ': ' . $option_value['label'];
    }

    /**
     * method for bad request with options(bug, product has select but does not have options)
     * @return bool
     */
    private function checkIfSameChild(): bool
    {
        if ( isset( $this->product_info['variants'] ) && $this->product_info['variants'] ) {
            return $this->product_info['sku'] === $this->product_info['variants'][0]['sku'];
        }

        return false;
    }

    /**
     * @return void
     */
    private function pushProductAttributeValues(): void
    {
        if ( $this->exists( 'table.productView-table tr' ) ) {
            $this->filter( 'table.productView-table tr' )
                ->each( function ( ParserCrawler $c ) use ( &$attributes ) {
                    $key   = $c->filter( 'td' )->getNode( 0 );
                    $value = $c->filter( 'td' )->getNode( 1 );
                    $first_value_child = $value->firstChild;

                    if ( isset( $key ) && isset( $value ) ) {
                        if ( $first_value_child->nodeName === 'a' ) {
                            $this->product_info['files'][] = [
                                'name' => $key->textContent,
                                'link' => $first_value_child->attributes['href']->value,
                            ];
                        }
                        else {
                            $this->product_info['attributes'][$key->textContent] = $value->textContent;
                        }
                    }
                });
        }

        if ( isset( $this->description ) && $this->description->exists('table tr' ) ) {
            $this->description->filter( 'table tr' )
                ->each( function ( ParserCrawler $c ) use ( &$attributes ) {
                    $item = $c->filter( 'td' )->getNode( 0 );

                    if ( isset( $item ) ) {
                        if ( $this->getMpn() == $item->textContent ) {
                            $table = $this->description->filter( 'table tr' );
                            $first_child = $table->first();

                            for ( $i = 1; $i <= $table->count(); $i++ ) {
                                $item = [
                                    'key'   => $first_child->filter( 'td' )->getNode( $i ),
                                    'value' => $c->filter( 'td' )->getNode( $i ),
                                ];

                                if ( isset( $item['key'] ) && isset( $item['value'] ) ) {
                                    $this->product_info['attributes'][$item['key']->textContent] = $item['value']->textContent;
                                }
                            }
                        }
                    }
                });
        }
    }

    /**
     * @return array|null
     */
    private function getDims(): ?array
    {
        //if in name with and height
        if (
            isset( $this->product_info['name'] )
            && preg_match( self::DIMENSIONS_REGEXES['WH'], $this->product_info['name'], $matches )
        ) {
            $dims = ['desc' => $this->product_info['name'], 'regex' => [self::DIMENSIONS_REGEXES['WH']], 'x' => 1, 'y' => 2, 'z' => 3,];
        }

        //if desc without li and has dim
        if (
            isset( $this->product_info['description'] )
            && preg_match( self::DIMENSIONS_REGEXES['HWD_DESC'], $this->product_info['description'] )
        ) {
            $description = $this->product_info['description'];
            $this->product_info['description'] = preg_replace( self::DIMENSIONS_REGEXES['HWD_DESC'], '', $description );
            $dims = ['desc' => $description, 'regex' => [self::DIMENSIONS_REGEXES['HWD_DESC']], 'x' => 2, 'y' => 1, 'z' => 3];
        }

        //if dim in short desc
        if ( isset( $this->product_info['short_description'] ) ) {
            foreach ( $this->product_info['short_description'] as $key => $li_value ) {
                if ( preg_match( self::DIMENSIONS_REGEXES['DWH'], $li_value ) ) {
                    $dims = ['desc' => $li_value, 'regex' => [self::DIMENSIONS_REGEXES['DWH']], 'x' => 2, 'y' => 3, 'z' => 1];

                    unset( $this->product_info['short_description'][$key] );
                }
                else if ( preg_match( self::DIMENSIONS_REGEXES['HWD'], $li_value ) ) {
                    $dims = ['desc' => $li_value, 'regex' => [self::DIMENSIONS_REGEXES['HWD']], 'x' => 2, 'y' => 1, 'z' => 3];

                    unset( $this->product_info['short_description'][$key] );
                }
                else if ( preg_match( self::DIMENSIONS_REGEXES['WLD'], $li_value ) ) {
                    $dims = ['desc' => $li_value, 'regex' => [self::DIMENSIONS_REGEXES['WLD']], 'x' => 1, 'y' => 2, 'z' => 3];

                    unset( $this->product_info['short_description'][$key] );
                }
                else if ( preg_match( self::DIMENSIONS_REGEXES['WH'], $li_value ) ) {
                    unset( $this->product_info['short_description'][$key] );
                }

                $weight_values = [];
                if ( preg_match( self::DIMENSIONS_REGEXES['WEIGHT'], $li_value, $weight_values ) ) {
                    $this->product_info['weight'] = isset( $weight_values[0] ) ? StringHelper::getFloat( $weight_values[0] ) : null;

                    unset( $this->product_info['short_description'][$key] );
                }

                $explode_value = explode(': ', $li_value);
                if ( count( $explode_value ) === 2 && isset( $this->product_info['short_description'][$key] ) ) {
                    $this->product_info['attributes'][$explode_value[0]] = $explode_value[1];
                    unset( $this->product_info['short_description'][$key] );
                }
            }
        }

        return $dims ?? null;
    }

    public function beforeParse(): void
    {
        $product_id = $this->getAttr( 'input[name="product_id"]', 'value' );
        if ( $product_id ) {
            $this->product_info = $this->executeProduct( $product_id );
        }

        $description = html_entity_decode( $this->getAttr( '[name="description"]', 'content' ) );
        if ( $description ) {
            $this->product_info['description'] = $description;

            $this->description = new ParserCrawler( $description );

            if ( $this->description->exists( 'li' ) ) {
                $this->product_info['short_description'] = $this->description->getContent( 'li' );
            }
        }

        $dims = $this->getDims();

        if ( isset( $dims ) ) {
            $dims = FeedHelper::getDimsRegexp( $dims['desc'], $dims['regex'], $dims['x'], $dims['y'], $dims['z'] );
            $this->product_info['depth']  = $dims['z'];
            $this->product_info['height'] = $dims['y'];
            $this->product_info['width']  = $dims['x'];
        }

        $this->pushProductAttributeValues();
    }

    public function isGroup(): bool
    {
        if ( $this->checkIfSameChild() ) {
            return false;
        }

        return $this->exists('[name*="attribute"]') ;
    }

    public function getProduct(): string
    {
        return $this->product_info['name'] ?? $this->getText('h1.productView-title');
    }

    public function getDescription(): string
    {
        return isset( $this->product_info['description'] )
            ? preg_replace([
                '/<ul\b[^>]*>(.*?)<\/ul>/i',
                '/<table\b[^>]*>(.*?)<\/table>/i',
                '/<p\b[^>]*>Features:<\/p>/i'
            ], '', $this->product_info['description'])
            : '';
    }

    public function getShortDescription(): array
    {
        return $this->product_info['short_description'] ?? [];
    }

    public function getImages(): array
    {
        return $this->getAttrs( 'a.productView-thumbnail-link', 'data-image-gallery-new-image-url' );
    }

    public function getDimX(): ?float
    {
        return $this->product_info['width'] ?? null;
    }

    public function getDimY(): ?float
    {
        return $this->product_info['height'] ?? null;
    }

    public function getProductFiles(): array
    {
        return $this->product_info['files'] ?? [];
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

    public function getMpn(): string
    {
        return $this->product_info['sku'] ?? $this->getText('.sku-section span');
    }

    public function getBrand(): ?string
    {
        return $this->getAttr( '.productView', 'data-product-brand' );
    }

    public function getCostToUs(): float
    {
        $money = $this->product_info['price'] ?? $this->getAttr( 'meta[ itemprop="price"]', 'content' );

        return StringHelper::getMoney( $money );
    }

    public function getAvail(): ?int
    {
        $availability = $this->getAttr(  'meta[ itemprop="availability"]', 'content' );

        return in_array( $availability, ['https://schema.org/InStock', 'http://schema.org/InStock', 'InStock'] )
            ? self::DEFAULT_AVAIL_NUMBER
            : 0;
    }

    public function getAttributes(): ?array
    {
        return $this->product_info['attributes'] ?? null;
    }

    public function getCategories(): array
    {
        return array_values( array_slice( $this->getContent( '.breadcrumb a' ), 2, -1 ) );
    }

    public function getChildProducts( FeedItem $parent_fi ): array
    {
        $child = [];
        if ( isset( $this->product_info['variants'] ) && $this->product_info['variants'] ) {
            foreach ( $this->product_info['variants'] as $variant ) {
                $images = $variant['image_url']
                    ? [$variant['image_url']]
                    : $this->getImages();

                $fi = clone $parent_fi;

                $fi->setMpn($variant['sku'] ?? '' );
                $fi->setUpc( $this->productUpc($variant) );
                $fi->setProduct( $this->getChildProductName($variant['option_values']) );
                $fi->setImages( $images );
                $fi->setCostToUs( StringHelper::getMoney( $variant['price'] ) );
                $fi->setRAvail( $variant['inventory_level'] );
                $fi->setDimZ($variant['depth'] ?: $this->getDimZ() );
                $fi->setDimY($variant['height'] ?: $this->getDimY() );
                $fi->setDimX($variant['width'] ?: $this->getDimX() );
                $fi->setWeight($variant['weight'] ?: $this->getWeight() );

                $child[] = $fi;
            }
        }

        return $child;
    }
}

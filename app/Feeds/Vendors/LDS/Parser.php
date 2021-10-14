<?php

namespace App\Feeds\Vendors\LDS;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Parser\HtmlParser;
use App\Feeds\Utils\Link;
use App\Feeds\Utils\ParserCrawler;
use App\Helpers\StringHelper;
use Exception;
use Generator;

class Parser extends HtmlParser
{
    public const NOT_VALID_ATTRIBUTES = [
        'SKU',
        'Brand',
        'UPC',
    ];

    public const VARIATION_URI = 'https://leodaniels.com/colorswatchproductview/get/mainImage';

    private array $product_info;

    private function getConfigOfChild(): array
    {
        preg_match( '/Product.Config\((.*?)\);/', $this->node->html(), $matches );

        try {
            return json_decode( $matches[ 1 ], true, 512, JSON_THROW_ON_ERROR );
        } catch ( Exception ) {
            return [];
        }
    }

    private function combinations( array $arrays, int $i = 0 )
    {
        if ( !isset( $arrays[ $i ] ) ) {
            return [];
        }

        if ( $i === count( $arrays ) - 1 ) {
            return $arrays[ $i ];
        }

        // get combinations from subsequent arrays
        $tmp = $this->combinations( $arrays, $i + 1 );

        $result = [];

        // concat each array from tmp with each element from $arrays[$i]
        foreach ( $arrays[ $i ] as $v ) {
            foreach ( $tmp as $t ) {
                $result[] = is_array( $t ) ? array_merge( [ $v ], $t ) : [ $v, $t ];
            }
        }

        return $result;
    }


    private function getNameOfChild( array|string $combination, array $options ): string
    {
        $name = '';

        if ( is_array( $combination ) ) {
            foreach ( $combination as $option_id ) {
                $name .= $options[ $option_id ][ 'name' ];
                $name .= ': ';
                $name .= $options[ $option_id ][ 'value' ];
                $name .= str_ends_with( $options[ $option_id ][ 'value' ], '.' ) ? ' ' : '. ';
            }
        }
        else {
            $name .= $options[ $combination ][ 'name' ];
            $name .= ': ';
            $name .= $options[ $combination ][ 'value' ];
            $name .= str_ends_with( $options[ $combination ][ 'value' ], '.' ) ? ' ' : '. ';
        }

        return $name;
    }

    private function getLinksAndNamesOfChild(): array
    {
        $config = $this->getConfigOfChild();

        $links = [];
        $names = [];
        $option_groups = [];
        $option_values_info = [];

        foreach ( $config[ 'attributes' ] as $group ) {
            $option_values = [];
            foreach ( $group[ 'options' ] as $option ) {
                $option_values[] = $option[ 'id' ];
                $option_values_info[ $option[ 'id' ] ] = [
                    'name' => $group[ 'label' ],
                    'value' => $option[ 'label' ],
                    'id' => $option[ 'id' ],
                    'group_id' => $group[ 'id' ],
                ];
            }
            $option_groups[] = $option_values;
        }

        $combinations = $this->combinations( $option_groups );

        foreach ( $combinations as $combination ) {
            $selection = [];

            if ( is_array( $combination ) ) {
                $option_id = (int)$combination[ array_key_first( $combination ) ];
                foreach ( $combination as $value ) {
                    $selection[ $option_values_info[ $value ][ 'group_id' ] ] = $value;
                }
            }
            else {
                $option_id = $combination;
                $selection[ $option_values_info[ $option_id ][ 'group_id' ] ] = $option_id;
            }
            $attribute_id = $option_values_info[ $option_id ][ 'group_id' ];

            $link = new Link( self::VARIATION_URI, 'GET', [
                'product_id' => $this->product_info[ 'id' ],
                'attribute_id' => $attribute_id,
                'option_id' => $option_id,
                'selection' => json_encode( $selection, JSON_THROW_ON_ERROR ),
            ] );

            $names[ $link->getUrl() ] = $this->getNameOfChild( $combination, $option_values_info );

            $links[] = $link;
        }

        return [
            'links' => $links,
            'names' => $names,
        ];
    }

    private function getParametersOfChild( string $json ): array
    {
        preg_match( '/product-table-description\'\).first\(\).innerHTML = \'(.*?)\';/', $json, $description );
        preg_match( '/product-page__price\'\).first\(\).innerHTML = \"(.*?)\";/', $json, $price );
        preg_match( '/galleryView.update\(\"(.*?)\"\);/', $json, $images );

        if ( !isset( $description[ 1 ], $price[ 1 ] ) ) {
            return [];
        }

        $html = $description[ 1 ] . $price[ 1 ];
        if ( !empty( $images[ 1 ] ) ) {
            $html .= $images[ 1 ];
        }
        $crawler = new ParserCrawler( preg_replace( '/\\\"/', '"', $html ) );

        $parameters = [];

        $crawler->filter( 'ul' )->each( function ( ParserCrawler $ul ) use ( &$parameters ) {
            if ( $ul->getText( '.name-of-column' ) === 'General' ) {
                $ul->filter( 'li' )->each( function ( ParserCrawler $li ) use ( &$parameters ) {
                    if ( $li->getText( 'span' ) === 'SKU:' ) {
                        $parameters[ 'sku' ] = $li->getText( 'div' );
                    }
                } );
            }
        } );

        $parameters[ 'price' ] = StringHelper::getFloat( $crawler->getText( '.regular-price' ) );
        $parameters[ 'images' ] = array_map( static fn( $image ) => preg_replace( '/\\\\/', '', $image ), $this->getParsedImages( $crawler ) );

        return $parameters;
    }

    private function fetchChild(): Generator
    {
        $initial_data = $this->getLinksAndNamesOfChild();
        $child = $this->getVendor()->getDownloader()->fetch( $initial_data[ 'links' ] );

        foreach ( $child as $item ) {
            $params = $this->getParametersOfChild( $item->getData() );
            $params[ 'name' ] = $initial_data[ 'names' ][ $item->getPageLink()->getUrl() ];

            yield $params;
        }
    }

    private function parseDescriptionTable(): void
    {
        $this->filter( '.product-table-description ul' )->each( function ( ParserCrawler $ul ) {
            if ( preg_match( '/\d+/', $ul->getText( '.name-of-column' ) ) ) {
                $this->product_info[ 'description' ] .= '<ul>' . $ul->html() . '</ul>';
            }
            else {
                $ul->filter( 'li' )->each( function ( ParserCrawler $c ) {
                    $text = $c->text();
                    if ( str_contains( $text, ':' ) ) {
                        [ $key, $value ] = explode( ':', $text, 2 );
                        if ( false !== stripos( $key, 'upc' ) ) {
                            $this->product_info[ 'upc' ] = $value;
                        }

                        if ( !empty( $key ) && !empty( $value ) ) {
                            $this->product_info[ 'attributes' ][ trim( $key ) ] = trim( StringHelper::normalizeSpaceInString( $value ) );
                        }
                    }
                    else {
                        $this->product_info[ 'short_description' ][] = $text;
                    }
                } );
            }
        } );
    }

    private function filterAttributes(): void
    {
        foreach ( self::NOT_VALID_ATTRIBUTES as $not_valid_attribute ) {
            if ( isset( $this->product_info[ 'attributes' ][ $not_valid_attribute ] ) ) {
                unset( $this->product_info[ 'attributes' ][ $not_valid_attribute ] );
            }
        }
    }

    private function getParsedImages( ParserCrawler|HtmlParser $crawler ): array
    {
        $images = $crawler->getAttrs( '[data-magic-slide-id="zoom"]', 'href' );

        if ( !$images ) {
            $images = [ $crawler->getAttr( '[data-magic-slide="zoom"] a', 'href' ) ];
        }

        return !empty( $images ) ? array_values( array_filter( $images ) ) : [];
    }

    public function beforeParse(): void
    {
        $id = $this->getAttr( 'input[name="product"]', 'value' );

        $this->product_info = [
            'id' => $id,
            'name' => $this->getText( '.product-page-mob__title' ),
            'price' => StringHelper::getFloat( $this->getText( '#product-price-' . $id ) ),
            'images' => $this->getParsedImages( $this ),
            'brand' => $this->getText( '.product-page-info .product-name-brand' ),
            'sku' => $this->getText( '.product-page__sku' ),
            'categories' => array_values( array_slice( $this->getContent( '.breadcrumbs a' ), 1 ) ),
            'description' => $this->getText( '.product-description' ),
            'attributes' => [],
            'short_description' => [],
        ];

        $this->parseDescriptionTable();

        $this->filterAttributes();
    }

    public function isGroup(): bool
    {
        return $this->exists( '#product-options-wrapper' );
    }

    public function getProduct(): string
    {
        return $this->product_info[ 'name' ] ?? '';
    }

    public function getCostToUs(): float
    {
        return $this->product_info[ 'price' ] ?? 0;
    }

    public function getMpn(): string
    {
        return $this->product_info[ 'sku' ] ?? '';
    }

    public function getUpc(): ?string
    {
        return $this->product_info[ 'upc' ] ?? null;
    }

    public function getBrand(): ?string
    {
        return $this->product_info[ 'brand' ] ?? null;
    }

    public function getImages(): array
    {
        return $this->product_info[ 'images' ] ?? [];
    }

    public function getShortDescription(): array
    {
        return $this->product_info[ 'short_description' ] ?? [];
    }

    public function getDescription(): string
    {
        return !empty( $this->product_info[ 'description' ] ) ? $this->product_info[ 'description' ] : $this->getProduct();
    }

    public function getAttributes(): ?array
    {
        return !empty( $this->product_info[ 'attributes' ] ) ? $this->product_info[ 'attributes' ] : null;
    }

    public function getAvail(): ?int
    {
        return $this->exists( '.out-of-stock span' ) ? 0 : self::DEFAULT_AVAIL_NUMBER;
    }

    public function getCategories(): array
    {
        return $this->product_info[ 'categories' ] ?? [];
    }

    public function getChildProducts( FeedItem $parent_fi ): array
    {
        $child = [];

        foreach ( $this->fetchChild() as $item ) {
            $fi = clone $parent_fi;

            $fi->setProduct( $item[ 'name' ] );
            $fi->setCostToUs( $item[ 'price' ] ?? 0 );
            $fi->setRAvail( $this->getAvail() );
            $fi->setImages( !empty( $item[ 'images' ] ) ? $item[ 'images' ] : $this->getImages() );

            $fi->setMpn( $item[ 'sku' ] ?? '' );

            $child[] = $fi;
        }

        return $child;
    }
}

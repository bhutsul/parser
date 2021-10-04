<?php

namespace App\Feeds\Vendors\LDS;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Parser\HtmlParser;
use App\Feeds\Utils\Link;
use App\Feeds\Utils\ParserCrawler;
use App\Helpers\FeedHelper;
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

    private function parseJsonFromJS( string $pattern ): array
    {
        preg_match( $pattern, $this->node->html(), $matches );

        try {
            return json_decode( $matches[ 1 ], true, 512, JSON_THROW_ON_ERROR );
        } catch ( Exception ) {
            return [];
        }
    }

    private function getCombinations(): array
    {
        return $this->parseJsonFromJS( '/initConfigurableOptions\((.*?)\)/' );
    }

    private function getConfigOfChild(): array
    {
        return $this->parseJsonFromJS( '/Product.Config\((.*?)\)/' );
    }

    private function getNameOfChild( array $combination, array $config ): string
    {
        $name = '';

        foreach ( $combination as $option => $value ) {
            $name .= $config[ 'attributes' ][ $option ][ 'label' ];
            $name .= ': ';

            $key_of_option_value = array_search( $value, array_column( $config[ 'attributes' ][ $option ][ 'options' ], 'id' ), true );

            $name .= $config[ 'attributes' ][ $option ][ 'options' ][ $key_of_option_value ][ 'label' ];
            $name .= str_ends_with( $config[ 'attributes' ][ $option ][ 'options' ][ $key_of_option_value ][ 'label' ], '.' ) ? ' ' : '. ';
        }

        return $name;
    }

    private function getLinksAndNamesOfChild(): array
    {
        $combinations = $this->getCombinations();
        $config = $this->getConfigOfChild();

        $links = [];
        $names = [];

        foreach ( $combinations as $combination ) {
            unset( $combination[ 'backorders' ] );

            $first_key = array_key_first( $combination );

            $link = new Link( self::VARIATION_URI, 'GET', [
                'product_id' => $this->product_info[ 'id' ],
                'attribute_id' => $first_key,
                'option_id' => (int)$combination[ $first_key ],
                'selection' => json_encode( $combination, JSON_THROW_ON_ERROR ),
            ] );

            $names[ $link->getUrl() ] = $this->getNameOfChild( $combination, $config );

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

        if ( !isset( $description[ 1 ], $price[ 1 ], $images[ 1 ] ) ) {
            return [];
        }

        $crawler = new ParserCrawler( preg_replace( '/\\\"/', '"', $description[ 1 ] . $price[ 1 ] . $images[ 1 ] ) );

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
        $parameters[ 'images' ] = array_map( static fn( $image ) => preg_replace( '/\\\\/', '', $image ), $this->getParsedImages($crawler) );

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
            if ( preg_match( '~[0-9]+~', $ul->getText( '.name-of-column' ) ) ) {
                $this->product_info[ 'description' ] .= '<ul>' . $ul->html() . '</ul>';
            }
            else {
                $shorts_and_attributes = FeedHelper::getShortsAndAttributesInList( $ul->html(), $this->product_info[ 'short_description' ], $this->product_info[ 'attributes' ] );
                $this->product_info[ 'short_description' ] = $shorts_and_attributes[ 'short_description' ];
                $this->product_info[ 'attributes' ] = $shorts_and_attributes[ 'attributes' ];
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

    private function getParsedImages(ParserCrawler|HtmlParser $crawler): array
    {
        $images = $crawler->getAttrs( '[data-magic-slide-id="zoom"]', 'href' );

        if (!$images) {
            $images = [$crawler->getAttr('[data-magic-slide="zoom"] a', 'href')];
        }

        return !empty($images) ? $images : [];
    }

    public function beforeParse(): void
    {
        $id = $this->getAttr( 'input[name="product"]', 'value' );

        $this->product_info = [
            'id' => $id,
            'name' => $this->getText( '.product-page-mob__title' ),
            'price' => StringHelper::getFloat( $this->getText( '#product-price-' . $id ) ),
            'images' => $this->getParsedImages($this),
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
        return !empty($this->product_info[ 'description' ]) ? $this->product_info[ 'description' ] : $this->getProduct();
    }

    public function getAttributes(): ?array
    {
        return !empty( $this->product_info[ 'attributes' ] ) ? $this->product_info[ 'attributes' ] : null;
    }

    public function getAvail(): ?int
    {
        return $this->exists('.out-of-stock span') ? 0 : self::DEFAULT_AVAIL_NUMBER;
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
            $fi->setCostToUs( $item[ 'price' ] );
            $fi->setRAvail( $this->getAvail() );
            $fi->setImages( $item[ 'images' ] );

            $fi->setMpn( $item[ 'sku' ] );

            $child[] = $fi;
        }

        return $child;
    }
}

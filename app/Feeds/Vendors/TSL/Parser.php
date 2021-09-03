<?php

namespace App\Feeds\Vendors\TSL;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Parser\HtmlParser;
use App\Feeds\Utils\Link;
use App\Feeds\Utils\ParserCrawler;
use App\Helpers\FeedHelper;
use App\Helpers\StringHelper;

class Parser extends HtmlParser
{
    public const OPTION_LINK = 'https://www.taylorsecurity.com/ajax/store/ajax.aspx';
    public const NOT_VALID_PARTS_OF_DESC = [

    ];

    private array $product_info;

    private function parseFilesAndVideos(): void
    {
        if ( $this->exists( '#tab-templates' ) ) {
            $this->filter( '#tab-templates a' )->each( function ( ParserCrawler $c ) {
                if ( false !== stripos( $c->attr( 'href' ), 'pdf' ) ) {
                    $this->product_info[ 'files' ][] = [
                        'name' => trim( str_replace( "Download", '', $c->text() ) ),
                        'link' => $c->attr( 'href' )
                    ];
                }
            } );

            $this->filter( '#tab-templates iframe' )->each( function ( ParserCrawler $iframe ) {
                $this->pushVideoFromIframe( $iframe );
            } );
        }

        if ( $this->exists( '#tab-video' ) ) {
            $this->filter( '#tab-video iframe' )->each( function ( ParserCrawler $iframe ) {
                $this->pushVideoFromIframe( $iframe );
            } );
        }
    }

    private function pushVideoFromIframe( ParserCrawler $iframe ): void
    {
        $this->product_info[ 'videos' ][] = [
            'name' => $this->getProduct(),
            'provider' => 'youtube',
            'video' => $iframe->attr( 'src' ),
        ];
    }

    private function formattedChildProperties( array $item_properties ): array
    {
        $properties = [];

        foreach ( $item_properties as $property ) {
            switch ( $property[ 'N' ] ) {
                case 'SKU':
                    $properties[ 'mpn' ] = $property[ 'V' ];
                    break;
                case 'Price':
                    $properties[ 'price' ] = StringHelper::getFloat( $property[ 'V' ] );
                    break;
                case 'Images':
                    $properties[ 'images' ] = !empty( $property[ 'V' ] ) ? $this->formattedImages( ( new ParserCrawler( $property[ 'V' ] ) )->getAttrs( 'a', 'href' ) ) : [];
                    break;
            }
        }

        return $properties;
    }

    private function formattedImages( array $images ): array
    {
        return array_map( static fn( $url ) => "https://www.taylorsecurity.com$url", $images );
    }

    private function optionNames(): array
    {
        $names = [];

        if ( !isset( $this->product_info[ 'options' ][ 'Classifications' ] ) ) {
            return $names;
        }

        foreach ( $this->product_info[ 'options' ][ 'Classifications' ] as $group ) {
            foreach ( $group[ 's' ] as $option ) {
                $names[ $option[ 'i' ] ] = $group[ 'n' ] . ': ' . $option[ 's' ] . '. ';
            }
        }

        return $names;
    }

    private function childName( array $options, array $option_names ): string
    {
        $name = '';
        foreach ( $options as $id ) {
            $name .= $option_names[ $id ];
        }
        return $name;
    }

    private function childItem( array $group_of_options ): array
    {
        $option = array_shift( $group_of_options[ 's' ] );

        $product = $this->getVendor()->getDownloader()->get( self::OPTION_LINK, [
            'F' => 'GetSelectionItemInfo',
            'SelectionId' => $option,
            'ItemId' => $group_of_options[ 'i' ],
        ] );

        $product = json_decode( $product->getData(), true, 512, JSON_THROW_ON_ERROR );

        return $this->formattedChildProperties( $product[ 'ItemProperties' ] );
    }

    private function fetchProducts(): \Generator
    {
        if ( !isset( $this->product_info[ 'options' ][ 'Items' ] ) ) {
            return [];
        }

        $links = [];
        $products_names = [];
        $option_names = $this->optionNames();

        foreach ( $this->product_info[ 'options' ][ 'Items' ] as $child_key => $group_of_options ) {
            $products_names[ $child_key ] = $this->childName( $group_of_options[ 's' ], $option_names );

            $links[] = new Link( self::OPTION_LINK, 'get', [
                'F' => 'GetSelectionItemInfo',
                'SelectionId' => $group_of_options[ 's' ][ array_key_first( $group_of_options[ 's' ] ) ],
                'ItemId' => $group_of_options[ 'i' ],
            ] );
        }

        $child_key = 0;
        $products = $this->getVendor()->getDownloader()->fetch( $links );
        foreach ( $products as $product ) {
            $product = json_decode( $product->getData(), true, 512, JSON_THROW_ON_ERROR );
            $child_properties = $this->formattedChildProperties( $product[ 'ItemProperties' ] );
            $child_properties[ 'name' ] = $products_names[ $child_key ];
            $child_key++;

            yield $child_properties;
        }
    }

    public function beforeParse(): void
    {
        $this->parseFilesAndVideos();
        if ( $this->exists( '#tab-description' ) ) {
            $short_desc_attr = FeedHelper::getShortsAndAttributesInDescription( $this->getHtml( '#tab-description' ) );
            $this->product_info[ 'short_description' ] = $short_desc_attr[ 'short_description' ];
            $this->product_info[ 'description' ] = $short_desc_attr[ 'description' ];
            $this->product_info[ 'attributes' ] = $short_desc_attr[ 'attributes' ];
        }

        preg_match( '/IdevSelections\([\s*]?({.*?})[\s*]?\);/s', $this->node->html(), $matches );
        if ( isset( $matches[ 1 ] ) ) {
            $this->product_info[ 'options' ] = json_decode( $matches[ 1 ], true, 512, JSON_THROW_ON_ERROR );
        }
    }

    public function isGroup(): bool
    {
        return isset( $this->product_info[ 'options' ][ 'Classifications' ] ) && count( $this->product_info[ 'options' ][ 'Classifications' ] );
    }

    public function getProduct(): string
    {
        return $this->getText( 'h1[itemprop="name"]' );
    }

    public function getBrand(): ?string
    {
        return $this->getText( 'span[itemprop="brand"]' );
    }

    public function getMpn(): string
    {
        return $this->getText( 'span[itemprop="sku"]' );
    }

    public function getDescription(): string
    {
        return $this->product_info[ 'description' ] ?? '';
    }

    public function getShortDescription(): array
    {
        return $this->product_info[ 'short_description' ] ?? [];
    }

    public function getImages(): array
    {
        return $this->formattedImages( $this->getAttrs( '#altImagesViewer a', 'href' ) );
    }

    public function getVideos(): array
    {
        return $this->product_info[ 'videos' ] ?? [];
    }

    public function getProductFiles(): array
    {
        return $this->product_info[ 'files' ] ?? [];
    }

    public function getAttributes(): ?array
    {
        return $this->product_info[ 'attributes' ] ?? null;
    }

    public function getCostToUs(): float
    {
        return StringHelper::getMoney( $this->getText( '#CT_ItemDetailsBottom_2_lblPrice' ) );
    }

    public function getAvail(): ?int
    {
        return $this->getAttr( 'span[property="og:availability"]', 'content' ) === 'InStock' ? self::DEFAULT_AVAIL_NUMBER : 0;
    }

    public function getCategories(): array
    {
        return array_values( array_slice( $this->getContent( '.breadcrumbs a' ), 2, -1 ) );
    }

    public function getChildProducts( FeedItem $parent_fi ): array
    {
        $child = [];

        foreach ( $this->fetchProducts() as $product ) {
            $fi = clone $parent_fi;
            $fi->setProduct( $product[ 'name' ] );
            $fi->setMpn( $product[ 'mpn' ] );
            $fi->setImages( $product[ 'images' ] );
            $fi->setCostToUs( $product[ 'price' ] ?? 0 );
            $fi->setRAvail( $this->getAvail() );

            $child[] = $fi;
        }

        return $child;
    }
}

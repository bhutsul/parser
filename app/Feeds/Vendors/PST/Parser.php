<?php

namespace App\Feeds\Vendors\PST;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Parser\HtmlParser;
use App\Feeds\Utils\Data;
use App\Feeds\Utils\Link;
use App\Feeds\Utils\ParserCrawler;
use App\Helpers\FeedHelper;
use App\Helpers\StringHelper;
use Exception;
use Generator;

class Parser extends HtmlParser
{
    public const NOT_VALID_PARTS_OF_DESC = [
        'Instagram',
        'Facebook',
        'YouTube',
        'message us',
        'message me',
        'SHIPPING',
        'Domestic',
        'usually shipped',
        'We use USPS mail services',
        'Domestic',
        'class mail',
        'Priority mail',
        'International',
        'No tracking information',
        'Each wire light is',
        'href=',
        '©',
        'our store',
        'Contacting us',
        'Customer service',
        'www.',
        '.com',
        'IN STOCK',
        'Gift Note',
        'discount offer',
        'Coupon Code',
        'purchase',
        'http:',
        'https:',
        'Feedback',
        'Postage',
        'delivery',
        '$',
        'contact us',
        '£',
        'wholesale',
        'S H I P P I N G & P R O D U C T I O N T I M E',
    ];
    public const DIGITAL_ATTR = 'digital download';
    public const QUANTITY_SELECT_ID = 'inventory-variation-select-quantity';
    public const VARIATIONS_URL = 'https://www.etsy.com/api/v3/ajax/bespoke/member/listings/%s/offerings/find-by-variations';
    public const PRICE_IN_OPTION_REGEXES = [
        '/[\s]?([(]?[usd|\$][\s]?\d+[\,]?[\.]?\d*[\,]?[\.]?\d*?[\s]?-?[\s]?[usd|\$][\s]?\d+[\,]?[\.]?\d*[\,]?[\.]?\d*?[\)]?)/ui',
        '/[\s]?([\(]?(usd|\$)[\s]?\d+[\,]?[\.]?\d*[\,]?[\.]?\d*?[\)]?)/ui',
    ];

    private array $product_info;

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

    private function parseJsonOfProduct(): void
    {
        preg_match_all( '/<script type="application\/ld\+json">\s*({.*?})\s*<\/script>/s', $this->node->html(), $matches );
        if ( !isset( $matches[ 1 ] ) ) {
            return;
        }

        foreach ( $matches[ 1 ] as $script ) {
            $json = json_decode( $script, true, 512, JSON_THROW_ON_ERROR );

            switch ( $json[ '@type' ] ) {
                case 'Product':
                    $this->product_info = $json;
                    break;
                case 'VideoObject':
                    $this->product_info[ 'videos' ][] = [
                        'name' => $json[ 'name' ],
                        'provider' => 'etsystatic',
                        'video' => $json[ 'contentUrl' ],
                    ];
                    break;
            }
        }
    }

    private function parseAttributesAndShorts(): void
    {
        if ( !$this->exists( '#product-details-content-toggle' ) ) {
            return;
        }

        $data = FeedHelper::getShortsAndAttributesInList( $this->getHtml( '#product-details-content-toggle' ) );

        $this->product_info[ 'short_description' ] = $data[ 'short_description' ];
        $this->product_info[ 'attributes' ] = array_map( static fn( $attribute ) => trim( str_replace( 'Read the full description', '', $attribute ) ), $data[ 'attributes' ] );
    }

    private function parseDescription(): void
    {
        if ( !$this->exists( '[data-id="description-text"]' ) ) {
            return;
        }

        $this->product_info[ 'description' ] = '';
        $parts_of_description = array_values( array_filter( explode( '<br><br>', $this->getHtml( '[data-id="description-text"] p' ) ) ) );

        foreach ( $parts_of_description as $key => $text ) {
            if ( false !== stripos( $text, 'Overall size' ) ) {
                $this->product_info[ 'dims' ] = FeedHelper::getDimsInString( $text, 'x' );
                continue;
            }

            if ( $this->descriptionIsValid( $parts_of_description, $key, $text ) ) {
                $text = trim( $text, " \t\n\r\0\x0B-" );
                if ( StringHelper::isNotEmpty( $text ) ) {
                    $this->product_info[ 'description' ] .= '<p>' . $text . '</p>';
                }
            }
        }
    }

    private function descriptionIsValid( array $parts_of_description, int $key, string $text ): bool
    {
        foreach ( self::NOT_VALID_PARTS_OF_DESC as $str ) {
            if ( false !== stripos( $text, $str ) ) {
                return false;
            }
        }

        return !( isset( $parts_of_description[ $key + 1 ] ) && false !== stripos( $parts_of_description[ $key + 1 ], 'href=' ) );
    }

    private function productIsNotValid(): bool
    {
        return ( $this->checkIfHasRequiredTextarea() )
            || (
                !empty( $this->product_info[ 'attributes' ] )
                && in_array( self::DIGITAL_ATTR, array_map( 'strtolower', $this->product_info[ 'short_description' ] ), true )
            );
    }

    private function nameAndMpnOfChild( array $option_values, array $options ): array
    {
        $mpn = '';
        $name = '';

        foreach ( $option_values as $key => $option_value ) {
            $name .= $options[ $option_value ][ 'name' ];
            $name .= ': ';

            $options[ $option_value ][ 'value' ] = trim( preg_replace( self::PRICE_IN_OPTION_REGEXES, '', $options[ $option_value ][ 'value' ] ) );

            $name .= $options[ $option_value ][ 'value' ];
            $name .= str_ends_with( $options[ $option_value ][ 'value' ], '.' ) ? ' ' : '. ';

            $mpn .= $options[ $option_value ][ 'id' ];
            if ( $key !== array_key_last( $option_values ) ) {
                $mpn .= '-';
            }
        }

        return [
            'name' => $name,
            'mpn' => $mpn,
        ];
    }

    private function priceOfChildFromResponse( array $item ): float
    {
        if ( empty( $item[ 'price' ] ) ) {
            return 0;
        }
        $price = new ParserCrawler( $item[ 'price' ] );

        return StringHelper::getFloat( $price->getText( 'p.wt-text-title-03' ), 0 );
    }

    private function getLinksAndPropertiesOfChild(): array
    {
        $options = [];
        $option_groups = [];
        $links = [];
        $properties = [];

        $this->filter( $this->selectorOfChild() )
            ->each( function ( ParserCrawler $select ) use ( &$options, &$option_groups ) {
                $option_values = [];

                $select->filter( 'option' )
                    ->each( function ( ParserCrawler $option ) use ( &$options, &$option_values, $select ) {
                        if ( $option->attr( 'value' ) && $select->attr( 'id' ) !== 'inventory-variation-select-quantity' ) {
                            $options[ $option->attr( 'value' ) ] = [
                                'name' => $select->parents()->parents()->getText( 'label' ),
                                'value' => $option->text(),
                                'id' => $option->attr( 'value' ),
                            ];
                            $option_values[] = $option->attr( 'value' );
                        }
                    } );
                if ( $option_values ) {
                    $option_groups[] = $option_values;
                }
            } );

        $variation_url = sprintf( self::VARIATIONS_URL, $this->getAttr( '[name="listing_id"]', 'value' ) );

        if ( count( $option_groups ) === 1 ) {
            foreach ( $options as $option ) {
                $link = new Link( $variation_url, 'GET', [
                    'listing_variation_ids' => $option[ 'id' ],
                ] );

                $properties[ $link->getUrl() ] = $this->nameAndMpnOfChild( [ $option[ 'id' ] ], $options );
                $links[] = $link;
            }
        }
        else {
            $combination_of_groups = $this->combinations( $option_groups );

            foreach ( $combination_of_groups as $option_values ) {
                $link = new Link( $variation_url . '?' . http_build_query( [
                        'listing_variation_ids' => $option_values,
                    ] ) );

                $properties[ $link->getUrl() ] = $this->nameAndMpnOfChild( $option_values, $options );
                $links[] = $link;
            }
        }

        return [
            'links' => $links,
            'properties' => $properties,
        ];
    }

    private function fetchChild(): Generator
    {
        $initial_data = $this->getLinksAndPropertiesOfChild();

        $child = $this->getVendor()->getDownloader()->fetch( $initial_data[ 'links' ] );

        foreach ( $child as $item ) {
            try {
                $json = json_decode( $item->getData(), true, 512, JSON_THROW_ON_ERROR );
            } catch ( Exception ) {
                $json = [];
            }

            $properties = $initial_data[ 'properties' ][ $item->getPageLink()->getUrl() ];
            $properties[ 'price' ] = $this->priceOfChildFromResponse( $json );

            yield $properties;
        }
    }

    private function selectorOfChild(): string|false
    {
        if ( $this->exists( '[data-component-island-template="listing-page/buy-box/App"]' ) ) {
            return '[data-component-island-template="listing-page/buy-box/App"] select';
        }

        return '#variations select';
    }

    private function checkIfHasRequiredTextarea(): bool
    {
        return $this->getAttr( '#variations #personalization textarea', 'aria-required' ) === 'true'
            || $this->getAttr( '[data-component-island-template="listing-page/buy-box/App"] #personalization textarea', 'aria-required' ) === 'true';
    }

    public function parseContent( Data $data, array $params = [] ): array
    {
        if ( !StringHelper::isNotEmpty( $data->getData() ) ) {
            $data = $this->getVendor()->getDownloader()->get( $params[ 'url' ] );
        }

        if ( !StringHelper::isNotEmpty( $data->getData() ) ) {
            return [];
        }

        return parent::parseContent( $data, $params );
    }

    public function beforeParse(): void
    {
        $this->parseJsonOfProduct();

        $this->parseAttributesAndShorts();

        $this->parseDescription();
    }

    public function afterParse( FeedItem $fi ): void
    {
        if ( $this->productIsNotValid() ) {
            $fi->setIsGroup( false );
            $fi->setCostToUs( 0 );
            $fi->setRAvail( 0 );
            $fi->setMpn( '' );
            $fi->setImages( [] );
        }
    }

    public function isGroup(): bool
    {
        if ( $this->checkIfHasRequiredTextarea() ) {
            return false;
        }

        $selector = $this->selectorOfChild();

        $count_of_selects = $this->filter( $selector )->count();

        return !( $count_of_selects === 0 || ( $count_of_selects === 1 && $this->getAttr( $selector, 'id' ) === self::QUANTITY_SELECT_ID ) );
    }

    public function getProduct(): string
    {
        return $this->product_info[ 'name' ] ?? '';
    }

    public function getBrand(): ?string
    {
        return $this->product_info[ 'brand' ] ?? null;
    }

    public function getCostToUs(): float
    {
        return StringHelper::getMoney( $this->getText( 'p.wt-text-title-03' ) );
    }

    public function getMpn(): string
    {
        return $this->getAttr( '[name="listing_inventory_id"]', 'value' );
    }

    public function getShortDescription(): array
    {
        return $this->product_info[ 'short_description' ] ?? [];
    }

    public function getDescription(): string
    {
        return $this->product_info[ 'description' ] ?? $this->getProduct();
    }

    public function getImages(): array
    {
        return $this->getAttrs( '.image-carousel-container img', 'data-src-zoom-image' );
    }

    public function getVideos(): array
    {
        return $this->product_info[ 'videos' ] ?? [];
    }

    public function getAttributes(): ?array
    {
        return !empty( $this->product_info[ 'attributes' ] ) ? $this->product_info[ 'attributes' ] : null;
    }

    public function getAvail(): ?int
    {
        return isset( $this->product_info[ 'offers' ][ 'availability' ] ) && $this->product_info[ 'offers' ][ 'availability' ] === 'https://schema.org/InStock' ? self::DEFAULT_AVAIL_NUMBER : 0;
    }

    public function getCategories(): array
    {
        return isset( $this->product_info[ 'category' ] ) ? array_slice( array_map( static fn( $category ) => mb_strtolower( trim( $category ) ), explode( '<', $this->product_info[ 'category' ] ) ), 0, 5 ) : [];
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

    public function getChildProducts( FeedItem $parent_fi ): array
    {
        $child = [];

        foreach ( $this->fetchChild() as $item ) {
            $fi = clone $parent_fi;

            $fi->setProduct( $item[ 'name' ] );
            $fi->setCostToUs( $item[ 'price' ] );
            $fi->setRAvail( $this->getAvail() );
            $fi->setImages( $this->getImages() );

            $fi->setMpn( $item[ 'mpn' ] );

            $child[] = $fi;
        }

        return $child;
    }
}
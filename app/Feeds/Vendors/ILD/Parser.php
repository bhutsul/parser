<?php

namespace App\Feeds\Vendors\ILD;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Parser\HtmlParser;
use App\Feeds\Utils\ParserCrawler;
use App\Helpers\FeedHelper;
use App\Helpers\StringHelper;

class Parser extends HtmlParser
{
    public const PRICE_IN_OPTION_REGEX = '/[\s]?[\+]?[\s]?\$(\d+[\,]?[\.]?\d*[\,]?[\.]?\d*?)/u';
    public const COMMON_DIMS_REGEX = '(\d+[\.]?\d*)[\',",″]?[\s]?[x,X][\s]?(\d+[\.]?\d*)[\',",″]?[\s]?[x,X]?[\s]?(:?\d+[\.]?\d*)?[\',",″]?';
    public const DIMENSIONS = [
        'common' => '/'. self::COMMON_DIMS_REGEX .'/i',
        'measures' => '/Measures[\s]?'. self::COMMON_DIMS_REGEX .'/i',
        'HLW' => '/(\d+[\.]?\d*)[\',",″]?[\s]?H[\s]?[x,X][\s]?(\d+[\.]?\d*)[\',",″]?[\s]?L[\s]?[x,X]?[\s]?(:?\d+[\.]?\d*)?[\',",″]?[\s]?[W]?/i',
        'LWH' => '/(\d+[\.]?\d*)[\',",″]?[\s]?L[\s]?[x,X][\s]?(\d+[\.]?\d*)[\',",″]?[\s]?W[\s]?[x,X]?[\s]?(:?\d+[\.]?\d*)?[\',",″]?[\s]?[H]?/i',
        'HW' => '/(\d+[\.]?\d*)[\',",″]?[\s]?H[\s]?[x,X,\;][\s]?(\d+[\.]?\d*)[\',",″]?[\s]?W/i',
        'WL' => '/(\d+[\.]?\d*)[\',",″]?[\s]?W[\s]?[x,X,\;][\s]?(\d+[\.]?\d*)[\',",″]?[\s]?L/i',
        'WT' => '/(\d+[\.]?\d*)[\',",″]?[\s]?W[\s]?[x,X,\;][\s]?(\d+[\.]?\d*)[\',",″]?[\s]?T/i',
        'WH' => '/(\d+[\.]?\d*)[\',",″]?[\s]?W[\s]?[x,X,\;][\s]?(\d+[\.]?\d*)[\',",″]?[\s]?H/i',
        'H' => '/(\d+[\.]?\d*)[\',",″]?[\s]?H/i',
        'W' => '/(\d+[\.]?\d*)[\',",″]?[\s]?W/i',
        'L' => '/(\d+[\.]?\d*)[\',",″]?[\s]?L/i',
        'cover' => '/Cover[:]?[\s]?'. self::COMMON_DIMS_REGEX .'/i',
        'sq' => '/(\d+[\.]?\d*)[\',",″]?[\s]?sq/i',
    ];

    private array $product_info;
    private bool $not_valid = false;

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

    private function childClone(
        FeedItem $parent_fi,
        array &$child,
        string $name,
        string $mpn,
        int|float $price,
        null|array $images
    ): void {
        $fi = clone $parent_fi;

        $fi->setProduct( $name );
        $fi->setCostToUs( $this->getCostToUs() + $price );
        $fi->setRAvail( $this->getAvail() );
        $fi->setImages( $images ?? $this->getImages() );

        $fi->setDimX( $this->getDimX() );
        $fi->setDimY( $this->getDimY() );
        $fi->setDimZ( $this->getDimZ() );

        $fi->setWeight( $this->getWeight() );

        $fi->setMpn( $mpn );

        $child[] = $fi;
    }

    private function getChildNameAndMpnAndPriceAndImagesInGroup( array $option_values, array $options ): array
    {
        $images = [];

        [ $name, $mpn, $price ] = $this->getDefaultNameMpnPrice();

        foreach ( $option_values as $option_value ) {
           $this->prepareNameAndMpnAndPrice( $options[ $option_value ], $name, $mpn, $price );

            if ( isset( $option[ 'images' ] ) ) {
                $images = array_merge( $options[ $option_value ][ 'images' ], $images );
            }
        }

        return [ $name, $mpn, $price, $images ? $this->formattedImages( $images ) : null ];
    }

    private function prepareNameAndMpnAndPrice(
        array $option,
        string &$name,
        string &$mpn,
        int|float|string &$price
    ): void {
        $option[ 'value' ] = preg_replace( self::PRICE_IN_OPTION_REGEX, '', $option[ 'value' ] );
        $option[ 'name' ] = preg_replace( [self::PRICE_IN_OPTION_REGEX, '/Select /'], '',  $option[ 'name' ] );

        $name .= $option[ 'name' ];
        $name .= ': ';
        $name .= trim( $option[ 'value' ], '. ' );
        $name .= '. ';

        $mpn .= '-';
        $mpn .= $option[ 'id' ];

        $price += $option[ 'price' ];
    }

    private function getDefaultNameMpnPrice(): array
    {
        return [ '', $this->getMpn(), 0 ];
    }

    private function pushToOptionsFromScriptData( array &$options, array &$option_groups, array $script_options ): void
    {
        foreach ( $script_options[ 'attributes' ] as $attribute ) {
            $option_values = [];

            foreach ( $attribute[ 'options' ] as $option ) {
                $options[ $option[ 'id' ] ] = [
                    'name' => $attribute[ 'label' ],
                    'value' => $option[ 'label' ],
                    'price' => 0,
                    'id' => $option[ 'id' ],
                ];

                if ( isset( $script_options[ 'index' ] ) ) {
                    foreach ( $script_options[ 'index' ] as $key => $options_index) {
                        if (
                            isset( $options_index[ $attribute[ 'id' ] ], $script_options[ 'images' ][ $key ] )
                            && $options_index[ $attribute[ 'id' ] ] === $option[ 'id' ]
                        ) {
                            $options[ $option[ 'id' ] ][ 'images' ] = $script_options[ 'images' ][ $key ];

                            break;
                        }
                    }
                }
                $option_values[] = $option[ 'id' ];
            }

            if ( $option_values ) {
                $option_groups[] = $option_values;
            }
        }
    }

    private function getOptionsAndGroups(): array
    {
        $options = [];
        $option_groups = [];

        if ( isset( $this->product_info[ 'super_options' ] ) ) {
           $this->pushToOptionsFromScriptData( $options, $option_groups, $this->product_info[ 'super_options' ] );
        }

        if ( isset( $this->product_info[ 'swatch' ] ) ) {
            $this->pushToOptionsFromScriptData( $options, $option_groups, $this->product_info[ 'swatch' ] );
        }

        $this->filter( '#product-options-wrapper select' )
            ->each( function ( ParserCrawler $select ) use ( &$options, &$option_groups ) {
                if ( false !== stripos( $select->attr( 'class' ), 'required' ) ) {
                    $option_values = [];

                    $select->filter( 'option' )
                        ->each( function ( ParserCrawler $option ) use ( &$options, &$option_values, $select ) {
                            if ( $option->attr( 'value' ) ) {
                                $options[ $option->attr( 'value' ) ] = [
                                    'name' => $this->getText( 'label[for="'. $select->attr( 'id' ) .'"]' ),
                                    'value' => $option->text(),
                                    'price' => $option->attr( 'price' ),
                                    'id' => $option->attr( 'value' ),
                                ];
                                $option_values[] = $option->attr( 'value' );
                            }
                        } );
                    if ( $option_values ) {
                        $option_groups[] = $option_values;
                    }
                }
            } );

        return [ $options, $option_groups ];
    }

    private function formattedImages( array $images ): array
    {
        return array_values(
            array_unique(
                array_filter(
                    array_map(static fn( $image ) => $image[ 'full' ], $images ),
                        static fn( $image ) => $image !== null && false === stripos( $image, 'placeholder' )
                )
            ),
        );
    }

    /**
     * @throws \JsonException
     */
    private function pushDataFromMagentoScripts(): void
    {
        preg_match_all('/<script type="text\/x-magento-init">\s*({.*?})\s*</s', $this->node->html(), $matches );

        if ( isset( $matches[1] ) ) {
            foreach ( $matches[1] as $script ) {
                $json = json_decode($script, true, 512, JSON_THROW_ON_ERROR);

                if ( isset( $json[ '[data-gallery-role=gallery-placeholder]' ][ 'mage/gallery/gallery' ] ) ) {
                    $this->product_info[ 'images' ] = $json[ '[data-gallery-role=gallery-placeholder]' ][ 'mage/gallery/gallery' ][ 'data' ];
                }
                else if ( isset( $json[ '[data-gallery-role=gallery-placeholder]' ][ 'Magento_ProductVideo/js/fotorama-add-video-events' ] ) ) {
                    $this->product_info[ 'videos' ] = $json[ '[data-gallery-role=gallery-placeholder]' ][ 'Magento_ProductVideo/js/fotorama-add-video-events' ][ 'videoData' ];
                }
                else if ( isset( $json[ '[data-role=swatch-options]' ][ 'Luxinten_Catalog/js/swatch-renderer-rewrite' ][ 'jsonConfig' ] ) ) {
                    $this->product_info[ 'swatch' ] = $json[ '[data-role=swatch-options]' ][ 'Luxinten_Catalog/js/swatch-renderer-rewrite' ][ 'jsonConfig' ];
                }
                else if ( isset( $json[ '#product_addtocart_form' ][ 'configurable' ][ 'spConfig' ][ 'attributes' ] ) ) {
                    $this->product_info[ 'super_options' ] = $json[ '#product_addtocart_form' ][ 'configurable' ][ 'spConfig' ];
                }
            }
        }
    }

    private function pushDataFromSpecsTable(): void
    {
        if ( $this->exists( '#product-attribute-specs-table tbody' ) ) {
            $this->filter( '#product-attribute-specs-table tbody tr' )
                ->each( function ( ParserCrawler $c ) {
                    $key   = $c->getText( 'th' );
                    $value = str_replace('”', "", $c->getText( 'td' ));
                    $separator = 'x';

                    if (
                        false !== strripos( $value, 'chain' )
                        || false !== strripos( $value, 'bow' )
                    ) {
                        $this->product_info[ 'attributes' ][ $key ] = $value;
                    }
                    else if ( false !== strripos( $key, 'brand' ) ) {
                        $this->product_info[ 'brand' ] = $value;
                    }
                    else if ( false !== strripos( $key, 'item weight' ) ) {
                        $this->product_info[ 'weight' ] = StringHelper::getFloat( $value );
                    }
                    else if (
                        preg_match( self::DIMENSIONS[ 'common' ], $value )
                        || preg_match( self::DIMENSIONS[ 'cover' ], $value )
                        || preg_match( self::DIMENSIONS[ 'measures' ], $value )
                    ) {
                        $this->product_info[ 'dims' ] = FeedHelper::getDimsInString( $value, $separator );
                    }
                    else if ( preg_match( self::DIMENSIONS[ 'HLW' ], $value )
                    ) {
                        $this->product_info[ 'dims' ] = FeedHelper::getDimsInString( $value, $separator, 2, 0, 1 );
                    }
                    else if ( preg_match( self::DIMENSIONS[ 'LWH' ], $value ) ) {
                        $this->product_info[ 'dims' ] = FeedHelper::getDimsInString( $value, $separator,1,2, 0);
                    }
                    else if ( preg_match( self::DIMENSIONS[ 'HW' ], $value ) ) {
                        if ( str_contains( $value, ';' ) ) {
                            $separator = ';';
                        }
                        $this->product_info[ 'dims' ] = FeedHelper::getDimsInString( $value, $separator, 1, 0);
                    }
                    else if ( preg_match( self::DIMENSIONS[ 'WL' ], $value ) ) {
                        if ( str_contains( $value, ';' ) ) {
                            $separator = ';';
                        }
                        $this->product_info[ 'dims' ] = FeedHelper::getDimsInString( $value, $separator, 0, 2,1);
                    }
                    else if (
                        preg_match( self::DIMENSIONS[ 'WT' ], $value )
                        || preg_match( self::DIMENSIONS[ 'WH' ], $value )
                    ) {
                        if ( str_contains( $value, ';' ) ) {
                            $separator = ';';
                        }
                        $this->product_info[ 'dims' ] = FeedHelper::getDimsInString( $value, $separator );
                    }
                    else if (
                        preg_match( self::DIMENSIONS[ 'H' ], $value, $h )
                        && isset( $h[ 1 ], $h[ 0 ] ) && strlen( $value ) === strlen( $h[ 0 ] )
                    ) {
                        $this->product_info[ 'dims' ][ 'y' ] = StringHelper::getFloat( $value );
                    }
                    else if (
                        preg_match( self::DIMENSIONS[ 'W' ], $value, $w )
                        && isset( $w[ 1 ], $w[ 0 ] ) && strlen( $value ) === strlen( $w[ 0 ] )
                    ) {
                        $this->product_info[ 'dims' ][ 'x' ] = StringHelper::getFloat( $value );
                    }
                    else if (
                        preg_match( self::DIMENSIONS[ 'L' ], $value, $l )
                        && isset( $l[ 1 ], $l[ 0 ] ) && strlen( $value ) === strlen( $l[ 0 ] )
                    ) {
                        $this->product_info[ 'dims' ][ 'z' ] = StringHelper::getFloat( $value );
                    }
                    else if ( preg_match( self::DIMENSIONS[ 'sq' ], $value, $sq ) ) {
                        $this->product_info[ 'dims' ][ 'x' ] = $sq[1];
                        $this->product_info[ 'dims' ][ 'y' ] = $sq[1];
                    }
                    else if ( false === strripos( $key, 'wholesale pricing' ) ) {
                        $this->product_info[ 'attributes' ][ $key ] = $value;
                    }
                });
        }
    }

    private function pushShortsAndAttributesAndDescription(): void
    {
        if ( !$this->exists( '.content .description') ) {
            return;
        }

        if ( $this->exists( '.content .description ul' ) ) {
            $data = FeedHelper::getShortsAndAttributesInList( $this->getHtml( '.content .description ul' ) );
        }

        $data = FeedHelper::getShortsAndAttributesInDescription( $this->getHtml( '.content .description' ), [], $data[ 'short_description' ] ?? [], $data[ 'attributes' ] ?? [] );

        $this->product_info[ 'attributes' ] = $data[ 'attributes' ];
        $this->product_info[ 'description' ] = preg_replace( [
            '/<ul\b[^>]*>(.*?)<\/ul>/is',
        ], '', $data[ 'description' ] );
        $this->product_info[ 'short_description' ] = $data[ 'short_description' ];
    }

    private function validateItem(): void
    {
        if ( $this->exists( '#product-options-wrapper input[type="text"]' ) ) {
            $this->filter( '#product-options-wrapper input[type="text"]' )
                ->each( function ( ParserCrawler $c ) {
                    if ( false !== stripos( $c->parents()->parents()->attr( 'class' ), 'required' ) ) {
                        $this->not_valid = true;
                    }
                });
        }
    }

    /**
     * @throws \JsonException
     */
    public function beforeParse(): void
    {
        $this->validateItem();

        if ( $this->not_valid ) {
            return;
        }

        $this->pushShortsAndAttributesAndDescription();

        $this->pushDataFromSpecsTable();

        $this->pushDataFromMagentoScripts();
    }

    public function isGroup(): bool
    {
        if ( $this->not_valid ) {
            return false;
        }

        return $this->exists( '#product-options-wrapper' );
    }

    public function getProduct(): string
    {
        return trim( $this->getText( 'span[itemprop="name"]' ) );
    }

    public function getMpn(): string
    {
        return $this->getText( 'div[itemprop="sku"]' );
    }

    public function getBrand(): ?string
    {
        return $this->product_info[ 'brand' ] ?? null;
    }

    public function getDescription(): string
    {
        return $this->product_info[ 'description' ] ?? '';
    }

    public function getShortDescription(): array
    {
        return $this->product_info[ 'short_description' ] ?? [];
    }

    public function getAttributes(): ?array
    {
        return $this->product_info[ 'attributes' ] ?? null;
    }

    public function getCategories(): array
    {
        return array_values( array_slice( $this->getContent( '.breadcrumbs a' ), 2, -1 ) );
    }

    public function getImages(): array
    {
        if ( $this->not_valid ) {
            return [];
        }

        if ( !isset( $this->product_info[ 'images' ] ) ) {
            return [];
        }

        return $this->formattedImages( $this->product_info[ 'images' ] );
    }

    public function getCostToUs(): float
    {
        if ( $this->not_valid ) {
            return 0;
        }

        return StringHelper::getMoney( $this->getAttr( 'meta[property="product:price:amount"]', 'content' )  );
    }

    public function getAvail(): ?int
    {
        return false !== stripos( $this->getText( '.stock' ), 'in stock' ) ? self::DEFAULT_AVAIL_NUMBER : 0;
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

    public function getVideos(): array
    {
        if ( !isset( $this->product_info[ 'videos' ] ) ) {
            return [];
        }

        return array_values(
            array_filter(
                array_map( fn( $video ) => [
                    'name' => $this->getProduct(),
                    'provider' => 'youtube',
                    'video' => $video[ 'videoUrl' ]
                ], $this->product_info[ 'videos' ] ),
                static fn( $video ) => $video[ 'video' ] !== null
            )
        );
    }

    public function getChildProducts( FeedItem $parent_fi ): array
    {
        $child = [];

        [ $options, $option_groups ] = $this->getOptionsAndGroups();

        if ( count( $option_groups ) === 1 ) {
            foreach ( $options as $option ) {
                [ $name, $mpn, $price ] = $this->getDefaultNameMpnPrice();

                if ( isset( $option[ 'images' ] ) ) {
                    $images = $this->formattedImages( $option[ 'images' ] );
                }

                $this->prepareNameAndMpnAndPrice( $option, $name, $mpn, $price );

                $this->childClone( $parent_fi, $child, $name, $mpn, $price, $images ?? null );
            }
        }
        else {
            $combination_of_groups = $this->combinations( $option_groups );

            foreach ( $combination_of_groups as $option_values ) {
                [ $name, $mpn, $price, $images ] = $this->getChildNameAndMpnAndPriceAndImagesInGroup( $option_values, $options );

                $this->childClone( $parent_fi, $child, $name, $mpn, $price, $images );
            }
        }

        return $child;
    }
}

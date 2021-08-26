<?php

namespace App\Feeds\Vendors\ILD;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Parser\HtmlParser;
use App\Feeds\Utils\ParserCrawler;
use App\Helpers\FeedHelper;
use App\Helpers\StringHelper;

class Parser extends HtmlParser
{
    private array $product_info;
    public const PRICE_IN_OPTION_REGEX = '/[\s]?[\+]?[\s]?\$(\d+[\,]?[\.]?\d*[\,]?[\.]?\d*?)/u';

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
        int|float $price
    ): void {
        $fi = clone $parent_fi;

        $fi->setProduct( $name );
        $fi->setCostToUs( $this->getCostToUs() + $price );
        $fi->setRAvail( $this->getAvail() );

        $fi->setDimX( $this->getDimX() );
        $fi->setDimY( $this->getDimY() );
        $fi->setDimZ( $this->getDimZ() );

        $fi->setWeight( $this->getWeight() );

        $fi->setMpn( $mpn );

        $child[] = $fi;
    }

    private function getChildNameAndMpnAndPriceInGroup( array $option_values, array $options ): array
    {
        [ $name, $mpn, $price ] = $this->getDefaultNameMpnPrice();

        foreach ( $option_values as $option_value ) {
           $this->prepareNameAndMpnAndPrice( $options[$option_value], $name, $mpn, $price );
        }

        return [ $name, $mpn, $price ];
    }

    private function prepareNameAndMpnAndPrice(
        array $option,
        string &$name,
        string &$mpn,
        int|float|string &$price
    ): void {
        $option[ 'value' ] = preg_replace( self::PRICE_IN_OPTION_REGEX, '', $option[ 'value' ] );
        $option[ 'name' ] = preg_replace( self::PRICE_IN_OPTION_REGEX, '',  $option[ 'name' ] );

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

    private function getOptionsAndGroups(): array
    {
        $options = [];
        $option_groups = [];

        if ( isset( $this->product_info[ 'swatch' ][ 'attributes' ] ) ) {
            foreach ( $this->product_info[ 'swatch' ][ 'attributes' ] as $attribute ) {
                $option_values = [];

                foreach ( $attribute[ 'options' ] as $option ) {
                    $options[ $option[ 'id' ] ] = [
                        'name' => $attribute[ 'label' ],
                        'value' => $option[ 'label' ],
                        'price' => 0,
                        'id' => $option[ 'id' ],
                    ];
                    $option_values[] = $option[ 'id' ];
                }

                if ( $option_values ) {
                    $option_groups[] = $option_values;
                }
            }
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
            }
        }
    }

    private function pushDataFromSpecsTable(): void
    {
        if ( $this->exists( '#product-attribute-specs-table tbody' ) ) {
            $this->filter( '#product-attribute-specs-table tbody tr' )
                ->each( function ( ParserCrawler $c ) {
                    $key   = $c->getText( 'th' );
                    $value = $c->getText( 'td' );

                    if ( false !== strripos( $key, 'brand' ) ) {
                        $this->product_info[ 'brand' ] = $value;
                    }
                    else if ( false !== strripos( $key, 'item weight' ) ) {
                        $this->product_info[ 'weight' ] = StringHelper::getFloat( $value );
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

    /**
     * @throws \JsonException
     */
    public function beforeParse(): void
    {
        $this->pushShortsAndAttributesAndDescription();

        $this->pushDataFromSpecsTable();

        $this->pushDataFromMagentoScripts();
    }

    public function isGroup(): bool
    {
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
        if ( !isset( $this->product_info[ 'images' ] ) ) {
            return [];
        }

        return array_values(
            array_unique(
                array_filter(
                    array_map(
                        static fn( $image ) => $image[ 'full' ],
                            $this->product_info[ 'images' ]
                    ),
                    static fn( $image ) => $image !== null && false === stripos( $image, 'placeholder' )
                )
            ),
        );
    }

    public function getCostToUs(): float
    {
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

                $this->prepareNameAndMpnAndPrice( $option, $name, $mpn, $price );

                $this->childClone( $parent_fi, $child, $name, $mpn, $price );
            }
        }
        else {
            $combination_of_groups = $this->combinations( $option_groups );

            foreach ( $combination_of_groups as $option_values ) {
                [ $name, $mpn, $price ] = $this->getChildNameAndMpnAndPriceInGroup( $option_values, $options );

                $this->childClone( $parent_fi, $child, $name, $mpn, $price );
            }
        }

        return $child;
    }
}

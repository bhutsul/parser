<?php

namespace App\Feeds\Vendors\JMF;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Parser\HtmlParser;
use App\Feeds\Utils\ParserCrawler;
use App\Helpers\FeedHelper;
use App\Helpers\StringHelper;

class Parser extends HtmlParser
{
    public const NOT_VALID_PARTS_OF_DESC_REGEXES = [
        '/<div\b[^>]+\bclass=[\'\"]prod-faq[\'\"][^>]*>(.*?)<\/div>/s',
        '/<p>• UN\/DOT.*?compatibility<\/p>/si',
    ];

    public const NOT_VALID_ATTRIBUTES = [
        'Model No',
        'Net weight',
        'Net Dimensions',
    ];

    private array $product_info;

    private function formattedImages( array $images ): array
    {
        return array_values(
            array_unique(
                array_filter(
                    array_map( static fn( $image ) => $image[ 'full' ], $images ),
                    static fn( $image ) => $image !== null && false === stripos( $image, 'placeholder' )
                )
            ),
        );
    }

    private function formattedVideos( array $videos ): array
    {
        return array_values(
            array_filter(
                array_map( fn( $video ) => [
                    'name' => $this->getProduct(),
                    'provider' => 'youtube',
                    'video' => $video[ 'videoUrl' ],
                ], $videos ),
                static fn( $video ) => $video[ 'video' ] !== null
            )
        );
    }

    private function pushDataFromMagentoScripts(): void
    {
        preg_match_all( '/<script type="text\/x-magento-init">\s*({.*?})\s*</s', $this->node->html(), $matches );

        if ( isset( $matches[ 1 ] ) ) {
            foreach ( $matches[ 1 ] as $script ) {
                $json = json_decode( $script, true, 512, JSON_THROW_ON_ERROR );

                if ( isset( $json[ '[data-gallery-role=gallery-placeholder]' ][ 'mage/gallery/gallery' ] ) ) {
                    $this->product_info[ 'images' ] = $this->formattedImages( $json[ '[data-gallery-role=gallery-placeholder]' ][ 'mage/gallery/gallery' ][ 'data' ] );
                }
                else if ( isset( $json[ '[data-gallery-role=gallery-placeholder]' ][ 'Magento_ProductVideo/js/fotorama-add-video-events' ] ) ) {
                    $this->product_info[ 'videos' ] = $this->formattedVideos( $json[ '[data-gallery-role=gallery-placeholder]' ][ 'Magento_ProductVideo/js/fotorama-add-video-events' ][ 'videoData' ] );
                }
                else if ( isset( $json[ '[data-role=swatch-options]' ][ 'Magento_Swatches/js/swatch-renderer' ][ 'jsonConfig' ] ) ) {
                    $this->product_info[ 'swatch' ] = $json[ '[data-role=swatch-options]' ][ 'Magento_Swatches/js/swatch-renderer' ][ 'jsonConfig' ];
                }
            }
        }
    }

    private function pushFiles(): void
    {
        $this->filter( '.mp-attachment-tab a' )->each( function ( ParserCrawler $a ) {
            $this->product_info[ 'files' ][] = [
                'name' => preg_replace( '/[\s+]?\(\d+[.]?\d*.*?\)/u', '', $a->text() ),
                'link' => $a->attr( 'href' ),
            ];
        } );
    }

    private function isAttributeValid( string $key ): bool
    {
        if ( empty( $key ) ) {
            return false;
        }

        foreach ( self::NOT_VALID_ATTRIBUTES as $str ) {
            if ( false !== stripos( $key, $str ) ) {
                return false;
            }
        }

        return true;
    }

    private function pushWeight( string $key, string $value ): void
    {
        if ( false !== stripos( $key, 'weight' ) && false !== stripos( $key, 'lbs' ) ) {
            $this->product_info[ 'weight' ] = StringHelper::getFloat( $value );
        }
    }

    private function pushDimsAndAttributes(): void
    {
        if ( $this->exists( '.attributes-wrapper table' ) ) {
            $this->filter( '.attributes-wrapper table tr' )->each( function ( ParserCrawler $tr ) {
                $key = $tr->getText( 'th' );
                $value = $tr->getText( 'td' );

                $this->pushWeight( $key, $value );

                if ( false !== stripos( $key, 'Net Dimensions (W x D x H)' ) ) {
                    $this->product_info[ 'dims' ] = FeedHelper::getDimsInString( $value, 'x', 0, 2, 1 );
                }

                if ( $this->isAttributeValid( $key ) ) {
                    $this->product_info[ 'attributes' ][ $key ] = $value;
                }
            } );
        }
    }

    private function pushShorts(): void
    {
        $this->filter( '.product-attr ul.allcaps li' )->each( function ( ParserCrawler $li ) {
            $this->product_info[ 'short_description' ][] = $li->text();
        } );
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

    private function childClone(
        FeedItem $parent_fi,
        array &$child,
        string $name,
        string $mpn,
        int|float $price,
        null|array $images
    ): void
    {
        $fi = clone $parent_fi;

        $fi->setProduct( $name );
        $fi->setCostToUs( $price );
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
    ): void
    {
        $name .= $option[ 'name' ];
        $name .= ': ';
        $name .= $option[ 'value' ];

        $mpn .= $option[ 'sku' ];

        $price += $option[ 'price' ];
    }

    private function getDefaultNameMpnPrice(): array
    {
        return [ '', '', 0 ];
    }

    private function getOptionsAndGroups(): array
    {
        $options = [];
        $option_groups = [];

        if ( isset( $this->product_info[ 'swatch' ] ) ) {
            foreach ( $this->product_info[ 'swatch' ][ 'attributes' ] as $attribute ) {
                $option_values = [];

                foreach ( $attribute[ 'options' ] as $option ) {
                    if ( $option[ 'products' ] ) {
                        $options[ $option[ 'id' ] ] = [
                            'name' => $attribute[ 'label' ],
                            'value' => $option[ 'label' ],
                            'id' => $option[ 'id' ],
                        ];

                        if ( isset( $this->product_info[ 'swatch' ][ 'index' ] ) ) {
                            foreach ( $this->product_info[ 'swatch' ][ 'index' ] as $key => $options_index ) {
                                if (
                                    isset( $options_index[ $attribute[ 'id' ] ], $this->product_info[ 'swatch' ][ 'images' ][ $key ] )
                                    && $options_index[ $attribute[ 'id' ] ] === $option[ 'id' ]
                                ) {
                                    $options[ $option[ 'id' ] ][ 'sku' ] = $options_index[ 'sku' ];
                                    $options[ $option[ 'id' ] ][ 'images' ] = $this->product_info[ 'swatch' ][ 'images' ][ $key ];
                                    $options[ $option[ 'id' ] ][ 'price' ] = $this->product_info[ 'swatch' ][ 'optionPrices' ][ $key ][ 'finalPrice' ][ 'amount' ];

                                    break;
                                }
                            }
                        }
                        $option_values[] = $option[ 'id' ];
                    }
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
                                    'name' => $this->getText( 'label[for="' . $select->attr( 'id' ) . '"]' ),
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

    public function beforeParse(): void
    {
        $sku = $this->getAttr( '[data-bv-show="rating_summary"]', 'data-bv-product-id' );
        $this->product_info = [
            'name' => preg_replace( '/[\s+]?[-]?[\s+]?[#]?[\s+]?' . $sku . '/ui', '', $this->getText( '[itemprop="name"]' ) ),
            'price' => StringHelper::getFloat( $this->getAttr( '[itemprop="price"]', 'content' ) ),
            'sku' => $sku,
            'categories' => $this->getContent( '.items .category a' ),
            'description' => preg_replace( self::NOT_VALID_PARTS_OF_DESC_REGEXES, '', $this->getHtml( '#description .description' ) ),
        ];

        $this->pushDataFromMagentoScripts();

        $this->pushFiles();

        $this->pushDimsAndAttributes();

        $this->pushShorts();
    }

    public function isGroup(): bool
    {
        return $this->exists( '#product-options-wrapper select' ) || !empty( $this->product_info[ 'swatch' ][ 'attributes' ] );
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

    public function getImages(): array
    {
        return $this->product_info[ 'images' ] ?? [];
    }

    public function getVideos(): array
    {
        return $this->product_info[ 'videos' ] ?? [];
    }

    public function getProductFiles(): array
    {
        return $this->product_info[ 'files' ] ?? [];
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

    public function getAvail(): ?int
    {
        return self::DEFAULT_AVAIL_NUMBER;
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

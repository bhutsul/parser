<?php

namespace App\Feeds\Vendors\SWC;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Parser\HtmlParser;
use App\Feeds\Utils\ParserCrawler;
use App\Helpers\FeedHelper;
use App\Helpers\StringHelper;

class Parser extends HtmlParser
{
    public const PRICE_IN_OPTION_REGEX = '/\[[A-Z,a-z]{3,3}\s[A-Z,a-z]{2,2}\$(\d+[\,]?[\.]?\d*[\,]?[\.]?\d*?)\]/u';
    public const WEIGHT_FROM_SHIPPING_DIMS = '/(\d+[.]?\d*)[\s]?(lbs|lb)[\.]?/u';
    public const NOT_VALID_DESC = [
        'When you\'re ready to upload your artwork, click on Artwork Upload on the top menu.',
        "you upload your artwork",
        'Uploading Artwork',
        'Regarding Colour Reproduction and Print Quality:',
        'service@canadiandisplay.ca',
        '1-888-748-8788',
        'Please contact us',
        'Click the button below to get the artwork',
        'click on',
        'call if you have any questions.',
        'click the',
        'available here',
        'upload',
        'contact us'
    ];
    public const DIMS_REGEXES = [
        'shipping' => '/Shipping Dimensions[:]?[-]?[\s]?(:?\d+[\.]?\d*[\s]?[a-z,A-Z]{2,3}[.]?)?[\s]?(\d+[\.]?\d*)(?:[\',",″]|[a-z]{1,2})?[\s]?[x,X][\s]?(\d+[\.]?\d*)(?:[\',",″]|[a-z]{1,2})?[\s]?[x,X]?[\s]?(:?\d+[\.]?\d*)?(?:[\',",″]|[a-z]{1,2})?[\s]?(:?\d+[\.]?\d*[\s]?[a-z,A-Z]{2,3}[.]?)?/i',
        'shipping_weight' => '/Shipping weight:[\s]?(\d+[\.]?\d*)/u',
        'weight' => '/Weight[:]?[\s]?(\d+[\.]?\d*)/',
        'depth' => '/Depth[:]?[\s]?(\d+[\.]?\d*)/',
        'height' => '/Height[:]?[\s]?(\d+[\.]?\d*)/',
        'width' => '/Width[:]?[\s]?(\d+[\.]?\d*)/',
        'dims' => '/(\d+[\.]?\d*)(?:[\',",″]|[a-z]{2,2})?[\s]?[x,X][\s]?(\d+[\.]?\d*)(?:[\',",″]|[a-z]{2,2})?[\s]?[x,X]?[\s]?(:?\d+[\.]?\d*)?(?:[\',",″]|[a-z]{2,2})/i',
        'HDW' => '/(\d+[\.]?\d*)[\',",″]?[\s]?\(H\)[\s]?[\s]?[x,X][\s]?(\d+[\.]?\d*)[\',",″]?[\s]?\(D\)[\s]?[\s]?[x,X]?[\s]?(\d+[\.]?\d*)[\',",″]?[\s]?\(W\)/i',
        'LWH' => '/(\d+[\.]?\d*)[\',",″]?[\s]?\(L\)[\s]?[\s]?[x,X][\s]?(\d+[\.]?\d*)[\',",″]?[\s]?\(W\)[\s]?[\s]?[x,X]?[\s]?(\d+[\.]?\d*)[\',",″]?[\s]?\(H\)/i',
    ];

    private array $product_info;
    private bool $not_group;

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

    public function getShortsAndAttributesInList( string $list, array $short_description = [], array $attributes = [] ): array
    {
        $crawler = new ParserCrawler( $list );
        $crawler->filter( 'li' )->each( function ( ParserCrawler $c ) use ( &$short_description, &$attributes ) {
            $text = $c->text();
            if ( str_contains( $text, ':' ) && substr_count( $text, ':' ) === 1 ) {
                [ $key, $value ] = explode( ':', $text, 2 );
                $has_range = str_contains( $value, '~' ) || false !== stripos( $key, 'range' );

                if ( !$value && false === stripos( $text, 'Display dimensions' ) ) {
                    $short_description[] = $text;
                }
                else if (
                    false === stripos( $key, 'Thick Lightweight' )
                    && (
                        str_starts_with( $key, 'Shipping dimensions' )
                        || str_starts_with( $key, 'Shipping Dimensions' )
                        || str_ends_with( $key, 'Shipping Dimensions' )
                        || str_ends_with( $key, 'Shipping dimensions' )
                    )
                ) {
                    if ( preg_match( self::WEIGHT_FROM_SHIPPING_DIMS, $value, $matches ) && isset( $matches[ 1 ] ) ) {
                        $this->product_info[ 'shipping_weight' ] = StringHelper::getFloat( $matches[ 1 ] );
                        $value = preg_replace( self::WEIGHT_FROM_SHIPPING_DIMS, '', $value );
                    }
                    $this->product_info[ 'shipping_dims' ] = FeedHelper::getDimsInString( $value, 'x' );

                    if (
                        preg_match( self::DIMS_REGEXES[ 'HDW' ], $key, $hdw )
                        && isset( $hdw[ 1 ], $hdw[ 0 ] )
                    ) {
                        $this->product_info[ 'dims' ] = FeedHelper::getDimsInString( $hdw[ 0 ], 'x', 2, 0, 1 );
                    }

                }
                else if ( str_starts_with( $key, 'Shipping weight' ) || str_starts_with( $key, 'Shipping Weight' ) ) {
                    $this->product_info[ 'shipping_weight' ] = StringHelper::getFloat( $value );
                }
                else if ( !$has_range && str_starts_with( $key, 'Depth' ) ) {
                    $this->product_info[ 'dims' ][ 'z' ] = StringHelper::getFloat( $value );
                }
                else if ( !$has_range && str_starts_with( $key, 'Height' ) ) {
                    $this->product_info[ 'dims' ][ 'y' ] = StringHelper::getFloat( $value );
                }
                else if (
                    !$has_range && (
                        false === stripos( $value, 'per square yard' )
                        && false === stripos( $value, '(1set)' )
                        && str_starts_with( $key, 'Weigh' )
                    )
                ) {
                    $this->product_info[ 'weight' ] = false !== stripos( $value, "oz" )
                        ? FeedHelper::convertLbsFromOz( StringHelper::getFloat( $value ) )
                        : StringHelper::getFloat( $value );
                }
                else if ( !$has_range && str_starts_with( $key, 'Width' ) ) {
                    $this->product_info[ 'dims' ][ 'x' ] = StringHelper::getFloat( $value );
                }
                else if (
                    false === stripos( $key, 'when pressed down on flat surface' )
                    &&
                    (
                        str_starts_with( $key, 'Overall Dimensions' )
                        || str_starts_with( $key, 'Dimensions' )
                        || str_starts_with( $key, 'Overall size' )
                        || str_starts_with( $key, 'Overall Size' )
                        || str_starts_with( $key, 'Size' )
                    )
                ) {
                    if ( false !== str_contains( $value, '-' ) ) {
                        $value = str_replace( "-", ' ', $value );
                    }
                    if ( false !== stripos( $key, '(lxwxh)' ) || preg_match( self::DIMS_REGEXES[ 'LWH' ], $value ) ) {
                        $this->product_info[ 'dims' ] = FeedHelper::getDimsInString( $value, 'x', 1, 2, 0 );
                    }
                    else {
                        $this->product_info[ 'dims' ] = FeedHelper::getDimsInString( $value, 'x' );
                    }
                }
                else if (
                    false !== stripos( $key, 'Thick Lightweight' )
                ) {
                    $short_description[] = $key . ': ' . $value;
                }
                else {
                    $attributes[ trim( $key ) ] = trim( StringHelper::normalizeSpaceInString( $value ) );
                }
            }
            else if ( preg_match( self::DIMS_REGEXES[ 'shipping' ], $text, $shipping ) ) {
                $this->pushShipping( $text, $shipping );
            }
            else if ( preg_match( self::DIMS_REGEXES[ 'shipping_weight' ], $text, $shipping_weight ) ) {
                $this->product_info[ 'shipping_weight' ] = StringHelper::getFloat( $shipping_weight[ 1 ] );
            }
            else if (
                preg_match( self::DIMS_REGEXES[ 'dims' ], $text, $dims )
                && isset( $dims[ 1 ], $dims[ 0 ] ) && strlen( $c->text() ) === strlen( $dims[ 0 ] )
            ) {
                $this->product_info[ 'dims' ] = FeedHelper::getDimsInString( $dims[ 0 ], 'x' );
            }
            else if (
                preg_match( self::DIMS_REGEXES[ 'HDW' ], $text, $hdw )
                && isset( $hdw[ 1 ], $hdw[ 0 ] ) && strlen( $c->text() ) === strlen( $hdw[ 0 ] )
            ) {
                $this->product_info[ 'dims' ] = FeedHelper::getDimsInString( $hdw[ 0 ], 'x', 2, 0, 1 );
            }
            else if ( str_starts_with( $text, 'Measures' ) ) {
                $this->product_info[ 'dims' ] = FeedHelper::getDimsInString( $text, 'x' );
            }
            else if ( preg_match( self::DIMS_REGEXES[ 'depth' ], $text, $depth ) ) {
                $this->product_info[ 'dims' ][ 'z' ] = StringHelper::getFloat( $depth[ 1 ] );
            }
            else if ( preg_match( self::DIMS_REGEXES[ 'height' ], $text, $height ) ) {
                $this->product_info[ 'dims' ][ 'y' ] = StringHelper::getFloat( $height[ 1 ] );
            }
            else if ( preg_match( self::DIMS_REGEXES[ 'width' ], $text, $width ) ) {
                $this->product_info[ 'dims' ][ 'x' ] = StringHelper::getFloat( $width[ 1 ] );
            }
            else if ( str_ends_with( $text, '" width' ) ) {
                $this->product_info[ 'dims' ][ 'x' ] = StringHelper::getFloat( $text );
            }
            else if ( preg_match( self::DIMS_REGEXES[ 'weight' ], $text, $weight ) ) {
                $this->product_info[ 'weight' ] = false !== stripos( $weight[ 1 ], "oz" )
                    ? FeedHelper::convertLbsFromOz( StringHelper::getFloat( $weight[ 1 ] ) )
                    : StringHelper::getFloat( $weight[ 1 ] );
            }
            else if ( false !== stripos( $text, 'Click the button' ) ) {
                $li = explode( '<br>', $c->html() );

                if ( isset( $li[ 0 ] ) ) {
                    $short_description[] = $li[ 0 ];
                }
            }
            else {
                $short_description[] = $text;
            }
        } );

        return [
            'short_description' => $short_description,
            'attributes' => FeedHelper::cleanAttributes( $attributes )
        ];
    }

    private function pushDescription(): void
    {
        if ( $this->exists( '#ProductDetail_ProductDetails_div' ) ) {
            $description = preg_replace( [
                '/<br>Click the button.*?<br>/is',
                '/<div\b[^>]+\bclass=[\'\"]video_description[\'\"][^>]*>(.*?)<\/div>/s',
                '/<b\b[^>]*>(.*?)<\/b>/i',
            ], '', $this->getHtml( '#ProductDetail_ProductDetails_div' ) );
            $part_of_descriptions = explode( '<br>', $description );
            $this->product_info[ 'description' ] = '';

            foreach ( $part_of_descriptions as $part_of_description ) {
                if ( !$part_of_description ) {
                    continue;
                }
                $text = trim( strip_tags( $part_of_description ) );

                $not_valid = false;
                foreach ( self::NOT_VALID_DESC as $value ) {
                    if ( false !== stripos( $text, $value ) ) {
                        $not_valid = true;
                    }
                }

                if ( $not_valid ) {
                    continue;
                }

                $this->product_info[ 'description' ] .= '<p>' . $text . '</p>';
            }
        }
    }

    private function pushVideos(): void
    {
        if ( $this->exists( '#ProductDetail_ProductDetails_div .video_container' ) ) {
            $this->filter( '#ProductDetail_ProductDetails_div .video_container' )
                ->each( function ( ParserCrawler $c ) {
                    $this->product_info[ 'videos' ][] = [
                        'name' => $c->getText( '.video_description' ) ?: $this->getProduct(),
                        'provider' => 'youtube',
                        'video' => 'https://' . ltrim( $c->getAttr( 'iframe', 'src' ), '//' )
                    ];
                } );
        }
    }

    private function pushFiles(): void
    {
        if ( $this->exists( '#ProductDetail_ProductDetails_div a' ) ) {
            $this->filter( '#ProductDetail_ProductDetails_div a' )
                ->each( function ( ParserCrawler $c ) {
                    if ( false !== stripos( $c->attr( 'href' ), 'pdf' ) ) {
                        if ( substr_count( $c->attr( 'href' ), 'https' ) === 2 ) {
                            $href = explode( 'https', $c->attr( 'href' ) );
                            $href = 'https' . $href[ 2 ];
                        }
                        $this->product_info[ 'files' ][ $c->attr( 'href' ) ] = [
                            'name' => $c->text() ?: $this->getProduct(),
                            'link' => $href ?? $c->attr( 'href' )
                        ];
                    }
                } );
        }
    }

    private function pushShortsAndAttributesAndDims(): void
    {
        if ( $this->exists( '#ProductDetail_TechSpecs_div' ) ) {
            if ( $this->filter( '#ProductDetail_TechSpecs_div ul' )->count() > 1 ) {
                $description = $this->getHtml( '#ProductDetail_TechSpecs_div' );
                if ( isset( $this->product_info[ 'description' ] ) ) {
                    $this->product_info[ 'description' ] .= $description;
                }
                else {
                    $this->product_info[ 'description' ] = $description;
                }
            }
            else {
                $shorts_and_attributes = $this->getShortsAndAttributesInList(
                    $this->getHtml( '#ProductDetail_TechSpecs_div' )
                );
            }
        }

        if ( $this->exists( '#ProductDetail_ProductDetails_div2' ) ) {
            $shorts_and_attributes = $this->getShortsAndAttributesInList(
                $this->getHtml( '#ProductDetail_ProductDetails_div2 ul' ),
                $shorts_and_attributes[ 'short_description' ] ?? [],
                $shorts_and_attributes[ 'attributes' ] ?? [],
            );
        }

        if ( $this->exists( '#ProductDetail_ExtInfo_div' ) ) {
            $ext_info = $this->getText( '#ProductDetail_ExtInfo_div' );

            if ( preg_match( self::DIMS_REGEXES[ 'shipping' ], $ext_info, $shipping ) ) {
                $this->pushShipping( $ext_info, $shipping );
            }
        }

        if ( !isset( $shorts_and_attributes ) ) {
            return;
        }

        $this->product_info[ 'shorts' ] = $shorts_and_attributes[ 'short_description' ];
        $this->product_info[ 'attributes' ] = $shorts_and_attributes[ 'attributes' ] ?: null;
    }

    private function pushShipping( string $text, array $shipping ):void
    {
        if ( preg_match( self::WEIGHT_FROM_SHIPPING_DIMS, $text, $shipping_weight ) && isset( $shipping_weight[ 1 ] ) ) {
            $this->product_info[ 'shipping_weight' ] = StringHelper::getFloat( $shipping_weight[ 1 ] );
            $shipping[ 0 ] = preg_replace( self::WEIGHT_FROM_SHIPPING_DIMS, '', $shipping[ 0 ] );
        }
        $this->product_info[ 'shipping_dims' ] = FeedHelper::getDimsInString( $shipping[ 0 ], 'x' );
    }

    private function formatValueAndPrice( string &$value, int|float &$price ): void
    {
        if (
            preg_match( self::PRICE_IN_OPTION_REGEX, $value, $matches )
            && isset( $matches[ 1 ] )
        ) {
            $price += StringHelper::getFloat( $matches[ 1 ] );

            $value = preg_replace(
                self::PRICE_IN_OPTION_REGEX, '', $value
            );
        }
    }

    private function buildChildNameMpnPrice( string $mpn, array $option_values, array $options ): array
    {
        $name = '';
        $price = 0;

        foreach ( $option_values as $option_value ) {
            $this->formatValueAndPrice( $options[ $option_value ][ 'value' ], $price );

            $this->buildChildName( $name, $options[ $option_value ] );

            $mpn .= '-';
            $mpn .= $option_value;

        }

        return [ $name, $mpn, $price ];
    }

    private function buildChildName( &$name, $option ): void
    {
        $name .= $option[ 'name' ];
        $name .= ': ';
        $name .= trim( $option[ 'value' ], '. ' );
        $name .= '. ';
    }

    private function childClone( FeedItem $parent_fi, array &$child, string $name, string $mpn, int|float $price ): void
    {
        $fi = clone $parent_fi;

        $fi->setProduct( $name );
        $fi->setCostToUs( $this->getCostToUs() + $price );
        $fi->setListPrice( null );
        $fi->setRAvail( $this->getAvail() );

        $fi->setDimX( $this->getDimX() );
        $fi->setDimY( $this->getDimY() );
        $fi->setDimZ( $this->getDimZ() );

        $fi->setShippingDimX( $this->getShippingDimX() );
        $fi->setShippingDimY( $this->getShippingDimY() );
        $fi->setShippingDimZ( $this->getShippingDimZ() );

        $fi->setWeight( $this->getWeight() );

        $fi->setShippingWeight( $this->getShippingWeight() );

        $fi->setMpn( $mpn );

        $child[] = $fi;
    }

    public function beforeParse(): void
    {
        $this->pushDescription();

        $this->pushFiles();

        $this->pushVideos();

        $this->pushShortsAndAttributesAndDims();
    }

    public function afterParse( FeedItem $fi ): void
    {
        if ( isset( $this->not_group ) && $this->not_group === true ) {
            $fi->setIsGroup( false );
            $fi->setCostToUs( $this->getCostToUs() );
            $fi->setRAvail( $this->getAvail() );
            $fi->setMpn( $this->getMpn() );
            $fi->setImages( $this->getImages() );
        }
    }

    public function isGroup(): bool
    {
        return $this->exists( '#options_table' );
    }

    public function getProduct(): string
    {
        return trim( $this->getText( 'span[itemprop="name"]' ) );
    }

    public function getMpn(): string
    {
        return $this->getText( 'span.product_code' );
    }

    public function getDescription(): string
    {
        if ( !isset( $this->product_info[ 'description' ] ) ) {
            return '';
        }

        return FeedHelper::cleanProductDescription( $this->product_info[ 'description' ] );
    }

    public function getShortDescription(): array
    {
        if ( !isset( $this->product_info[ 'shorts' ] ) ) {
            return [];
        }

        return FeedHelper::cleanShortDescription( array_filter( $this->product_info[ 'shorts' ], static function ( $el ) {
            return !str_ends_with( $el, ':' ) && stripos( $el, 'upload' ) === false;
        } ) );
    }

    public function getCategories(): array
    {
        return array_values( array_slice( $this->getContent( '.vCSS_breadcrumb_td a' ), 1 ) );
    }

    public function getImages(): array
    {
        $images = array_map( static fn( $image ) => 'https:' . $image, $this->getAttrs( '#altviews a', 'href' ) );

        if ( !$images ) {
            if ( !$this->exists( '#product_photo_zoom_url' ) ) {
                return [];
            }

            $image = 'https:' . $this->getAttr( '#product_photo_zoom_url', 'href' );
            $images = [ $image ];
        }

        return $images;
    }

    public function getAttributes(): ?array
    {
        return $this->product_info[ 'attributes' ] ?? null;
    }

    public function getCostToUs(): float
    {
        return StringHelper::getMoney( $this->getAttr( 'span[itemprop="price"]', 'content' ) );
    }

    public function getListPrice(): ?float
    {
        if ( !$this->exists( '[itemprop="offers"] .product_listprice' ) ) {
            return null;
        }

        return StringHelper::getMoney( $this->getText( '[itemprop="offers"] .product_listprice b' ) );
    }

    public function getAvail(): ?int
    {
        return $this->getAttr( 'meta[itemprop="availability"]', 'content' ) === 'InStock' ? self::DEFAULT_AVAIL_NUMBER : 0;
    }

    public function getBrand(): ?string
    {
        if ( !$this->exists( 'meta[itemprop="manufacturer"]' ) ) {
            return null;
        }

        return $this->getAttr( 'meta[itemprop="manufacturer"]', 'content' );
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

    public function getShippingDimX(): ?float
    {
        return $this->product_info[ 'shipping_dims' ][ 'x' ] ?? null;
    }

    public function getShippingDimY(): ?float
    {
        return $this->product_info[ 'shipping_dims' ][ 'y' ] ?? null;
    }

    public function getShippingDimZ(): ?float
    {
        return $this->product_info[ 'shipping_dims' ][ 'z' ] ?? null;
    }

    public function getShippingWeight(): ?float
    {
        return $this->product_info[ 'shipping_weight' ] ?? null;
    }

    public function getVideos(): array
    {
        return $this->product_info[ 'videos' ] ?? [];
    }

    public function getProductFiles(): array
    {
        if ( !isset( $this->product_info[ 'files' ] ) ) {
            return [];
        }
        return array_values( $this->product_info[ 'files' ] );
    }

    public function getChildProducts( FeedItem $parent_fi ): array
    {
        $child = [];
        $options = [];
        $option_groups = [];

        $this->filter( '#options_table select' )
            ->each( function ( ParserCrawler $select ) use ( &$options, &$option_groups ) {
                $option_values = [];

                $select->filter( 'option' )
                    ->each( function ( ParserCrawler $option ) use ( &$options, &$option_values, $select ) {
                        if (
                            false === stripos( $option->text(), 'contact us' )
                            && false === stripos( $option->text(), 'upload' )
                            && false === stripos( $option->text(), 'custom' )
                            && false === stripos( $select->attr( 'title' ), 'design service' )
                            && false === stripos( $select->attr( 'title' ), 'help' )
                        ) {
                            $options[ $option->attr( 'value' ) ] = [
                                'name' => $select->attr( 'title' ),
                                'value' => $option->text(),
                            ];
                            $option_values[] = $option->attr( 'value' );
                        }
                    } );
                if ( $option_values ) {
                    $option_groups[] = $option_values;
                }
            } );

        if ( count( $option_groups ) === 0 ) {
            $this->not_group = true;

            return $child;
        }

        if ( count( $option_groups ) === 1 ) {
            foreach ( $options as $key => $option ) {
                $price = 0;
                $this->formatValueAndPrice( $option[ 'value' ], $price );

                $name = '';

                $this->buildChildName( $name, $option );

                $mpn = $this->getMpn() . '-' . $key;

                $this->childClone( $parent_fi, $child, $name, $mpn, $price );
            }
        }
        else {
            $combination_of_groups = $this->combinations( $option_groups );

            foreach ( $combination_of_groups as $option_values ) {
                [ $name, $mpn, $price ] = $this->buildChildNameMpnPrice( $this->getMpn(), $option_values, $options );

                $this->childClone( $parent_fi, $child, $name, $mpn, $price );
            }
        }

        return $child;
    }
}
<?php

namespace App\Feeds\Vendors\SWC;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Parser\HtmlParser;
use App\Feeds\Utils\ParserCrawler;
use App\Helpers\FeedHelper;
use App\Helpers\StringHelper;


class Parser extends HtmlParser
{
    public const PRICE_IN_OPTION_REGEX = '/\[[A-Z,a-z]{3,3}\s[A-Z]{2,2}\$(\d+[\.]?\d*)\]/u';
    public const WEIGHT_FROM_SHIPPING_DIMS = '/(\d+[.]?\d*)[\s]?[lbs,lb]/u';
    public const DIMS_REGEXES = [
        'shipping' => '/Shipping Dimensions:[\s]?(:?\d+[\.]?\d*[\s]?[a-z,A-Z]{2,3}[.]?)?[\s]?(\d+[\.]?\d*)(?:[\',",″]|[a-z]{1,2})?[\s]?[x,X][\s]?(\d+[\.]?\d*)(?:[\',",″]|[a-z]{1,2})?[\s]?[x,X]?[\s]?(:?\d+[\.]?\d*)?(?:[\',",″]|[a-z]{1,2})?[\s]?(:?\d+[\.]?\d*[\s]?[a-z,A-Z]{2,3}[.]?)?/u',
        'shipping_weight' => '/Shipping weight:[\s]?(\d+[\.]?\d*)/u',
        'weight' => '/Weight:[\s]?(\d+[\.]?\d*)/u',
        'depth' => '/Depth:[\s]?(\d+[\.]?\d*)/u',
        'height' => '/Height:[\s]?(\d+[\.]?\d*)/u',
        'width' => '/Width:[\s]?(\d+[\.]?\d*)/u',
    ];

    private array $product_info;

    private function combinations( array $arrays, int $i = 0 )
    {
        if ( !isset( $arrays[$i] ) ) {
            return [];
        }

        if ( $i === count( $arrays ) - 1 ) {
            return $arrays[$i];
        }

        // get combinations from subsequent arrays
        $tmp = $this->combinations( $arrays, $i + 1 );

        $result = [];

        // concat each array from tmp with each element from $arrays[$i]
        foreach ( $arrays[$i] as $v ) {
            foreach ( $tmp as $t ) {
                $result[] = is_array( $t ) ? array_merge( [$v], $t ) : [$v, $t];
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
                $attributes[ trim( $key ) ] = trim( StringHelper::normalizeSpaceInString( $value ) );
            }
            else {
                if ( preg_match( self::DIMS_REGEXES['shipping'], $text, $shipping) ) {
                    if ( preg_match( self::WEIGHT_FROM_SHIPPING_DIMS, $shipping[0], $shipping_weight ) && isset( $shipping_weight[1] ) ) {
                        $this->product_info['shipping_weight'] = StringHelper::getFloat( $shipping_weight[1] );
                        $shipping[0] = preg_replace( self::WEIGHT_FROM_SHIPPING_DIMS, '', $shipping[0]);
                    }
                    $this->product_info['shipping_dims'] = FeedHelper::getDimsInString($shipping[0], 'x');
                }
                if ( preg_match( self::DIMS_REGEXES['shipping_weight'], $text, $shipping_weight) ) {
                    $this->product_info['shipping_weight'] = StringHelper::getFloat( $shipping_weight[1] );
                }
                if ( preg_match( self::DIMS_REGEXES['depth'], $text, $depth) ) {
                    $this->product_info['dims']['x'] = StringHelper::getFloat( $depth[1] );
                }
                if ( preg_match( self::DIMS_REGEXES['height'], $text, $height) ) {
                    $this->product_info['dims']['y'] = StringHelper::getFloat( $height[1] );
                }
                if ( preg_match( self::DIMS_REGEXES['width'], $text, $width) ) {
                    $this->product_info['dims']['z'] = StringHelper::getFloat( $width[1] );
                }
                if ( preg_match( self::DIMS_REGEXES['weight'], $text, $weight) ) {
                    $this->product_info['weight'] = StringHelper::getFloat( $weight[1] );
                }

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
            $this->product_info['description'] = $this->getHtml( '#ProductDetail_ProductDetails_div' );
        }
    }

    private function pushVideos(): void
    {
        if ( $this->exists( '#ProductDetail_ProductDetails_div .video_container iframe' ) ) {
            $this->product_info['videos'][] = [
                'name' => $this->getProduct(),
                'provider' => 'youtube',
                'video' => $this->getAttr( '#ProductDetail_ProductDetails_div .video_container iframe', 'src' )
            ];
        }
    }

    private function pushFiles(): void
    {
        if ( $this->exists( '#ProductDetail_ProductDetails_div a' ) ) {
            $this->filter( '#ProductDetail_ProductDetails_div a' )
                ->each( function ( ParserCrawler $c ) {
                    if ( false !== stripos( $c->attr( 'href' ), 'pdf' ) ) {
                        $this->product_info['files'][] = [
                            'name' => $c->text() ?: $this->getProduct(),
                            'link' => $c->attr( 'href' )
                        ];
                    }
                });
        }
    }

    private function pushShortsAndAttributesAndDims(): void
    {
        if ( $this->exists( '#ProductDetail_TechSpecs_div' ) ) {
            $shorts_and_attributes = $this->getShortsAndAttributesInList(
                $this->getHtml( '#ProductDetail_TechSpecs_div' )
            );
        }

        if ( $this->exists( '#ProductDetail_ProductDetails_div2' ) ) {
            $shorts_and_attributes = $this->getShortsAndAttributesInList(
                $this->getHtml( '#ProductDetail_ProductDetails_div2 ul' ),
                $shorts_and_attributes['short_description'] ?? [],
                $shorts_and_attributes['attributes'] ?? [],
            );
        }

        if ( !isset( $shorts_and_attributes ) ) {
            return;
        }

        $this->product_info['shorts'] = $shorts_and_attributes['short_description'];

        foreach ( $shorts_and_attributes['attributes'] as $key => $value ) {
            if ( str_starts_with( $key, 'Shipping dimensions' ) || str_starts_with( $key, 'Shipping Dimensions' ) ) {
                if ( preg_match( self::WEIGHT_FROM_SHIPPING_DIMS, $value, $matches ) && isset( $matches[1] ) ) {
                    $this->product_info['shipping_weight'] = StringHelper::getFloat( $matches[1] );
                    $value = preg_replace( self::WEIGHT_FROM_SHIPPING_DIMS, '', $value);
                }
                $this->product_info['shipping_dims'] = FeedHelper::getDimsInString($value, 'x');
            }
            else if ( str_starts_with( $key, 'Shipping weight' ) ) {
                $this->product_info['shipping_weight'] = StringHelper::getFloat( $value );
            }
            else if ( str_starts_with( $key, 'Depth' ) ) {
                $this->product_info['dims']['x'] = StringHelper::getFloat( $value );
            }
            else if ( str_starts_with( $key, 'Height' ) ) {
                $this->product_info['dims']['y'] = StringHelper::getFloat( $value );
            }
            else if ( str_starts_with( $key, 'Weight' ) ) {
                $this->product_info['weight'] = StringHelper::getFloat( $value );
            }
            else if ( str_starts_with( $key, 'Width' ) ) {
                $this->product_info['dims']['z'] = StringHelper::getFloat( $value );
            }
            else if (
                str_starts_with( $key, 'Dimensions' )
                && false === stripos( $key, 'when pressed down on flat surface' )
            ) {
                if ( false !== stripos( $key, '(lxwxh)' ) ) {
                    $this->product_info['dims'] = FeedHelper::getDimsInString($value, 'x', 0, 2, 1);
                }
                else {
                    $this->product_info['dims'] = FeedHelper::getDimsInString($value, 'x');
                }
            }
        }
        $this->product_info['attributes'] = $shorts_and_attributes['attributes'] ? : null;
    }

    private function buildChildNameMpnPrice( string $mpn, array $option_values, array $options ): array
    {
        $name = '';
        $price = 0;

        foreach ( $option_values as $option_value ) {
            $matches = [];

            if (
                preg_match( self::PRICE_IN_OPTION_REGEX, $options[$option_value]['value'], $matches )
                && isset( $matches[1])
            ) {
                $price += $matches[1];

                $options[$option_value]['value'] = preg_replace(
                    self::PRICE_IN_OPTION_REGEX, '', $options[$option_value]['value']
                );
            }

            $name .= $options[$option_value]['name'];
            $name .= ': ';
            $name .= trim( $options[$option_value]['value'], '.' );
            $name .= '. ';

            $mpn .= '-';
            $mpn .= $option_value;

        }

        return [$name, $mpn, $price];
    }

    private function childClone( FeedItem $parent_fi, array &$child, array $option_values, array $options ): void
    {
        $fi = clone $parent_fi;

        [$name, $mpn, $price] = $this->buildChildNameMpnPrice( $this->getMpn(), $option_values, $options );
        $fi->setProduct( $name );
        $fi->setCostToUs( $this->getCostToUs() + $price );
        $fi->setListPrice( $this->getListPrice() );
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

    public function isGroup(): bool
    {
        return $this->exists('#options_table');
//        return false;
    }

    public function getProduct(): string
    {
        return trim( $this->getAttr( 'meta[property="og:title"]', 'content' ) );
    }

    public function getMpn(): string
    {
        return $this->getText( 'span.product_code' );
    }

    public function getDescription(): string
    {
        if ( !isset( $this->product_info['description'] ) ) {
            return '';
        }

        return FeedHelper::cleanProductDescription( $this->product_info['description'] );
    }

    public function getShortDescription(): array
    {
        if ( !isset( $this->product_info['shorts'] ) ) {
            return [];
        }

        return FeedHelper::cleanShortDescription( $this->product_info['shorts'] );
    }

    public function getImages(): array
    {
        return array_map( static fn($image) => 'https:' . $image , $this->getAttrs( '#altviews a', 'href' ) );
    }

    public function getAttributes(): ?array
    {
        return $this->product_info['attributes'] ?? null;
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
        return $this->getAttr( 'meta[itemprop="availability"]', 'content' ) === 'InStock'
                    ? self::DEFAULT_AVAIL_NUMBER
                    : 0;
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
        return $this->product_info['dims']['x'] ?? null;
    }

    public function getDimY(): ?float
    {
        return $this->product_info['dims']['y'] ?? null;
    }

    public function getDimZ(): ?float
    {
        return $this->product_info['dims']['z'] ?? null;
    }

    public function getWeight(): ?float
    {
        return $this->product_info['weight'] ?? null;
    }

    public function getShippingDimX(): ?float
    {
        return $this->product_info['shipping_dims']['x'] ?? null;
    }

    public function getShippingDimY(): ?float
    {
        return $this->product_info['shipping_dims']['y'] ?? null;
    }

    public function getShippingDimZ(): ?float
    {
        return $this->product_info['shipping_dims']['z'] ?? null;
    }

    public function getShippingWeight(): ?float
    {
        return $this->product_info['shipping_weight'] ?? null;
    }

    public function getVideos(): array
    {
        return $this->product_info['videos'] ?? [];
    }

    public function getProductFiles(): array
    {
        return $this->product_info['files'] ?? [];
    }

    /**
     * @throws \JsonException
     */
    public function getChildProducts( FeedItem $parent_fi ): array
    {
        $child = [];
        $options = [];
        $option_groups = [];

        $this->filter( '#options_table select')
            ->each( function ( ParserCrawler $select ) use ( &$options, &$option_groups ) {
                $option_values = [];

                $select->filter( 'option' )
                    ->each( function ( ParserCrawler $option ) use ( &$options, &$option_values, $select ) {
                        $options[$option->attr( 'value' )] = [
                            'name' => $select->attr( 'title' ),
                            'value' => $option->text(),
                        ];

                        $option_values[] = $option->attr( 'value' );
                    });
                $option_groups[] = $option_values;
            });

        if ( count( $option_groups ) === 1 ) {
            $this->childClone( $parent_fi, $child, $option_groups[0], $options );
        }
        else {
            $combination_of_groups = $this->combinations( $option_groups );

            foreach ( $combination_of_groups as $option_values ) {
                $this->childClone( $parent_fi, $child, $option_values, $options );
            }
        }

        return $child;
    }
}

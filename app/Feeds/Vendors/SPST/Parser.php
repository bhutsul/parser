<?php

namespace App\Feeds\Vendors\SPST;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Parser\HtmlParser;
use App\Feeds\Utils\ParserCrawler;
use App\Helpers\FeedHelper;
use App\Helpers\StringHelper;


class Parser extends HtmlParser
{
    private array $product_info;

    private function getDims( string$val, float $contain_val ): array
    {
        $dims = FeedHelper::getDimsInString($val, 'x', 0, 2, 1);

        foreach ( $dims as $key => $value ) {
            if ( $value ) {
                $dims[$key] = FeedHelper::convert( $value, $contain_val );
            }
        }

        return $dims;
    }

    private function pushShortsAndAttributesAndDims( ParserCrawler $c ): void
    {
        if ( str_contains( $c->text(), ':' ) ) {
            [ $key, $val ] = explode( ':', $c->text() );

            if ( $key && $val ) {
                if (
                    false !== stripos( $key, 'large size' )
                    || false !== stripos( $key, 'measures' )
                    || false !== stripos( $key, 'bag size' )
                    || (
                        str_starts_with( $key, "Size" )
                        && substr_count( $val, 'cm' ) >= 2
                    )
                ) {
                    $this->product_info['dims'] = $this->getDims( $val, 0.39 );
                }
                else if (
                    str_starts_with( $key, "Size" )
                    && substr_count( $val, 'mm' ) >= 2
                ) {
                    $this->product_info['dims'] = $this->getDims( $val, 0.039 );
                }
                else if ( false !== stripos( $key, 'weight' ) ) {
                    $this->product_info['weight'] =  FeedHelper::convert( StringHelper::getFloat( $val ), 2.20 ) ;
                }
                else {
                    $this->product_info['attributes'][StringHelper::normalizeSpaceInString( $key )] = StringHelper::normalizeSpaceInString( $val );
                }
            }
        }
        else {
            $this->product_info['shorts'][] = StringHelper::normalizeSpaceInString( $c->text() );
        }
    }

    /**
     * @throws \JsonException
     */
    public function beforeParse(): void
    {
        preg_match( '/<script type="application\/ld\+json">\s*(\{.*?\})\s*<\/script>/s', $this->node->html(), $matches );
        if ( isset( $matches[1] ) ) {
            $this->product_info = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);

            $offer_key = array_search('Offer', array_column($this->product_info['offers'], '@type'), true);
            $this->product_info['offer'] = $this->product_info['offers'][$offer_key];

            $this->product_info['description'] = $this->exists( '#tab-description' )
                ? preg_replace( ['/<h2\b[^>]*>(.*?)<\/h2>/i',], '', $this->getHtml( '#tab-description' ) )
                : '';

            if ( $this->exists( '.woocommerce-product-details__short-description' ) ) {
                if ( $this->exists( '.woocommerce-product-details__short-description .block' ) ) {
                    $this->filter( '.woocommerce-product-details__short-description .block span' )
                        ->each( function ( ParserCrawler $c ) {
                            $this->pushShortsAndAttributesAndDims( $c );
                        } );
                }
                elseif ( $this->exists( '.woocommerce-product-details__short-description p' ) ) {
                    $short_desc = $this->filter( '.woocommerce-product-details__short-description p' );

                    if ( $short_desc->count() <= 1 ) {
                        if ( !$this->product_info['description'] ) {
                            $this->product_info['description'] = $short_desc->text();
                        }
                        else {
                            $this->product_info['shorts'][] = StringHelper::normalizeSpaceInString( $short_desc->text() );
                        }
                    }
                    else {
                        $short_desc->each( function ( ParserCrawler $c ) {
                            $this->pushShortsAndAttributesAndDims( $c );
                        } );
                    }

                }
            }
        }
    }

    public function isGroup(): bool
    {
        return $this->exists( '.variations_form' );
    }

    public function getProduct(): string
    {
        return $this->product_info['name'] ?? $this->getText( 'h2.product_title' );
    }

    public function getMpn(): string
    {
        return $this->product_info['sku'] ?? $this->getText( 'span.sku_wrapper .sku' );
    }

    public function getDescription(): string
    {
        return FeedHelper::cleanProductDescription( $this->product_info['description'] ?? '' );
    }

    public function getShortDescription(): array
    {
        return isset( $this->product_info['shorts'] )
                    ? FeedHelper::cleanShortDescription( $this->product_info['shorts'] )
                    : [];
    }

    public function getImages(): array
    {
        return $this->getAttrs( '[data-large_image]', 'data-large_image' );
    }

    public function getAttributes(): ?array
    {
        return $this->product_info['attributes'] ?? null;
    }

    public function getCostToUs(): float
    {
        return StringHelper::getMoney(
            $this->product_info['offer']['price']
                    ?? $this->getText( 'div.summary p.price ins span' )
                        ?: $this->getText( 'div.summary p.price span' )
        );
    }

    public function getListPrice(): ?float
    {
        if ( !$this->exists( 'div.summary p.price del' ) ) {
            return null;
        }

        return StringHelper::getMoney( $this->getText( 'div.summary p.price del span' ) );
    }

    public function getAvail(): ?int
    {
        if ( !isset( $this->product_info['offer']['price'] ) ) {
            return 0;
        }
        return $this->product_info['offer']['price'] === 'http://schema.org/InStock'
                    ? self::DEFAULT_AVAIL_NUMBER
                    : 0;
    }

    public function getCategories(): array
    {
        return array_slice( $this->getContent( '.posted_in a' ),0, 5 );
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

    /**
     * @throws \JsonException
     */
    public function getChildProducts( FeedItem $parent_fi ): array
    {
        $child = [];

        $variations = $this->getAttr( '.variations_form', 'data-product_variations' );
        $variations = json_decode( $variations, true, 512, JSON_THROW_ON_ERROR );

        foreach ( $variations as $variation ) {
            $fi = clone $parent_fi;

            $product_name = '';

            foreach ( $variation['attributes'] as $key => $combination ) {
                $product_name .= $combination;

                if ( $key !== array_key_last( $variation['attributes'] ) ) {
                    $product_name .= '-';
                }
            }

            $fi->setProduct( $product_name );
            $fi->setMpn($variation['sku'] . '-' .  $variation['variation_id'] );
            $fi->setImages( [$variation['image']['url']] );
            $fi->setCostToUs( StringHelper::getMoney( $variation['display_price'] ) );
            $fi->setRAvail( $variation['is_in_stock'] ? self::DEFAULT_AVAIL_NUMBER : 0 );
            $fi->setDimZ($variation['dimensions']['width'] ?: $this->getDimZ() );
            $fi->setDimY($variation['dimensions']['height'] ?: $this->getDimY() );
            $fi->setDimX($variation['dimensions']['length'] ?: $this->getDimX() );
            $fi->setWeight($variation['weight'] ?: $this->getWeight() );

            $child[] = $fi;
        }

        return $child;
    }
}

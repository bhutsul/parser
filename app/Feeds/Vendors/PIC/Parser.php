<?php

namespace App\Feeds\Vendors\PIC;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Parser\HtmlParser;
use App\Feeds\Utils\ParserCrawler;
use App\Helpers\FeedHelper;
use App\Helpers\StringHelper;


class Parser extends HtmlParser
{
    private array $product_info;

    /**
     * @throws \JsonException
     */
    public function beforeParse(): void
    {
        if ( $this->exists( 'table.shop_attributes' ) ) {
            $this->filter( 'table.shop_attributes  tr' )
                ->each( function ( ParserCrawler $c ) {
                    $key   = $c->getText( 'th' );
                    $value = $c->getText( 'td' );

                    if ( false !== stripos( $key, 'item weight' ) ) {
                        $this->product_info['weight'] = StringHelper::getFloat( $value );
                    }
                    else if ( false !== stripos( $key, 'item dimensions' ) ) {
                        $this->product_info['dims'] = FeedHelper::getDimsInString($value, 'x', 0, 2, 1);
                    }
                    else if ( false !== stripos( $key, 'shipping dimensions' ) ) {
                        $this->product_info['shipping_dims'] = FeedHelper::getDimsInString($value, 'x', 0, 2, 1);
                    }
                    else if ( false !== stripos( $key, 'shipping weight' ) ) {
                        $this->product_info['shipping_weight'] = StringHelper::getFloat( $value );
                    }
                    else if ( false !== stripos( $key, 'brand' ) ) {
                        $this->product_info['brand'] = StringHelper::getFloat( $value );
                    }
                    else if ( false !== stripos( $key, 'features' ) ) {
                        $shorts = explode('<br>', $c->getHtml( 'td p' ) );

                        foreach ( $shorts as $short ) {
                            $this->product_info['shorts'][] = $short;
                        }
                    }
                    else {
                        if (
                            false !== stripos( $key, 'components' )
                            &&  false === stripos( $key, 'number of components' )
                        ) {
                            $value = str_replace( "<br>", ' \n ', $c->getHtml( 'td p' ) );
                        }
                        $this->product_info['attributes'][$key] = $value;
                    }
                });
        }

        if ( $this->exists( '#product-description iframe') ) {
            $this->product_info['videos'][] = [
                'name' => $this->getProduct(),
                'provider' => 'youtube',
                'video' => $this->getAttr( '#product-description iframe', 'nitro-og-src' )
            ];
        }
    }

    public function isGroup(): bool
    {
        return $this->exists( '.variations_form' );
    }

    public function getProduct(): string
    {
        return $this->getText( 'h1.product_title' );
    }

    public function getMpn(): string
    {
        return $this->getText( 'span.sku_wrapper .sku' );
    }

    public function getBrand(): ?string
    {
        return $this->product_info['brand'] ?? null;
    }

    public function getDescription(): string
    {
        return FeedHelper::cleanProductDescription(
            $this->exists( '#product-description' )
                ? preg_replace( '/<h2\b[^>]*>(.*?)<\/h2>/i', '', $this->getHtml( '#product-description' ) )
                : $this->getHtml( '.woocommerce-product-details__short-description p' )
        );
    }

    public function getShortDescription(): array
    {
        return isset( $this->product_info['shorts'] )
                    ? FeedHelper::cleanShortDescription( $this->product_info['shorts'] )
                    : [];
    }

    public function getImages(): array
    {
        return $this->getAttrs( '.woocommerce-product-gallery .woocommerce-product-gallery__wrapper div ', 'data-thumb' );
    }

    public function getAttributes(): ?array
    {
        return $this->product_info['attributes'] ?? null;
    }

    public function getCostToUs(): float
    {
        return StringHelper::getMoney( $this->getText( 'div.summary p.price span' ) );
    }

    public function getAvail(): ?int
    {
        return $this->getAttr( 'meta[property="og:availability"]', 'content' ) === 'instock'
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

    /**
     * @throws \JsonException
     */
    public function getChildProducts(FeedItem $parent_fi ): array
    {
        $child = [];

        $variations = $this->getAttr( '.variations_form', 'data-product_variations' );
        $variations = json_decode( $variations, true, 512, JSON_THROW_ON_ERROR );

        foreach ( $variations as $variation ) {
            $fi = clone $parent_fi;

            $fi->setProduct($variation['attributes']['attribute_pa_color'] ?? '' );
            $fi->setMpn($variation['sku'] ?? '' );
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

<?php

namespace App\Feeds\Vendors\TTG;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Parser\HtmlParser;
use App\Helpers\StringHelper;


class Parser extends HtmlParser
{
    private array $variation_data;
    private array $product_data;

    /**
     * @throws \JsonException
     */
    public function beforeParse(): void
    {
        if ( $this->exists( '#wsite-com-product-view-variation-data' ) ) {
            $this->variation_data = json_decode(
                $this->getAttr('#wsite-com-product-view-variation-data', 'value' ),
                true,
                512,
                JSON_THROW_ON_ERROR
            );

            $this->product_data = $this->variation_data[0];
        }
    }

    public function isGroup(): bool
    {
        return isset( $this->variation_data ) && count( $this->variation_data ) > 1;
    }

    public function getProduct(): string
    {
        return $this->getText('[itemprop="name"]' );
    }

    public function getMpn(): string
    {
        $sku = $this->getText('[itemprop="sku"]' );

        if ( !$sku && isset( $this->product_data['site_product_sku_id'] ) ) {
            return $this->getProduct() . '-' . $this->product_data['site_product_sku_id'];
        }

        return $sku;
    }

    public function getDescription(): string
    {
        return $this->getHtml( '[itemprop="description"]' ) ;
    }

    public function getShortDescription(): array
    {
        return $this->getContent( 'div.tabbed-box-content-group div.paragraph span' );
    }

    public function getImages(): array
    {
        $images = $this->getSrcImages( '.wsite-com-product-images-secondary-image .wsite-imageaspectratio-image' );

        if ( !$images ) {
            return [];
        }

        return array_map( fn( $image ) => $image . $this->getUri(), $images);
    }

    public function getCostToUs(): float
    {
        return StringHelper::getMoney( $this->product_data['sale_price'] ?? $this->product_data['price'] ?? $this->getAttr( '[itemprop="sku"]', 'content' ) );
    }

    public function getListPrice(): ?float
    {
        if ( isset( $this->product_data['sale_price'], $this->product_data['price'] ) ) {
            return StringHelper::getMoney( $this->product_data['price'] );
        }

        return null;
    }

    public function getAvail(): ?int
    {
        return $this->product_data['inventory'] ?? 0;
    }

    public function getCategories(): array
    {
        return array_values( array_slice( $this->getContent( '.wsite-com-breadcrumbs a' ), 2, -1 ) );
    }

    public function getChildProducts( FeedItem $parent_fi ): array
    {
        $child = [];

        $variations = array_values($this->variation_data);

        foreach ( $variations as $key => $variation ) {
            if ( $key > 0 ) {
                $fi = clone $parent_fi;


                $fi->setProduct( $option['choices'] );
                $fi->setCostToUs( $this->getCostToUs() );
                $fi->setRAvail( $this->getAvail() );
                $fi->setCategories( $this->getCategories() );
                $fi->setMpn( $this->getMpn() . '-' . $option['choices'] );

                $child[] = $fi;
            }
        }

        return $child;
    }

}

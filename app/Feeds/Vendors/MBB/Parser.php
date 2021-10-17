<?php

namespace App\Feeds\Vendors\MBB;

use App\Feeds\Parser\WoocommerceParser;

class Parser extends WoocommerceParser
{
    private array $product_info;

    public function beforeParse(): void
    {
        preg_match( '/<script type="application\/ld\+json">\s*({.*?})\s*<\/script>/s', $this->node->html(), $matches );
        if ( isset( $matches[ 1 ] ) ) {
            $json = json_decode( $matches[ 1 ], true, 512, JSON_THROW_ON_ERROR );

            $this->product_info = $json;

            $offer_key = array_search( 'Offer', array_column( $this->product_info[ 'offers' ], '@type' ), true );
            $this->product_info[ 'offer' ] = $this->product_info[ 'offers' ][ $offer_key ];
        }
    }

    public function getProduct(): string
    {
        return $this->product_info[ 'name' ] ?? '';
    }

    public function getMpn(): string
    {
        return $this->product_info[ 'sku' ] ?? '';
    }

    public function getDescription(): string
    {
        return $this->product_info[ 'description' ] ?? '';
    }

    public function getImages(): array
    {
        return $this->getAttrs('.sp-wrap a', 'href');
    }

    public function getAvail(): ?int
    {
        return isset( $this->product_info[ 'offer' ][ 'availability' ] ) && $this->product_info[ 'offer' ][ 'availability' ] === 'http://schema.org/InStock' ? self::DEFAULT_AVAIL_NUMBER : 0;
    }
}

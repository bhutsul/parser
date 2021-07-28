<?php

namespace App\Feeds\Vendors\ULI;

use App\Feeds\Parser\HtmlParser;
use App\Helpers\StringHelper;

class Parser extends HtmlParser
{
    private array $product_info = [];

    public function beforeParse(): void
    {
        preg_match( '/application\/ld\+json">(.*)<\//', $this->node->html(), $matches );
        if ( isset( $matches[ 1 ] ) ) {
            $product_info = $matches[ 1 ];

            $this->product_info = json_decode( $product_info, true, 512, JSON_THROW_ON_ERROR );
        }
    }

    public function getProduct(): string
    {
        return $this->product_info[ 'name' ] ?? '';
    }

    public function getShortDescription(): array
    {
        if ( !isset( $this->product_info[ 'description' ] ) ) {
            return [];
        }

        $shorts = explode( '.', $this->product_info[ 'description' ] );

        if (!$shorts) {
            return [];
        }

        return $shorts;
    }

    public function getDescription(): string
    {
        return $this->getHtml( '#productInfoContainer' );
    }

    public function getCostToUs(): float
    {
        if ( !isset( $this->product_info[ 'price' ] ) ) {
            return 0;
        }

        return StringHelper::getMoney( $this->product_info[ 'price' ] );
    }

    public function getMpn(): string
    {
        return $this->product_info[ 'sku' ] ?? '';
    }

    public function getAvail(): ?int
    {
        if ( !isset( $this->product_info[ 'availability' ] ) ) {
            return 0;
        }

        return in_array( $this->product_info[ 'availability' ], [
            'https://schema.org/InStock',
            'http://schema.org/InStock',
            'InStock'
        ] ) ? self::DEFAULT_AVAIL_NUMBER : 0;
    }
}

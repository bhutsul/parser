<?php

namespace App\Feeds\Vendors\PBS;

use App\Feeds\Parser\HtmlParser;
use App\Helpers\StringHelper;

class Parser extends HtmlParser
{
    private array $product_info;

    public const NOT_VALID_PARTS_OF_DESC = [
        'No Credit Needed',
        'Click Banner',
    ];

    private function descriptionIsValid( string $text ): bool
    {
        foreach ( self::NOT_VALID_PARTS_OF_DESC as $str ) {
            if ( false !== stripos( $text, $str ) ) {
                return false;
            }
        }

        return true;
    }

    public function beforeParse(): void
    {
        preg_match( '/<script type="application\/ld\+json">\s*({.*?})\s*<\/script>/s', $this->node->html(), $matches );
        if ( isset( $matches[ 1 ] ) ) {
            $this->product_info = json_decode( $matches[ 1 ], true, 512, JSON_THROW_ON_ERROR );
            $this->product_info[ 'description' ] = '';

            $description = preg_replace( [ '/<\w+.*?>/', '/<\/\w+>/' ], "\n", $this->getHtml( '#productDescription' ) );
            $parts_of_description = explode( "\n", StringHelper::normalizeSpaceInString( $description ) );

            if ( $parts_of_description ) {
                foreach ( $parts_of_description as $text ) {
                    if ( $text && $this->descriptionIsValid( $text ) ) {
                        if ( str_contains( $text, ':' ) ) {
                            [ $key, $value ] = explode( ':', $text, 2 );
                            if ( empty( $value ) ) {
                                $this->product_info[ 'description' ] .= '<p>' . $text . '</p>';
                            }
                            else {
                                $this->product_info[ 'attributes' ][ trim( $key ) ] = trim( StringHelper::normalizeSpaceInString( $value ) );
                            }
                        }
                        else {
                            $this->product_info[ 'description' ] .= '<p>' . $text . '</p>';
                        }
                    }
                }
            }
        }
    }

    public function getProduct(): string
    {
        return $this->product_info[ 'name' ] ?? $this->getText( '[itemprop="name"]' );
    }

    public function getMpn(): string
    {
        return $this->product_info[ 'sku' ] ?? '';
    }

    public function getAvail(): ?int
    {
        return isset( $this->product_info[ 'availability' ] ) && $this->product_info[ 'availability' ] === 'http://schema.org/InStock' ? self::DEFAULT_AVAIL_NUMBER : 0;
    }

    public function getDescription(): string
    {
        return $this->product_info[ 'description' ] ?? $this->getProduct();
    }

    public function getImages(): array
    {
        if ( !isset( $this->product_info[ 'image' ] ) ) {
            return [];
        }
        return is_array( $this->product_info[ 'image' ] ) ? $this->product_info[ 'image' ] : [ $this->product_info[ 'image' ] ];
    }

    public function getAttributes(): ?array
    {
        return $this->product_info[ 'attributes' ] ?? null;
    }

    public function getCostToUs(): float
    {
        return StringHelper::getMoney( $this->getAttr( '[itemprop="price"]', 'content' ) );
    }

    public function getCategories(): array
    {
        return array_values( array_slice( $this->getContent( '.ec-breadcrumbs a' ), 2, -1 ) );
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
}

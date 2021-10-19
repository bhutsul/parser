<?php

namespace App\Feeds\Vendors\MRK;

use App\Feeds\Parser\HtmlParser;
use App\Feeds\Utils\ParserCrawler;
use App\Helpers\FeedHelper;
use App\Helpers\StringHelper;

class Parser extends HtmlParser
{
    public const URI = 'https://www.mar-k.com';

    private array $shipping_dims;
    private array $attributes;

    private function parseDims(): void
    {
        $contains_more_boxes = preg_match('/box\s\d/i', $this->getText('#boxing'));

        $this->filter( '#boxing .row .row' )->each( function ( ParserCrawler $c ) use ($contains_more_boxes) {
            $node = $c->getNode( 0 );

            if ( !$node ) {
                return;
            }

            [ $key, $value ] = explode( ':', $node->textContent, 2 );

            if ( $value && $key ) {
                if ($contains_more_boxes) {
                    $name_of_box = $c->parents()->getText('p');
                    $key = $name_of_box . ': ' . trim( $key );
                    $this->attributes[ $key ] = trim( StringHelper::normalizeSpaceInString( $value ) );
                } else {
                    if ( false !== stripos( $key, 'Box dimensions' ) ) {
                        $this->shipping_dims = FeedHelper::getDimsInString( $value, 'x', 2, 0, 1 );
                    }

                    if ( false !== stripos( $key, 'Total weight' ) ) {
                        $this->shipping_dims[ 'weight' ] = StringHelper::getFloat( $value );
                    }
                }
            }
        } );
    }

    private function parsedParts(): string
    {
        if ( !$this->exists( '#components' ) ) {
            return '';
        }

        $parts = '<p>Package includes</p>';

        $table_values = $this->filter( '#components table tr' );

        for ( $i = 1; $i < $table_values->count(); $i++ ) {
            $values = $table_values->eq( $i );

            $product_code = $values->filter( 'td' )->first()->text();
            if ( $product_code ) {
                $parts .= '<p>product_code ' . $product_code . '</p>';
            }
        }

        return $parts;
    }

    public function beforeParse(): void
    {
        $this->parseDims();
    }

    public function getProduct(): string
    {
        return $this->getText( '#lblProductCategory' );
    }

    public function getCostToUs(): float
    {
        return StringHelper::getFloat( $this->getText( '#lblPriceTop' ), 0 );
    }

    public function getMpn(): string
    {
        return $this->getText( '#lblItemID' );
    }

    public function getDescription(): string
    {
        $description = preg_replace( [
            '/<b>PLEASE NOTE.*?7945[.]?<\/b>/si',
        ], '', $this->getHtml( '#lblLongDesc' ) );

        return $description . $this->parsedParts();
    }

    public function getImages(): array
    {
        $image = self::URI . $this->getAttr( '#imgItem', 'src' );

        return false === stripos( $image, 'NotAvail' ) ? [ $image ] : [];
    }

    public function getAttributes(): ?array
    {
        return $this->attributes ?? null;
    }

    public function getProductFiles(): array
    {
        if ( !$this->exists( '#hlCatalog' ) ) {
            return [];
        }

        return [ [
            'name' => rtrim( $this->getText( '#hlCatalog' ), ' (PDF)' ),
            'link' => self::URI . ltrim( $this->getAttr( '#hlCatalog', 'href' ), '.' )
        ] ];
    }

    public function getAvail(): ?int
    {
        return self::DEFAULT_AVAIL_NUMBER;
    }

    public function getShippingDimX(): ?float
    {
        return $this->shipping_dims[ 'x' ] ?? null;
    }

    public function getShippingDimY(): ?float
    {
        return $this->shipping_dims[ 'y' ] ?? null;
    }

    public function getShippingDimZ(): ?float
    {
        return $this->shipping_dims[ 'z' ] ?? null;
    }

    public function getShippingWeight(): ?float
    {
        return $this->shipping_dims[ 'weight' ] ?? null;
    }
}

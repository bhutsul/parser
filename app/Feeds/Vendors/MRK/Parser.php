<?php

namespace App\Feeds\Vendors\MRK;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Parser\HtmlParser;
use App\Feeds\Utils\ParserCrawler;
use App\Helpers\FeedHelper;
use App\Helpers\StringHelper;

class Parser extends HtmlParser
{
    public const URI = 'https://www.mar-k.com';

    private array $shipping_dims;
    private array $attributes;
    private string $description;

    private function parseDims(): void
    {
        if ( $this->filter( '#boxing .row .row' )->count() > 4 ) {
            $this->description .= $this->getHtml( '#boxing' );
            return;
        }

        $this->filter( '#boxing .row .row' )->each( function ( ParserCrawler $c ) {
            $node = $c->getNode( 0 );

            if ( !$node ) {
                return;
            }

            [ $key, $value ] = explode( ':', $node->textContent, 2 );

            if ( $value && $key ) {
                if ( false !== stripos( $key, 'Box dimensions' ) ) {
                    $this->shipping_dims = FeedHelper::getDimsInString( $value, 'x', 2, 0, 1 );
                }

                if ( false !== stripos( $key, 'Total weight' ) ) {
                    $this->shipping_dims[ 'weight' ] = StringHelper::getFloat( $value );
                }
            }
        } );
    }

    private function parseParts(): void
    {
        if ( !$this->exists( '#components' ) ) {
            return;
        }

        $parts = '<p>Package includes</p><table><tbody>';

        $head_of_table = $this->filter( '#components table tr' )->first();

        $parts .= '<tr>';
        $head_of_table->filter( 'th' )->each( function ( ParserCrawler $c ) use ( &$parts ) {
            if ( $c->nextAll()->count() < 2 ) {
                return;
            }
            $parts .= '<th>';
            $parts .= $c->text();
            $parts .= '</th>';
        } );
        $parts .= '</tr>';

        $head_of_table->nextAll()->each( function ( ParserCrawler $c ) use ( &$parts ) {
            if ( $c->nextAll()->count() !== 0 ) {
                $parts .= '<tr>';
                $c->filter( 'td' )->each( function ( ParserCrawler $c ) use ( &$parts ) {
                    if ( $c->nextAll()->count() < 2 ) {
                        return;
                    }
                    $parts .= '<td>';
                    if ( $c->previousAll()->count() === 0 ) {
                        $parts .= $this->vendor->getPrefix() . $c->text();
                    }
                    else {
                        $parts .= $c->text();
                    }
                    $parts .= '</td>';
                } );
                $parts .= '</tr>';
            }
        } );

        $parts .= '</table></tbody>';

        $this->description .= $parts;
    }

    private function parseGroups(): void
    {
        $final_table = '<p><strong>Grouped:</strong></p>';
        $this->filter( '#morespecs div.col-sm-6' )->each( function ( ParserCrawler $c ) use ( &$final_table ) {
            if ( str_contains( $c->text(), 'Grouped:' ) ) {
                $html = $c->html();

                $array_tables = explode( "<br>", $html );
                $i = 1;
                foreach ( $array_tables as $table_part ) {
                    if ( StringHelper::isNotEmpty( $table_part ) && false === stripos($table_part, 'Grouped:') ) {
                        $final_table .= '<p><strong>Group ' . $i . '</strong></p>';
                        $i++;

                        $crawler = new ParserCrawler( $table_part );
                        
                        $final_table .= '<table>';
                        
                        $crawler->filter( 'div.row' )->each( function ( ParserCrawler $c2 ) use ( &$final_table ) {
                            $final_table .= '<tr>';
                            if ( StringHelper::isNotEmpty( $c2->getText( '.col-sm-2' ) ) ) {
                                $final_table .= '<td>' . $c2->getText( '.col-sm-2' ) . '</td>';
                            } else {
                                $final_table .= '<td>&nbsp;</td>';
                            }
                            $final_table .= '<td>' . $c2->getText( '.col-sm-10' ) . '</td>';
                            $final_table .= '</tr>';
                        } );
                        
                        $final_table .= '</table>';
                    }
                }
            }
        } );

        $this->description .= $final_table;
    }

    public function beforeParse(): void
    {
        $this->description = preg_replace( [
            ' /<b >.*?<\/b >/si',
        ], '', $this->getHtml( '#lblLongDesc' ) );

        if ( $this->exists( '#lblUnitOfMeasure' ) && $this->getText( '#lblUnitOfMeasure' ) ) {
            $this->attributes[ 'Unit' ] = $this->getText( '#lblUnitOfMeasure' );
        }
        $this->parseParts();
        $this->parseGroups();
        $this->parseDims();
    }

    public function afterParse( FeedItem $fi ): void
    {
        if ( false !== stripos( $this->getProduct(), 'gift' ) ) {
            $fi->setCostToUs( 0 );
            $fi->setRAvail();
            $fi->setMpn( '' );
            $fi->setImages( [] );
        }
    }

    public function getProduct(): string
    {
        return $this->getText( '#lblProductCategory' );
    }

    public function getMinAmount(): ?int
    {
        return StringHelper::getFloat( $this->getText( '#lblDefaultQty' ), 1 );
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
        return $this->description;
    }

    public function getImages(): array
    {
        $image = self::URI . $this->getAttr( '#imgItem', 'src' );

        return false === stripos( $image, 'NotAvail' ) ? [ $image ] : [];
    }

    public function getAttributes(): ?array
    {
        return !empty( $this->attributes ) ? $this->attributes : null;
    }

    public function getAvail(): ?int
    {
        return $this->exists( '#lblQtyAvailable' ) ? StringHelper::getFloat( $this->getText( '#lblQtyAvailable' ), self::DEFAULT_AVAIL_NUMBER ) : self::DEFAULT_AVAIL_NUMBER;
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

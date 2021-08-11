<?php

namespace App\Feeds\Vendors\UDU;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Parser\HtmlParser;
use App\Feeds\Utils\ParserCrawler;
use App\Helpers\FeedHelper;
use App\Helpers\StringHelper;


class Parser extends HtmlParser
{
    public const WEIGHT_REGEX = '/(\d+[\.]?\d*)[\s]?lbs[\.]?/i';
    public const DIMENSIONS_REGEX = '/(\d+[\.]?\d*+(?:\s\d{1,3}+\/\d{1,3})?)[\',"]?[\s]?[A-Z]?[\s]?x\s(\d+[\.]?\d*+(?:\s\d{1,3}+\/\d{1,3})?)[\',"]?[\s]?[A-Z]?[\s]?x\s(\d+[\.]?\d*+(?:\s\d{1,3}+\/\d{1,3})?)[\',"]?[\s]?[A-Z]?/i';

    private array $product_info;
    private array $dims;
    private string $weight;

    /**
     * @throws \JsonException
     */
    public function beforeParse(): void
    {
        preg_match( '/<script type="application\/ld\+json">\s*(\{.*?\})\s*<\/script>/s', $this->node->html(), $matches );
        if ( isset( $matches[1] ) ) {
            $json = json_decode( $matches[1], true, 512, JSON_THROW_ON_ERROR );

            if ( isset( $json['@graph'] ) ) {
                $product_key = array_search( 'Product', array_column( $json['@graph'], '@type' ), true );
                $this->product_info = $json['@graph'][$product_key];

                $offer_key = array_search('Offer', array_column($this->product_info['offers'], '@type'), true);
                $this->product_info['offer'] = $this->product_info['offers'][$offer_key];

                if ( $this->exists( '#tab-additional_information table' ) ) {
                    $this->filter( '#tab-additional_information table tr' )
                        ->each( function ( ParserCrawler $c ) {
                            $key   = $c->getText( 'th' );
                            $value = $c->getText( 'td' );

                            $matches = [];
                            if (
                                ( false !== stripos( $key, 'weight' ) )
                                && preg_match( self::WEIGHT_REGEX, $value, $matches )
                            ) {
                                $this->weight = $matches[1];
                            }
                            else if (
                                ( false !== stripos( $key, 'dimensions' ) )
                                && preg_match( self::DIMENSIONS_REGEX, $value )
                            ) {
                                $this->dims = FeedHelper::getDimsRegexp( $value, [self::DIMENSIONS_REGEX], 2, 1 );
                            }
                            else {
                                $this->product_info['attributes'][$key] = $value;
                            }
                        });
                }
            }
        }
    }

    public function getProduct(): string
    {
        return $this->product_info['name'] ?? '';
    }

    public function getMpn(): string
    {
        return $this->product_info['sku'] ?? '';
    }

    public function getDescription(): string
    {
        return $this->product_info['description'] ?? '';
    }

    public function getImages(): array
    {
        return isset( $this->product_info['image'] ) ? [ $this->product_info['image'] ] : [];
    }

    public function getAttributes(): ?array
    {
        return $this->product_info['attributes'] ?? null;
    }

    public function getCostToUs(): float
    {
        if ( !isset( $this->product_info['offer']['price'] ) ) {
            return 0;
        }

        return StringHelper::getMoney( $this->product_info['offer']['price'] );
    }

    public function getListPrice(): ?float
    {
        if ( !$this->exists( 'p.price del' ) ) {
            return null;
        }

        return StringHelper::getMoney( $this->getText( 'p.price del' ) );
    }

    public function getAvail(): ?int
    {
        if ( !isset( $this->product_info['offer']['availability'] ) ) {
            return 0;
        }

        return $this->product_info['offer']['availability'] === 'http://schema.org/InStock'
            ? self::DEFAULT_AVAIL_NUMBER
            : 0;
    }

    public function getDimX(): ?float
    {
        return $this->dims['x'] ?? null;
    }

    public function getDimY(): ?float
    {
        return $this->dims['y'] ?? null;
    }

    public function getDimZ(): ?float
    {
        return $this->dims['z'] ?? null;
    }

    public function getWeight(): ?float
    {
        if ( !isset( $this->weight ) ) {
            return null;
        }

        return StringHelper::getFloat( $this->weight );
    }

}

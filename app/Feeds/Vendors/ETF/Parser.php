<?php

namespace App\Feeds\Vendors\ETF;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Parser\HtmlParser;
use App\Feeds\Utils\ParserCrawler;
use App\Helpers\FeedHelper;
use App\Helpers\StringHelper;


class Parser extends HtmlParser
{
    private array $attributes = [];
    private array $dims = [];
    private int|string $weight;

    public const DIMENSIONS_REGEX = '/(\d+[\.]?\d*+(?:\s\d{1,3}+\/\d{1,3})?)[\',"]?[\s]?[A-Z]?[\s]?x\s(\d+[\.]?\d*+(?:\s\d{1,3}+\/\d{1,3})?)[\',"]?[\s]?[A-Z]?[\s]?x\s(\d+[\.]?\d*+(?:\s\d{1,3}+\/\d{1,3})?)[\',"]?[\s]?[A-Z]?/i';
    public const WEIGHT_REGEX = '/(\d+[\.]?\d*)[\s]?[[a-zA-Z]{2,2}[\.]?[\s]?]?(:?\d[\.]?\d*)?[\s]?[a-zA-Z]?[{2,2}]?[\.]?/i';

    private function pushWeight( string $description ): void
    {
        if ( preg_match( self::WEIGHT_REGEX, $description, $matches )) {
            if ( isset( $matches[2] ) ) {
                $this->weight = StringHelper::getFloat( $matches[1] ) + FeedHelper::convertLbsFromOz( StringHelper::getFloat( $matches[2] ) );
            }
            else if ( isset( $matches[1] ) ) {
                $this->weight = FeedHelper::convertLbsFromOz( StringHelper::getFloat( $matches[1] ) );
            }
        }
    }

    private function pushParametersFromAttributes( ParserCrawler $li ): void
    {
        $attributes = explode(':', $li->text() );

        if ( !$attributes || 2 !== count( $attributes )) {
            return;
        }

        [$key, $value] = $attributes;

        if ( isset( $key, $value ) ) {
            $value = trim( $value );

            if (
                false !== stripos( $key, 'dimensions' )
                && false === stripos( $key, 'glasses open' )
                && false === stripos( $key, 'glasses closed' )
            ) {
                $this->dims = FeedHelper::getDimsRegexp(
                    str_replace('”', "", $value ),
                    [self::DIMENSIONS_REGEX],
                    2
                    , 1
                );
            }
            else if ( false !== stripos( $key, 'weight' ) ) {
                $this->pushWeight( $value );
            }
            else {
                $this->attributes[$key] = $value;
            }
        }
    }

    public function beforeParse(): void
    {
        $this->filter( 'li#container_2 ul li p' )
            ->each( function ( ParserCrawler $c ) {
                $this->attributes[$c->getText( 'strong' )] = $c->text();
            });

        if ( $this->exists( 'li#container_2 ul ul' ) ) {
            $this->filter( 'li#container_2 ul ul li' )
                ->each( function ( ParserCrawler $c_li ) {
                    $this->pushParametersFromAttributes( $c_li );
                });
        }

        if (
            $this->exists( 'li#container_1 div.description ul' )
            && stripos($this->getText( 'li#container_1 div.description ul li' ), 'specifications')
        ) {
            $this->filter( 'li#container_1 div.description ul li ul' )
                ->each( function ( ParserCrawler $c ) {
                    $c->filter( 'li' )
                        ->each( function ( ParserCrawler $c_li ) {
                            $this->pushParametersFromAttributes( $c_li );
                        });
                });
        }
    }

//    public function isGroup(): bool
//    {
//        return $this->exists('dl.last');
//    }

    public function getProduct(): string
    {
        return $this->getText('div.product-name h1');
    }

    public function getDescription(): string
    {
        return $this->getHtml( 'li#container_1 div.description p' ) ;
    }

    public function getShortDescription(): array
    {
        return explode('.', $this->getText( 'div.short-description div.std' ));
    }

    public function getImages(): array
    {
        return array_filter( $this->getLinks( 'div.more-views ul li a' ) , fn( $image ) => $image !== $this->getUri() ) ;
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
        return $this->weight ?? null;
    }

    public function getMpn(): string
    {
        return $this->getText( 'p.product-model-text span' );
    }

    public function getCostToUs(): float
    {
        return StringHelper::getMoney( $this->getText( 'div.price-box span.regular-price span.price' ) );
    }

    public function getAvail(): ?int
    {
        $availability = $this->getText(  'p.availability span' );

        return in_array( $availability, ['In stock', 'In Stock', 'InStock'] )
            ? self::DEFAULT_AVAIL_NUMBER
            : 0;
    }

    public function getAttributes(): ?array
    {
        return $this->attributes ?: null;
    }

    public function getCategories(): array
    {
        return array_values( array_slice( $this->getContent( '.breadcrumbs a' ), 2, -1 ) );
    }

//    public function getChildProducts( FeedItem $parent_fi ): array
//    {
////        $fi = clone $parent_fi;
////
////        $fi->setMpn($variant['sku'] ?? '' );
////        $fi->setUpc( $this->productUpc($variant) );
////        $fi->setProduct( $this->getChildProductName($variant['option_values']) );
////        $fi->setImages( $images );
////        $fi->setCostToUs( StringHelper::getMoney( $variant['price'] ) );
////        $fi->setRAvail( $variant['inventory_level'] );
////        $fi->setDimZ($variant['depth'] ?: $this->getDimZ() );
////        $fi->setDimY($variant['height'] ?: $this->getDimY() );
////        $fi->setDimX($variant['width'] ?: $this->getDimX() );
////        $fi->setWeight($variant['weight'] ?: $this->getWeight() );
////
////        $child[] = $fi;
//
//        return $child;
//    }
}

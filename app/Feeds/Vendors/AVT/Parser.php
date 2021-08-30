<?php

namespace App\Feeds\Vendors\AVT;

use App\Feeds\Parser\HtmlParser;
use App\Feeds\Utils\ParserCrawler;
use App\Helpers\FeedHelper;
use App\Helpers\StringHelper;


class Parser extends HtmlParser
{
    public const YOUTUBE_REGEX = 'http(?:s?):\/\/(?:www\.)?youtu(?:be\.com\/watch\?v=|\.be\/)([\w\-\_]*)(&(amp;)?‌​[\w\?‌​=]*)?';
    public const YOUTUBE_REPLACE_REGEX = 'Watch these tips before starting[:]?[\s]?' . self::YOUTUBE_REGEX;
    public const COMMON_DIMS_REGEX = '(\d+[\.]?\d*)[\',",″]?[\s]?\*[\s]?(\d+[\.]?\d*)[\',",″]?[\s]?\*[\s]?(:?\d+[\.]?\d*)?[\',",″]?[\s]?(in)?[\.]?';
    public const DIMENSIONS = [
        'shipping' => '/Box Dimensions[:]?[\s]?'. self::COMMON_DIMS_REGEX .'/i',
        'product' => '/Assembled Model Dimensions[:]?[\s]?'. self::COMMON_DIMS_REGEX .'/i',
    ];


    private null|array $product_info;

    private function pushDimsAndAttributes( string $key, string $value ): void
    {
        $value = str_replace( 'onepercentfortheplanet.org', '', $value );

        if ( trim( $value ) ) {
            if ( false !== stripos( $key, 'box dimensions' ) ) {
                $this->product_info[ 'shipping_dims' ] = FeedHelper::getDimsInString( $value, '*' );
            }
            else if ( false !== stripos( $key, 'assembled model dimensions' ) ) {
                $this->product_info[ 'dims' ] = FeedHelper::getDimsInString( $value, '*' );
            }
            else {
                $this->product_info[ 'attributes' ][ trim( $key )  ] = false !== stripos( $key, 'Safety & Compliance' )
                    ? $this->replaceByRegex( $value )
                    : $value;
            }
        }
    }

    private function pushVideo( string $value ): bool
    {
        if (
            preg_match( '/' . self::YOUTUBE_REGEX . '/u', $value, $matches_yt )
            && isset( $matches_yt[ 1 ] )
        ) {
            $this->product_info[ 'videos' ][] = [
                'name' => $this->getProduct(),
                'provider' => 'youtube',
                'video' => 'https://youtu.be/' . $matches_yt[ 1 ]
            ];

            return true;
        }

        return false;
    }

    private function replaceByRegex( $string ): string
    {
        return preg_replace( [
            '/' . self::YOUTUBE_REPLACE_REGEX . '/u',
            '/onepercentfortheplanet.org/u',
            self::DIMENSIONS[ 'shipping' ],
            self::DIMENSIONS[ 'product' ],
        ], '', $string );
    }

    private function parseDimsDescriptionAndAttributes(): void
    {
        if ( $this->exists( '[data-hook="collapse-info-section"] li' ) ) {
            $this->filter( '[data-hook="collapse-info-section"] li' )
                ->each( function ( ParserCrawler $c )  {
                    $key = $c->getText( 'h2' );
                    $value = $c->getText( 'div[data-hook="info-section-description"]' );

                    $this->pushDimsAndAttributes( $key, $value );

                    $this->pushVideo( $value );
                } );
        }

        if ( $this->exists( 'pre[data-hook="description"]' ) ) {
            if ( $this->exists( 'pre[data-hook="description"] p' ) ) {
                $this->filter('pre[data-hook="description"] p')
                    ->each( function ( ParserCrawler $c ) {
                        if ( $c->text() ) {
                            if ( str_contains( $c->text(), ':' ) ) {
                                [$key, $value] = explode( ':', $c->text(), 2 );

                                if ( $value ) {
                                    $key = ltrim( $key, '- ' );

                                    $this->pushDimsAndAttributes( $key, $value );

                                    $video_push = $this->pushVideo( $value );

                                    if ( $video_push && isset( $this->product_info[ 'attributes' ][ $key ] ) ) {
                                        unset( $this->product_info[ 'attributes' ][ $key ] );
                                    }
                                }
                            } else {
                                $this->product_info['description'] .= '<p>' . $c->text() . '</p>';
                            }
                        }
                    });
            }
            else {
                $description = $this->getText( 'pre[data-hook="description"]' );

                $this->pushVideo( $description );

                if ( preg_match( self::DIMENSIONS[ 'shipping' ], $description) ) {
                    $this->product_info[ 'shipping_dims' ] = FeedHelper::getDimsRegexp( $description, [ self::DIMENSIONS[ 'shipping' ] ] );
                }

                if ( preg_match( self::DIMENSIONS[ 'product' ], $description ) ) {
                    $this->product_info[ 'dims' ] = FeedHelper::getDimsRegexp( $description, [ self::DIMENSIONS[ 'shipping' ] ] );
                }

                $this->product_info[ 'description' ] = $description ;
            }
        }
    }
    /**
     * @throws \JsonException
     */
    public function beforeParse(): void
    {
        for ( $i = 0; $i <= 1; $i ++ ) {
            if ( $this->exists( '#wix-warmup-data' ) ) {
                $warmup_data = json_decode( $this->getText( '#wix-warmup-data' ), true, 512, JSON_THROW_ON_ERROR );

                if ( isset( $warmup_data[ 'appsWarmupData' ] ) && count( $warmup_data[ 'appsWarmupData' ] ) ) {
                    $product = $warmup_data[ 'appsWarmupData' ][array_key_first($warmup_data[ 'appsWarmupData' ])];

                    $this->product_info = $product[array_key_first( $product )][ 'catalog' ][ 'product' ] ?? null;

                    if ( $this->product_info ) {
                       $this->parseDimsDescriptionAndAttributes();
                    }

                    break;
                }

                $this->node = new ParserCrawler( $this->getVendor()->getDownloader()->get( $this->getUri() )->getData() );
            }
        }

    }

    public function getProduct(): string
    {
        return $this->product_info[ 'name' ] ?? $this->getAttr( 'meta[property="og:title"]', 'content' );
    }

    public function getMpn(): string
    {
        return isset( $this->product_info[ 'sku' ] )
                && $this->product_info[ 'sku' ]
                    ? $this->product_info[ 'sku' ]
                    : $this->product_info[ 'id' ] ?? '';
    }

    public function getDescription(): string
    {
        if ( !isset( $this->product_info[ 'description' ] ) ) {
            return '';
        }

        return FeedHelper::cleanProductDescription( $this->replaceByRegex( $this->product_info[ 'description' ] ) );
    }

    public function getShortDescription(): array
    {
        return $this->product_info[ 'short_desc' ] ?? [];
    }

    public function getAttributes(): ?array
    {
        return $this->product_info[ 'attributes' ] ?? null;
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

    public function getShippingDimX(): ?float
    {
        return $this->product_info[ 'shipping_dims' ][ 'x' ] ?? null;
    }

    public function getShippingDimY(): ?float
    {
        return $this->product_info[ 'shipping_dims' ][ 'y' ] ?? null;
    }

    public function getShippingDimZ(): ?float
    {
        return $this->product_info[ 'shipping_dims' ][ 'z' ] ?? null;
    }

    public function getImages(): array
    {
        return $this->getAttrs( 'div[data-hook="main-media-image-wrapper"] div', 'href' );
    }

    public function getVideos(): array
    {
        return $this->product_info[ 'videos' ] ?? [];
    }

    public function getCostToUs(): float
    {
        return StringHelper::getMoney(
            $this->product_info[ 'price' ] ?? $this->getText( 'span[data-hook="formatted-primary-price"]' )
        );
    }

    public function getAvail(): ?int
    {
        return isset( $this->product_info[ 'inventory' ][ 'status' ] )
                && $this->product_info[ 'inventory' ][ 'status' ] === 'in_stock'
                    ? $this->product_info[ 'inventory' ][ 'quantity' ]
                    : 0;
    }
}

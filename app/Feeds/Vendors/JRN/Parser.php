<?php

namespace App\Feeds\Vendors\JRN;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Parser\HtmlParser;
use App\Feeds\Utils\ParserCrawler;
use App\Helpers\FeedHelper;
use App\Helpers\StringHelper;


class Parser extends HtmlParser
{
    private array $product_info;
    private static string $variant_uri = 'https://www.jracenstein.com/includes/custom_includes/mt360_GetMatrixItemDetails.asp';
    private static string $attributes_uri = 'https://www.jracenstein.com/includes/custom_includes/e2_GetProductAttribs.asp';

    /**
     * @param $child
     * @param FeedItem $parent_fi
     * @param ParserCrawler $option_selects
     * @param array $parent_params
     * @param int $parent_iteration
     * @throws \JsonException
     */
    private function recursiveGetOptionRequest(
        &$child,
        FeedItem $parent_fi,
        ParserCrawler $option_selects,
        array $parent_params = [],
        int $parent_iteration = 1
    ): void {
        $select = $option_selects->eq( $parent_iteration - 1 );
        $options = array_filter( $select->getAttrs( 'option', 'value' ) );

        foreach ( $options as $option ) {
            $params = $parent_params;
            $params[] = $option;
            $iteration = $parent_iteration;

            if ( $iteration !== $option_selects->count() ) {
                $data = $this->getVendor()
                    ->getDownloader()
                    ->get( self::$attributes_uri, $this->preparedParams( 'at', $params ) + [
                        'gc' => $this->product_info['sku']
                    ] );
                $selects = ( new ParserCrawler( $data->getData() ) )->filter( 'select' );

                $iteration++;

                $this->recursiveGetOptionRequest( $child, $parent_fi, $selects, $params, $iteration );
            }
            else {
                $this->childClone( $parent_fi, $child, $this->preparedParams( 'a', $params ) + [
                    'mg' => $this->product_info['sku'],
                    'lvl' => 'Web'
                ] );
            }
        }
    }

    /**
     * method for prepare request params for options
     * @param string $key
     * @param array $array
     * @return array
     */
    private function preparedParams( string $key, array $array ): array
    {
        $prepared_params = [];

        foreach ( $array as $i => $item ) {
            $params_position = $i + 1;

            $prepared_params[$key . $params_position] = $item;
        }

        return $prepared_params;
    }

    /**
     * @throws \JsonException
     */
    private function childClone( FeedItem $parent_fi, array &$child, array $params ): void
    {
        $load = 1;
        do {
            $data_load = $this->getVendor()->getDownloader()->get( self::$variant_uri, $params );

            if ( $data_load->getData() ) {
                break;
            }

            $load++;
        } while ( $load <= 5);

        $data = json_decode( $data_load->getData(), true, 512, JSON_THROW_ON_ERROR );

        if ( isset( $data[0] ) ) {
            $product = $data[0];

            $fi = clone $parent_fi;

            $fi->setMpn( $product['ITEMNO'] ?? $product['MPN'] ?? '' );
            $fi->setUpc( $product['UPC'] ?: null );
            $fi->setProduct( $product['ITEMNAME'] );
            $fi->setCostToUs( StringHelper::getMoney( $product['PRICE'] ) );
            $fi->setListPrice( StringHelper::getMoney( $product['REGULARPRICE'] ) );
            $fi->setRAvail( $product['STOCK'] === 'YES' ? self::DEFAULT_AVAIL_NUMBER : 0 );

            $child[] = $fi;
        }
    }

    /**
     * @throws \JsonException
     */
    public function beforeParse(): void
    {
        preg_match('/<script type="application\/ld\+json">\s*({.*?})\s*</s', $this->node->html(), $matches );

        if ( isset( $matches[1] ) ) {
            $json = preg_replace( '/"description":(.*?)",/', '', $matches[1]);
            $this->product_info = json_decode($json, true);


            if ( $this->exists( '#mainItemDesc' ) ) {
                $this->product_info['descriptions'] = FeedHelper::getShortsAndAttributesInDescription( $this->getHtml( '#mainItemDesc' ) );

                $this->filter( '#mainItemDesc a' )
                    ->each( function ( ParserCrawler $c ) {
                        if ( false !== stripos( $c->attr( 'href' ), 'pdf' ) ) {
                            $this->product_info['files'][] = [
                                'name' => $c->text(),
                                'link' => 'https://www.jracenstein.com' . $c->attr( 'href' )
                            ];
                        }
                    });
            }
        }
    }

    public function isGroup(): bool
    {
        return $this->exists( '#mainItemAttribsDIV' );
    }

    public function getProduct(): string
    {
        return $this->product_info['name'] ?? '';
    }

    public function getBrand(): ?string
    {
        return $this->product_info['brand']['name'] ?? '';
    }

    public function getDescription(): string
    {
        if ( !isset( $this->product_info['descriptions']['description'] ) ) {
            return '';
        }

        return FeedHelper::cleanProductDescription( preg_replace([
                '/<a\b[^>]*>(.*?)<\/a>/i',
            ], '', $this->product_info['descriptions']['description'] )
        );
    }

    public function getShortDescription(): array
    {
        $shorts = $this->getContent( '#mainItemSummary .short-description ul li' );

        if ( isset( $this->product_info['descriptions']['short_description'] ) ) {
            $shorts = array_merge( $shorts, $this->product_info['descriptions']['short_description'] );
        }

        return $shorts;
    }

    public function getAttributes(): ?array
    {
        return $this->product_info['descriptions']['attributes'] ?? null;
    }

    public function getImages(): array
    {
        return array_values(
            array_filter(
                array_unique( $this->getSrcImages( '.imgRttPopBig' ) ),
                    static fn( $image ) => false === stripos( $image, 'coming_soon' )
            )
        );
    }

    public function getVideos(): array
    {
        if ( !$this->exists( '#ExtVidPop' ) ) {
            return [];
        }

        return array_map( fn( $id ) => [
            'name' => $this->getProduct(),
            'provider' => 'youtube',
            'video' => 'https://www.youtube.com/embed/' . $id
        ], $this->getAttrs( '.ytvid ', 'data-ytid' ) );
    }

    public function getProductFiles(): array
    {
        return $this->product_info['files'] ?? [];
    }

    public function getMpn(): string
    {
        return $this->product_info['sku'] ?? '';
    }

    public function getCostToUs(): float
    {
        if ( !isset( $this->product_info['offers']['price'] ) ) {
            return 0;
        }

        return StringHelper::getMoney( $this->product_info['offers']['price'] );
    }

    public function getListPrice(): ?float
    {
        if ( !$this->exists( '#prodPriceContainer .old-price .price' ) ) {
            return 0;
        }

        return StringHelper::getMoney( $this->getText( '#prodPriceContainer .old-price .price' ) );
    }

    public function getAvail(): ?int
    {
        if ( !isset( $this->product_info['offers']['availability'] ) ) {
            return 0;
        }

        return $this->product_info['offers']['availability'] === 'http://schema.org/InStock'
                    ? self::DEFAULT_AVAIL_NUMBER
                    : 0;
    }

    public function getCategories(): array
    {
        return array_values( array_slice( $this->getContent( '.breadcrumbs a' ), 2, -1 ) );
    }

    /**
     * @throws \JsonException
     */
    public function getChildProducts(FeedItem $parent_fi ): array
    {
        if ( !isset( $this->product_info['sku'] ) ) {
            return [];
        }

        $child = [];

        $selects = $this->filter( '#mainItemAttribsDIV select' );

        if ( $selects->count() === 1 ) {
            $options = array_filter( $selects->getAttrs( 'option', 'value' ) );

            foreach ( $options as $option ) {
                $this->childClone( $parent_fi,$child, $this->preparedParams( 'a', [$option] ) + [
                    'mg' => $this->product_info['sku'],
                    'lvl' => 'Web',
                ] );
            }
        }
        else if ( $selects->count() > 1 ) {
            $this->recursiveGetOptionRequest( $child, $parent_fi, $selects );
        }

        return $child;
    }
}

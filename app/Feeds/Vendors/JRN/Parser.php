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
     * @param int $iteration
     * @throws \JsonException
     */
    private function recursiveGetOptionRequest(
        &$child,
        FeedItem $parent_fi,
        ParserCrawler $option_selects,
        array $parent_params = [],
        int $iteration = 0
    ): void {
        $select = $option_selects->eq( $iteration );
        $options = array_filter( $select->getAttrs( 'option', 'value' ) );
        $iteration++;

        foreach ( $options as $option ) {
            $params = $parent_params ;
            $params[] = $option;

            if ( $iteration !== $option_selects->count() ) {
                $data = $this->getVendor()
                    ->getDownloader()
                    ->get(
                        self::$attributes_uri,
                        $this->preparedParams('at', $params) + ['gc' => $this->product_info['sku']]
                    );
                $selects = ( new ParserCrawler( $data->getData() ) )->filter( 'select' );

                $this->recursiveGetOptionRequest(
                    $child,
                    $parent_fi,
                    $selects,
                    $params,
                    $iteration
                );
            }
            else {
                $iteration = 0;
                $this->childClone(
                    $parent_fi,
                    $this->preparedParams('a', $params) + ['mg' => $this->product_info['sku'], 'lvl' => 'Web'],
                    $child
                );
            }
        }
    }

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
    private function childClone( FeedItem $parent_fi, array $params, array &$child ): void
    {
        $data = $this->getVendor()->getDownloader()->get( self::$variant_uri, $params );
        $data = json_decode( $data, true, 512, JSON_THROW_ON_ERROR );

        if ( isset( $data[0] ) ) {
            $product = $data[0];

            $fi = clone $parent_fi;

            $fi->setMpn( $product['MPN'] ?? $product['ITEMNO'] ?? '' );
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
        preg_match_all( '/<script type="application\/ld\+json">\s*(\{.*?\})\s*<\/script>/s', $this->node->html(), $matches );

        if ( isset( $matches[1] ) ) {
            foreach ( $matches[1] as $match ) {
                $json = json_decode( $match, true, 512, JSON_THROW_ON_ERROR );
                if ( isset( $json['@type'] ) && $json['@type'] === 'Product' ) {
                    $this->product_info = $json;
                }
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

//    public function getDescription(): string
//    {
//        return $this->getHtml( 'li#container_1 div.description p' ) ;
//    }

    public function getShortDescription(): array
    {
        return $this->getContent( '#mainItemSummary .short-description ul li' );
    }

    public function getImages(): array
    {
        return $this->getSrcImages( '.imgRttPopBig' );
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

    public function getMpn(): string
    {
        return $this->product_info['sku'] ?? '';
    }

    public function getCostToUs(): float
    {
        return 222;
//        return StringHelper::getMoney( $this->getText( 'div.price-box span.regular-price span.price' ) );
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

        $params = [
            'md' => $this->product_info['sku'],
            'lvl' => 'Web',
        ];

        $child = [];

        $selects = $this->filter( '#mainItemAttribsDIV select' );

        if ( $selects->count() === 1 ) {
            $options = array_filter( $selects->getAttrs( 'option', 'value' ) );

            foreach ( $options as $key => $option ) {
                $params["a$key"] = $option;

                $this->childClone( $parent_fi, $params, $child);
            }
        }
        else if ( $selects->count() > 1 ) {
            $this->recursiveGetOptionRequest( $child, $parent_fi, $selects );
        }


        return $child;
    }
}

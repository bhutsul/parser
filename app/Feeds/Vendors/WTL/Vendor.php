<?php

namespace App\Feeds\Vendors\WTL;

use App\Feeds\Processor\HttpProcessor;
use App\Feeds\Utils\Collection;
use App\Feeds\Utils\Data;
use App\Feeds\Utils\Link;
use App\Feeds\Utils\ParserCrawler;
use App\Helpers\HttpHelper;

class Vendor extends HttpProcessor
{
    public const CATEGORY_LINK_CSS_SELECTORS = [ '#main-menu .menu li a', '.pager a' ];
    public const PRODUCT_LINK_CSS_SELECTORS = [ '.views-field-field-itemno a' ];
    public array $custom_products = [
//        'https://www.wtliving.com/products/home-furniture/braga-metal-stool-table-container-antique-bronze-finish',
//        'https://www.wtliving.com/products/patio-sense-furniture/patio-seating/comfort-height-coconino-armchair-mocha-all-weather-wicker',
        'https://wtliving.com/products/patio-sense-furniture/patio-seating/sava-indoor-outdoor-folding-chair-warm-gray-webbing',
        'https://wtliving.com/products/vinyl-outdoor-covers/outdoor-patio-heater-head-vinyl-cover',
    ];
    protected array $headers = [
        'Connection' => 'keep-alive',
        'Accept' => '*/*',
    ];
    protected const STATIC_USER_AGENT = true;
    protected const DELAY_S = 0.3;

    private function preparedData( string $url ): Data
    {
        $this->getDownloader()->setUserAgent( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/93.0.4577.63 Safari/537.36' );
        $cookies = HttpHelper::sucuri( $this->getDownloader()->get( $url )->getData() );
        if (isset($cookies[ 0 ], $cookies[ 1 ] )) {
            $this->getDownloader()->setCookie( $cookies[ 0 ], $cookies[ 1 ] );
        }

        return $this->getDownloader()->get( $url );
    }

    public function beforeProcess()
    {
        $this->getQueue()->addLinks( [ new Link( 'https://www.wtliving.com' ) ], Collection::LINK_TYPE_CATEGORY );
    }

    public function getCategoriesLinks( Data $data, string $url ): array
    {
        return parent::getCategoriesLinks( $this->preparedData( $url ), $url );
    }

    public function getProductsLinks( Data $data, string $url ): array
    {
        return parent::getProductsLinks( $this->preparedData( $url ), $url );
    }

//    /**
//     * @param FeedItem $fi
//     * @return bool
//     */
//    protected function isValidFeedItem(FeedItem $fi ): bool
//    {
//        if ( $fi->isGroup() ) {
//            $fi->setChildProducts( array_values(
//                array_filter( $fi->getChildProducts(), static fn( FeedItem $item ) => !empty( $item->getMpn() ) && count( $item->getImages() ) && $item->getCostToUs() > 0 )
//            ) );
//            return count( $fi->getChildProducts() );
//        }
//
//        return !empty( $fi->getMpn() ) && count( $fi->getImages() ) && $fi->getCostToUs() > 0;
//    }
}

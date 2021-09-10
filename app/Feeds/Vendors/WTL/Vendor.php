<?php

namespace App\Feeds\Vendors\WTL;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Processor\HttpProcessor;
use App\Feeds\Utils\Collection;
use App\Feeds\Utils\Data;
use App\Feeds\Utils\Link;
use App\Helpers\HttpHelper;

class Vendor extends HttpProcessor
{
    public const CATEGORY_LINK_CSS_SELECTORS = [ '#main-menu .menu li a', '.pager a' ];
    public const PRODUCT_LINK_CSS_SELECTORS = [ '.views-field-field-itemno a' ];

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
        if ( isset( $cookies[ 0 ], $cookies[ 1 ] ) ) {
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

    protected function isValidFeedItem( FeedItem $fi ): bool
    {
        return !empty( $fi->getMpn() ) && count( $fi->getImages() );
    }
}

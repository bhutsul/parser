<?php

namespace App\Feeds\Vendors\WTL;

use App\Feeds\Processor\HttpProcessor;
use App\Feeds\Utils\Link;

class Vendor extends HttpProcessor
{
    protected array $first = ['https://www.wtliving.com/products'];
    public const CATEGORY_LINK_CSS_SELECTORS = ['.view-content a'];
    public const PRODUCT_LINK_CSS_SELECTORS = ['.view-content table tr span.field-content a'];
    protected array $headers = [
        'accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',
        'cookie' => 'sucuri_cloudproxy_uuid_8ee7bcb6d=8b82b78d4a791a19be50c6d64277067d; has_js=1; BVImplmain_site=14983; BVBRANDID=efe615b4-df4f-4821-925b-1bfc85debade; BVBRANDSID=58585cde-0f23-4183-93c7-8f515f3e1aba'
    ];

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

    public function filterProductLinks( Link $link ): bool
    {
        return str_contains( $link->getUrl(), '/product/' );
    }
}

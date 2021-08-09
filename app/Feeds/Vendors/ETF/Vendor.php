<?php

namespace App\Feeds\Vendors\ETF;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Processor\SitemapHttpProcessor;
use App\Feeds\Utils\Link;

class Vendor extends SitemapHttpProcessor
{
    protected array $first = ['https://www.electroflip.com/sitemap/sitemapfull.xml'];
//    public array $custom_products = [
//        'https://www.electroflip.com/products/dual-dashboard-camera',
//        'https://www.electroflip.com/products/gadgets/sketch-light-panel',
//        'https://www.electroflip.com/products/gps-tracking-devices/itrack-4g-lte-gps-tracker',
//        'https://www.electroflip.com/products/helmet-video-cams/headcam2-waterproof-forehead-camera',
//    ];

    /**
     * product can be without image or sku
     * @param FeedItem $fi
     * @return bool
     */
//    protected function isValidFeedItem(FeedItem $fi ): bool
//    {
//        if ( $fi->isGroup() ) {
//            $fi->setChildProducts( array_values(
//                array_filter( $fi->getChildProducts(), static fn( FeedItem $item ) => !empty( $item->getMpn() ) && count( $item->getImages() ) && $item->getCostToUs() > 0 )
//            ) );
//            return count( $fi->getChildProducts() );
//        }
//
//        return !empty( $fi->getMpn() ) && count( $fi->getImages() );
//    }
//    public function filterProductLinks( Link $link ): bool
//    {
//        return str_contains( $link->getUrl(), '/product/' );
//    }
}

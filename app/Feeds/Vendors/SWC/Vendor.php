<?php

namespace App\Feeds\Vendors\SWC;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Processor\SitemapHttpProcessor;
use App\Feeds\Utils\Link;

class Vendor extends SitemapHttpProcessor
{
    protected array $first = ['https://www.canadiandisplay.ca/sitemap.xml'];
    public array $custom_products = [
        'https://www.canadiandisplay.ca/Wall-Mount-Vinyl-Rack-54-Width-p/bvr54.htm',
//        'https://www.canadiandisplay.ca/Custom-Acrylic-Plaque-Lobby-Sign-Kit-p/dllsk03.htm',
    ];
//    protected ?int $max_products = 300;
    
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

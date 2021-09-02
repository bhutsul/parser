<?php

namespace App\Feeds\Vendors\TSL;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Processor\SitemapHttpProcessor;
use App\Feeds\Utils\Link;

class Vendor extends SitemapHttpProcessor
{
    protected array $first = ['https://www.taylorsecurity.com/sitemap.xml'];
    public array $custom_products = [
//        'https://www.taylorsecurity.com/securitech-sb175-series-safebolt-instant-button-activated-lockdown-lock/',
        'https://www.taylorsecurity.com/deadbolt-by-emtek-empowered-motorized-touchscreen-keypad-deadbolt/'
    ];

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

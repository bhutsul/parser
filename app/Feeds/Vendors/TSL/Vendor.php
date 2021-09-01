<?php

namespace App\Feeds\Vendors\TSL;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Processor\SitemapHttpProcessor;
use App\Feeds\Utils\Link;

class Vendor extends SitemapHttpProcessor
{
    protected array $first = ['https://www.taylorsecurity.com/sitemap.xml'];
    public array $custom_products = [
//        'https://www.taylorsecurity.com/5400-key-storage/',
//        'https://www.taylorsecurity.com/hes-1006-electric-strike-1006-630-1006-blk-1006-613-1006-lbm-1006-lbsm-1006f-1006f-630-1006f-blk-1006f-613-1006f-lbm-1006f-lbsm/'
        'https://www.taylorsecurity.com/codelocks-kl1200-sg-kitlock-cabinet-electronic-keyless-door-lock/'
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

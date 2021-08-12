<?php

namespace App\Feeds\Vendors\JRN;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Processor\SitemapHttpProcessor;

class Vendor extends SitemapHttpProcessor
{
    protected array $first = ['https://www.jracenstein.com/sitemaps/sitemap_products.xml'];
    public array $custom_products = [
//        'https://www.jracenstein.com/p/soft-wash-nozzle-tip/150-0m',
//        'https://www.jracenstein.com/p/descent-control-window-cleaners-chair-sky-genie/92-21m',
//        'https://www.jracenstein.com/p/unger-micro-wipe/273-1m/',
//        'https://www.jracenstein.com/p/sky-genie-descender-large-1-2in-rope/93-214',
//        'https://www.jracenstein.com/p/sprayer-system-50-gallon-w-150ft-hose/150-0420',
//        'https://www.jracenstein.com/p/protool-clever-spraying-system/150-0759',
    'https://www.jracenstein.com/p/wagtail-squeegee-aluminum-slimline/02-7m'
    ];

    /**
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

}

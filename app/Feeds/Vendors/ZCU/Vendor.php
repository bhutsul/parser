<?php

namespace App\Feeds\Vendors\ZCU;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Processor\SitemapHttpProcessor;

class Vendor extends SitemapHttpProcessor
{
    protected array $first = ['https://www.zcush.com/store-products-sitemap.xml'];
    /**
     * @param FeedItem $fi
     * @return bool
     */
//    protected function isValidFeedItem(FeedItem $fi ): bool
//    {
//        return !empty( $fi->getMpn() ) && count( $fi->getImages() ) && $fi->getCostToUs() > 0;
//    }

}

<?php

namespace App\Feeds\Vendors\TTG;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Processor\SitemapHttpProcessor;

class Vendor extends SitemapHttpProcessor
{
    protected array $first = ['https://www.thetingoat.com/sitemap.xml'];
    protected const USE_PROXY = true;

    /**
     * @param FeedItem $fi
     * @return bool
     */
//    protected function isValidFeedItem(FeedItem $fi ): bool
//    {
//        return !empty( $fi->getMpn() ) && count( $fi->getImages()) && $fi->getCostToUs() > 0 ;
//    }

}

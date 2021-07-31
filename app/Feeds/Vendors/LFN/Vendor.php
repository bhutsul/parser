<?php

namespace App\Feeds\Vendors\LFN;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Processor\SitemapHttpProcessor;
use App\Feeds\Utils\Link;

class Vendor extends SitemapHttpProcessor
{
    protected array $first = ['https://www.lawnfawn.com/sitemap_products_1.xml?from=12090522&to=6611714736202'];

    public function filterProductLinks( Link $link ): bool
    {
        return str_contains( $link->getUrl(), '/products/' );
    }

    protected function isValidFeedItem(FeedItem $fi ): bool
    {
        return !empty( $fi->getMpn() ) && count( $fi->getImages() ) && $fi->getCostToUs() > 0;
    }
}

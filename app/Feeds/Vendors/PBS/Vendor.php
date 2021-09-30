<?php

namespace App\Feeds\Vendors\PBS;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Processor\SitemapHttpProcessor;

class Vendor extends SitemapHttpProcessor
{
    protected array $first = ['https://pricebusters.furniture/sitemap.xml'];
    public array $custom_products = ['https://pricebusters.furniture/2038-Soft-Touch-Leather-Match-2Pc-Sectional-p387710932'];

    protected function isValidFeedItem(FeedItem $fi ): bool
    {
        return !empty( $fi->getMpn() ) && count( $fi->getImages() ) && $fi->getCostToUs() > 0;
    }
}

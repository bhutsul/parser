<?php

namespace App\Feeds\Vendors\PBS;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Processor\SitemapHttpProcessor;
use App\Feeds\Utils\Link;

class Vendor extends SitemapHttpProcessor
{
    protected array $first = ['https://pricebusters.furniture/sitemap.xml'];
    public array $custom_products = ['https://pricebusters.furniture/8517-Full-Size-Broadway-Plush-Mattress-p307287712'];

    protected function isValidFeedItem(FeedItem $fi ): bool
    {
        return !empty( $fi->getMpn() ) && count( $fi->getImages() ) && $fi->getCostToUs() > 0;
    }
}

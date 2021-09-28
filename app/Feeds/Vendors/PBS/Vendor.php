<?php

namespace App\Feeds\Vendors\PBS;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Processor\SitemapHttpProcessor;
use App\Feeds\Utils\Link;

class Vendor extends SitemapHttpProcessor
{
    protected array $first = ['https://pricebusters.furniture/sitemap.xml'];
    public array $custom_products = ['https://pricebusters.furniture/Home-Elegance-9479SDB-RECLINER-p364363888'];

    protected function isValidFeedItem(FeedItem $fi ): bool
    {
        return !empty( $fi->getMpn() ) && count( $fi->getImages() ) && $fi->getCostToUs() > 0;
    }
}

<?php

namespace App\Feeds\Vendors\JMF;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Processor\SitemapHttpProcessor;

class Vendor extends SitemapHttpProcessor
{
    protected array $first = ['https://www.justrite.com/pub/sitemap/sitemap.xml'];
    public array $custom_products = ['https://www.justrite.com/25777-safe-t-vent-thermally-actuated-damper-for-venting-cabinets'];
    protected const DELAY_S = 0.1;

    protected function isValidFeedItem(FeedItem $fi ): bool
    {
        return !empty( $fi->getMpn() ) && count( $fi->getImages() );
    }
}

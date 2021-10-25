<?php

namespace App\Feeds\Vendors\MRK;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Processor\SitemapHttpProcessor;
use App\Feeds\Utils\Link;

class Vendor extends SitemapHttpProcessor
{
    protected array $first = [ 'https://www.mar-k.com/sitemap.xml' ];

    protected function isValidFeedItem( FeedItem $fi ): bool
    {
        return !empty( $fi->getMpn() ) && count( $fi->getImages() ) && $fi->getCostToUs() > 0;
    }

    public function filterProductLinks( Link $link ): bool
    {
        return !str_contains( $link->getUrl(), 'https://www.mar-k.com/%7' );
    }
}
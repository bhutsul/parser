<?php

namespace App\Feeds\Vendors\DLO;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Processor\SitemapHttpProcessor;

class Vendor extends SitemapHttpProcessor
{
    protected array $first = ['https://www.delasco.com/xmlsitemap.php'];

    /**
     * product can be without image or sku
     * @param FeedItem $fi
     * @return bool
     */
    protected function isValidFeedItem(FeedItem $fi ): bool
    {
        if ( $fi->isGroup() ) {
            $fi->setChildProducts( array_values(
                array_filter( $fi->getChildProducts(), static fn( FeedItem $item ) => !empty( $item->getMpn() ) && count( $item->getImages() ) && $item->getCostToUs() > 0 )
            ) );
            return count( $fi->getChildProducts() );
        }

        return !empty( $fi->getMpn() ) && count( $fi->getImages() );
    }
}

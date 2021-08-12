<?php

namespace App\Feeds\Vendors\UDU;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Processor\HttpProcessor;

class Vendor extends HttpProcessor
{
    public const CATEGORY_LINK_CSS_SELECTORS = ['#menu-item-376 a', '.woocommerce-pagination ul li a'];
    public const PRODUCT_LINK_CSS_SELECTORS = ['.woocommerce-LoopProduct-link'];

    protected array $first = ['https://urnsdirect2u.com/'];

    /**
     * @param FeedItem $fi
     * @return bool
     */
    protected function isValidFeedItem(FeedItem $fi ): bool
    {
        return !empty( $fi->getMpn() ) && count( $fi->getImages() );
    }

}

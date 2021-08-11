<?php

namespace App\Feeds\Vendors\UDU;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Processor\HttpProcessor;

class Vendor extends HttpProcessor
{
    public const CATEGORY_LINK_CSS_SELECTORS = ['#menu-item-376 a', '.woocommerce-pagination ul li a'];
    public const PRODUCT_LINK_CSS_SELECTORS = ['.woocommerce-LoopProduct-link'];

    protected array $first = ['https://urnsdirect2u.com/'];
//    public array $custom_products = ['https://urnsdirect2u.com/product/american-flag-cremation-urn-pendant/', 'https://urnsdirect2u.com/product/espresso-cafe-georgian-adult-urn/'];

    /**
     * @param FeedItem $fi
     * @return bool
     */
//    protected function isValidFeedItem(FeedItem $fi ): bool
//    {
//        if ( $fi->isGroup() ) {
//            $fi->setChildProducts( array_values(
//                array_filter( $fi->getChildProducts(), static fn( FeedItem $item ) => !empty( $item->getMpn() ) && count( $item->getImages() ) && $item->getCostToUs() > 0 )
//            ) );
//            return count( $fi->getChildProducts() );
//        }
//
//        return !empty( $fi->getMpn() ) && count( $fi->getImages() );
//    }

}

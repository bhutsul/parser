<?php

namespace App\Feeds\Vendors\PST;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Processor\HttpProcessor;

class Vendor extends HttpProcessor
{
    protected array $first = ['https://www.etsy.com/shop/premiumsoftees/'];

    public const CATEGORY_LINK_CSS_SELECTORS = ['[data-item-pagination] a' ];
    public const PRODUCT_LINK_CSS_SELECTORS = [ '[data-listings-container] .listing-link' ];
    protected array $headers = [
        'Connection' => 'keep-alive',
        'Accept' => '*/*',
    ];
    protected const DELAY_S = 0.2;

    public function beforeProcess(): void
    {
        $this->getDownloader()->removeCookies();
        $this->getDownloader()->setCookie('user_prefs', 'NRjsj1bKAcFiwwQg3CnlecNDc9FjZACCRA-zZxC68lC0Umiwi5JOXmlOjo5Sap5uaLCSjhKIAIsYQShcRCwDAA..');
    }

    protected function isValidFeedItem(FeedItem $fi ): bool
    {
        if ( $fi->isGroup() ) {
            $fi->setChildProducts( array_values(
                array_filter( $fi->getChildProducts(), static fn( FeedItem $item ) => !empty( $item->getMpn() ) && count( $item->getImages() ) && $item->getCostToUs() > 0 )
            ) );
            return count( $fi->getChildProducts() );
        }

        return !empty( $fi->getMpn() ) && count( $fi->getImages() ) && $fi->getCostToUs() > 0;
    }
}

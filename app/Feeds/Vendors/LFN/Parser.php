<?php

namespace App\Feeds\Vendors\LFN;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Parser\ShopifyParser;
use App\Helpers\FeedHelper;

class Parser extends ShopifyParser
{
    public const DIMENSIONS_REGEX = '/(\d{1,3})[\',"]?[\s]?x[\s]?(\d{1,3})[\',"]?/i';

    public function beforeParse(): void
    {
        if ( $this->meta ) {
            $this->meta[ 'title' ] = preg_replace('/&qout;/', '', $this->meta[ 'title' ] );
//            $this->meta[ 'title' ] = html_entity_decode( $this->meta[ 'title' ] );

            $dims = FeedHelper::getDimsRegexp( $this->meta[ 'title' ], [self::DIMENSIONS_REGEX] );

            $this->meta[ 'width' ]  = $dims[ 'x' ];
            $this->meta[ 'height' ] = $dims[ 'y' ];
        }
    }

    public function getDimX(): ?float
    {
        return $this->meta[ 'width' ] ?? null;
    }

    public function getDimY(): ?float
    {
        return $this->meta[ 'height' ] ?? null;
    }

    public function getChildProducts( FeedItem $parent_fi ): array
    {
        return array_map( static function (FeedItem $item ) {
            $dims = FeedHelper::getDimsRegexp( $item->getProduct(), [self::DIMENSIONS_REGEX] );
            $item->setDimY( $dims[ 'y' ] );
            $item->setDimX( $dims[ 'x' ] );

            return $item;
        }, parent::getChildProducts( $parent_fi ) );
    }

    public function getCategories(): array
    {
        return array_slice( parent::getCategories(), 0, 5 );
    }
}

<?php

namespace App\Feeds\Vendors\LFN;

use App\Feeds\Feed\FeedItem;
use App\Feeds\Parser\ShopifyParser;
use App\Helpers\FeedHelper;

class Parser extends ShopifyParser
{
    protected function getRegex( string $description ): array
    {
        if ( preg_match( '/(\d+[\.]?\d*)[\',"]?\sround/i', $description ) ) {
            return [ 'x', '/(\d+[\.]?\d*)[\',"]/i' ];
        }

        return [ 'y', '/(\d+[\.]?\d*)[\',"]\sx\s(\d+[\.]?\d*)[\',"]?/i' ];
    }

    public function beforeParse(): void
    {
        if ( $this->meta ) {
            $this->meta[ 'title' ] = preg_replace('/&qout;/', '', $this->meta[ 'title' ] );
//            $this->meta[ 'title' ] = html_entity_decode( $this->meta[ 'title' ] );

            [ $key, $regex ] = $this->getRegex( $this->meta[ 'title' ] );

            $dims = FeedHelper::getDimsRegexp( $this->meta[ 'title' ], [$regex] );

            $this->meta[ 'width' ]  = $dims[ 'x' ];
            $this->meta[ 'height' ] = $dims[ $key ];
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
        return array_map( function (FeedItem $item ) {
            $description = $item->getProduct();

            [ $key, $regex ] = $this->getRegex( $description );

            $dims = FeedHelper::getDimsRegexp( $description, [ $regex ] );

            $item->setDimY( $dims[ $key ] );
            $item->setDimX( $dims[ 'x' ] );

            return $item;
        }, parent::getChildProducts( $parent_fi ) );
    }

    public function getCategories(): array
    {
        return array_slice( parent::getCategories(), 0, 5 );
    }
}

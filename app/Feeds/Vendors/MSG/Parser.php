<?php

namespace App\Feeds\Vendors\MSG;

use App\Feeds\Parser\HtmlParser;
use App\Feeds\Utils\ParserCrawler;
use App\Helpers\FeedHelper;
use App\Helpers\StringHelper;

class Parser extends HtmlParser
{
    public const NOT_VALID_ATTRIBUTES = [
        'contact_us',
    ];

    public const NOT_VALID_OPTIONS = [
        'custom',
        'we will call',
    ];

    public const NOT_VALID_PARTS_OF_DESC_REGEXES = [
        '/<strong\b[^>]*><span\b[^>]*>\*Receive.*?checkout.<\/strong>/ui',
        '/<strong\b[^>]*>If you need.*?order \*{1,3}<\/strong>/ui',
        '/<br>Approx Dimensions:.*?<br><br>/ui',
        '/<br><br>Approx. Dimensions:.*?<br><br>/ui',
    ];

    public const FEATURES_REGEXES = [
        '/<(div|p|span|b|strong|h\d|em)>Features(s)?:?(?<content_list><br>.*?<br><br>)/is',
    ];

    private string $name = '';
    private float $price = 0;
    private array|null $attributes = null;
    private string $description = '';
    private array $short_description = [];
    private array $dims = [];

    private function parseAttributesAndShorts(): array
    {
        $attributes = [];
        $short_description = [];

        $this->filter( '.product-attributes .product-attribute' )->each( function ( ParserCrawler $c ) use ( &$attributes, &$short_description ) {
            $text = $c->text();
            if ( str_contains( $text, ':' ) ) {
                [ $key, $value ] = explode( ':', $text, 2 );
                $attributes[ trim( $key ) ] = trim( StringHelper::normalizeSpaceInString( $value ) );
            }
            else {
                $short_description[] = $text;
            }
        } );

        return [
            FeedHelper::cleanShortDescription( $short_description ),
            FeedHelper::cleanAttributes( $attributes ),
        ];
    }

    private function parsePrice(): float
    {
        $price = $this->getText( '.prod-detail-price .prod-detail-cost-value' );

        if ( preg_match( '/(regularly[\s]?\$[\s]?\d+[,]?[.]?\d*[,]?[.]?\d*?)/ui', $price, $matches ) && isset( $matches[ 1 ] ) ) {
            $price = $matches[ 1 ];
        }

        return StringHelper::getFloat( $price, 0 );
    }

    private function validatedAttributes( array|null $attributes ): array|null
    {
        if ( !isset( $attributes ) ) {
            return null;
        }

        $validated = [];

        foreach ( $attributes as $key => $value ) {
            if ( false !== stripos( $key, 'sold by the' ) ) {
                $this->name .= " ( $key: $value )";
                break;
            }

            foreach ( self::NOT_VALID_ATTRIBUTES as $not_valid_attribute ) {
                if ( false !== stripos( $key, $not_valid_attribute ) || false !== stripos( $value, $not_valid_attribute ) ) {
                    break;
                }
                $validated[ $key ] = $value;
            }
        }

        return $validated;
    }

    private function parseOptionBySelectors( &$options, string $select_selector, string $label_selector ): void
    {
        $this->filter( $select_selector )->each( function ( ParserCrawler $select ) use ( &$options, $label_selector ) {
            $name = $select->parents()->parents()->parents()->getText( $label_selector );

            if ( !$name ) {
                return;
            }
            $name = StringHelper::removeSpaces( $name );

            $option_values = [];
            $select->filter( 'option' )->each( function ( ParserCrawler $option ) use ( &$option_values ) {
                if ( $option->attr( 'value' ) ) {
                    foreach ( self::NOT_VALID_OPTIONS as $not_valid_option ) {
                        if ( false !== stripos( $option->text(), $not_valid_option ) ) {
                            return;
                        }
                    }
                    $option_values[] = StringHelper::removeSpaces( $option->text() );
                }
            } );

            if ( !$option_values ) {
                return;
            }

            $options[ $name ] = $option_values;
        } );
    }

    private function parseDims( string $description ): array
    {
        $dims = [
            'x' => null,
            'y' => null,
            'z' => null,
        ];

        if ( preg_match( '/<br>Approx Dimensions:(.*?)<br><br>/ui', $description, $matches ) && isset( $matches[ 1 ] ) ) {
            $dims_values = array_filter( explode( '<br>', $matches[ 1 ] ) );
            foreach ( $dims_values as $text ) {
                [ $key, $value ] = explode( ':', $text, 2 );

                switch ( $key ) {
                    case 'Height':
                        $dims[ 'y' ] = StringHelper::getFloat( $value );
                        break;
                    case 'Width':
                        $dims[ 'x' ] = StringHelper::getFloat( $value );
                        break;
                    case 'Depth':
                        $dims[ 'z' ] = StringHelper::getFloat( $value );
                        break;
                }
            }
            return $dims;
        }

        if ( preg_match( '/<br><br>Approx. Dimensions:.*?<br><br>/ui', $description, $matches ) && isset( $matches[ 0 ] ) ) {
            $dims = FeedHelper::getDimsInString( $matches[ 0 ], 'x' );
        }

        return $dims;
    }

    public function beforeParse(): void
    {
        $this->name = $this->getText( '#product-detail-div h1' );
        $this->price = $this->parsePrice();

        [ $short_description, $attributes ] = $this->parseAttributesAndShorts();

        if ( $this->exists( '.prod-detail-desc' ) ) {
            $description = $this->getHtml( '.prod-detail-desc' );
            $this->dims = $this->parseDims( $description );

            $description = (string)preg_replace( self::NOT_VALID_PARTS_OF_DESC_REGEXES, '', $description );
            $additional_info = FeedHelper::getShortsAndAttributesInDescription( $description, self::FEATURES_REGEXES, $short_description, $attributes );
            $short_description = $additional_info[ 'short_description' ];
            $attributes = $additional_info[ 'attributes' ];

            $this->description = $additional_info[ 'description' ];
        }

        $this->short_description = $short_description;
        $this->attributes = $this->validatedAttributes( $attributes );
    }


    public function getProduct(): string
    {
        return $this->name;
    }

    public function getMpn(): string
    {
        return $this->getText( 'span.prod-detail-part-value' );
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getShortDescription(): array
    {
        return $this->short_description;
    }

    public function getImages(): array
    {
        return array_values( array_unique( array_map( static fn( $image ) => 'https://www.miamiwholesalesunglasses.com' . $image,
            $this->getAttrs( '.prod-detail-lt a', 'href' )
        ) ) );
    }

    public function getAttributes(): ?array
    {
        return !empty( $this->attributes ) ? $this->attributes : null;
    }

    public function getCostToUs(): float
    {
        return $this->price;
    }

    public function getAvail(): ?int
    {
        return $this->getText( '.prod-detail-stock' ) === 'In Stock' ? self::DEFAULT_AVAIL_NUMBER : 0;
    }

    public function getCategories(): array
    {
        return array_values( array_slice( $this->getContent( '.breadcrumb a' ), 2, -1 ) );
    }

    public function getDimX(): ?float
    {
        return $this->dims[ 'x' ] ?? null;
    }

    public function getDimY(): ?float
    {
        return $this->dims[ 'y' ] ?? null;
    }

    public function getDimZ(): ?float
    {
        return $this->dims[ 'z' ] ?? null;
    }

    public function getOptions(): array
    {
        $options = [];

        if ( $this->exists( '#ctl00_pageContent_ppQuestions_questions' ) ) {
            $this->parseOptionBySelectors( $options, '#ctl00_pageContent_ppQuestions_questions select', '.personalization-question-label' );
        }

        if ( $this->exists( '.prod-detail-rt .variationDropdownPanel' ) ) {
            $this->parseOptionBySelectors( $options, '.prod-detail-rt .variationDropdownPanel select', 'span.label' );
        }

        return $options;
    }
}

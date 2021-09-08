<?php

namespace App\Feeds\Vendors\WTL;

use App\Feeds\Parser\HtmlParser;
use App\Feeds\Utils\ParserCrawler;
use App\Helpers\FeedHelper;
use App\Helpers\StringHelper;

class Parser extends HtmlParser
{
    public const NOT_VALID_PARTS_OF_DESC = [
        'Features',
        'WARNING',
        'www.P65Warnings.ca.gov',
        'Item Dimensions:',
        'Carton Dimensions:',
        'Assembled Dimensions:',
    ];
    public const DIMS_REGEX = '/(\d+[\.]?\d*)[\',",″][a-z, A-Z]{1,1}/u';
    public const WEIGHT_REGEX = '/(\d+[.]?\d*)[\s]?lbs|lb/u';

    private array $product_info;

    private function dimsFromString(string $text): array
    {
        if (preg_match(self::WEIGHT_REGEX, $text, $matches) && isset($matches[1])) {
            $weight = StringHelper::getFloat($matches[1]);
        }
        $dims = FeedHelper::getDimsRegexp($text, [self::DIMS_REGEX]);

        return [
            'dims' => $dims,
            'weight' => $weight ?? null,
        ];
    }

    public function beforeParse(): void
    {
        if ($this->exists('.views-field-field-description')) {
            $short_info = FeedHelper::getShortsAndAttributesInList($this->getHtml('.views-field-field-description'));

            $this->product_info['attributes'] = $short_info['attributes'];
            $this->product_info['short_description'] = $short_info['short_description'];
            $this->product_info['description'] = '';

            $this->filter('#tab-description p')->each(function (ParserCrawler $c) {
                $description = $c->text();
                if ($description) {
                    $not_valid = false;
                    foreach (self::NOT_VALID_PARTS_OF_DESC as $text) {
                        if (false !== stripos($description, $text)) {
                            $not_valid = true;
                        }
                    }

                    if (false !== stripos($description, 'Carton Dimensions:')) {
                        $dims = $this->dimsFromString($description);
                        $this->product_info['shipping_weight'] = $dims['weight'];
                        $this->product_info['shipping_dims'] = $dims['dims'];
                    } else if (false !== stripos($description, 'item dimensions')) {
                        $dims = $this->dimsFromString($description);
                        $this->product_info['weight'] = $dims['weight'];
                        $this->product_info['dims'] = $dims['dims'];
                    } else if (false !== stripos($description, 'table')) {
                        [$key, $value] = explode(':', $description, 2);
                        $this->product_info['attributes'][trim($key)] = trim(StringHelper::normalizeSpaceInString($value));
                    }

                    if ($not_valid === false) {
                        $this->product_info['description'] .= '<p>' . $description . '</p>';
                    }
                }
            });

            $this->filter('.views-field-field-description iframe')->each(function (ParserCrawler $iframe) {
                $this->product_info['videos'][] = [
                    'name' => $this->getProduct(),
                    'provider' => 'youtube',
                    'video' => $iframe->attr('src'),
                ];
            });
        }
    }

    public function getProduct(): string
    {
        return $this->getText('h1.page-title');
    }

    public function getMpn(): string
    {
        return $this->getText('.views-field-field-itemno .field-content');
    }

    public function getShortDescription(): array
    {
        return $this->product_info['short_description'] ?? [];
    }

    public function getDescription(): string
    {
        return $this->product_info['description'] ?? '';
    }

    public function getImages(): array
    {
        return $this->getSrcImages('.gallery-slide');
    }

    public function getVideos(): array
    {
        return $this->product_info['videos'] ?? [];
    }

    public function getAttributes(): ?array
    {
        return $this->product_info['attributes'] ?? null;
    }

    public function getCostToUs(): float
    {
        return StringHelper::getMoney($this->product_info['price'] ?? 0);
    }

    public function getAvail(): ?int
    {
        return self::DEFAULT_AVAIL_NUMBER;
    }

    public function getCategories(): array
    {
        return array_values(array_slice($this->getContent('.breadcrumbs a'), 2, -1));
    }

    public function getDimX(): ?float
    {
        return $this->product_info['dims']['x'] ?? null;
    }

    public function getDimY(): ?float
    {
        return $this->product_info['dims']['y'] ?? null;
    }

    public function getDimZ(): ?float
    {
        return $this->product_info['dims']['z'] ?? null;
    }

    public function getWeight(): ?float
    {
        return $this->product_info['weight'] ?? null;
    }

    public function getShippingDimX(): ?float
    {
        return $this->product_info['shipping_dims']['x'] ?? null;
    }

    public function getShippingDimY(): ?float
    {
        return $this->product_info['shipping_dims']['y'] ?? null;
    }

    public function getShippingDimZ(): ?float
    {
        return $this->product_info['shipping_dims']['z'] ?? null;
    }

    public function getShippingWeight(): ?float
    {
        return $this->product_info['shipping_weight'] ?? null;
    }
}

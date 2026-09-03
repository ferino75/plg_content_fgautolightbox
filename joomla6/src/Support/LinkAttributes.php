<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Content.fgautolightbox
 * @copyright   Copyright (C) 2026 FG. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace FG\Plugin\Content\Fgautolightbox\Support;

\defined('_JEXEC') or die;

/**
 * Zostavuje odvodené atribúty lightbox odkazu - viditeľný popisok (title) a
 * výslednú CSS triedu.
 */
final class LinkAttributes
{
    public function buildTitle(string $srcVal, string $altValue, CaptionMode $mode): string
    {
        return match ($mode) {
            CaptionMode::Alt => $altValue,
            CaptionMode::Filename => $this->filenameFromSrc($srcVal),
            CaptionMode::None => '',
        };
    }

    /**
     * Pevná funkčná trieda "alb-link" plus voliteľná trieda z nastavenia
     * link_class (bez duplicity, ak by admin nastavil rovnakú hodnotu).
     */
    public function buildLinkClass(string $linkClass): string
    {
        return match (true) {
            $linkClass === '', $linkClass === 'alb-link' => 'alb-link',
            default => trim('alb-link ' . $linkClass),
        };
    }

    private function filenameFromSrc(string $srcVal): string
    {
        $filename = basename($srcVal);
        $filename = urldecode($filename);
        $filename = preg_replace('/\.[^.]+$/', '', $filename) ?? $filename;

        return str_replace(['_', '-'], ' ', $filename);
    }
}

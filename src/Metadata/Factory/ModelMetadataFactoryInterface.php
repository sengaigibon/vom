<?php

/*
 * This file is part of the VOM package.
 *
 * (c) Andreas Linden <zlx@gmx.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zolex\VOM\Metadata\Factory;

use Zolex\VOM\Metadata\ModelMetadata;

interface ModelMetadataFactoryInterface
{
    public function getMetadataFor(string $class): ?ModelMetadata;
}

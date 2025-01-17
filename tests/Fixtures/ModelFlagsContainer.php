<?php

/*
 * This file is part of the VOM package.
 *
 * (c) Andreas Linden <zlx@gmx.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Zolex\VOM\Test\Fixtures;

use Zolex\VOM\Mapping as VOM;

#[VOM\Model]
class ModelFlagsContainer
{
    #[VOM\Property]
    public ModelFlag $flagA;

    #[VOM\Property]
    public ModelFlag $flagB;

    #[VOM\Property]
    public ?ModelFlag $flagC = null;

    #[VOM\Property]
    public ModelFlag $flagD;
}

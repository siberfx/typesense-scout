<?php

namespace Siberfx\Typesense;

use Illuminate\Support\Facades\Facade;

/**
 * Class TypesenseFacade.
 *
 * @date    4/5/20
 *
 * @author  Selim Görmüş <info@siberfx.com>
 */
class TypesenseFacade extends Facade
{
    /**
     * @return string
     */
    public static function getFacadeAccessor(): string
    {
        return 'typesense';
    }
}

<?php

namespace App\Filament\Cabinet\Widgets;

use Filament\Widgets\Widget;

/** «Новое в кабинете» — закрывается крестиком, запоминается в браузере. */
class WhatsNew extends Widget
{
    protected static ?int $sort = -3;

    protected static bool $isLazy = false;

    protected string $view = 'filament.cabinet.whats-new';

    protected int|string|array $columnSpan = 'full';
}

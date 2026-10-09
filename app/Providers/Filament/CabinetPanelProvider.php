<?php

namespace App\Providers\Filament;

use App\Filament\Cabinet\Auth\EditProfile;
use Filament\Actions\Action;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/** Личный кабинет: регистрация открыта, каждый видит и правит только свои данные. */
class CabinetPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('cabinet')
            ->path('cabinet')
            ->login()
            ->registration()
            ->profile(EditProfile::class, isSimple: false)
            ->brandName('Success')
            ->brandLogo(fn () => view('filament.brand'))
            ->brandLogoHeight('2.25rem')
            ->favicon(asset('img/favicon.png'))
            ->colors([
                'primary' => Color::Green,
                'gray' => Color::Zinc,
            ])
            ->darkMode(true)
            ->discoverResources(in: app_path('Filament/Cabinet/Resources'), for: 'App\Filament\Cabinet\Resources')
            ->discoverWidgets(in: app_path('Filament/Cabinet/Widgets'), for: 'App\Filament\Cabinet\Widgets')
            ->pages([Dashboard::class])
            ->userMenuItems([
                Action::make('public')
                    ->label('Моя публичная страница')
                    ->url(fn () => route('profile.show', auth()->user()))
                    ->icon(Heroicon::OutlinedGlobeAlt),
                Action::make('admin')
                    ->label('Админка сайта')
                    ->url('/admin')
                    ->icon(Heroicon::OutlinedWrenchScrewdriver)
                    ->visible(fn () => auth()->user()?->is_admin),
            ])
            ->navigationItems([
                NavigationItem::make('Моя страница')
                    ->url(fn () => route('profile.show', auth()->user()), shouldOpenInNewTab: true)
                    ->icon(Heroicon::OutlinedGlobeAlt)
                    ->sort(100),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}

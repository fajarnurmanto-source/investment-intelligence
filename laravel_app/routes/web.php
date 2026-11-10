<?php

use App\Http\Controllers\CompanyResearchController;
use App\Http\Controllers\FundamentalHubController;
use App\Http\Controllers\FundamentalScreenerController;
use App\Http\Controllers\MacroDashboardController;
use App\Http\Controllers\PortfolioSandboxController;
use App\Http\Controllers\ProductDashboardController;
use App\Http\Controllers\ResearchCaseController;
use App\Http\Controllers\ResearchLoginController;
use App\Http\Controllers\ResearchWorkspaceController;
use App\Http\Controllers\RiskBudgetController;
use App\Http\Controllers\RollingValidationController;
use App\Http\Controllers\SignalCenterController;
use App\Http\Controllers\StrategyLabController;
use App\Http\Controllers\StrategyValidationController;
use App\Http\Middleware\EnsureResearchRole;
use Illuminate\Support\Facades\Route;

// Local, synthetic-only application. Authentication does not grant trading authority.
Route::redirect('/', '/ii/dashboard');

Route::prefix('ii')->name('ii.')->group(function (): void {
    Route::middleware('guest')->group(function (): void {
        Route::get('/login', [ResearchLoginController::class, 'show'])->name('login');
        Route::post('/login', [ResearchLoginController::class, 'login'])
            ->middleware('throttle:5,1')->name('login.submit');
    });

    Route::post('/logout', [ResearchLoginController::class, 'logout'])
        ->middleware('auth')->name('logout');

    Route::middleware(['auth', EnsureResearchRole::class])->group(function (): void {

        Route::get('/dashboard', [ProductDashboardController::class, 'index'])->name('dashboard');
        Route::get('/signals', [SignalCenterController::class, 'index'])->name('signals.index');
        Route::post('/signals/import', [SignalCenterController::class, 'import'])
            ->middleware('throttle:8,1')->name('signals.import');
        Route::get('/signals/example', [SignalCenterController::class, 'example'])->name('signals.example');
        Route::get('/fundamentals', [FundamentalHubController::class, 'index'])->name('fundamentals.index');
        Route::post('/fundamentals/import', [FundamentalHubController::class, 'import'])
            ->middleware('throttle:6,1')->name('fundamentals.import');
        Route::get('/fundamentals/example', [FundamentalHubController::class, 'example'])->name('fundamentals.example');
        Route::get('/fundamentals/{ticker}/export.csv', [FundamentalHubController::class, 'export'])->name('fundamentals.export');
        Route::get('/screener', [FundamentalScreenerController::class, 'index'])->name('screener.index');
        Route::get('/screener/export.csv', [FundamentalScreenerController::class, 'export'])->name('screener.export');
        Route::get('/strategy', [StrategyLabController::class, 'index'])->name('strategy.index');
        Route::get('/strategy/validate', [StrategyValidationController::class, 'index'])->name('strategy.validate');
        Route::get('/strategy/rolling', [RollingValidationController::class, 'index'])->name('strategy.rolling');
        Route::post('/strategy/rolling/load-demo', [RollingValidationController::class, 'loadDemo'])
            ->middleware('throttle:3,1')->name('strategy.rolling.load-demo');
        Route::get('/risk-budget', [RiskBudgetController::class, 'index'])->name('risk-budget.index');
        Route::get('/portfolio', [PortfolioSandboxController::class, 'index'])->name('portfolio.index');
        Route::get('/macro', [MacroDashboardController::class, 'index'])->name('macro.index');
        Route::get('/macro/report.csv', [MacroDashboardController::class, 'report'])->name('macro.report');

        Route::get('/company', [CompanyResearchController::class, 'index'])->name('company');
        Route::post('/scenario', [CompanyResearchController::class, 'scenario'])->name('scenario');

        Route::get('/workspace', [ResearchWorkspaceController::class, 'index'])->name('workspace');
        Route::post('/workspace/note', [ResearchWorkspaceController::class, 'note'])->name('workspace.note');
        Route::get('/workspace/report', [ResearchWorkspaceController::class, 'report'])->name('workspace.report');

        Route::get('/cases', [ResearchCaseController::class, 'index'])->name('cases.index');
        Route::post('/cases', [ResearchCaseController::class, 'store'])->name('cases.store');
        Route::get('/cases/{id}', [ResearchCaseController::class, 'show'])->name('cases.show');
        Route::post('/cases/{id}/note', [ResearchCaseController::class, 'note'])->name('cases.note');
        Route::get('/cases/{id}/report', [ResearchCaseController::class, 'report'])->name('cases.report');
    });
});

// Laravel's auth middleware expects a route named "login".
Route::get('/login', fn () => redirect()->route('ii.login'))->name('login');

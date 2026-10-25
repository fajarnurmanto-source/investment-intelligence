// VS5 local synthetic persistence; not production admission
\Illuminate\Support\Facades\Route::get('/ii/cases', [\App\Http\Controllers\ResearchCaseController::class, 'index']);
\Illuminate\Support\Facades\Route::post('/ii/cases', [\App\Http\Controllers\ResearchCaseController::class, 'store']);
\Illuminate\Support\Facades\Route::get('/ii/cases/{id}', [\App\Http\Controllers\ResearchCaseController::class, 'show']);
\Illuminate\Support\Facades\Route::post('/ii/cases/{id}/note', [\App\Http\Controllers\ResearchCaseController::class, 'note']);
\Illuminate\Support\Facades\Route::get('/ii/cases/{id}/report', [\App\Http\Controllers\ResearchCaseController::class, 'report']);
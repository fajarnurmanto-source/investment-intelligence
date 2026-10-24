// II VS3 synthetic-only Company Research bridge
\Illuminate\Support\Facades\Route::get('/ii/company', [\App\Http\Controllers\CompanyResearchController::class,'index']);
\Illuminate\Support\Facades\Route::post('/ii/scenario', [\App\Http\Controllers\CompanyResearchController::class,'scenario']);
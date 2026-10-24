// II VS4 synthetic research workspace; local demonstration only
\Illuminate\Support\Facades\Route::get('/ii/workspace', [\App\Http\Controllers\ResearchWorkspaceController::class, 'index']);
\Illuminate\Support\Facades\Route::post('/ii/workspace/note', [\App\Http\Controllers\ResearchWorkspaceController::class, 'note']);
\Illuminate\Support\Facades\Route::get('/ii/workspace/report', [\App\Http\Controllers\ResearchWorkspaceController::class, 'report']);
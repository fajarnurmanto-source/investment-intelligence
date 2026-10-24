# II VS3 â€” Laravel/FastAPI Company Research bridge

Synthetic-only local research. This is an overlay, not an independent Laravel deployment.

Requires PHP >=8.3, Composer, Laravel 13, and running VS2 FastAPI locally at http://127.0.0.1:8811.

To reproduce in a **new isolated location**: `composer create-project laravel/laravel laravel_app '^13.0'`;
copy controller into `app/Http/Controllers/`, copy Blade into `resources/views/ii/`, append the route fragment to `routes/web.php`, and place the service array entry before the final `];` in `config/services.php`.

Run `php artisan route:list --path=ii`. Launch Laravel on 127.0.0.1 only. Neither data admission nor investment/trading authority is granted. No user authentication or production UI is provided by this prototype. Filament is not yet installed.

Proof of E2E behavior on Windows recorded by operator: 10 Python regression tests PASS, FastAPI contract PASS, Laravel GET for DEMO2/DEMO3 PASS, scenario POST PASS; no database canonical touched.
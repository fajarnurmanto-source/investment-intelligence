<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ResearchLoginController extends Controller
{
    public function show(): \Illuminate\Contracts\View\View { return view('ii.login'); }

    public function login(Request $request): \Illuminate\Http\RedirectResponse
    {
        $credentials = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (! Auth::attempt($credentials)) {
            throw ValidationException::withMessages(['email' => 'Email atau kata sandi tidak sesuai. Silakan coba kembali.']);
        }
        $request->session()->regenerate();
        if (! in_array((string) $request->user()->ii_research_role, ['analyst','reviewer','admin'], true)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            throw ValidationException::withMessages(['email' => 'Akun ini belum memiliki akses ke ruang penelitian.']);
        }
        return redirect()->intended(route('ii.dashboard'));
    }

    public function logout(Request $request): \Illuminate\Http\RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/ii/login');
    }
}

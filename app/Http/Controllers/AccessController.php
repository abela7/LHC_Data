<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\AccessMode;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AccessController extends Controller
{
    private const ADMIN_PASSWORD = 'admin303';

    public function choose(): View
    {
        return view('access.choose');
    }

    public function showAdminGate(): View
    {
        return view('access.admin');
    }

    public function enterDataEntry(): RedirectResponse
    {
        session(['access_mode' => AccessMode::DATA_ENTRY]);

        return redirect()
            ->route('data-entry.dashboard')
            ->with('status', 'Welcome — data entry mode.');
    }

    public function enterAdmin(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'string'],
        ]);

        if ($request->string('password')->toString() !== self::ADMIN_PASSWORD) {
            return back()
                ->withInput()
                ->withErrors(['password' => 'Incorrect password.']);
        }

        session(['access_mode' => AccessMode::ADMIN]);

        return redirect()
            ->route('dashboard')
            ->with('status', 'Admin mode unlocked.');
    }

    public function dataEntryDashboard(): View
    {
        return view('data-entry.dashboard');
    }

    public function switch(): RedirectResponse
    {
        session()->forget('access_mode');

        return redirect()
            ->route('access.choose')
            ->with('status', 'Choose how you want to use the app.');
    }
}

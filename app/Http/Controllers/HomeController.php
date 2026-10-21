<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function index(): View
    {
        return view('home');
    }

    public function generate(Request $request): Response
    {
        $request->validate([
            'business_type' => ['required', 'string', 'max:255'],
        ]);

        return response('Generated successfully', 200)
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}

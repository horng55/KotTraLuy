<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class PdfExportController
{
    public function store(Request $request)
    {
        abort_unless($request->user()->business_id && $request->user()->status === 'active', 403);
        $request->validate([
            'file' => 'required|file|max:8192',
            'filename' => ['required', 'regex:/\AKotTraLuy_[A-Za-z0-9_-]+\.pdf\z/', 'max:180'],
        ]);
        $contents = file_get_contents($request->file('file')->getRealPath());
        abort_unless(str_starts_with($contents, '%PDF-'), 422);

        // Telegram's downloader cannot send our session's Authorization header.
        // This random capability expires after five minutes; no login token is exposed.
        $token = Str::random(64);
        Cache::put('pdf-export:'.$token, [
            'contents' => base64_encode($contents),
            'filename' => $request->input('filename'),
            'user_id' => $request->user()->id,
            'business_id' => $request->user()->business_id,
        ], now()->addMinutes(5));

        return response()->json(['url' => route('reports.pdf.download', ['token' => $token], false)], 201);
    }

    public function download(string $token)
    {
        $export = Cache::get('pdf-export:'.$token);
        abort_unless($export, 404);
        $user = User::find($export['user_id']);
        abort_unless($user && $user->status === 'active' && !$user->must_change_password
            && (int) $user->business_id === (int) $export['business_id'], 403);

        return response(base64_decode($export['contents']), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$export['filename'].'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }
}

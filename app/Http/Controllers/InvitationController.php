<?php

namespace App\Http\Controllers;

use App\Services\InvitationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class InvitationController extends Controller
{
    public function accept(Request $request, InvitationService $invitations): JsonResponse
    {
        $data = $request->validate([
            'token' => 'required|string|size:64',
            'name' => 'required|string|max:120',
            'password' => ['required', 'string', 'max:1024', Password::min(config('security.password_min_length'))->letters()->numbers()],
        ]);

        $invitations->accept($data['token'], ['name' => $data['name'], 'password' => $data['password']]);

        return response()->json(['accepted' => true], 201);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Mail\ContactEnquiry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class ContactController extends Controller
{
    public function send(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'    => ['required', 'string', 'max:100'],
            'email'   => ['required', 'email', 'max:150'],
            'subject' => ['required', 'string', 'max:150'],
            'message' => ['required', 'string', 'max:2000'],
        ]);

        Mail::to('info@pdlafrica.com')->send(new ContactEnquiry(
            senderName:  $validated['name'],
            senderEmail: $validated['email'],
            subject:     $validated['subject'],
            body:        $validated['message'],
        ));

        return response()->json(['message' => 'Your message has been sent successfully.']);
    }
}

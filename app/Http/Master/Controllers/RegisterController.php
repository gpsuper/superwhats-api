<?php

namespace App\Http\Master\Controllers;

use App\Data\FileDTO;
use App\Data\RegisterInputData;
use App\UseCases\Register;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class RegisterController
{

    public function __construct(
        private Register $register,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $name = $request->attributes->get('name');
            $email = $request->attributes->get('email');
            $password = $request->attributes->get('password');
            $confirm_password = $request->attributes->get('confirm_password');
            $role = $request->attributes->get('role');
            $pictures = $request->file('pictures');
            $has_pictures = !!$pictures;
            $picture_list = [];

            if ($has_pictures) {
                foreach ($pictures as $picture) {
                    $picture_list[] = FileDTO::validateAndCreate([
                        'name' => $picture->getClientOriginalName(),
                        'path' => $picture->getPathname(),
                        'size' => $picture->getSize(),
                        'mime_type' => $picture->getMimeType(),
                    ])->toArray();
                }
            }

            $input = RegisterInputData::validateAndCreate([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'confirm_password' => $confirm_password,
                'role' => $role,
                'pictures' => $picture_list,
            ]);

            $output = $this->register->execute($input);

            return response()->json([
                'message' => 'User registered successfully',
            ], 200);
        } catch (\Exception $th) {
            return response()->json([
                'message' => $th->getMessage()
            ], $th->getCode() ?? 400);
        }
    }
}

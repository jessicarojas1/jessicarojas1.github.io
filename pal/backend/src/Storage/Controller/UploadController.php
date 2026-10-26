<?php

declare(strict_types=1);

namespace App\Storage\Controller;

use App\Identity\User\PalUser;
use App\Platform\Http\ApiProblem;
use App\Storage\UploadService;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Accepts a file into PAL (scanned and stored). Attaching it to a document is a separate,
 * authorized step; an unattached upload grants nothing.
 */
final class UploadController
{
    public function __construct(private readonly UploadService $uploads)
    {
    }

    #[Route('/api/uploads', name: 'api_upload', methods: ['POST'])]
    public function upload(#[CurrentUser] PalUser $user, Request $request): JsonResponse
    {
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            throw ApiProblem::validation(['file' => 'Choose a file to upload.']);
        }

        return new JsonResponse($this->uploads->accept($file, $user), 201);
    }
}

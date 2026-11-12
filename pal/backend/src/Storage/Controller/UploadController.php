<?php

declare(strict_types=1);

namespace App\Storage\Controller;

use App\Identity\User\PalUser;
use App\Platform\Http\ApiProblem;
use App\Storage\UploadService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Accepts a file into PAL (scanned and stored). Attaching it to a document is a separate,
 * authorized step; an unattached upload grants nothing.
 */
final class UploadController
{
    public function __construct(
        private readonly UploadService $uploads,
        #[Autowire(service: 'limiter.upload')]
        private readonly RateLimiterFactory $limiter,
    ) {
    }

    #[Route('/api/uploads', name: 'api_upload', methods: ['POST'])]
    public function upload(#[CurrentUser] PalUser $user, Request $request): JsonResponse
    {
        if (!$this->limiter->create($user->id)->consume()->isAccepted()) {
            throw new ApiProblem(429, 'too_many_uploads', 'Too many uploads. Wait a while and try again.');
        }
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            throw ApiProblem::validation(['file' => 'Choose a file to upload.']);
        }

        // purpose=image: an image for document content (allow-list, size limit, metadata stripped).
        $image = 'image' === $request->request->get('purpose');

        return new JsonResponse($image ? $this->uploads->acceptImage($file, $user) : $this->uploads->accept($file, $user), 201);
    }
}

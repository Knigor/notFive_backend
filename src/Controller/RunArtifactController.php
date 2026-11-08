<?php

declare(strict_types=1);

namespace App\Controller;

use App\Digest\DigestRepository;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class RunArtifactController
{
    private const FILES = ['digest.md', 'post.md', 'email.md', 'journal.csv'];

    public function __construct(private DigestRepository $repository)
    {
    }

    #[Route('/api/runs/{runId}/artifacts/{filename}', name: 'run_artifact_download', requirements: ['runId' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}', 'filename' => 'digest\.md|post\.md|email\.md|journal\.csv'], methods: ['GET'])]
    #[OA\Get(summary: 'Download one completed digest artifact', description: 'Requires administrator JWT. The file is available only when runStatus is completed and all four artifacts exist.', tags: ['Digest artifacts'], security: [['AdminBearer' => []]], parameters: [new OA\Parameter(name: 'runId', in: 'path', required: true, description: 'Run ID returned by startRun.', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'filename', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: self::FILES))], responses: [new OA\Response(response: 200, description: 'Markdown or CSV attachment; Content-Type is text/markdown or text/csv.', content: [new OA\MediaType(mediaType: 'text/markdown', schema: new OA\Schema(type: 'string')), new OA\MediaType(mediaType: 'text/csv', schema: new OA\Schema(type: 'string'))]), new OA\Response(response: 401, description: 'Administrator token required.'), new OA\Response(response: 403, description: 'Administrator role required.'), new OA\Response(response: 404, description: 'Run is incomplete or file is unavailable.')])]
    public function file(string $runId, string $filename): Response
    {
        $files = $this->readyFiles($runId);
        if (null === $files) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $type = 'journal.csv' === $filename ? 'text/csv; charset=utf-8' : 'text/markdown; charset=utf-8';

        return new Response($files[$filename], Response::HTTP_OK, [
            'Content-Type' => $type,
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    #[Route('/api/runs/{runId}/artifacts.zip', name: 'run_artifacts_download', requirements: ['runId' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}'], methods: ['GET'])]
    #[OA\Get(summary: 'Download all four completed digest artifacts as ZIP', description: 'Requires administrator JWT. The archive contains exactly digest.md, post.md, email.md and journal.csv from one completed run.', tags: ['Digest artifacts'], security: [['AdminBearer' => []]], parameters: [new OA\Parameter(name: 'runId', in: 'path', required: true, description: 'Run ID returned by startRun.', schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'ZIP attachment.', content: new OA\MediaType(mediaType: 'application/zip', schema: new OA\Schema(type: 'string', format: 'binary'))), new OA\Response(response: 401, description: 'Administrator token required.'), new OA\Response(response: 403, description: 'Administrator role required.'), new OA\Response(response: 404, description: 'Run is incomplete or files are unavailable.')])]
    public function archive(string $runId): Response
    {
        $files = $this->readyFiles($runId);
        if (null === $files) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $path = tempnam(sys_get_temp_dir(), 'notfive-digest-');
        if (false === $path) {
            throw new \RuntimeException('Cannot create digest archive.');
        }
        $archive = new \ZipArchive();
        if (true !== $archive->open($path, \ZipArchive::OVERWRITE)) {
            unlink($path);
            throw new \RuntimeException('Cannot open digest archive.');
        }
        foreach (self::FILES as $filename) {
            $archive->addFromString($filename, $files[$filename]);
        }
        $archive->close();

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set('Content-Disposition', 'attachment; filename="digest-'.$runId.'.zip"');
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->deleteFileAfterSend(true);

        return $response;
    }

    /** @return array<string, string>|null */
    private function readyFiles(string $runId): ?array
    {
        $run = $this->repository->run($runId);
        if (null === $run || 'completed' !== $run['status']) {
            return null;
        }
        $files = $this->repository->artifacts($runId);
        foreach (self::FILES as $filename) {
            if (!array_key_exists($filename, $files)) {
                return null;
            }
        }

        return $files;
    }
}

<?php

namespace App\Infrastructure\Services;

use App\Data\FileDTO;
use App\Infrastructure\Services\FileService;
use Aws\S3\S3Client;

class S3FileService implements FileService
{
    public function __construct(
        private readonly S3Client $s3Client
    ) {}

    public function get(FileDTO $file): string
    {
        $result = $this->s3Client->getObject([
            'Bucket' => $_ENV['AWS_BUCKET'],
            'Key'    => $_ENV['AWS_FILE_PATH'] . '/' . $file->name
        ]);

        $temp_path = tempnam(sys_get_temp_dir(), 'attach_');
        if ($temp_path === false) {
            throw new \RuntimeException('Failed to create temporary file.');
        }

        $stream = $result['Body'];
        file_put_contents($temp_path, $stream);

        return $temp_path;
    }

    public function store(string $filepath, FileDTO $file): void
    {
        $key = $file->name;

        if (!file_exists($filepath)) {
            throw new \RuntimeException("File not found: {$filepath}");
        }

        $this->s3Client->putObject([
            'Bucket'      => $_ENV['AWS_BUCKET'],
            'Key'         => $_ENV['AWS_ATTACHMENTS_PATH'] . '/' . $key,
            'SourceFile'  => $filepath,
            'ContentType' => $file->mime_type,
            'ACL'         => 'private',
        ]);
    }
}

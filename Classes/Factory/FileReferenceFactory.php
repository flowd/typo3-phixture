<?php

declare(strict_types=1);

namespace Flowd\Typo3Phixture\Factory;

use Flowd\Typo3Phixture\Persistence\Persister;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * A file reference (sys_file_reference).
 *
 * The file enters TYPO3 through the file abstraction layer (ResourceStorage::addFile(), which indexes it as
 * sys_file with metadata); the reference is written by DataHandler together with the record using it.
 */
abstract class FileReferenceFactory extends RecordFactory
{
    protected const string TABLE = 'sys_file_reference';

    private string $folder = 'phixture';

    /** @var array<string, mixed> */
    private array $fileMetadata = [];

    /**
     * Name of the file in the storage, without a path. The same name in the same folder is the same file:
     * all references to it share one sys_file record and its metadata. Within a test such a file cannot
     * get another content or other metadata values: that would silently change the other references.
     */
    abstract protected function fileName(): string;

    /**
     * Writes the content of the file to the given path.
     */
    abstract protected function writeFileTo(string $path): void;

    public function alternative(string $alternative): static
    {
        return $this->set('alternative', $alternative);
    }

    public function title(string $title): static
    {
        return $this->set('title', $title);
    }

    public function description(string $description): static
    {
        return $this->set('description', $description);
    }

    /**
     * Metadata of the file itself (sys_file_metadata: "title", "alternative", "description"), which a
     * reference without own values falls back to. Written through DataHandler. It belongs to the file:
     * every reference to a file of this name shows it.
     *
     * @param array<string, mixed> $values
     */
    public function fileMetadata(array $values): static
    {
        $this->fileMetadata = [...$this->fileMetadata, ...$values];

        return $this;
    }

    /**
     * The folder of the default storage the file is added to, "" for its root. The place of a file can show
     * in the page: a download link built from the identifier of the file contains it.
     */
    public function inFolder(string $folder): static
    {
        $this->folder = trim($folder, '/');

        return $this;
    }

    #[\Override]
    public function beforePersist(): void
    {
        $this->set('uid_local', $this->importFile());
    }

    /**
     * A file name is a name, not a path: it becomes part of the path of a temporary file.
     */
    final protected static function assertIsFileName(string $fileName): void
    {
        if (in_array($fileName, ['', '.', '..'], true) || str_contains($fileName, '/') || str_contains($fileName, '\\')) {
            throw new \InvalidArgumentException(
                sprintf('"%s" is no file name: it must not be empty or contain a path.', $fileName),
                1795600002,
            );
        }
    }

    /**
     * @return int uid of the sys_file record
     */
    private function importFile(): int
    {
        $fileName = $this->fileName();
        self::assertIsFileName($fileName);

        $storage = GeneralUtility::makeInstance(StorageRepository::class)->getDefaultStorage()
            ?? throw new \RuntimeException('The test instance has no default file storage.', 1763560501);
        $folder = match (true) {
            $this->folder === '' => $storage->getRootLevelFolder(),
            $storage->hasFolder($this->folder) => $storage->getFolder($this->folder),
            default => $storage->createFolder($this->folder),
        };

        // addFile() moves the file into the storage: always hand it a temporary one
        $temporaryFile = Environment::getVarPath() . '/transient/' . bin2hex(random_bytes(8)) . '-' . $fileName;
        GeneralUtility::mkdir_deep(dirname($temporaryFile));
        try {
            $this->writeFileTo($temporaryFile);

            // The database is emptied for every test, the files of the instance are not. So only a file
            // the database knows was added by this test.
            $identifier = $folder->getIdentifier() . $storage->sanitizeFileName($fileName, $folder);
            $knownFile = $this->indexedFile($storage->getUid(), $identifier);
            if ($knownFile === null) {
                // replace, don't rename: the file can be left from an earlier test
                $fileUid = $storage->addFile($temporaryFile, $folder, $fileName, DuplicationBehavior::REPLACE)->getUid();
            } elseif ($knownFile['sha1'] !== sha1_file($temporaryFile)) {
                throw new \LogicException(
                    sprintf('File "%s" is already used in this test with another content. Give one of the two another name.', $identifier),
                    1795600003,
                );
            } else {
                // The same file again. It is not added once more: TYPO3 would index it again, which
                // resets the metadata another reference gave it.
                $fileUid = $knownFile['uid'];
            }
        } finally {
            if (is_file($temporaryFile)) {
                unlink($temporaryFile);
            }
        }

        if ($this->fileMetadata !== []) {
            $metadata = $this->metadataOf($fileUid)
                ?? throw new \RuntimeException(sprintf('File "%s" has no metadata record.', $fileName), 1763900201);
            if ($knownFile !== null) {
                $this->assertMetadataIsNotChanged($identifier, $metadata);
            }
            (new Persister())->updateRecord('sys_file_metadata', (int)$metadata['uid'], $this->fileMetadata);
        }

        return $fileUid;
    }

    /**
     * @return array{uid: int, sha1: string}|null null for a file the database does not know
     */
    private function indexedFile(int $storageUid, string $identifier): ?array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('sys_file');
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('uid', 'sha1')
            ->from('sys_file')
            ->where(
                $queryBuilder->expr()->eq('storage', $queryBuilder->createNamedParameter($storageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('identifier', $queryBuilder->createNamedParameter($identifier)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : ['uid' => (int)$row['uid'], 'sha1' => (string)$row['sha1']];
    }

    /**
     * The metadata record of the file as it is stored now. Read from the database: the file object keeps
     * the values it loaded first, also after DataHandler changed the record.
     *
     * @return array<string, mixed>|null
     */
    private function metadataOf(int $fileUid): ?array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('sys_file_metadata');
        $queryBuilder->getRestrictions()->removeAll();
        $row = $queryBuilder
            ->select('*')
            ->from('sys_file_metadata')
            ->where(
                $queryBuilder->expr()->eq('file', $queryBuilder->createNamedParameter($fileUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $row;
    }

    /**
     * Another reference of this test already gave the file a value: this one must not replace it.
     *
     * @param array<string, mixed> $stored
     */
    private function assertMetadataIsNotChanged(string $identifier, array $stored): void
    {
        foreach ($this->fileMetadata as $field => $value) {
            $storedValue = $stored[$field] ?? null;
            if (!is_scalar($value) || !is_scalar($storedValue) || (string)$storedValue === '' || (string)$storedValue === (string)$value) {
                continue;
            }
            throw new \LogicException(
                sprintf(
                    'File "%s" already has the metadata "%s" = "%s" in this test, it cannot get "%s": metadata belongs to the file, not to the reference. Give one of the two files another name.',
                    $identifier,
                    $field,
                    (string)$storedValue,
                    (string)$value,
                ),
                1795600004,
            );
        }
    }
}

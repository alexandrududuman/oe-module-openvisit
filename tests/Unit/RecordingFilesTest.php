<?php

declare(strict_types=1);

namespace OpenVisit\OpenEMR\Modules\OpenVisit\Tests\Unit;

use OpenVisit\OpenEMR\Modules\OpenVisit\Service\RecordingFiles;
use PHPUnit\Framework\TestCase;

final class RecordingFilesTest extends TestCase
{
    private string $root;
    private RecordingFiles $files;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/openvisit-files-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/inbox', 0777, true);
        $this->files = new RecordingFiles($this->root . '/inbox', $this->root . '/storage');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function jibriDrop(string $dir, string $room, ?string $ready = '62.4', bool $metadata = true): void
    {
        $path = $this->root . '/inbox/' . $dir;
        mkdir($path);
        file_put_contents("$path/{$room}_2026-11-09-10-00-00.mp4", str_repeat('x', 1000));
        if ($metadata) {
            file_put_contents("$path/metadata.json", json_encode(['meeting_url' => "https://meet.example.test/{$room}"]));
        }
        if ($ready !== null) {
            file_put_contents("$path/.ready", $ready);
        }
    }

    public function testClaimsOnlyFinishedRecordingsAndOnlyOnce(): void
    {
        $this->jibriDrop('a', 'ov-room1');
        $this->jibriDrop('b', 'ov-room2', null);

        $claimed = $this->files->claimReady();

        self::assertCount(1, $claimed);
        self::assertSame('ov-room1', $claimed[0]->room);
        self::assertSame(62, $claimed[0]->durationSeconds);
        self::assertStringEndsWith('.mp4', $claimed[0]->file);
        self::assertSame([], $this->files->claimReady());
    }

    public function testRoomFallsBackToTheFileNameWithoutJibriMetadata(): void
    {
        $this->jibriDrop('a', 'ov-room1', '', false);

        $claimed = $this->files->claimReady();

        self::assertSame('ov-room1', $claimed[0]->room);
        self::assertNull($claimed[0]->durationSeconds);
    }

    public function testStoreMovesTheFileOutOfTheInbox(): void
    {
        $this->jibriDrop('a', 'ov-room1');
        $claimed = $this->files->claimReady()[0];

        $size = $this->files->store($claimed, '7/3.mp4');

        self::assertSame(1000, $size);
        self::assertFileExists($this->root . '/storage/7/3.mp4');
        self::assertSame('600', decoct(fileperms($this->root . '/storage/7/3.mp4') & 0777));
        self::assertSame($this->root . '/storage/7/3.mp4', $this->files->absolute('7/3.mp4'));
        self::assertDirectoryDoesNotExist($this->root . '/inbox/a');
    }

    public function testAFolderJibriOwnsIsMarkedImportedInsteadOfRemoved(): void
    {
        $this->jibriDrop('a', 'ov-room1');
        $claimed = $this->files->claimReady()[0];
        chmod($this->root . '/inbox', 0555);

        $this->files->store($claimed, '7/3.mp4');
        chmod($this->root . '/inbox', 0755);

        self::assertFileExists($this->root . '/inbox/a/.imported');
        self::assertFileDoesNotExist($this->root . '/inbox/a/metadata.json');
        self::assertSame([], $this->files->claimReady());
    }

    public function testUnmatchedRecordingsAreSetAsideAndNotClaimedAgain(): void
    {
        $this->jibriDrop('a', 'someone-elses-room');
        $this->files->setAside($this->files->claimReady()[0]);

        self::assertSame([], $this->files->claimReady());
        self::assertFileExists($this->root . '/inbox/a/.unmatched');
    }

    public function testRelativePathsCannotLeaveTheStorageFolder(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->files->absolute('../etc/passwd');
    }

    public function testReportsWhetherTheFoldersAreUsable(): void
    {
        self::assertTrue($this->files->inboxUsable());
        self::assertTrue($this->files->storageUsable());
        self::assertFalse((new RecordingFiles($this->root . '/missing', $this->root . '/storage'))->inboxUsable());
    }
}

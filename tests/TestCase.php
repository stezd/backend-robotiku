<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // D1b — the suite must never reach real cloud storage.
        //
        // MediaStorage::resolveDiskForFile() probes the 's3' disk *by name* whenever the
        // default disk misses. That branch is the "files that predate the cloud migration"
        // fallback, and it ignores FILESYSTEM_DISK — so pinning `local` in phpunit.xml is
        // not sufficient: any missing file still triggers a live HeadObject against R2.
        //
        // Faking both disks here closes it at the one point every test passes through.
        // Verified by running the suite with AWS_ACCESS_KEY_ID set to garbage: the
        // failure count no longer changes, which is what "offline" actually means.
        Storage::fake('local');
        Storage::fake('s3');
    }
}

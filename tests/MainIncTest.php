<?php
use PHPUnit\Framework\TestCase;

class MainIncTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset query log and clean isolated cache dir before each test
        reset_captured_queries();
        find_duplicates_clear_cache(true);
    }

    protected function tearDown(): void
    {
        find_duplicates_clear_cache(true);
    }

    /**
     * Helper: build a deterministic PNG image whose center $squareSide x $squareSide
     * contains a rich non-uniform grayscale gradient/checker pattern, while any outer
     * landscape/portrait margins are filled with a contrasting solid brightness.
     */
    private function createTestImageWithSharedCenter(string $relPath, int $width, int $height, int $squareSide, int $marginGray = 250): string
    {
        $absPath = PHPWG_ROOT_PATH . ltrim($relPath, '/\\');
        $dir = dirname($absPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $im = imagecreatetruecolor($width, $height);
        $bg = imagecolorallocate($im, $marginGray, $marginGray, $marginGray);
        imagefilledrectangle($im, 0, 0, $width - 1, $height - 1, $bg);

        $offsetX = (int)(($width - $squareSide) / 2);
        $offsetY = (int)(($height - $squareSide) / 2);

        for ($y = 0; $y < $squareSide; $y++) {
            for ($x = 0; $x < $squareSide; $x++) {
                // Non-trivial asymmetric pattern so horizontal dHash has both '0' and '1' bits
                $val = (($x * 7 + $y * 13) + (($x % 31 < 15) ? 90 : 10)) % 256;
                $col = imagecolorallocate($im, $val, $val, $val);
                imagesetpixel($im, $offsetX + $x, $offsetY + $y, $col);
            }
        }

        imagepng($im, $absPath);
        if (is_resource($im)) {
            imagedestroy($im);
        } else {
            unset($im);
        }

        return $absPath;
    }

    public function testFindDuplicatesOnDeleteWithEmptyIds()
    {
        // Act
        find_duplicates_on_delete([]);

        // Assert
        $queries = get_captured_queries();
        $this->assertEmpty($queries, 'No queries should be executed when IDs array is empty.');
    }

    public function testFindDuplicatesOnDeleteWithValidIds()
    {
        // Arrange
        $ids = [1, 2, 5];

        // Act
        find_duplicates_on_delete($ids);

        // Assert
        $queries = get_captured_queries();
        $this->assertCount(1, $queries, 'Exactly one query should be executed.');

        $query = preg_replace('/\s+/', ' ', $queries[0]);
        $expectedPattern = '/DELETE FROM piwigo_find_duplicates_hashes WHERE image_id IN \(1,2,5\)/';
        $this->assertMatchesRegularExpression($expectedPattern, $query, 'The query should delete elements by their IDs.');
    }

    public function testFindDuplicatesOnDeleteWithMaliciousOrInvalidIds()
    {
        // Act with string containing SQL injection payload and invalid values
        find_duplicates_on_delete(['1 OR 1=1', 'abc', -10, 0, 7]);

        // Assert
        $queries = get_captured_queries();
        $this->assertCount(1, $queries, 'Query should be executed for sanitized positive integers.');
        $query = preg_replace('/\s+/', ' ', $queries[0]);
        $this->assertSame('DELETE FROM piwigo_find_duplicates_hashes WHERE image_id IN (1,7)', $query);
    }

    public function testFindDuplicatesOnDeleteWithAllInvalidIds()
    {
        find_duplicates_on_delete(['abc', 'xyz', -1, 0]);
        $this->assertEmpty(get_captured_queries(), 'No queries should be executed if no positive integers found.');
    }

    public function testFindDuplicatesOnDeleteInvalidatesJsonCache()
    {
        $cacheDir = find_duplicates_ensure_cache_dir();
        $cacheFile = $cacheDir . '/cache_v12_s100_a0_h10_mvisual_t15_deadbeef01234567.json';
        file_put_contents($cacheFile, '[{"PHASH":"abc"}]');
        $this->assertFileExists($cacheFile);

        find_duplicates_on_delete([10, 20]);

        $this->assertFileDoesNotExist($cacheFile, 'Deleting images must invalidate cached duplicate JSON files.');
        $this->assertFileExists($cacheDir . '/.htaccess');
        $this->assertFileExists($cacheDir . '/index.htm');
    }

    public function testFindDuplicatesSetGroupFavoriteMarkAll()
    {
        $updated = find_duplicates_set_group_favorite('10,20,10,invalid,-5', 1);

        $this->assertSame([10, 20], $updated);
        $queries = get_captured_queries();
        $this->assertCount(1, $queries);
        $this->assertSame(
            'INSERT INTO piwigo_find_duplicates_hashes (image_id, is_favorite) VALUES (10, 1), (20, 1) ON DUPLICATE KEY UPDATE is_favorite = 1',
            $queries[0]
        );
    }

    public function testFindDuplicatesSetGroupFavoriteUnmarkAll()
    {
        $updated = find_duplicates_set_group_favorite([101, 102], 0);

        $this->assertSame([101, 102], $updated);
        $queries = get_captured_queries();
        $this->assertCount(1, $queries);
        $this->assertSame(
            'INSERT INTO piwigo_find_duplicates_hashes (image_id, is_favorite) VALUES (101, 0), (102, 0) ON DUPLICATE KEY UPDATE is_favorite = 0',
            $queries[0]
        );
    }

    public function testFindDuplicatesSetGroupFavoriteWithEmptyInput()
    {
        $updated = find_duplicates_set_group_favorite('', 1);

        $this->assertEmpty($updated);
        $this->assertEmpty(get_captured_queries());
    }

    public function testEnsureFavoriteColumnAddsColumnWhenMissing()
    {
        global $mock_fetch_assoc_result;
        $mock_fetch_assoc_result = false;

        find_duplicates_ensure_favorite_column();

        $queries = get_captured_queries();
        $this->assertCount(2, $queries);
        $this->assertSame("SHOW COLUMNS FROM `piwigo_find_duplicates_hashes` LIKE 'is_favorite'", $queries[0]);
        $this->assertSame("ALTER TABLE `piwigo_find_duplicates_hashes` ADD COLUMN `is_favorite` tinyint(1) NOT NULL DEFAULT 0", $queries[1]);
    }

    public function testEnsureFavoriteColumnSkipsAlterWhenPresent()
    {
        global $mock_fetch_assoc_result;
        $mock_fetch_assoc_result = ['Field' => 'is_favorite'];

        find_duplicates_ensure_favorite_column();

        $queries = get_captured_queries();
        $this->assertCount(1, $queries);
        $this->assertSame("SHOW COLUMNS FROM `piwigo_find_duplicates_hashes` LIKE 'is_favorite'", $queries[0]);
    }

    public function testFindDuplicatesIsMotionPhoto()
    {
        // Positive matches (Pixel, Samsung, Google Camera)
        $this->assertSame(1, find_duplicates_is_motion_photo('PXL_20230501_123456.MP.jpg'));
        $this->assertSame(1, find_duplicates_is_motion_photo('PXL_20230501_123456.PORTRAIT.MP.jpg'));
        $this->assertSame(1, find_duplicates_is_motion_photo('PXL_20230501_123456.MP'));
        $this->assertSame(1, find_duplicates_is_motion_photo('MVIMG_20190101_120000.jpg'));
        $this->assertSame(1, find_duplicates_is_motion_photo('20230101_120000_MotionPhoto.jpg'));
        $this->assertSame(1, find_duplicates_is_motion_photo('MotionPhoto_20230101_120000.jpg'));
        $this->assertSame(1, find_duplicates_is_motion_photo('custom_name.jpg', 'PXL_20230501_123456.MP'));

        // Negative matches (Videos, Audio, regular photos)
        $this->assertSame(0, find_duplicates_is_motion_photo('video.mp4'));
        $this->assertSame(0, find_duplicates_is_motion_photo('sample.MP4'));
        $this->assertSame(0, find_duplicates_is_motion_photo('audio.mp3'));
        $this->assertSame(0, find_duplicates_is_motion_photo('camping.jpg'));
        $this->assertSame(0, find_duplicates_is_motion_photo('my_photo.png'));
        $this->assertSame(0, find_duplicates_is_motion_photo('PXL_20230501_123456.MP.mp4'));
    }

    public function testComputeDhashFormatAndCenterSquareParityAcrossAspectRatios()
    {
        $squarePath = $this->createTestImageWithSharedCenter('upload/dhash_square_120x120.png', 120, 120, 120, 128);
        $landscapePath = $this->createTestImageWithSharedCenter('upload/dhash_landscape_180x120.png', 180, 120, 120, 255);
        $portraitPath = $this->createTestImageWithSharedCenter('upload/dhash_portrait_120x180.png', 120, 180, 120, 0);

        $squareHash = find_duplicates_compute_dhash($squarePath);
        $landscapeHash = find_duplicates_compute_dhash($landscapePath);
        $portraitHash = find_duplicates_compute_dhash($portraitPath);

        $this->assertIsString($squareHash);
        $this->assertMatchesRegularExpression('/^[01]{64}$/', $squareHash, 'dHash must be a 64-character binary string.');
        $this->assertStringContainsString('0', $squareHash);
        $this->assertStringContainsString('1', $squareHash);

        $this->assertSame($squareHash, $landscapeHash, '180x120 landscape image sharing 120x120 center must match IMG_SQUARE 120x120 hash.');
        $this->assertSame($squareHash, $portraitHash, '120x180 portrait image sharing 120x120 center must match IMG_SQUARE 120x120 hash.');
        $this->assertNull(find_duplicates_compute_dhash(PHPWG_ROOT_PATH . 'upload/does_not_exist.png'));
    }

    public function testFindDuplicatesOnUploadPreservesFavoritesAndInvalidatesCache()
    {
        $relPath = 'upload/upload_hook_test.png';
        $absPath = $this->createTestImageWithSharedCenter($relPath, 120, 120, 120);
        $expectedHash = find_duplicates_compute_dhash($absPath);

        $cacheDir = find_duplicates_ensure_cache_dir();
        $cacheFile = $cacheDir . '/cache_v12_upload_stale.json';
        file_put_contents($cacheFile, '[]');
        $this->assertFileExists($cacheFile);

        find_duplicates_on_upload([
            'id' => 42,
            'path' => $relPath,
        ]);

        $queries = get_captured_queries();
        $this->assertCount(1, $queries);
        $this->assertStringNotContainsStringIgnoringCase('REPLACE INTO', $queries[0], 'Upload hook must never use REPLACE INTO (which wipes is_favorite).');
        $this->assertSame(
            "INSERT INTO piwigo_find_duplicates_hashes (image_id, phash) VALUES (42, '" . $expectedHash . "') ON DUPLICATE KEY UPDATE phash = VALUES(phash)",
            $queries[0]
        );
        $this->assertFileDoesNotExist($cacheFile, 'Upload hook must invalidate cached JSON files.');
    }

    public function testFindDuplicatesOnUploadStillInvalidatesCacheWhenHashUnavailable()
    {
        $cacheDir = find_duplicates_ensure_cache_dir();
        $cacheFile = $cacheDir . '/cache_v12_time_mode_stale.json';
        file_put_contents($cacheFile, '[]');

        find_duplicates_on_upload([
            'id' => 99,
            'path' => 'upload/nonexistent_photo.jpg',
        ]);

        $this->assertEmpty(get_captured_queries(), 'No hash insert should execute when source file is missing.');
        $this->assertFileDoesNotExist($cacheFile, 'Cache must still be cleared on upload for time-mode accuracy.');
    }

    public function testCacheDirectoryProtectionSecretAndCompleteRemoval()
    {
        global $conf;
        $previousSecret = $conf['secret_key'] ?? null;

        try {
            $cacheDir = find_duplicates_ensure_cache_dir();
            $this->assertDirectoryExists($cacheDir);
            $this->assertFileExists($cacheDir . '/index.htm');
            $this->assertFileExists($cacheDir . '/.htaccess');

            $htaccess = file_get_contents($cacheDir . '/.htaccess');
            $this->assertStringContainsString('Require all denied', $htaccess);
            $this->assertStringContainsString('Deny from all', $htaccess);

            $conf['secret_key'] = 'unit_test_secret_one';
            $secretOne = find_duplicates_get_cache_secret();
            $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $secretOne);
            $this->assertSame($secretOne, find_duplicates_get_cache_secret());

            $conf['secret_key'] = 'unit_test_secret_two';
            $secretTwo = find_duplicates_get_cache_secret();
            $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $secretTwo);
            $this->assertNotSame($secretOne, $secretTwo);

            $sampleJson = $cacheDir . '/cache_v12_' . $secretOne . '.json';
            file_put_contents($sampleJson, '[]');
            $this->assertFileExists($sampleJson);

            // Clearing without $remove_dir removes JSON but preserves protection files
            find_duplicates_clear_cache(false);
            $this->assertFileDoesNotExist($sampleJson);
            $this->assertDirectoryExists($cacheDir);
            $this->assertFileExists($cacheDir . '/.htaccess');
            $this->assertFileExists($cacheDir . '/index.htm');

            // Clearing with $remove_dir = true removes JSON, .htaccess, index.htm, and the directory itself
            file_put_contents($sampleJson, '[]');
            find_duplicates_clear_cache(true);
            $this->assertDirectoryDoesNotExist($cacheDir);
        } finally {
            if ($previousSecret === null) {
                unset($conf['secret_key']);
            } else {
                $conf['secret_key'] = $previousSecret;
            }
        }
    }

    public function testGroupByTimeInterleavedAlbumsWithSameAlbumEnabled()
    {
        // Interleaved chronological order: A1 (00s) -> B1 (05s) -> A2 (10s) -> B2 (14s)
        // With $same_album = 1 and $fd_time_interval = 15, B1 must NOT break [A1, A2] and A2 must NOT break [B1, B2].
        $imagesData = [
            101 => [
                'ID' => 101,
                'DATE_CREATION' => '2025-05-01 12:00:00',
                'ALBUMS' => [10 => 'Album A'],
            ],
            201 => [
                'ID' => 201,
                'DATE_CREATION' => '2025-05-01 12:00:05',
                'ALBUMS' => [20 => 'Album B'],
            ],
            102 => [
                'ID' => 102,
                'DATE_CREATION' => '2025-05-01 12:00:10',
                'ALBUMS' => [10 => 'Album A'],
            ],
            202 => [
                'ID' => 202,
                'DATE_CREATION' => '2025-05-01 12:00:14',
                'ALBUMS' => [20 => 'Album B'],
            ],
        ];

        $groups = find_duplicates_group_by_time($imagesData, 15, 1);
        $this->assertSame([[101, 102], [201, 202]], $groups);
    }

    public function testGroupByTimeMultiAlbumPhotosDoNotProduceDuplicateGroups()
    {
        // Both photos belong to Album A (10) AND Album B (20)
        $imagesData = [
            301 => [
                'ID' => 301,
                'DATE_CREATION' => '2025-05-01 13:00:00',
                'ALBUMS' => [10 => 'Album A', 20 => 'Album B'],
            ],
            302 => [
                'ID' => 302,
                'DATE_CREATION' => '2025-05-01 13:00:04',
                'ALBUMS' => [10 => 'Album A', 20 => 'Album B'],
            ],
        ];

        $groups = find_duplicates_group_by_time($imagesData, 15, 1);
        $this->assertCount(1, $groups, 'Identical group shared across multiple albums must be deduplicated.');
        $this->assertSame([[301, 302]], $groups);
    }

    public function testGroupByTimeIgnoresZeroEmptyAndInvalidDates()
    {
        $imagesData = [
            401 => [
                'ID' => 401,
                'DATE_CREATION' => '0000-00-00 00:00:00',
                'ALBUMS' => [10 => 'Album A'],
            ],
            402 => [
                'ID' => 402,
                'DATE_CREATION' => '0000-00-00 00:00:00',
                'ALBUMS' => [10 => 'Album A'],
            ],
            403 => [
                'ID' => 403,
                'DATE_CREATION' => '',
                'ALBUMS' => [10 => 'Album A'],
            ],
            404 => [
                'ID' => 404,
                'DATE_CREATION' => 'not-a-valid-date',
                'ALBUMS' => [10 => 'Album A'],
            ],
            405 => [
                'ID' => 405,
                'DATE_CREATION' => '2025-06-10 09:30:00',
                'ALBUMS' => [10 => 'Album A'],
            ],
            406 => [
                'ID' => 406,
                'DATE_CREATION' => '2025-06-10 09:30:08',
                'ALBUMS' => [10 => 'Album A'],
            ],
        ];

        $this->assertSame([[405, 406]], find_duplicates_group_by_time($imagesData, 15, 0));
        $this->assertSame([[405, 406]], find_duplicates_group_by_time($imagesData, 15, 1));
    }
}

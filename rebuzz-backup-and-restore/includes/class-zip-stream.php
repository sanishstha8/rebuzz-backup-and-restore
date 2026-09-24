<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Streaming ZIP writer, with Zip64 support.
 *
 * ZipArchive rebuilds the whole file on every close(), getting
 * slower as the backup grows. This class only ever appends bytes;
 * the central directory is built separately in a ".cdir" file and
 * merged in once, in finalize().
 *
 * Text-like entries (the SQL dump, PHP/JS/CSS...) are DEFLATEd as they
 * stream through; already-compressed media stays STORE, since
 * deflating it costs CPU for nothing. A shared-host restore needs room
 * for the ZIP and its extracted copy at once, so this matters.
 * Zip64 fields always written (small fixed overhead) rather than
 * conditionally past 4GB/65535 entries, to avoid boundary bugs.
 * ZipArchive/libzip (used to read archives back) supports Zip64 fine.
 */
// phpcs:disable WordPress.WP.AlternativeFunctions -- WP_Filesystem has no streaming API; archives are moved in chunks to stay inside memory limits.

class WPCB_Zip_Stream
{
    const SIG_LOCAL              = 0x04034b50;
    const SIG_CENTRAL            = 0x02014b50;
    const SIG_EOCD                = 0x06054b50;
    const SIG_DATA_DESCRIPTOR    = 0x08074b50;
    const SIG_ZIP64_EOCD          = 0x06064b50;
    const SIG_ZIP64_EOCD_LOCATOR = 0x07064b50;

    /** Zip64-required version marker (4.5, encoded as major*10+minor). */
    const VERSION_ZIP64 = 45;

    const METHOD_STORE = 0;
    const METHOD_DEFLATE = 8;

    /** Extensions worth compressing - text that typically shrinks 70-90%. */
    const DEFLATE_EXTENSIONS = [
        'sql', 'php', 'js', 'css', 'html', 'htm', 'txt', 'json', 'xml',
        'svg', 'md', 'po', 'pot', 'csv', 'map', 'ini', 'log'
    ];

    /** Above this, compress at level 1 so one big dump fits a step's time budget. */
    const FAST_DEFLATE_ABOVE_BYTES = 67108864;

    /** @var string */
    private $zipFile;

    /** @var string */
    private $cdirFile;

    public function __construct($zipFile, $cdirFile)
    {
        $this->zipFile  = $zipFile;
        $this->cdirFile = $cdirFile;
    }

    /**
     * Append one file to the archive.
     *
     * @param string $sourcePath Absolute path of file on disk.
     * @param string $entryName  Path to store inside the ZIP.
     * @param int    $offset     Current byte length of backup.zip (caller tracks across batches).
     *
     * @return array|false {written, cdir_written, sha256} or false on failure.
     *                      sha256 comes from the same read, not a second pass.
     */
    public function addFile($sourcePath, $entryName, $offset)
    {
        $in = @fopen($sourcePath, 'rb');

        if (!$in) {
            return false;
        }

        $out = @fopen($this->zipFile, 'ab');

        if (!$out) {
            fclose($in);
            return false;
        }

        list($dosTime, $dosDate) = $this->dosDateTime(
            filemtime($sourcePath) ?: time()
        );

        $name = str_replace('\\', '/', $entryName);

        $deflate = $this->deflateContext($name, $sourcePath);
        $method = $deflate !== null ? self::METHOD_DEFLATE : self::METHOD_STORE;

        // Bit 3 set: crc/size deferred to a data descriptor after the
        // read/write loop, enabling one streaming pass instead of
        // reading each file twice. Central-dir readers (incl.
        // ZipArchive) ignore the local header's crc/size anyway.
        // Sizes below are Zip64 sentinels w/ placeholder extra field,
        // since real sizes aren't known yet either way.
        $localHeader = pack(
            'VvvvvvVVVvv',
            self::SIG_LOCAL,
            self::VERSION_ZIP64,
            0x0008,         // general purpose flag: data descriptor follows
            $method,        // compression method
            $dosTime,
            $dosDate,
            0,              // crc-32 - deferred to data descriptor
            0xFFFFFFFF,     // compressed size - Zip64 sentinel
            0xFFFFFFFF,     // uncompressed size - Zip64 sentinel
            strlen($name),
            20              // extra field length (Zip64 extra, below)
        );

        $localExtra = pack(
            'vvPP',
            0x0001,         // Zip64 extended information extra field tag
            16,             // size of the two fields below
            0,              // uncompressed size placeholder - see descriptor
            0               // compressed size placeholder - see descriptor
        );

        // writeAll() catches short/failed writes (e.g. disk-full) that
        // would otherwise silently corrupt every entry appended after
        // it. Failing here lets the caller retry via truncateToOffset().
        if (
            !$this->writeAll($out, $localHeader) ||
            !$this->writeAll($out, $name) ||
            !$this->writeAll($out, $localExtra)
        ) {
            fclose($in);
            fclose($out);
            return false;
        }

        $written = strlen($localHeader) + strlen($name) + strlen($localExtra);

        // Single pass: hash (crc32 + sha256) the read bytes, write them
        // (compressed or not). Hashes are of the original bytes, so
        // checksums don't depend on the compression method.
        $crcCtx = hash_init('crc32b');
        $shaCtx = hash_init('sha256');
        $size = 0;
        $compressedSize = 0;
        $writeFailed = false;

        while (!feof($in)) {
            $chunk = fread($in, 1048576);
            if ($chunk === false) {
                break;
            }
            hash_update($crcCtx, $chunk);
            hash_update($shaCtx, $chunk);
            $size += strlen($chunk);

            $data = $deflate !== null ? deflate_add($deflate, $chunk, ZLIB_NO_FLUSH) : $chunk;

            if ($data === false || !$this->writeAll($out, $data)) {
                $writeFailed = true;
                break;
            }
            $compressedSize += strlen($data);
            $written += strlen($data);
        }

        if (!$writeFailed && $deflate !== null) {

            $data = deflate_add($deflate, '', ZLIB_FINISH);

            if ($data === false || !$this->writeAll($out, $data)) {
                $writeFailed = true;
            } else {
                $compressedSize += strlen($data);
                $written += strlen($data);
            }
        }

        fclose($in);

        if ($writeFailed) {
            fclose($out);
            return false;
        }

        $crc    = hexdec(hash_final($crcCtx));
        $sha256 = hash_final($shaCtx);

        // Real crc/sizes, now known. 8-byte Zip64 fields, not classic 4-byte.
        $descriptor = pack(
            'VVPP',
            self::SIG_DATA_DESCRIPTOR,
            $crc,
            $compressedSize,
            $size           // uncompressed size
        );

        if (!$this->writeAll($out, $descriptor)) {
            fclose($out);
            return false;
        }

        $written += strlen($descriptor);

        fclose($out);

        // Central directory record, append-only like backup.zip.
        // Holds the real crc/sizes (readers use these, not the local
        // header's deferred ones). Same Zip64 sentinel pattern; real
        // values, incl. offset, go in the extra field.
        $central = pack(
            'VvvvvvvVVVvvvvvVV',
            self::SIG_CENTRAL,
            self::VERSION_ZIP64,  // version made by
            self::VERSION_ZIP64,  // version needed to extract
            0x0008,                // general purpose flag (match local header)
            $method,               // compression method
            $dosTime,
            $dosDate,
            $crc,
            0xFFFFFFFF,            // compressed size - Zip64 sentinel
            0xFFFFFFFF,            // uncompressed size - Zip64 sentinel
            strlen($name),
            28,                    // extra field length (Zip64 extra, below)
            0,                     // file comment length
            0,                     // disk number start
            0,                     // internal file attributes
            0100644 << 16,         // external file attributes (regular file)
            0xFFFFFFFF             // relative offset of local header - Zip64 sentinel
        );

        $centralExtra = pack(
            'vvPPP',
            0x0001,         // Zip64 extended information extra field tag
            24,             // size of the three fields below
            $size,          // uncompressed size
            $compressedSize,
            $offset         // relative offset of local header
        );

        $cdirRecord = $central . $name . $centralExtra;

        $cdirOut = @fopen($this->cdirFile, 'ab');

        // A swallowed failure here would desync cdir_offset from the
        // real file and leave the EOCD entry count wrong, corrupting
        // the archive. Report failure so the caller can retry instead.
        if (!$cdirOut || !$this->writeAll($cdirOut, $cdirRecord)) {

            if ($cdirOut) {
                fclose($cdirOut);
            }

            return false;
        }

        fclose($cdirOut);

        return [
            'written'      => $written,
            'cdir_written' => strlen($cdirRecord),
            'sha256'       => $sha256
        ];
    }

    /**
     * Raw-deflate context for a text-like entry, or null to STORE it
     * (media, or no zlib on this server).
     *
     * @return \DeflateContext|resource|null
     */
    private function deflateContext($name, $sourcePath)
    {
        if (!function_exists('deflate_init')) {
            return null;
        }

        if (!in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::DEFLATE_EXTENSIONS, true)) {
            return null;
        }

        $size = @filesize($sourcePath);

        $context = @deflate_init(ZLIB_ENCODING_RAW, [
            'level' => ($size !== false && $size > self::FAST_DEFLATE_ABOVE_BYTES) ? 1 : 6
        ]);

        return $context === false ? null : $context;
    }

    /**
     * Wraps fwrite() to catch short writes (e.g. disk-full), which
     * plain fwrite() doesn't flag as an error.
     */
    private function writeAll($handle, $data)
    {
        $length = strlen($data);

        if ($length === 0) {
            return true;
        }

        $result = @fwrite($handle, $data);

        return ($result !== false && $result === $length);
    }

    /**
     * Merge central directory onto the ZIP, write Zip64 EOCD+Locator
     * then classic EOCD. Call once, after the last file is added.
     *
     * @param int $entryCount Total entries added so far.
     */
    public function finalize($entryCount)
    {
        if (!file_exists($this->zipFile) || !file_exists($this->cdirFile)) {
            return false;
        }

        $cdOffset = filesize($this->zipFile);

        $out = fopen($this->zipFile, 'ab');
        $in  = fopen($this->cdirFile, 'rb');

        if (!$out || !$in) {
            if ($out) {
                fclose($out);
            }
            if ($in) {
                fclose($in);
            }
            return false;
        }

        while (!feof($in)) {

            $chunk = fread($in, 1048576);

            // Genuine read failure (not just EOF) - stop rather than loop.
            if ($chunk === false || !$this->writeAll($out, $chunk)) {
                fclose($in);
                fclose($out);
                return false;
            }
        }

        fclose($in);

        $cdSize = filesize($this->cdirFile);

        // Zip64 EOCD: real 8-byte counts/size/offset for when classic
        // EOCD can't hold them (>65535 entries or >4GB). Always written.
        $zip64EocdOffset = $cdOffset + $cdSize;

        $zip64Eocd = pack(
            'VPvvVVPPPP',
            self::SIG_ZIP64_EOCD,
            44,                    // size of this record after this field (fixed portion)
            self::VERSION_ZIP64,   // version made by
            self::VERSION_ZIP64,   // version needed to extract
            0,                     // number of this disk
            0,                     // disk with the start of the central directory
            $entryCount,           // entries on this disk
            $entryCount,           // total entries
            $cdSize,
            $cdOffset
        );

        // These three writes are the last thing finalize() does, right
        // after streaming the whole (potentially huge) central
        // directory - a very plausible moment for disk space to run
        // out. Routed through writeAll() like every other write in
        // this class, so a short/failed write here is caught instead
        // of silently producing a truncated ZIP that still gets
        // reported as a completed backup.
        if (!$this->writeAll($out, $zip64Eocd)) {
            fclose($out);
            return false;
        }

        // Zip64 EOCD Locator: points to the record written above.
        $zip64Locator = pack(
            'VVPV',
            self::SIG_ZIP64_EOCD_LOCATOR,
            0,                  // disk with the start of the zip64 EOCD record
            $zip64EocdOffset,
            1                   // total number of disks
        );

        if (!$this->writeAll($out, $zip64Locator)) {
            fclose($out);
            return false;
        }

        // Classic EOCD, last. Counts/size/offset set to Zip64
        // sentinels; any Zip64-aware reader (incl. ZipArchive) reads
        // the real values from the record above instead.
        $eocd = pack(
            'VvvvvVVv',
            self::SIG_EOCD,
            0,              // number of this disk
            0,              // disk where central directory starts
            0xFFFF,         // entries on this disk - Zip64 sentinel
            0xFFFF,         // total entries - Zip64 sentinel
            0xFFFFFFFF,     // size of central directory - Zip64 sentinel
            0xFFFFFFFF,     // offset of start of central directory - Zip64 sentinel
            0               // comment length
        );

        if (!$this->writeAll($out, $eocd)) {
            fclose($out);
            return false;
        }

        fclose($out);

        return true;
    }

    private function dosDateTime($timestamp)
    {
        $d = getdate($timestamp);

        if ($d['year'] < 1980) {
            $d['year'] = 1980;
        }

        $dosTime = ($d['hours'] << 11) | ($d['minutes'] << 5) | (int) ($d['seconds'] / 2);
        $dosDate = (($d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday'];

        return [$dosTime, $dosDate];
    }
}

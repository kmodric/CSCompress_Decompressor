<?php
/**
 * Standalone SAP CSCompress decompressor (single-file PHP web app).
 *
 * What it does:
 * - Shows an upload form for a CSCompress file.
 * - Validates and decompresses the uploaded file.
 * - Returns the original file as a download (PDF if the content starts with %PDF-).
 *
 * No third-party libraries are required.
 * Requires PHP with the zlib extension enabled (for gzinflate()).
 */

declare(strict_types=1);

const CS_MAGIC = "\x1f\x9d";
const CS_HEAD_SIZE = 8;
const CS_ALGORITHM_LZH = 2;
const NONSENSE_LENBITS = 2;

class CsCompressException extends RuntimeException {}

function html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function parse_header(string $blob): array
{
    if (strlen($blob) < CS_HEAD_SIZE) {
        throw new CsCompressException('File too small to be a CSCompress blob.');
    }

    $origLen = unpack('V', substr($blob, 0, 4))[1];
    $veralg = ord($blob[4]);
    $version = ($veralg >> 4) & 0x0F;
    $algorithm = $veralg & 0x0F;
    $magic = substr($blob, 5, 2);
    $special = ord($blob[7]);

    if ($magic !== CS_MAGIC) {
        throw new CsCompressException(
            sprintf(
                'Invalid CSCompress magic: expected %s, got %s.',
                strtoupper(bin2hex(CS_MAGIC)),
                strtoupper(bin2hex($magic))
            )
        );
    }

    return [
        'orig_len' => $origLen,
        'version' => $version,
        'algorithm' => $algorithm,
        'special' => $special,
    ];
}

function shift_lsb_bitstream(string $data, int $skipBits): string
{
    if ($skipBits < 0) {
        throw new InvalidArgumentException('skipBits must be >= 0.');
    }

    $out = '';
    $acc = 0;
    $accBits = 0;
    $totalBits = strlen($data) * 8;

    for ($bitIndex = $skipBits; $bitIndex < $totalBits; $bitIndex++) {
        $byte = ord($data[intdiv($bitIndex, 8)]);
        $bit = ($byte >> ($bitIndex % 8)) & 1;
        $acc |= ($bit << $accBits);
        $accBits++;

        if ($accBits === 8) {
            $out .= chr($acc);
            $acc = 0;
            $accBits = 0;
        }
    }

    if ($accBits > 0) {
        $out .= chr($acc);
    }

    return $out;
}

function decompress_lzh_payload(string $payload): string
{
    if ($payload === '') {
        throw new CsCompressException('Empty payload.');
    }

    $x = ord($payload[0]) & ((1 << NONSENSE_LENBITS) - 1);
    $skipBits = NONSENSE_LENBITS + $x;
    $shifted = shift_lsb_bitstream($payload, $skipBits);

    if (!function_exists('gzinflate')) {
        throw new CsCompressException('PHP zlib extension is required (gzinflate() not available).');
    }

    set_error_handler(static function (): bool {
        return true;
    });

    try {
        $out = gzinflate($shifted);
    } finally {
        restore_error_handler();
    }

    if ($out === false) {
        throw new CsCompressException(
            sprintf('Raw DEFLATE decompression failed after stripping %d prefix bits.', $skipBits)
        );
    }

    return $out;
}

function decompress_cscompress(string $blob): array
{
    $header = parse_header($blob);
    $payload = substr($blob, CS_HEAD_SIZE);

    if ($header['algorithm'] !== CS_ALGORITHM_LZH) {
        throw new CsCompressException(
            'This standalone PHP script currently supports only algorithm 2 (LZH). '
            . 'Found algorithm ' . $header['algorithm'] . '.'
        );
    }

    $out = decompress_lzh_payload($payload);

    if (strlen($out) !== $header['orig_len']) {
        throw new CsCompressException(
            sprintf(
                'Length mismatch after decompression: expected %d, got %d.',
                $header['orig_len'],
                strlen($out)
            )
        );
    }

    return [$out, $header];
}

function default_output_name(string $inputName, string $data): string
{
    $base = pathinfo($inputName, PATHINFO_FILENAME);
    if ($base === '') {
        $base = 'decompressed';
    }

    if (strncmp($data, "%PDF-", 5) === 0) {
        return $base . '.pdf';
    }

    return $base . '.decompressed';
}

function send_download(string $filename, string $data): never
{
    $isPdf = strncmp($data, "%PDF-", 5) === 0;
    $contentType = $isPdf ? 'application/pdf' : 'application/octet-stream';

    header('Content-Description: File Transfer');
    header('Content-Type: ' . $contentType);
    header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
    header('Content-Length: ' . (string) strlen($data));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo $data;
    exit;
}

$error = null;
$info = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!isset($_FILES['cs_file'])) {
            throw new CsCompressException('No file field received.');
        }

        $upload = $_FILES['cs_file'];

        if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $code = is_array($upload) ? ($upload['error'] ?? UPLOAD_ERR_NO_FILE) : UPLOAD_ERR_NO_FILE;
            throw new CsCompressException('Upload failed with code ' . $code . '.');
        }

        $tmpName = (string) $upload['tmp_name'];
        $originalName = (string) ($upload['name'] ?? 'input.compressed');

        $blob = file_get_contents($tmpName);
        if ($blob === false) {
            throw new CsCompressException('Failed to read uploaded file.');
        }

        [$data, $header] = decompress_cscompress($blob);
        $downloadName = default_output_name($originalName, $data);
        send_download($downloadName, $data);
    } catch (Throwable $e) {
        $error = $e->getMessage();
        if (isset($blob) && is_string($blob) && strlen($blob) >= CS_HEAD_SIZE) {
            try {
                $info = parse_header($blob);
            } catch (Throwable $ignored) {
                $info = null;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="hr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CSCompress Decompressor</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 2rem;
            line-height: 1.5;
            color: #1f2937;
            background: #f9fafb;
        }
        .wrap {
            max-width: 760px;
            margin: 0 auto;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 4px 14px rgba(0,0,0,.06);
        }
        h1 { margin-top: 0; }
        .error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 16px;
        }
        .info {
            background: #eff6ff;
            color: #1e3a8a;
            border: 1px solid #bfdbfe;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 16px;
        }
        .field { margin-bottom: 16px; }
        .hint {
            color: #4b5563;
            font-size: 0.95rem;
        }
        button {
            background: #2563eb;
            color: #fff;
            border: 0;
            border-radius: 8px;
            padding: 10px 16px;
            cursor: pointer;
            font-size: 1rem;
        }
        button:hover { background: #1d4ed8; }
        code { background: #f3f4f6; padding: 2px 6px; border-radius: 4px; }
        ul { margin-bottom: 0; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>CSCompress Decompressor</h1>
    <p>Odaberi SAP Content Server <code>CSCompress</code> datoteku i skripta će vratiti dekomprimirani originalni file.</p>

    <?php if ($error !== null): ?>
        <div class="error"><strong>Greška:</strong> <?= html($error) ?></div>
    <?php endif; ?>

    <?php if (is_array($info)): ?>
        <div class="info">
            <strong>Header info:</strong>
            <ul>
                <li>Originalna duljina: <?= html((string) $info['orig_len']) ?></li>
                <li>Verzija: <?= html((string) $info['version']) ?></li>
                <li>Algoritam: <?= html((string) $info['algorithm']) ?></li>
                <li>Special: <?= html((string) $info['special']) ?></li>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <div class="field">
            <label for="cs_file"><strong>Compressed file</strong></label><br>
            <input type="file" id="cs_file" name="cs_file" required>
        </div>
        <div class="field hint">
            Skripta podržava CSCompress uzorak koji si poslao, s algoritmom <code>2 (LZH)</code>.
            Ako je sadržaj PDF, output će biti preuzet kao <code>.pdf</code>.
        </div>
        <button type="submit">Decompress &amp; Download</button>
    </form>
</div>
</body>
</html>

<?php
/**
 * Library Access Card JPEG generator.
 *
 * Produces a compact school-ID style JPEG using the single Jose Abad Santos
 * High School logo. The layout is:
 *   [School name] [Logo]
 *   [Student/Teacher name]
 *   [QR code]
 *   [ID / Student number]
 *   Library Access Card
 *
 * Colors are derived from the logo: yellow, black, white, and light gray.
 */

function libraryCardFontCandidates(bool $bold = false): array {
    return $bold ? [
        'C:/Windows/Fonts/arialbd.ttf',
        'C:/Windows/Fonts/ARIALBD.TTF',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/liberation2/LiberationSans-Bold.ttf',
    ] : [
        'C:/Windows/Fonts/arial.ttf',
        'C:/Windows/Fonts/ARIAL.TTF',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/truetype/liberation2/LiberationSans-Regular.ttf',
    ];
}

function libraryCardFindFont(bool $bold = false): ?string {
    foreach (libraryCardFontCandidates($bold) as $path) {
        if (is_file($path) && is_readable($path)) return $path;
    }
    return null;
}

function libraryCardSafeText(string $value): string {
    $value = preg_replace('/[\r\n]+/', ' ', $value);
    return trim((string)$value);
}

function libraryCardUpper(string $value): string {
    return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
}

function libraryCardLength(string $value): int {
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function libraryCardWrapName(string $name, int $maxChars = 28): array {
    $name = libraryCardSafeText($name);
    if ($name === '') return [''];
    if (libraryCardLength($name) <= $maxChars) return [$name];

    $words = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY);
    $lines = [''];
    foreach ($words as $word) {
        $candidate = trim($lines[count($lines)-1] . ' ' . $word);
        if ($lines[count($lines)-1] === '' || libraryCardLength($candidate) <= $maxChars) {
            $lines[count($lines)-1] = $candidate;
        } else {
            $lines[] = $word;
        }
    }
    return array_slice($lines, 0, 2);
}

function libraryCardTextWidth($font, float $size, string $text): float {
    $box = imagettfbbox($size, 0, $font, $text);
    return abs($box[2] - $box[0]);
}

function libraryCardDrawCenteredTtf($image, string $font, float $size, int $y, string $text, array $rgb): void {
    $w = libraryCardTextWidth($font, $size, $text);
    $x = (imagesx($image) - $w) / 2;
    imagettftext($image, $size, 0, (int)round($x), $y, imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]), $font, $text);
}

function libraryCardDrawTtf($image, string $font, float $size, int $x, int $y, string $text, array $rgb): void {
    imagettftext($image, $size, 0, $x, $y, imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]), $font, $text);
}

function libraryCardBuildWithGd(string $fullName, string $idNumber, string $qrCode, string $qrBytes, string $logoBytes): string {
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagecreatefromstring')) {
        throw new RuntimeException('PHP GD extension is required to generate Library Access Card JPEG files.');
    }

    $logo = @imagecreatefromstring($logoBytes);
    $qr = @imagecreatefromstring($qrBytes);
    if (!$logo || !$qr) throw new RuntimeException('The supplied logo or QR image is not a valid image.');

    // Keep the downloaded card at the same compact proportion as the reference design.
    $W = 590; $H = 372;
    $img = imagecreatetruecolor($W, $H);
    imagealphablending($img, true);
    imagesavealpha($img, false);

    $white = imagecolorallocate($img, 255, 255, 255);
    $black = imagecolorallocate($img, 20, 20, 20);
    $yellow = imagecolorallocate($img, 246, 214, 27);
    $yellowSoft = imagecolorallocate($img, 255, 242, 159);
    $gray = imagecolorallocate($img, 242, 243, 245);
    $grayText = imagecolorallocate($img, 108, 108, 108);
    $border = imagecolorallocate($img, 215, 216, 219);

    imagefilledrectangle($img, 0, 0, $W, $H, $white);
    imagefilledrectangle($img, 0, 0, $W, 46, $yellow);
    imagefilledrectangle($img, 0, 45, $W, 50, $black);
    // PHP 8.4+: imagefilledpolygon() no longer accepts the redundant point-count argument.
    imagefilledpolygon($img, [$W-115,$H, $W,$H-62, $W,$H], $yellowSoft);
    imagefilledpolygon($img, [$W-54,$H, $W,$H-30, $W,$H], $black);
    imagerectangle($img, 4, 4, $W-5, $H-5, $black);
    imagerectangle($img, 9, 9, $W-10, $H-10, $border);

    $fontRegular = libraryCardFindFont(false);
    $fontBold = libraryCardFindFont(true);

    // Logo upper-right. It is intentionally the only logo on the card.
    $logoBox = 72;
    $logoW = imagesx($logo); $logoH = imagesy($logo);
    $scale = min($logoBox / max(1,$logoW), $logoBox / max(1,$logoH));
    $dstW = max(1, (int)round($logoW * $scale));
    $dstH = max(1, (int)round($logoH * $scale));
    imagecopyresampled($img, $logo, 497, 14, 0, 0, $dstW, $dstH, $logoW, $logoH);

    if ($fontRegular && $fontBold && function_exists('imagettftext')) {
        libraryCardDrawTtf($img, $fontBold, 18, 28, 30, 'Jose Abad Santos High School', [20,20,20]);
        libraryCardDrawTtf($img, $fontRegular, 9, 28, 42, 'SCHOOL LIBRARY', [20,20,20]);

        // Fit the name into the center band without colliding with the logo/header.
        $name = libraryCardUpper(libraryCardSafeText($fullName));
        $nameSize = 20;
        while ($nameSize > 14 && libraryCardTextWidth($fontBold, $nameSize, $name) > 430) $nameSize -= 1;
        libraryCardDrawCenteredTtf($img, $fontBold, $nameSize, 80, $name, [20,20,20]);
    } else {
        imagestring($img, 5, 28, 10, 'Jose Abad Santos High School', $black);
        imagestring($img, 3, 29, 32, 'SCHOOL LIBRARY', $black);
        imagestring($img, 5, 150, 62, libraryCardSafeText($fullName), $black);
    }

    // Compact but highly visible QR block, matching the reference card.
    $qrSize = 160;
    $qx = (int)(($W - $qrSize) / 2);
    $qy = 100;
    imagefilledrectangle($img, $qx-10, $qy-10, $qx+$qrSize+10, $qy+$qrSize+10, $gray);
    $qrW = imagesx($qr); $qrH = imagesy($qr);
    imagecopyresampled($img, $qr, $qx, $qy, 0, 0, $qrSize, $qrSize, $qrW, $qrH);

    imagefilledrectangle($img, $qx-10, $qy+$qrSize+10, $qx+$qrSize+10, $qy+$qrSize+14, $yellow);
    if ($fontRegular && $fontBold && function_exists('imagettftext')) {
        libraryCardDrawCenteredTtf($img, $fontBold, 21, 316, libraryCardSafeText($idNumber), [20,20,20]);
        libraryCardDrawCenteredTtf($img, $fontBold, 17, 342, 'LIBRARY ACCESS CARD', [20,20,20]);
        libraryCardDrawCenteredTtf($img, $fontRegular, 8, 362, 'QR ID: ' . libraryCardSafeText($qrCode), [108,108,108]);
    } else {
        imagestring($img, 5, 250, 305, libraryCardSafeText($idNumber), $black);
        imagestring($img, 4, 201, 334, 'LIBRARY ACCESS CARD', $black);
        imagestring($img, 2, 214, 357, 'QR ID: ' . libraryCardSafeText($qrCode), $grayText);
    }

    ob_start();
    imagejpeg($img, null, 95);
    $jpeg = ob_get_clean();
    imagedestroy($logo); imagedestroy($qr); imagedestroy($img);
    if ($jpeg === false || $jpeg === '') throw new RuntimeException('Unable to encode Library Access Card as JPEG.');
    return $jpeg;
}

function libraryCardBuildWithImageMagick(string $fullName, string $idNumber, string $qrCode, string $qrBytes, string $logoBytes): string {
    if (!function_exists('exec')) throw new RuntimeException('A JPEG image engine is required to generate Library Access Card files.');

    $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'jas_library_card_' . bin2hex(random_bytes(6));
    if (!@mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) throw new RuntimeException('Unable to create a temporary card workspace.');
    $svgPath = $tmpDir . DIRECTORY_SEPARATOR . 'card.svg';
    $outPath = $tmpDir . DIRECTORY_SEPARATOR . 'card.jpg';

    try {
        $logoData = 'data:image/jpeg;base64,' . base64_encode($logoBytes);
        $qrData = 'data:image/png;base64,' . base64_encode($qrBytes);
        $name = htmlspecialchars(libraryCardUpper(libraryCardSafeText($fullName)), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $id = htmlspecialchars(libraryCardSafeText($idNumber), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $qrid = htmlspecialchars(libraryCardSafeText($qrCode), ENT_QUOTES | ENT_XML1, 'UTF-8');

        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="590" height="372" viewBox="0 0 590 372">
  <rect width="590" height="372" rx="22" fill="#fff"/>
  <clipPath id="clip"><rect x="2" y="2" width="586" height="368" rx="20"/></clipPath>
  <g clip-path="url(#clip)">
    <rect width="590" height="46" fill="#F6D61B"/>
    <rect y="45" width="590" height="5" fill="#141414"/>
    <path d="M475 372H590L590 310Z" fill="#FFF29F"/>
    <path d="M536 372H590L590 342Z" fill="#141414"/>
    <rect x="4" y="4" width="582" height="364" rx="20" fill="none" stroke="#141414" stroke-width="1.5"/>
    <rect x="9" y="9" width="572" height="354" rx="17" fill="none" stroke="#D7D8DB" stroke-width="1"/>
    <text x="28" y="30" font-family="Arial, DejaVu Sans, sans-serif" font-weight="700" font-size="18" fill="#141414">Jose Abad Santos High School</text>
    <text x="28" y="42" font-family="Arial, DejaVu Sans, sans-serif" font-size="9" fill="#141414">SCHOOL LIBRARY</text>
    <image href="$logoData" x="497" y="14" width="72" height="72" preserveAspectRatio="xMidYMid meet"/>
    <text x="295" y="80" text-anchor="middle" font-family="Arial, DejaVu Sans, sans-serif" font-weight="700" font-size="20" fill="#141414">$name</text>
    <rect x="205" y="90" width="180" height="180" rx="6" fill="#F2F3F5"/>
    <image href="$qrData" x="215" y="100" width="160" height="160" preserveAspectRatio="none"/>
    <rect x="205" y="270" width="180" height="4" fill="#F6D61B"/>
    <text x="295" y="316" text-anchor="middle" font-family="Arial, DejaVu Sans, sans-serif" font-weight="700" font-size="21" fill="#141414">$id</text>
    <text x="295" y="342" text-anchor="middle" font-family="Arial, DejaVu Sans, sans-serif" font-weight="700" font-size="17" fill="#141414">LIBRARY ACCESS CARD</text>
    <text x="295" y="362" text-anchor="middle" font-family="Arial, DejaVu Sans, sans-serif" font-size="8" fill="#6C6C6C">QR ID: $qrid</text>
  </g>
</svg>
SVG;
        file_put_contents($svgPath, $svg);

        $engine = libraryCardFindImageMagickEngine();
        if ($engine === null) throw new RuntimeException('Neither GD nor ImageMagick is available for JPEG card generation.');
        $o = []; $code = 1;
        @exec(escapeshellarg($engine) . ' -background white ' . escapeshellarg($svgPath) . ' -resize 590x372! -quality 95 ' . escapeshellarg($outPath) . ' 2>&1', $o, $code);
        if ($code !== 0 || !is_file($outPath) || filesize($outPath) === 0) throw new RuntimeException('Unable to render the Library Access Card JPEG.');
        $bytes = file_get_contents($outPath);
        if ($bytes === false || $bytes === '') throw new RuntimeException('Unable to read the generated Library Access Card JPEG.');
        return $bytes;
    } finally {
        foreach ([$svgPath,$outPath] as $p) { if (is_file($p)) @unlink($p); }
        @rmdir($tmpDir);
    }
}

function fetchLibraryQrPngBytes(string $qrCode): ?string {
    $qrCode = libraryCardSafeText($qrCode);
    if (!preg_match('/^[A-Za-z0-9_-]{1,120}$/', $qrCode)) return null;

    $local = dirname(__DIR__) . '/qr_codes/' . $qrCode . '.png';
    if (is_file($local) && filesize($local) > 0) {
        $bytes = @file_get_contents($local);
        if ($bytes !== false && $bytes !== '') return $bytes;
    }

    $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=420x420&margin=0&ecc=M&data=' . rawurlencode($qrCode);
    $image = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($qrUrl);
        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Jose Abad Santos High School Library QR Generator',
        ];
        if (function_exists('gmailCaFile')) {
            $qrCaFile = gmailCaFile();
            if ($qrCaFile !== null) $curlOptions[CURLOPT_CAINFO] = $qrCaFile;
        }
        curl_setopt_array($ch, $curlOptions);
        $image = curl_exec($ch);
        curl_close($ch);

        // Local development only: if Laragon/Windows antivirus intercepts HTTPS
        // and replaces the certificate, allow the QR image request to retry once
        // without certificate verification.
        if (($image === false || !is_string($image) || $image === '') && function_exists('gmailIsLocalDevelopmentHost') && gmailIsLocalDevelopmentHost()) {
            $ch = curl_init($qrUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_USERAGENT => 'Jose Abad Santos High School Library QR Generator',
            ]);
            $image = curl_exec($ch);
            curl_close($ch);
        }
    }
    if ($image === false || !is_string($image) || $image === '') {
        $context = stream_context_create([
            'http' => ['timeout' => 15, 'user_agent' => 'Jose Abad Santos High School Library QR Generator'],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]
        ]);
        $image = @file_get_contents($qrUrl, false, $context);
    }
    if (($image === false || !is_string($image) || $image === '') && function_exists('gmailIsLocalDevelopmentHost') && gmailIsLocalDevelopmentHost()) {
        $context = stream_context_create([
            'http' => ['timeout' => 15, 'user_agent' => 'Jose Abad Santos High School Library QR Generator'],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]
        ]);
        $image = @file_get_contents($qrUrl, false, $context);
    }
    if ($image !== false && is_string($image) && $image !== '') {
        if (!is_dir(dirname($local))) @mkdir(dirname($local), 0755, true);
        @file_put_contents($local, $image);
        return $image;
    }
    return null;
}

function libraryCardFindImageMagickEngine(): ?string {
    if (!function_exists('exec')) return null;
    $candidates = [
        'magick',
        'convert',
        '/usr/bin/magick',
        '/usr/local/bin/magick',
        '/opt/imagemagick/bin/magick',
        'C:\Program Files\ImageMagick-7.1.1-Q16-HDRI\magick.exe',
        'C:\Program Files\ImageMagick-7.1.0-Q16-HDRI\magick.exe',
    ];
    foreach ($candidates as $candidate) {
        $o = []; $code = 1;
        @exec(escapeshellarg($candidate) . ' -version 2>&1', $o, $code);
        if ($code === 0) return $candidate;
    }
    return null;
}

function buildLibraryAccessCardJpeg(string $fullName, string $idNumber, string $qrCode, string $qrBytes = ''): string {
    $fullName = libraryCardSafeText($fullName);
    $idNumber = libraryCardSafeText($idNumber);
    $qrCode = libraryCardSafeText($qrCode);
    if ($fullName === '' || $idNumber === '' || $qrCode === '') {
        throw new RuntimeException('Name, ID number, and QR ID are required to build the Library Access Card.');
    }
    if (!preg_match('/^[A-Za-z0-9_-]{1,120}$/', $qrCode)) {
        throw new RuntimeException('The QR code contains invalid characters.');
    }

    $logoPath = dirname(__DIR__) . '/Img/jAbadSantos_Logo.jpg';
    if (!is_file($logoPath) || filesize($logoPath) === 0) {
        throw new RuntimeException('The Jose Abad Santos High School logo file is missing.');
    }
    $logoBytes = file_get_contents($logoPath);
    if ($logoBytes === false || $logoBytes === '') throw new RuntimeException('Unable to read the school logo.');

    if ($qrBytes === '') {
        $qrPath = dirname(__DIR__) . '/qr_codes/' . $qrCode . '.png';
        if (is_file($qrPath) && filesize($qrPath) > 0) {
            $qrBytes = file_get_contents($qrPath);
        }
    }
    if ($qrBytes === false || $qrBytes === '') {
        throw new RuntimeException('The QR image is not available.');
    }

    if (extension_loaded('gd') && function_exists('imagepng')) {
        return libraryCardBuildWithGd($fullName, $idNumber, $qrCode, $qrBytes, $logoBytes);
    }
    return libraryCardBuildWithImageMagick($fullName, $idNumber, $qrCode, $qrBytes, $logoBytes);
}



/**
 * Build a print-ready PDF by embedding the generated JPEG directly.
 * No ImageMagick/PDF extension is required.
 */
function buildLibraryAccessCardPdf(string $fullName, string $idNumber, string $qrCode, string $qrBytes = ''): string {
    $jpeg = buildLibraryAccessCardJpeg($fullName, $idNumber, $qrCode, $qrBytes);
    $size = @getimagesizefromstring($jpeg);
    if (!$size || empty($size[0]) || empty($size[1])) {
        throw new RuntimeException('The generated Library Access Card JPEG is invalid.');
    }

    $widthPx = (int)$size[0];
    $heightPx = (int)$size[1];
    $pageW = 243.0; // 3.375 in @ 72 pt/in
    $pageH = 153.0; // 2.125 in @ 72 pt/in
    $content = "q\n" . $pageW . " 0 0 " . $pageH . " 0 0 cm\n/Im0 Do\nQ\n";

    $objects = [];
    $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
    $objects[2] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
    $objects[3] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$pageW} {$pageH}] /Resources << /XObject << /Im0 5 0 R >> >> /Contents 4 0 R >>";
    $objects[4] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream";
    $objects[5] = "<< /Type /XObject /Subtype /Image /Width {$widthPx} /Height {$heightPx} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($jpeg) . " >>\nstream\n" . $jpeg . "\nendstream";

    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [0 => 0];
    for ($i = 1; $i <= 5; $i++) {
        $offsets[$i] = strlen($pdf);
        $pdf .= $i . " 0 obj\n" . $objects[$i] . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 6\n0000000000 65535 f \n";
    for ($i = 1; $i <= 5; $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF\n";
    return $pdf;
}

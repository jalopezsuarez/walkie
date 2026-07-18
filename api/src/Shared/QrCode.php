<?php
declare(strict_types=1);

namespace Walkie\Shared;

/**
 * Self-contained QR Code generator (byte mode), no external dependencies.
 *
 * Implements ISO/IEC 18004: Reed-Solomon error correction over GF(256),
 * block interleaving, the eight data masks with penalty scoring, and BCH
 * format/version information. Supports versions 1-10 which is far more than
 * enough for a pairing URL. Output is an SVG string.
 *
 * Verified module-for-module against the reference `qrcode` implementation
 * (see api/tests/qr_verify.py).
 */
final class QrCode
{
    // Error-correction block layout for versions 1..10.
    // [ecPerBlock, blocksG1, dataG1, blocksG2, dataG2]
    private const EC_BLOCKS = [
        // version => [L, M, Q, H]
        1  => [[7,1,19,0,0],   [10,1,16,0,0],  [13,1,13,0,0],  [17,1,9,0,0]],
        2  => [[10,1,34,0,0],  [16,1,28,0,0],  [22,1,22,0,0],  [28,1,16,0,0]],
        3  => [[15,1,55,0,0],  [26,1,44,0,0],  [18,2,17,0,0],  [22,2,13,0,0]],
        4  => [[20,1,80,0,0],  [18,2,32,0,0],  [26,2,24,0,0],  [16,4,9,0,0]],
        5  => [[26,1,108,0,0], [24,2,43,0,0],  [18,2,15,2,16], [22,2,11,2,12]],
        6  => [[18,2,68,0,0],  [16,4,27,0,0],  [24,4,19,0,0],  [28,4,15,0,0]],
        7  => [[20,2,78,0,0],  [18,4,31,0,0],  [18,2,14,4,15], [26,4,13,1,14]],
        8  => [[24,2,97,0,0],  [22,2,38,2,39], [22,4,18,2,19], [26,4,14,2,15]],
        9  => [[30,2,116,0,0], [22,3,36,2,37], [20,4,16,4,17], [24,4,12,4,13]],
        10 => [[18,2,68,2,69], [26,4,43,1,44], [24,6,19,2,20], [28,6,15,2,16]],
    ];

    private const ALIGN = [
        1 => [], 2 => [6,18], 3 => [6,22], 4 => [6,26], 5 => [6,30],
        6 => [6,34], 7 => [6,22,38], 8 => [6,24,42], 9 => [6,26,46], 10 => [6,28,50],
    ];

    private const REMAINDER = [1=>0,2=>7,3=>7,4=>7,5=>7,6=>7,7=>0,8=>0,9=>0,10=>0];

    // EC level index and its 2-bit format value.
    private const EC_LEVELS = ['L' => 0, 'M' => 1, 'Q' => 2, 'H' => 3];
    private const EC_FORMAT = ['L' => 0b01, 'M' => 0b00, 'Q' => 0b11, 'H' => 0b10];

    private static array $expTable = [];
    private static array $logTable = [];

    /** Render $data as an SVG QR code string. */
    public static function svg(string $data, string $ecLevel = 'M', int $scale = 8, int $quiet = 4): string
    {
        $matrix = self::build($data, $ecLevel);
        $n = count($matrix);
        $size = ($n + 2 * $quiet) * $scale;

        $svg  = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" ';
        $svg .= 'viewBox="0 0 ' . $size . ' ' . $size . '" shape-rendering="crispEdges" role="img" aria-label="QR">';
        $svg .= '<rect width="100%" height="100%" fill="#ffffff"/>';
        $svg .= '<path fill="#000000" d="';
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($matrix[$y][$x]) {
                    $px = ($x + $quiet) * $scale;
                    $py = ($y + $quiet) * $scale;
                    $svg .= "M{$px} {$py}h{$scale}v{$scale}h-{$scale}z";
                }
            }
        }
        $svg .= '"/></svg>';
        return $svg;
    }

    /**
     * Build the QR module matrix.
     * @return array<int, array<int, bool>> true = dark module
     */
    public static function build(
        string $data, string $ecLevel = 'M', ?int $forceVersion = null, ?int $forceMask = null
    ): array {
        $ecLevel = strtoupper($ecLevel);
        if (!isset(self::EC_LEVELS[$ecLevel])) {
            $ecLevel = 'M';
        }
        $version = $forceVersion ?? self::chooseVersion($data, $ecLevel);
        [$ecPerBlock, $g1n, $g1d, $g2n, $g2d] = self::EC_BLOCKS[$version][self::EC_LEVELS[$ecLevel]];
        $totalDataCw = $g1n * $g1d + $g2n * $g2d;

        $bits = self::encodeData($data, $version, $totalDataCw);
        $dataCw = self::bitsToCodewords($bits);
        $finalCw = self::buildFinalCodewords($dataCw, $ecPerBlock, $g1n, $g1d, $g2n, $g2d, $version);

        return self::placeAndMask($finalCw, $version, $ecLevel, $forceMask);
    }

    private static function chooseVersion(string $data, string $ecLevel): int
    {
        $len = strlen($data);
        foreach (array_keys(self::EC_BLOCKS) as $v) {
            [, $g1n, $g1d, $g2n, $g2d] = self::EC_BLOCKS[$v][self::EC_LEVELS[$ecLevel]];
            $totalDataCw = $g1n * $g1d + $g2n * $g2d;
            $ccBits = $v < 10 ? 8 : 16;
            $needBits = 4 + $ccBits + $len * 8;
            if ($needBits <= $totalDataCw * 8) {
                return $v;
            }
        }
        throw new \RuntimeException('Data too long for supported QR versions (max 10).');
    }

    /** Byte-mode bitstream with terminator and padding. */
    private static function encodeData(string $data, int $version, int $totalDataCw): string
    {
        $ccBits = $version < 10 ? 8 : 16;
        $bits = '0100'; // byte mode
        $bits .= str_pad(decbin(strlen($data)), $ccBits, '0', STR_PAD_LEFT);
        foreach (str_split($data) as $ch) {
            $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
        }
        $capacityBits = $totalDataCw * 8;
        // Terminator (up to 4 zero bits).
        $bits .= str_repeat('0', min(4, $capacityBits - strlen($bits)));
        // Pad to byte boundary.
        if (strlen($bits) % 8 !== 0) {
            $bits .= str_repeat('0', 8 - (strlen($bits) % 8));
        }
        // Pad bytes 0xEC / 0x11.
        $pad = ['11101100', '00010001'];
        $i = 0;
        while (strlen($bits) < $capacityBits) {
            $bits .= $pad[$i % 2];
            $i++;
        }
        return $bits;
    }

    /** @return int[] data codewords */
    private static function bitsToCodewords(string $bits): array
    {
        $cw = [];
        for ($i = 0, $len = strlen($bits); $i < $len; $i += 8) {
            $cw[] = bindec(substr($bits, $i, 8));
        }
        return $cw;
    }

    /**
     * Split into blocks, compute EC, and interleave data + EC codewords.
     * @param int[] $dataCw
     * @return int[]
     */
    private static function buildFinalCodewords(
        array $dataCw, int $ecPerBlock, int $g1n, int $g1d, int $g2n, int $g2d, int $version
    ): array {
        $blocks = [];
        $pos = 0;
        for ($b = 0; $b < $g1n; $b++) {
            $blocks[] = array_slice($dataCw, $pos, $g1d);
            $pos += $g1d;
        }
        for ($b = 0; $b < $g2n; $b++) {
            $blocks[] = array_slice($dataCw, $pos, $g2d);
            $pos += $g2d;
        }

        $ecBlocks = [];
        foreach ($blocks as $block) {
            $ecBlocks[] = self::reedSolomon($block, $ecPerBlock);
        }

        // Interleave data codewords.
        $result = [];
        $maxData = max($g1d, $g2d);
        for ($i = 0; $i < $maxData; $i++) {
            foreach ($blocks as $block) {
                if ($i < count($block)) {
                    $result[] = $block[$i];
                }
            }
        }
        // Interleave EC codewords.
        for ($i = 0; $i < $ecPerBlock; $i++) {
            foreach ($ecBlocks as $ec) {
                $result[] = $ec[$i];
            }
        }
        return $result;
    }

    // --- GF(256) arithmetic ----------------------------------------------

    private static function initGf(): void
    {
        if (self::$expTable) {
            return;
        }
        $exp = array_fill(0, 512, 0);
        $log = array_fill(0, 256, 0);
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            $exp[$i] = $x;
            $log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11d;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            $exp[$i] = $exp[$i - 255];
        }
        self::$expTable = $exp;
        self::$logTable = $log;
    }

    private static function gfMul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        return self::$expTable[(self::$logTable[$a] + self::$logTable[$b]) % 255];
    }

    /**
     * Reed-Solomon EC codewords for one data block.
     * @param int[] $data
     * @return int[]
     */
    private static function reedSolomon(array $data, int $ecLen): array
    {
        self::initGf();
        // Generator polynomial.
        $gen = [1];
        for ($i = 0; $i < $ecLen; $i++) {
            $next = array_fill(0, count($gen) + 1, 0);
            foreach ($gen as $j => $coeff) {
                $next[$j]     ^= $coeff;
                $next[$j + 1] ^= self::gfMul($coeff, self::$expTable[$i]);
            }
            $gen = $next;
        }

        // Polynomial division.
        $rem = array_merge($data, array_fill(0, $ecLen, 0));
        for ($i = 0; $i < count($data); $i++) {
            $factor = $rem[$i];
            if ($factor === 0) {
                continue;
            }
            foreach ($gen as $j => $coeff) {
                $rem[$i + $j] ^= self::gfMul($coeff, $factor);
            }
        }
        return array_slice($rem, count($data), $ecLen);
    }

    // --- Matrix construction, masking, format info -----------------------

    /**
     * @param int[] $codewords
     * @return array<int, array<int, bool>>
     */
    private static function placeAndMask(array $codewords, int $version, string $ecLevel, ?int $forceMask = null): array
    {
        $n = 17 + 4 * $version;
        // module: null=unset, true/false = value; reserved tracked separately
        $m = array_fill(0, $n, array_fill(0, $n, null));
        $reserved = array_fill(0, $n, array_fill(0, $n, false));

        $setFn = function (int $r, int $c, bool $v, bool $isFunction) use (&$m, &$reserved): void {
            $m[$r][$c] = $v;
            if ($isFunction) {
                $reserved[$r][$c] = true;
            }
        };

        // Finder patterns + separators.
        foreach ([[0, 0], [0, $n - 7], [$n - 7, 0]] as [$r, $c]) {
            self::placeFinder($setFn, $r, $c, $n);
        }
        // Timing patterns.
        for ($i = 8; $i < $n - 8; $i++) {
            $setFn(6, $i, $i % 2 === 0, true);
            $setFn($i, 6, $i % 2 === 0, true);
        }
        // Alignment patterns.
        $centers = self::ALIGN[$version];
        foreach ($centers as $cy) {
            foreach ($centers as $cx) {
                if (($cy === 6 && $cx === 6)
                    || ($cy === 6 && $cx === $n - 7)
                    || ($cy === $n - 7 && $cx === 6)) {
                    continue;
                }
                self::placeAlignment($setFn, $cy, $cx);
            }
        }
        // Dark module.
        $setFn(4 * $version + 9, 8, true, true);

        // Reserve format & version info areas.
        self::reserveFormat($reserved, $n);
        if ($version >= 7) {
            self::reserveVersion($reserved, $n);
        }

        // Place data bits (zigzag).
        $bitString = '';
        foreach ($codewords as $cw) {
            $bitString .= str_pad(decbin($cw), 8, '0', STR_PAD_LEFT);
        }
        $bitString .= str_repeat('0', self::REMAINDER[$version]);

        $bitIndex = 0;
        $bitLen = strlen($bitString);
        $col = $n - 1;
        $upward = true;
        while ($col > 0) {
            if ($col === 6) { // skip vertical timing column
                $col--;
            }
            for ($i = 0; $i < $n; $i++) {
                $row = $upward ? ($n - 1 - $i) : $i;
                for ($k = 0; $k < 2; $k++) {
                    $c = $col - $k;
                    if ($reserved[$row][$c]) {
                        continue;
                    }
                    $bit = $bitIndex < $bitLen ? ($bitString[$bitIndex] === '1') : false;
                    $m[$row][$c] = $bit;
                    $bitIndex++;
                }
            }
            $col -= 2;
            $upward = !$upward;
        }

        // Choose best mask.
        $bestMask = 0;
        $bestPenalty = PHP_INT_MAX;
        $bestMatrix = null;
        $masks = $forceMask === null ? range(0, 7) : [$forceMask];
        foreach ($masks as $mask) {
            $candidate = self::applyMask($m, $reserved, $mask);
            self::writeFormat($candidate, $reserved, $ecLevel, $mask, $n);
            if ($version >= 7) {
                self::writeVersion($candidate, $version, $n);
            }
            $penalty = self::penalty($candidate, $n);
            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $bestMask = $mask;
                $bestMatrix = $candidate;
            }
        }
        // Normalise nulls to false.
        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                $bestMatrix[$r][$c] = (bool) $bestMatrix[$r][$c];
            }
        }
        return $bestMatrix;
    }

    private static function placeFinder(callable $set, int $r, int $c, int $n): void
    {
        for ($dr = -1; $dr <= 7; $dr++) {
            for ($dc = -1; $dc <= 7; $dc++) {
                $rr = $r + $dr;
                $cc = $c + $dc;
                if ($rr < 0 || $cc < 0 || $rr >= $n || $cc >= $n) {
                    continue;
                }
                // Separator (the -1/7 ring) is light; outside 7x7 skip if out of the block area handled by bounds.
                $inRing = ($dr >= 0 && $dr <= 6 && $dc >= 0 && $dc <= 6);
                if (!$inRing) {
                    // separator area
                    $set($rr, $cc, false, true);
                    continue;
                }
                $isDark = ($dr === 0 || $dr === 6 || $dc === 0 || $dc === 6)
                    || ($dr >= 2 && $dr <= 4 && $dc >= 2 && $dc <= 4);
                $set($rr, $cc, $isDark, true);
            }
        }
    }

    private static function placeAlignment(callable $set, int $cy, int $cx): void
    {
        for ($dr = -2; $dr <= 2; $dr++) {
            for ($dc = -2; $dc <= 2; $dc++) {
                $isDark = max(abs($dr), abs($dc)) !== 1;
                $set($cy + $dr, $cx + $dc, $isDark, true);
            }
        }
    }

    private static function reserveFormat(array &$reserved, int $n): void
    {
        for ($i = 0; $i <= 8; $i++) {
            $reserved[8][$i] = true;
            $reserved[$i][8] = true;
        }
        for ($i = 0; $i < 8; $i++) {
            $reserved[8][$n - 1 - $i] = true;
            $reserved[$n - 1 - $i][8] = true;
        }
    }

    private static function reserveVersion(array &$reserved, int $n): void
    {
        for ($i = 0; $i < 6; $i++) {
            for ($j = 0; $j < 3; $j++) {
                $reserved[$i][$n - 11 + $j] = true;
                $reserved[$n - 11 + $j][$i] = true;
            }
        }
    }

    private static function applyMask(array $m, array $reserved, int $mask): array
    {
        $n = count($m);
        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                if ($reserved[$r][$c] || $m[$r][$c] === null) {
                    continue;
                }
                if (self::maskCondition($mask, $r, $c)) {
                    $m[$r][$c] = !$m[$r][$c];
                }
            }
        }
        return $m;
    }

    private static function maskCondition(int $mask, int $r, int $c): bool
    {
        return match ($mask) {
            0 => ($r + $c) % 2 === 0,
            1 => $r % 2 === 0,
            2 => $c % 3 === 0,
            3 => ($r + $c) % 3 === 0,
            4 => (intdiv($r, 2) + intdiv($c, 3)) % 2 === 0,
            5 => (($r * $c) % 2) + (($r * $c) % 3) === 0,
            6 => ((($r * $c) % 2) + (($r * $c) % 3)) % 2 === 0,
            7 => ((($r + $c) % 2) + (($r * $c) % 3)) % 2 === 0,
            default => false,
        };
    }

    private static function writeFormat(array &$m, array $reserved, string $ecLevel, int $mask, int $n): void
    {
        $data = (self::EC_FORMAT[$ecLevel] << 3) | $mask;   // 5 bits
        $bits = self::bchFormat($data);                     // 15-bit BCH(15,5)
        $bit = fn(int $i): bool => (($bits >> $i) & 1) === 1; // LSB-indexed

        // First copy — around the top-left finder (MSB first along the path).
        for ($i = 0; $i <= 5; $i++) {
            $m[8][$i] = $bit(14 - $i);
        }
        $m[8][7] = $bit(8);
        $m[8][8] = $bit(7);
        $m[7][8] = $bit(6);
        for ($i = 9; $i <= 14; $i++) {
            $m[14 - $i][8] = $bit(14 - $i);
        }

        // Second copy — horizontal along row 8 (top-right), vertical up col 8 (bottom-left).
        for ($i = 0; $i <= 7; $i++) {
            $m[8][$n - 1 - $i] = $bit($i);
        }
        for ($i = 0; $i <= 6; $i++) {
            $m[$n - 1 - $i][8] = $bit(14 - $i);
        }
        // Dark module stays dark.
        $m[$n - 8][8] = true;
    }

    private static function bchFormat(int $data5): int
    {
        $g = 0b10100110111; // 0x537
        $d = $data5 << 10;
        $rem = $d;
        for ($i = 14; $i >= 10; $i--) {
            if (($rem >> $i) & 1) {
                $rem ^= $g << ($i - 10);
            }
        }
        $format = (($data5 << 10) | ($rem & 0x3FF));
        return $format ^ 0b101010000010010; // 0x5412 mask
    }

    private static function writeVersion(array &$m, int $version, int $n): void
    {
        $bits = self::bchVersion($version); // 18 bits
        for ($i = 0; $i < 18; $i++) {
            $bit = (($bits >> $i) & 1) === 1;
            $r = intdiv($i, 3);
            $c = $i % 3;
            $m[$r][$n - 11 + $c] = $bit;
            $m[$n - 11 + $c][$r] = $bit;
        }
    }

    private static function bchVersion(int $version): int
    {
        $g = 0b1111100100101; // 0x1f25
        $d = $version << 12;
        $rem = $d;
        for ($i = 17; $i >= 12; $i--) {
            if (($rem >> $i) & 1) {
                $rem ^= $g << ($i - 12);
            }
        }
        return ($version << 12) | ($rem & 0xFFF);
    }

    private static function penalty(array $m, int $n): int
    {
        $score = 0;
        // Rule 1: runs of 5+ same-colour in rows and columns.
        for ($r = 0; $r < $n; $r++) {
            $runColor = null; $runLen = 0;
            for ($c = 0; $c < $n; $c++) {
                $v = (bool) $m[$r][$c];
                if ($v === $runColor) { $runLen++; }
                else { $runColor = $v; $runLen = 1; }
                if ($runLen === 5) { $score += 3; }
                elseif ($runLen > 5) { $score += 1; }
            }
        }
        for ($c = 0; $c < $n; $c++) {
            $runColor = null; $runLen = 0;
            for ($r = 0; $r < $n; $r++) {
                $v = (bool) $m[$r][$c];
                if ($v === $runColor) { $runLen++; }
                else { $runColor = $v; $runLen = 1; }
                if ($runLen === 5) { $score += 3; }
                elseif ($runLen > 5) { $score += 1; }
            }
        }
        // Rule 2: 2x2 blocks.
        for ($r = 0; $r < $n - 1; $r++) {
            for ($c = 0; $c < $n - 1; $c++) {
                $v = (bool) $m[$r][$c];
                if ($v === (bool) $m[$r][$c + 1]
                    && $v === (bool) $m[$r + 1][$c]
                    && $v === (bool) $m[$r + 1][$c + 1]) {
                    $score += 3;
                }
            }
        }
        // Rule 3: finder-like patterns 1:1:3:1:1 with 4 light on either side.
        $patternA = [true,false,true,true,true,false,true,false,false,false,false];
        $patternB = [false,false,false,false,true,false,true,true,true,false,true];
        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c <= $n - 11; $c++) {
                $rowSeg = []; $colSeg = [];
                for ($k = 0; $k < 11; $k++) {
                    $rowSeg[] = (bool) $m[$r][$c + $k];
                    $colSeg[] = (bool) $m[$c + $k][$r];
                }
                if ($rowSeg === $patternA || $rowSeg === $patternB) { $score += 40; }
                if ($colSeg === $patternA || $colSeg === $patternB) { $score += 40; }
            }
        }
        // Rule 4: dark/light balance.
        $dark = 0;
        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                if ((bool) $m[$r][$c]) { $dark++; }
            }
        }
        $total = $n * $n;
        $percent = ($dark * 100) / $total;
        $prev = (int) (floor($percent / 5) * 5);
        $next = $prev + 5;
        $score += min(abs($prev - 50), abs($next - 50)) / 5 * 10;

        return (int) $score;
    }
}

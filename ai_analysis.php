<?php
session_start();

// Note: the QR code no longer depends on a local qrcode.php library
// (that file was never present, so require_once'ing it fatally
// crashed this entire page on every load). The "Scan to Verify" QR
// is now built the same way as in admin_reviews.php and
// view_assignment.php: an <img> tag against the free api.qrserver.com
// endpoint, encoding a direct link to image/certificate.png.
// See the "Build the Scan to Verify QR code" block below.

// ═══════════════════════════════════════════════════════════════
// CONNECTION
// ═══════════════════════════════════════════════════════════════
$servername = "localhost";
$username   = "root";
$password   = "";               // Default XAMPP password
$dbname     = "assignment_db";  // Your local database name

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

require_once __DIR__ . '/notification_helpers.php';

 $admin_id = $_SESSION['user_id'] ?? null;
if (!isset($admin_id)) {
    header('location: login.php');
    exit;
}

// Verify admin exists
 $admin_check = $conn->prepare("SELECT user_id FROM users WHERE user_id = ? AND user_type = 'admin'");
 $admin_check->bind_param("i", $admin_id);
 $admin_check->execute();
if ($admin_check->get_result()->num_rows === 0) {
    header('location: login.php');
    exit;
}
 $admin_check->close();

// ═══════════════════════════════════════════════════════════════
// HELPER: Resolve file path to absolute, prevent traversal
// ═══════════════════════════════════════════════════════════════
function resolveFilePath($relative_path) {
    if (empty($relative_path)) return false;
    $relative_path = ltrim($relative_path, '/\\');
    // Prevent path traversal
    if (strpos($relative_path, '..') !== false) return false;
    $abs = __DIR__ . '/' . $relative_path;
    $real = realpath($abs);
    if ($real === false) return false;
    $upload_base = realpath(__DIR__ . '/uploads/assignments/');
    if ($upload_base === false) return false;
    // Must be inside uploads/assignments/
    if (strpos($real, $upload_base) !== 0) return false;
    return $real;
}

// ═══════════════════════════════════════════════════════════════
// HELPER: Get the assignment's relative file path, with fallback
// to the legacy "upload_file" column (bare filename) for rows
// that were submitted before "file_path" existed.
// ═══════════════════════════════════════════════════════════════
function getAssignmentRelativePath($assignment) {
    if (!empty($assignment['file_path'])) {
        return $assignment['file_path'];
    }
    if (!empty($assignment['upload_file'])) {
        // upload_file may already contain "uploads/assignments/..." (older rows)
        // or just the bare filename (current rows) — handle both.
        $uf = $assignment['upload_file'];
        if (strpos($uf, 'uploads/assignments/') !== false) {
            return $uf;
        }
        return 'uploads/assignments/' . ltrim($uf, '/\\');
    }
    return '';
}

// ═══════════════════════════════════════════════════════════════
// HELPER: Very basic text extraction for PDF / DOCX so uploaded
// assignments can actually be read for analysis instead of being
// treated as an opaque binary blob.
// ═══════════════════════════════════════════════════════════════
function extractPdfText($path) {
    $data = @file_get_contents($path);
    if ($data === false) return '';
    $text = '';

    // Handle both plain and FlateDecode (zlib compressed) content streams
    if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $data, $streams)) {
        foreach ($streams[1] as $stream) {
            $decoded = @gzuncompress($stream);
            if ($decoded === false) $decoded = @gzinflate($stream);
            $chunk = ($decoded !== false) ? $decoded : $stream;
            // Extract text shown via Tj / TJ operators — literal strings (...)
            if (preg_match_all('/\((?:[^()\\\\]|\\\\.)*\)\s*Tj/', $chunk, $mm)) {
                foreach ($mm[0] as $m) {
                    $inner = preg_replace('/\)\s*Tj$/', '', preg_replace('/^\(/', '', $m));
                    $text .= decodePdfString($inner) . ' ';
                }
            }
            if (preg_match_all('/\[(.*?)\]\s*TJ/', $chunk, $mm2)) {
                foreach ($mm2[1] as $arr) {
                    if (preg_match_all('/\((?:[^()\\\\]|\\\\.)*\)/', $arr, $mm3)) {
                        foreach ($mm3[0] as $piece) {
                            $text .= decodePdfString(substr($piece, 1, -1)) . ' ';
                        }
                    }
                    // Same array can also contain hex strings <...> mixed with literals
                    if (preg_match_all('/<([0-9A-Fa-f\s]+)>/', $arr, $mm3h)) {
                        foreach ($mm3h[1] as $hex) {
                            $text .= decodePdfHexString($hex) . ' ';
                        }
                    }
                }
            }
            // Hex strings shown directly via Tj: <...> Tj
            if (preg_match_all('/<([0-9A-Fa-f\s]+)>\s*Tj/', $chunk, $mmh)) {
                foreach ($mmh[1] as $hex) {
                    $text .= decodePdfHexString($hex) . ' ';
                }
            }
        }
    }
    return trim(preg_replace('/\s+/', ' ', $text));
}

function decodePdfHexString($hex) {
    $hex = preg_replace('/\s+/', '', $hex);
    if (strlen($hex) % 2 !== 0) $hex .= '0';
    $bytes = @hex2bin($hex);
    if ($bytes === false) return '';
    // Two-byte-per-char (UTF-16BE) is common for hex strings; fall back to
    // treating it as single-byte WinAnsi/PDFDocEncoding if that decode
    // doesn't apply (this is where smart quotes/dashes/accents live).
    if (strlen($bytes) >= 2 && substr($bytes, 0, 2) === "\xFE\xFF") {
        $decoded = @mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16BE');
        return $decoded !== false ? sanitizeExtractedText($decoded) : sanitizeExtractedText($bytes);
    }
    return sanitizeExtractedText($bytes);
}

function decodePdfString($s) {
    // Decode octal escapes (\ddd) FIRST — this is how PDF literal strings
    // commonly encode smart quotes, em/en-dashes and accented characters
    // (e.g. \222 for a right single quote). Leaving these undecoded is a
    // major source of the "random letters/square boxes" corruption.
    $s = preg_replace_callback('/\\\\([0-7]{1,3})/', function ($m) {
        return chr(octdec($m[1]) & 0xFF);
    }, $s);
    // Then the remaining standard PDF literal-string escapes.
    $s = str_replace(
        ['\\n', '\\r', '\\t', '\\b', '\\f', '\\(', '\\)', '\\\\'],
        ["\n", "\r", "\t", "\x08", "\x0C", '(', ')', '\\'],
        $s
    );
    // PDF literal strings default to WinAnsiEncoding (~Windows-1252), not
    // UTF-8, so any remaining high-bit bytes must be reinterpreted rather
    // than passed straight through (which is what produced the corrupted
    // output previously).
    return sanitizeExtractedText($s);
}

// ═══════════════════════════════════════════════════════════════
// UNICODE SANITIZER — guarantees valid, clean UTF-8 text.
//
// Root cause of the corrupted-PDF bug: text extracted from uploaded
// PDFs/DOCX can contain bytes that are NOT valid UTF-8 — most commonly
// Windows-1252/WinAnsiEncoding bytes in the 0x80-0x9F range, which is
// exactly where smart quotes, en/em dashes and similar symbols live.
// When those raw bytes were fed straight into an HTML page declared as
// UTF-8, the browser (and its print-to-PDF engine) rendered them as the
// Unicode replacement character (a square box) or mis-mapped glyphs.
//
// This function is the single choke point every piece of extracted text
// flows through before it reaches the report: valid UTF-8 passes through
// untouched; anything else is reinterpreted as Windows-1252 (a safe,
// almost-always-correct assumption for Word/PDF-exported text) and
// converted properly; any byte that still can't be mapped is dropped
// rather than left to render as a corrupted glyph.
// ═══════════════════════════════════════════════════════════════
function sanitizeExtractedText($text) {
    if ($text === null) return '';
    $text = (string)$text;
    if ($text === '') return '';

    // Normalise line endings up front so downstream regex/whitespace
    // handling stays predictable.
    $text = str_replace(["\r\n", "\r"], "\n", $text);

    if (!mb_check_encoding($text, 'UTF-8')) {
        $converted = false;
        if (function_exists('iconv')) {
            $try = @iconv('Windows-1252', 'UTF-8//IGNORE', $text);
            if ($try !== false && $try !== '') { $text = $try; $converted = true; }
        }
        if (!$converted && function_exists('mb_convert_encoding')) {
            $try = @mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
            if ($try !== false) { $text = $try; $converted = true; }
        }
        if (!$converted) {
            // Last resort: strip whatever bytes still don't form valid UTF-8
            // instead of letting them render as corrupted characters.
            $stripped = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
            $text = ($stripped !== false && $stripped !== '') ? $stripped : preg_replace('/[\x80-\xFF]/', '', $text);
        }
    }

    // Strip stray control characters (keep \n and \t) — these are the
    // "random letters/boxes" that show up when binary junk leaks into text.
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

    // Drop any leftover Unicode replacement characters (U+FFFD) — these
    // only ever appear here because of an unrecoverable encoding error,
    // never as legitimate content.
    $text = str_replace("\xEF\xBF\xBD", '', $text);

    return $text;
}

function extractDocxText($path) {
    if (!class_exists('ZipArchive')) return '';
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return '';
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === false) return '';
    // Paragraph breaks and explicit line/page breaks (<w:br/>, <w:br w:type="page"/>)
    // both need to become newlines BEFORE tags are stripped, or the text
    // collapses onto one line and no longer matches the original layout.
    $xml = preg_replace('/<w:p\b[^>]*>/', "\n", $xml);
    $xml = preg_replace('/<w:br\s*\/?>/', "\n", $xml);
    $xml = preg_replace('/<w:tab\s*\/?>/', "\t", $xml);
    $text = strip_tags($xml);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    // Collapse repeated spaces/tabs but keep intentional newlines intact.
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\n[ \t]+/', "\n", $text);
    return trim($text);
}

// ═══════════════════════════════════════════════════════════════
// STRUCTURED DOCX EXTRACTION (high-fidelity path)
// Unlike extractDocxText() above (a flat regex pass kept only as an
// emergency fallback), this walks the real word/document.xml tree
// with DOMDocument/XPath and preserves actual document structure:
// headings, paragraphs, bullet/numbered lists, tables, indentation,
// alignment, bold/italic/underline, and page breaks. Nothing here
// rewrites or reflows text — every w:t run is carried through
// character-for-character; only structural wrapper tags are added.
// ═══════════════════════════════════════════════════════════════
const DOCX_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

function extractDocxStructured($path) {
    $empty = ['blocks' => [], 'plain' => ''];
    if (!class_exists('ZipArchive') || !class_exists('DOMDocument')) return $empty;

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return $empty;
    $xml = $zip->getFromName('word/document.xml');
    $numbering = parseDocxNumbering($zip);
    $zip->close();
    if ($xml === false) return $empty;

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $ok = $dom->loadXML($xml, LIBXML_NOENT);
    libxml_clear_errors();
    if (!$ok) return $empty;

    $xp = new DOMXPath($dom);
    $xp->registerNamespace('w', DOCX_NS);

    $bodyNodes = $xp->query('/w:document/w:body/*');
    if ($bodyNodes === false) return $empty;

    $blocks = [];
    $plainParts = [];
    foreach ($bodyNodes as $node) {
        if ($node->nodeName === 'w:p') {
            $block = extractDocxParagraph($node, $xp, $numbering);
            if ($block === null) continue;
            $blocks[] = $block;
            $plainParts[] = $block['plainText'];
        } elseif ($node->nodeName === 'w:tbl') {
            $block = extractDocxTable($node, $xp, $numbering);
            $blocks[] = $block;
            $plainParts[] = $block['plainText'];
        }
        // Other body-level nodes (w:sectPr, bookmarks, etc.) carry no
        // visible text and are intentionally skipped.
    }

    return ['blocks' => $blocks, 'plain' => trim(implode("\n", array_filter($plainParts, fn($p) => trim($p) !== '')))];
}

// Reads word/numbering.xml to map numId -> [ilvl => 'bullet'|'ordered']
// so list paragraphs render as real <ul>/<ol> instead of plain text.
function parseDocxNumbering($zip) {
    $xml = $zip->getFromName('word/numbering.xml');
    if ($xml === false) return [];

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $ok = $dom->loadXML($xml, LIBXML_NOENT);
    libxml_clear_errors();
    if (!$ok) return [];

    $xp = new DOMXPath($dom);
    $xp->registerNamespace('w', DOCX_NS);

    $abstract = [];
    foreach ($xp->query('//w:abstractNum') as $an) {
        $aid = $an->getAttribute('w:abstractNumId');
        $levels = [];
        foreach ($xp->query('w:lvl', $an) as $lvl) {
            $ilvl = $lvl->getAttribute('w:ilvl');
            $fmtNode = $xp->query('w:numFmt', $lvl)->item(0);
            $fmt = $fmtNode ? $fmtNode->getAttribute('w:val') : 'decimal';
            $levels[$ilvl] = ($fmt === 'bullet') ? 'bullet' : 'ordered';
        }
        $abstract[$aid] = $levels;
    }

    $result = [];
    foreach ($xp->query('//w:num') as $n) {
        $numId = $n->getAttribute('w:numId');
        $absNode = $xp->query('w:abstractNumId', $n)->item(0);
        $absId = $absNode ? $absNode->getAttribute('w:val') : null;
        $result[$numId] = $abstract[$absId] ?? [];
    }
    return $result;
}

// Extracts one <w:p> paragraph into a block descriptor: heading/list/plain
// tag, alignment, indentation, and an ordered list of runs (each run
// keeps its own bold/italic/underline flags and raw text, plus inline
// page-break markers positioned exactly where they occur in the flow).
function extractDocxParagraph($p, $xp, $numbering) {
    $styleNode = $xp->query('w:pPr/w:pStyle', $p)->item(0);
    $style = $styleNode ? $styleNode->getAttribute('w:val') : '';
    $tag = 'p';
    if (preg_match('/^Heading(\d)$/i', $style, $m)) {
        $tag = 'h' . min(6, max(1, (int)$m[1]));
    } elseif (stripos($style, 'Title') === 0) {
        $tag = 'h1';
    }

    $jcNode = $xp->query('w:pPr/w:jc', $p)->item(0);
    $align = $jcNode ? $jcNode->getAttribute('w:val') : '';
    $alignCss = ['center' => 'center', 'right' => 'right', 'both' => 'justify'][$align] ?? '';

    $indNode = $xp->query('w:pPr/w:ind', $p)->item(0);
    $indentPx = 0;
    if ($indNode) {
        $left = $indNode->getAttribute('w:left');
        if ($left === '') $left = $indNode->getAttribute('w:start');
        if ($left !== '') $indentPx = (int)round(((int)$left) / 20 * (4 / 3));
    }

    $list = null;
    $numPrNode = $xp->query('w:pPr/w:numPr', $p)->item(0);
    if ($numPrNode) {
        $ilvlNode = $xp->query('w:ilvl', $numPrNode)->item(0);
        $numIdNode = $xp->query('w:numId', $numPrNode)->item(0);
        $ilvl = $ilvlNode ? (int) $ilvlNode->getAttribute('w:val') : 0;
        $numId = $numIdNode ? $numIdNode->getAttribute('w:val') : null;
        $fmt = ($numId !== null && isset($numbering[$numId][(string)$ilvl])) ? $numbering[$numId][(string)$ilvl] : 'bullet';
        $list = ['ilvl' => $ilvl, 'ordered' => $fmt === 'ordered'];
    }

    $pageBreakBefore = $xp->query('w:pPr/w:pageBreakBefore', $p)->length > 0;

    // Walk runs in document order, splitting each run's content into
    // text vs. inline page-break markers so both stay in exact sequence.
    $segments = [];
    $plainText = '';
    foreach ($xp->query('.//w:r', $p) as $r) {
        $bFalse = $xp->query('w:rPr/w:b[@w:val="false" or @w:val="0" or @w:val="off"]', $r)->length > 0;
        $iFalse = $xp->query('w:rPr/w:i[@w:val="false" or @w:val="0" or @w:val="off"]', $r)->length > 0;
        $bold = !$bFalse && $xp->query('w:rPr/w:b', $r)->length > 0;
        $italic = !$iFalse && $xp->query('w:rPr/w:i', $r)->length > 0;
        $uNode = $xp->query('w:rPr/w:u', $r)->item(0);
        $underline = $uNode && $uNode->getAttribute('w:val') !== 'none';

        $buf = '';
        foreach ($xp->query('w:t|w:tab|w:br|w:cr', $r) as $child) {
            if ($child->nodeName === 'w:t') {
                $buf .= $child->textContent;
            } elseif ($child->nodeName === 'w:tab') {
                $buf .= "\t";
            } elseif ($child->nodeName === 'w:cr') {
                $buf .= "\n";
            } elseif ($child->nodeName === 'w:br') {
                $type = $child->getAttribute('w:type');
                if ($type === 'page' || $type === 'column') {
                    if ($buf !== '') {
                        $segments[] = ['text' => $buf, 'bold' => $bold, 'italic' => $italic, 'underline' => $underline];
                        $plainText .= $buf;
                        $buf = '';
                    }
                    $segments[] = ['pageBreak' => true];
                } else {
                    $buf .= "\n";
                }
            }
        }
        if ($buf !== '') {
            $segments[] = ['text' => $buf, 'bold' => $bold, 'italic' => $italic, 'underline' => $underline];
            $plainText .= $buf;
        }
    }

    if (empty($segments) && !$pageBreakBefore) return null; // fully empty paragraph, skip

    return [
        'type' => 'paragraph',
        'tag' => $tag,
        'align' => $alignCss,
        'indentPx' => $indentPx,
        'list' => $list,
        'pageBreakBefore' => $pageBreakBefore,
        'segments' => $segments,
        'plainText' => $plainText,
    ];
}

function extractDocxTable($tbl, $xp, $numbering) {
    $rows = [];
    $plainParts = [];
    foreach ($xp->query('w:tr', $tbl) as $tr) {
        $cells = [];
        foreach ($xp->query('w:tc', $tr) as $tc) {
            $spanNode = $xp->query('w:tcPr/w:gridSpan', $tc)->item(0);
            $colspan = $spanNode ? max(1, (int)$spanNode->getAttribute('w:val')) : 1;
            $paras = [];
            $cellPlain = [];
            foreach ($xp->query('w:p', $tc) as $p) {
                $block = extractDocxParagraph($p, $xp, $numbering);
                if ($block === null) continue;
                $paras[] = $block;
                $cellPlain[] = $block['plainText'];
            }
            $cells[] = ['paragraphs' => $paras, 'colspan' => $colspan];
            $plainParts[] = implode(' ', $cellPlain);
        }
        $rows[] = $cells;
    }
    return ['type' => 'table', 'rows' => $rows, 'plainText' => implode("\n", $plainParts)];
}

// ═══════════════════════════════════════════════════════════════
// VIEW FILE HANDLER — Serve uploaded file safely
// ═══════════════════════════════════════════════════════════════
if (isset($_GET['action']) && $_GET['action'] === 'view_file') {
    $aid = intval($_GET['id'] ?? 0);
    if ($aid <= 0) { http_response_code(404); exit('Invalid ID.'); }

    $stmt = $conn->prepare("SELECT file_path, upload_file, file_name FROM assignments WHERE assignment_id = ?");
    $stmt->bind_param("i", $aid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $relPath = $row ? getAssignmentRelativePath($row) : '';
    if (!$row || empty($relPath)) {
        http_response_code(404);
        exit('File not found in database.');
    }

    $real_path = resolveFilePath($relPath);
    if ($real_path === false || !file_exists($real_path)) {
        http_response_code(404);
        exit('The uploaded assignment file could not be found. Please upload the file again.');
    }

    $file_name = $row['file_name'] ?? basename($real_path);
    $ext = strtolower(pathinfo($real_path, PATHINFO_EXTENSION));

    $mime_map = [
        'pdf'  => 'application/pdf',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'txt'  => 'text/plain',
        'csv'  => 'text/csv',
        'md'   => 'text/markdown',
    ];
    $mime = $mime_map[$ext] ?? 'application/octet-stream';

    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . addslashes($file_name) . '"');
    header('Content-Length: ' . filesize($real_path));
    header('Cache-Control: no-cache');
    readfile($real_path);
    exit;
}

// ═══════════════════════════════════════════════════════════════
// DOWNLOAD REPORT HANDLER — Admin downloads the generated AI report
// ═══════════════════════════════════════════════════════════════
if (isset($_GET['action']) && $_GET['action'] === 'download_report') {
    $aid = intval($_GET['id'] ?? 0);
    if ($aid <= 0) { http_response_code(404); exit('Invalid assignment ID.'); }

    $stmt = $conn->prepare("SELECT processed_file, file_name FROM assignments WHERE assignment_id = ?");
    $stmt->bind_param("i", $aid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || empty($row['processed_file'])) {
        http_response_code(404);
        exit('No report has been generated for this assignment yet.');
    }

    // Reports only ever live under reports/, so validate against that folder
    $rel = ltrim($row['processed_file'], '/\\');
    if (strpos($rel, '..') !== false || strpos($rel, 'reports/') !== 0) {
        http_response_code(400);
        exit('Invalid report path.');
    }
    $report_abs = realpath(__DIR__ . '/' . $rel);
    $reports_base = realpath(__DIR__ . '/reports/');
    if ($report_abs === false || $reports_base === false || strpos($report_abs, $reports_base) !== 0 || !file_exists($report_abs)) {
        http_response_code(404);
        exit('Report file not found. Please re-run the AI analysis.');
    }

    // Base the downloaded report's file name on the student's original
    // submitted file name (not a generic "Assignment_5" label), so what
    // they see matches what they actually uploaded.
    $orig_name = $row['file_name'] ?? ('Assignment_' . $aid);
    $base_name = sanitizeFilenameBase(pathinfo($orig_name, PATHINFO_FILENAME));
    if ($base_name === '') $base_name = 'Assignment_' . $aid;
    $download_name = $base_name . '_AI_Report.html';

    // Serve inline (not as a forced .html attachment) with autoprint=1 so the
    // browser opens its native print dialog immediately — choosing "Save as
    // PDF" there produces a genuine PDF of the report, images/stamp/logo and
    // all, without needing any server-side PDF library.
    //
    // IMPORTANT: browsers take the *suggested filename* for "Save as PDF"
    // from the document's <title>, not from this Content-Disposition header
    // (that header only matters for a raw .html download). buildReportHTML()
    // sets <title> to this exact same "{name}_AI_Report" value so the final
    // saved PDF is always named e.g. "Environmental Essay_AI_Report.pdf" —
    // matching the original upload name — instead of every report saving
    // under the same generic name.
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: inline; filename="' . addslashes($download_name) . '"');
    header('Cache-Control: no-cache');
    $report_contents = file_get_contents($report_abs);
    $report_contents .= '<script>window.addEventListener("load",function(){setTimeout(function(){window.print();},700);});</script>';
    echo $report_contents;
    exit;
}

// ─── MARK ALL NOTIFICATIONS AS READ ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_all_read') {
    $conn->query("UPDATE notifications SET is_read = 1 WHERE user_id IS NULL AND is_read = 0");
    header("Location: " . basename($_SERVER['PHP_SELF']) . (isset($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit;
}

// ═══════════════════════════════════════════════════════════════
// DELETE ASSIGNMENT HANDLER (AJAX)
// ═══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_assignment') {
    header('Content-Type: application/json; charset=utf-8');
    $aid = intval($_POST['assignment_id'] ?? 0);

    if ($aid <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid assignment ID.']);
        exit;
    }

    // Get file info before deleting
    $stmt = $conn->prepare("SELECT file_path, upload_file, file_name FROM assignments WHERE assignment_id = ?");
    $stmt->bind_param("i", $aid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Assignment not found.']);
        exit;
    }

    // Delete physical file
    $file_deleted = true;
    $relPath = getAssignmentRelativePath($row);
    if (!empty($relPath)) {
        $real_path = resolveFilePath($relPath);
        if ($real_path !== false && file_exists($real_path)) {
            $file_deleted = unlink($real_path);
        }
    }

    // Delete any related report file (covers both old .php and new .html reports)
    foreach (['.html', '.php'] as $ext) {
        $report_path = __DIR__ . '/reports/report_' . $aid . $ext;
        if (file_exists($report_path)) {
            unlink($report_path);
        }
    }

    // Delete related reviews
    $stmt = $conn->prepare("DELETE FROM assignment_reviews WHERE assignment_id = ?");
    $stmt->bind_param("i", $aid);
    $stmt->execute();
    $stmt->close();

    // Delete assignment record
    $stmt = $conn->prepare("DELETE FROM assignments WHERE assignment_id = ?");
    $stmt->bind_param("i", $aid);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok) {
        echo json_encode(['success' => true, 'message' => 'Assignment deleted successfully.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Unable to delete assignment. Please try again.']);
    }
    exit;
}

// ═══════════════════════════════════════════════════════════════
// COMPANY CONFIG
// ═══════════════════════════════════════════════════════════════
 $company_name = "AI Checker";
 $company_tagline = "AI-Powered Assignment Checking System";

// Embed the logo as a base64 data URI so the report is a single,
// fully self-contained file (logo still displays even if the
// report is downloaded and opened somewhere else, offline).
 $company_logo_data = '';
 $logo_file = __DIR__ . '/image/logo.png';
if (file_exists($logo_file)) {
    $logo_bin = @file_get_contents($logo_file);
    if ($logo_bin !== false) {
        $company_logo_data = 'data:image/png;base64,' . base64_encode($logo_bin);
    }
}

// Embed the official verification stamp (image/stamp.png) as a base64 data
// URI too, so every generated report carries the exact same stamp graphic
// used across the platform — no CSS-drawn substitute.
 $company_stamp_data = '';
 $stamp_file = __DIR__ . '/image/stamp.png';
if (file_exists($stamp_file)) {
    $stamp_bin = @file_get_contents($stamp_file);
    if ($stamp_bin !== false) {
        $company_stamp_data = 'data:image/png;base64,' . base64_encode($stamp_bin);
    }
}

// ═══════════════════════════════════════════════════════════════
// AJAX HANDLER — START AI ANALYSIS (FIXED FILE PATH)
// ═══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'start_analysis') {
    header('Content-Type: application/json; charset=utf-8');
    $aid = intval($_POST['assignment_id'] ?? 0);

    if ($aid <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid assignment ID.']);
        exit;
    }

    $stmt = $conn->prepare("SELECT * FROM assignments WHERE assignment_id = ?");
    $stmt->bind_param("i", $aid);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) {
        $stmt->close();
        echo json_encode(['success' => false, 'message' => 'Assignment not found in database.']);
        exit;
    }
    $assignment = $res->fetch_assoc();
    $stmt->close();

    // ★ FIXED: Resolve file path correctly (with legacy upload_file fallback)
    $file_path_db = getAssignmentRelativePath($assignment);
    if (empty($file_path_db)) {
        echo json_encode(['success' => false, 'message' => 'No file has been uploaded for this assignment yet.']);
        exit;
    }

    $abs_file_path = resolveFilePath($file_path_db);
    if ($abs_file_path === false || !file_exists($abs_file_path)) {
        echo json_encode(['success' => false, 'message' => 'The uploaded assignment file could not be found. Please upload the file again.']);
        exit;
    }

    $ext = strtolower(pathinfo($abs_file_path, PATHINFO_EXTENSION));

    $docx_blocks = null;
    if ($ext === 'pdf') {
        $raw_content = extractPdfText($abs_file_path);
    } elseif ($ext === 'docx') {
        // Try the high-fidelity structured path first (real headings, lists,
        // tables, indentation, page breaks). Fall back to the flat-text
        // extractor only if structured parsing fails or finds no content.
        $structured = extractDocxStructured($abs_file_path);
        if (!empty($structured['blocks'])) {
            $docx_blocks = $structured['blocks'];
            $raw_content = $structured['plain'];
        } else {
            $raw_content = extractDocxText($abs_file_path);
        }
    } else {
        $raw_content = file_get_contents($abs_file_path);
    }

    // Guarantee clean, valid UTF-8 before this text touches the report at
    // all — this is what actually fixes the corrupted-PDF symptom (random
    // letters / square boxes), since it forces every extraction path
    // (PDF, DOCX, plain text) through the same encoding-safe pipeline.
    $raw_content = sanitizeExtractedText($raw_content);

    if ($raw_content === false || empty(trim($raw_content))) {
        $raw_content = "[No readable text could be extracted from this file — it may be a scanned/image-based document.]\n\nFile: " . htmlspecialchars($assignment['file_name'] ?? basename($abs_file_path)) . "\nSize: " . filesize($abs_file_path) . " bytes\nType: " . $ext;
    }

    // ═══════════════════════════════════════════════════════════
    // ORIGINAL FILE FINGERPRINT + EMBEDDABLE COPY
    // The report must always reference (and, where practical, show)
    // the *exact* uploaded file — not a re-typed or reformatted
    // version — so we hash the exact bytes on disk and, for
    // reasonably sized PDFs/DOCX files, embed the exact bytes so the
    // report can render the original with its original formatting,
    // images, tables, headers/footers, fonts and page layout intact.
    // ═══════════════════════════════════════════════════════════
    $original_file_size = filesize($abs_file_path);
    $original_file_hash = @hash_file('sha256', $abs_file_path) ?: '';
    $original_file_data = '';
    $embed_limit_bytes = 20 * 1024 * 1024; // 20MB cap so huge uploads don't bloat the report
    if ($original_file_size > 0 && $original_file_size <= $embed_limit_bytes && ($ext === 'pdf' || $ext === 'docx')) {
        $orig_bin = @file_get_contents($abs_file_path);
        if ($orig_bin !== false) {
            $orig_mime = $ext === 'pdf'
                ? 'application/pdf'
                : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
            $original_file_data = 'data:' . $orig_mime . ';base64,' . base64_encode($orig_bin);
        }
    }

    $stmt = $conn->prepare("UPDATE assignments SET status = 'Processing' WHERE assignment_id = ?");
    $stmt->bind_param("i", $aid);
    $stmt->execute();
    $stmt->close();

    $analysis = analyzeContent($raw_content);

    $reports_dir = __DIR__ . '/reports/';
    if (!is_dir($reports_dir)) {
        mkdir($reports_dir, 0755, true);
    }
    $report_filename = 'report_' . $aid . '.html';
    $report_path = $reports_dir . $report_filename;
    $report_web_path = 'reports/' . $report_filename;

    $user_name = 'Unknown';
    $user_email = '';
    if (!empty($assignment['user_id'])) {
        $urow = $conn->prepare("SELECT name, email FROM users WHERE user_id = ?");
        $urow->bind_param("i", $assignment['user_id']);
        $urow->execute();
        $ufetch = $urow->get_result()->fetch_assoc();
        $urow->close();
        if ($ufetch) {
            $user_name = $ufetch['name'];
            $user_email = $ufetch['email'] ?? '';
        }
    }

    $report_html = buildReportHTML($assignment, $user_name, $user_email, $analysis, $raw_content, $company_name, $company_tagline, $company_logo_data, $company_stamp_data, [
        'ext' => $ext,
        'size' => $original_file_size,
        'hash' => $original_file_hash,
        'data' => $original_file_data,
    ], $docx_blocks);
    $write_ok = file_put_contents($report_path, $report_html);

    if ($write_ok === false) {
        $stmt = $conn->prepare("UPDATE assignments SET status = 'Pending' WHERE assignment_id = ?");
        $stmt->bind_param("i", $aid);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['success' => false, 'message' => 'Failed to write report file. Check /reports/ folder permissions.']);
        exit;
    }

    $stmt = $conn->prepare("UPDATE assignments SET status = 'Completed', ai_score = ?, ai_feedback = ?, processed_file = ? WHERE assignment_id = ?");
    $stmt->bind_param("dssi", $analysis['score'], $analysis['feedback'], $report_web_path, $aid);
    $stmt->execute();
    $stmt->close();

    createAssignmentNotification($conn, $aid, 'AI analysis for your assignment "{title}" has been completed. Your result is ready to view.');

    echo json_encode([
        'success' => true,
        'assignment_id' => $aid,
        'score' => $analysis['score'],
        'risk' => $analysis['risk'],
        'feedback' => $analysis['feedback'],
        'keywords' => $analysis['keywords'],
        'report_path' => $report_web_path,
        'stats' => [
            'word_count' => $analysis['word_count'],
            'sentence_count' => $analysis['sentence_count'],
            'paragraph_count' => $analysis['paragraph_count'],
            'keyword_count' => count($analysis['keywords']),
            'transition_count' => $analysis['transition_count']
        ]
    ]);
    exit;
}

// ═══════════════════════════════════════════════════════════════
// GET ASSIGNMENT ID (Single View)
// ═══════════════════════════════════════════════════════════════
 $assignment_id = intval($_GET['id'] ?? 0);
 $assignment = null;
 $user_name = 'Unknown';
 $is_completed = false;
 $is_processing = false;
 $existing_score = null;
 $existing_feedback = '';
 $existing_report = '';
 $existing_risk = '';
 $file_exists_on_disk = false;
 $real_file_path = '';

if ($assignment_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM assignments WHERE assignment_id = ?");
    $stmt->bind_param("i", $assignment_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows > 0) {
        $assignment = $res->fetch_assoc();
        if (!empty($assignment['user_id'])) {
            $urow = $conn->prepare("SELECT name FROM users WHERE user_id = ?");
            $urow->bind_param("i", $assignment['user_id']);
            $urow->execute();
            $ufetch = $urow->get_result()->fetch_assoc();
            $urow->close();
            if ($ufetch) $user_name = $ufetch['name'];
        }
        $is_completed = (strtolower($assignment['status'] ?? 'pending') === 'completed' && isset($assignment['ai_score']) && $assignment['ai_score'] !== null);
        $is_processing = (strtolower($assignment['status'] ?? 'pending') === 'processing');
        $existing_score = (isset($assignment['ai_score']) && $assignment['ai_score'] !== null) ? floatval($assignment['ai_score']) : null;
        $existing_feedback = $assignment['ai_feedback'] ?? '';
        $existing_report = $assignment['processed_file'] ?? '';
        $existing_risk = $existing_score !== null ? ($existing_score <= 20 ? 'Low' : ($existing_score <= 50 ? 'Medium' : 'High')) : '';

        // ★ Check if file actually exists on disk (with legacy upload_file fallback)
        $relPathCheck = getAssignmentRelativePath($assignment);
        if (!empty($relPathCheck)) {
            $real_file_path = resolveFilePath($relPathCheck);
            $file_exists_on_disk = ($real_file_path !== false);
        }
    }
    $stmt->close();
}

// ═══════════════════════════════════════════════════════════════
// FETCH ALL ASSIGNMENTS FOR DASHBOARD
// ═══════════════════════════════════════════════════════════════
 $all_assignments = [];
 $total_all = 0;
 $completed_count = 0;
 $pending_count = 0;
 $processing_count = 0;
 $searchQuery = trim($_GET['search'] ?? '');
 $filterStatus = trim($_GET['filter'] ?? '');

if ($assignment_id <= 0) {
    $whereClause = "WHERE 1=1";
    $params = [];
    $types = "";

    if (!empty($searchQuery)) {
        $whereClause .= " AND (a.file_name LIKE ? OR u.name LIKE ? OR CAST(a.assignment_id AS CHAR) LIKE ?)";
        $sw = "%" . $searchQuery . "%";
        $params[] = $sw; $params[] = $sw; $params[] = $sw;
        $types .= "sss";
    }
    if (!empty($filterStatus) && in_array($filterStatus, ['Pending', 'Processing', 'Completed'])) {
        $whereClause .= " AND a.status = ?";
        $params[] = $filterStatus;
        $types .= "s";
    }

    $sql = "SELECT a.*, u.name as user_name FROM assignments a LEFT JOIN users u ON a.user_id = u.user_id $whereClause ORDER BY a.assignment_id DESC";
    $stmt = $conn->prepare($sql);
    if (!empty($types)) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        // ★ Check file existence for each row (with legacy upload_file fallback)
        $row['_file_exists'] = false;
        $relPathRow = getAssignmentRelativePath($row);
        if (!empty($relPathRow)) {
            $row['_file_exists'] = (resolveFilePath($relPathRow) !== false);
        }
        $all_assignments[] = $row;
    }
    $stmt->close();

    $total_all = count($all_assignments);
    foreach ($all_assignments as $a) {
        $s = strtolower($a['status'] ?? 'pending');
        if ($s === 'completed') $completed_count++;
        elseif ($s === 'processing') $processing_count++;
        else $pending_count++;
    }
}

// ═══════════════════════════════════════════════════════════════
// FETCH ADMIN DATA
// ═══════════════════════════════════════════════════════════════
 $admin_query = $conn->prepare("SELECT user_id, name, email, avatar, created_at FROM users WHERE user_id = ? AND user_type = 'admin'");
 $admin_query->bind_param("i", $admin_id);
 $admin_query->execute();
 $admin_res = $admin_query->get_result();
if ($admin_res->num_rows === 0) die("Admin account not found.");
 $admin_data = $admin_res->fetch_assoc();
 $admin_query->close();
 $admin_name = $admin_data['name'] ?? 'Admin';
 $admin_email = $admin_data['email'] ?? '';
 $db_avatar = $admin_data['avatar'] ?? 'default.png';
if (!empty($db_avatar) && $db_avatar !== 'default.png') {
    if (filter_var($db_avatar, FILTER_VALIDATE_URL)) { $avatar = $db_avatar; }
    else { $avatar = "https://api.dicebear.com/7.x/avataaars/svg?seed=" . urlencode($db_avatar) . "&backgroundColor=ede9fe"; }
} else {
    $avatar = "https://api.dicebear.com/7.x/avataaars/svg?seed=Admin&backgroundColor=ede9fe";
}

function time_ago($datetime) {
    $now = new DateTime(); $ago = new DateTime($datetime); $diff = $now->diff($ago);
    if ($diff->y > 0) return $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ago';
    if ($diff->m > 0) return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
    if ($diff->d > 0) return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
    if ($diff->h > 0) return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
    if ($diff->i > 0) return $diff->i . ' min ago';
    return 'Just now';
}

 $unread_count = 0; $notifications = [];
 $noti_query = $conn->prepare("SELECT * FROM notifications WHERE user_id IS NULL ORDER BY created_at DESC LIMIT 30");
 $noti_query->execute();
 $noti_res = $noti_query->get_result();
if ($noti_res->num_rows > 0) {
    while ($row = $noti_res->fetch_assoc()) {
        $notifications[] = $row;
        if ($row['is_read'] == 0) $unread_count++;
    }
}
 $noti_query->close();
 $conn->close();

// ═══════════════════════════════════════════════════════════════
// Build a safe, human-readable file name base from the student's
// ORIGINAL uploaded file name. Previously this stripped every
// character outside A-Za-z0-9, which silently collapsed any name
// containing Malay diacritics, punctuation, or other non-ASCII
// characters down to a blank string — falling back to a generic
// "Assignment_N" label so every such report *looked* like it had
// the same auto-generated name. This keeps letters/numbers/marks
// from any language (Unicode-aware), spaces, hyphens, underscores
// and parentheses, so "Karangan Alam Sekitar.docx" stays
// recognisable instead of becoming "Assignment_7".
// ═══════════════════════════════════════════════════════════════
function sanitizeFilenameBase($name) {
    $name = sanitizeExtractedText((string)$name);
    $name = preg_replace('/[^\p{L}\p{N} _\-\(\)]+/u', '', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    return trim($name);
}

function esc($v) {
    // Previously: htmlspecialchars($v ?? '') with no explicit charset/flags.
    // On non-UTF-8 input (common with extracted PDF/DOCX text and file
    // names containing accented/Malay characters) this either silently
    // returned an empty string or mis-rendered bytes — part of the PDF
    // corruption bug. Route through the same sanitizer + explicit UTF-8 +
    // ENT_SUBSTITUTE used elsewhere in the report so behaviour is consistent.
    return htmlspecialchars(sanitizeExtractedText((string)($v ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ═══════════════════════════════════════════════════════════════
// AI ANALYSIS ENGINE (Simulated)
// ═══════════════════════════════════════════════════════════════
// ═══════════════════════════════════════════════════════════════
// SHARED AI-INDICATOR WORD LISTS
// Pulled out into their own functions so the scoring logic
// (analyzeContent) and the report highlighter (highlightAISentences)
// always flag on the exact same words/phrases — no drift between
// what earns points and what gets highlighted.
// ═══════════════════════════════════════════════════════════════
function getAiTransitions() {
    return ['furthermore','moreover','additionally','consequently','nevertheless','nonetheless','accordingly','subsequently','thereafter','henceforth','notably','importantly','significantly','interestingly','underscoring','delving','comprehensive','multifaceted','paramount','crucial','pivotal','intricate','nuanced','elucidate','demonstrate','illustrate','underscore','facilitate','leverage','utilize','pertaining','inasmuch','wherein','thereby','thusly'];
}
function getAiPhrases() {
    return ['in conclusion','in summary','to summarize','it is important to note','it is worth noting','as mentioned earlier','as previously stated','plays a crucial role','serves as a','shed light on'];
}
function getHighlightKeywords() {
    return ['analysis','method','result','conclusion','introduction','discussion','hypothesis','data','research','findings','objective','literature','review','abstract','reference','theory','experiment','survey','sample','variable','framework','approach','algorithm','model','evaluation','implementation','observation','interpretation','limitation','recommendation','summary','furthermore','moreover','additionally','consequently','nevertheless','utilize','elucidate','comprehensive','paramount','crucial'];
}

function analyzeContent($content) {
    $words = array_filter(preg_split('/[\s]+/', trim($content)));
    $wordCount = count($words);
    $sentences = array_filter(preg_split('/[.!?]+/', $content));
    $sentenceCount = count($sentences);
    $paragraphs = array_filter(preg_split('/[\r\n]{2,}/', trim($content)));
    $paragraphCount = count($paragraphs);
    $lowerContent = strtolower($content);
    $score = 0;

    $aiTransitions = getAiTransitions();
    $transitionCount = 0;
    foreach ($aiTransitions as $t) { $transitionCount += substr_count($lowerContent, $t); }
    if ($transitionCount > 12) $score += 28;
    elseif ($transitionCount > 7) $score += 18;
    elseif ($transitionCount > 3) $score += 10;
    elseif ($transitionCount > 0) $score += 4;

    if ($sentenceCount > 3) {
        $sLengths = array_map(function($s) { return count(array_filter(preg_split('/[\s]+/', $s))); }, $sentences);
        $avgLen = array_sum($sLengths) / count($sLengths);
        $variance = array_sum(array_map(function($l) use ($avgLen) { return pow($l - $avgLen, 2); }, $sLengths)) / count($sLengths);
        $stdDev = sqrt(max(0, $variance));
        $cv = $avgLen > 0 ? ($stdDev / $avgLen) * 100 : 100;
        if ($cv < 20) $score += 22; elseif ($cv < 35) $score += 14; elseif ($cv < 50) $score += 6;
    }

    $personalPronouns = [' i ',' i\'',' my ',' me ',' we ',' our ',' us ',' i\'ve ',' i\'ll ',' i\'m '];
    $personalCount = 0;
    foreach ($personalPronouns as $p) { $personalCount += substr_count($lowerContent, $p); }
    if ($personalCount === 0 && $wordCount > 80) $score += 18;
    elseif ($personalCount < 2 && $wordCount > 80) $score += 10;
    else $score -= 6;

    if ($paragraphCount > 2) {
        $pLens = array_map(function($p) { return strlen(trim($p)); }, $paragraphs);
        $pAvg = array_sum($pLens) / count($pLens);
        $pVar = array_sum(array_map(function($l) use ($pAvg) { return pow($l - $pAvg, 2); }, $pLens)) / count($pLens);
        $pCV = $pAvg > 0 ? (sqrt(max(0,$pVar)) / $pAvg) * 100 : 100;
        if ($pCV < 25) $score += 14; elseif ($pCV < 40) $score += 7;
    }

    $uniqueWords = count(array_unique(array_map('strtolower', $words)));
    $ttr = $wordCount > 0 ? ($uniqueWords / $wordCount) * 100 : 0;
    if ($ttr > 72 && $wordCount > 100) $score += 10; elseif ($ttr > 62 && $wordCount > 100) $score += 5;

    $avgSL = $sentenceCount > 0 ? $wordCount / $sentenceCount : 0;
    if ($avgSL > 28) $score += 8; elseif ($avgSL > 22) $score += 4;
    if ($wordCount < 40) $score += 12; elseif ($wordCount < 80) $score += 5;

    $phrases = getAiPhrases();
    $phraseHits = 0;
    foreach ($phrases as $ph) { $phraseHits += substr_count($lowerContent, $ph); }
    if ($phraseHits > 4) $score += 12; elseif ($phraseHits > 2) $score += 7; elseif ($phraseHits > 0) $score += 3;

    $score = max(0, min(100, round($score)));

    $highlightKeywords = getHighlightKeywords();
    $foundKeywords = [];
    foreach ($highlightKeywords as $kw) { if (strpos($lowerContent, $kw) !== false) $foundKeywords[] = $kw; }

    $risk = $score <= 20 ? 'Low' : ($score <= 50 ? 'Medium' : 'High');
    $feedback = buildFeedback($score, $wordCount, $sentenceCount, $paragraphCount, $transitionCount, $personalCount, $foundKeywords);

    return ['score'=>$score,'risk'=>$risk,'feedback'=>$feedback,'keywords'=>$foundKeywords,'word_count'=>$wordCount,'sentence_count'=>$sentenceCount,'paragraph_count'=>$paragraphCount,'transition_count'=>$transitionCount,'personal_pronoun_count'=>$personalCount];
}

function buildFeedback($score, $wc, $sc, $pc, $tc, $ppc, $kws) {
    $lines = [];
    if ($score <= 20) { $lines[] = "The assignment appears to be predominantly human-written content."; $lines[] = "Structural patterns, vocabulary usage, and sentence variability are consistent with natural writing."; }
    elseif ($score <= 50) { $lines[] = "The assignment shows moderate indicators that may suggest AI-assisted writing."; $lines[] = "Some patterns (transition word frequency, sentence uniformity) warrant closer review."; }
    else { $lines[] = "The assignment exhibits strong indicators consistent with AI-generated content."; $lines[] = "Multiple structural and linguistic patterns suggest significant AI involvement."; }
    $lines[] = "";
    $lines[] = "Content Statistics: $wc words, $sc sentences, $pc paragraphs.";
    if ($tc > 0) $lines[] = "Detected $tc AI-typical transition/filler word usage(s).";
    if ($ppc === 0 && $wc > 80) $lines[] = "No personal pronouns found — this is uncommon in student writing.";
    if (count($kws) > 0) $lines[] = count($kws) . " academic keywords identified and highlighted in the report.";
    if ($wc < 80) $lines[] = "Warning: Very short content may affect analysis reliability.";
    return implode("\n", $lines);
}

// ═══════════════════════════════════════════════════════════════
// HIGHLIGHT AI-FLAGGED SENTENCES (whole sentences, not single words)
// Used for the plain-text fallback path (PDF/txt/csv/md, or DOCX if
// structured extraction isn't available). Walks the ORIGINAL content
// exactly as extracted — same characters, same spacing, same line
// breaks — and wraps entire flagged sentences in <mark>. Nothing about
// the underlying text is rewritten, only marked up. Shares its sentence
// detection with the DOCX structured renderer via getFlaggedRanges().
// ═══════════════════════════════════════════════════════════════
function highlightAISentences($content) {
    $ranges = getFlaggedRanges($content);
    $len = strlen($content);
    $out = '';
    $cursor = 0;
    foreach ($ranges as [$start, $end]) {
        if ($start > $cursor) $out .= renderTextExact(substr($content, $cursor, $start - $cursor));
        $out .= '<mark class="ai-hl">' . renderTextExact(substr($content, $start, $end - $start)) . '</mark>';
        $cursor = $end;
    }
    if ($cursor < $len) $out .= renderTextExact(substr($content, $cursor));
    return $out;
}

// htmlspecialchars() can return an empty string when fed bytes that
// aren't valid UTF-8 (common with raw PDF/DOCX text extraction) unless
// told to substitute instead of bailing out. This wrapper guarantees a
// usable string either way.
function safeHtml($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Returns a list of [startByteOffset, endByteOffset) ranges within $text
// marking whole sentences that contain an AI-indicator word/phrase.
// Byte offsets (not character offsets) are used throughout and deliberately
// never mixed with mb_*-based indexing, so multi-byte UTF-8 text still maps
// back onto the original runs correctly.
function getFlaggedRanges($text) {
    if (trim($text) === '') return [];
    $indicators = array_merge(getAiTransitions(), getAiPhrases(), getHighlightKeywords());
    $ranges = [];
    // Matches a run of non-terminator characters ending in ./!/? plus any
    // trailing whitespace, OR a final trailing fragment with no terminator.
    if (!preg_match_all('/[^.!?]*[.!?]+\s*|[^.!?]+$/', $text, $m, PREG_OFFSET_CAPTURE)) {
        return [];
    }
    foreach ($m[0] as [$sentence, $offset]) {
        if (trim($sentence) === '') continue;
        $lower = strtolower($sentence);
        foreach ($indicators as $ind) {
            if (strpos($lower, $ind) !== false) {
                $ranges[] = [$offset, $offset + strlen($sentence)];
                break;
            }
        }
    }
    return $ranges;
}

// Escapes text for HTML output while preserving it exactly as extracted:
// newlines become <br>, and all runs of spaces/tabs are left completely
// untouched (the container is rendered with white-space:pre-wrap so the
// browser displays every space rather than collapsing them).
function renderTextExact($text) {
    $text = safeHtml($text);
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    return str_replace("\n", '<br>', $text);
}

// Renders one paragraph's ordered run/page-break segments to HTML, wrapping
// only whole AI-flagged sentences in <mark>. Highlight ranges are computed
// once over the paragraph's full concatenated plain text, then mapped back
// onto each individual run so formatting (bold/italic/underline) and
// highlighting can coexist without ever splitting a word or breaking a tag.
function renderDocxSegments($segments, $plainText) {
    $flaggedRanges = getFlaggedRanges($plainText);
    $html = '';
    $cursor = 0; // running byte offset into $plainText

    foreach ($segments as $seg) {
        if (!empty($seg['pageBreak'])) {
            $html .= '<div class="doc-page-break"><span>Page Break</span></div>';
            continue;
        }

        $text = $seg['text'];
        $len = strlen($text);
        $segStart = $cursor;
        $segEnd = $cursor + $len;
        $cursor = $segEnd;

        // Collect breakpoints inside this run from any overlapping flagged range.
        $points = [0, $len];
        foreach ($flaggedRanges as [$rs, $re]) {
            if ($re <= $segStart || $rs >= $segEnd) continue;
            $points[] = max(0, $rs - $segStart);
            $points[] = min($len, $re - $segStart);
        }
        $points = array_values(array_unique($points));
        sort($points, SORT_NUMERIC);

        $runHtml = '';
        for ($i = 0; $i < count($points) - 1; $i++) {
            $a = $points[$i];
            $b = $points[$i + 1];
            if ($b <= $a) continue;
            $piece = substr($text, $a, $b - $a);
            $midAbs = $segStart + $a + intdiv($b - $a, 2);
            $flagged = false;
            foreach ($flaggedRanges as [$rs, $re]) {
                if ($midAbs >= $rs && $midAbs < $re) { $flagged = true; break; }
            }
            $rendered = renderTextExact($piece);
            $runHtml .= $flagged ? ('<mark class="ai-hl">' . $rendered . '</mark>') : $rendered;
        }

        if (!empty($seg['bold'])) $runHtml = '<strong>' . $runHtml . '</strong>';
        if (!empty($seg['italic'])) $runHtml = '<em>' . $runHtml . '</em>';
        if (!empty($seg['underline'])) $runHtml = '<u>' . $runHtml . '</u>';
        $html .= $runHtml;
    }

    return $html;
}

// Renders a single structured paragraph block (heading/plain/list item) to HTML.
function renderDocxParagraphBlock($block) {
    $styleParts = [];
    if ($block['align'] !== '') $styleParts[] = 'text-align:' . $block['align'];
    if ($block['indentPx'] > 0) $styleParts[] = 'margin-left:' . $block['indentPx'] . 'px';
    $style = $styleParts ? ' style="' . implode(';', $styleParts) . '"' : '';

    $inner = renderDocxSegments($block['segments'], $block['plainText']);
    if (trim($block['plainText']) === '' && strpos($inner, 'doc-page-break') === false) {
        $inner = '&nbsp;'; // preserve genuinely blank paragraphs as spacing, not vanish them
    }

    $prefix = $block['pageBreakBefore'] ? '<div class="doc-page-break"><span>Page Break</span></div>' : '';
    return $prefix . '<' . $block['tag'] . $style . '>' . $inner . '</' . $block['tag'] . '>';
}

function renderDocxTableBlock($table) {
    $html = '<table class="doc-table">';
    foreach ($table['rows'] as $row) {
        $html .= '<tr>';
        foreach ($row as $cell) {
            $colspan = $cell['colspan'] > 1 ? ' colspan="' . (int)$cell['colspan'] . '"' : '';
            $cellHtml = '';
            foreach ($cell['paragraphs'] as $p) {
                $cellHtml .= renderDocxParagraphBlock($p);
            }
            if ($cellHtml === '') $cellHtml = '&nbsp;';
            $html .= '<td' . $colspan . '>' . $cellHtml . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</table>';
    return $html;
}

// Top-level: renders the full list of extracted blocks (paragraphs, headings,
// lists, tables, page breaks) into one HTML fragment, grouping consecutive
// list-item paragraphs into real nested <ul>/<ol> elements by indent level.
function renderDocxStructuredHTML($blocks) {
    $html = '';
    $listStack = []; // stack of ['ordered'=>bool, 'ilvl'=>int]

    $closeListsTo = function($targetDepth) use (&$listStack, &$html) {
        while (count($listStack) > $targetDepth) {
            $top = array_pop($listStack);
            $html .= $top['ordered'] ? '</ol>' : '</ul>';
        }
    };

    foreach ($blocks as $block) {
        if ($block['type'] === 'table') {
            $closeListsTo(0);
            $html .= renderDocxTableBlock($block);
            continue;
        }

        if ($block['type'] === 'paragraph' && $block['list']) {
            $ilvl = $block['list']['ilvl'];
            $ordered = $block['list']['ordered'];
            $depth = $ilvl + 1;

            // Close deeper/mismatched levels, open new ones as needed.
            $closeListsTo(min($depth, count($listStack)));
            while (count($listStack) < $depth) {
                $listStack[] = ['ordered' => $ordered, 'ilvl' => $ilvl];
                $html .= $ordered ? '<ol>' : '<ul>';
            }
            $prefix = $block['pageBreakBefore'] ? '<div class="doc-page-break"><span>Page Break</span></div>' : '';
            $html .= $prefix . '<li>' . renderDocxSegments($block['segments'], $block['plainText']) . '</li>';
            continue;
        }

        $closeListsTo(0);
        $html .= renderDocxParagraphBlock($block);
    }
    $closeListsTo(0);

    return $html;
}



// ═══════════════════════════════════════════════════════════════
// BUILD REPORT HTML
// ═══════════════════════════════════════════════════════════════
function buildReportHTML($assignment, $user_name, $user_email, $analysis, $raw_content, $company_name, $company_tagline, $logo_data, $stamp_data, $original_file = [], $docx_blocks = null) {
    $score = $analysis['score']; $risk = $analysis['risk'];
    $feedback = esc($analysis['feedback']); $keywords = $analysis['keywords'];
    $file_name = esc($assignment['file_name'] ?? basename($assignment['file_path'] ?? 'Unknown'));
    $user_id = esc($assignment['user_id'] ?? '');
    $user_name_esc = esc($user_name);
    $user_email_esc = esc($user_email);
    $a_id = (int)$assignment['assignment_id'];
    $date_now = date('F j, Y \a\t g:i A');
    $report_date_ymd = date('Ymd');
    $report_id = 'RPT-' . $a_id . '-' . $report_date_ymd;

    // Must match sanitizeFilenameBase()'s output in the download_report
    // handler exactly — this becomes the <title>, which is what browsers
    // actually use as the suggested filename when the user chooses
    // "Save as PDF" from the print dialog (Content-Disposition headers
    // are ignored for that flow). Keeping the two in sync is what makes
    // the saved PDF come out named e.g. "Environmental Essay_AI_Report.pdf"
    // instead of every report suggesting the same generic name.
    $pdf_name_base = sanitizeFilenameBase(pathinfo($assignment['file_name'] ?? ('Assignment_' . $a_id), PATHINFO_FILENAME));
    if ($pdf_name_base === '') $pdf_name_base = 'Assignment_' . $a_id;
    $pdf_title_esc = esc($pdf_name_base . '_AI_Report');

    if ($score <= 20) { $scoreColor='#388E3C'; $scoreBg='rgba(56,142,60,0.08)'; $riskColor='#388E3C'; $riskBg='rgba(56,142,60,0.12)'; }
    elseif ($score <= 50) { $scoreColor='#F57C00'; $scoreBg='rgba(245,124,0,0.08)'; $riskColor='#F57C00'; $riskBg='rgba(245,124,0,0.12)'; }
    else { $scoreColor='#D32F2F'; $scoreBg='rgba(211,47,47,0.08)'; $riskColor='#D32F2F'; $riskBg='rgba(211,47,47,0.12)'; }

    // Two rendering paths:
    // - DOCX with successful structured extraction: real headings, lists,
    //   tables, indentation and page breaks, with AI highlights wrapped
    //   around existing text only (renderDocxStructuredHTML).
    // - Everything else (PDF/txt/csv/md, or DOCX if structured parsing
    //   failed): the exact extracted text, preformatted so every space
    //   and line break is preserved as-is, with sentences highlighted
    //   (highlightAISentences).
    $is_structured = is_array($docx_blocks) && !empty($docx_blocks);
    if ($is_structured) {
        $highlighted_content = renderDocxStructuredHTML($docx_blocks);
        $content_body_class = 'content-body content-structured';
    } else {
        $highlighted_content = highlightAISentences($raw_content);
        $content_body_class = 'content-body content-plain';
    }

    if (!empty($logo_data)) { $logo_html = '<img src="'.$logo_data.'" alt="Logo" style="width:110px;height:110px;object-fit:contain;border-radius:16px;">'; }
    else { $logo_html = '<div style="width:110px;height:110px;border-radius:16px;background:linear-gradient(135deg,#6A0DAD,#9C27B0);display:flex;align-items:center;justify-content:center;"><span style="font-size:42px;font-weight:900;color:#fff;font-family:sans-serif;">AI</span></div>'; }

    // Official stamp graphic (image/stamp.png) — used identically on the
    // cover page and as the "checked" mark on every following page.
    if (!empty($stamp_data)) {
        $stamp_html_cover  = '<img src="'.$stamp_data.'" alt="Verified Stamp" style="width:150px;height:150px;object-fit:contain;">';
        $stamp_html_page   = '<img src="'.$stamp_data.'" alt="Verified Stamp" style="width:130px;height:130px;object-fit:contain;">';
    } else {
        $stamp_html_cover = '<div class="cover-stamp">AI Checked / Completed</div>';
        $stamp_html_page  = '<div class="ai-stamp-inner">AI<br>CHECKED</div>';
    }

    $kwCount = count($keywords);
    $kwTags = $kwCount > 0 ? implode('', array_map(function($k){ return '<span>'.esc($k).'</span>'; }, $keywords)) : '<span style="background:#f5f5f5;color:#999;border-color:#ddd;">No keywords detected</span>';

    // ── Build the "Scan to Verify" QR code ──
    // The QR now encodes a direct, absolute link to the certificate
    // image (image/certificate.png) on this server, so scanning it
    // opens the certificate picture immediately — no verify_report.php
    // page, no signature check, no database lookup. This still needs
    // this server to be reachable from whatever device scans it,
    // since a QR code can only ever hold a link, never the image
    // itself.
    $qr_scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $qr_host = $_SERVER['HTTP_HOST'] ?? 'sql300.infinityfree.com';
    $qr_selfDir = isset($_SERVER['PHP_SELF']) ? rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'])), '/') : '';
    $verification_url = $qr_scheme . '://' . $qr_host . $qr_selfDir . '/image/certificate.png';
    $certificate_exists = file_exists(__DIR__ . '/image/certificate.png');
    $verification_url_esc = esc($verification_url);
    $qr_svg = $certificate_exists
        ? '<img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($verification_url) . '" alt="Certificate QR code" style="width:100%;height:100%;object-fit:contain;">'
        : '';

    // ═══════════════════════════════════════════════════════════
    // ORIGINAL FILE PREVIEW BLOCK
    // Renders the exact uploaded file — not a re-typed copy — so the
    // report always references and (where the file type allows)
    // visually reproduces the original formatting, images, tables,
    // headers/footers, fonts and page layout.
    // ═══════════════════════════════════════════════════════════
    $orig_ext  = $original_file['ext'] ?? '';
    $orig_size = isset($original_file['size']) ? (int)$original_file['size'] : 0;
    $orig_hash = $original_file['hash'] ?? '';
    $orig_data = $original_file['data'] ?? '';
    $orig_size_h = $orig_size > 0 ? round($orig_size / 1024, 1) . ' KB' : 'Unknown';
    $orig_hash_h = $orig_hash ? esc($orig_hash) : 'Not available';

    $original_preview_html = '';
    if (!empty($orig_data) && $orig_ext === 'pdf') {
        $original_preview_html = '<embed src="'.$orig_data.'" type="application/pdf" class="orig-embed" />';
    } elseif (!empty($orig_data) && $orig_ext === 'docx') {
        $docx_id = 'docxPreview_' . $a_id;
        $original_preview_html = <<<DOCXBLOCK
<div id="{$docx_id}" class="orig-docx-preview">Rendering original document preview…</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/mammoth/1.6.0/mammoth.browser.min.js"></script>
<script>
(function(){
  var target = document.getElementById('{$docx_id}');
  function b64ToArrayBuffer(b64){
    var bin = atob(b64), len = bin.length, bytes = new Uint8Array(len);
    for (var i=0;i<len;i++){ bytes[i] = bin.charCodeAt(i); }
    return bytes.buffer;
  }
  try {
    var dataUri = "{$orig_data}";
    var b64 = dataUri.split(',')[1];
    var buf = b64ToArrayBuffer(b64);
    if (window.mammoth) {
      mammoth.convertToHtml({arrayBuffer: buf}).then(function(result){
        target.innerHTML = result.value || '<p style="color:#999;">No content could be rendered from this document.</p>';
        target.classList.add('rendered');
      }).catch(function(err){
        target.innerHTML = '<p style="color:#D32F2F;">Could not render the original DOCX layout in this viewer. The exact uploaded file is still attached and referenced in this report (see download link above).</p>';
      });
    } else {
      target.innerHTML = '<p style="color:#999;">Live preview requires an internet connection. The exact uploaded file is still attached and referenced in this report (see download link above).</p>';
    }
  } catch(e) {
    target.innerHTML = '<p style="color:#999;">Preview unavailable. The exact uploaded file is still attached and referenced in this report (see download link above).</p>';
  }
})();
</script>
DOCXBLOCK;
    } else {
        $original_preview_html = '<p style="color:#999;font-size:13px;">A live visual preview is not available for this file type in this viewer. The extracted text below was read directly from the exact uploaded file and used for all analysis.</p>';
    }

    $view_file_link = 'ai_analysis.php?action=view_file&id=' . $a_id;

    return <<<REPORT
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$pdf_title_esc}</title>
<style>
@page{size:A4;margin:15mm;}
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Segoe UI','Noto Sans',Arial,'Helvetica Neue',Tahoma,Geneva,Verdana,sans-serif;color:#2D1B4E;background:#E8E0F0;}
.watermark{position:fixed;top:50%;left:50%;transform:translate(-50%,-50%) rotate(-45deg);font-size:100px;font-weight:900;color:rgba(106,13,173,0.035);white-space:nowrap;pointer-events:none;z-index:0;letter-spacing:12px;}
.page{width:210mm;min-height:297mm;padding:22mm 20mm;margin:20px auto;position:relative;background:#fff;box-shadow:0 4px 30px rgba(0,0,0,0.12);z-index:1;}
.page-break{page-break-before:always;}
.cover{display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;min-height:253mm;}
.cover-logo{margin-bottom:28px;}
.cover-company{font-size:26px;font-weight:800;color:#6A0DAD;margin-bottom:6px;letter-spacing:1px;}
.cover-sub{font-size:13px;color:#7B6B8D;font-weight:500;margin-bottom:40px;}
.cover-title{font-size:34px;font-weight:900;color:#2D1B4E;border-bottom:4px solid #6A0DAD;padding-bottom:16px;margin-bottom:30px;display:inline-block;}
.cover-meta{font-size:15px;color:#555;line-height:2.2;}
.cover-meta strong{color:#2D1B4E;}
.cover-stamp{margin-top:45px;padding:14px 48px;border:3px solid #388E3C;border-radius:10px;color:#388E3C;font-size:19px;font-weight:900;letter-spacing:4px;text-transform:uppercase;}
.stamp-wrap-cover{margin-top:36px;}
.sec-title{font-size:20px;font-weight:800;color:#2D1B4E;margin-bottom:18px;padding-bottom:10px;border-bottom:3px solid #6A0DAD;display:flex;align-items:center;gap:10px;}
.score-box{text-align:center;padding:30px;border-radius:16px;margin:20px 0;background:{$scoreBg};border:1px solid {$scoreColor}22;}
.score-num{font-size:80px;font-weight:900;color:{$scoreColor};line-height:1;}
.score-label{font-size:14px;color:#7B6B8D;font-weight:600;margin-top:4px;}
.risk-badge{display:inline-block;padding:8px 28px;border-radius:24px;font-size:16px;font-weight:700;margin-top:12px;color:{$riskColor};background:{$riskBg};border:1px solid {$riskColor}33;}
.feedback-box{background:#F9F7FC;border:1px solid #E8E0F0;border-radius:12px;padding:20px 24px;margin:16px 0;line-height:1.8;font-size:14px;white-space:pre-line;}
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:16px 0;}
.stat-item{text-align:center;padding:14px 8px;background:#F9F7FC;border-radius:10px;border:1px solid #E8E0F0;}
.stat-item .sv{font-size:22px;font-weight:800;color:#6A0DAD;}
.stat-item .sl{font-size:11px;color:#7B6B8D;font-weight:500;margin-top:2px;}
.content-body{font-size:13.5px;line-height:1.85;color:#333;padding:16px 0;}
/* Plain-text fallback path (PDF/txt/csv/md): preserve every space, tab and
   line break exactly as extracted — no collapsing, no reflow. */
.content-plain{white-space:pre-wrap;word-wrap:break-word;font-family:'Courier New',monospace;font-size:12.5px;}
/* Structured DOCX path: real block-level HTML built from the document's
   own structure. white-space:pre-wrap on text-bearing elements preserves
   multiple consecutive spaces/tabs exactly while still wrapping normally. */
.content-structured p,.content-structured li,.content-structured td,.content-structured th,
.content-structured h1,.content-structured h2,.content-structured h3,
.content-structured h4,.content-structured h5,.content-structured h6{white-space:pre-wrap;word-wrap:break-word;}
.content-structured p{margin:0 0 10px 0;}
.content-structured h1,.content-structured h2,.content-structured h3,
.content-structured h4,.content-structured h5,.content-structured h6{color:#2D1B4E;font-weight:800;margin:18px 0 10px 0;}
.content-structured h1{font-size:22px;}
.content-structured h2{font-size:19px;}
.content-structured h3{font-size:16.5px;}
.content-structured h4,.content-structured h5,.content-structured h6{font-size:14.5px;}
.content-structured ul,.content-structured ol{margin:6px 0 14px 0;padding-left:28px;}
.content-structured li{margin-bottom:6px;}
.content-structured .doc-table{border-collapse:collapse;width:100%;margin:12px 0 18px 0;font-size:12.5px;}
.content-structured .doc-table td{border:1px solid #DCD3E8;padding:8px 10px;vertical-align:top;}
.content-structured .doc-page-break{display:flex;align-items:center;gap:10px;margin:22px 0;color:#9C8BB4;font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;}
.content-structured .doc-page-break::before,.content-structured .doc-page-break::after{content:'';flex:1;border-top:1px dashed #C9B8DE;}
.content-structured .doc-page-break{page-break-after:always;}
.kw-hl{background-color:#FFFF00;padding:1px 5px;border-radius:3px;font-weight:600;}
.ai-hl{background-color:rgba(211,47,47,0.14);border-bottom:2px solid #D32F2F;padding:1px 2px;border-radius:2px;}
.keywords-list{display:flex;flex-wrap:wrap;gap:8px;margin:12px 0;}
.keywords-list span{background:rgba(106,13,173,0.08);color:#6A0DAD;padding:5px 14px;border-radius:20px;font-size:12px;font-weight:600;border:1px solid rgba(106,13,173,0.12);}
.ai-stamp{position:absolute;bottom:24mm;right:20mm;opacity:0.85;}
.ai-stamp-inner{width:120px;height:120px;border:4px solid #D32F2F;border-radius:50%;display:flex;align-items:center;justify-content:center;text-align:center;transform:rotate(-15deg);font-size:17px;font-weight:900;color:#D32F2F;line-height:1.3;letter-spacing:1px;}
.page-footer{position:absolute;bottom:12mm;left:20mm;right:20mm;border-top:1px solid #E8E0F0;padding-top:8px;display:flex;justify-content:space-between;font-size:10px;color:#999;}
.qr-block{margin-top:36px;display:flex;flex-direction:column;align-items:center;}
.qr-box{width:120px;height:120px;padding:8px;background:#fff;border:2px solid #6A0DAD;border-radius:12px;}
.qr-caption{font-size:11px;color:#7B6B8D;margin-top:10px;max-width:280px;line-height:1.5;}
.qr-caption strong{color:#2D1B4E;}
.qr-url{font-size:9.5px;color:#9C8DAE;margin-top:8px;max-width:320px;word-break:break-all;}
.qr-url a{color:#6A0DAD;text-decoration:none;}
.file-ref-box{background:#F9F7FC;border:1px solid #E8E0F0;border-radius:12px;padding:16px 20px;margin:14px 0 20px;font-size:12.5px;color:#444;line-height:1.9;word-break:break-all;}
.file-ref-box strong{color:#2D1B4E;}
.view-original-link{display:inline-flex;align-items:center;gap:8px;margin:8px 0 18px;padding:9px 18px;background:rgba(106,13,173,0.08);color:#6A0DAD;border:1px solid rgba(106,13,173,0.2);border-radius:8px;text-decoration:none;font-size:12.5px;font-weight:700;}
.orig-embed{width:100%;height:900px;border:1px solid #E8E0F0;border-radius:10px;}
.orig-docx-preview{border:1px solid #E8E0F0;border-radius:10px;padding:24px;min-height:200px;font-size:13.5px;line-height:1.8;color:#333;background:#fff;overflow:auto;}
.orig-docx-preview.rendered table{border-collapse:collapse;margin:12px 0;width:100%;}
.orig-docx-preview.rendered td,.orig-docx-preview.rendered th{border:1px solid #ddd;padding:6px 10px;}
.orig-docx-preview.rendered img{max-width:100%;height:auto;}
.pdf-download-bar{position:fixed;top:18px;right:18px;z-index:999;}
.pdf-download-btn{display:inline-flex;align-items:center;gap:8px;padding:12px 22px;background:linear-gradient(135deg,#6A0DAD,#9C27B0);color:#fff;border:none;border-radius:10px;font-family:inherit;font-size:13.5px;font-weight:700;cursor:pointer;box-shadow:0 6px 22px rgba(106,13,173,0.35);}
.pdf-download-btn:hover{transform:translateY(-2px);}
@media print{
  body,*{font-family:'Segoe UI','Noto Sans',Arial,'Helvetica Neue',Tahoma,Geneva,Verdana,sans-serif !important;}
  body{background:#fff;}
  .page{box-shadow:none;margin:0;page-break-after:always;}
  .watermark,.ai-stamp{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
  .pdf-download-bar,.orig-embed{display:none !important;}
  .view-original-link{display:none !important;}
}
</style>
</head>
<body>
<div class="pdf-download-bar"><button class="pdf-download-btn" onclick="window.print()"><span>&#128190;</span> Download as PDF</button></div>
<div class="watermark">{$company_name}</div>
<div class="page">
    <div class="cover">
        <div class="cover-logo">{$logo_html}</div>
        <div class="cover-company">{$company_name}</div>
        <div class="cover-sub">{$company_tagline}</div>
        <div class="cover-title">AI Assignment Report</div>
        <div class="cover-meta">
            <strong>File:</strong> {$file_name}<br>
            <strong>Student Name:</strong> {$user_name_esc}<br>
            <strong>Student Email:</strong> {$user_email_esc}<br>
            <strong>User ID:</strong> {$user_id}<br>
            <strong>Date Processed:</strong> {$date_now}<br>
            <strong>Report ID:</strong> {$report_id}
        </div>
        <div class="stamp-wrap-cover">{$stamp_html_cover}</div>
        <div class="qr-block">
            <div class="qr-box">{$qr_svg}</div>
            <div class="qr-caption"><strong>Scan with any phone camera to verify.</strong> It opens this report's official certificate image directly — no database lookup involved.</div>
            <div class="qr-url"><a href="{$verification_url_esc}" target="_blank">{$verification_url_esc}</a></div>
        </div>
    </div>
</div>
<div class="page page-break" style="position:relative;">
    <div class="sec-title">&#9881; AI Analysis Results</div>
    <div class="score-box">
        <div class="score-num">{$score}</div>
        <div class="score-label">AI Probability Score (0-100)</div>
        <div class="risk-badge">Risk Level: {$risk}</div>
    </div>
    <div class="stats-grid">
        <div class="stat-item"><div class="sv">{$analysis['word_count']}</div><div class="sl">Words</div></div>
        <div class="stat-item"><div class="sv">{$analysis['sentence_count']}</div><div class="sl">Sentences</div></div>
        <div class="stat-item"><div class="sv">{$analysis['paragraph_count']}</div><div class="sl">Paragraphs</div></div>
        <div class="stat-item"><div class="sv">{$kwCount}</div><div class="sl">Keywords</div></div>
    </div>
    <div class="sec-title" style="margin-top:28px;font-size:17px;">&#128172; AI Feedback</div>
    <div class="feedback-box">{$feedback}</div>
    <div class="sec-title" style="margin-top:24px;font-size:17px;">&#127912; Detected Keywords</div>
    <div class="keywords-list">{$kwTags}</div>
    <div class="page-footer"><span>{$company_name} — {$company_tagline}</span><span>Generated: {$date_now}</span></div>
    <div class="ai-stamp">{$stamp_html_page}</div>
</div>
<div class="page page-break" style="position:relative;">
    <div class="sec-title">&#128196; Original Uploaded File</div>
    <div class="file-ref-box">
        <strong>File Name:</strong> {$file_name}<br>
        <strong>File Type:</strong> {$orig_ext}<br>
        <strong>File Size:</strong> {$orig_size_h}<br>
        <strong>SHA-256 Fingerprint:</strong> {$orig_hash_h}<br>
        This report was generated by reading and analyzing this exact file. The fingerprint above uniquely identifies the precise bytes that were uploaded and analyzed.
    </div>
    <a class="view-original-link" href="{$view_file_link}" target="_blank">&#128065; Open Original File</a>
    {$original_preview_html}
    <div class="page-footer"><span>{$company_name} — {$company_tagline}</span><span>Page 3 of 4</span></div>
    <div class="ai-stamp">{$stamp_html_page}</div>
</div>
<div class="page page-break" style="position:relative;">
    <div class="sec-title">&#128209; Extracted Text (Used for Analysis)</div>
    <p style="font-size:12px;color:#999;margin-bottom:12px;">Text extracted directly from the original file above, shown exactly as submitted. Sentences containing AI-indicator language are highlighted in <span style="background:rgba(211,47,47,0.14);border-bottom:2px solid #D32F2F;padding:1px 6px;border-radius:3px;font-weight:600;">red</span>.</p>
    <div class="{$content_body_class}">{$highlighted_content}</div>
    <div class="page-footer"><span>{$company_name} — {$company_tagline}</span><span>Page 4 of 4</span></div>
    <div class="ai-stamp">{$stamp_html_page}</div>
</div>
<script>
if (window.location.search.indexOf('autoprint=1') !== -1) {
    window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 700); });
}
</script>
</body>
</html>
REPORT;
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $assignment_id > 0 ? 'AI Analysis — Assignment #' . $assignment_id : 'AI Analysis — All Assignments'; ?> — AI Assignment Checker</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap">
    <style>
        :root{--primary:#6A0DAD;--primary-light:#9C27B0;--primary-dark:#4A0072;--primary-rgb:106,13,173;--secondary-rgb:156,39,176;--bg:#F3F0F7;--card-bg:rgba(255,255,255,0.78);--sidebar-width:260px;--header-height:70px;--text-dark:#2D1B4E;--text-muted:#7B6B8D;--border-color:rgba(106,13,173,0.08);--input-bg:#FFFFFF;--shadow-sm:0 2px 8px rgba(106,13,173,0.06);--shadow-md:0 4px 20px rgba(106,13,173,0.1);--shadow-lg:0 8px 40px rgba(106,13,173,0.15);--radius:16px;--radius-sm:10px;}
        [data-theme="dark"]{--bg:#110B18;--card-bg:rgba(32,18,52,0.82);--text-dark:#E8E0F0;--text-muted:#9B8DB5;--border-color:rgba(156,39,176,0.12);--input-bg:rgba(45,27,78,0.6);--shadow-sm:0 2px 8px rgba(0,0,0,0.25);--shadow-md:0 4px 20px rgba(0,0,0,0.35);--shadow-lg:0 8px 40px rgba(0,0,0,0.45);}
        *{margin:0;padding:0;box-sizing:border-box;}
        body{font-family:'Poppins',sans-serif;background:var(--bg);color:var(--text-dark);overflow-x:hidden;min-height:100vh;transition:background .35s ease,color .35s ease;}
        .sidebar{position:fixed;top:0;left:0;width:var(--sidebar-width);height:100vh;background:linear-gradient(180deg,var(--primary-dark) 0%,var(--primary) 50%,var(--primary-light) 100%);z-index:1050;transition:transform .35s cubic-bezier(.4,0,.2,1);display:flex;flex-direction:column;box-shadow:4px 0 30px rgba(106,13,173,0.3);}
        .sidebar-brand{padding:24px 20px;border-bottom:1px solid rgba(255,255,255,0.1);display:flex;align-items:center;gap:12px;}
        .sidebar-brand .brand-icon{width:52px;height:60px;background:rgba(255,255,255,0.15);border-radius:12px;overflow:hidden;backdrop-filter:blur(10px);flex-shrink:0;}
        .sidebar-brand .brand-icon img{width:100%;height:100%;object-fit:cover;}
        .sidebar-brand h5{color:#fff;font-weight:700;font-size:15px;margin:0;line-height:1.3;}
        .sidebar-brand small{color:rgba(255,255,255,0.6);font-size:11px;}
        .sidebar-menu{flex:1;padding:16px 12px;overflow-y:auto;}
        .sidebar-menu::-webkit-scrollbar{display:none;}
        .sidebar-menu .menu-label{color:rgba(255,255,255,0.4);font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:1.5px;padding:12px 14px 8px;}
        .sidebar-menu a{display:flex;align-items:center;gap:12px;padding:11px 14px;color:rgba(255,255,255,0.7);text-decoration:none;border-radius:var(--radius-sm);font-size:13.5px;font-weight:500;transition:all .25s ease;margin-bottom:2px;position:relative;}
        .sidebar-menu a:hover{background:rgba(255,255,255,0.1);color:#fff;transform:translateX(4px);}
        .sidebar-menu a.active{background:rgba(255,255,255,0.18);color:#fff;box-shadow:0 4px 15px rgba(0,0,0,0.15);}
        .sidebar-menu a.active::before{content:'';position:absolute;left:0;top:50%;transform:translateY(-50%);width:4px;height:60%;background:#fff;border-radius:0 4px 4px 0;}
        .sidebar-menu a .sidebar-noti-badge{margin-left:auto;background:#FF4757;color:#fff;font-size:10px;font-weight:700;padding:2px 7px;border-radius:10px;min-width:20px;text-align:center;line-height:1.4;}
        .sidebar-menu a.logout-btn{color:#FF6B8A;margin-top:20px;border-top:1px solid rgba(255,255,255,0.08);padding-top:16px;}
        .sidebar-menu a.logout-btn:hover{background:rgba(255,107,138,0.12);color:#FF6B8A;transform:translateX(4px);}
        .sidebar-footer{padding:16px 20px;border-top:1px solid rgba(255,255,255,0.1);}
        .sidebar-footer .admin-info{display:flex;align-items:center;gap:10px;}
        .sidebar-footer .admin-avatar-img{width:38px;height:38px;border-radius:10px;border:2px solid rgba(255,255,255,0.2);background:rgba(255,255,255,0.08);object-fit:cover;flex-shrink:0;}
        .sidebar-footer .admin-name{color:#fff;font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:150px;}
        .sidebar-footer .admin-role{color:rgba(255,255,255,0.5);font-size:11px;}
        .main-content{margin-left:var(--sidebar-width);min-height:100vh;transition:margin-left .35s cubic-bezier(.4,0,.2,1);}
        .top-header{height:var(--header-height);background:rgba(255,255,255,0.8);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border-bottom:1px solid var(--border-color);display:flex;align-items:center;justify-content:space-between;padding:0 30px;position:sticky;top:0;z-index:1000;transition:background .35s ease;}
        [data-theme="dark"] .top-header{background:rgba(17,11,24,0.88);}
        .top-header .left-section{display:flex;align-items:center;gap:16px;}
        .sidebar-toggle{display:none;background:none;border:none;font-size:20px;color:var(--primary);cursor:pointer;padding:6px;border-radius:8px;transition:background .2s;}
        .sidebar-toggle:hover{background:rgba(var(--primary-rgb),0.08);}
        .top-header .page-title{font-size:18px;font-weight:700;color:var(--text-dark);transition:color .35s ease;}
        .top-header .page-title span{color:var(--primary);}
        .top-header .right-section{display:flex;align-items:center;gap:10px;}
        .header-btn{width:40px;height:40px;border-radius:12px;border:1px solid var(--border-color);background:#fff;display:flex;align-items:center;justify-content:center;color:var(--text-muted);font-size:16px;cursor:pointer;transition:all .25s ease;position:relative;}
        [data-theme="dark"] .header-btn{background:rgba(45,27,78,0.5);border-color:var(--border-color);color:var(--text-muted);}
        .header-btn:hover{border-color:var(--primary);color:var(--primary);box-shadow:var(--shadow-sm);}
        .header-time{font-size:12.5px;color:var(--text-muted);font-weight:500;background:rgba(var(--primary-rgb),0.05);padding:6px 14px;border-radius:8px;}
        [data-theme="dark"] .header-time{background:rgba(156,39,176,0.08);}
        .notification-wrapper{position:relative;}
        .noti-badge{position:absolute;top:6px;right:6px;min-width:18px;height:18px;background:#FF4757;color:#fff;font-size:10px;font-weight:700;border-radius:50%;display:flex;align-items:center;justify-content:center;border:2px solid #fff;padding:0 3px;line-height:1;animation:notiPulse 2s ease-in-out infinite;}
        [data-theme="dark"] .noti-badge{border-color:#1A1025;}
        @keyframes notiPulse{0%,100%{transform:scale(1);}50%{transform:scale(1.15);}}
        .notification-dropdown{position:absolute;top:calc(100% + 12px);right:-8px;width:360px;max-height:440px;background:#fff;border:1px solid var(--border-color);border-radius:var(--radius);box-shadow:0 20px 60px rgba(0,0,0,0.15);opacity:0;visibility:hidden;transform:translateY(-8px) scale(0.97);transition:all .3s cubic-bezier(.16,1,.3,1);z-index:9999;overflow:hidden;display:flex;flex-direction:column;}
        [data-theme="dark"] .notification-dropdown{background:#1F1333;border-color:rgba(156,39,176,0.15);}
        .notification-dropdown.show{opacity:1;visibility:visible;transform:translateY(0) scale(1);}
        .notification-dropdown .noti-header{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid var(--border-color);flex-shrink:0;}
        .notification-dropdown .noti-header h6{font-size:14px;font-weight:700;color:var(--text-dark);margin:0;display:flex;align-items:center;gap:8px;}
        .notification-dropdown .noti-header h6 .count{background:var(--primary);color:#fff;font-size:10px;padding:2px 7px;border-radius:8px;}
        .mark-read-btn{background:none;border:none;color:var(--primary);font-size:11.5px;font-weight:600;cursor:pointer;font-family:inherit;padding:4px 8px;border-radius:6px;transition:background .2s;}
        .mark-read-btn:hover{background:rgba(var(--primary-rgb),0.08);}
        .notification-dropdown .noti-list{overflow-y:auto;flex:1;}
        .notification-dropdown .noti-item{display:flex;align-items:flex-start;gap:12px;padding:13px 18px;border-bottom:1px solid var(--border-color);transition:background .2s;}
        .notification-dropdown .noti-item:last-child{border-bottom:none;}
        .notification-dropdown .noti-item:hover{background:rgba(var(--primary-rgb),0.03);}
        .notification-dropdown .noti-item.unread{background:rgba(var(--primary-rgb),0.04);}
        .notification-dropdown .noti-dot{width:8px;height:8px;border-radius:50%;background:#E0D4ED;flex-shrink:0;margin-top:6px;}
        .notification-dropdown .noti-dot.active{background:var(--primary);box-shadow:0 0 0 3px rgba(var(--primary-rgb),0.15);}
        .notification-dropdown .noti-content{flex:1;min-width:0;}
        .notification-dropdown .noti-content p{font-size:12.5px;color:var(--text-dark);margin:0 0 3px;line-height:1.45;}
        .notification-dropdown .noti-content span{font-size:11px;color:var(--text-muted);}
        .noti-empty{padding:40px 20px;text-align:center;color:var(--text-muted);font-size:13px;}
        .noti-empty i{font-size:28px;margin-bottom:8px;display:block;opacity:0.3;}
        .dashboard-body{padding:28px 30px 40px;}
        .stat-card{background:var(--card-bg);backdrop-filter:blur(20px);border:1px solid var(--border-color);border-radius:var(--radius);padding:24px 22px;position:relative;overflow:hidden;box-shadow:var(--shadow-sm);transition:all .35s cubic-bezier(.4,0,.2,1);}
        .stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;border-radius:var(--radius) var(--radius) 0 0;opacity:0;transition:opacity .3s ease;}
        .stat-card:hover{transform:translateY(-6px);box-shadow:var(--shadow-lg);border-color:rgba(var(--primary-rgb),0.15);}
        .stat-card:hover::before{opacity:1;}
        .stat-card .s-icon{width:52px;height:52px;border-radius:15px;display:flex;align-items:center;justify-content:center;font-size:20px;margin-bottom:18px;transition:transform .3s ease;}
        .stat-card:hover .s-icon{transform:scale(1.1) rotate(-5deg);}
        .stat-card .s-value{font-size:28px;font-weight:800;color:var(--text-dark);line-height:1;margin-bottom:4px;letter-spacing:-0.5px;}
        .stat-card .s-label{font-size:12.5px;color:var(--text-muted);font-weight:500;}
        .stat-card .s-bg{position:absolute;right:-10px;bottom:-14px;font-size:86px;opacity:0.022;color:var(--primary);pointer-events:none;transition:opacity .3s ease;}
        .stat-card:hover .s-bg{opacity:0.05;}
        .c-purple::before{background:linear-gradient(90deg,#6A0DAD,#9C27B0);}
        .c-purple .s-icon{background:rgba(var(--primary-rgb),0.1);color:var(--primary);}
        .c-blue::before{background:linear-gradient(90deg,#1565C0,#42A5F5);}
        .c-blue .s-icon{background:rgba(33,150,243,0.1);color:#1976D2;}
        .c-green::before{background:linear-gradient(90deg,#2E7D32,#66BB6A);}
        .c-green .s-icon{background:rgba(76,175,80,0.1);color:#388E3C;}
        .c-orange::before{background:linear-gradient(90deg,#E65100,#FFA726);}
        .c-orange .s-icon{background:rgba(255,152,0,0.1);color:#F57C00;}
        .search-filter-bar{background:var(--card-bg);backdrop-filter:blur(20px);border:1px solid var(--border-color);border-radius:var(--radius);padding:18px 22px;margin-bottom:20px;box-shadow:var(--shadow-sm);display:flex;flex-wrap:wrap;align-items:center;gap:12px;}
        .search-input-wrap{position:relative;flex:1;min-width:220px;}
        .search-input-wrap i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:14px;}
        .search-input-wrap input{width:100%;padding:10px 14px 10px 40px;border:1.5px solid var(--border-color);border-radius:var(--radius-sm);background:var(--input-bg);color:var(--text-dark);font-family:inherit;font-size:13px;transition:all .25s ease;}
        .search-input-wrap input:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 4px rgba(var(--primary-rgb),0.08);}
        .filter-select{padding:10px 36px 10px 14px;border:1.5px solid var(--border-color);border-radius:var(--radius-sm);background:var(--input-bg);color:var(--text-dark);font-family:inherit;font-size:13px;min-width:160px;cursor:pointer;transition:all .25s ease;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%237B6B8D' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center;}
        .filter-select:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 4px rgba(var(--primary-rgb),0.08);}
        .clear-filters-btn{padding:10px 16px;border:1.5px solid var(--border-color);border-radius:var(--radius-sm);background:transparent;color:var(--text-muted);font-size:13px;font-family:inherit;font-weight:500;cursor:pointer;transition:all .25s ease;display:inline-flex;align-items:center;gap:6px;}
        .clear-filters-btn:hover{border-color:#F44336;color:#F44336;background:rgba(244,67,54,0.04);}
        .table-card{background:var(--card-bg);backdrop-filter:blur(20px);border:1px solid var(--border-color);border-radius:var(--radius);box-shadow:var(--shadow-sm);overflow:hidden;}
        .table-card .table-header{padding:22px 26px;border-bottom:1px solid var(--border-color);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;}
        .table-card .table-header h5{font-size:16px;font-weight:700;color:var(--text-dark);margin:0;display:flex;align-items:center;gap:10px;}
        .table-card .table-header h5 i{color:var(--primary);}
        .assign-table{width:100%;border-collapse:collapse;}
        .assign-table thead th{padding:14px 16px;text-align:left;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.8px;color:var(--text-muted);background:rgba(var(--primary-rgb),0.025);border-bottom:1.5px solid var(--border-color);white-space:nowrap;}
        .assign-table tbody td{padding:14px 16px;vertical-align:middle;border-bottom:1px solid var(--border-color);font-size:13px;transition:background .2s ease;}
        .assign-table tbody tr{transition:all .2s ease;}
        .assign-table tbody tr:hover{background:rgba(var(--primary-rgb),0.03);}
        .assign-table tbody tr:last-child td{border-bottom:none;}
        .assign-id{font-weight:700;color:var(--primary);font-size:13px;background:rgba(var(--primary-rgb),0.08);padding:3px 10px;border-radius:6px;display:inline-block;}
        .assign-name{font-weight:600;color:var(--text-dark);font-size:13px;display:flex;align-items:center;gap:10px;}
        .assign-avatar{width:34px;height:34px;border-radius:10px;background:linear-gradient(135deg,var(--primary),var(--primary-light));color:#fff;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;flex-shrink:0;}
        .assign-file{color:var(--text-muted);font-size:12px;max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block;}
        .assign-file.has-file{color:var(--text-dark);font-weight:500;}
        .assign-file.missing-file{color:#D32F2F;font-style:italic;}
        .status-badge{display:inline-flex;align-items:center;gap:4px;padding:5px 14px;border-radius:20px;font-size:11.5px;font-weight:700;letter-spacing:0.3px;}
        .status-pending{background:rgba(245,124,0,0.1);color:#F57C00;border:1px solid rgba(245,124,0,0.2);}
        .status-processing{background:rgba(33,150,243,0.1);color:#1976D2;border:1px solid rgba(33,150,243,0.2);}
        .status-completed{background:rgba(56,142,60,0.1);color:#388E3C;border:1px solid rgba(56,142,60,0.2);}
        .score-pill{font-weight:700;font-size:13px;padding:4px 12px;border-radius:8px;}
        .score-low{background:rgba(56,142,60,0.1);color:#388E3C;}
        .score-med{background:rgba(245,124,0,0.1);color:#F57C00;}
        .score-high{background:rgba(211,47,47,0.1);color:#D32F2F;}
        .btn-analyze{padding:8px 18px;border-radius:var(--radius-sm);border:none;background:linear-gradient(135deg,var(--primary),var(--primary-light));color:#fff;font-family:inherit;font-size:12px;font-weight:700;cursor:pointer;transition:all .25s ease;display:inline-flex;align-items:center;gap:6px;text-decoration:none;box-shadow:0 3px 12px rgba(var(--primary-rgb),0.25);white-space:nowrap;}
        .btn-analyze:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(var(--primary-rgb),0.35);color:#fff;}
        .btn-analyze:disabled{opacity:0.4;cursor:not-allowed;transform:none!important;box-shadow:none!important;}
        .btn-view-report{padding:8px 18px;border-radius:var(--radius-sm);border:1px solid rgba(33,150,243,0.3);background:rgba(33,150,243,0.06);color:#1976D2;font-family:inherit;font-size:12px;font-weight:600;cursor:pointer;transition:all .25s ease;display:inline-flex;align-items:center;gap:6px;text-decoration:none;white-space:nowrap;}
        .btn-view-report:hover{background:#1976D2;color:#fff;border-color:#1976D2;transform:translateY(-2px);}
        .btn-download-report{padding:8px 18px;border-radius:var(--radius-sm);border:1px solid rgba(56,142,60,0.3);background:rgba(56,142,60,0.06);color:#388E3C;font-family:inherit;font-size:12px;font-weight:600;cursor:pointer;transition:all .25s ease;display:inline-flex;align-items:center;gap:6px;text-decoration:none;white-space:nowrap;}
        .btn-download-report:hover{background:#388E3C;color:#fff;border-color:#388E3C;transform:translateY(-2px);}
        .btn-view-file{padding:8px 14px;border-radius:var(--radius-sm);border:1px solid rgba(56,142,60,0.3);background:rgba(56,142,60,0.06);color:#388E3C;font-family:inherit;font-size:12px;font-weight:600;cursor:pointer;transition:all .25s ease;display:inline-flex;align-items:center;gap:6px;text-decoration:none;white-space:nowrap;}
        .btn-view-file:hover{background:#388E3C;color:#fff;border-color:#388E3C;transform:translateY(-2px);}
        .btn-view-file:disabled{opacity:0.35;cursor:not-allowed;transform:none!important;}
        .btn-delete-assign{padding:8px 14px;border-radius:var(--radius-sm);border:1px solid rgba(244,67,54,0.3);background:rgba(244,67,54,0.06);color:#D32F2F;font-family:inherit;font-size:12px;font-weight:600;cursor:pointer;transition:all .25s ease;display:inline-flex;align-items:center;gap:6px;text-decoration:none;white-space:nowrap;}
        .btn-delete-assign:hover{background:#D32F2F;color:#fff;border-color:#D32F2F;transform:translateY(-2px);}
        .action-btns-cell{display:flex;align-items:center;gap:6px;flex-wrap:wrap;}
        .table-footer{padding:14px 26px;border-top:1px solid var(--border-color);display:flex;align-items:center;justify-content:space-between;font-size:12px;color:var(--text-muted);}
        .empty-state{padding:60px 20px;text-align:center;}
        .empty-state-icon{width:90px;height:90px;border-radius:50%;background:rgba(var(--primary-rgb),0.06);display:flex;align-items:center;justify-content:center;margin:0 auto 20px;font-size:36px;color:var(--primary-light);}
        .empty-state h6{font-weight:700;color:var(--text-dark);margin-bottom:6px;font-size:16px;}
        .empty-state p{color:var(--text-muted);font-size:13px;max-width:360px;margin:0 auto;}
        .back-link{display:inline-flex;align-items:center;gap:8px;color:var(--primary);text-decoration:none;font-size:13px;font-weight:600;margin-bottom:24px;padding:8px 16px;border-radius:var(--radius-sm);transition:all .2s ease;border:1px solid transparent;}
        .back-link:hover{background:rgba(var(--primary-rgb),0.06);border-color:rgba(var(--primary-rgb),0.12);transform:translateX(-4px);}
        .info-card{background:var(--card-bg);backdrop-filter:blur(20px);border:1px solid var(--border-color);border-radius:var(--radius);padding:28px;box-shadow:var(--shadow-sm);margin-bottom:24px;transition:box-shadow .35s ease;}
        .info-card:hover{box-shadow:var(--shadow-md);}
        .info-card .card-head{display:flex;align-items:center;gap:14px;margin-bottom:22px;}
        .info-card .card-head .ch-icon{width:52px;height:52px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0;}
        .info-card .card-head h5{font-size:18px;font-weight:700;margin:0;color:var(--text-dark);}
        .info-card .card-head small{font-size:12px;color:var(--text-muted);font-weight:400;}
        .info-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;}
        .info-item{padding:14px 18px;background:rgba(var(--primary-rgb),0.03);border-radius:var(--radius-sm);border:1px solid var(--border-color);}
        .info-item .ii-label{font-size:11px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.8px;margin-bottom:4px;}
        .info-item .ii-value{font-size:14px;font-weight:600;color:var(--text-dark);word-break:break-all;}
        .start-btn{display:inline-flex;align-items:center;gap:12px;padding:16px 40px;background:linear-gradient(135deg,var(--primary),var(--primary-light));color:#fff;border:none;border-radius:var(--radius-sm);font-family:inherit;font-size:16px;font-weight:700;cursor:pointer;transition:all .3s ease;box-shadow:0 4px 20px rgba(var(--primary-rgb),0.3);margin-top:8px;}
        .start-btn:hover{transform:translateY(-3px);box-shadow:0 8px 30px rgba(var(--primary-rgb),0.4);}
        .start-btn:disabled{opacity:0.5;cursor:not-allowed;transform:none!important;box-shadow:none!important;}
        .processing-overlay{position:fixed;inset:0;background:rgba(17,11,24,0.85);backdrop-filter:blur(12px);z-index:9999;display:flex;align-items:center;justify-content:center;opacity:0;visibility:hidden;transition:all .4s ease;}
        .processing-overlay.active{opacity:1;visibility:visible;}
        .processing-box{background:var(--card-bg);border:1px solid var(--border-color);border-radius:var(--radius);padding:48px 56px;text-align:center;box-shadow:var(--shadow-lg);max-width:460px;width:90%;transform:scale(0.9);transition:transform .4s cubic-bezier(.16,1,.3,1);}
        .processing-overlay.active .processing-box{transform:scale(1);}
        .processing-spinner{width:64px;height:64px;border:4px solid rgba(var(--primary-rgb),0.15);border-top-color:var(--primary);border-radius:50%;margin:0 auto 24px;animation:spin .8s linear infinite;}
        @keyframes spin{to{transform:rotate(360deg);}}
        .processing-title{font-size:20px;font-weight:800;color:var(--text-dark);margin-bottom:8px;}
        .processing-sub{font-size:13px;color:var(--text-muted);margin-bottom:28px;}
        .processing-steps{text-align:left;display:flex;flex-direction:column;gap:10px;}
        .processing-step{display:flex;align-items:center;gap:12px;padding:10px 14px;border-radius:var(--radius-sm);background:rgba(var(--primary-rgb),0.03);border:1px solid var(--border-color);transition:all .3s ease;font-size:13px;font-weight:500;color:var(--text-muted);}
        .processing-step .step-icon{width:24px;height:24px;border-radius:50%;background:rgba(var(--primary-rgb),0.08);display:flex;align-items:center;justify-content:center;font-size:11px;color:var(--primary);flex-shrink:0;transition:all .3s ease;}
        .processing-step.completed{color:var(--text-dark);background:rgba(56,142,60,0.04);border-color:rgba(56,142,60,0.15);}
        .processing-step.completed .step-icon{background:#388E3C;color:#fff;}
        .results-container{display:none;}
        .results-container.visible{display:block;animation:fadeUp .6s ease;}
        @keyframes fadeUp{from{opacity:0;transform:translateY(20px);}to{opacity:1;transform:translateY(0);}}
        .result-header{text-align:center;margin-bottom:32px;}
        .result-header .check-icon{width:80px;height:80px;border-radius:50%;background:rgba(56,142,60,0.1);display:inline-flex;align-items:center;justify-content:center;font-size:36px;color:#388E3C;margin-bottom:16px;animation:scaleIn .5s cubic-bezier(.16,1,.3,1);}
        @keyframes scaleIn{from{transform:scale(0);}to{transform:scale(1);}}
        .result-header h3{font-size:28px;font-weight:800;color:var(--text-dark);margin-bottom:6px;}
        .result-header p{font-size:14px;color:var(--text-muted);}
        .score-display{text-align:center;padding:40px;border-radius:var(--radius);margin-bottom:24px;position:relative;overflow:hidden;}
        .score-display::before{content:'';position:absolute;inset:0;opacity:0.06;background:radial-gradient(circle at center,var(--primary) 0%,transparent 70%);pointer-events:none;}
        .score-big{font-size:96px;font-weight:900;line-height:1;position:relative;}
        .score-of{font-size:16px;color:var(--text-muted);font-weight:600;margin-top:4px;position:relative;}
        .risk-display{display:inline-flex;align-items:center;gap:8px;padding:10px 28px;border-radius:24px;font-size:16px;font-weight:700;margin-top:16px;position:relative;}
        .risk-low{background:rgba(56,142,60,0.1);color:#388E3C;border:1px solid rgba(56,142,60,0.2);}
        .risk-medium{background:rgba(245,124,0,0.1);color:#F57C00;border:1px solid rgba(245,124,0,0.2);}
        .risk-high{background:rgba(211,47,47,0.1);color:#D32F2F;border:1px solid rgba(211,47,47,0.2);}
        .result-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:14px;margin-bottom:24px;}
        .rs-item{background:var(--card-bg);backdrop-filter:blur(20px);border:1px solid var(--border-color);border-radius:var(--radius-sm);padding:18px 14px;text-align:center;box-shadow:var(--shadow-sm);transition:transform .25s ease;}
        .rs-item:hover{transform:translateY(-4px);}
        .rs-item .rs-val{font-size:26px;font-weight:800;color:var(--primary);line-height:1;}
        .rs-item .rs-lbl{font-size:11px;color:var(--text-muted);font-weight:600;margin-top:4px;text-transform:uppercase;letter-spacing:0.5px;}
        .feedback-card{background:var(--card-bg);backdrop-filter:blur(20px);border:1px solid var(--border-color);border-radius:var(--radius);padding:24px 28px;box-shadow:var(--shadow-sm);margin-bottom:24px;}
        .feedback-card h6{font-size:15px;font-weight:700;color:var(--text-dark);margin-bottom:14px;display:flex;align-items:center;gap:8px;}
        .feedback-card h6 i{color:var(--primary);}
        .feedback-text{font-size:13.5px;line-height:1.8;color:var(--text-dark);white-space:pre-line;background:rgba(var(--primary-rgb),0.03);padding:16px 20px;border-radius:var(--radius-sm);border:1px solid var(--border-color);}
        .file-warning{background:rgba(245,124,0,0.08);border:1px solid rgba(245,124,0,0.2);border-radius:var(--radius-sm);padding:14px 20px;margin-bottom:20px;display:flex;align-items:center;gap:12px;font-size:13px;color:#E65100;font-weight:500;}
        .file-warning i{font-size:18px;flex-shrink:0;}
        .file-missing{background:rgba(211,47,47,0.08);border:1px solid rgba(211,47,54,0.2);border-radius:var(--radius-sm);padding:14px 20px;margin-bottom:20px;display:flex;align-items:center;gap:12px;font-size:13px;color:#C62828;font-weight:500;}
        .file-missing i{font-size:18px;flex-shrink:0;}
        .sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1040;backdrop-filter:blur(4px);}
        .sidebar-overlay.show{display:block;}
        .settings-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:1060;backdrop-filter:blur(4px);opacity:0;transition:opacity .3s ease;}
        .settings-overlay.show{display:block;opacity:1;}
        .settings-panel{position:fixed;top:0;right:0;width:340px;max-width:90vw;height:100vh;background:#fff;border-left:1px solid var(--border-color);z-index:1070;transform:translateX(100%);transition:transform .35s cubic-bezier(.4,0,.2,1);display:flex;flex-direction:column;box-shadow:-8px 0 40px rgba(0,0,0,0.1);}
        [data-theme="dark"] .settings-panel{background:#1A1025;border-left-color:rgba(156,39,176,0.15);}
        .settings-panel.show{transform:translateX(0);}
        .settings-panel-header{display:flex;align-items:center;justify-content:space-between;padding:22px 24px;border-bottom:1px solid var(--border-color);}
        .settings-panel-header h5{font-size:17px;font-weight:700;color:var(--text-dark);margin:0;display:flex;align-items:center;gap:10px;}
        .settings-panel-header h5 i{color:var(--primary);}
        .settings-close-btn{width:36px;height:36px;border-radius:10px;border:1px solid var(--border-color);background:transparent;display:flex;align-items:center;justify-content:center;color:var(--text-muted);font-size:14px;cursor:pointer;transition:all .2s;}
        .settings-close-btn:hover{background:rgba(244,67,54,0.08);border-color:rgba(244,67,54,0.2);color:#F44336;}
        .settings-body{flex:1;overflow-y:auto;padding:8px 0;}
        .settings-section{padding:20px 24px;border-bottom:1px solid var(--border-color);}
        .settings-label{font-size:14px;font-weight:600;color:var(--text-dark);margin-bottom:4px;}
        .settings-desc{font-size:12px;color:var(--text-muted);margin-bottom:14px;line-height:1.5;}
        .theme-toggle-row{display:flex;align-items:center;justify-content:space-between;}
        .theme-toggle-options{display:flex;align-items:center;gap:10px;}
        .theme-toggle-options i{font-size:15px;}
        .theme-toggle-options .fa-sun{color:#FF9800;}
        .theme-toggle-options .fa-moon{color:#5C6BC0;}
        .theme-switch{position:relative;width:52px;height:28px;display:inline-block;cursor:pointer;}
        .theme-switch input{opacity:0;width:0;height:0;position:absolute;}
        .theme-switch .slider{position:absolute;inset:0;background:#E0D4ED;border-radius:28px;transition:all .35s cubic-bezier(.4,0,.2,1);}
        .theme-switch .slider::before{content:'';position:absolute;width:22px;height:22px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:all .35s cubic-bezier(.4,0,.2,1);box-shadow:0 2px 6px rgba(0,0,0,0.15);}
        .theme-switch input:checked + .slider{background:var(--primary);}
        .theme-switch input:checked + .slider::before{transform:translateX(24px);}
        ::-webkit-scrollbar{width:6px;}
        ::-webkit-scrollbar-track{background:transparent;}
        ::-webkit-scrollbar-thumb{background:rgba(var(--primary-rgb),0.2);border-radius:10px;}
        ::-webkit-scrollbar-thumb:hover{background:rgba(var(--primary-rgb),0.35);}
        @media(max-width:991.98px){
            .sidebar{transform:translateX(-100%);}
            .sidebar.show{transform:translateX(0);}
            .main-content{margin-left:0;}
            .sidebar-toggle{display:flex;}
            .dashboard-body{padding:20px 16px 30px;}
            .top-header{padding:0 16px;}
            .header-time{display:none;}
            .notification-dropdown{width:320px;right:-40px;}
            .search-filter-bar{flex-direction:column;}
            .search-input-wrap{min-width:100%;}
            .action-btns-cell{flex-direction:column;align-items:flex-start;}
        }
        @media(max-width:575.98px){
            .notification-dropdown{width:calc(100vw - 32px);right:-60px;max-height:380px;}
            .settings-panel{width:100vw;max-width:100vw;}
            .assign-table thead th,.assign-table tbody td{padding:10px 10px;font-size:12px;}
        }
    </style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<div class="settings-overlay" id="settingsOverlay"></div>

<!-- ═══ SIDEBAR ═══ -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-icon"><img src="image/logo.png" alt="Logo"></div>
        <div><h5>AI Checker</h5><small>Admin Panel</small></div>
    </div>
    <nav class="sidebar-menu">
        <div class="menu-label">Main</div>
        <a href="adminpage.php"><i class="fas fa-th-large"></i> Dashboard</a>
        <a href="admin_users.php"><i class="fas fa-users"></i> Users</a>
        <a href="admin_assignments.php"><i class="fas fa-file-alt"></i> Assignments</a>
        <a href="admin_reviews.php"><i class="fas fa-star"></i> Reviews</a>
        <a href="ai_analysis.php" class="active"><i class="fas fa-magnifying-glass-chart"></i> Analysis</a>
        <div class="menu-label">Management</div>
        <a href="admin_plans.php"><i class="fas fa-tags"></i> Plans</a>
        <a href="admin_payments.php"><i class="fas fa-credit-card"></i> Payments</a>
        <a href="admin_vouchers.php"><i class="fas fa-ticket-alt"></i> Vouchers</a>
        <a href="admin_testimonials.php"><i class="fas fa-quote-right"></i> Testimonials</a>
        <a href="admin_contacts.php"><i class="fas fa-phone-alt"></i> Contacts</a>
        <a href="login.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </nav>
    <div class="sidebar-footer">
        <div class="admin-info">
            <img src="<?php echo htmlspecialchars($avatar); ?>" class="admin-avatar-img" alt="<?php echo esc($admin_name); ?>" onerror="this.src='https://api.dicebear.com/7.x/avataaars/svg?seed=Admin&backgroundColor=ede9fe';">
            <div><div class="admin-name"><?php echo esc($admin_name); ?></div><div class="admin-role">Administrator</div></div>
        </div>
    </div>
</aside>

<!-- ═══ SETTINGS PANEL ═══ -->
<div class="settings-panel" id="settingsPanel">
    <div class="settings-panel-header">
        <h5><i class="fas fa-cog"></i> Settings</h5>
        <button class="settings-close-btn" id="settingsCloseBtn"><i class="fas fa-times"></i></button>
    </div>
    <div class="settings-body">
        <div class="settings-section">
            <div class="settings-label">Appearance</div>
            <div class="settings-desc">Choose between light and dark mode.</div>
            <div class="theme-toggle-row">
                <div class="theme-toggle-options"><i class="fas fa-sun"></i><label class="theme-switch"><input type="checkbox" id="themeToggle"><span class="slider"></span></label><i class="fas fa-moon"></i></div>
            </div>
        </div>
    </div>
</div>

<!-- ═══ MAIN CONTENT ═══ -->
<main class="main-content">
    <header class="top-header">
        <div class="left-section">
            <button class="sidebar-toggle" id="sidebarToggle"><i class="fas fa-bars"></i></button>
            <h1 class="page-title"><span>AI</span> Analysis</h1>
        </div>
        <div class="right-section">
            <span class="header-time" id="headerTime"></span>
            <div class="notification-wrapper">
                <button class="header-btn" id="notiBtn"><i class="fas fa-bell"></i><?php if ($unread_count > 0): ?><span class="noti-badge"><?php echo $unread_count; ?></span><?php endif; ?></button>
                <div class="notification-dropdown" id="notiDropdown">
                    <div class="noti-header">
                        <h6>Notifications <span class="count"><?php echo $unread_count; ?></span></h6>
                        <form method="POST" style="display:inline;"><input type="hidden" name="action" value="mark_all_read"><button type="submit" class="mark-read-btn">Mark all read</button></form>
                    </div>
                    <div class="noti-list">
                        <?php if (empty($notifications)): ?>
                        <div class="noti-empty"><i class="fas fa-bell-slash"></i>No notifications yet</div>
                        <?php else: ?>
                            <?php foreach ($notifications as $n):
                                $icon_class = 'default';
                                if (stripos($n['message'], 'assignment') !== false) $icon_class = 'assignment';
                                elseif (stripos($n['message'], 'registered') !== false || stripos($n['message'], 'user') !== false) $icon_class = 'register';
                            ?>
                            <div class="noti-item <?php echo $n['is_read'] == 0 ? 'unread' : ''; ?>">
                                <div class="noti-dot <?php echo $n['is_read'] == 0 ? 'active' : ''; ?>"></div>
                                <div class="noti-content"><p><?php echo htmlspecialchars($n['message']); ?></p><span><?php echo time_ago($n['created_at']); ?></span></div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <button class="header-btn" id="settingsBtn"><i class="fas fa-cog"></i></button>
        </div>
    </header>

    <div class="dashboard-body">

<?php if ($assignment_id > 0 && $assignment): ?>
    <!-- ═══ SINGLE ASSIGNMENT VIEW ═══ -->
    <a href="ai_analysis.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to All Assignments</a>

    <?php if (!$file_exists_on_disk && !empty($assignment['file_path'])): ?>
    <div class="file-missing"><i class="fas fa-exclamation-triangle"></i><div><strong>File not found on server.</strong> The uploaded assignment file could not be found. Please upload the file again.</div></div>
    <?php elseif (empty($assignment['file_path'])): ?>
    <div class="file-warning"><i class="fas fa-info-circle"></i><div><strong>No file uploaded.</strong> This assignment has no uploaded file yet. AI Analysis cannot be started.</div></div>
    <?php endif; ?>

    <div class="info-card">
        <div class="card-head">
            <div class="ch-icon" style="background:rgba(var(--primary-rgb),0.1);color:var(--primary);"><i class="fas fa-file-lines"></i></div>
            <div><h5>Assignment #<?php echo $assignment_id; ?></h5><small>Submitted by <?php echo esc($user_name); ?> &middot; <?php echo $assignment['submission_date'] ? time_ago($assignment['submission_date']) : 'N/A'; ?></small></div>
        </div>
        <div class="info-grid">
            <div class="info-item"><div class="ii-label">File Name</div><div class="ii-value"><?php echo esc($assignment['file_name'] ?? 'No file'); ?></div></div>
            <div class="info-item"><div class="ii-label">Subject</div><div class="ii-value"><?php echo esc($assignment['subject'] ?? 'N/A'); ?></div></div>
            <div class="info-item"><div class="ii-label">Title</div><div class="ii-value"><?php echo esc($assignment['title'] ?? 'N/A'); ?></div></div>
            <div class="info-item"><div class="ii-label">Status</div><div class="ii-value"><?php $st = $assignment['status'] ?? 'Pending'; $sc = strtolower($st); echo '<span class="status-badge status-' . $sc . '">' . esc($st) . '</span>'; ?></div></div>
            <div class="info-item"><div class="ii-label">File on Disk</div><div class="ii-value" style="color:<?php echo $file_exists_on_disk ? '#388E3C' : '#D32F2F'; ?>;"><i class="fas fa-<?php echo $file_exists_on_disk ? 'check-circle' : 'times-circle'; ?>"></i> <?php echo $file_exists_on_disk ? 'Exists' : (empty($assignment['file_path']) ? 'Not uploaded' : 'Missing'); ?></div></div>
            <div class="info-item"><div class="ii-label">Actions</div><div class="ii-value" style="display:flex;gap:8px;flex-wrap:wrap;">
                <?php if ($file_exists_on_disk): ?>
                <a href="ai_analysis.php?action=view_file&id=<?php echo $assignment_id; ?>" target="_blank" class="btn-view-file"><i class="fas fa-eye"></i> View File</a>
                <?php endif; ?>
                <?php if ($is_completed && !empty($existing_report)): ?>
                <a href="<?php echo esc($existing_report); ?>" target="_blank" class="btn-view-report"><i class="fas fa-file-pdf"></i> View Report</a>
                <a href="ai_analysis.php?action=download_report&id=<?php echo (int)$assignment_id; ?>" class="btn-download-report"><i class="fas fa-download"></i> Download Report</a>
                <?php endif; ?>
            </div></div>
        </div>
        <div style="margin-top:24px;">
            <button class="start-btn" id="startAnalysisBtn" <?php echo (!$file_exists_on_disk || $is_processing) ? 'disabled' : ''; ?> onclick="startAnalysis(<?php echo $assignment_id; ?>)">
                <i class="fas fa-robot"></i> <?php echo $is_processing ? 'Analysis in Progress...' : ($is_completed ? 'Re-run Analysis' : 'Start AI Analysis'); ?>
            </button>
            <?php if (!$file_exists_on_disk): ?>
            <div style="font-size:12px;color:var(--text-muted);margin-top:8px;"><i class="fas fa-lock" style="margin-right:4px;"></i> Analysis is disabled because the uploaded file is not available.</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Existing Results -->
    <?php if ($is_completed): ?>
    <div class="results-container visible" id="existingResults">
        <div class="result-header"><div class="check-icon"><i class="fas fa-check"></i></div><h3>AI Analysis Completed</h3><p>Results for Assignment #<?php echo $assignment_id; ?></p></div>
        <?php
        if ($existing_score !== null) {
            $sc_class = $existing_score <= 20 ? 'low' : ($existing_score <= 50 ? 'medium' : 'high');
            $sc_color = $existing_score <= 20 ? '#388E3C' : ($existing_score <= 50 ? '#F57C00' : '#D32F2F');
            echo '<div class="score-display" style="background:rgba(' . ($existing_score <= 20 ? '56,142,60' : ($existing_score <= 50 ? '245,124,0' : '211,47,47')) . ',0.06);">';
            echo '<div class="score-big" style="color:' . $sc_color . ';">' . $existing_score . '</div>';
            echo '<div class="score-of">out of 100</div>';
            echo '<div class="risk-display risk-' . $sc_class . '"><i class="fas fa-shield-halved"></i> Risk: ' . esc($existing_risk) . '</div>';
            echo '</div>';
        }
        ?>
        <div class="feedback-card"><h6><i class="fas fa-comment-dots"></i> AI Feedback</h6><div class="feedback-text"><?php echo esc($existing_feedback) ?: 'No feedback generated.'; ?></div></div>
    </div>
    <?php endif; ?>

    <!-- Live Results Container -->
    <div class="results-container" id="liveResults"></div>

<?php else: ?>
    <!-- ═══ ALL ASSIGNMENTS VIEW ═══ -->
    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-md-6">
            <div class="stat-card c-purple">
                <div class="s-icon"><i class="fas fa-list"></i></div>
                <div class="s-value"><?php echo $total_all; ?></div>
                <div class="s-label">Total Assignments</div>
                <i class="fas fa-list s-bg"></i>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="stat-card c-green">
                <div class="s-icon"><i class="fas fa-check-circle"></i></div>
                <div class="s-value"><?php echo $completed_count; ?></div>
                <div class="s-label">Completed</div>
                <i class="fas fa-check-circle s-bg"></i>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="stat-card c-orange">
                <div class="s-icon"><i class="fas fa-clock"></i></div>
                <div class="s-value"><?php echo $pending_count; ?></div>
                <div class="s-label">Pending</div>
                <i class="fas fa-clock s-bg"></i>
            </div>
        </div>
        <div class="col-xl-3 col-md-6">
            <div class="stat-card c-blue">
                <div class="s-icon"><i class="fas fa-spinner"></i></div>
                <div class="s-value"><?php echo $processing_count; ?></div>
                <div class="s-label">Processing</div>
                <i class="fas fa-spinner s-bg"></i>
            </div>
        </div>
    </div>

    <div class="search-filter-bar">
        <div class="search-input-wrap">
            <i class="fas fa-search"></i>
            <form method="GET" style="display:flex;width:100%;"><input type="text" name="search" placeholder="Search by file name, user, ID..." value="<?php echo esc($searchQuery); ?>"></form>
        </div>
        <select class="filter-select" onchange="window.location.href='ai_analysis.php?search=<?php echo urlencode($searchQuery); ?>&filter='+this.value">
            <option value="">All Status</option>
            <option value="Pending" <?php echo $filterStatus === 'Pending' ? 'selected' : ''; ?>>Pending</option>
            <option value="Processing" <?php echo $filterStatus === 'Processing' ? 'selected' : ''; ?>>Processing</option>
            <option value="Completed" <?php echo $filterStatus === 'Completed' ? 'selected' : ''; ?>>Completed</option>
        </select>
        <?php if (!empty($searchQuery) || !empty($filterStatus)): ?>
        <a href="ai_analysis.php" class="clear-filters-btn"><i class="fas fa-times"></i> Clear</a>
        <?php endif; ?>
    </div>

    <div class="table-card">
        <div class="table-header">
            <h5><i class="fas fa-magnifying-glass-chart"></i> Assignment Analysis Queue</h5>
            <span style="font-size:12px;color:var(--text-muted);"><?php echo $total_all; ?> records</span>
        </div>
        <div style="overflow-x:auto;">
        <table class="assign-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>User</th>
                    <th>File</th>
                    <th>Status</th>
                    <th>AI Score</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($all_assignments)): ?>
                <tr><td colspan="6">
                    <div class="empty-state"><div class="empty-state-icon"><i class="fas fa-inbox"></i></div><h6>No Assignments Found</h6><p>There are no assignments matching your current filters.</p></div>
                </td></tr>
            <?php else: ?>
                <?php foreach ($all_assignments as $a):
                    $st = strtolower($a['status'] ?? 'pending');
                    $sc = $a['ai_score'] !== null ? floatval($a['ai_score']) : null;
                    $sc_class = $sc !== null ? ($sc <= 20 ? 'low' : ($sc <= 50 ? 'med' : 'high')) : '';
                    $has_file = !empty($a['file_path']);
                    $file_on_disk = $a['_file_exists'];
                    $initials = strtoupper(substr($a['user_name'] ?? 'U', 0, 1));
                    $fname = esc($a['file_name'] ?? ($has_file ? basename($a['file_path']) : 'No file'));
                    $fclass = $has_file ? ($file_on_disk ? 'has-file' : 'missing-file') : '';
                ?>
                <tr>
                    <td><span class="assign-id">#<?php echo $a['assignment_id']; ?></span></td>
                    <td>
                        <div class="assign-name">
                            <div class="assign-avatar"><?php echo $initials; ?></div>
                            <div>
                                <div style="font-weight:600;font-size:13px;"><?php echo esc($a['user_name'] ?? 'Unknown'); ?></div>
                                <div style="font-size:11px;color:var(--text-muted);"><?php echo $a['submission_date'] ? time_ago($a['submission_date']) : ''; ?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="assign-file <?php echo $fclass; ?>" title="<?php echo $fname; ?>">
                            <?php if ($has_file): ?>
                                <i class="fas fa-<?php echo $file_on_disk ? 'file-alt' : 'exclamation-circle'; ?>" style="margin-right:4px;color:<?php echo $file_on_disk ? 'var(--primary)' : '#D32F2F'; ?>;"></i><?php echo $fname; ?>
                            <?php else: ?>
                                <i class="fas fa-ban" style="margin-right:4px;opacity:0.4;"></i>No file
                            <?php endif; ?>
                        </span>
                    </td>
                    <td><span class="status-badge status-<?php echo $st; ?>"><?php echo esc($a['status'] ?? 'Pending'); ?></span></td>
                    <td><?php echo $sc !== null ? '<span class="score-pill score-' . $sc_class . '">' . $sc . '</span>' : '<span style="color:var(--text-muted);font-size:12px;">—</span>'; ?></td>
                    <td>
                        <div class="action-btns-cell">
                            <?php if ($file_on_disk): ?>
                            <a href="ai_analysis.php?action=view_file&id=<?php echo $a['assignment_id']; ?>" target="_blank" class="btn-view-file" title="View uploaded file"><i class="fas fa-eye"></i> View</a>
                            <?php else: ?>
                            <button class="btn-view-file" disabled title="File not available"><i class="fas fa-eye-slash"></i> View</button>
                            <?php endif; ?>

                            <a href="ai_analysis.php?id=<?php echo $a['assignment_id']; ?>" class="btn-analyze" <?php echo !$file_on_disk ? 'style="opacity:0.4;pointer-events:none;" title="File not available for analysis"' : ''; ?>><i class="fas fa-robot"></i> Analyze</a>

                            <?php if ($st === 'completed' && !empty($a['processed_file'])): ?>
                            <a href="<?php echo esc($a['processed_file']); ?>" target="_blank" class="btn-view-report" title="View AI report"><i class="fas fa-file-pdf"></i> Report</a>
                            <a href="ai_analysis.php?action=download_report&id=<?php echo $a['assignment_id']; ?>" class="btn-download-report" title="Download AI report"><i class="fas fa-download"></i> Download</a>
                            <?php endif; ?>

                            <button class="btn-delete-assign" onclick="deleteAssignment(<?php echo $a['assignment_id']; ?>, this)" title="Delete assignment and file"><i class="fas fa-trash"></i> Delete</button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
        <?php if ($total_all > 0): ?>
        <div class="table-footer"><span>Showing all <?php echo $total_all; ?> assignments</span><span>Last updated: <?php echo date('d M Y, g:i A'); ?></span></div>
        <?php endif; ?>
    </div>

<?php endif; ?>
    </div>
</main>

<!-- ═══ PROCESSING OVERLAY ═══ -->
<div class="processing-overlay" id="processingOverlay">
    <div class="processing-box">
        <div class="processing-spinner"></div>
        <div class="processing-title">Analyzing Assignment</div>
        <div class="processing-sub">Please wait while our AI engine processes the file...</div>
        <div class="processing-steps">
            <div class="processing-step" id="step1"><div class="step-icon"><i class="fas fa-check"></i></div> Reading uploaded file...</div>
            <div class="processing-step" id="step2"><div class="step-icon"><i class="fas fa-check"></i></div> Analyzing content patterns...</div>
            <div class="processing-step" id="step3"><div class="step-icon"><i class="fas fa-check"></i></div> Calculating AI probability score...</div>
            <div class="processing-step" id="step4"><div class="step-icon"><i class="fas fa-check"></i></div> Generating detailed report...</div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ─── SIDEBAR ───
const sidebar=document.getElementById('sidebar'),sideOv=document.getElementById('sidebarOverlay'),sideTog=document.getElementById('sidebarToggle');
sideTog.addEventListener('click',()=>{sidebar.classList.toggle('show');sideOv.classList.toggle('show');});
sideOv.addEventListener('click',()=>{sidebar.classList.remove('show');sideOv.classList.remove('show');});

// ─── NOTIFICATIONS ───
const notiBtn=document.getElementById('notiBtn'),notiDD=document.getElementById('notiDropdown');
notiBtn.addEventListener('click',e=>{e.stopPropagation();notiDD.classList.toggle('show');});
document.addEventListener('click',()=>notiDD.classList.remove('show'));
notiDD.addEventListener('click',e=>e.stopPropagation());

// ─── SETTINGS ───
const setBtn=document.getElementById('settingsBtn'),setPanel=document.getElementById('settingsPanel'),setOv=document.getElementById('settingsOverlay'),setClose=document.getElementById('settingsCloseBtn');
function openSet(){setPanel.classList.add('show');setOv.classList.add('show');}
function closeSet(){setPanel.classList.remove('show');setOv.classList.remove('show');}
setBtn.addEventListener('click',openSet);setClose.addEventListener('click',closeSet);setOv.addEventListener('click',closeSet);

// ─── THEME ───
const tt=document.getElementById('themeToggle'),htm=document.documentElement;
if(localStorage.getItem('theme')==='dark'){htm.setAttribute('data-theme','dark');tt.checked=true;}
tt.addEventListener('change',()=>{if(tt.checked){htm.setAttribute('data-theme','dark');localStorage.setItem('theme','dark');}else{htm.setAttribute('data-theme','light');localStorage.setItem('theme','light');}});

// ─── CLOCK ───
function updateClock(){const n=new Date(),o={weekday:'short',day:'numeric',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'};document.getElementById('headerTime').textContent=n.toLocaleDateString('en-GB',o);}
updateClock();setInterval(updateClock,30000);

// ═══════════════════════════════════════════════════
// START AI ANALYSIS
// ═══════════════════════════════════════════════════
function startAnalysis(aid) {
    const overlay = document.getElementById('processingOverlay');
    const btn = document.getElementById('startAnalysisBtn');
    const steps = ['step1','step2','step3','step4'];
    const delays = [600, 1400, 2400, 3400];

    // Reset steps
    steps.forEach(s => document.getElementById(s).classList.remove('completed'));
    overlay.classList.add('active');
    btn.disabled = true;

    // Animate steps
    delays.forEach((d, i) => {
        setTimeout(() => {
            document.getElementById(steps[i]).classList.add('completed');
        }, d);
    });

    fetch('ai_analysis.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=start_analysis&assignment_id=' + aid
    })
    .then(r => r.json())
    .then(data => {
        overlay.classList.remove('active');
        btn.disabled = false;

        if (data.success) {
            showResults(data);
            showToast('AI analysis completed successfully.', 'success');
        } else {
            showToast(data.message || 'Analysis failed. Please try again.', 'error');
        }
    })
    .catch(err => {
        overlay.classList.remove('active');
        btn.disabled = false;
        showToast('Network error. Please check your connection.', 'error');
    });
}

function showResults(data) {
    const sc = data.score;
    const scColor = sc <= 20 ? '#388E3C' : (sc <= 50 ? '#F57C00' : '#D32F2F');
    const scClass = sc <= 20 ? 'low' : (sc <= 50 ? 'medium' : 'high');
    const riskClass = sc <= 20 ? 'low' : (sc <= 50 ? 'medium' : 'high');

    let html = `
        <div class="result-header">
            <div class="check-icon"><i class="fas fa-check"></i></div>
            <h3>AI Analysis Completed</h3>
            <p>Analysis finished successfully</p>
        </div>
        <div class="score-display" style="background:rgba(${sc <= 20 ? '56,142,60' : (sc <= 50 ? '245,124,0' : '211,47,47')},0.06);">
            <div class="score-big" style="color:${scColor};">${sc}</div>
            <div class="score-of">out of 100</div>
            <div class="risk-display risk-${riskClass}"><i class="fas fa-shield-halved"></i> Risk: ${data.risk}</div>
        </div>
        <div class="result-stats">
            <div class="rs-item"><div class="rs-val">${data.stats.word_count}</div><div class="rs-lbl">Words</div></div>
            <div class="rs-item"><div class="rs-val">${data.stats.sentence_count}</div><div class="rs-lbl">Sentences</div></div>
            <div class="rs-item"><div class="rs-val">${data.stats.paragraph_count}</div><div class="rs-lbl">Paragraphs</div></div>
            <div class="rs-item"><div class="rs-val">${data.stats.keyword_count}</div><div class="rs-lbl">Keywords</div></div>
            <div class="rs-item"><div class="rs-val">${data.stats.transition_count}</div><div class="rs-lbl">Transitions</div></div>
        </div>
        <div class="feedback-card">
            <h6><i class="fas fa-comment-dots"></i> AI Feedback</h6>
            <div class="feedback-text">${data.feedback.replace(/\n/g, '<br>')}</div>
        </div>
        ${data.report_path ? `<a href="${data.report_path}" target="_blank" class="btn-view-report" style="display:inline-flex;margin-bottom:24px;margin-right:10px;padding:12px 28px;font-size:14px;"><i class="fas fa-file-pdf"></i> View Full Report</a>
        <a href="ai_analysis.php?action=download_report&id=${data.assignment_id}" class="btn-download-report" style="display:inline-flex;margin-bottom:24px;padding:12px 28px;font-size:14px;"><i class="fas fa-download"></i> Download Report</a>` : ''}
    `;

    const container = document.getElementById('liveResults');
    const existing = document.getElementById('existingResults');
    if (existing) existing.style.display = 'none';
    container.innerHTML = html;
    container.classList.add('visible');
    container.scrollIntoView({behavior:'smooth', block:'start'});
}

// ═══════════════════════════════════════════════════
// DELETE ASSIGNMENT
// ═══════════════════════════════════════════════════
function deleteAssignment(aid, btn) {
    if (!confirm('Are you sure you want to delete this assignment?\n\nThis will permanently remove:\n• The database record\n• The uploaded file\n• Any related reviews and reports\n\nThis action cannot be undone.')) return;

    const origHTML = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
    btn.disabled = true;
    btn.style.pointerEvents = 'none';

    fetch('ai_analysis.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'action=delete_assignment&assignment_id=' + aid
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showToast(data.message, 'success');
            // Remove the row with animation
            const row = btn.closest('tr');
            if (row) {
                row.style.transition = 'all 0.3s ease';
                row.style.opacity = '0';
                row.style.transform = 'translateX(30px)';
                setTimeout(() => row.remove(), 300);
            }
            // Update stat cards if they exist
            setTimeout(() => location.reload(), 800);
        } else {
            showToast(data.message || 'Unable to delete assignment.', 'error');
            btn.innerHTML = origHTML;
            btn.disabled = false;
            btn.style.pointerEvents = '';
        }
    })
    .catch(err => {
        showToast('Network error. Please try again.', 'error');
        btn.innerHTML = origHTML;
        btn.disabled = false;
        btn.style.pointerEvents = '';
    });
}

// ═══════════════════════════════════════════════════
// TOAST NOTIFICATIONS
// ═══════════════════════════════════════════════════
function showToast(message, type) {
    const existing = document.querySelector('.custom-toast');
    if (existing) existing.remove();

    const toast = document.createElement('div');
    toast.className = 'custom-toast';
    const bgColor = type === 'success' ? '#388E3C' : (type === 'error' ? '#D32F2F' : '#F57C00');
    const icon = type === 'success' ? 'fa-check-circle' : (type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle');

    toast.style.cssText = `position:fixed;top:90px;right:30px;z-index:99999;background:#fff;border-radius:12px;padding:16px 22px;box-shadow:0 8px 30px rgba(0,0,0,0.15);display:flex;align-items:center;gap:12px;font-family:'Poppins',sans-serif;font-size:13px;font-weight:500;color:#2D1B4E;border-left:4px solid ${bgColor};animation:slideInRight .4s ease;max-width:420px;`;
    toast.innerHTML = `<i class="fas ${icon}" style="font-size:18px;color:${bgColor};flex-shrink:0;"></i><span style="flex:1;">${message}</span><button onclick="this.parentElement.remove()" style="background:none;border:none;color:#999;font-size:14px;cursor:pointer;padding:4px;flex-shrink:0;"><i class="fas fa-times"></i></button>`;

    const style = document.createElement('style');
    style.textContent = '@keyframes slideInRight{from{opacity:0;transform:translateX(40px);}to{opacity:1;transform:translateX(0);}}';
    document.head.appendChild(style);

    document.body.appendChild(toast);
    setTimeout(() => { if (toast.parentElement) { toast.style.transition = 'all .3s ease'; toast.style.opacity = '0'; toast.style.transform = 'translateX(40px)'; setTimeout(() => toast.remove(), 300); } }, 5000);
}
</script>
</body>
</html>
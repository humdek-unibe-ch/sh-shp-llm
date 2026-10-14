<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */

/**
 * LLM File Utility Class
 *
 * Shared utilities for file operations across LLM services.
 * Contains common file processing functions to eliminate code duplication.
 */
class LlmFileUtility
{
    /**
     * Detect MIME type using finfo
     * Falls back to file extension-based detection if finfo fails
     *
     * @param string $filePath Path to the file
     * @return string|null Detected MIME type
     */
    public static function detectMimeType($filePath)
    {
        if (!file_exists($filePath)) {
            return null;
        }

        // Try finfo first (most reliable)
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $filePath);
            finfo_close($finfo);

            if ($mimeType && $mimeType !== 'application/octet-stream') {
                return $mimeType;
            }
        }

        // Try mime_content_type as fallback
        if (function_exists('mime_content_type')) {
            $mimeType = mime_content_type($filePath);
            if ($mimeType && $mimeType !== 'application/octet-stream') {
                return $mimeType;
            }
        }

        return null;
    }

    /**
     * Format file size for human-readable display
     *
     * @param int $bytes File size in bytes
     * @return string Formatted file size (e.g. "1.5 MB")
     */
    public static function formatFileSize($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $unitIndex = 0;

        while ($bytes >= 1024 && $unitIndex < count($units) - 1) {
            $bytes /= 1024;
            $unitIndex++;
        }

        return round($bytes, 1) . ' ' . $units[$unitIndex];
    }

    /**
     * Get human-readable upload error message
     *
     * @param int $errorCode PHP upload error code (UPLOAD_ERR_*)
     * @return string Human-readable error message
     */
    public static function getUploadErrorMessage($errorCode)
    {
        switch ($errorCode) {
            case UPLOAD_ERR_INI_SIZE:
                return 'The uploaded file exceeds the upload_max_filesize directive in php.ini';
            case UPLOAD_ERR_FORM_SIZE:
                return 'The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form';
            case UPLOAD_ERR_PARTIAL:
                return 'The uploaded file was only partially uploaded';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Missing a temporary folder';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Failed to write file to disk';
            case UPLOAD_ERR_EXTENSION:
                return 'A PHP extension stopped the file upload';
            default:
                return 'Unknown upload error';
        }
    }

    /**
     * Read a compact EXIF summary from an image file (JPEG/TIFF primarily).
     *
     * Vision APIs re-encode images and strip EXIF; extract before that and pass
     * the summary as text if the model should see GPS / datetime / camera.
     *
     * Requires the PHP exif extension. Returns null when unavailable or empty.
     *
     * @param string $filePath Absolute filesystem path
     * @return array{summary: string, fields: array}|null
     */
    public static function extractImageExif($filePath)
    {
        if (!is_string($filePath) || $filePath === '' || !is_file($filePath)) {
            return null;
        }
        if (!function_exists('exif_read_data')) {
            return null;
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        // PHP exif_read_data is reliable for JPEG/TIFF; skip others quietly.
        if (!in_array($ext, array('jpg', 'jpeg', 'tif', 'tiff', 'webp'), true)) {
            // Still try JPEG-in-disguise / some tools write EXIF into webp; ignore failures.
            if (!in_array($ext, array('jpg', 'jpeg', 'tif', 'tiff'), true)) {
                // Attempt anyway for jpg-like binaries saved with other extensions.
            }
        }

        $exif = @exif_read_data($filePath, null, true);
        if (!is_array($exif) || empty($exif)) {
            return null;
        }

        $fields = array();

        // Only real photo EXIF timestamps — never FILE.FileDateTime (that is
        // "when PHP wrote our upload copy" and looks like fake camera metadata).
        $datetime = self::exifFirstString($exif, array(
            array('EXIF', 'DateTimeOriginal'),
            array('EXIF', 'DateTimeDigitized'),
            array('IFD0', 'DateTime'),
        ));
        if ($datetime !== null) {
            $fields['datetime'] = $datetime;
        }

        $make = self::exifFirstString($exif, array(array('IFD0', 'Make')));
        $model = self::exifFirstString($exif, array(array('IFD0', 'Model')));
        if ($make !== null || $model !== null) {
            $fields['camera'] = trim(($make ?? '') . ' ' . ($model ?? ''));
        }

        $software = self::exifFirstString($exif, array(array('IFD0', 'Software')));
        if ($software !== null) {
            $fields['software'] = $software;
        }

        $gps = self::exifGpsToDecimal($exif);
        if ($gps !== null) {
            $fields['gps'] = $gps;
            $fields['gps_maps'] = sprintf(
                'https://maps.google.com/?q=%s,%s',
                $gps['lat'],
                $gps['lon']
            );
        }

        $orientation = self::exifFirstString($exif, array(array('IFD0', 'Orientation')));
        if ($orientation !== null) {
            $fields['orientation'] = $orientation;
        }

        if (empty($fields)) {
            return null;
        }

        $parts = array();
        if (!empty($fields['datetime'])) {
            $parts[] = 'datetime=' . $fields['datetime'];
        }
        if (!empty($fields['camera'])) {
            $parts[] = 'camera=' . $fields['camera'];
        }
        if (!empty($fields['gps'])) {
            $parts[] = 'gps=' . $fields['gps']['lat'] . ',' . $fields['gps']['lon'];
        }
        if (!empty($fields['software'])) {
            $parts[] = 'software=' . $fields['software'];
        }

        return array(
            'summary' => implode('; ', $parts),
            'fields' => $fields,
        );
    }

    /**
     * @param array $exif
     * @param array $paths List of [section, key]
     * @return string|null
     */
    private static function exifFirstString(array $exif, array $paths)
    {
        foreach ($paths as $path) {
            $section = $path[0];
            $key = $path[1];
            if (!isset($exif[$section][$key])) {
                continue;
            }
            $value = $exif[$section][$key];
            if (is_array($value)) {
                $value = implode(' ', $value);
            }
            $value = trim((string)$value);
            if ($value !== '') {
                return $value;
            }
        }
        return null;
    }

    /**
     * @param array $exif
     * @return array{lat: string, lon: string}|null
     */
    private static function exifGpsToDecimal(array $exif)
    {
        if (empty($exif['GPS']['GPSLatitude']) || empty($exif['GPS']['GPSLongitude'])) {
            return null;
        }

        $lat = self::exifGpsCoordinateToDecimal(
            $exif['GPS']['GPSLatitude'],
            $exif['GPS']['GPSLatitudeRef'] ?? 'N'
        );
        $lon = self::exifGpsCoordinateToDecimal(
            $exif['GPS']['GPSLongitude'],
            $exif['GPS']['GPSLongitudeRef'] ?? 'E'
        );
        if ($lat === null || $lon === null) {
            return null;
        }

        return array(
            'lat' => number_format($lat, 6, '.', ''),
            'lon' => number_format($lon, 6, '.', ''),
        );
    }

    /**
     * @param array|string $coordinate
     * @param string $ref
     * @return float|null
     */
    private static function exifGpsCoordinateToDecimal($coordinate, $ref)
    {
        if (!is_array($coordinate) || count($coordinate) < 3) {
            return null;
        }

        $parts = array();
        for ($i = 0; $i < 3; $i++) {
            $parts[$i] = self::exifRationalToFloat($coordinate[$i]);
            if ($parts[$i] === null) {
                return null;
            }
        }

        $decimal = $parts[0] + ($parts[1] / 60) + ($parts[2] / 3600);
        $ref = strtoupper(trim((string)$ref));
        if ($ref === 'S' || $ref === 'W') {
            $decimal *= -1;
        }
        return $decimal;
    }

    /**
     * @param mixed $rational
     * @return float|null
     */
    private static function exifRationalToFloat($rational)
    {
        if (is_numeric($rational)) {
            return (float)$rational;
        }
        if (!is_string($rational) || strpos($rational, '/') === false) {
            return null;
        }
        $bits = explode('/', $rational, 2);
        if (count($bits) !== 2 || (float)$bits[1] == 0.0) {
            return null;
        }
        return (float)$bits[0] / (float)$bits[1];
    }
}
?>

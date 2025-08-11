<?php

namespace Noerd\Media\Services;

use Alimranahmed\LaraOCR\Facades\OCR;
use Exception;
use Imagick;

class OcrService
{
    public function parseWithOCR(string $path): array
    {
        //dump("Parsing PDF using OCR...");

        $allText = '';
        $debugInfo = [];
        $tempDir = storage_path('app/temp_ocr');

        // Create temp directory if it doesn't exist
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        try {

            putenv('MAGICK_THREAD_LIMIT=1'); // optional safety
            putenv('MAGICK_TEMPORARY_PATH=/tmp'); // optional if you're on a container
            putenv('PATH=' . getenv('PATH') . ':/usr/bin'); // make sure gs is found

            // Convert PDF to images using Imagick
            $imagick = new Imagick();
            $imagick->setResolution(300, 300); // High resolution for better OCR
            $imagick->readImage($path);
            $imagick->setImageFormat('png');

            $totalPages = $imagick->getNumberImages();
            //dump("Converting {$totalPages} pages to images...");

            for ($i = 0; $i < $totalPages; $i++) {
                //dump("Processing page " . ($i + 1) . " of {$totalPages}...");

                $imagick->setIteratorIndex($i);
                $imagePath = $tempDir . "/page_{$i}.png";
                $imagick->writeImage($imagePath);

                // Run OCR on the image using LaraOCR
                $pageText = Ocr::scan($imagePath);

                $pageInfo = [
                    'page_number' => $i + 1,
                    'image_path' => $imagePath,
                    'ocr_text' => $pageText,
                ];

                $debugInfo[] = $pageInfo;

                $allText .= "=== PAGE " . ($i + 1) . " ===\n";
                $allText .= $pageText . "\n\n";

                // Clean up the temporary image
                unlink($imagePath);
            }

            $imagick->clear();
            $imagick->destroy();

            //dump("OCR processing completed successfully!");
        } catch (Exception $e) {
            $debugInfo['error'] = $e->getMessage();
            $allText .= "OCR Error: " . $e->getMessage() . "\n";
            dump("OCR Error: " . $e->getMessage());
        }

        return [
            'extracted_text' => $allText,
            'debug_info' => $debugInfo,
            'total_pages' => $totalPages ?? 0,
        ];
    }
}

<?php
/* ======================================== */
/* Virtual Tour Video Broken URL Checker Functions
/* ======================================== */

// Check if video URLs are broken (supports single URL or array)
function checkBrokenVideoUrls($urls): array {
    // Ensure $urls is always an array
    if (!is_array($urls)) {
        $urls = [$urls];
    }

    $brokenUrls = [];

    foreach ($urls as $url) {
        try {
            if (isYouTube($url)) {
                $videoId = getYouTubeId($url);
                if (!$videoId || !isYouTubeVideoAvailable($videoId)) {
                    $brokenUrls[] = $url;
                }
            } elseif (isVimeo($url)) {
                $videoId = getVimeoId($url);
                if (!$videoId || !isVimeoVideoAvailable($videoId)) {
                    $brokenUrls[] = $url;
                }
            } elseif (isDailymotion($url)) {
                $videoId = getDailymotionId($url);
                if (!$videoId || !isDailymotionVideoAvailable($videoId)) {
                    $brokenUrls[] = $url;
                }
            } elseif (isVideoFile($url)) {
                // Validate direct video file
                if (!isDirectVideoFileAvailable($url)) {
                    $brokenUrls[] = $url;
                }
            } else {
                $brokenUrls[] = $url; // Unrecognized URLs are considered broken
            }
        } catch (Exception $e) {
            $brokenUrls[] = $url;
        }
    }

    return $brokenUrls;
}

// Validate direct video file URL
function isDirectVideoFileAvailable($url): bool {
    $headers = @get_headers($url);
    if ($headers && strpos($headers[0], '200') !== false) {
        // Check if the Content-Type indicates a valid video format
        foreach ($headers as $header) {
            if (stripos($header, 'Content-Type: video') !== false) {
                return true;
            }
        }
    }
    return false;
}

// Validate YouTube video
function isYouTubeVideoAvailable($videoId): bool {
    $url = "https://www.youtube.com/oembed?url=https://www.youtube.com/watch?v=$videoId&format=json";
    $response = @file_get_contents($url);
    return $response !== false;
}

// Validate Vimeo video
function isVimeoVideoAvailable($videoId): bool {
    $url = "https://vimeo.com/api/oembed.json?url=https://vimeo.com/$videoId";
    $response = @file_get_contents($url);
    return $response !== false;
}

// Validate Dailymotion video
function isDailymotionVideoAvailable($videoId): bool {
    $url = "https://www.dailymotion.com/embed/video/$videoId";
    $headers = @get_headers($url);
    return strpos($headers[0], '200') !== false;
}
?>

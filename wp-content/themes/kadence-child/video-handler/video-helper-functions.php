<?php
/* ======================================== */
/* Shared Video Helper Functions
/* ======================================== */

// Check if the URL is a YouTube link
function isYouTube($url): bool {
    return preg_match('/(?:youtube\.com|youtu\.be)/', $url);
}

// Extract YouTube video ID
function getYouTubeId($url): ?string {
    preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/ ]{11})/i', $url, $matches);
    return $matches[1] ?? null;
}

// Check if the URL is a Vimeo link
function isVimeo($url): bool {
    return preg_match('/vimeo\.com/', $url);
}

// Extract Vimeo video ID
function getVimeoId($url): ?string {
    preg_match('/vimeo\.com\/(\d+)/', $url, $matches);
    return $matches[1] ?? null;
}

// Check if the URL is a Dailymotion link
function isDailymotion($url): bool {
    return preg_match('/dailymotion\.com/', $url);
}

// Extract Dailymotion video ID
function getDailymotionId($url): ?string {
    preg_match('/dailymotion\.com\/video\/([a-zA-Z0-9]+)/', $url, $matches);
    return $matches[1] ?? null;
}

// Check if the URL is a direct video file
function isVideoFile($url): bool {
    $videoExtensions = ['mp4', 'webm', 'ogg'];
    $parsedUrl = parse_url($url, PHP_URL_PATH);
    $extension = pathinfo($parsedUrl, PATHINFO_EXTENSION);
    return in_array(strtolower($extension), $videoExtensions);
}
?>

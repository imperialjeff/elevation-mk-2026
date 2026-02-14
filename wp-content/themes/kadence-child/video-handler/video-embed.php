<?php
/* ======================================== */
/* Virtual Tour Video Embed Helper Functions
/* ======================================== */

// Embed videos from an array or a single URL
function embedVideos($urls) {
    // Ensure $urls is always an array
    if (!is_array($urls)) {
        $urls = [$urls];
    }

    foreach ($urls as $url) {
        try {
            if (isYouTube($url)) {
                $videoId = getYouTubeId($url);
                if ($videoId) {
                    echo responsiveEmbed("https://www.youtube.com/embed/$videoId");
                }
            } elseif (isVimeo($url)) {
                $videoId = getVimeoId($url);
                if ($videoId) {
                    echo responsiveEmbed("https://player.vimeo.com/video/$videoId");
                }
            } elseif (isDailymotion($url)) {
                $videoId = getDailymotionId($url);
                if ($videoId) {
                    echo responsiveEmbed("https://www.dailymotion.com/embed/video/$videoId");
                }
            } elseif (isVideoFile($url)) {
                echo responsiveVideoTag($url);
            } else {
                echo "<!-- Unsupported video URL: $url -->";
            }
        } catch (Exception $e) {
            echo "<!-- Error embedding video for URL: $url -->";
        }
    }
}

// Generate responsive embed HTML for iframe videos
function responsiveEmbed($src) {
    return "<div class='video-wrapper'><iframe src='$src' frameborder='0' allow='autoplay; fullscreen' allowfullscreen></iframe></div>";
}

// Generate responsive HTML for direct video files
function responsiveVideoTag($url) {
    $type = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
    return "<div class='video-wrapper'><video controls><source src='$url' type='video/$type'>Your browser does not support the video tag.</video></div>";
}
?>
